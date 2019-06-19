"""
Module that contains all of the jobs that the Submitty Daemon can do
"""

from abc import ABC, abstractmethod
import os
from pathlib import Path
import shutil
import subprocess
import json
import stat
import traceback
import datetime
import fcntl
from urllib.parse import unquote
from . import bulk_qr_split
from . import bulk_upload_split
from . import INSTALL_DIR, DATA_DIR
from . import write_to_log as logger


class AbstractJob(ABC):
    """
    Abstract class that all jobs should extend from, creating a common
    interface that can be expected for all jobs
    """

    required_keys = []

    def __init__(self, job_details):
        self.job_details = job_details

    def has_required_keys(self):
        """
        Validates that the job_details contains all keys specified in
        the required_keys class variable, and that the values for them
        is not None
        """
        for key in self.required_keys:
            if key not in self.job_details or self.job_details[key] is None:
                return False
        return True

    def validate_job_details(self):
        """
        Checks to see if the passed in job details contain all
        necessary components to run the particular job.
        """
        return True

    @abstractmethod
    def run_job(self):
        pass

    def cleanup_job(self):
        pass


# pylint: disable=abstract-method
class CourseJob(AbstractJob):
    """
    Base class for jobs that involve operation for a course.
    This class validates that the job details includes a semester
    and course and that they are valid directories within Submitty
    """

    required_keys = [
        'semester',
        'course'
    ]

    def validate_job_details(self):
        for key in ['semester', 'course']:
            if key not in self.job_details or self.job_details[key] is None:
                return False
            if self.job_details[key] in ['', '.', '..']:
                return False
            if self.job_details[key] != os.path.basename(self.job_details[key]):
                return False
        test_path = Path(DATA_DIR, 'courses', self.job_details['semester'], self.job_details['course'])
        return test_path.exists()


# pylint: disable=abstract-method
class CourseGradeableJob(CourseJob):
    """
    Base class for jobs that involve a semester/course as well as a gradeable, validating
    that we have a gradeable within our job details, and that it's not empty nor just a
    dot or two dots.
    """

    required_keys = CourseJob.required_keys + ['gradeable']

    def validate_job_details(self):
        if not super().validate_job_details():
            return False
        if 'gradeable' not in self.job_details or self.job_details['gradeable'] is None:
            return False
        if self.job_details['gradeable'] != os.path.basename(self.job_details['gradeable']):
            return False
        self.job_details['gradeable'] = os.path.basename(self.job_details['gradeable'])
        return self.job_details['gradeable'] not in ['', '.', '..']


class BuildConfig(CourseGradeableJob):
    def run_job(self):
        semester = self.job_details['semester']
        course = self.job_details['course']
        gradeable = self.job_details['gradeable']

        build_script = os.path.join(DATA_DIR, 'courses', semester, course, 'BUILD_{}.sh'.format(course))
        build_output = os.path.join(DATA_DIR, 'courses', semester, course, 'build_script_output.txt')

        try:
            with open(build_output, "w") as output_file:
                subprocess.call([build_script, gradeable, "--clean"], stdout=output_file, stderr=output_file)
        except PermissionError:
            print("error, could not open "+output_file+" for writing")


class RunLichen(CourseGradeableJob):
    def run_job(self):
        semester = self.job_details['semester']
        course = self.job_details['course']
        gradeable = self.job_details['gradeable']

        lichen_script = Path(INSTALL_DIR, 'sbin', 'run_lichen_plagiarism.py')

        # create directory for logging
        logging_dir = os.path.join(DATA_DIR, 'courses', semester, course, 'lichen', 'logs', gradeable)
        if(not os.path.isdir(logging_dir)):
            os.makedirs(logging_dir)

        lichen_output = Path(DATA_DIR, 'courses', semester, course, 'lichen', 'logs', gradeable, 'lichen_job_output.txt')

        try:
            with lichen_output.open("w") as output_file:
                subprocess.call([str(lichen_script), semester, course, gradeable], stdout=output_file, stderr=output_file)
        except PermissionError:
            print("error, could not open "+output_file+" for writing")


class DeleteLichenResult(CourseGradeableJob):
    def run_job(self):
        semester = self.job_details['semester']
        course = self.job_details['course']
        gradeable = self.job_details['gradeable']

        lichen_dir = Path(DATA_DIR, 'courses', semester, course, 'lichen')
        if not lichen_dir.exists():
            return

        log_file = Path(DATA_DIR, 'courses', semester, course, 'lichen', 'logs', gradeable, 'lichen_job_output.txt')

        with log_file.open('w') as open_file:
            lichen_json = 'lichen_{}_{}_{}.json'.format(semester, course, gradeable)
            config = Path(lichen_dir, 'config', lichen_json)
            if config.exists():
                config.unlink()
            ranking = Path(lichen_dir, 'ranking', '{}.txt'.format(gradeable))
            if ranking.exists():
                ranking.unlink()
            for folder in ['provided_code', 'tokenized', 'concatenated', 'hashes', 'matches']:
                shutil.rmtree(str(Path(lichen_dir, folder, gradeable)), ignore_errors=True)
            msg = 'Deleted lichen plagiarism results and saved config for {}'.format(gradeable)
            open_file.write(msg) 

