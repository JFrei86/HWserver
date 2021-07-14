#!/usr/bin/env python3

import collections
import os
import time
import signal
import string
import json

import shutil
import contextlib
import datetime
import multiprocessing
from pathlib import Path
from submitty_utils import dateutils, string_utils, ssh_proxy_jump
import operator
import paramiko
import tempfile
import socket
import traceback
import subprocess
import random
import urllib
import re

from enum import Enum
from math import floor
from os import PathLike
from typing import List, Tuple

from autograder import autograding_utils
from autograder import packer_unpacker
from autograder import scheduler
from autograder import config as submitty_config


INTERACTIVE_QUEUE = ''
IN_PROGRESS_PATH = ''
JOB_ID = '~SHIP~'


def instantiate_global_variables(config):
    global INTERACTIVE_QUEUE, IN_PROGRESS_PATH
    INTERACTIVE_QUEUE = os.path.join(config.submitty['submitty_data_dir'], "to_be_graded_queue")
    IN_PROGRESS_PATH = os.path.join(config.submitty['submitty_data_dir'], "in_progress_grading")


class GradingStatus(Enum):
    """
    An enumeration to represent statuses that can be returned from
    the worker machine.

    SUCCESS: The job was successfully unpacked and can be further processed.
    WAITING: The worker is still grading the job.
    FAILURE: The worker irrecoverably failed to grade the job.
    """
    SUCCESS = 1
    WAITING = 2
    FAILURE = 3


class CopyDirection(Enum):
    """
    Determines which direction files should be copied in ``copy_files``.
    """
    PUSH = 1
    PULL = 2


def copy_files(
    config: submitty_config.Config,
    address: str,
    files: List[Tuple[PathLike, PathLike]],
    direction: CopyDirection,
):
    """Copy files between two directories.

    Note that this function does *not* handle exceptions, so potential exceptions should be handled
    by the caller.

    Parameters
    ----------
    address : str
        Address of the destination. May be either `localhost` for copying files locally, or some
        `username@hostname` format for copying files to some remote location via SFTP.
    files : list of tuple of paths
        List of (source, destination) paths.
    direction : CopyDirection
        If `address` is a remote address, which way files should move. If `PUSH`, then the source
        file is on localhost and it should be pushed to the destination file in the remote address;
        if `PULL` then the source file is on the remote machine and it should be pulled to the
        source file on the local machine.
    """
    if address == 'localhost':
        for src, dest in files:
            if src != dest:
                shutil.copy(src, dest)
    else:
        user, host = address.split('@')
        sftp = ssh = None

        try:
            # NOTE: my_name is used pretty inconsistently across the file:
            # - In one case it's set to user@host
            # - In one case it's set to the name of the running thread
            # - In one case it's set to None
            # Setting it to an empty string shouldn't lose us any debugging information.
            (ssh, intermediate_connection) = establish_ssh_connection(config, '', user, host)
        except Exception as e:
            raise RuntimeError(f"SSH to {address} failed") from e

        try:
            sftp = ssh.open_sftp()
        except Exception as e:
            raise RuntimeError(f"SFTP to {address} failed") from e

        try:
            if direction == CopyDirection.PUSH:
                for local, remote in files:
                    sftp.put(local, remote)
            else:
                for remote, local in files:
                    sftp.get(remote, local)
        finally:
            if sftp is not None:
                sftp.close()
            if ssh is not None:
                ssh.close()
            if intermediate_connection is not None:
                intermediate_connection.close()


def delete_files(
    config: submitty_config.Config,
    address: str,
    files: List[PathLike],
    *,
    ignore_not_found: bool = False
):
    """Remove files from some place.

    Parameters
    ----------
    address : str
        Address of the destination. May be either `localhost` for deleting files locally, or some
        `username@hostname` format for deleting files from some remote location via SFTP.
    files : list of paths
        List of file paths to delete on the target machine.
    ignore_not_found : bool
        (default False) If True, then any `FileNotFoundError` raised from the deletion operation
        will be ignored.
    """
    if address == 'localhost':
        for file in files:
            if not ignore_not_found:
                os.remove(file)
            else:
                with contextlib.suppress(FileNotFoundError):
                    os.remove(file)
    else:
        user, host = address.split('@')
        sftp = ssh = None

        try:
            (ssh, intermediate_connection) = establish_ssh_connection(config, '', user, host)
        except Exception as e:
            raise RuntimeError(f"SSH to {address} failed") from e

        try:
            sftp = ssh.open_sftp()
        except Exception as e:
            raise RuntimeError(f"SFTP to {address} failed") from e

        try:
            for remote in files:
                if not ignore_not_found:
                    sftp.remove(remote)
                else:
                    with contextlib.suppress(FileNotFoundError):
                        sftp.remove(remote)
        finally:
            if sftp is not None:
                sftp.close()
            if ssh is not None:
                ssh.close()
            if intermediate_connection is not None:
                intermediate_connection.close()


def worker_folder(worker_name):
    return os.path.join(IN_PROGRESS_PATH, worker_name)


# ==================================================================================
def initialize(untrusted_queue):
    """
    Initializer function for all our processes. We get one untrusted user off our queue which
    we then set in our Process. We cannot recycle the shipper process as else the untrusted user
    we set for this process will be lost.

    :param untrusted_queue: multiprocessing.queues.Queue that contains all untrusted users left to
                            assign
    """
    multiprocessing.current_process().untrusted = untrusted_queue.get()


# ==================================================================================
def add_fields_to_autograding_worker_json(
    config: submitty_config.Config,
    autograding_worker_json: dict,
    entry: str
):

    submitty_config = os.path.join(
        config.submitty['submitty_install_dir'],
        'config',
        'version.json'
    )

    try:
        with open(submitty_config) as infile:
            submitty_details = json.load(infile)
            installed_commit = submitty_details['installed_commit']
            most_recent_tag = submitty_details['most_recent_git_tag']
    except FileNotFoundError as e:
        config.logger.log_stack_trace(traceback.format_exc())
        raise SystemExit("ERROR, could not locate the submitty.json:", e)

    autograding_worker_json[entry]['server_name'] = socket.getfqdn()
    autograding_worker_json[entry]['primary_commit'] = installed_commit
    autograding_worker_json[entry]['most_recent_tag'] = most_recent_tag
    return autograding_worker_json


# ==================================================================================
def update_remote_autograding_workers(config, autograding_workers):
    success_map = dict()
    for machine, value in autograding_workers.items():
        if value['enabled'] is False:
            print(f"SKIPPING WORKER MACHINE {machine} because it is not enabled")
            success_map[machine] = False
            continue
        print(f"UPDATE CONFIGURATION FOR WORKER MACHINE: {machine}")
        formatted_entry = {machine: value}
        formatted_entry = add_fields_to_autograding_worker_json(config, formatted_entry, machine)
        success = update_worker_json(config, machine, formatted_entry)
        success_map[machine] = success
    return success_map


