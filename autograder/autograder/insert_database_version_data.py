
"""
This module is used for inserting/updating autograding information into the DB.
Generally, the site should be inserting an empty row into the DB for the autograding
submission and then this script updates said row, but should be fault-tolerant to
handle inserting the row if necessary.
"""
import json
import os

from submitty_utils import dateutils
from sqlalchemy import create_engine, Table, MetaData, bindparam, select, func
from . import grade_item


def str2bool(v):
    return v.lower() in ("yes", "true", "t", "1")


def insert_into_database(config, semester, course, gradeable_id, user_id, team_id, who_id, is_team,
                         version):
    db_user = config.database['database_user']
    db_host = config.database['database_host']
    db_pass = config.database['database_password']
    data_dir = config.submitty['submitty_data_dir']

    non_hidden_non_ec = 0
    non_hidden_ec = 0
    hidden_non_ec = 0
    hidden_ec = 0

    user_or_team_id = team_id if is_team else user_id

    tmp_submission = os.path.join(
        config.submitty['submitty_data_dir'],
        'courses',
        semester,
        course,
        'results',
        gradeable_id,
        user_or_team_id,
        version
    )

    submit_notebook_path = os.path.join(tmp_submission, ".submit.notebook")
    if os.path.exists(submit_notebook_path):
        with open(submit_notebook_path, 'r') as infile:
            notebook_data = json.load(infile).get('item_pools_selected', [])
    else:
        print(f'could not find {submit_notebook_path}')
        notebook_data = []

    testcases = get_testcases(config, semester, course, gradeable_id, notebook_data)
    results = get_result_details(data_dir, semester, course, gradeable_id, who_id, version)

    if len(testcases) != len(results['testcases']):
        print(f"ERROR!  mismatched # of testcases {len(testcases)} != {len(results['testcases'])}")
        raise Exception(
            f"ERROR!  mismatched # of testcases {len(testcases)} != {len(results['testcases'])}"
        )
    for i in range(len(testcases)):
        print(f"testcase[i]= {json.dumps(results['testcases'][i])}")
        if testcases[i]['hidden'] and testcases[i]['extra_credit']:
            hidden_ec += results['testcases'][i]['points']
        elif testcases[i]['hidden']:
            hidden_non_ec += results['testcases'][i]['points']
        elif testcases[i]['extra_credit']:
            non_hidden_ec += results['testcases'][i]['points']
        else:
            non_hidden_non_ec += results['testcases'][i]['points']
    submission_time = results['submission_time']

    if 'automatic_grading_total' in results.keys():
        # automatic_grading_total = results["automatic_grading_total"]
        nonhidden_automatic_grading_total = results["nonhidden_automatic_grading_total"]

        # hidden_diff    = automatic_grading_total - hidden_ec - hidden_non_ec
        nonhidden_diff = nonhidden_automatic_grading_total - non_hidden_ec - non_hidden_non_ec

        non_hidden_non_ec += nonhidden_diff
        # hidden_non_ec += hidden_diff

    db_name = f"submitty_{semester}_{course}"

    # If using a UNIX socket, have to specify a slightly different connection string
    if os.path.isdir(db_host):
        conn_string = f"postgresql://{db_user}:{db_pass}@/{db_name}?host={db_host}"
    else:
        conn_string = f"postgresql://{db_user}:{db_pass}@{db_host}/{db_name}"

    engine = create_engine(conn_string)
    db = engine.connect()
    metadata = MetaData(bind=db)
    data_table = Table('electronic_gradeable_data', metadata, autoload=True)

    """
    The data row should have been inserted by PHP when the student uploads the submission, requiring
    us to do an update here (as the PHP also deals with the active version for us), but in case
    we're using some other method of grading, we'll insert the row and whoever called the script
    will need to handle the active version afterwards.
    """
    if is_team is True:
        result = db.execute(select([func.count()]).select_from(data_table)
                            .where(data_table.c.g_id == bindparam('g_id'))
                            .where(data_table.c.team_id == bindparam('team_id'))
                            .where(data_table.c.g_version == bindparam('g_version')),
                            g_id=gradeable_id,  team_id=team_id, g_version=version)
        row = result.fetchone()
        result.close()
        query_type = data_table.insert()
        if row[0] > 0:
            query_type = data_table\
                .update(
                    values={
                        data_table.c.autograding_non_hidden_non_extra_credit:
                            bindparam("autograding_non_hidden_non_extra_credit"),
                        data_table.c.autograding_non_hidden_extra_credit:
                            bindparam("autograding_non_hidden_extra_credit"),
                        data_table.c.autograding_hidden_non_extra_credit:
                            bindparam("autograding_hidden_non_extra_credit"),
                        data_table.c.autograding_hidden_extra_credit:
                            bindparam("autograding_hidden_extra_credit"),
                        data_table.c.autograding_complete:
                            bindparam("autograding_complete")
                    })\
                .where(data_table.c.g_id == bindparam('u_g_id'))\
                .where(data_table.c.team_id == bindparam('u_team_id'))\
                .where(data_table.c.g_version == bindparam('u_g_version'))
            # we bind "u_g_id" (and others) as we cannot use "g_id" in the where clause for an
            # update. Passing this as an argument to db.execute doesn't cause any issue when we
            # use the insert query (that doesn't have u_g_id)
        db.execute(query_type,
                   g_id=gradeable_id, u_g_id=gradeable_id,
                   team_id=team_id, u_team_id=team_id,
                   g_version=version, u_g_version=version,
                   autograding_non_hidden_non_extra_credit=non_hidden_non_ec,
                   autograding_non_hidden_extra_credit=non_hidden_ec,
                   autograding_hidden_non_extra_credit=hidden_non_ec,
                   autograding_hidden_extra_credit=hidden_ec,
                   submission_time=submission_time,
                   autograding_complete=True)

    else:
        result = db.execute(select([func.count()]).select_from(data_table)
                            .where(data_table.c.g_id == bindparam('g_id'))
                            .where(data_table.c.user_id == bindparam('user_id'))
                            .where(data_table.c.g_version == bindparam('g_version')),
                            g_id=gradeable_id, user_id=user_id, g_version=version)
        row = result.fetchone()
        result.close()
        query_type = data_table.insert()
        if row[0] > 0:
            query_type = data_table\
                .update(
                    values={
                        data_table.c.autograding_non_hidden_non_extra_credit:
                            bindparam("autograding_non_hidden_non_extra_credit"),
                        data_table.c.autograding_non_hidden_extra_credit:
                            bindparam("autograding_non_hidden_extra_credit"),
                        data_table.c.autograding_hidden_non_extra_credit:
                            bindparam("autograding_hidden_non_extra_credit"),
                        data_table.c.autograding_hidden_extra_credit:
                            bindparam("autograding_hidden_extra_credit"),
                        data_table.c.autograding_complete:
                            bindparam("autograding_complete")
                    })\
                .where(data_table.c.g_id == bindparam('u_g_id'))\
                .where(data_table.c.user_id == bindparam('u_user_id'))\
                .where(data_table.c.g_version == bindparam('u_g_version'))
            # we bind "u_g_id" (and others) as we cannot use "g_id" in the where clause for an
            # update. Passing this as an argument to db.execute doesn't cause any issue when we
            # use the insert query (that doesn't have u_g_id)
        db.execute(query_type,
                   g_id=gradeable_id, u_g_id=gradeable_id,
                   user_id=user_id, u_user_id=user_id,
                   g_version=version, u_g_version=version,
                   autograding_non_hidden_non_extra_credit=non_hidden_non_ec,
                   autograding_non_hidden_extra_credit=non_hidden_ec,
                   autograding_hidden_non_extra_credit=hidden_non_ec,
                   autograding_hidden_extra_credit=hidden_ec,
                   submission_time=submission_time,
                   autograding_complete=True)
    db.close()
    engine.dispose()


