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
    database.execute("ALTER TABLE electronic_gradeable ADD COLUMN IF NOT EXISTS eg_depends_on varchar(255) DEFAULT NULL;")
    database.execute("ALTER TABLE electronic_gradeable DROP CONSTRAINT IF EXISTS fk_depends_on;")
    database.execute("ALTER TABLE electronic_gradeable ADD CONSTRAINT fk_depends_on FOREIGN KEY(eg_depends_on) REFERENCES electronic_gradeable(g_id);")
    database.execute("ALTER TABLE electronic_gradeable ADD COLUMN IF NOT EXISTS eg_depends_on_points integer DEFAULT NULL; ")


def down(config, database, semester, course):
    """
    Run down migration (rollback).

    :param config: Object holding configuration details about Submitty
    :type config: migrator.config.Config
    :param database: Object for interacting with given database for environment
    :type database: migrator.db.Database
    :param semester: Semester of the course being migrated
    :type semester: str
    :param course: Code of course being migrated
    :type course: str
    """
    pass