# ==================================================================================
# Updates the autograding_worker.json in a workers autograding_TODO folder (tells it)
#   how many threads to be running on startup.
def update_worker_json(config: submitty_config.Config, name: str, entry: str):

    fd, tmp_json_path = tempfile.mkstemp()
    foreign_json = os.path.join(
        config.submitty['submitty_data_dir'],
        "autograding_TODO",
        "autograding_worker.json"
    )
    autograding_worker_to_ship = entry

    try:
        user = autograding_worker_to_ship[name]['username']
        host = autograding_worker_to_ship[name]['address']
    except Exception as e:
        print(f"ERROR: autograding_workers.json entry for {e} is malformatted. {name}")
        config.logger.log_message(
            f"ERROR: autograding_workers.json entry for {name} is malformed. {e}"
        )
        config.logger.log_stack_trace(traceback.format_exc())
        return False

    # create a new temporary json with only the entry for the current machine.
    with open(tmp_json_path, 'w') as outfile:
        json.dump(autograding_worker_to_ship, outfile, sort_keys=True, indent=4)

    # Set the address for the copy_files call.
    if host == "localhost":
        address = host
    else:
        address = f'{user}@{host}'

    success = False
    try:
        copy_files(config, address, [
            (tmp_json_path, foreign_json)
        ], CopyDirection.PUSH)
        success = True
    except Exception as e:
        config.logger.log_stack_trace(
            traceback.format_exc(),
            job_id=JOB_ID,
        )
        config.logger.log_message(
            "ERROR: Could not move autograding_TODO/autograding_worker.json to "
            f"{address}: {e}",
            job_id=JOB_ID,
        )
    finally:
        if host != "localhost":
            os.remove(tmp_json_path)
    return success


def establish_ssh_connection(
    config: submitty_config.Config,
    my_name: str,
    user: str,
    host: str,
    only_try_once: bool = False
) -> paramiko.SSHClient:
    """
    Returns a connected paramiko ssh session.
    Tries to connect until a connection is established, unless only_try_once
    is set to true. If only_try_once is true, raise whatever connection error is thrown.
    """
    connected = False
    target_connection = None
    intermediate_connection = None
    retry_delay = .1
    while not connected:
        try:
            (target_connection,
             intermediate_connection) = ssh_proxy_jump.ssh_connection_allowing_proxy_jump(user,
                                                                                          host)
            connected = True
        except Exception:
            if only_try_once:
                raise
            time.sleep(retry_delay)
            retry_delay = min(10, retry_delay * 2)
            config.logger.log_message(
                f"{my_name} Could not establish connection with {user}@{host} going to re-try."
            )
    return (target_connection, intermediate_connection)


# ==================================================================================
def prepare_job(
    config: submitty_config.Config,
    my_name: str,
    which_machine: str,
    which_untrusted: str,
    next_directory: str,
    next_to_grade: str,
    random_identifier: str
):
    # verify the DAEMON_USER is running this script
    if not int(os.getuid()) == int(config.submitty_users['daemon_uid']):
        config.logger.log_message("ERROR: must be run by DAEMON_USER")
        raise SystemExit(
            "ERROR: the submitty_autograding_shipper.py script must be run by the DAEMON_USER"
        )

    if which_machine == 'localhost':
        host = which_machine
    else:
        host = which_machine.split('@')[1]

    # prepare the zip files
    try:
        machine_name = my_name[:my_name.rfind('_')]
        zips = packer_unpacker.prepare_autograding_and_submission_zip(
            config,
            machine_name,
            which_machine,
            which_untrusted,
            next_directory,
            next_to_grade
        )
        autograding_zip_tmp, submission_zip_tmp = zips

        fully_qualified_domain_name = socket.getfqdn()
        servername_workername = "{0}_{1}".format(fully_qualified_domain_name, host)
        autograding_zip = os.path.join(
            config.submitty['submitty_data_dir'], "autograding_TODO",
            f"{servername_workername}_{which_untrusted}_autograding.zip"
        )
        submission_zip = os.path.join(
            config.submitty['submitty_data_dir'], "autograding_TODO",
            f"{servername_workername}_{which_untrusted}_submission.zip"
        )
        todo_queue_file = os.path.join(
            config.submitty['submitty_data_dir'], "autograding_TODO",
            f"{servername_workername}_{which_untrusted}_queue.json"
        )

        with open(next_to_grade, 'r') as infile:
            queue_obj = json.load(infile)
            queue_obj["which_untrusted"] = which_untrusted
            queue_obj["which_machine"] = which_machine
            queue_obj["ship_time"] = dateutils.write_submitty_date(milliseconds=True)
            queue_obj['identifier'] = random_identifier

    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc())
        config.logger.log_message(
            f"ERROR: failed preparing submission zip or accessing next to grade {e}"
        )
        print("ERROR: failed preparing submission zip or accessing next to grade ", e)
        return False

    with open(todo_queue_file, 'w') as outfile:
        json.dump(queue_obj, outfile, sort_keys=True, indent=4)

    try:
        copy_files(config, which_machine, [
            (autograding_zip_tmp, autograding_zip),
            (submission_zip_tmp, submission_zip),
            (todo_queue_file, todo_queue_file)
        ], CopyDirection.PUSH)
    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc(), job_id=JOB_ID)
        config.logger.log_message(
            f"ERROR: could not move files due to the following error: {e}", job_id=JOB_ID,
        )
        print(f"ERROR: could not move files due to the following error: {e}")
        return False
    finally:
        os.remove(autograding_zip_tmp)
        os.remove(submission_zip_tmp)
        if host != 'localhost':
            os.remove(todo_queue_file)

    # log completion of job preparation
    obj = packer_unpacker.load_queue_file_obj(config, JOB_ID, next_directory, next_to_grade)
    if "generate_output" not in obj:
        partial_path = os.path.join(obj["gradeable"], obj["who"], str(obj["version"]))
        item_name = os.path.join(obj["semester"], obj["course"], "submissions", partial_path)
    elif obj["generate_output"]:
        item_name = os.path.join(
            obj["semester"], obj["course"], "generated_output", obj["gradeable"]
        )
    is_batch = "regrade" in obj and obj["regrade"]
    config.logger.log_message(
        f"Prepared job for {which_machine}",
        jobname=item_name, which_untrusted=which_untrusted, is_batch=is_batch,
    )
    return True


