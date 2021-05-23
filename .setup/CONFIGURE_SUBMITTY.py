#!/usr/bin/env python3

import argparse
from collections import OrderedDict
import grp
import json
import os
import pwd
import secrets
import shutil
import string
import tzlocal
import tempfile
import readline


def get_uid(user):
    return pwd.getpwnam(user).pw_uid


def get_gid(user):
    return pwd.getpwnam(user).pw_gid


def get_ids(user):
    try:
        return get_uid(user), get_gid(user)
    except KeyError:
        raise SystemExit("ERROR: Could not find user: " + user)


def get_input(question, default=""):
    add = "[{}] ".format(default) if default != "" else ""
    user = input("{}: {}".format(question, add)).strip()
    if user == "":
        user = default
    return user


##############################################################################
# this script must be run by root or sudo
if os.getuid() != 0:
    raise SystemExit('ERROR: This script must be run by root or sudo')


parser = argparse.ArgumentParser(description='Submitty configuration script',
                                 formatter_class=argparse.ArgumentDefaultsHelpFormatter)
parser.add_argument('--debug', action='store_true', default=False, help='Configure Submitty to be in debug mode. '
                                                                        'This should not be used in production!')
parser.add_argument('--setup-for-sample-courses', action='store_true', default=False,
                    help="Sets up Submitty for use with the sample courses. This is a Vagrant convenience "
                         "flag and should not be used in production!")
parser.add_argument('--worker', action='store_true', default=False, help='Configure Submitty with autograding only')
parser.add_argument('--install-dir', default='/usr/local/submitty', help='Set the install directory for Submitty')
parser.add_argument('--data-dir', default='/var/local/submitty', help='Set the data directory for Submitty')

args = parser.parse_args()

# determine location of SUBMITTY GIT repository
# this script (CONFIGURES_SUBMITTY.py) is in the top level directory of the repository
# (this command works even if we run configure from a different directory)
SETUP_SCRIPT_DIRECTORY = os.path.dirname(os.path.realpath(__file__))
SUBMITTY_REPOSITORY = os.path.dirname(SETUP_SCRIPT_DIRECTORY)

# recommended (default) directory locations
# FIXME: Check that directories exist and are readable/writeable?
SUBMITTY_INSTALL_DIR = args.install_dir
if not os.path.isdir(SUBMITTY_INSTALL_DIR) or not os.access(SUBMITTY_INSTALL_DIR, os.R_OK | os.W_OK):
    raise SystemExit('Install directory {} does not exist or is not accessible'.format(SUBMITTY_INSTALL_DIR))

SUBMITTY_DATA_DIR = args.data_dir
if not os.path.isdir(SUBMITTY_DATA_DIR) or not os.access(SUBMITTY_DATA_DIR, os.R_OK | os.W_OK):
    raise SystemExit('Data directory {} does not exist or is not accessible'.format(SUBMITTY_DATA_DIR))

TAGRADING_LOG_PATH = os.path.join(SUBMITTY_DATA_DIR, 'logs')
AUTOGRADING_LOG_PATH = os.path.join(SUBMITTY_DATA_DIR, 'logs', 'autograding')

##############################################################################

# recommended names for special users & groups related to the SUBMITTY system
PHP_USER = 'submitty_php'
PHP_GROUP = 'submitty_php'
CGI_USER = 'submitty_cgi'
DAEMON_USER = 'submitty_daemon'
DAEMON_GROUP = 'submitty_daemon'

if not args.worker:
    PHP_UID, PHP_GID = get_ids(PHP_USER)
    CGI_UID, CGI_GID = get_ids(CGI_USER)
    # System Groups
    DAEMONPHP_GROUP = 'submitty_daemonphp'
    try:
        grp.getgrnam(DAEMONPHP_GROUP)
    except KeyError:
        raise SystemExit("ERROR: Could not find group: " + DAEMONPHP_GROUP)
    DAEMONCGI_GROUP = 'submitty_daemoncgi'
    try:
        grp.getgrnam(DAEMONCGI_GROUP)
    except KeyError:
        raise SystemExit("ERROR: Could not find group: " + DAEMONCGI_GROUP)

