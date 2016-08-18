#!/usr/bin/env python

from collections import OrderedDict
from datetime import date, timedelta
import grp
import json
import os
import pwd

submitty_repository = "/usr/local/submitty/GIT_CHECKOUT_Submitty"
submitty_install_dir = "/usr/local/submitty"
submitty_data_dir = "/var/local/submitty"
sample_dir = submitty_install_dir + "/sample_files/sample_assignment_config"
hwphp = (pwd.getpwnam("hwphp").pw_uid, grp.getgrnam("hwphp").gr_gid)


def create_course(course, semester, course_group, assignments):
    """
    Creates a course for use within the system. This deals with running the appropriate create
    scripts, as well as populate the course with some specified assignments for use within the
    system.

    :param course: course name for the course to build
    :param semester: what semester is the course we're creating in
    :param course_group: what is the primary group that should own the directories for the course
    :param assignments: what assignments should be built and added to this course
    """
    course_group_gid = grp.getgrnam(course_group).gr_gid

    # ---------------------------------------------------------------
    # CREATE THE COURSE
    os.system("%s/bin/create_course.sh %s %s %s %s" % (
        submitty_install_dir, semester, course, "instructor", course_group))

    # ---------------------------------------------------------------
    # CREATE THE COURSE DATABASE AND POPULATE IT
    os.environ['PGPASSWORD'] = 'hsdbu'
    database = "submitty_" + semester + "_" + course
    os.system('psql -d postgres -h localhost -U hsdbu -c "CREATE DATABASE ' + database + '"')
    os.system("psql -d %s -h localhost -U hsdbu -f %s/site/data/tables.sql" %
              (database, submitty_repository))
    os.system("psql -d %s -h localhost -U hsdbu -f %s/.setup/vagrant/db_inserts.sql" %
              (database, submitty_repository))

    # ---------------------------------------------------------------
    # WRITE THE PER ASSIGNMENT FORM JSON AND ASSIGNMENT DATA TO DATABASE
    for i in range(len(assignments)):
        assignment = assignments[i]
        with open("%s/sample_files/sample_form_config/form_%s.json" %
                  (submitty_repository, assignment)) as read_file:
            form_json = json.load(read_file, object_pairs_hook=OrderedDict)

        tmp = date.today() + timedelta(days=((2 * i) - 2))
        otmp = tmp - timedelta(days=8)
        form_json["date_submit"] = "{:d}-{:d}-{:d} 00:00:01".format(otmp.year, otmp.month,
                                                                    otmp.day)
        form_json["date_due"] = "{:d}-{:d}-{:d} 23:59:59".format(tmp.year, tmp.month, tmp.day)
        tmp2 = tmp + timedelta(days=4)
        form_json["date_grade"] = "{:d}-{:d}-{:d} 23:59:59".format(tmp2.year, tmp2.month,
                                                                   tmp2.day)
        tmp2 = tmp + timedelta(days=1)
        form_json["date_released"] = "{:d}-{:d}-{:d} 23:59:59".format(tmp2.year, tmp2.month,
                                                                      tmp2.day)

        form_file = "{}/courses/{}/{}/config/form/form_{}.json".format(submitty_data_dir, semester, course,
                                                                       assignment)
        with open(form_file, "w") as form_write:
            json.dump(form_json, form_write, indent=2)
        os.chown(form_file, hwphp[0], course_group_gid)

        os.system("psql -d {} -h localhost -U hsdbu -c \"INSERT INTO gradeable VALUES ('{}', "
                  "'{}', '', false, 0, true, '{}', '{}', 'homework', 1, NULL)\""
                  .format(database, form_json['gradeable_id'], form_json['gradeable_title'],
                          form_json['date_grade'], form_json['date_released']))
        os.system("psql -d {} -h localhost -U hsdbu -c \"INSERT INTO electronic_gradeable "
                  "VALUES ('{}', '{}', '{}', '{}', false, '', true, '{}', 2, {})\""
                  .format(database, form_json['gradeable_id'], form_json['instructions_url'],
                          form_json['date_submit'], form_json['date_due'],
                          form_json['config_path'], form_json['point_precision']))
        os.system("psql -d {} -h localhost -U hsdbu -c \"INSERT INTO gradeable_component "
                  "VALUES ({:d}, '{}', 'Test', '', '', 5, false, false, 1)\""
                  .format(database, i, form_json['gradeable_id']))


        json.dump(class_json, write_file, indent=2)

    # ---------------------------------------------------------------
    # RUN THE BUILD COURSE SCRIPT
    os.system("%s/courses/%s/%s/BUILD_%s.sh" % (submitty_data_dir, semester, course, course))

    # ---------------------------------------------------------------
    # DELETE THE PGPASSWORD FILE
    os.system("psql -d {} -h localhost -U hsdbu -c \"SELECT pg_catalog.setval("
              "'gradeable_component_gc_id_seq', {:d}, true)\"".format(database, len(assignments)))
    del os.environ['PGPASSWORD']

if __name__ == "__main__":
    today = date.today()
    semester = "f" + str(today.year)[-2:]
    if today.month < 7:
        semester = "s" + str(today.year)[-2:]

    os.system("mkdir -p " + submitty_data_dir + "/courses")

    python_homeworks = ["python_simple_homework", "python_linehighlight",
                        "python_simple_homework_multipart", "python_static_analysis"]
    cpp_homeworks = ["cpp_simple_lab", "cpp_cats", "cpp_memory_debugging", "cpp_custom", "cpp_hidden_tests"]
    java_homeworks = ["java_factorial", "java_coverage_factorial"]
    all = python_homeworks + cpp_homeworks + java_homeworks

    create_course("csci1000", semester, "csci1000_tas_www", all)
    create_course("csci1100", semester, "csci1100_tas_www", python_homeworks)
    create_course("csci1200", semester, "csci1200_tas_www", cpp_homeworks)
    create_course("csci2600", semester, "csci2600_tas_www", java_homeworks)