# ==================================================================================
# ==================================================================================
def unpack_job(
    config,
    which_machine,
    which_untrusted,
    next_directory,
    next_to_grade,
    random_identifier
):

    # verify the DAEMON_USER is running this script
    if not int(os.getuid()) == int(config.submitty_users['daemon_uid']):
        config.logger.log_message("ERROR: must be run by DAEMON_USER")
        raise SystemExit(
            "ERROR: the submitty_autograding_shipper.py script must be run by the DAEMON_USER"
        )

    # Grab the path to this assignment for logging purposes
    obj = packer_unpacker.load_queue_file_obj(config, JOB_ID, next_directory, next_to_grade)
    if "generate_output" not in obj:
        partial_path = os.path.join(obj["gradeable"], obj["who"], str(obj["version"]))
        item_name = os.path.join(obj["semester"], obj["course"], "submissions", partial_path)
    elif obj["generate_output"]:
        item_name = os.path.join(obj["semester"], obj["course"], "generated_output")
    is_batch = "regrade" in obj and obj["regrade"]

    # Address is either localhost or a string of the form user@host
    address = which_machine if which_machine == 'localhost' else which_machine.split('@')[1]

    # The full name of the worker associated with the socket
    worker_name = f"{socket.getfqdn()}_{address}_{which_untrusted}"

    target_results_zip = os.path.join(
        config.submitty['submitty_data_dir'], "autograding_DONE",
        f"{worker_name}_results.zip"
    )
    target_done_queue_file = os.path.join(
        config.submitty['submitty_data_dir'], "autograding_DONE",
        f"{worker_name}_queue.json"
    )

    # status will be set to a GradingStatus.
    status = None

    try:
        # Try to pull in the finished files into temporary work files.
        fd1, local_done_queue_file = tempfile.mkstemp()
        fd2, local_results_zip = tempfile.mkstemp()
        copy_files(config, which_machine, [
            (target_done_queue_file, local_done_queue_file),
            (target_results_zip, local_results_zip)
        ], CopyDirection.PULL)
    except (socket.timeout, TimeoutError, FileNotFoundError):
        # These are expected error cases, so we clean up on our end and return a `WAITING` status.
        status = GradingStatus.WAITING
    except Exception as e:
        # Unexpected error case, clean up, log some stuff and return a `FAILURE` status.
        config.logger.log_stack_trace(
            f'{traceback.format_exc()}\n'
            'Consider exception handling for the above error to the shipper.',
            job_id=JOB_ID,
        )
        config.logger.log_message(
            f"ERROR: Could not retrieve the file from the foreign machine {e}", job_id=JOB_ID
        )
        print(f"ERROR: Could not retrieve the file from the foreign machine.\nERROR: {e}")
        status = GradingStatus.FAILURE
    else:
        delete_files(config, which_machine, [
            target_done_queue_file,
            target_results_zip,
        ], ignore_not_found=True)
    finally:
        # Close the unused file descriptors
        with contextlib.suppress(OSError):
            os.close(fd1)
            os.close(fd2)

    if status is not None:
        # We've assigned a value to status, so return the status
        with contextlib.suppress(FileNotFoundError):
            os.remove(local_done_queue_file)
            os.remove(local_results_zip)
        return status

    try:
        with open(local_done_queue_file, 'r') as infile:
            local_done_queue_obj = json.load(infile)

        # Reproduce all stack trace entries in the queue file into our local stack trace log.
        if len(local_done_queue_obj['errors']) > 0:
            config.logger.log_message(
                f"NOTE: Job {item_name} has stack trace messages. "
                "Check the stack traces log for details."
            )
            for trace_obj in local_done_queue_obj['errors']:
                config.logger.log_stack_trace(**trace_obj, echo_source=worker_name)

        # Check to make certain that the job we received was the correct job
        if random_identifier != local_done_queue_obj['identifier']:
            msg = f"{which_machine} returned a stale job (ids don't match). Discarding."
            # Even though this job was not the one we are waiting for, report errors.
            if local_done_queue_obj['autograding_status']['status'] == 'fail':
                msg += ' discarded job failed. Check the stack traces log for details.'
                config.logger.log_stack_trace(
                    f"ERROR: {worker_name} returned the following error for a stale job:\n"
                    f"{local_done_queue_obj['autograding_status']['message']}"
                )
            print(msg)
            config.logger.log_message(
                msg, jobname=item_name, which_untrusted=which_untrusted, is_batch=is_batch,
            )
            # Return waiting, because we haven't received the job we are actually looking for.
            return GradingStatus.WAITING

        status_str = local_done_queue_obj['autograding_status']['status']
        # If the job we received was a good job, check to see if it was a success.
        if status_str == 'success':
            status = GradingStatus.SUCCESS
            print(f'{worker_name} returned a successful job.')
        # otherwise, check to see if the returned status was a failure
        elif status_str == 'fail':
            config.logger.log_message(
                f"ERROR: failure returned by {worker_name}. View stack traces for more info",
                jobname=item_name
            )
            print(f'{worker_name} returned a failed job.')
            status = GradingStatus.FAILURE
        # If we hit this else statement, a bad status was returned.
        else:
            config.logger.log_message(
                f'ERROR: {worker_name} returned unexpected status {status_str}',
                jobname=item_name, which_untrusted=which_untrusted, is_batch=is_batch
            )
            # Report this as a stack trace as well.
            config.logger.log_stack_trace(
                f'ERROR: {worker_name} returned unexpected status {status_str}'
            )
            # Set the status to failure, as we don't know the state of the returned job.
            status = GradingStatus.FAILURE

        # Regardless of the status returned, try to copy the results zip.
        # TODO: make packer_unpacker.unpack_grading_results_zip more robust
        # to partial grading/failures.
        could_unpack = packer_unpacker.unpack_grading_results_zip(
            config, which_machine, which_untrusted, local_results_zip
        )
        # If we couldn't unpack the returned job, we consider it to be a failure.
        if not could_unpack:
            status = GradingStatus.FAILURE
            config.logger.log_stack_trace(
                f'ERROR: {worker_name} could not unpack {local_results_zip}'
            )
            print(f'ERROR: {worker_name} could not unpack {local_results_zip}')
    # If we have thrown an exception, it was very likely either when we tried to load the
    # local_queue_obj or when we called unpack_grading_results_zip. Log the error, set status
    # to failure, and carry on.
    except Exception:
        config.logger.log_stack_trace(traceback.format_exc())
        config.logger.log_message(
            "ERROR: Exception when unpacking results zip. For more details, see traces entry.",
            jobname=item_name,
        )
        print("ERROR: Exception when unpacking results zip.")
        status = GradingStatus.FAILURE
    finally:
        # Whether we succeeded or failed, make sure that we've cleaned up after ourselves.
        with contextlib.suppress(FileNotFoundError):
            os.remove(local_results_zip)
        with contextlib.suppress(FileNotFoundError):
            os.remove(local_done_queue_file)

    if status == GradingStatus.SUCCESS:
        config.logger.log_message(f"Unpacked job from {worker_name}", jobname=item_name)
    return status


# ==================================================================================
def grade_queue_file(config, my_name, which_machine, which_untrusted, queue_file):
    """
    Oversees the autograding of single item from the queue

    :param queue_file: details of what to grade
    :param which_machine: name of machine to send this job to (might be "localhost")
    :param which_untrusted: specific untrusted user for this autograding job
    """

    my_dir, my_file = os.path.split(queue_file)
    directory = os.path.dirname(os.path.realpath(queue_file))
    name = os.path.basename(os.path.realpath(queue_file))
    grading_file = os.path.join(directory, "GRADING_" + name)

    # Try to short-circuit this job. If it's possible, then great! Clean
    # everything up and return.
    try:
        if try_short_circuit(config, queue_file):
            grading_cleanup(config, my_name, queue_file, grading_file)
            return
    except Exception as e:
        # Catch-all to help prevent the shipper from getting into a weird stuck state.
        config.logger.log_message(
            f"Unexpected error when attempting to short-circuit {queue_file}: {e}. "
            f"Attempting to grade normally. See stack traces for more details."
        )
        config.logger.log_stack_trace(
            traceback.format_exc()
        )

    # TODO: break which_machine into id, address, and passphrase.

    try:
        # prepare the job
        shipper_counter = 0
        random_identifier = string_utils.generate_random_string(64)
        while not prepare_job(
            config, my_name, which_machine, which_untrusted, my_dir, queue_file, random_identifier
        ):
            time.sleep(5)

        prep_job_success = True

        if not prep_job_success:
            print(my_name, " ERROR unable to prepare job: ", queue_file)
            config.logger.log_message(f"{my_name} ERROR unable to prepare job: {queue_file}")

        else:
            # then wait for grading to be completed
            shipper_counter = 0
            while unpack_job(
                config, which_machine, which_untrusted, my_dir, queue_file, random_identifier
            ) == GradingStatus.WAITING:
                shipper_counter += 1
                time.sleep(1)
                if shipper_counter >= 10:
                    print(my_name, which_untrusted, "shipper wait for grade: ", queue_file)
                    shipper_counter = 0

    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc())
        print(my_name, " ERROR attempting to grade item: ", queue_file, " exception=", str(e))
        config.logger.log_message(
            message=f"{my_name} ERROR attempting to grade item: {queue_file} exception={e}"
        )

    grading_cleanup(config, my_name, queue_file, grading_file)


