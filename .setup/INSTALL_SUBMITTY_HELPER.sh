#!/bin/bash

########################################################################################################################
########################################################################################################################

# NOTE: This script should not be called directly.  It is called from
# the INSTALL_SUBMITTY.sh script that is generated by
# CONFIGURE_SUBMITTY.py.  That helper script initializes dozens of
# variables that are used in the code below.

# FIXME: Add some error checking to make sure these values were filled in correctly

if [ -z ${SUBMITTY_REPOSITORY+x} ]; then
    echo "ERROR! Configuration variables not initialized"
    exit 1
fi


########################################################################################################################
########################################################################################################################
# this script must be run by root or sudo
if [[ "$UID" -ne "0" ]] ; then
    echo "ERROR: This script must be run by root or sudo"
    exit 1
fi

# check optional argument
if [[ "$#" -ge 1 && "$1" != "test" && "$1" != "clean" && "$1" != "test_rainbow" ]]; then
    echo -e "Usage:"
    echo -e "   ./INSTALL_SUBMITTY.sh"
    echo -e "   ./INSTALL_SUBMITTY.sh clean"
    echo -e "   ./INSTALL_SUBMITTY.sh clean test"
    echo -e "   ./INSTALL_SUBMITTY.sh clear test  <test_case_1>"
    echo -e "   ./INSTALL_SUBMITTY.sh clear test  <test_case_1> ... <test_case_n>"
    echo -e "   ./INSTALL_SUBMITTY.sh test"
    echo -e "   ./INSTALL_SUBMITTY.sh test  <test_case_1>"
    echo -e "   ./INSTALL_SUBMITTY.sh test  <test_case_1> ... <test_case_n>"
    echo -e "   ./INSTALL_SUBMITTY.sh test_rainbow"
    exit 1
fi

echo -e "\nBeginning installation of the Submitty homework submission server\n"


#this function takes a single argument, the name of the file to be edited
function replace_fillin_variables {
    sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_REPOSITORY__|$SUBMITTY_REPOSITORY|g" $1
    sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_INSTALL_DIR__|$SUBMITTY_INSTALL_DIR|g" $1
    sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_TUTORIAL_DIR__|$SUBMITTY_TUTORIAL_DIR|g" $1
    sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_DATA_DIR__|$SUBMITTY_DATA_DIR|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCGI_USER__|$HWCGI_USER|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWPHP_USER__|$HWPHP_USER|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCRON_USER__|$HWCRON_USER|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCRONPHP_GROUP__|$HWCRONPHP_GROUP|g" $1
    sed -i -e "s|__INSTALL__FILLIN__COURSE_BUILDERS_GROUP__|$COURSE_BUILDERS_GROUP|g" $1

    sed -i -e "s|__INSTALL__FILLIN__NUM_UNTRUSTED__|$NUM_UNTRUSTED|g" $1
    sed -i -e "s|__INSTALL__FILLIN__FIRST_UNTRUSTED_UID__|$FIRST_UNTRUSTED_UID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__FIRST_UNTRUSTED_GID__|$FIRST_UNTRUSTED_GID|g" $1

    sed -i -e "s|__INSTALL__FILLIN__HWCRON_UID__|$HWCRON_UID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCRON_GID__|$HWCRON_GID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWPHP_UID__|$HWPHP_UID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWPHP_GID__|$HWPHP_GID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCGI_UID__|$HWCGI_UID|g" $1
    sed -i -e "s|__INSTALL__FILLIN__HWCGI_GID__|$HWCGI_GID|g" $1


    sed -i -e "s|__INSTALL__FILLIN__DATABASE_HOST__|$DATABASE_HOST|g" $1
    sed -i -e "s|__INSTALL__FILLIN__DATABASE_USER__|$DATABASE_USER|g" $1
    sed -i -e "s|__INSTALL__FILLIN__DATABASE_PASSWORD__|$DATABASE_PASSWORD|g" $1

    sed -i -e "s|__INSTALL__FILLIN__TAGRADING_URL__|$TAGRADING_URL|g" $1
    sed -i -e "s|__INSTALL__FILLIN__SUBMISSION_URL__|$SUBMISSION_URL|g" $1
    sed -i -e "s|__INSTALL__FILLIN__CGI_URL__|$CGI_URL|g" $1
    sed -i -e "s|__INSTALL__FILLIN__SITE_LOG_PATH__|$SITE_LOG_PATH|g" $1

    sed -i -e "s|__INSTALL__FILLIN__AUTHENTICATION_METHOD__|${AUTHENTICATION_METHOD}|g" $1
    sed -i -e "s|__INSTALL__FILLIN__INSTITUTION__NAME__|$INSTITUTION_NAME|g" $1
    sed -i -e "s|__INSTALL__FILLIN__INSTITUTION__HOMEPAGE__|$INSTITUTION_HOMEPAGE|g" $1
    sed -i -e "s|__INSTALL__FILLIN__USERNAME__TEXT__|$USERNAME_CHANGE_TEXT|g" $1

    sed -i -e "s|__INSTALL__FILLIN__DEBUGGING_ENABLED__|$DEBUGGING_ENABLED|g" $1

    sed -i -e "s|__INSTALL__FILLIN__AUTOGRADING_LOG_PATH__|$AUTOGRADING_LOG_PATH|g" $1

    sed -i -e "s|__INSTALL__FILLIN__NUM_GRADING_SCHEDULER_WORKERS__|$NUM_GRADING_SCHEDULER_WORKERS|g" $1


    # FIXME: Add some error checking to make sure these values were filled in correctly
}


