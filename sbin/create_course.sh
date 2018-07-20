#!/bin/bash


########################################################################################################################
########################################################################################################################
# this script must be run by root or sudo
if [[ "$UID" -ne "0" ]] ; then
    echo "ERROR: This script must be run by root or sudo"
    exit
fi

########################################################################################################################
########################################################################################################################

CONF_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"/../config

SUBMITTY_REPOSITORY_DIR=$(jq -r '.submitty_repository' ${CONF_DIR}/submitty.json)
SUBMITTY_INSTALL_DIR=$(jq -r '.submitty_install_dir' ${CONF_DIR}/submitty.json)
SUBMITTY_DATA_DIR=$(jq -r '.submitty_data_dir' ${CONF_DIR}/submitty.json)
SUBMISSION_URL=$(jq -r '.submission_url' ${CONF_DIR}/submitty.json)

PHP_USER=$(jq -r '.php_user' ${CONF_DIR}/submitty_users.json)
DAEMON_USER=$(jq -r '.daemon_user' ${CONF_DIR}/submitty_users.json)
CGI_USER=$(jq -r '.cgi_user' ${CONF_DIR}/submitty_users.json)

COURSE_BUILDERS_GROUP=$(jq -r '.course_builders_group' ${CONF_DIR}/submitty_users.json)

DATABASE_HOST=$(jq -r '.database_host' ${CONF_DIR}/database.json)
DATABASE_USER=$(jq -r '.database_user' ${CONF_DIR}/database.json)
DATABASE_PASS=$(jq -r '.database_password' ${CONF_DIR}/database.json)

########################################################################################################################
########################################################################################################################

# Check that Submitty Master DB exists.
PGPASSWORD=${DATABASE_PASS} psql -h ${DATABASE_HOST} -U ${DATABASE_USER} -lqt | cut -d \| -f 1 | grep -qw submitty
if [[ $? -ne "0" ]] ; then
    echo "ERROR: Submitty master database doesn't exist."
    exit
fi

#Ensure that tables exist within Submitty Master DB.
sql="SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename IN ('courses','courses_users','sessions','users');"
table_count=`PGPASSWORD=${DATABASE_PASS} psql -h ${DATABASE_HOST} -U ${DATABASE_USER} -d submitty -tAc "${sql}"`
if [[ $table_count -ne "4" ]] ; then
    echo "ERROR: Submitty Master DB is invalid."
    exit
fi

