# Necessary imports. Provides library functions to ease writing tests.
from lib import prebuild, testcase, SUBMITTY_INSTALL_DIR

import subprocess
import os
import glob


############################################################################
# COPY THE ASSIGNMENT FROM THE SAMPLE ASSIGNMENTS DIRECTORIES

SAMPLE_ASSIGNMENT_CONFIG = SUBMITTY_INSTALL_DIR + "/sample_files/sample_assignment_config/java_coverage_factorial"
SAMPLE_SUBMISSIONS       = SUBMITTY_INSTALL_DIR + "/sample_files/sample_submissions/java_coverage_factorial"

@prebuild
def initialize(test):
    try:
        os.mkdir(os.path.join(test.testcase_path, "assignment_config"))
    except OSError:
        pass
    try:
        os.mkdir(os.path.join(test.testcase_path, "data"))
    except OSError:
        pass

    subprocess.call(["cp",
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "config.h"),
        os.path.join(test.testcase_path, "assignment_config")])
    subprocess.call(["cp", "-r",
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "test_code"),
        os.path.join(test.testcase_path, "data")])


############################################################################


def cleanup(test):
    # seem to need to cleanup this class file, otherwise it doesn't recompile
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data/", "*.zip")))
    subprocess.call(["rm"] + ["-rf"] +
            glob.glob(os.path.join(test.testcase_path, "data/hw0")))
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data/", "*.class")))
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data/", "*.java")))
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data/", "test*.txt")))
    subprocess.call(["rm"] + ["-f"] + 
            glob.glob(os.path.join(test.testcase_path, "data/.submit.grade")))
    subprocess.call(["rm"] + ["-f"] + 
            glob.glob(os.path.join(test.testcase_path, "data/submission.json")))


@testcase
def correct(test):
    cleanup(test)
    subprocess.call(["cp",
        os.path.join(SAMPLE_SUBMISSIONS, "correct/hw0.zip"),
        os.path.join(test.testcase_path, "data/")])
    subprocess.call(["unzip",
                     "-q",  # quiet
                     "-o",  # overwrite files
                     os.path.join(test.testcase_path, "data/hw0.zip"),
                     "-d",  # save to directory
                     os.path.join(test.testcase_path, "data/")])
    test.run_compile()
    test.run_run()
    test.run_validator()
    test.diff(".submit.grade","correct_.submit.grade")
    test.diff("submission.json","correct_submission.json")
    test.emma_coverage_diff("test05_emma_report.txt","correct_test05_emma_report.txt")


@testcase
def correct_no_coverage(test):
    cleanup(test)
    subprocess.call(["cp",
        os.path.join(SAMPLE_SUBMISSIONS, "correct_no_coverage/hw0.zip"),
        os.path.join(test.testcase_path, "data/")])
    subprocess.call(["unzip",
                     "-q",  # quiet
                     "-o",  # overwrite files
                     os.path.join(test.testcase_path, "data/hw0.zip"),
                     "-d",  # save to directory
                     os.path.join(test.testcase_path, "data/")])
    test.run_compile()
    test.run_run()
    test.run_validator()
    test.diff(".submit.grade","correct_no_coverage_.submit.grade")
    test.diff("submission.json","correct_no_coverage_submission.json")
    test.emma_coverage_diff("test05_emma_report.txt","correct_no_coverage_test05_emma_report.txt")


@testcase
def buggy_coverage(test):
    cleanup(test)
    subprocess.call(["cp",
        os.path.join(SAMPLE_SUBMISSIONS, "buggy_coverage/hw0.zip"),
        os.path.join(test.testcase_path, "data/")])
    subprocess.call(["unzip",
                     "-q",  # quiet
                     "-o",  # overwrite files
                     os.path.join(test.testcase_path, "data/hw0.zip"),
                     "-d",  # save to directory
                     os.path.join(test.testcase_path, "data/")])
    test.run_compile()
    test.run_run()
    test.run_validator()
    test.diff(".submit.grade","buggy_coverage_.submit.grade")
    test.diff("submission.json","buggy_coverage_submission.json")
    test.emma_coverage_diff("test05_emma_report.txt","buggy_coverage_test05_emma_report.txt")


@testcase
def buggy_no_coverage(test):
    return
    cleanup(test)
    subprocess.call(["cp",
        os.path.join(SAMPLE_SUBMISSIONS, "buggy_no_coverage/hw0.zip"),
        os.path.join(test.testcase_path, "data/")])
    subprocess.call(["unzip",
                     "-q",  # quiet
                     "-o",  # overwrite files
                     os.path.join(test.testcase_path, "data/hw0.zip"),
                     "-d",  # save to directory
                     os.path.join(test.testcase_path, "data/")])
    test.run_compile()
    test.run_run()
    test.run_validator()
    test.diff(".submit.grade","buggy_no_coverage_.submit.grade")
    test.diff("submission.json","buggy_no_coverage_submission.json")
    test.emma_coverage_diff("test05_emma_report.txt","buggy_no_coverage_test05_emma_report.txt")