def grading_cleanup(config, my_name, queue_file, grading_file):
    # note: not necessary to acquire lock for these statements, but
    # make sure you remove the queue file, then the grading file
    try:
        os.remove(queue_file)
    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc())
        print(f"{my_name} ERROR attempting to remove queue file: {queue_file} exception={e}")
        config.logger.log_message(
            f"{my_name} ERROR attempting to remove queue file: {queue_file} exception={e}"
        )
    try:
        os.remove(grading_file)
    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc())
        print(f"{my_name} ERROR attempting to remove grading file: {grading_file} exception={e}")
        config.logger.log_message(
            f"{my_name} ERROR attempting to remove grading file: {grading_file} exception={e}"
        )


# ==================================================================================
# ==================================================================================
def valid_github_user_id(userid):
    # Github username may only contain alphanumeric characters or
    # hyphens. Github username cannot have multiple consecutive
    # hyphens. Github username cannot begin or end with a hyphen.
    # Maximum is 39 characters.
    #
    # NOTE: We only scrub the input for allowed characters.
    if userid == '':
        # GitHub userid cannot be empty
        return False

    def checklegal(char):
        return char.isalnum() or char == '-'

    filtered_userid = ''.join(list(filter(checklegal, userid)))
    if not userid == filtered_userid:
        return False
    return True


def valid_github_repo_id(repoid):
    # Only characters, numbers, dots, minus and underscore are allowed.
    if repoid == '':
        # GitHub repoid cannot be empty
        return False

    def checklegal(char):
        return char.isalnum() or char == '.' or char == '-' or char == '_'

    filtered_repoid = ''.join(list(filter(checklegal, repoid)))
    if not repoid == filtered_repoid:
        return False
    return True


def checkout_vcs_repo(config, my_file):
    print("SHIPPER CHECKOUT VCS REPO ", my_file)

    with open(my_file, 'r') as infile:
        obj = json.load(infile)

    partial_path = os.path.join(obj["gradeable"], obj["who"], str(obj["version"]))
    course_dir = os.path.join(
        config.submitty['submitty_data_dir'],
        "courses",
        obj["semester"],
        obj["course"]
    )
    submission_path = os.path.join(course_dir, "submissions", partial_path)
    checkout_path = os.path.join(course_dir, "checkout", partial_path)
    results_path = os.path.join(course_dir, "results", partial_path)

    # Load the git branch for autgrading from the course config file
    which_branch = 'main'
    course_config_file = os.path.join(course_dir, "config", "config.json")
    with open(course_config_file) as open_file:
        COURSE_JSON = json.load(open_file)
        if 'git_autograding_branch' in COURSE_JSON['course_details']:
            which_branch = COURSE_JSON['course_details']['git_autograding_branch']
    # verify that the branch only contains alphabetic characters a-z
    if not re.match('^[a-z]+$', which_branch):
        which_branch = 'main'

    vcs_info = packer_unpacker.get_vcs_info(
        config,
        config.submitty['submitty_data_dir'],
        obj["semester"], obj["course"], obj["gradeable"], obj["who"], obj["team"]
    )
    is_vcs, vcs_type, vcs_base_url, vcs_subdirectory = vcs_info

    # cleanup the previous checkout (if it exists)
    shutil.rmtree(checkout_path, ignore_errors=True)
    os.makedirs(checkout_path, exist_ok=True)

    job_id = "~VCS~"

    try:
        # If we are public or private github, we will have an empty vcs_subdirectory
        if vcs_subdirectory == '':
            with open(
                os.path.join(submission_path, ".submit.VCS_CHECKOUT")
            ) as submission_vcs_file:
                VCS_JSON = json.load(submission_vcs_file)
                git_user_id = VCS_JSON["git_user_id"]
                git_repo_id = VCS_JSON["git_repo_id"]
                if not valid_github_user_id(git_user_id):
                    raise Exception("Invalid GitHub user/organization name: '"+git_user_id+"'")
                if not valid_github_repo_id(git_repo_id):
                    raise Exception("Invalid GitHub repository name: '"+git_repo_id+"'")
                # construct path for GitHub
                vcs_path = "https://www.github.com/"+git_user_id+"/"+git_repo_id

        # is vcs_subdirectory standalone or should it be combined with base_url?
        elif vcs_subdirectory[0] == '/' or '://' in vcs_subdirectory:
            vcs_path = vcs_subdirectory
        else:
            if '://' in vcs_base_url:
                vcs_path = urllib.parse.urljoin(vcs_base_url, vcs_subdirectory)
            else:
                vcs_path = os.path.join(vcs_base_url, vcs_subdirectory)

        # warning: --depth is ignored in local clones; use file:// instead.
        if '://' not in vcs_path:
            vcs_path = "file:///" + vcs_path

        Path(results_path+"/logs").mkdir(parents=True, exist_ok=True)
        checkout_log_file = os.path.join(results_path, "logs", "vcs_checkout.txt")

        # -----------------------------------------------------------
        # First see if the course-specified branch exists
        ls_branch_command = [
            '/usr/bin/git', 'ls-remote', '--exit-code', vcs_path, which_branch
        ]
        try:
            subprocess.check_call(ls_branch_command)
        except subprocess.CalledProcessError:
            # Then try to use the 'main' branch -- the recommended default
            # starting in 2020
            which_branch = 'main'
            ls_branch_command[4] = which_branch
            try:
                subprocess.check_call(ls_branch_command)
            except subprocess.CalledProcessError:
                # Last chance use the 'master' branch -- the default until 2020
                which_branch = 'master'

        # -----------------------------------------------------------

        # grab the submission time
        # with open(os.path.join(submission_path, ".submit.timestamp")) as submission_time_file:
        #     submission_string = submission_time_file.read().rstrip()

        # OPTION: A shallow clone with only the most recent commit
        # from the submission timestamp.
        #
        #   NOTE: if the student has set their computer time in the
        #     future, they could be confused that we don't grab their
        #     most recent code.
        #   NOTE: github repos currently fail (a bug?) with an error when
        #     --shallow-since is used:
        #     "fatal: The remote end hung up unexpectedly"
        #
        # clone_command = ['/usr/bin/git', 'clone', vcs_path, checkout_path,
        #                  '--shallow-since='+submission_string, '-b', '<WHICH_BRANCH>']

        # OPTION: A shallow clone, with just the most recent commit.
        #
        #  NOTE: If the server is busy, it might take seconds or
        #     minutes for an available shipper to process the git
        #     clone, and thethe timestamp might be slightly late)
        #
        #  So we choose this option!  (for now)
        #
        clone_command = [
            '/usr/bin/git', 'clone', vcs_path, checkout_path, '--depth', '1', '-b', which_branch
        ]

        with open(checkout_log_file, 'a') as f:
            print("VCS CHECKOUT", file=f)
            print('vcs_base_url', vcs_base_url, file=f)
            print('vcs_subdirectory', vcs_subdirectory, file=f)
            print('vcs_path', vcs_path, file=f)
            print(' '.join(clone_command), file=f)
            print("\n====================================\n", file=f)

        # git clone may fail -- because repository does not exist,
        # or because we don't have appropriate access credentials
        try:
            subprocess.check_call(clone_command)
            os.chdir(checkout_path)

            # determine which version we need to checkout
            # if the repo is empty or the specified branch does not exist, this command will fail
            try:
                what_version = subprocess.check_output(['git', 'rev-list', '-n', '1', which_branch])
                # old method:  when we had the full history, roll-back to a version by date
                # what_version = subprocess.check_output(['git', 'rev-list', '-n', '1',
                #                                         '--before="'+submission_string+'"',
                #                                         which_branch])
                what_version = str(what_version.decode('utf-8')).rstrip()
                if what_version == "":
                    # oops, pressed the grade button before a valid commit
                    shutil.rmtree(checkout_path, ignore_errors=True)
                # old method:
                # else:
                #    # and check out the right version
                #    subprocess.call(['git', 'checkout', '-b', 'grade', what_version])

                subprocess.call(['ls', '-lR', checkout_path], stdout=open(checkout_log_file, 'a'))
                print(
                    "\n====================================\n",
                    file=open(checkout_log_file, 'a')
                )
                subprocess.call(['du', '-skh', checkout_path], stdout=open(checkout_log_file, 'a'))
                obj['revision'] = what_version

            # exception on git rev-list
            except subprocess.CalledProcessError as error:
                config.logger.log_message(
                    f"ERROR: failed to determine version on {which_branch} branch {error}",
                    job_id=job_id
                )
                os.chdir(checkout_path)
                error_path = os.path.join(
                    checkout_path, "failed_to_determine_version_on_specifed_branch.txt"
                )
                with open(error_path, 'w') as f:
                    print(str(error), file=f)
                    print("\n", file=f)
                    print("Check to be sure the repository is not empty.\n", file=f)
                    print("Check to be sure the repository has a " + which_branch +
                          " branch.\n", file=f)
                    print(
                        "And check to be sure the timestamps on the " + which_branch +
                        " branch are reasonable.\n",
                        file=f
                    )

        # exception on git clone
        except subprocess.CalledProcessError as error:
            config.logger.log_message(f"ERROR: failed to clone repository {error}", job_id=job_id)
            os.chdir(checkout_path)
            error_path = os.path.join(checkout_path, "failed_to_clone_repository.txt")
            with open(error_path, 'w') as f:
                print(str(error), file=f)
                print("\n", file=f)
                print("Check to be sure the repository exists.\n", file=f)
                print(
                    "And check to be sure the submitty_daemon user has appropriate access "
                    "credentials.\n",
                    file=f
                )

    # exception in constructing full git repository url/path
    except Exception as error:
        config.logger.log_message(
            f"ERROR: failed to construct valid repository url/path {error}",
            job_id=job_id
        )
        os.chdir(checkout_path)
        error_path = os.path.join(checkout_path, "failed_to_construct_valid_repository_url.txt")
        with open(error_path, 'w') as f:
            print(str(error), file=f)
            print("\n", file=f)
            print("Check to be sure the repository exists.\n", file=f)
            print(
                "And check to be sure the submitty_daemon user has appropriate access "
                "credentials.\n",
                file=f)

    return obj


