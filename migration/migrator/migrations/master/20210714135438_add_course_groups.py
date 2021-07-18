"""Migration for the Submitty master database."""
from pathlib import Path
from sqlalchemy import text
import os

def up(config, database):
    """
    Run up migration.

    :param config: Object holding configuration details about Submitty
    :type config: migrator.config.Config
    :param database: Object for interacting with given database for environment
    :type database: migrator.db.Database
    """
    database.execute("ALTER TABLE courses ADD COLUMN IF NOT EXISTS group_name varchar(255);")
    database.execute("ALTER TABLE courses ADD COLUMN IF NOT EXISTS owner_name varchar(255);")
    database.execute("ALTER TABLE courses DROP CONSTRAINT IF EXISTS group_validate;")
    database.execute("ALTER TABLE courses DROP CONSTRAINT IF EXISTS owner_validate;")
    database.execute("ALTER TABLE courses ADD CONSTRAINT group_validate CHECK (group_name ~ '^[a-zA-Z0-9_-]*$')")
    database.execute("ALTER TABLE courses ADD CONSTRAINT owner_validate CHECK (owner_name ~ '^[a-zA-Z0-9_-]*$')")
    semester_dir = Path(config.submitty['submitty_data_dir'], 'courses')
    for semester in semester_dir.iterdir():
        for course in semester.iterdir():
            query = "UPDATE courses SET group_name=:g, owner_name=:o WHERE semester = :s AND course = :c;"
            params = {
                's': semester.name,
                'c': course.name,
                'g': course.group(),
                'o': course.owner()
            }
            database.session.execute(text(query), params)
    # we should be able to force the not null constraint now
    database.execute("ALTER TABLE courses ALTER COLUMN group_name SET NOT NULL;")
    database.execute("ALTER TABLE courses ALTER COLUMN owner_name SET NOT NULL;")


def down(config, database):
    """
    Run down migration (rollback).

    :param config: Object holding configuration details about Submitty
    :type config: migrator.config.Config
    :param database: Object for interacting with given database for environment
    :type database: migrator.db.Database
    """
    pass