DAEMON_UID, DAEMON_GID = get_ids(DAEMON_USER)

COURSE_BUILDERS_GROUP = 'submitty_course_builders'
try:
    grp.getgrnam(COURSE_BUILDERS_GROUP)
except KeyError:
    raise SystemExit("ERROR: Could not find group: " + COURSE_BUILDERS_GROUP)

##############################################################################

# This is the upper limit of the number of parallel grading threads on
# this machine
NUM_UNTRUSTED = 60

FIRST_UNTRUSTED_UID, FIRST_UNTRUSTED_GID = get_ids('untrusted00')

# confirm that the uid/gid of the untrusted users are sequential
for i in range(1, NUM_UNTRUSTED):
    untrusted_user = "untrusted{:0=2d}".format(i)
    uid, gid = get_ids(untrusted_user)
    if uid != FIRST_UNTRUSTED_UID + i:
        raise SystemExit('CONFIGURATION ERROR: untrusted UID not sequential: ' + untrusted_user)
    elif gid != FIRST_UNTRUSTED_GID + i:
        raise SystemExit('CONFIGURATION ERROR: untrusted GID not sequential: ' + untrusted_user)

##############################################################################

# adjust this number depending on the # of processors
# available on your hardware
NUM_GRADING_SCHEDULER_WORKERS = 5

##############################################################################

SETUP_INSTALL_DIR = os.path.join(SUBMITTY_INSTALL_DIR, '.setup')
SETUP_REPOSITORY_DIR = os.path.join(SUBMITTY_REPOSITORY, '.setup')

CONFIGURATION_FILE = os.path.join(SETUP_INSTALL_DIR, 'INSTALL_SUBMITTY.sh')
CONFIGURATION_JSON = os.path.join(SETUP_INSTALL_DIR, 'submitty_conf.json')
SITE_CONFIG_DIR = os.path.join(SUBMITTY_INSTALL_DIR, "site", "config")
CONFIG_INSTALL_DIR = os.path.join(SUBMITTY_INSTALL_DIR, 'config')
SUBMITTY_ADMIN_JSON = os.path.join(CONFIG_INSTALL_DIR, 'submitty_admin.json')
EMAIL_JSON = os.path.join(CONFIG_INSTALL_DIR, 'email.json')
AUTHENTICATION_JSON = os.path.join(CONFIG_INSTALL_DIR, 'authentication.json')

##############################################################################

authentication_methods = [
    'PamAuthentication',
    'DatabaseAuthentication',
    'LdapAuthentication',
]

defaults = {
    'database_host': 'localhost',
    'database_port': 5432,
    'database_user': 'submitty_dbuser',
    'submission_url': '',
    'supervisor_user': 'submitty',
    'vcs_url': '',
    'authentication_method': 1,
    'institution_name' : '',
    'username_change_text' : 'Submitty welcomes individuals of all ages, backgrounds, citizenships, disabilities, sex, education, ethnicities, family statuses, genders, gender identities, geographical locations, languages, military experience, political views, races, religions, sexual orientations, socioeconomic statuses, and work experiences. In an effort to create an inclusive environment, you may specify a preferred name to be used instead of what was provided on the registration roster.',
    'institution_homepage' : '',
    'timezone' : tzlocal.get_localzone().zone,
    'submitty_admin_username': '',
    'submitty_admin_password': '',
    'email_user': '',
    'email_password': '',
    'email_sender': 'submitty@myuniversity.edu',
    'email_reply_to': 'submitty_do_not_reply@myuniversity.edu',
    'email_server_hostname': 'mail.myuniversity.edu',
    'email_server_port': 25,
    'course_code_requirements': "Please follow your school's convention for course code.",
    'sys_admin_email': '',
    'sys_admin_url': '',
    'ldap_options': {
        'url': '',
        'uid': '',
        'bind_dn': ''
    }
}

loaded_defaults = {}
if os.path.isfile(CONFIGURATION_JSON):
    with open(CONFIGURATION_JSON) as conf_file:
        loaded_defaults = json.load(conf_file)