class BulkUpload(CourseJob):
    required_keys = CourseJob.required_keys + ['timestamp', 'g_id', 'filename', 'is_qr']

    def add_permissions(self,item,perms):
        if os.getuid() == os.stat(item).st_uid:
            os.chmod(item,os.stat(item).st_mode | perms)

    def add_permissions_recursive(self,top_dir,root_perms,dir_perms,file_perms):
        for root, dirs, files in os.walk(top_dir):
            self.add_permissions(root,root_perms)
        for d in dirs:
            self.add_permissions(os.path.join(root, d),dir_perms)
        for f in files:
            self.add_permissions(os.path.join(root, f),file_perms)

    def run_job(self):
        semester = self.job_details['semester']
        course = self.job_details['course']
        timestamp = self.job_details['timestamp']
        gradeable_id = self.job_details['g_id']
        filename = self.job_details['filename']
        is_qr = self.job_details['is_qr']

        if is_qr and ('qr_prefix' not in self.job_details or 'qr_suffix' not in self.job_details):
            msg = "did not pass in qr prefix or suffix"
            print(msg)
            sys.exit(1)

        if is_qr:
            qr_prefix = unquote(unquote(self.job_details['qr_prefix']))
            qr_suffix = unquote(unquote(self.job_details['qr_suffix']))
        else:
            if 'num' not in self.job_details:
                msg = "Did not pass in the number to divide " + filename + " by"
                print(msg)
                sys.exit(1)
            num  = self.job_details['num']

        today = datetime.datetime.now()
        log_path = os.path.join(DATA_DIR, "logs", "bulk_uploads")
        log_file_path = os.path.join(log_path, 
                                "{:04d}{:02d}{:02d}.txt".format(today.year, today.month,
                                today.day))

        pid = os.getpid()
        log_msg = "Process " + str(pid) + ": Starting to split " + filename + " on " + timestamp + ". "
        if is_qr:
            log_msg += "QR bulk upload job, QR Prefx: \'" + qr_prefix + "\', QR Suffix: \'" + qr_suffix + "\'"
        else:
            log_msg += "Normal bulk upload job, pages per PDF: " + str(num)
        
        try:
            logger.write_to_log(log_file_path, log_msg)
        except Exception as err:
            print(err)
            traceback.print_exc()
            sys.exit(1)

        #create paths
        try:
            with open("/usr/local/submitty/config/submitty.json", encoding='utf-8') as data_file:
                CONFIG = json.loads(data_file.read())

            current_path = os.path.dirname(os.path.realpath(__file__))
            uploads_path = os.path.join(CONFIG["submitty_data_dir"],"courses",semester,course,"uploads")
            bulk_path = os.path.join(CONFIG["submitty_data_dir"],"courses",semester,course,"uploads/bulk_pdf",gradeable_id,timestamp)
            split_path = os.path.join(CONFIG["submitty_data_dir"],"courses",semester,course,"uploads/split_pdf",gradeable_id,timestamp)
        except Exception:
            msg = "Process " + str(pid) + ": Failed while parsing args and creating paths"
            print(msg)
            traceback.print_exc()
            logger.write_to_log(log_file_path, msg + "\n" + traceback.format_exc())
            root_split_path = os.path.join(CONFIG["submitty_data_dir"],"courses",semester,course,"uploads/split_pdf")
        except Exception as err:
            msg = "Process " + str(pid) + ": Failed while parsing args and creating paths"
            print(msg)
            traceback.print_exc()
            logger.write_to_log(log_file_path, msg + "\n" + traceback.format_exc())
            sys.exit(1)

        #copy file over to correct folders
        try:
            if not os.path.exists(split_path):
                #if the directory has been made by another job continue as normal
                try:
                    os.makedirs(split_path)
                except Exception:
                    pass

            # copy over file to new directory
            if not os.path.isfile(os.path.join(split_path, filename)):
                shutil.copyfile(os.path.join(bulk_path, filename), os.path.join(split_path, filename))

            # move to copy folder
            os.chdir(split_path)
        except Exception:
            msg = "Process " + str(pid) + ": Failed while copying files"
            print(msg)
            traceback.print_exc()
            logger.write_to_log(log_file_path, msg + "\n" + traceback.format_exc())
            sys.exit(1)

        try:
            if is_qr:
                bulk_qr_split.main([filename, split_path, qr_prefix, qr_suffix, log_file_path])
            else: 
                bulk_upload_split.main([filename, split_path, num, log_file_path])

            os.chdir(split_path)
            #if the original file has been deleted, continue as normal
            try:
                os.remove(filename)
            except Exception:
                pass

            os.chdir(current_path)
        except Exception:
            msg = "Failed to launch bulk_split subprocess!"
            print(msg)
            traceback.print_exc()
            sys.exit(1)

        # reset permissions just in case, group needs read/write
        # access so submitty_php can view & delete pdfs when they are
        # assigned to a student and/or deleted
        self.add_permissions_recursive(root_split_path,
                                       stat.S_IRUSR | stat.S_IWUSR | stat.S_IXUSR |   stat.S_IRGRP | stat.S_IWGRP | stat.S_IXGRP |   stat.S_ISGID,
                                       stat.S_IRUSR | stat.S_IWUSR | stat.S_IXUSR |   stat.S_IRGRP | stat.S_IWGRP | stat.S_IXGRP |   stat.S_ISGID,
                                       stat.S_IRUSR | stat.S_IWUSR |                  stat.S_IRGRP | stat.S_IWGRP                                  )