# ==================================================================================
def get_job(config, my_name, which_machine, my_capabilities, which_untrusted):
    """
    Pick a job from the queue.
    """

    time_get_job_begin = dateutils.get_current_time()

    folder = worker_folder(my_name)

    '''
    ----------------------------------------------------------------
    Our first priority is to perform any awaiting VCS checkouts

    Note: This design is imperfect:

        * If all shippers are busy working on long-running autograding
        tasks there will be a delay of seconds or minutes between
        a student pressing the submission button and clone happening.
        This is a minor exploit allowing them to theoretically
        continue working on their submission past the deadline for
        the time period of the delay.
        -- This is not a significant, practical problem.

        * If multiple and/or large git submissions arrive close
        together, this shipper job will be tied up performing these
        clone operations.  Because we don't release the lock, any
        other shippers that complete their work will also be blocked
        from either helping with the clones or tackling the next
        autograding job.
        -- Based on experience with actual submission patterns, we
            do not anticipate that this will be a significant
            bottleneck at this time.

        * If a git clone takes a very long time and/or hangs because of
        network problems, this could halt all work on the server.
        -- We'll need to monitor the production server.

    We plan to do a complete overhaul of the
    scheduler/shipper/worker and refactoring this design should be
    part of the project.
    ----------------------------------------------------------------
    '''

    # Grab all the VCS files currently in the folder...
    vcs_files = [str(f) for f in Path(folder).glob('VCS__*')]
    for f in vcs_files:
        vcs_file = f[len(folder)+1:]
        no_vcs_file = f[len(folder)+1+5:]
        # do the checkout
        updated_obj = checkout_vcs_repo(config, folder+"/"+vcs_file)
        # save the regular grading queue file
        with open(os.path.join(folder, no_vcs_file), "w") as queue_file:
            json.dump(updated_obj, queue_file)
        # cleanup the vcs queue file
        os.remove(folder+"/"+vcs_file)
    # ----------------------------------------------------------------

    # Grab all the files currently in the folder, sorted by creation
    # time, and put them in the queue to be graded
    files = [str(f) for f in Path(folder).glob('*')]
    files_and_times = list()
    for f in files:
        try:
            my_time = os.path.getctime(f)
        except Exception:
            continue
        tup = (f, my_time)
        files_and_times.append(tup)

    files_and_times = sorted(files_and_times, key=operator.itemgetter(1))
    my_job = ""

    for full_path_file, _file_time in files_and_times:
        # get the file name (without the path)
        just_file = full_path_file[len(folder)+1:]

        # skip items that are already being graded
        if just_file.startswith("GRADING_"):
            continue
        grading_file = os.path.join(folder, "GRADING_"+just_file)
        if grading_file in files:
            continue

        # skip items (very recently added!) that are already waiting for a VCS checkout
        if just_file.startswith("VCS__"):
            continue

        # found something to do
        try:
            with open(full_path_file, 'r') as infile:
                queue_obj = json.load(infile)
        except Exception:
            continue

        # Check to make sure that we are capable of grading this submission
        required_capabilities = queue_obj["required_capabilities"]
        if required_capabilities not in my_capabilities:
            continue

        # prioritize interactive jobs over (batch) regrades
        # if you've found an interactive job, exit early (since they are sorted by timestamp)
        if "regrade" not in queue_obj or not queue_obj["regrade"]:
            my_job = just_file
            break

        # otherwise it's a regrade, and if we don't already have a
        # job, take it, but we have to search the rest of the list
        if my_job == "":
            my_job = just_file

    if not my_job == "":
        grading_file = os.path.join(folder, "GRADING_" + my_job)
        # create the grading file
        with open(os.path.join(grading_file), "w") as queue_file:
            json.dump({"untrusted": which_untrusted, "machine": which_machine}, queue_file)

    time_get_job_end = dateutils.get_current_time()

    time_delta = time_get_job_end-time_get_job_begin
    if time_delta > datetime.timedelta(milliseconds=100):
        print(my_name, " WARNING: submitty_autograding shipper get_job time ", time_delta)
        config.logger.log_message(
            f"{my_name} WARNING: submitty_autograding shipper get_job time {time_delta}"
        )

    return (my_job)


