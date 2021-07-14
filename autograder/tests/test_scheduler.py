import contextlib
import json
import os
import shutil
import time
import unittest

from unittest import mock

from autograder import config
from autograder.scheduler import FCFSScheduler, Worker

SCRIPT_DIR = os.path.dirname(os.path.realpath(__file__))
# Path any auxiliary test files (e.g. autograding configs)
TEST_DATA_SRC_DIR = os.path.join(SCRIPT_DIR, 'data')

# Where to dump intermediate test files
# The GIT_CHECKOUT folder is guaranteed to be within the Submitty install dir.
WORKING_DIR = os.path.abspath(
    os.path.join('..', '..', '..', 'test_suite', 'unitTests', 'autograder')
)
# Path to place our temporary data files
TEST_DATA_DIR = os.path.join(WORKING_DIR, 'data')
# Equivalent to install dir
TEST_ENVIRONMENT = os.path.join(WORKING_DIR, 'test_environment')

# Holds all system config files (equivalent to /usr/local/submitty/config)
CONFIG_DIR = os.path.join(TEST_ENVIRONMENT, 'config')
SUBMITTY_DATA_DIR = os.path.join(TEST_ENVIRONMENT, 'autograding')

# Autograding directories
TODO_DIR = os.path.join(SUBMITTY_DATA_DIR, 'autograding_TODO')
DONE_DIR = os.path.join(SUBMITTY_DATA_DIR, 'autograding_DONE')
TO_BE_GRADED = os.path.join(SUBMITTY_DATA_DIR, 'to_be_graded_queue')
GRADING = os.path.join(SUBMITTY_DATA_DIR, "in_progress_grading")

WORKER_PROPERTIES = {
    "worker_0": {
        "capabilities": [
            "default", "zero",
        ],
        "enabled": True,
        "num_autograding_workers": 1,
    },
    "worker_1": {
        "capabilities": [
            "default", "one",
        ],
        "enabled": True,
        "num_autograding_workers": 1,
    },
    "worker_2": {
        "capabilities": [
            "default", "two",
        ],
        "enabled": True,
        "num_autograding_workers": 1,
    },
}
WORKER_DIRECTORIES = [
    os.path.join(GRADING, name)
    for name in WORKER_PROPERTIES.keys()
]

# Log directorories
LOG_PATH = os.path.join(TEST_ENVIRONMENT, 'logs')
STACK_TRACES = os.path.join(LOG_PATH, 'autograding_stack_traces')
AUTOGRADING_LOGS = os.path.join(LOG_PATH, 'autograding')
# The autograder.Config object to pass to the shipper.
CONFIG = None


def generate_queue_file(name: str, *, required_capabilities: str):
    queue_obj = {
        'required_capabilities': required_capabilities,
    }
    with open(os.path.join(TO_BE_GRADED, name), 'w') as f:
        json.dump(queue_obj, f)


def generate_broken_queue_file(name: str):
    with open(os.path.join(TO_BE_GRADED, name), 'w') as f:
        f.write("This isn't a valid JSON file.\n")