if os.path.isfile(SUBMITTY_ADMIN_JSON):
    with open(SUBMITTY_ADMIN_JSON) as submitty_admin_file:
        loaded_defaults.update(json.load(submitty_admin_file))
if os.path.isfile(EMAIL_JSON):
    with open(EMAIL_JSON) as email_file:
        loaded_defaults.update(json.load(email_file))

if os.path.isfile(AUTHENTICATION_JSON):
    with open(AUTHENTICATION_JSON) as authentication_file:
        loaded_defaults.update(json.load(authentication_file))

#no need to authenticate on a worker machine (no website)
if not args.worker:
    if 'authentication_method' in loaded_defaults:
        loaded_defaults['authentication_method'] = authentication_methods.index(loaded_defaults['authentication_method'])

# grab anything not loaded in (useful for backwards compatibility if a new default is added that
# is not in an existing config file.)
for key in defaults.keys():
    if key not in loaded_defaults:
        loaded_defaults[key] = defaults[key]
defaults = loaded_defaults

print("\nWelcome to the Submitty Homework Submission Server Configuration\n")
DEBUGGING_ENABLED = args.debug is True

if DEBUGGING_ENABLED:
    print('!! DEBUG MODE ENABLED !!')
    print()

if args.worker:
    print("CONFIGURING SUBMITTY AS A WORKER !!")

print('Hit enter to use default in []')
print()

if args.worker:
    SUPERVISOR_USER = get_input('What is the id for your submitty user?', defaults['supervisor_user'])