########################################################################################################################
########################################################################################################################
# if the top level INSTALL directory does not exist, then make it
mkdir -p ${SUBMITTY_INSTALL_DIR}


# option for clean install (delete all existing directories/files
if [[ "$#" -ge 1 && $1 == "clean" ]] ; then

    # pop this argument from the list of arguments...
    shift

    echo -e "\nDeleting directories for a clean installation\n"

    # save the course index page
    originalcurrentcourses=/usr/local/submitty/site/app/views/current_courses.php
    if [ -f $originalcurrentcourses ]; then
        mytempcurrentcourses=`mktemp`
        echo "save this file! ${originalcurrentcourses} ${mytempcurrentcourses}"
        mv ${originalcurrentcourses} ${mytempcurrentcourses}
    fi

    rm -rf ${SUBMITTY_INSTALL_DIR}/hwgrading_website
    rm -rf ${SUBMITTY_INSTALL_DIR}/site
    rm -rf ${SUBMITTY_INSTALL_DIR}/src
    rm -rf ${SUBMITTY_INSTALL_DIR}/bin
    rm -rf ${SUBMITTY_INSTALL_DIR}/test_suite
    rm -rf ${SUBMITTY_INSTALL_DIR}/SubmittyAnalysisTools
fi


# set the permissions of the top level directory
chown  root:${COURSE_BUILDERS_GROUP}  ${SUBMITTY_INSTALL_DIR}
chmod  751                            ${SUBMITTY_INSTALL_DIR}


########################################################################################################################
########################################################################################################################
# if the top level DATA, COURSES, & LOGS directores do not exist, then make them

echo -e "Make top level directores & set permissions"

mkdir -p ${SUBMITTY_DATA_DIR}
mkdir -p ${SUBMITTY_DATA_DIR}/courses
mkdir -p ${SUBMITTY_DATA_DIR}/vcs
mkdir -p ${SUBMITTY_DATA_DIR}/logs
mkdir -p ${SUBMITTY_DATA_DIR}/logs/autograding
mkdir -p ${SUBMITTY_DATA_DIR}/logs/site_errors
mkdir -p ${SUBMITTY_DATA_DIR}/logs/access


# set the permissions of these directories
chown  root:${COURSE_BUILDERS_GROUP}              ${SUBMITTY_DATA_DIR}
chmod  751                                        ${SUBMITTY_DATA_DIR}
chown  root:${COURSE_BUILDERS_GROUP}              ${SUBMITTY_DATA_DIR}/courses
chmod  751                                        ${SUBMITTY_DATA_DIR}/courses
chown  root:www-data                              ${SUBMITTY_DATA_DIR}/vcs
chmod  770                                        ${SUBMITTY_DATA_DIR}/vcs
chown  -R ${HWPHP_USER}:${COURSE_BUILDERS_GROUP}  ${SUBMITTY_DATA_DIR}/logs
chmod  -R u+rwx,g+rxs,o+x                         ${SUBMITTY_DATA_DIR}/logs
chown  -R ${HWCRON_USER}:${COURSE_BUILDERS_GROUP} ${SUBMITTY_DATA_DIR}/logs/autograding
chmod  -R u+rwx,g+rxs                             ${SUBMITTY_DATA_DIR}/logs/autograding

