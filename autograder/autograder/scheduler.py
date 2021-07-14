"""The shipper scheduler."""

import contextlib
import json
import multiprocessing
import os
import random
import shutil
import traceback

from abc import ABC, abstractmethod
from typing import List, Optional

from .config import Config


class Job:
    """A representation of an autograding job.

    Contains the path to the queue file for this job, as well as a lazily-loaded instance of the
    contents of its queue file.

    Parameters
    ----------
    config: Config
        Submitty configuration object.
    path : str
        Path to this job's queue file.
    """
    def __init__(self, config: Config, path: str):
        self.config = config
        self.path = path
        self._queue_obj = None

    def _try_load_queue_object(self) -> bool:
        """Try to load the queue object from its path.

        This function is a no-op if the queue object has already been loaded.

        If the file has already been loaded, or if the file was loaded successfully, this function
        returns True. If an error occurs when loading the file, then this function logs the error
        and returns False.
        """
        if self._queue_obj is None:
            # Queue object isn't loaded yet, load it.
            try:
                with open(self.path) as f:
                    self._queue_obj = json.load(f)
            except Exception as e:
                self.config.logger.log_message(
                    f"ERROR: Could not load queue file {self.path}: {e}. "
                    "See stack traces for more details.",
                )
                self.config.logger.log_stack_trace(
                    traceback.format_exc(),
                )
                return False
        # The queue object was either already loaded or was successfully loaded, so return True.
        return True

    @property
    def queue_obj(self) -> Optional[dict]:
        """Get the queue object for the job, returning None if it could not be loaded."""
        if not self._try_load_queue_object():
            return None
        return self._queue_obj


class Worker:
    """A representation of an autograder instance.

    Parameters
    ----------
    config : Config
        Submitty configuration object.
    name : str
        Name used to uniquely identify this worker.
    properties : dict
        A dictionary mapping property names to property values.
    shipper_process : multiprocessing.Process
        The shipper process that will ship jobs to this worker.
    """

    def __init__(
        self,
        config: Config,
        name: str,
        properties: dict,
        shipper_process: multiprocessing.Process,
    ):
        self.config = config
        self.name = name
        self.properties = properties
        self.process = shipper_process
        self.folder = os.path.join(
            self.config.submitty['submitty_data_dir'],
            'in_progress_grading',
            self.name,
        )

    def is_enabled(self) -> bool:
        """Check if this worker is enabled."""
        return self.properties.get('enabled', False)

    def is_shipper_process_alive(self) -> bool:
        """Check if the worker's corresponding shipper process is still alive."""
        return self.process.is_alive()

    def is_idle(self) -> bool:
        """Check whether this worker's individual job queue is empty."""
        return len(os.listdir(self.folder)) == 0

    def can_run(self, job: Job) -> bool:
        """Check whether this worker can run the given job.

        A worker can run a job if and only if its `capabilities` dictionary contains all of the
        capabilities in the job's `required_capabilities` dictionary.
        """
        if job.queue_obj is None:
            self.config.logger.log_message(
                f"NOTE: Skipping over {job.path}."
            )
            return False
        if 'required_capabilities' not in job.queue_obj:
            self.config.logger.log_message(
                f"ERROR: Queue file at {job.path} missing `required_capabilities` key"
            )
            return False
        requirements = job.queue_obj['required_capabilities']
        return requirements in self.properties['capabilities']


class BaseScheduler(ABC):
    """Base class for Submitty job schedulers.

    Subclasses should override the `_assign_jobs` method, which is the important piece of
    functionality behind each scheduler.

    Parameters
    ----------
    config : Config
        Submitty configuration object.
    workers: list of Worker
        The workers that this scheduler has to schedule jobs for.
    """

    def __init__(self, config: Config, workers: List[Worker]):
        self.config = config
        self.workers = workers

        self.queue_folder = os.path.join(
            self.config.submitty['submitty_data_dir'],
            'to_be_graded_queue'
        )

    def _list_jobs(self) -> list:
        """Get a list of paths to jobs to be scheduled.

        Note that these paths have no intrinsic order to them.
        """
        jobs = [
            Job(self.config, os.path.join(self.queue_folder, file))
            for file in os.listdir(self.queue_folder)
            if os.path.isfile(os.path.join(self.queue_folder, file))
        ]
        return jobs

    def update_and_schedule(self):
        """Check for new jobs and schedule them onto workers, if applicable."""
        for worker in self.workers:
            if not worker.is_shipper_process_alive():
                self.config.logger.log_message(
                    f"WARNING: Worker process {worker.name} is not alive!"
                )
        self._assign_jobs(self._list_jobs())

    @abstractmethod
    def _assign_jobs(self, jobs: List[Job]):
        """Assign the jobs in `jobs` to workers."""
        raise NotImplementedError()


class FCFSScheduler(BaseScheduler):
    """The default first come, first serve scheduler.

    Jobs are assigned randomly to compatible workers, prioritizing jobs submitted earlier over
    those submitted later.
    """

    def __init__(self, config: Config, workers: List[Worker]):
        super().__init__(config, workers)

    def _assign_jobs(self, jobs: List[Job]):
        idle_workers = [worker for worker in self.workers if worker.is_idle()]

        jobs.sort(key=lambda j: os.stat(j.path).st_ctime_ns)

        for job in jobs:
            if len(idle_workers) == 0:
                break

            matching_workers = [worker for worker in idle_workers if worker.can_run(job)]
            if len(matching_workers) == 0:
                # If no registered worker can handle this job, then print a message to the log
                # and remove the queue entry. This will trigger the "Something has gone wrong"
                # message on the student's side.
                #
                # TODO: This should dump an error message to the `results` directory that
                #       exposes the error message in the UI, but that's for a future PR.
                if not any(worker.can_run(job) for worker in self.workers):
                    if job.queue_obj is not None:
                        self.config.logger.log_message(
                            f"ERROR: no worker compatible with job {job.path}: no worker has "
                            f"capability {job.queue_obj['required_capabilities']}. Removing."
                        )
                    else:
                        self.config.logger.log_message(
                            f"ERROR: could not load queue object for job {job.path}. Removing."
                        )
                    with contextlib.suppress(FileNotFoundError):
                        os.remove(job.path)
                continue

            dest = random.choice(matching_workers)
            shutil.move(job.path, dest.folder)
            idle_workers.remove(dest)