class TestScheduler(unittest.TestCase):
    @classmethod
    def tearDownClass(cls):
        with contextlib.suppress(FileNotFoundError):
            shutil.rmtree(WORKING_DIR)

    @classmethod
    def setUpClass(cls):
        """
        Sets up a mock environment roughly equivalent to the production server.
        As more features are needed, they should be added here
        """
        global CONFIG

        # Remove the test environment if it is left over from a previous run.
        with contextlib.suppress(FileNotFoundError):
            shutil.rmtree(TEST_ENVIRONMENT)

        # Make the working directory
        os.makedirs(WORKING_DIR, exist_ok=True)

        # Copy test data into the dir
        shutil.copytree(TEST_DATA_SRC_DIR, TEST_DATA_DIR)

        # All testing will take place within the TEST_ENVIRONMENT directory
        os.mkdir(TEST_ENVIRONMENT)

        # A mock of /usr/local/submitty/config
        os.mkdir(CONFIG_DIR)

        # A mock directory for /var/local/submitty
        os.mkdir(SUBMITTY_DATA_DIR)
        for directory in [TODO_DIR, DONE_DIR, TO_BE_GRADED, GRADING]:
            os.mkdir(directory)
        for directory in WORKER_DIRECTORIES:
            os.mkdir(directory)

        # A mock directory for /var/local/submitty/logs
        os.mkdir(LOG_PATH)
        for directory in [STACK_TRACES, AUTOGRADING_LOGS]:
            os.mkdir(directory)

        # Create the configuration json files
        submitty_json = {
            'submitty_data_dir': SUBMITTY_DATA_DIR,
            'submitty_install_dir': TEST_ENVIRONMENT,
            'autograding_log_path': AUTOGRADING_LOGS,
            'site_log_path': LOG_PATH,
            'submission_url': '/fake/url/for/submission/',
            'vcs_url': '/fake/url/for/vcs/submission/'
        }
        users_json = {
            # Pretend that we are the daemon user.
            'daemon_uid': os.getuid()
        }
        # The database json is required by autograder/insert_database_version_data.py
        # When we test that script, a mock database may be needed, and these
        # values will have to be updated.
        database_json = {
            'database_user': 'foo',
            'database_host': 'bar',
            'database_password': 'password'
        }

        for filename, json_file in [
            ('submitty', submitty_json),
            ('submitty_users', users_json),
            ('database', database_json)
        ]:
            with open(os.path.join(CONFIG_DIR, f'{filename}.json'), 'w') as outfile:
                json.dump(json_file, outfile, indent=4)

        # Instantiate the shipper's config object
        CONFIG = config.Config.path_constructor(CONFIG_DIR, 'TEST')

    def tearDown(self):
        """Clear out all of the queues between test invocations."""
        for queue_file in os.listdir(TO_BE_GRADED):
            with contextlib.suppress(FileNotFoundError):
                os.remove(os.path.join(TO_BE_GRADED, queue_file))
        for worker_queue in WORKER_DIRECTORIES:
            if os.path.exists(worker_queue) and os.path.isdir(worker_queue):
                for entry in os.listdir(worker_queue):
                    with contextlib.suppress(FileNotFoundError):
                        os.remove(os.path.join(worker_queue, entry))

    @mock.patch('multiprocessing.Process')
    def test_fcfs_simple(self, MockProcess: mock.Mock):
        """Test the most barebones scheduling setup: one job, one worker."""

        # Make our workers
        worker_proc = MockProcess()
        worker_proc.is_alive = mock.MagicMock(return_value=True)

        worker = Worker(CONFIG, 'worker_0', WORKER_PROPERTIES['worker_0'], worker_proc)

        # Make our scheduler
        scheduler = FCFSScheduler(CONFIG, [worker])

        # Place a dummy queue file in the queue
        generate_queue_file("test", required_capabilities='default')

        # Invoke the scheduler's update mechanism
        scheduler.update_and_schedule()

        # Check the worker's directory for the queue file
        worker_files = os.listdir(worker.folder)
        self.assertIn("test", worker_files)

    @mock.patch('multiprocessing.Process')
    def test_fcfs_multiworker(self, MockProcess: mock.Mock):
        """Test a scheduling setup with two workers."""

        worker_names = ['worker_0', 'worker_1']

        worker_procs = [MockProcess() for _ in worker_names]
        for proc in worker_procs:
            proc.is_alive = mock.MagicMock(return_value=True)

        workers = [
            Worker(CONFIG, name, WORKER_PROPERTIES[name], proc)
            for name, proc in zip(worker_names, worker_procs)
        ]

        scheduler = FCFSScheduler(CONFIG, workers)

        generate_queue_file("test", required_capabilities='default')

        scheduler.update_and_schedule()

        # Quick way to get all of the files in all of the worker queues
        all_worker_files = sum([os.listdir(worker.folder) for worker in workers], [])
        self.assertIn("test", all_worker_files)
        # Make sure we don't double-copy files
        self.assertEqual(all_worker_files.count("test"), 1)

    @mock.patch('multiprocessing.Process')
    def test_fcfs_capacity(self, MockProcess: mock.Mock):
        """Test that the scheduling behavior respects the one-job-per-worker rule.

        This test also helps verify that the scheduler follows proper first come, first serve
        behavior.
        """

        worker_proc = MockProcess()
        worker_proc.is_alive = mock.MagicMock(return_value=True)

        worker = Worker(CONFIG, 'worker_0', WORKER_PROPERTIES['worker_0'], worker_proc)

        scheduler = FCFSScheduler(CONFIG, [worker])

        generate_queue_file("first", required_capabilities='default')
        time.sleep(0.1)  # Kinda ugly, but helps avoid temporal aliasing by the OS
        generate_queue_file("second", required_capabilities='default')

        scheduler.update_and_schedule()

        worker_files = os.listdir(worker.folder)
        self.assertIn("first", worker_files)
        self.assertNotIn("second", worker_files)

        # Still no space, so `second` should still not be queued yet
        scheduler.update_and_schedule()

        worker_files = os.listdir(worker.folder)
        self.assertIn("first", worker_files)
        self.assertNotIn("second", worker_files)

        # Remove the existing queue file, then issue another scheduling run.
        # `second` should be scheduled now.
        os.remove(os.path.join(worker.folder, "first"))
        scheduler.update_and_schedule()

        worker_files = os.listdir(worker.folder)
        self.assertNotIn("first", worker_files)
        self.assertIn("second", worker_files)

    @mock.patch('multiprocessing.Process')
    def test_fcfs_capacity_multi(self, MockProcess: mock.Mock):
        """Test that the one-job-per-worker rule is still applied with multiple workers."""

        worker_names = ['worker_0', 'worker_1']

        worker_procs = [MockProcess() for _ in worker_names]
        for proc in worker_procs:
            proc.is_alive = mock.MagicMock(return_value=True)

        workers = [
            Worker(CONFIG, name, WORKER_PROPERTIES[name], proc)
            for name, proc in zip(worker_names, worker_procs)
        ]

        scheduler = FCFSScheduler(CONFIG, workers)

        generate_queue_file("first", required_capabilities='default')
        time.sleep(0.1)
        generate_queue_file("second", required_capabilities='default')
        time.sleep(0.1)
        generate_queue_file("third", required_capabilities='default')

        scheduler.update_and_schedule()

        all_worker_files = sum([os.listdir(worker.folder) for worker in workers], [])
        self.assertIn("first", all_worker_files)
        self.assertIn("second", all_worker_files)
        self.assertNotIn("third", all_worker_files)

        self.assertEqual(all_worker_files.count("first"), 1)
        self.assertEqual(all_worker_files.count("second"), 1)

    @mock.patch('multiprocessing.Process')
    def test_fcfs_constraints(self, MockProcess: mock.Mock):
        """Test that job constraints are respected."""

        worker_names = ['worker_0', 'worker_1', 'worker_2']

        worker_procs = [MockProcess() for _ in worker_names]
        for proc in worker_procs:
            proc.is_alive = mock.MagicMock(return_value=True)

        workers = [
            Worker(CONFIG, name, WORKER_PROPERTIES[name], proc)
            for name, proc in zip(worker_names, worker_procs)
        ]

        scheduler = FCFSScheduler(CONFIG, workers)

        generate_queue_file("first", required_capabilities='zero')
        generate_queue_file("second", required_capabilities='one')
        generate_queue_file("third", required_capabilities='two')
        time.sleep(0.1)
        generate_queue_file("fourth", required_capabilities='zero')

        scheduler.update_and_schedule()

        self.assertIn("first", os.listdir(workers[0].folder))
        self.assertIn("second", os.listdir(workers[1].folder))
        self.assertIn("third", os.listdir(workers[2].folder))

        all_worker_files = sum([os.listdir(worker.folder) for worker in workers], [])
        self.assertNotIn("fourth", all_worker_files)

    @mock.patch('multiprocessing.Process')
    def test_fcfs_ignore_invalid_queue_file(self, MockProcess: mock.Mock):
        """Test that the scheduler properly ignores invalid queue files."""
        worker_proc = MockProcess()
        worker_proc.is_alive = mock.MagicMock(return_value=True)

        worker = Worker(CONFIG, 'worker_0', WORKER_PROPERTIES['worker_0'], worker_proc)

        scheduler = FCFSScheduler(CONFIG, [worker])

        generate_broken_queue_file("first")
        time.sleep(0.1)
        generate_queue_file("second", required_capabilities='default')

        scheduler.update_and_schedule()

        worker_files = os.listdir(worker.folder)
        self.assertNotIn("first", worker_files)
        self.assertIn("second", worker_files)