# if the to_be_graded directories do not exist, then make them
mkdir -p $SUBMITTY_DATA_DIR/to_be_graded_interactive
mkdir -p $SUBMITTY_DATA_DIR/to_be_graded_batch
mkdir -p $SUBMITTY_DATA_DIR/to_be_built

# set the permissions of these directories

#hwphp will write items to this list, hwcron will remove them
chown  ${HWCRON_USER}:${HWCRONPHP_GROUP}        $SUBMITTY_DATA_DIR/to_be_graded_interactive
chmod  770                                      $SUBMITTY_DATA_DIR/to_be_graded_interactive
#course builders (instructors & head TAs) will write items to this todo list, hwcron will remove them
chown  ${HWCRON_USER}:${COURSE_BUILDERS_GROUP}  $SUBMITTY_DATA_DIR/to_be_graded_batch
chmod  770                                      $SUBMITTY_DATA_DIR/to_be_graded_batch

#hwphp will write items to this list, hwcron will remove them
chown  ${HWCRON_USER}:${HWCRONPHP_GROUP}        $SUBMITTY_DATA_DIR/to_be_built
chmod  770                                      $SUBMITTY_DATA_DIR/to_be_built



########################################################################################################################
########################################################################################################################
# RSYNC NOTES
#  a = archive, recurse through directories, preserves file permissions, owner  [ NOT USED, DON'T WANT TO MESS W/ PERMISSIONS ]
#  r = recursive
#  v = verbose, what was actually copied
#  t = preserve modification times
#  u = only copy things that have changed
#  z = compresses (faster for text, maybe not for binary)
#  (--delete, but probably dont want)
#  / trailing slash, copies contents into target
#  no slash, copies the directory & contents to target


########################################################################################################################
########################################################################################################################
# COPY THE CORE GRADING CODE (C++ files) & BUILD THE SUBMITTY GRADING LIBRARY

echo -e "Copy the grading code"

# copy the files from the repo
rsync -rtz ${SUBMITTY_REPOSITORY}/grading ${SUBMITTY_INSTALL_DIR}/src

#replace necessary variables
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/src/grading/Sample_CMakeLists.txt
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/src/grading/CMakeLists.txt
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/src/grading/system_call_check.cpp
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/src/grading/seccomp_functions.cpp
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/src/grading/execute.cpp

# building the autograding library
mkdir -p ${SUBMITTY_INSTALL_DIR}/src/grading/lib
pushd ${SUBMITTY_INSTALL_DIR}/src/grading/lib
cmake ..
make
if [ $? -ne 0 ] ; then
    echo "ERROR BUILDING AUTOGRADING LIBRARY"
    exit 1
fi
popd

# root will be owner & group of these files
chown -R  root:root ${SUBMITTY_INSTALL_DIR}/src
# "other" can cd into & ls all subdirectories
find ${SUBMITTY_INSTALL_DIR}/src -type d -exec chmod 555 {} \;
# "other" can read all files
find ${SUBMITTY_INSTALL_DIR}/src -type f -exec chmod 444 {} \;


########################################################################################################################
########################################################################################################################
# COPY THE SAMPLE FILES FOR COURSE MANAGEMENT

echo -e "Copy the sample files"

# copy the files from the repo
rsync -rtz ${SUBMITTY_REPOSITORY}/more_autograding_examples ${SUBMITTY_INSTALL_DIR}

# root will be owner & group of these files
chown -R  root:root ${SUBMITTY_INSTALL_DIR}/more_autograding_examples
# but everyone can read all that files & directories, and cd into all the directories
find ${SUBMITTY_INSTALL_DIR}/more_autograding_examples -type d -exec chmod 555 {} \;
find ${SUBMITTY_INSTALL_DIR}/more_autograding_examples -type f -exec chmod 444 {} \;


########################################################################################################################
########################################################################################################################
# BUILD JUNIT TEST RUNNER (.java file)

echo -e "Build the junit test runner"

# copy the file from the repo
rsync -rtz ${SUBMITTY_REPOSITORY}/junit_test_runner/TestRunner.java ${SUBMITTY_INSTALL_DIR}/JUnit/TestRunner.java

pushd ${SUBMITTY_INSTALL_DIR}/JUnit > /dev/null
# root will be owner & group of the source file
chown  root:root  TestRunner.java
# everyone can read this file
chmod  444 TestRunner.java