else:
    DATABASE_HOST = get_input('What is the database host?', defaults['database_host'])
    print()

    if not os.path.isdir(DATABASE_HOST):
        DATABASE_PORT = int(get_input('What is the database port?', defaults['database_port']))
        print()
    else:
        DATABASE_PORT = defaults['database_port']

    DATABASE_USER = get_input('What is the database user/role?', defaults['database_user'])
    print()

    default = ''
    if 'database_password' in defaults and DATABASE_USER == defaults['database_user']:
        default = '(Leave blank to use same password)'
    DATABASE_PASS = get_input('What is the password for the database user/role {}? {}'.format(DATABASE_USER, default))
    if DATABASE_PASS == '' and DATABASE_USER == defaults['database_user'] and 'database_password' in defaults:
        DATABASE_PASS = defaults['database_password']
    print()

    TIMEZONE = get_input('What timezone should Submitty use? (for a full list of supported timezones see http://php.net/manual/en/timezones.php)', defaults['timezone'])
    print()

    SUBMISSION_URL = get_input('What is the url for submission? (ex: http://192.168.56.101 or '
                               'https://submitty.cs.rpi.edu)', defaults['submission_url']).rstrip('/')
    print()

    VCS_URL = get_input('What is the url for VCS? (Leave blank to default to submission url + {$vcs_type}) (ex: http://192.168.56.101/{$vcs_type} or https://submitty-vcs.cs.rpi.edu/{$vcs_type}', defaults['vcs_url']).rstrip('/')
    print()

    INSTITUTION_NAME = get_input('What is the name of your institution? (Leave blank/type "none" if not desired)',
                             defaults['institution_name'])
    print()

    if INSTITUTION_NAME == '' or INSTITUTION_NAME.isspace():
        INSTITUTION_HOMEPAGE = ''
    else:
        INSTITUTION_HOMEPAGE = get_input("What is the url of your institution\'s homepage? "
                                     '(Leave blank/type "none" if not desired)', defaults['institution_homepage'])
        if INSTITUTION_HOMEPAGE.lower() == "none":
            INSTITUTION_HOMEPAGE = ''
        print()


    SYS_ADMIN_EMAIL = get_input("What is the email for system administration?", defaults['sys_admin_email'])
    SYS_ADMIN_URL = get_input("Where to report problems with Submitty (url for help link)?", defaults['sys_admin_url'])

    USERNAME_TEXT = defaults['username_change_text']

    print('What authentication method to use:')
    for i in range(len(authentication_methods)):
        print(f"{i + 1}. {authentication_methods[i]}")

    while True:
        try:
            auth = int(get_input('Enter number?', defaults['authentication_method']))
        except ValueError:
            auth = 0
        if auth not in range(1, len(authentication_methods)):
            break
        print(f'Number must in between 1 - {len(authentication_methods)} (inclusive)!')
    print()

    AUTHENTICATION_METHOD = authentication_methods[auth]

    default_auth_options = defaults.get('ldap_options', dict())
    LDAP_OPTIONS = {
        'url': default_auth_options.get('url', ''),
        'uid': default_auth_options.get('uid', ''),
        'bind_dn': default_auth_options.get('bind_dn', '')
    }

    if AUTHENTICATION_METHOD == 'LdapAuthentication':
        LDAP_OPTIONS['url'] = get_input('Enter LDAP url?', LDAP_OPTIONS['url'])
        LDAP_OPTIONS['uid'] = get_input('Enter LDAP UID?', LDAP_OPTIONS['uid'])
        LDAP_OPTIONS['bind_dn'] = get_input('Enter LDAP bind_dn?', LDAP_OPTIONS['bind_dn'])


    CGI_URL = SUBMISSION_URL + '/cgi-bin'

    SUBMITTY_ADMIN_USERNAME = get_input("What is the submitty admin username (optional)?", defaults['submitty_admin_username'])
    while True:
        SUBMITTY_ADMIN_PASSWORD = get_input("What is the submitty admin password", defaults['submitty_admin_password'])
        if SUBMITTY_ADMIN_USERNAME != '' and SUBMITTY_ADMIN_PASSWORD == '':
            continue
        break

    while True:
        is_email_enabled = get_input("Will Submitty use email notifications? [y/n]", 'y')
        if (is_email_enabled.lower() in ['yes', 'y']):
            EMAIL_ENABLED = True
            EMAIL_USER = get_input("What is the email user?", defaults['email_user'])
            EMAIL_PASSWORD = get_input("What is the email password",defaults['email_password'])
            EMAIL_SENDER = get_input("What is the email sender address (the address that will appear in the From: line)?",defaults['email_sender'])
            EMAIL_REPLY_TO = get_input("What is the email reply to address?", defaults['email_reply_to'])
            EMAIL_SERVER_HOSTNAME = get_input("What is the email server hostname?", defaults['email_server_hostname'])
            try:
                EMAIL_SERVER_PORT = int(get_input("What is the email server port?", defaults['email_server_port']))
            except ValueError:
                EMAIL_SERVER_PORT = defaults['email_server_port']
            break

        elif (is_email_enabled.lower() in ['no', 'n']):
            EMAIL_ENABLED = False
            EMAIL_USER = defaults['email_user']
            EMAIL_PASSWORD = defaults['email_password']
            EMAIL_SENDER = defaults['email_sender']
            EMAIL_REPLY_TO = defaults['email_reply_to']
            EMAIL_SERVER_HOSTNAME = defaults['email_server_hostname']
            EMAIL_SERVER_PORT = defaults['email_server_port']
            break
    print()



##############################################################################
# make the installation setup directory

if os.path.isdir(SETUP_INSTALL_DIR):
    shutil.rmtree(SETUP_INSTALL_DIR)
os.makedirs(SETUP_INSTALL_DIR, exist_ok=True)

shutil.chown(SETUP_INSTALL_DIR, 'root', COURSE_BUILDERS_GROUP)
os.chmod(SETUP_INSTALL_DIR, 0o751)

##############################################################################
# WRITE CONFIG FILES IN ${SUBMITTY_INSTALL_DIR}/.setup

config = OrderedDict()

config['submitty_install_dir'] = SUBMITTY_INSTALL_DIR
config['submitty_repository'] = SUBMITTY_REPOSITORY
config['submitty_data_dir'] = SUBMITTY_DATA_DIR

config['course_builders_group'] = COURSE_BUILDERS_GROUP

config['num_untrusted'] = NUM_UNTRUSTED
config['first_untrusted_uid'] = FIRST_UNTRUSTED_UID
config['first_untrusted_gid'] = FIRST_UNTRUSTED_UID
config['num_grading_scheduler_workers'] = NUM_GRADING_SCHEDULER_WORKERS


