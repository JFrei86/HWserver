#!/usr/bin/env bash

# Submitty source code is split among a number of repositories.

# This script performs the initial checkout and manages updates of the
# repositories to ensure all of the source code is up-to-date.

########################################################################

# this script must be run by root or sudo
if [[ "$UID" -ne "0" ]] ; then
    echo "ERROR: This script must be run by root or sudo"
    exit 1
fi

# get the repository name from the location of this script
MY_PATH="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null && pwd )"
SUBMITTY_REPOSITORY=${MY_PATH}/../..

########################################################################

# These variables specify the minimum version necessary for
# dependencies between versions.

source ${MY_PATH}/versions.sh

########################################################################
# Helper function requires 2 args, the short name of the repository,
# and the minimum required version.

function clone_or_update_repo {

    repo_name=$1
    min_repo_version=$2
    parent_repo_dir=${SUBMITTY_REPOSITORY}/..
    repo_dir=${parent_repo_dir}/${repo_name}

    echo "CLONE OR UPDATE ${repo_name}... "

    if [ -d "${repo_dir}" ]; then

        # IF THE REPO ALREADY EXISTS...
        pushd ${repo_dir} > /dev/null

        # CHECK TO SEE IF VERSION MATCHES OR IS ANCESTOR
        git merge-base --is-ancestor "${min_repo_version}" HEAD 2> /dev/null
        if [ $? -eq 0 ]; then
            echo -e "    current version matches or exceeds minimum version ${min_repo_version}"
        else
            echo "    this repository is out of date..."

            # CHECK TO SEE IF THIS IS A SHALLOW REPOSITORY (NO HISTORY)
            if [ -f $(git rev-parse --git-dir)/shallow ]; then

                # WE CAN AUTOMATICALLY DELETE
                popd > /dev/null
                rm -rf ${repo_dir}

                # AND THEN RE-CLONE THIS REPOSITORY
                pushd ${parent_repo_dir} > /dev/null
                git clone --branch ${min_repo_version} --depth 1 "https://github.com/Submitty/${repo_name}" 2> /dev/null
                popd > /dev/null

                echo -e "    automatically updated to version ${min_repo_version}.\n"

            else

                # OTHERWISE, IF THIS REPOSITORY HAS HISTORY, THIS
                # REPOSITORY MAY BE UNDER ACTIVE DEVELOPMENT AND IT IS
                # SAFEST TO LET THE USER UPDATE THIS REPOSITORY
                echo -e "    ERROR: Submitty/${repo_name} repository history does not contain version ${min_repo_version}"
                echo -e "        Run 'git fetch' to get the tags from github."
                echo -e "        Also check to be sure your current branch is up-to-date."
                popd > /dev/null
                exit 1
            fi
        fi

    else

        # THE REPO DID NOT EXIST
        echo "    the repository did not previously exist... "
        pushd ${parent_repo_dir} > /dev/null
        git clone --branch ${min_repo_version} --depth 1 "https://github.com/Submitty/${repo_name}" 2> /dev/null
        popd > /dev/null
        echo -e "    automatically cloned version ${min_repo_version}\n"

    fi
}

########################################################################

clone_or_update_repo  AnalysisTools  ${AnalysisTools_Version}
clone_or_update_repo  Lichen  ${Lichen_Version}
clone_or_update_repo  RainbowGrades  ${RainbowGrades_Version}
clone_or_update_repo  Tutorial  ${Tutorial_Version}
clone_or_update_repo  SysadminTools  ${SysadminTools_Version}

