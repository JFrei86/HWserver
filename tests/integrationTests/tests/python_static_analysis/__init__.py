# Necessary imports. Provides library functions to ease writing tests.
from lib import prebuild, testcase, SUBMITTY_INSTALL_DIR

import subprocess
import os
import glob

############################################################################
# COPY THE ASSIGNMENT FROM THE SAMPLE ASSIGNMENTS DIRECTORIES

SAMPLE_ASSIGNMENT_CONFIG = SUBMITTY_INSTALL_DIR + "/sample_files/sample_assignment_config/python_static_analysis"
SAMPLE_SUBMISSIONS       = SUBMITTY_INSTALL_DIR + "/sample_files/sample_submissions/python_static_analysis"

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
        os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "config.json"),
        os.path.join(test.testcase_path, "assignment_config")])
    for i in [str(n) for n in range(1, 5)]:
        try:
            os.mkdir(os.path.join(test.testcase_path, "data", "part" + i))
        except OSError:
            pass
        subprocess.call(["cp",
            os.path.join(SAMPLE_ASSIGNMENT_CONFIG, "test_output", "p" + i + "_out.txt"),
            os.path.join(test.testcase_path, "data")])


def cleanup(test):
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data", "part*", "*")))
    subprocess.call(["rm"] + ["-f"] +
            glob.glob(os.path.join(test.testcase_path, "data", "test*")))


@testcase
def correct(test):
    cleanup(test)
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p1_sol.py"),
                     os.path.join(test.testcase_path, "data", "part1")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p2_sol.txt"),
                     os.path.join(test.testcase_path, "data", "part2")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p3_sol.py"),
                     os.path.join(test.testcase_path, "data", "part3")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p4_sol.py"),
                     os.path.join(test.testcase_path, "data", "part4")])
    test.run_compile()  # NOTE: This is necessary to rename part2 file
    test.run_run()
    test.run_validator()
    test.diff("results_grade.txt", "results_grade.txt_correct", "-b")
    test.json_diff("results.json", "results.json_correct")

    test.debug_print("data/test01_STDOUT_0.txt")
    test.debug_print("data/test01_STDOUT_1.txt")
    test.debug_print("data/test01_STDERR_0.txt")
    test.debug_print("data/test01_STDERR_1.txt")

    test.debug_print("data/results.json")
    test.debug_print("validation/results.json_correct")

@testcase
def buggy(test):
    cleanup(test)
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p1_bug.py"),
                     os.path.join(test.testcase_path, "data", "part1")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p2_bug.txt"),
                     os.path.join(test.testcase_path, "data", "part2")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p3_bug.py"),
                     os.path.join(test.testcase_path, "data", "part3")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p4_bug.py"),
                     os.path.join(test.testcase_path, "data", "part4")])
    test.run_compile()  # NOTE: This is necessary to rename part2 file
    test.run_run()
    test.run_validator()
    test.diff("results_grade.txt", "results_grade.txt_buggy", "-b")
    test.json_diff("results.json", "results.json_buggy")


@testcase
def buggy2(test):
    cleanup(test)

    # FIXME: INFINITE LOOP(?) WHEN THIS DOES NOT EXIST
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p1_bug.py"),
                     os.path.join(test.testcase_path, "data", "part1")])

    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p2_bug2.txt"),
                     os.path.join(test.testcase_path, "data", "part2")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p3_bug2.py"),
                     os.path.join(test.testcase_path, "data", "part3")])

    # FIXME: INFINITE LOOP(?) WHEN THIS DOES NOT EXIST
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p4_bug.py"),
                     os.path.join(test.testcase_path, "data", "part4")])
    test.run_compile()  # NOTE: This is necessary to rename part2 file
    test.run_run()
    test.run_validator()
    test.diff("results_grade.txt", "results_grade.txt_buggy2", "-b")
    test.json_diff("results.json", "results.json_buggy2")


@testcase
def buggy3(test):
    cleanup(test)

    # FIXME: INFINITE LOOP(?) WHEN THIS DOES NOT EXIST
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p1_bug.py"),
                     os.path.join(test.testcase_path, "data", "part1")])

    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p2_bug3.txt"),
                     os.path.join(test.testcase_path, "data", "part2")])
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p3_bug3.py"),
                     os.path.join(test.testcase_path, "data", "part3")])

    # FIXME: INFINITE LOOP(?) WHEN THIS DOES NOT EXIST
    subprocess.call(["cp",
                     os.path.join(SAMPLE_SUBMISSIONS, "p4_bug.py"),
                     os.path.join(test.testcase_path, "data", "part4")])
    test.run_compile()  # NOTE: This is necessary to rename part2 file
    test.run_run()
    test.run_validator()
    test.diff("results_grade.txt", "results_grade.txt_buggy3", "-b")
    test.json_diff("results.json", "results.json_buggy3")