config['daemon_user'] = DAEMON_USER
config['daemon_uid'] = DAEMON_UID
config['daemon_gid'] = DAEMON_GID

if args.worker:
    config['supervisor_user'] = SUPERVISOR_USER
else:
    config['php_user'] = PHP_USER
    config['cgi_user'] = CGI_USER
    config['daemonphp_group'] = DAEMONPHP_GROUP
    config['daemoncgi_group'] = DAEMONCGI_GROUP
    config['php_uid'] = PHP_UID
    config['php_gid'] = PHP_GID

    config['database_host'] = DATABASE_HOST
    config['database_port'] = DATABASE_PORT
    config['database_user'] = DATABASE_USER
    config['database_password'] = DATABASE_PASS
    config['timezone'] = TIMEZONE

    config['authentication_method'] = AUTHENTICATION_METHOD
    config['vcs_url'] = VCS_URL
    config['submission_url'] = SUBMISSION_URL
    config['cgi_url'] = CGI_URL

    config['institution_name'] = INSTITUTION_NAME
    config['username_change_text'] = USERNAME_TEXT
    config['institution_homepage'] = INSTITUTION_HOMEPAGE
    config['debugging_enabled'] = DEBUGGING_ENABLED

# site_log_path is a holdover name. This could more accurately be called the "log_path"
config['site_log_path'] = TAGRADING_LOG_PATH
config['autograding_log_path'] = AUTOGRADING_LOG_PATH

if args.worker:
    config['worker'] = 1
else:
    config['worker'] = 0


with open(CONFIGURATION_FILE, 'w') as open_file:
    def write(x=''):
        print(x, file=open_file)
    write('#!/bin/bash')
    write()

    write('# Variables prepared by CONFIGURE_SUBMITTY.py')
    write('# Manual editing is allowed (but will be clobbered if CONFIGURE_SUBMITTY.py is re-run)')
    write()

    for key, value in config.items():
        key = str(key).upper()
        if isinstance(value, str):
            # To escape a single quote in bash, use '\'' because bash is awful
            write("{}='{}'".format(key, value.replace("'", "'\''")))
        elif isinstance(value, bool):
            write('{}={}'.format(key, 'true' if value is True else 'false'))
        else:
            write('{}={}'.format(key, value))
    write()
    write('# Now actually run the installation script')
    write('source '+SETUP_REPOSITORY_DIR+'/INSTALL_SUBMITTY_HELPER.sh  "$@"')

os.chmod(CONFIGURATION_FILE, 0o700)

with open(CONFIGURATION_JSON, 'w') as json_file:
    json.dump(config, json_file, indent=2)

os.chmod(CONFIGURATION_JSON, 0o500)

##############################################################################
# Setup ${SUBMITTY_INSTALL_DIR}/config

DATABASE_JSON = os.path.join(CONFIG_INSTALL_DIR, 'database.json')
SUBMITTY_JSON = os.path.join(CONFIG_INSTALL_DIR, 'submitty.json')
SUBMITTY_USERS_JSON = os.path.join(CONFIG_INSTALL_DIR, 'submitty_users.json')
WORKERS_JSON = os.path.join(CONFIG_INSTALL_DIR, 'autograding_workers.json')
CONTAINERS_JSON = os.path.join(CONFIG_INSTALL_DIR, 'autograding_containers.json')
SECRETS_PHP_JSON = os.path.join(CONFIG_INSTALL_DIR, 'secrets_submitty_php.json')

#Rescue the autograding_workers and _containers files if they exist.
rescued = list()
tmp_folder = tempfile.mkdtemp()
if not args.worker:
    for full_file_name, file_name in [(WORKERS_JSON, 'autograding_workers.json'), (CONTAINERS_JSON, 'autograding_containers.json')]:
        if os.path.isfile(full_file_name):
            #make a tmp folder and copy autograding workers to it
            tmp_file = os.path.join(tmp_folder, file_name)
            shutil.move(full_file_name, tmp_file)
            rescued.append((full_file_name, tmp_file))