# compile the executable
javac -cp ./junit-4.12.jar TestRunner.java

# everyone can read the compiled file
chown root:root TestRunner.class
chmod 444 TestRunner.class

popd > /dev/null

########################################################################################################################
########################################################################################################################
# COPY VARIOUS SCRIPTS USED BY INSTRUCTORS AND SYS ADMINS FOR COURSE ADMINISTRATION

echo -e "Copy the scripts"

# make the directory (has a different name)
mkdir -p ${SUBMITTY_INSTALL_DIR}/bin
chown root:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin
chmod 755 ${SUBMITTY_INSTALL_DIR}/bin

# copy all of the files
rsync -rtz  ${SUBMITTY_REPOSITORY}/bin/*   ${SUBMITTY_INSTALL_DIR}/bin/
#replace necessary variables in the copied scripts
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/authentication.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/adduser.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/create_course.sh
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/generate_repos.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/grade_item.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/submitty_grading_scheduler.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/grade_items_logging.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/grading_done.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/regrade.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/check_everything.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/build_homework_function.sh
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/setcsvfields
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/setcsvfields.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/get_version_details.py
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/bin/insert_database_version_data.py

# most of the scripts should be root only
find ${SUBMITTY_INSTALL_DIR}/bin -type f -exec chown root:root {} \;
find ${SUBMITTY_INSTALL_DIR}/bin -type f -exec chmod 500 {} \;

# all course builders (instructors & head TAs) need read/execute access to these scripts
chown root:www-data ${SUBMITTY_INSTALL_DIR}/bin/authentication.py
chown root:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/regrade.py
chown root:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/grading_done.py
chown root:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/read_iclicker_ids.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/authentication.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/regrade.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/grading_done.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/read_iclicker_ids.py

# course builders & hwcron need access to these scripts
chown ${HWCRON_USER}:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/build_homework_function.sh
chown ${HWCRON_USER}:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/make_assignments_txt_file.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/build_homework_function.sh
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/make_assignments_txt_file.py

# hwcron only things
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/insert_database_version_data.py
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/grade_item.py
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/submitty_grading_scheduler.py
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/grade_items_logging.py
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/write_grade_history.py
chown root:${HWCRON_USER} ${SUBMITTY_INSTALL_DIR}/bin/build_config_upload.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/insert_database_version_data.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/grade_item.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/submitty_grading_scheduler.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/grade_items_logging.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/write_grade_history.py
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/build_config_upload.py

# root only things
chown root:root ${SUBMITTY_INSTALL_DIR}/bin/untrusted_canary.py
chown root:root ${SUBMITTY_INSTALL_DIR}/bin/check_everything.py
chown root:root ${SUBMITTY_INSTALL_DIR}/bin/get_version_details.py
chmod 500 ${SUBMITTY_INSTALL_DIR}/bin/untrusted_canary.py
chmod 500 ${SUBMITTY_INSTALL_DIR}/bin/check_everything.py
chmod 500 ${SUBMITTY_INSTALL_DIR}/bin/get_version_details.py


# build the helper program for strace output and restrictions by system call categories
g++ ${SUBMITTY_INSTALL_DIR}/src/grading/system_call_check.cpp -o ${SUBMITTY_INSTALL_DIR}/bin/system_call_check.out
# set the permissions
chown root:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/bin/system_call_check.out
chmod 550 ${SUBMITTY_INSTALL_DIR}/bin/system_call_check.out


###############################################
# scripts used only by root for setup only
mkdir -p ${SUBMITTY_INSTALL_DIR}/.setup/bin
chown root:root ${SUBMITTY_INSTALL_DIR}/.setup/bin
chmod 700 ${SUBMITTY_INSTALL_DIR}/.setup/bin

cp  ${SUBMITTY_REPOSITORY}/.setup/bin/reupload_old_assignments.py   ${SUBMITTY_INSTALL_DIR}/.setup/bin/
cp  ${SUBMITTY_REPOSITORY}/.setup/bin/reupload_generate_csv.py   ${SUBMITTY_INSTALL_DIR}/.setup/bin/
chown root:root ${SUBMITTY_INSTALL_DIR}/.setup/bin/reupload*
chmod 700 ${SUBMITTY_INSTALL_DIR}/.setup/bin/reupload*
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/.setup/bin/reupload_old_assignments.py


########################################################################################################################
########################################################################################################################
# PREPARE THE UNTRUSTED_EXEUCTE EXECUTABLE WITH SUID

# copy the file
rsync -rtz  ${SUBMITTY_REPOSITORY}/.setup/untrusted_execute.c   ${SUBMITTY_INSTALL_DIR}/.setup/
# replace necessary variables
replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/.setup/untrusted_execute.c

# SUID (Set owner User ID up on execution), allows the $HWCRON_USER
# to run this executable as sudo/root, which is necessary for the
# "switch user" to untrusted as part of the sandbox.

pushd ${SUBMITTY_INSTALL_DIR}/.setup/ > /dev/null
# set ownership/permissions on the source code
chown root:root untrusted_execute.c
chmod 500 untrusted_execute.c
# compile the code
g++ -static untrusted_execute.c -o ${SUBMITTY_INSTALL_DIR}/bin/untrusted_execute
# change permissions & set suid: (must be root)
chown root  ${SUBMITTY_INSTALL_DIR}/bin/untrusted_execute
chgrp $HWCRON_USER  ${SUBMITTY_INSTALL_DIR}/bin/untrusted_execute
chmod 4550  ${SUBMITTY_INSTALL_DIR}/bin/untrusted_execute
popd > /dev/null


################################################################################################################
################################################################################################################
# COPY THE TA GRADING WEBSITE

echo -e "Copy the ta grading website"

mkdir -p ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading

# Using a symbolic link would be nicer, but it seems that suphp doesn't like them very much so we just have
# two copies of the site
rsync  -rtz ${SUBMITTY_REPOSITORY}/TAGradingServer/*php         ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading
rsync  -rtz ${SUBMITTY_REPOSITORY}/TAGradingServer/toolbox      ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading
rsync  -rtz ${SUBMITTY_REPOSITORY}/TAGradingServer/lib          ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading
rsync  -rtz ${SUBMITTY_REPOSITORY}/TAGradingServer/account      ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading
rsync  -rtz ${SUBMITTY_REPOSITORY}/TAGradingServer/models       ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading

# set special user $HWPHP_USER as owner & group of all hwgrading_website files
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -exec chown $HWPHP_USER:$HWPHP_USER {} \;

# set the permissions of all files
# $HWPHP_USER can read & execute all directories and read all files
# "other" can cd into all subdirectories
chmod -R 400 ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type d -exec chmod uo+x {} \;
# "other" can read all .txt & .css files
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.css -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.txt -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.ico -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.css -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.png -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.jpg -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.gif -exec chmod o+r {} \;

# "other" can read & execute all .js files
find ${SUBMITTY_INSTALL_DIR}/site/public/hwgrading -type f -name \*.js -exec chmod o+rx {} \;

################################################################################################################
################################################################################################################
# COPY THE 1.0 Grading Website

echo -e "Copy the submission website"

# copy the website from the repo
rsync -rtz   ${SUBMITTY_REPOSITORY}/site   ${SUBMITTY_INSTALL_DIR}

# set special user $HWPHP_USER as owner & group of all website files
find ${SUBMITTY_INSTALL_DIR}/site -exec chown $HWPHP_USER:$HWPHP_USER {} \;
find ${SUBMITTY_INSTALL_DIR}/site/cgi-bin -exec chown $HWCGI_USER:$HWCGI_USER {} \;

# TEMPORARY (until we have generalized code for generating charts in html)
# copy the zone chart images
mkdir -p ${SUBMITTY_INSTALL_DIR}/site/public/zone_images/
cp ${SUBMITTY_INSTALL_DIR}/zone_images/* ${SUBMITTY_INSTALL_DIR}/site/public/zone_images/ 2>/dev/null

# set the permissions of all files
# $HWPHP_USER can read & execute all directories and read all files
# "other" can cd into all subdirectories
chmod -R 440 ${SUBMITTY_INSTALL_DIR}/site
find ${SUBMITTY_INSTALL_DIR}/site -type d -exec chmod ogu+x {} \;

# "other" can read all .txt, .jpg, & .css files
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.css -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.otf -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.jpg -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.png -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.ico -exec chmod o+r {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.txt -exec chmod o+r {} \;
# "other" can read & execute all .js files
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.js -exec chmod o+rx {} \;
find ${SUBMITTY_INSTALL_DIR}/site -type f -name \*.cgi -exec chmod u+x {} \;

replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/site/config/master_template.ini
mv ${SUBMITTY_INSTALL_DIR}/site/config/master_template.ini ${SUBMITTY_INSTALL_DIR}/site/config/master.ini


# return the course index page (only necessary when 'clean' option is used)
if [ -f "$mytempcurrentcourses" ]; then
    echo "return this file! ${mytempcurrentcourses} ${originalcurrentcourses}"
    mv ${mytempcurrentcourses} ${originalcurrentcourses}
fi


################################################################################################################
################################################################################################################
# GENERATE & INSTALL THE CRONTAB FILE FOR THE hwcron USER
#

echo -e "Generate & install the crontab file for hwcron user"

# name of temporary file
HWCRON_CRONTAB_FILE=my_hwcron_crontab_file.txt

# generate the file
echo -e "\n\n"                                                                                >  ${HWCRON_CRONTAB_FILE}
echo "# DO NOT EDIT -- THIS FILE CREATED AUTOMATICALLY BY INSTALL_SUBMITTY.sh"                >> ${HWCRON_CRONTAB_FILE}

## NOTE:  the build_config_upload script is hardcoded to run for ~5 minutes and then exit
minutes=0
printf "*/5 * * * *   ${SUBMITTY_INSTALL_DIR}/bin/build_config_upload.py  >  /dev/null\n"  >> ${HWCRON_CRONTAB_FILE}