# ==================================================================================
# ==================================================================================
def shipper_process(config, my_name, my_data, full_address, which_untrusted):
    """
    Each shipper process spins in a loop, looking for a job that
    matches the capabilities of this machine, and then oversees the
    autograding of that job.  Interactive jobs are prioritized over
    batch (regrade) jobs.  If no jobs are available, the shipper waits
    on an event editing one of the queues.
    """

    which_machine = full_address
    my_capabilities = my_data['capabilities']
    my_folder = worker_folder(my_name)

    # ignore keyboard interrupts in the shipper processes
    signal.signal(signal.SIGINT, signal.SIG_IGN)

    counter = 0
    while True:
        try:
            my_job = get_job(config, my_name, which_machine, my_capabilities, which_untrusted)
            if not my_job == "":
                counter = 0
                grade_queue_file(
                    config, my_name, which_machine, which_untrusted, os.path.join(my_folder, my_job)
                )
                continue
            else:
                if counter == 0 or counter >= 10:
                    # do not log this message, only print it to console when manual testing &
                    # debugging
                    print("{0} {1}: no available job".format(my_name, which_untrusted))
                    counter = 0
                counter += 1
                time.sleep(1)

        except Exception as e:
            config.logger.log_stack_trace(traceback.format_exc())
            my_message = (
                f"ERROR in get_job {which_machine} {which_untrusted} {str(e)}. "
                "For more details, see traces entry"
            )
            config.logger.log_message(my_message)
            time.sleep(1)


# ==================================================================================
# ==================================================================================
def is_testcase_submission_limit(testcase: dict) -> bool:
    """Check whether the given testcase object is a submission limit check."""
    return (
        testcase['type'] == 'FileCheck' and
        testcase['title'] == 'Submission Limit'
    )


def can_short_circuit(config_obj: dict) -> bool:
    """Check if a job can be short-circuited.

    Currently, a job can be short-circuited if either:

    * It has no autograding test cases
    * It has one autograding test case and that test case is the submission limit check.
    """

    base_testcases = config_obj['testcases']
    num_testcases = len(base_testcases)

    if 'item_pool' in config_obj:
        for item in config_obj['item_pool']:
            if 'testcases' in item:
                num_testcases += len(item['testcases'])

    # If there are no itempool or base testcases, we can short circuit
    if num_testcases == 0:
        # No test cases, so this is trivially short-circuitable.
        return True
    # If there is only one testcase and it is a base testcase, check if it is submission limit
    elif len(base_testcases) == 1 and num_testcases == 1:
        # We have only one test case; check if it's a submission limit check
        return is_testcase_submission_limit(base_testcases[0])
    else:
        return False


def check_submission_limit_penalty_inline(
    config_obj: dict,
    queue_obj: dict
) -> dict:
    """Check if a submission violates the submission limit.

    Note that this function makes the assumption that the file being graded is
    short-circuitable (i.e. only has one test case; the sentinel "submission
    limit" test case).
    """

    ###########################################################################
    #
    # NOTE: Editing this? Make sure this function stays in-sync with the
    #       my_testcase.isSubmissionLimit() branch in
    #       grading/main_validator.cpp::ValidateATestCase()
    #
    ###########################################################################

    # regrade.py seems to make this into a str, so force into int
    subnum = int(queue_obj['version'])
    testcase = config_obj['testcases'][0]
    penalty = testcase['penalty']
    possible_points = testcase['points']
    max_submissions = testcase['max_submissions']

    excessive = max(subnum - max_submissions, 0)
    points = floor(excessive * penalty)
    points = max(points, possible_points)
    view_testcase = points != 0

    return {
        'test_name': f"Test 1 {testcase['title']}",
        'view_testcase': view_testcase,
        'points_awarded': points
    }


def history_short_circuit_helper(
    base_path: str,
    course_path: str,
    queue_obj: dict,
    gradeable_config_obj: dict
) -> dict:
    """Figure out parameter values for just_write_grade_history."""
    user_path = os.path.join(
        course_path,
        'submissions',
        queue_obj['gradeable'],
        queue_obj['who'],
        str(queue_obj['version'])
    )
    submit_timestamp_path = os.path.join(
        user_path,
        '.submit.timestamp'
    )
    user_assignment_access_path = os.path.join(
        user_path,
        '.user_assignment_access.json'
    )

    gradeable_deadline = gradeable_config_obj['date_due']
    with open(submit_timestamp_path) as fd:
        submit_timestamp = dateutils.read_submitty_date(fd.read().rstrip())
    submit_time = dateutils.write_submitty_date(submit_timestamp)
    gradeable_deadline_dt = dateutils.read_submitty_date(gradeable_deadline)

    seconds_late = int((submit_timestamp - gradeable_deadline_dt).total_seconds())

    first_access = ''
    access_duration = -1
    if os.path.exists(user_assignment_access_path):
        with open(user_assignment_access_path) as fd:
            obj = json.load(fd, object_pairs_hook=collections.OrderedDict)
        if len(obj) == 0:
            # this can happen if the student never clicks on the page and the
            # instructor makes a submission for the student
            pass
        else:
            first_access = obj[0]['timestamp']
            first_access_dt = dateutils.read_submitty_date(first_access)
            access_duration = int((submit_timestamp - first_access_dt).total_seconds())

    return {
        'gradeable_deadline': gradeable_deadline,
        'submission': submit_time,
        'seconds_late': seconds_late,
        'first_access': first_access,
        'access_duration': access_duration
    }