if os.path.isdir(CONFIG_INSTALL_DIR):
    shutil.rmtree(CONFIG_INSTALL_DIR)
os.makedirs(CONFIG_INSTALL_DIR, exist_ok=True)
shutil.chown(CONFIG_INSTALL_DIR, 'root', COURSE_BUILDERS_GROUP)
os.chmod(CONFIG_INSTALL_DIR, 0o755)

# Finish rescuing files.
for full_file_name, tmp_file_name in rescued:
    #copy autograding workers back
    shutil.move(tmp_file_name, full_file_name)
    #make sure the permissions are correct.
    shutil.chown(full_file_name, 'root',DAEMON_GID)
    os.chmod(full_file_name, 0o460)

#remove the tmp folder
os.removedirs(tmp_folder)

##############################################################################
# WRITE CONFIG FILES IN ${SUBMITTY_INSTALL_DIR}/conf

if not args.worker:
    if not os.path.isfile(WORKERS_JSON):
        worker_dict = {
            "primary": {
                "capabilities": ["default"],
                "address": "localhost",
                "username": "",
                "num_autograding_workers": NUM_GRADING_SCHEDULER_WORKERS,
                "enabled" : True
            }
        }

        if args.setup_for_sample_courses:
            worker_dict['primary']['capabilities'].extend([
                'cpp',
                'python',
                'et-cetera',
                'notebook',
            ])

        with open(WORKERS_JSON, 'w') as workers_file:
            json.dump(worker_dict, workers_file, indent=4)

    if not os.path.isfile(CONTAINERS_JSON):
        container_dict = {
            "default": [
                          "submitty/clang:6.0",
                          "submitty/autograding-default:latest",
                          "submitty/java:8",
                          "submitty/java:11",
                          "submitty/python:3.6",
                          "submittyrpi/csci1200:default"
                       ]
        }

        with open(CONTAINERS_JSON, 'w') as container_file:
            json.dump(container_dict, container_file, indent=4)

    for file in [WORKERS_JSON, CONTAINERS_JSON]:
      shutil.chown(file, 'root',DAEMON_GID)
      os.chmod(file, 0o460)

    shutil.chown(CONTAINERS_JSON, group=DAEMONPHP_GROUP)

##############################################################################
# Write database json

if not args.worker:
    config = OrderedDict()
    config['authentication_method'] = AUTHENTICATION_METHOD
    config['database_host'] = DATABASE_HOST
    config['database_port'] = DATABASE_PORT
    config['database_user'] = DATABASE_USER
    config['database_password'] = DATABASE_PASS
    config['debugging_enabled'] = DEBUGGING_ENABLED

    with open(DATABASE_JSON, 'w') as json_file:
        json.dump(config, json_file, indent=2)
    shutil.chown(DATABASE_JSON, 'root', DAEMONPHP_GROUP)
    os.chmod(DATABASE_JSON, 0o440)

##############################################################################
# Write authentication json
if not args.worker:
    config = OrderedDict()
    config['authentication_method'] = AUTHENTICATION_METHOD
    config['ldap_options'] = LDAP_OPTIONS

    with open(AUTHENTICATION_JSON, 'w') as json_file:
        json.dump(config, json_file, indent=2)
    shutil.chown(AUTHENTICATION_JSON, 'root', DAEMONPHP_GROUP)
    os.chmod(AUTHENTICATION_JSON, 0o440)

##############################################################################
# Write submitty json

config = OrderedDict()
config['submitty_install_dir'] = SUBMITTY_INSTALL_DIR
config['submitty_repository'] = SUBMITTY_REPOSITORY
config['submitty_data_dir'] = SUBMITTY_DATA_DIR
config['autograding_log_path'] = AUTOGRADING_LOG_PATH
if not args.worker:
    config['sys_admin_email'] = SYS_ADMIN_EMAIL
    config['sys_admin_url'] = SYS_ADMIN_URL
# site_log_path is a holdover name. This could more accurately be called the "log_path"
config['site_log_path'] = TAGRADING_LOG_PATH