echo "# DO NOT EDIT -- THIS FILE CREATED AUTOMATICALLY BY INSTALL_SUBMITTY.sh"                >> ${HWCRON_CRONTAB_FILE}
echo -e "\n\n"                                                                                >> ${HWCRON_CRONTAB_FILE}

# install the crontab file for the hwcron user
crontab  -u ${HWCRON_USER}  ${HWCRON_CRONTAB_FILE}
rm ${HWCRON_CRONTAB_FILE}


################################################################################################################
################################################################################################################
# COMPILE AND INSTALL ANALYSIS TOOLS

echo -e "Compile and install analysis tools"
ST_VERSION=v0.3.3
mkdir -p ${SUBMITTY_INSTALL_DIR}/SubmittyAnalysisTools

pushd ${SUBMITTY_INSTALL_DIR}/SubmittyAnalysisTools
if [[ ! -f VERSION || $(< VERSION) != "${ST_VERSION}" ]]; then
    for b in count plagiarism diagnostics;
        do wget -nv "https://github.com/Submitty/AnalysisTools/releases/download/${ST_VERSION}/${b}" -O ${b}
    done
    echo ${ST_VERSION} > VERSION
fi
popd

# change permissions
chown -R ${HWCRON_USER}:${COURSE_BUILDERS_GROUP} ${SUBMITTY_INSTALL_DIR}/SubmittyAnalysisTools
chmod -R 555 ${SUBMITTY_INSTALL_DIR}/SubmittyAnalysisTools