def try_short_circuit(config: dict, queue_file: str) -> bool:
    """Attempt to short-circuit the job represented by the given queue file.

    Returns True if the job is short-circuitable and was successfully
    short-circuited.

    This function will first check if the queue file represents a gradeable
    that supports short-circuiting. If so, then this function will attempt to
    short-circuit the job within this child shipper process.

    Once the job is finished grading, then this function will write the history
    JSON file, zip up the results, and use the standard
    unpack_grading_results_zip function to place the results where they are
    expected. If something goes wrong during this process, then this function
    will return False, signaling to the caller that this job should be graded
    normally.
    """
    with open(queue_file) as fd:
        queue_obj = json.load(fd)

    gradeable_id = f"{queue_obj['semester']}/{queue_obj['course']}/{queue_obj['gradeable']}"

    course_path = os.path.join(
        config.submitty['submitty_data_dir'],
        'courses',
        queue_obj['semester'],
        queue_obj['course']
    )

    config_path = os.path.join(
        course_path,
        'config',
        'complete_config',
        f'complete_config_{queue_obj["gradeable"]}.json'
    )

    gradeable_config_path = os.path.join(
        course_path, 'config', 'form', f'form_{queue_obj["gradeable"]}.json'
    )

    # Some of the config files may disappear if we're running this step and the gradeable is
    # currently in the process of being re-built. In this case, we give up on trying to
    # short-circuit and let the full autograder handle it. Best-case scenario, the config once
    # again exists and we can grade it normally. Worst-case scenario, the config is still out of
    # commission, but the grader is better-equipped to handle this scenario.
    try:
        with open(config_path) as fd:
            config_obj = json.load(fd)
        with open(gradeable_config_path) as fd:
            gradeable_config_obj = json.load(fd)
    except FileNotFoundError as e:
        config.logger.log_message(
            f"Error when short-circuiting: could not find configs for {gradeable_id}: {e}. "
            f"Attempting to grade normally.",
            jobname=gradeable_id,
        )
        return False

    job_id = ''.join(random.choice(string.ascii_letters + string.digits) for _ in range(6))
    config.logger.log_message(f"Short-circuiting {gradeable_id}", job_id=job_id)

    if not can_short_circuit(config_obj):
        return False

    # Augment the queue object
    base, path = os.path.split(queue_file)
    queue_time = packer_unpacker.get_queue_time(base, path)
    queue_time_longstring = dateutils.write_submitty_date(queue_time)
    grading_began = dateutils.get_current_time()
    wait_time = (grading_began - queue_time).total_seconds()

    queue_obj.update(
        queue_time=queue_time_longstring,
        regrade=queue_obj.get('regrade', False),
        waittime=wait_time,
        job_id=job_id
    )

    base_dir = tempfile.mkdtemp()

    results_dir = os.path.join(base_dir, 'TMP_RESULTS')
    results_json_path = os.path.join(results_dir, 'results.json')
    grade_txt_path = os.path.join(results_dir, 'grade.txt')
    queue_file_json_path = os.path.join(results_dir, 'queue_file.json')
    history_json_path = os.path.join(results_dir, 'history.json')
    logs_dir = os.path.join(results_dir, 'logs')

    os.makedirs(results_dir, exist_ok=True)
    os.makedirs(logs_dir, exist_ok=True)

    testcases = config_obj['testcases']
    testcase_outputs = []

    # This will probably always run, but it gives us a degree of
    # future-proofing.
    if len(testcases) > 0:
        testcase_outputs.append(
            check_submission_limit_penalty_inline(config_obj, queue_obj)
        )

    autograde_result_msg = write_grading_outputs(
        testcases,
        testcase_outputs,
        results_json_path,
        grade_txt_path
    )

    grading_finished = dateutils.get_current_time()
    grading_time = (grading_finished - grading_began).total_seconds()

    queue_obj['gradingtime'] = grading_time
    queue_obj['grade_result'] = autograde_result_msg
    queue_obj['which_untrusted'] = '(short-circuited)'
    queue_obj['which_machine'] = '(short-circuited)'

    # Save the augmented queue object
    with open(queue_file_json_path, 'w') as fd:
        json.dump(
            queue_obj, fd, indent=4, sort_keys=True, separators=(',', ':')
        )

    h = history_short_circuit_helper(
        base_dir,
        course_path,
        queue_obj,
        gradeable_config_obj
    )

    try:
        autograding_utils.just_write_grade_history(
            history_json_path,
            h['gradeable_deadline'],
            h['submission'],
            h['seconds_late'],
            h['first_access'],
            h['access_duration'],
            queue_obj['queue_time'],
            'BATCH' if queue_obj.get('regrade', False) else 'INTERACTIVE',
            dateutils.write_submitty_date(grading_began),
            int(queue_obj['waittime']),
            dateutils.write_submitty_date(grading_finished),
            int(grading_time),
            autograde_result_msg,
            queue_obj.get('revision', None)
        )

        results_zip_path = os.path.join(base_dir, 'results.zip')
        autograding_utils.zip_my_directory(results_dir, results_zip_path)
        packer_unpacker.unpack_grading_results_zip(
            config, '(short-circuit)', '(short-circuit)', results_zip_path
        )
    except Exception:
        config.logger.log_message(
            f"Short-circuit failed for {gradeable_id} (check stack traces). "
            "Falling back to standard grade.", job_id=job_id,
        )
        config.logger.log_stack_trace(traceback.format_exc(), job_id=job_id)
        return False
    finally:
        shutil.rmtree(base_dir, ignore_errors=True)

    config.logger.log_message(f"Successfully short-circuited {gradeable_id}!", job_id=job_id)
    return True


def write_grading_outputs(
    testcases: list,
    testcase_outputs: list,
    results_json: str,
    grade_txt: str
) -> str:
    """Write the grading output data to the specified paths."""

    ###########################################################################
    #
    # NOTE: Editing this file? Make sure that this function stays in-sync with
    #       grading/main_validator.cpp::validateTestCase()
    #
    ###########################################################################

    results = {'testcases': testcase_outputs}
    with open(results_json, 'w') as fd:
        json.dump(results, fd, indent=4)

    max_auto_points = sum(tc['points'] for tc in testcases)
    max_nonhidden_auto_points = sum(
        tc['points'] for tc in testcases if not tc.get('hidden', False)
    )

    auto_points = sum(r['points_awarded'] for r in testcase_outputs)
    nonhidden_auto_points = sum(
        r['points_awarded']
        for r, tc in zip(testcase_outputs, testcases)
        if not tc.get('hidden', False)
    )

    with open(grade_txt, 'w') as fd:
        # Write each test case's individual output
        for i, tc in enumerate(testcases):
            title = tc['title']
            extra_credit = tc.get('extra_credit', False)
            max_points = tc['points']
            points = testcase_outputs[i]['points_awarded']
            fd.write(f"Testcase {i:3}: {title:<50} ")

            if extra_credit:
                if points > 0:
                    fd.write(f"+{points:2} points")
                else:
                    fd.write(' ' * 10)
            elif max_points < 0:
                if points < 0:
                    fd.write(f"{points:3} points")
                else:
                    fd.write(' ' * 10)
            else:
                fd.write(f"{points:3} / {max_points:3}  ")

            if tc.get('hidden', False):
                fd.write("  [ HIDDEN ]")
            fd.write('\n')

        # Write the final lines
        autograde_total_msg = (
            f"{'Automatic grading total:':<64}{auto_points:3} /"
            f"{max_auto_points:3}\n"
        )
        fd.write(autograde_total_msg)
        fd.write(f"{'Non-hidden automatic grading total:':<64}{nonhidden_auto_points:3} /"
                 f"{max_nonhidden_auto_points:3}\n")
    return autograde_total_msg


