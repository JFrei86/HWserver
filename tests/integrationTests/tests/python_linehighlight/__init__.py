# Necessary imports. Provides library functions to ease writing tests.
from lib import prebuild, testcase, SUBMITTY_INSTALL_DIR

import subprocess
import os
import glob
import shutil
from submitty_utils import submitty_schema_validator


############################################################################
# COPY THE ASSIGNMENT FROM THE SAMPLE ASSIGNMENTS DIRECTORIES

SAMPLE_ASSIGNMENT_CONFIG = SUBMITTY_INSTALL_DIR + "/more_autograding_examples/python_linehighlight/config"
SAMPLE_SUBMISSIONS       = SUBMITTY_INSTALL_DIR + "/more_autograding_examples/python_linehighlight/submissions"

@prebuild
def initialize(test):
    try:
        os.mkdir(os.path.join(test.testcase_path, "assignment_config"))
    except OSError:
        pass
    try:
        data_path = os.path.join(test.testcase_path, "data")
        if os.path.isdir(data_path):
            shutil.rmtree(data_path)
        os.mkdir(data_path)
        os.mkdir(os.path.join(data_path, "test_output"))
    except OSError:
        pass
    try:
        os.mkdir(os.path.join(test.testcase_path, "data"))
    except OSError:
        pass

    subprocess.call(["cp",
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "config.json"),
        os.path.join(test.testcase_path, "assignment_config")])
    subprocess.call(["cp",
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "test_input", "gettysburg_address.txt"),
        os.path.join(test.testcase_path, "data")])
    subprocess.call(["cp",
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "test_output", "output_instructor.txt"),
        os.path.join(test.testcase_path, "data", "test_output")])

    subprocess.call(["cp"] +
            glob.glob(os.path.join(SAMPLE_SUBMISSIONS, "*.py")) +
            [os.path.join(test.testcase_path, "data")])


############################################################################

@testcase
def schema_validation(test):
    cleanup(test)
    config_path = os.path.join(test.testcase_path, 'assignment_config', 'complete_config.json')
    schema = os.path.join(SUBMITTY_INSTALL_DIR, 'bin', 'json_schemas', 'complete_config_schema.json')
    try:
        submitty_schema_validator.validate_complete_config_schema_using_filenames(config_path, schema, show_warnings=False)
    except submitty_schema_validator.SubmittySchemaException as s:
        s.print_human_readable_error()
        raise

@testcase
def run_test(test):
    test.run_run()
    test.diff("test01/output_correct.txt","data/test_output/output_instructor.txt")
    test.diff("test02/output_duplicates.txt","duplicate_lines.txt")
    test.diff("test03/output_duplicates.txt","duplicate_lines.txt")
    test.diff("test04/output_extra.txt","extra_lines.txt")
    test.diff("test05/output_extra.txt","extra_lines.txt")
    test.diff("test06/output_missing.txt","missing_lines.txt")
    test.diff("test07/output_missing.txt","missing_lines.txt")
    test.diff("test08/output_reordered.txt","output_reordered.txt")
    test.diff("test09/output_reordered.txt","output_reordered.txt")
    test.run_validator()
    test.json_diff("results.json")
    for i in range(1, 10):
        test.json_diff("test{:02}/0_diff.json".format(i), "test{:02}_0_diff.json".format(i))
        test.empty_file("test{:02}/STDERR.txt".format(i))
        test.empty_file("test{:02}/STDOUT.txt".format(i))
        test.empty_file("test{:02}/execute_logfile.txt".format(i))