################################################################################################################
################################################################################################################
# INSTALL PYTHON SUBMITTY UTILS

echo -e "Install python_submitty_utils"

pushd ${SUBMITTY_REPOSITORY}/python_submitty_utils
python3 setup.py -q install

# fix permissions
chmod -R 555 /usr/local/lib/python*/*
chmod 555 /usr/lib/python*/dist-packages
sudo chmod 500   /usr/local/lib/python*/dist-packages/pam.py*
sudo chown hwcgi /usr/local/lib/python*/dist-packages/pam.py*
sudo chmod o+r /usr/local/lib/python*/dist-packages/submitty_utils*.egg
sudo chmod o+r /usr/local/lib/python*/dist-packages/easy-install.pth

popd

################################################################################################################
################################################################################################################
# INSTALL & START GRADING SCHEDULER DAEMON


# stop the scheduler (if it's running)
systemctl is-active --quiet submitty_grading_scheduler
is_active_before=$?
if [[ "$is_active_before" == "0" ]]; then
    systemctl stop submitty_grading_scheduler
    echo -e "Stopped Submitty Grading Scheduler Daemon\n"
fi

systemctl is-active --quiet submitty_grading_scheduler
is_active_tmp=$?
if [[ "$is_active_tmp" == "0" ]]; then
    echo -e "ERROR: did not successfully stop submitty grading scheduler daemon\n"
    exit 1
fi