def get_testcases(config, semester, course, g_id, notebook_data):
    """
    Get all the testcases for a homework from its build json file. This should have a 1-to-1
    correspondance with the testcases that come from the results.json file.

    :param semester:
    :param course:
    :param g_id:
    :return:
    """
    testcase_specs = []
    build_file = os.path.join(
        config.submitty['submitty_data_dir'],
        "courses",
        semester,
        course,
        "config",
        "build",
        f"build_{g_id}.json"
    )
    if os.path.isfile(build_file):
        with open(build_file) as build_file:
            build_json = json.load(build_file)
        if 'testcases' in build_json and build_json['testcases'] is not None:
            testcase_specs += build_json['testcases']
    else:
        print(f'could not find {build_file}')

    for notebook_item in notebook_data:
        item_dict = grade_item.get_item_from_item_pool(build_json, notebook_item)
        if item_dict is not None and 'testcases' in item_dict:
            testcase_specs += item_dict['testcases']

    testcases = []
    for testcase in testcase_specs:
        testcases.append({
                'hidden': testcase.get('hidden', False),
                'extra_credit': testcase.get('extra_credit', False)
            })
    return testcases


def get_result_details(data_dir, semester, course, g_id, who_id, version):
    """
    Gets the result details for a particular version of a gradeable for the who (user or team).
    It returns a dictionary that contains a list of the testcases (that should have a 1-to-1
    correspondence with the testcases gotten through get_testcases() method) and the submission
    time for the particular version.

    :param semester:
    :param course:
    :param g_id:
    :param who_id:
    :param version:
    :return:
    """

    result_details = {'testcases': [], 'submission_time': None}
    result_dir = os.path.join(data_dir, "courses", semester, course, "results", g_id, who_id,
                              str(version))
    if os.path.isfile(os.path.join(result_dir, "results.json")):
        with open(os.path.join(result_dir, "results.json")) as result_file:
            result_json = json.load(result_file)
            if 'testcases' in result_json and result_json['testcases'] is not None:
                for testcase in result_json['testcases']:
                    result_details['testcases'].append({'points': testcase['points_awarded']})
            if 'automatic_grading_total' in result_json:
                result_details['automatic_grading_total'] = result_json['automatic_grading_total']
            if 'nonhidden_automatic_grading_total' in result_json:
                result_details['nonhidden_automatic_grading_total'] = \
                   result_json['nonhidden_automatic_grading_total']

    if os.path.isfile(os.path.join(result_dir, "history.json")):
        with open(os.path.join(result_dir, "history.json")) as result_file:
            result_json = json.load(result_file)
            # a = datetime.strptime(result_json[-1]['submission_time'], "%a %b  %d %H:%M:%S %Z %Y")
            a = dateutils.read_submitty_date(result_json[-1]['submission_time'])
            result_details['submission_time'] = '{}-{:02d}-{:02d} {:02d}:{:02d}:{:02d}' \
                .format(a.year, a.month, a.day, a.hour, a.minute, a.second)
    return result_details
