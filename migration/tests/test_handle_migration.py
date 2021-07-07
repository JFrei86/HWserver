from argparse import Namespace
from copy import deepcopy
from io import StringIO
from pathlib import Path
import shutil
from sqlalchemy.exc import OperationalError
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import migrator
from migrator import main


class TestHandleMigration(unittest.TestCase):
    def setUp(self):
        self.stdout = sys.stdout
        sys.stdout = StringIO()
        self.dir = tempfile.mkdtemp()
        self.old_migrations_path = migrator.MIGRATIONS_PATH
        migrator.MIGRATIONS_PATH = Path(self.dir)

    def tearDown(self):
        sys.stdout = self.stdout
        shutil.rmtree(self.dir)
        migrator.MIGRATIONS_PATH = self.old_migrations_path

    def setup_test(self, environment):
        Path(self.dir, environment).mkdir()

    def create_database(self, environment, create=True):
        database = migrator.db.Database({'database_driver': 'sqlite'}, environment)
        if create is True:
            database.DynamicBase.metadata.create_all(database.engine)
        return database

    def create_course_table(self, database):
        database.execute("""
            CREATE TABLE courses (
                semester character varying(255) NOT NULL,
                course character varying(255) NOT NULL,
                status smallint DEFAULT 1 NOT NULL
            );
            """)

    def add_course(self, database, semester, course, status=1):
        database.execute(
            f"INSERT INTO courses VALUES ('{semester}', '{course}', {status})"
        )

    def test_no_course_dir(self):
        args = Namespace()
        args.environments = ['course']
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = {
            'submitty_data_dir': self.dir
        }

        with self.assertRaises(SystemExit) as context:
            main.handle_migration(args)
        self.assertEqual(
            "Migrator Error:  Could not find courses directory: {}".format(
                str(Path(self.dir, 'courses'))
            ),
            str(context.exception)
        )

    def test_migration_no_db_master(self):
        args = Namespace()
        args.environments = ['master']
        args.config = SimpleNamespace()
        args.config.database = dict()

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = OperationalError('test', None, "No Database")
            main.handle_migration(args)
        self.assertEqual(
            "Submitty Database Migration Error for master:\n  (builtins.str) No Database",
            str(context.exception)
        )

    def test_migration_no_db_system(self):
        args = Namespace()
        args.environments = ['system']
        args.config = SimpleNamespace()
        args.config.database = dict()

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = OperationalError('test', None, "No Database")
            main.handle_migration(args)
        self.assertEqual(
            "Submitty Database Migration Error for system:\n  (builtins.str) No Database",
            str(context.exception)
        )

    def test_migration_no_db_course(self):
        database = self.create_database('master')
        self.create_course_table(database)
        self.add_course(database, 'f19', 'csci1100')

        args = Namespace()
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database, OperationalError('test', None, None)]
            main.handle_migration(args)
        self.assertEqual(
            "Submitty Database Migration Error:  Database does not exist for semester=f19 course=csci1100",
            str(context.exception)
        )

    def test_migration_no_db_all(self):
        args = Namespace()
        args.environments = ['course', 'master', 'system']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = OperationalError('test', None, "No Database")
            main.handle_migration(args)

        self.assertEqual(
            "Submitty Database Migration Error for master:\n  (builtins.str) No Database",
            str(context.exception)
        )

    @patch('migrator.main.migrate_environment')
    def test_migration_master(self, mock_method):
        args = Namespace()
        self.setup_test('master')
        database = self.create_database('master')
        args.environments = ['master']
        args.config = SimpleNamespace()
        args.config.database = dict()

        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database]
            main.handle_migration(args)
        self.assertTrue(mock_class.called)
        self.assertEqual(1, mock_class.call_count)
        self.assertTrue(({'dbname': 'submitty'}, 'master'), mock_class.call_args[0])
        self.assertTrue(mock_method.called)
        self.assertEqual(1, mock_method.call_count)
        self.assertEqual(database, mock_method.call_args[0][0])
        self.assertEqual('master', mock_method.call_args[0][1])
        self.assertEqual(args, mock_method.call_args[0][2])
        self.assertFalse(database.open)

    @patch('migrator.main.migrate_environment')
    def test_migration_system(self, mock_method):
        args = Namespace()
        self.setup_test('system')
        database = self.create_database('system')
        args.environments = ['system']
        args.config = SimpleNamespace()
        args.config.database = dict()

        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database]
            main.handle_migration(args)
        self.assertTrue(mock_class.called)
        self.assertEqual(1, mock_class.call_count)
        self.assertTrue(({'dbname': 'submitty'}, 'system'), mock_class.call_args[0])
        self.assertTrue(mock_method.called)
        self.assertEqual(1, mock_method.call_count)
        self.assertEqual(database, mock_method.call_args[0][0])
        self.assertEqual('system', mock_method.call_args[0][1])
        self.assertEqual(args, mock_method.call_args[0][2])
        self.assertFalse(database.open)

    @patch('migrator.main.migrate_environment')
    def test_migration_course(self, mock_method):
        args = Namespace()
        self.setup_test('course')
        database_0 = self.create_database('master')
        self.create_course_table(database_0)
        self.add_course(database_0, 'f19', 'csci1100')

        database = self.create_database('course')
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)

        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database_0, database]
            main.handle_migration(args)
        self.assertTrue(mock_class.called)
        self.assertEqual(2, mock_class.call_count)
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1100'}, 'course'),
            mock_class.call_args[0]
        )
        self.assertTrue(mock_method.called)
        self.assertEqual(1, mock_method.call_count)
        self.assertEqual(database, mock_method.call_args[0][0])
        self.assertEqual('course', mock_method.call_args[0][1])
        # Test that mutation did not happen
        self.assertEqual(args.config.database, dict())
        self.assertNotIn('semester', args)
        self.assertNotIn('course', args)
        args.config.database = {'dbname': 'submitty_f19_csci1100'}
        args.semester = 'f19'
        args.course = 'csci1100'
        self.assertEqual(args, mock_method.call_args[0][2])
        self.assertEqual(args.semester, 'f19')
        self.assertEqual(args.course, 'csci1100')
        self.assertFalse(database.open)

    @patch('migrator.main.migrate_environment')
    def test_migration_multiple_courses(self, mock_method):
        args = Namespace()
        self.setup_test('course')
        database_0 = self.create_database('master')
        self.create_course_table(database_0)
        self.add_course(database_0, 'f17', 'csci1100', 3)
        self.add_course(database_0, 'f18', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1200', 2)

        database_1 = self.create_database('course')
        database_2 = self.create_database('course')
        database_3 = self.create_database('course')
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)
        Path(self.dir, 'courses', 'f16', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f18', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1200').mkdir(parents=True)
        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database_0, database_1, database_2, database_3]
            main.handle_migration(args)
        self.assertTrue(mock_class.called)
        self.assertEqual(4, mock_class.call_count)
        self.assertTrue(
            ({'dbname': 'submitty_f18_csci1100'}, 'course'),
            mock_class.call_args_list[0][0]
        )
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1100'}, 'course'),
            mock_class.call_args_list[1][0]
        )
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1200'}, 'course'),
            mock_class.call_args_list[2][0]
        )
        self.assertTrue(mock_method.called)
        self.assertEqual(3, mock_method.call_count)

        mock_args = mock_method.call_args_list[0][0]
        expected_args = deepcopy(args)
        self.assertEqual(database_1, mock_args[0])
        self.assertEqual('course', mock_args[1])
        self.assertEqual(args.config.database, dict())
        self.assertNotIn('semester', expected_args)
        self.assertNotIn('course', expected_args)
        expected_args.config.database = {'dbname': 'submitty_f18_csci1100'}
        expected_args.semester = 'f18'
        expected_args.course = 'csci1100'
        self.assertEqual(expected_args, mock_args[2])
        self.assertEqual(expected_args.semester, 'f18')
        self.assertEqual(expected_args.course, 'csci1100')
        self.assertFalse(database_1.open)

        mock_args = mock_method.call_args_list[1][0]
        expected_args = deepcopy(args)
        self.assertEqual(database_2, mock_args[0])
        self.assertEqual('course', mock_args[1])
        self.assertEqual(expected_args.config.database, dict())
        self.assertNotIn('semester', expected_args)
        self.assertNotIn('course', expected_args)
        expected_args.config.database = {'dbname': 'submitty_f19_csci1100'}
        expected_args.semester = 'f19'
        expected_args.course = 'csci1100'
        self.assertEqual(expected_args, mock_args[2])
        self.assertEqual(expected_args.semester, 'f19')
        self.assertEqual(expected_args.course, 'csci1100')
        self.assertFalse(database_2.open)

        mock_args = mock_method.call_args_list[2][0]
        expected_args = deepcopy(args)
        self.assertEqual(database_3, mock_args[0])
        self.assertEqual('course', mock_args[1])
        self.assertEqual(args.config.database, dict())
        self.assertNotIn('semester', expected_args)
        self.assertNotIn('course', expected_args)
        expected_args.config.database = {'dbname': 'submitty_f19_csci1200'}
        expected_args.semester = 'f19'
        expected_args.course = 'csci1200'
        self.assertEqual(expected_args, mock_args[2])
        self.assertEqual(expected_args.semester, 'f19')
        self.assertEqual(expected_args.course, 'csci1200')
        self.assertFalse(database_3.open)

    @patch('migrator.main.migrate_environment')
    def test_migration_choose_courses(self, mock_method):
        args = Namespace()
        self.setup_test('course')
        database_0 = self.create_database('master')
        self.create_course_table(database_0)
        self.add_course(database_0, 'f18', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1200')

        database_1 = self.create_database('course')
        args.environments = ['course']
        args.choose_course = ['f19', 'csci1100']
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)
        Path(self.dir, 'courses', 'f18', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1200').mkdir(parents=True)
        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database_0, database_1]
            main.handle_migration(args)
        self.assertTrue(mock_class.called)
        self.assertEqual(2, mock_class.call_count)
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1100'}, 'course'),
            mock_class.call_args_list[0][0]
        )
        self.assertTrue(mock_method.called)
        self.assertEqual(1, mock_method.call_count)

        mock_args = mock_method.call_args_list[0][0]
        expected_args = deepcopy(args)
        self.assertEqual(database_1, mock_args[0])
        self.assertEqual('course', mock_args[1])
        self.assertEqual(expected_args.config.database, dict())
        self.assertNotIn('semester', expected_args)
        self.assertNotIn('course', expected_args)
        expected_args.config.database = {'dbname': 'submitty_f19_csci1100'}
        expected_args.semester = 'f19'
        expected_args.course = 'csci1100'
        self.assertEqual(expected_args, mock_args[2])
        self.assertEqual(expected_args.semester, 'f19')
        self.assertEqual(expected_args.course, 'csci1100')
        self.assertFalse(database_1.open)

    @patch('migrator.main.migrate_environment')
    def test_migration_multiple_courses_missing_migration(self, mock_method):
        args = Namespace()
        self.setup_test('course')

        database_0 = self.create_database('master')
        self.create_course_table(database_0)
        self.add_course(database_0, 'f17', 'csci1100', 3)
        self.add_course(database_0, 'f18', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1100')
        self.add_course(database_0, 'f19', 'csci1200', 2)

        database_1 = self.create_database('course')
        database_2 = self.create_database('course')
        database_3 = self.create_database('course')
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)

        Path(self.dir, 'courses', 'f16', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f18', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1100').mkdir(parents=True)
        Path(self.dir, 'courses', 'f19', 'csci1200').mkdir(parents=True)

        missing_migration = Path(self.dir, 'test.txt')
        missing_migration.touch()
        mock_method.side_effect = lambda *args: args[-1].add(missing_migration)
        self.assertTrue(missing_migration.exists())

        with patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database_0, database_1, database_2, database_3]
            main.handle_migration(args)
        self.assertFalse(missing_migration.exists())
        self.assertTrue(mock_class.called)
        self.assertEqual(4, mock_class.call_count)
        self.assertTrue(
            ({'dbname': 'submitty_f18_csci1100'}, 'course'),
            mock_class.call_args_list[0][0]
        )
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1100'}, 'course'),
            mock_class.call_args_list[1][0]
        )
        self.assertTrue(
            ({'dbname': 'submitty_f19_csci1200'}, 'course'),
            mock_class.call_args_list[2][0]
        )
        self.assertTrue(mock_method.called)
        self.assertEqual(3, mock_method.call_count)

    @patch('migrator.main.migrate_environment')
    def test_migration_course_missing_directory(self, mock_method):
        args = Namespace()
        self.setup_test('course')

        database_0 = self.create_database('master')
        self.create_course_table(database_0)
        self.add_course(database_0, 'f18', 'csci1100')

        database_1 = self.create_database('course')
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)

        Path(self.dir, 'courses').mkdir(parents=True)

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [database_0, database_1]
            main.handle_migration(args)

        self.assertEqual(
            "Migrator Error:  Could not find directory for f18 csci1100",
            str(context.exception)
        )

    @patch('migrator.main.migrate_environment')
    def test_migration_course_missing_master_db(self, mock_method):
        args = Namespace()
        self.setup_test('course')

        database_1 = self.create_database('course')
        args.environments = ['course']
        args.choose_course = None
        args.config = SimpleNamespace()
        args.config.database = dict()
        args.config.submitty = dict()
        args.config.submitty['submitty_data_dir'] = Path(self.dir)

        Path(self.dir, 'courses').mkdir(parents=True)

        with self.assertRaises(SystemExit) as context, \
                patch.object(migrator.db, 'Database') as mock_class:
            mock_class.side_effect = [OperationalError('test', None, None), database_1]
            main.handle_migration(args)

        self.assertEqual(
            "Submitty Database Migration Error:  Database does not exist for master for courses",
            str(context.exception)
        )
