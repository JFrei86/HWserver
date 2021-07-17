#!/usr/bin/env python3

"""
Handles updating the database with allowed minutes
and override allowed minutes for the gradeable timer
"""

from sqlalchemy import create_engine, MetaData, text
import datetime
import os
import sys
import json
import re

try:
    CONFIG_PATH = os.path.join(
        os.path.dirname(os.path.realpath(__file__)), '..', 'config')

    with open(os.path.join(CONFIG_PATH, 'submitty.json')) as open_file:
        SUBMITTY_CONFIG = json.load(open_file)

    with open(os.path.join(CONFIG_PATH, 'database.json')) as open_file:
        DATABASE_CONFIG = json.load(open_file)

except Exception as config_fail_error:
    print("[{}] ERROR: CORE SUBMITTY CONFIGURATION ERROR {}".format(
        str(datetime.datetime.now()), str(config_fail_error)))
    sys.exit(1)

DB_HOST = DATABASE_CONFIG['database_host']
DB_USER = DATABASE_CONFIG['database_user']
DB_PASSWORD = DATABASE_CONFIG['database_password']

CONFIG_FILE_PATH = sys.argv[1]
SEMESTER = sys.argv[2]
COURSE = sys.argv[3]
GRADEABLE = sys.argv[4]


def setup_db():
    """Set up a connection with the course database."""
    db_name = "submitty_{}_{}".format(SEMESTER, COURSE)
    # If using a UNIX socket, have to specify a slightly different connection string
    if os.path.isdir(DB_HOST):
        conn_string = "postgresql://{}:{}@/{}?host={}".format(
            DB_USER, DB_PASSWORD, db_name, DB_HOST)
    else:
        conn_string = "postgresql://{}:{}@{}/{}".format(
            DB_USER, DB_PASSWORD, DB_HOST, db_name)

    engine = create_engine(conn_string)
    db = engine.connect()
    metadata = MetaData(bind=db)
    return db, metadata


def send_data(db, allowed_minutes, override):
    query = """UPDATE gradeable SET g_allowed_minutes = :minutes
               WHERE g_id=:gradeable"""
    db.execute(text(query), minutes=allowed_minutes, gradeable=GRADEABLE)
    query = """DELETE FROM gradeable_allowed_minutes_override WHERE g_id=:gradeable"""
    db.execute(text(query), gradeable=GRADEABLE)
    if override is not None:
        for user in override:
            query = """INSERT INTO gradeable_allowed_minutes_override (g_id, user_id, allowed_minutes)
                       VALUES (:gradeable, :userid, :minutes)"""
            db.execute(text(query), gradeable=GRADEABLE, userid=user['user'],
                       minutes=user['allowed_minutes'])


def main():
    db, metadata = setup_db()
    with open(CONFIG_FILE_PATH) as config_file:
        json_string = config_file.read()
    # Removes #'s
    json_string = re.sub("(^|\n)#[^\n]*(?=\n)", "", json_string)
    CONFIG_FILE = json.loads(json_string)
    timelimit_case = None
    for testcase in CONFIG_FILE['testcases']:
        if testcase['title'] == "Check Time Limit":
            if 'validation' in testcase and len(testcase['validation']) > 0:
                if 'allowed_minutes' in testcase['validation'][0]:
                    timelimit_case = testcase
                    break

    if timelimit_case is not None:
        allowed_minutes = timelimit_case['validation'][0]['allowed_minutes']
        override = None
        if 'override' in timelimit_case['validation'][0]:
            override = timelimit_case['validation'][0]['override']
        send_data(db, allowed_minutes, override)


if __name__ == "__main__":
    main()