if not args.worker:
    config['submission_url'] = SUBMISSION_URL
    config['vcs_url'] = VCS_URL
    config['cgi_url'] = CGI_URL
    config['institution_name'] = INSTITUTION_NAME
    config['username_change_text'] = USERNAME_TEXT
    config['institution_homepage'] = INSTITUTION_HOMEPAGE
    config['timezone'] = TIMEZONE
    config['duck_special_effects'] = False

config['worker'] = True if args.worker == 1 else False

with open(SUBMITTY_JSON, 'w') as json_file:
    json.dump(config, json_file, indent=2)
os.chmod(SUBMITTY_JSON, 0o444)

##############################################################################
# Write users json

config = OrderedDict()
config['num_grading_scheduler_workers'] = NUM_GRADING_SCHEDULER_WORKERS
config['num_untrusted'] = NUM_UNTRUSTED
config['first_untrusted_uid'] = FIRST_UNTRUSTED_UID
config['first_untrusted_gid'] = FIRST_UNTRUSTED_UID
config['daemon_uid'] = DAEMON_UID
config['daemon_gid'] = DAEMON_GID
config['daemon_user'] = DAEMON_USER
config['course_builders_group'] = COURSE_BUILDERS_GROUP

if not args.worker:
    config['php_uid'] = PHP_UID
    config['php_gid'] = PHP_GID
    config['php_user'] = PHP_USER
    config['cgi_user'] = CGI_USER
    config['daemonphp_group'] = DAEMONPHP_GROUP
    config['daemoncgi_group'] = DAEMONCGI_GROUP
else:
    config['supervisor_user'] = SUPERVISOR_USER

with open(SUBMITTY_USERS_JSON, 'w') as json_file:
    json.dump(config, json_file, indent=2)
shutil.chown(SUBMITTY_USERS_JSON, 'root', DAEMON_GROUP if args.worker else DAEMONPHP_GROUP)

os.chmod(SUBMITTY_USERS_JSON, 0o440)

##############################################################################
# Write secrets_submitty_php json

if not args.worker:
    config = OrderedDict()
    characters = string.ascii_letters + string.digits
    config['session'] = ''.join(secrets.choice(characters) for _ in range(64))
    with open(SECRETS_PHP_JSON, 'w') as json_file:
        json.dump(config, json_file, indent=2)
    shutil.chown(SECRETS_PHP_JSON, 'root', PHP_GROUP)
    os.chmod(SECRETS_PHP_JSON, 0o440)

##############################################################################
# Write submitty_admin json

if not args.worker:
    config = OrderedDict()
    config['submitty_admin_username'] = SUBMITTY_ADMIN_USERNAME
    config['submitty_admin_password'] = SUBMITTY_ADMIN_PASSWORD

    with open(SUBMITTY_ADMIN_JSON, 'w') as json_file:
        json.dump(config, json_file, indent=2)
    shutil.chown(SUBMITTY_ADMIN_JSON, 'root', DAEMON_GROUP)
    os.chmod(SUBMITTY_ADMIN_JSON, 0o440)

##############################################################################
# Write email json

if not args.worker:
    config = OrderedDict()
    config['email_enabled'] = EMAIL_ENABLED
    config['email_user'] = EMAIL_USER
    config['email_password'] = EMAIL_PASSWORD
    config['email_sender'] = EMAIL_SENDER
    config['email_reply_to'] = EMAIL_REPLY_TO
    config['email_server_hostname'] = EMAIL_SERVER_HOSTNAME
    config['email_server_port'] = EMAIL_SERVER_PORT

    with open(EMAIL_JSON, 'w') as json_file:
        json.dump(config, json_file, indent=2)
    shutil.chown(EMAIL_JSON, 'root', DAEMONPHP_GROUP)
    os.chmod(EMAIL_JSON, 0o440)

##############################################################################

print('Configuration completed. Now you may run the installation script')
print('    sudo ' + CONFIGURATION_FILE)
print('          or')
print('    sudo {} clean'.format(CONFIGURATION_FILE))
print("\n")
