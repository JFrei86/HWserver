"""Migration for a given Submitty course database."""


def up(config, database, semester, course):
    """
    Run up migration.

    :param config: Object holding configuration details about Submitty
    :type config: migrator.config.Config
    :param database: Object for interacting with given database for environment
    :type database: migrator.db.Database
    :param semester: Semester of the course being migrated
    :type semester: str
    :param course: Code of course being migrated
    :type course: str
    """
    database.execute("ALTER TABLE poll_responses ADD COLUMN id serial NOT NULL")
    database.execute("ALTER TABLE poll_responses ADD PRIMARY KEY (id)")
    database.execute("ALTER TABLE poll_options RENAME COLUMN option_id TO option_id_old")
    database.execute("ALTER TABLE poll_options ADD COLUMN option_id serial NOT NULL")
    database.execute("ALTER TABLE poll_options ADD PRIMARY KEY (option_id)")
    database.execute("UPDATE poll_responses SET (option_id) = (SELECT option_id FROM poll_options WHERE poll_options.option_id_old = poll_responses.option_id)")
    database.execute("ALTER TABLE poll_options DROP COLUMN option_id_old")

def down(config, database, semester, course):
    database.execute("ALTER TABLE poll_responses DROP COLUMN id")
    database.execute("ALTER TABLE poll_options ALTER COLUMN option_id DROP DEFAULT")
    database.execute("ALTER TABLE poll_options DROP CONSTRAINT poll_options_pkey")
    database.execute("DROP SEQUENCE poll_options_option_id_seq")
