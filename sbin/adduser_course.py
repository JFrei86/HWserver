#!/usr/bin/env python3

"""
Use this script to add a user to courses. Any user added to a course
will be an instructor.
"""

import argparse
import json
from os import path
import subprocess
from sqlalchemy import create_engine, MetaData, Table, bindparam, and_

CONFIG_PATH = path.join(path.dirname(path.realpath(__file__)), '..', 'config')
with open(path.join(CONFIG_PATH, 'database.json')) as open_file:
    DATABASE_DETAILS = json.load(open_file)
DATABASE_HOST = DATABASE_DETAILS['database_host']
DATABASE_USER = DATABASE_DETAILS['database_user']
DATABASE_PASS = DATABASE_DETAILS['database_password']


def parse_args():
    parser = argparse.ArgumentParser(description='Adds a user to courses')

    parser.add_argument('user_id', help='user_id of the user to create')
    parser.add_argument('--course', metavar='arg', action='append', nargs=3, help='[SEMESTER] [COURSE] [REGISTRATION_SECTION]')

    return parser.parse_args()


def main():
    args = parse_args()
    user_id = args.user_id

    if path.isdir(DATABASE_HOST):
        engine_str = "postgresql://{}:{}@/submitty?host={}".format(DATABASE_USER, DATABASE_PASS, DATABASE_HOST)
    else:
        engine_str = "postgresql://{}:{}@{}/submitty".format(DATABASE_USER, DATABASE_PASS, DATABASE_HOST)

    engine = create_engine(engine_str)
    connection = engine.connect()
    metadata = MetaData(bind=engine)
    users_table = Table('users', metadata, autoload=True)
    select = users_table.select().where(users_table.c.user_id == bindparam('user_id'))
    user = connection.execute(select, user_id=user_id).fetchone()

    if 'course' in args and args.course is not None and len(args.course) > 0:
        courses_table = Table('courses', metadata, autoload=True)
        for course in args.course:
            if not course[2].isdigit():
                course[2] = None
            select = courses_table.select().where(and_(courses_table.c.semester == bindparam('semester'),
                                                       courses_table.c.course == bindparam('course')))
            row = connection.execute(select, semester=course[0], course=course[1]).fetchone()
            # course does not exist, so just skip this argument
            if row is None:
                continue

            courses_u_table = Table('courses_users', metadata, autoload=True)
            select = courses_u_table.select().where(and_(and_(courses_u_table.c.semester == bindparam('semester'),
                                                              courses_u_table.c.course == bindparam('course')),
                                                         courses_u_table.c.user_id == bindparam('user_id')))
            row = connection.execute(select, semester=course[0], course=course[1], user_id=user_id).fetchone()
            # does this user have a row in courses_users for this semester and course?
            if row is None:
                query = courses_u_table.insert()
                connection.execute(query, user_id=user_id, semester=course[0], course=course[1], user_group=1,
                                   registration_section=course[2])
            else:
                query = courses_u_table.update(values={
                    courses_u_table.c.registration_section: bindparam('registration_section')
                }).where(courses_u_table.c.user_id == bindparam('user_id'))
                connection.execute(query, registration_section=course[2])


if __name__ == '__main__':
    main()