# Check that there are exactly 4 command line arguments.
if [[ $# -ne "4" ]] ; then
    echo "ERROR: Usage, wrong number of arguments"
    echo "   create_course.sh  <semester>  <course>  <instructor username>  <ta group>"
    exit
fi

semester=$1
course=$2
instructor=$3
ta_www_group=$4

echo -e "\nCREATE COURSE:"
echo -e "  semester:     $semester"
echo -e "  course:       $course"
echo -e "  instructor:   $instructor"
echo -e "  ta_www_group: $ta_www_group\n"

########################################################################################################################
########################################################################################################################
# ERROR CHECKING ON THE ARGUMENTS

# confirm that the instructor user exists
if ! id -u "$instructor" >/dev/null 2>&1 ; then
    echo -e "ERROR: $instructor user does not exist\n"
    exit
fi

# confirm that the ta_www_group exists
if ! getent group "$ta_www_group" >/dev/null 2>&1 ; then
    echo -e "ERROR: $ta_www_group group does not exist\n"
    exit
fi


# confirm that the instructor is a member of the $COURSE_BUILDERS_GROUP
if ! groups "$instructor" | grep -q "\b${COURSE_BUILDERS_GROUP}\b" ; then
    echo -e "ERROR: $instructor is not in group $COURSE_BUILDERS_GROUP\n"
    exit
fi

# confirm that the instructor, submitty_daemon, submitty_php, and submitty_cgi are members of the
# ta_www_group
if ! groups "$instructor" | grep -q "\b${ta_www_group}\b" ; then
    echo -e "ERROR: $instructor is not in group $ta_www_group\n"
    exit
fi
if ! groups "$PHP_USER" | grep -q "\b${ta_www_group}\b" ; then
    echo -e "ERROR: $PHP_USER is not in group $ta_www_group\n"
    exit
fi
if ! groups "$DAEMON_USER" | grep -q "\b${ta_www_group}\b" ; then
    echo -e "ERROR: $DAEMON_USER is not in group $ta_www_group\n"
    exit
fi
if ! groups "$CGI_USER" | grep -q "\b${ta_www_group}\b" ; then
    echo -e "ERROR: $CGI_USER is not in group $ta_www_group\n"
    exit
fi

# NOTE: the ta_www_group should also contain the usernames of any
#       additional instructors and/or head TAs who need read/write
#       access to these files


# FIXME: add some error checking on the $semester and $course
#        variables
#
#   (not clear how to do this since these variables could have quite
#   different structure at different schools)

########################################################################################################################
########################################################################################################################

course_dir=$SUBMITTY_DATA_DIR/courses/$semester/$course

if [ -d "$course_dir" ]; then
    echo -e "ERROR: specific course directory " $course_dir " already exists"
    exit
fi


########################################################################################################################
########################################################################################################################

DATABASE_NAME=submitty_${semester}_${course}

########################################################################################################################
########################################################################################################################

#this function takes a single argument, the name of the file to be edited
function replace_fillin_variables {
    sed -i -e "s|__CREATE_COURSE__FILLIN__SUBMITTY_INSTALL_DIR__|$SUBMITTY_INSTALL_DIR|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__SUBMITTY_DATA_DIR__|$SUBMITTY_DATA_DIR|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__SUBMISSION_URL__|$SUBMISSION_URL|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__PHP_USER__|$PHP_USER|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__DAEMON_USER__|$DAEMON_USER|g" $1

    sed -i -e "s|__CREATE_COURSE__FILLIN__SEMESTER__|$semester|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__COURSE__|$course|g" $1

    sed -i -e "s|__CREATE_COURSE__FILLIN__TAGRADING_DATABASE_NAME__|$DATABASE_NAME|g" $1
    sed -i -e "s|__CREATE_COURSE__FILLIN__TAGRADING_COURSE_FILES_LOCATION__|$course_dir|g" $1

    # FIXME: Add some error checking to make sure these values were filled in correctly
}


########################################################################################################################
########################################################################################################################

if [ ! -d "$SUBMITTY_DATA_DIR" ]; then
    echo -e "ERROR: base directory " $SUBMITTY_DATA_DIR " does not exist\n"
    exit
fi

if [ ! -d "$SUBMITTY_DATA_DIR/courses" ]; then
    echo -e "ERROR: courses directory " $SUBMITTY_DATA_DIR/courses " does not exist\n"
    exit
fi

if [ ! -d "$SUBMITTY_DATA_DIR/courses/$semester" ]; then
    mkdir                               $SUBMITTY_DATA_DIR/courses/$semester
    chown root:$COURSE_BUILDERS_GROUP   $SUBMITTY_DATA_DIR/courses/$semester
    chmod 751                           $SUBMITTY_DATA_DIR/courses/$semester
fi

########################################################################################################################
########################################################################################################################

function create_and_set {
    permissions="$1"
    owner="$2"
    group="$3"
    directory="$4"
    mkdir                 $directory
    chown $owner:$group   $directory
    chmod $permissions    $directory
}


# top level course directory
#               drwxrws---       instructor   ta_www_group    ./
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir


#               drwxrws---       instructor   ta_www_group    build/
#               drwxrws---       instructor   ta_www_group    config/
create_and_set  u=rwx,g=rwxs,o=  $instructor  $ta_www_group   $course_dir/build
create_and_set  u=rwx,g=rwxs,o=  $instructor  $ta_www_group   $course_dir/config
create_and_set  u=rwx,g=rwxs,o=  $instructor  $ta_www_group   $course_dir/config/build
create_and_set  u=rwx,g=rwxs,o=  $instructor  $ta_www_group   $course_dir/config/form


# NOTE: when homework is    installed, grading executables, code, & datafiles are placed here
#               drwxr-s---       instructor   ta_www_group    bin/
#               drwxr-s---       instructor   ta_www_group    provided_code/
#               drwxr-s---       instructor   ta_www_group    test_input/
#               drwxr-s---       instructor   ta_www_group    test_output/
#               drwxr-s---       instructor   ta_www_group    custom_validation_code/
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir/bin
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir/provided_code
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir/test_input
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir/test_output
create_and_set  u=rwx,g=rwxs,o=   $instructor  $ta_www_group   $course_dir/custom_validation_code


# NOTE: on each student submission, files are written to these directories
#               drwxr-s---       $PHP_USER        ta_www_group    submissions/
#               drwxr-s---       $PHP_USER        ta_www_group    config_upload/
#               drwxr-s---       $DAEMON_USER     ta_www_group    results/
#               drwxr-s---       $DAEMON_USER     ta_www_group    checkout/
#               drwxr-s---       $DAEMON_USER     ta_www_group    uploads/
#               drwxr-s---       $PHP_USER        ta_www_group    uploads/bulk_pdf/
#               drwxr-s---       $CGI_USER        ta_www_group    uploads/split_pdf/
#               drwxr-s---       $PHP_USER        ta_www_group    uploads/student_images/
#               drwxr-s---       $PHP_USER        ta_www_group    uploads/student_images/tmp
#               drwxr-s---       $DAEMON_USER     ta_www_group    lichen/
#               drwxrws---       $PHP_USER        ta_www_group    lichen/config
#               drwxrws---       $PHP_USER        ta_www_group    lichen/provided_code
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/submissions
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/forum_attachments
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/annotations
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/config_upload
create_and_set  u=rwx,g=rxs,o=   $DAEMON_USER     $ta_www_group   $course_dir/results
create_and_set  u=rwx,g=rxs,o=   $DAEMON_USER     $ta_www_group   $course_dir/checkout
create_and_set  u=rwx,g=rxs,o=   $DAEMON_USER     $ta_www_group   $course_dir/uploads
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/uploads/bulk_pdf
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/uploads/student_images
create_and_set  u=rwx,g=rxs,o=   $PHP_USER        $ta_www_group   $course_dir/uploads/student_images/tmp
create_and_set  u=rwx,g=rxs,o=   $CGI_USER        $ta_www_group   $course_dir/uploads/split_pdf
create_and_set  u=rwx,g=rxs,o=   $DAEMON_USER     $ta_www_group   $course_dir/lichen
create_and_set  u=rwx,g=rwxs,o=  $PHP_USER        $ta_www_group   $course_dir/lichen/config
create_and_set  u=rwx,g=rwxs,o=  $PHP_USER        $ta_www_group   $course_dir/lichen/provided_code


# NOTE:    instructor uploads TA HW grade reports & overall grade scores here
#               drwxr-s---       instructor   ta_www_group    reports/
create_and_set  u=rwx,g=rwxs,o=   $instructor   $ta_www_group   $course_dir/reports
create_and_set  u=rwx,g=rwxs,o=   $instructor   $ta_www_group   $course_dir/reports/summary_html
create_and_set  u=rwx,g=rwxs,o=   $PHP_USER   $ta_www_group   $course_dir/reports/all_grades


########################################################################################################################
########################################################################################################################

# copy the build_course.sh script
cp $SUBMITTY_INSTALL_DIR/sbin/build_course.sh $course_dir/BUILD_${course}.sh
chown $instructor:$ta_www_group $course_dir/BUILD_${course}.sh
chmod 770 $course_dir/BUILD_${course}.sh
replace_fillin_variables $course_dir/BUILD_${course}.sh


# copy the config file for TA grading & replace the variables
cp ${SUBMITTY_INSTALL_DIR}/site/config/course_template.ini ${course_dir}/config/config.ini
chown ${PHP_USER}:${ta_www_group} ${course_dir}/config/config.ini
chmod 660 ${course_dir}/config/config.ini
replace_fillin_variables ${course_dir}/config/config.ini


echo -e "Creating database ${DATABASE_NAME}\n"
PGPASSWORD=${DATABASE_PASS} psql -h ${DATABASE_HOST} -U ${DATABASE_USER} -d postgres -c "CREATE DATABASE ${DATABASE_NAME}"
if [[ $? -ne "0" ]] ; then
    echo "ERROR: Failed to create database ${DATABASE_NAME}"
    exit
fi

python3 ${SUBMITTY_REPOSITORY_DIR}/migration/migrator.py -e course --course ${semester} ${course} migrate --initial
if [[ $? -ne "0" ]] ; then
    echo "ERROR: Failed to create tables within database ${DATABASE_NAME}"
    exit
fi
PGPASSWORD=${DATABASE_PASS} psql -h ${DATABASE_HOST} -U ${DATABASE_USER} -d submitty -c "INSERT INTO courses (semester, course) VALUES ('${semester}', '${course}');"
if [[ $? -ne "0" ]] ; then
    echo "ERROR: Failed to add this course to the master Submitty database."
    exit
fi
echo -e "\nSUCCESS!\n\n"

########################################################################################################################
########################################################################################################################

echo -e "SUCCESS!  new course   $course $semester   CREATED HERE:   $course_dir"
echo -e "SUCCESS!  course page url  ${SUBMISSION_URL}/index.php?semester=${semester}&course=${course}"

########################################################################################################################
########################################################################################################################