# update the scheduler daemon
rsync -rtz  ${SUBMITTY_REPOSITORY}/.setup/submitty_grading_scheduler.service   /etc/systemd/system/submitty_grading_scheduler.service
chown -R hwcron:hwcron /etc/systemd/system/submitty_grading_scheduler.service
chmod 444 /etc/systemd/system/submitty_grading_scheduler.service


# delete the autograding tmp directories
rm -rf /var/local/submitty/autograding_tmp

# recreate the top level autograding tmp directory
mkdir /var/local/submitty/autograding_tmp
chown root:root /var/local/submitty/autograding_tmp
chmod 511 /var/local/submitty/autograding_tmp

# recreate the per untrusted directories
for ((i=0;i<$NUM_UNTRUSTED;i++));
do
    myuser=`printf "untrusted%02d" $i`
    mydir=`printf "/var/local/submitty/autograding_tmp/untrusted%02d" $i`
    mkdir $mydir
    chown hwcron:$myuser $mydir
    chmod 770 $mydir
done


# start the scheduler (if it was running)
if [[ "$is_active_before" == "0" ]]; then
    systemctl start submitty_grading_scheduler
    systemctl is-active --quiet submitty_grading_scheduler
    is_active_after=$?
    if [[ "$is_active_after" != "0" ]]; then
        echo -e "\nERROR!  Failed to restart Submitty Grading Scheduler Daemon\n"
    fi
    echo -e "Restarted Submitty Grading Scheduler Daemon\n"
else
    echo -e "NOTE: Submitty Grading Scheduler Daemon is not currently running\n"
    echo -e "To start the daemon, run:\n   sudo systemctl start submitty_grading_scheduler\n"
fi


################################################################################################################
################################################################################################################
# INSTALL TEST SUITE


# one optional argument installs & runs test suite
if [[ "$#" -ge 1 && $1 == "test" ]]; then

    # copy the directory tree and replace variables
    echo -e "Install Autograding Test Suite..."
    rsync -rtz  ${SUBMITTY_REPOSITORY}/tests/  ${SUBMITTY_INSTALL_DIR}/test_suite
    mkdir -p ${SUBMITTY_INSTALL_DIR}/test_suite/log
    replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/test_suite/integrationTests/lib.py

    # add a symlink to conveniently run the test suite or specific tests without the full reinstall
    ln -sf  ${SUBMITTY_INSTALL_DIR}/test_suite/integrationTests/run.py  ${SUBMITTY_INSTALL_DIR}/bin/run_test_suite.py

    echo -e "\nRun Autograding Test Suite...\n"

    # pop the first argument from the list of command args
    shift
    # pass any additional command line arguments to the run test suite
    ${SUBMITTY_INSTALL_DIR}/test_suite/integrationTests/run.py  "$@"

    echo -e "\nCompleted Autograding Test Suite\n"
fi

################################################################################################################
################################################################################################################

# INSTALL RAINBOW GRADES TEST SUITE


# one optional argument installs & runs test suite
if [[ "$#" -ge 1 && $1 == "test_rainbow" ]]; then

    # copy the directory tree and replace variables
    echo -e "Install Rainbow Grades Test Suite..."
    rsync -rtz  ${SUBMITTY_REPOSITORY}/tests/  ${SUBMITTY_INSTALL_DIR}/test_suite
    replace_fillin_variables ${SUBMITTY_INSTALL_DIR}/test_suite/rainbowGrades/test_sample.py

    # add a symlink to conveniently run the test suite or specific tests without the full reinstall
    #ln -sf  ${SUBMITTY_INSTALL_DIR}/test_suite/integrationTests/run.py  ${SUBMITTY_INSTALL_DIR}/bin/run_test_suite.py

    echo -e "\nRun Rainbow Grades Test Suite...\n"
    rainbow_counter=0
    rainbow_total=0

    # pop the first argument from the list of command args
    shift
    # pass any additional command line arguments to the run test suite
    rainbow_total=$((rainbow_total+1))
    ${SUBMITTY_INSTALL_DIR}/test_suite/rainbowGrades/test_sample.py  "$@"
    
    if [[ $? -ne 0 ]]; then
        echo -e "\n[ FAILED ] sample test\n"
    else
        rainbow_counter=$((rainbow_counter+1))
        echo -e "\n[ SUCCEEDED ] sample test\n"
    fi

    echo -e "\nCompleted Rainbow Grades Test Suite. $rainbow_counter of $rainbow_total tests succeeded.\n"
fi

################################################################################################################
################################################################################################################