# ==================================================================================
# ==================================================================================
def cleanup_shippers(config, worker_status_map, autograding_workers):
    print("CLEANUP SHIPPERS")
    config.logger.log_message("cleanup prior to launching submitty_autograding_shipper.py")

    # remove the temporary files for any incomplete autograding
    for file_path in Path(
        config.submitty['submitty_data_dir'],
        "autograding_TODO"
    ).glob("untrusted*"):
        file_path = str(file_path)
        config.logger.log_message(f"Remove autograding TODO file: {file_path}")
        os.remove(file_path)

    for file_path in Path(config.submitty['submitty_data_dir'], "autograding_DONE").glob("*"):
        file_path = str(file_path)
        config.logger.log_message(f"Remove autograding DONE file: {file_path}")
        os.remove(file_path)

    # clean up the worker queue files and queue directories (they will be recreated)
    for p in Path(IN_PROGRESS_PATH).glob('*'):
        for f in Path(p).glob('*'):
            dname, fname = os.path.split(f)
            if fname.startswith("GRADING_"):
                os.remove(f)
                print(f"canceling in progress job: {fname}")
            else:
                try:
                    shutil.move(str(f), INTERACTIVE_QUEUE)
                    print(f"Returned job to the to_be_graded_queue: {fname}")
                except Exception as e:
                    print(f"WARNING: Failed to return job: {fname} ERROR: {e}")
                    os.remove(f)
        os.rmdir(p)
        print(f"cleaned up directory: {p}")


# ==================================================================================
# ==================================================================================
def launch_shippers(config, worker_status_map, autograding_workers) -> List[scheduler.Worker]:
    print("LAUNCH SHIPPERS")
    config.logger.log_message("submitty_autograding_shipper.py launched")

    # Launch a shipper process for every worker on the primary machine and each worker machine
    shippers = []
    for name, machine in autograding_workers.items():
        # SKIP MACHINES THAT ARE NOT ENABLED OR NOT REACHABLE
        if not machine['enabled']:
            print(f"NOTE: MACHINE {name} is not enabled")
            config.logger.log_message(f"NOTE: MACHINE {name} is not enabled")
            continue
        if not worker_status_map[name]:
            print(f"ERROR: MACHINE {name} could not be reached => no shipper threads.")
            config.logger.log_message(
                f"ERROR: MACHINE {name} could not be reached => no shipper threads."
            )
            continue

        # CREATE THE QUEUE FILE DIRECTORIES
        num_workers_on_machine = machine["num_autograding_workers"]
        if num_workers_on_machine < 0:
            raise SystemExit(
                f"ERROR: num_workers_on_machine for '{machine}' must be non-negative."
            )
        worker_folders = [worker_folder(f'{name}_{i}') for i in range(num_workers_on_machine)]
        for folder in worker_folders:
            os.makedirs(folder, exist_ok=True)

        # PREPARE FULL ADDRESS
        try:
            full_address = ""
            if machine["address"] != "localhost":
                if machine["username"] == "":
                    raise SystemExit(
                        f"ERROR: empty username for worker machine {machine['address']}"
                    )
                full_address = f'{machine["username"]}@{machine["address"]}'
            else:
                if not machine["username"] == "":
                    raise SystemExit('ERROR: username for primary (localhost) must be ""')
                full_address = machine['address']
            single_machine_data = {name: machine}
            single_machine_data = add_fields_to_autograding_worker_json(
                config,
                single_machine_data,
                name
            )
        except Exception as e:
            config.logger.log_stack_trace(traceback.format_exc())
            print(f"ERROR: autograding_workers.json entry for {name} contains an error: {e}. "
                  "For more details, see trace entry.")
            config.logger.log_message(
                f"ERROR: autograding_workers.json entry for {name} contains an error: {e} "
                "For more details, see trace entry."
            )
            continue

        # LAUNCH SHIPPER THREADS
        for i in range(num_workers_on_machine):
            thread_name = f'{name}_{i}'
            u = "untrusted" + str(i).zfill(2)
            p = multiprocessing.Process(
                target=shipper_process,
                args=(config, thread_name, single_machine_data[name], full_address, u)
            )
            p.start()
            shipper = scheduler.Worker(config, thread_name, machine, p)
            shippers.append(shipper)

    return shippers


def get_job_requirements(job_file):
    try:
        with open(job_file, 'r') as infile:
            job_obj = json.load(infile)
        return job_obj["required_capabilities"]
    except Exception:
        print(f"ERROR: This job does not have required capabilities: {job_file}")
        return None


def worker_job_match(worker, autograding_workers, job_requirements):
    try:
        machine = worker.split("_")[0]
        capabilities = autograding_workers[machine]["capabilities"]
        return job_requirements in capabilities
    except Exception:
        print(f"ERROR: This worker / machine does not have capabilities {worker}")
        return None


def monitoring_loop(
    config: submitty_config.Config,
    processes: List[scheduler.Worker]
):

    print("MONITORING LOOP")
    sched = scheduler.FCFSScheduler(config, processes)
    total_num_workers = len(processes)

    # main monitoring loop
    try:
        while True:
            sched.update_and_schedule()
            time.sleep(1)

    except KeyboardInterrupt:
        config.logger.log_message("grade_scheduler.py keyboard interrupt")
        # just kill everything in this group id right now
        # NOTE:  this may be a bug if the grandchildren have a different group id and not be killed
        os.kill(-os.getpid(), signal.SIGKILL)

        # run this to check if everything is dead
        #    ps  xao pid,ppid,pgid,sid,comm,user  | grep untrust

        # everything's dead, including the main process so the rest of this will be ignored
        # but this was mostly working...

        # terminate the jobs
        for i in range(0, total_num_workers):
            processes[i].terminate()
        # wait for them to join
        for i in range(0, total_num_workers):
            processes[i].join()

    config.logger.log_message("grade_scheduler.py terminated")


# ==================================================================================
def load_autograding_workers_json(config: submitty_config.Config):
    print("LOAD AUTOGRADING WORKERS JSON")

    # The names of the worker machines, the capabilities of each
    # worker machine, and the number of workers per machine are stored
    # in the autograding_workers json.
    try:
        autograding_workers_path = os.path.join(
            config.submitty['submitty_install_dir'], 'config', "autograding_workers.json"
        )
        with open(autograding_workers_path, 'r') as infile:
            autograding_workers = json.load(infile)
    except Exception as e:
        config.logger.log_stack_trace(traceback.format_exc())
        raise SystemExit(f"ERROR: could not locate the autograding workers json: {e}")

    # There must always be a primary machine, it may or may not have
    # autograding workers.
    if "primary" not in autograding_workers:
        raise SystemExit("ERROR: autograding_workers.json contained no primary machine.")

    # One (or more) of the machines must accept "default" jobs.
    default_present = False
    for _name, machine in autograding_workers.items():
        if not machine["enabled"]:
            continue
        if "default" in machine["capabilities"]:
            default_present = True
            break
    if not default_present:
        raise SystemExit(
            "ERROR: autograding_workers.json contained no enabled machine with default capabilities"
        )

    return autograding_workers


# ==================================================================================
if __name__ == "__main__":

    config_dir = os.path.join(os.path.dirname(os.path.realpath(__file__)), '..', 'config')
    config = submitty_config.Config.path_constructor(config_dir, JOB_ID)

    instantiate_global_variables(config)

    # verify the DAEMON_USER is running this script
    if not int(os.getuid()) == int(config.submitty_users['daemon_uid']):
        raise SystemExit(
            "ERROR: the submitty_autograding_shipper.py script must be run by the DAEMON_USER"
        )

    autograding_workers = load_autograding_workers_json(config)
    worker_status_map = update_remote_autograding_workers(config, autograding_workers)
    cleanup_shippers(config, worker_status_map, autograding_workers)
    processes = launch_shippers(config, worker_status_map, autograding_workers)
    monitoring_loop(config, processes)
