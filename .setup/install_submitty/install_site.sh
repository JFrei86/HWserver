#!/usr/bin/env bash


################################################################################################
### SITE INSTALLER
#
# This script is used to install the submitty site code from ${SUBMITTY_REPOSITORY}/site to
# ${SUBMITTY_INSTALL_DIR}/site. It then deals with composer and npm dependencies, and finally
# sets permissions as appropriate. To have the best efficiency and speed, we use rsync to
# deal with moving the files, and then use the result of that command to tell us what files
# were updated and to accomplish the following just for those files:
#   - if package.json or package-lock.json was modified, run NPM and move into place js files
#   - if composer.json or composer.lock was modified, run composer
#   - only set permissions for modified files
#
################################################################################################

set_permissions () {
    local fullpath=$1
    filename=$(basename -- "$fullpath")
    extension="${filename##*.}"
    # filename="${filename%.*}"
    case "${extension}" in
        css|otf|jpg|png|ico|txt|twig|map)
            chmod 444 ${fullpath}
            ;;
        bcmap|ttf|eot|svg|woff|woff2|js|cgi)
            chmod 445 ${fullpath}
            ;;
        html)
            if [ ${fullpath} != ${SUBMITTY_INSTALL_DIR}/site/public/index.html ]; then
                chmod 440 ${fullpath}
            fi
            ;;
        *)
            chmod 440 ${fullpath}
            ;;
    esac
}

echo -e "Copy the submission website"

THIS_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null && pwd )"
source ${THIS_DIR}/../bin/versions.sh

VAGRANT=0
if [ -d ${THIS_DIR}/../../.vagrant ]; then
    VAGRANT=1
fi

# This is run under /usr/local/submitty/GIT_CHECKOUT/Submitty/.setup/bin/
CONF_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"/../../../../config
SUBMITTY_REPOSITORY=$(jq -r '.submitty_repository' ${CONF_DIR}/submitty.json)
SUBMITTY_INSTALL_DIR=$(jq -r '.submitty_install_dir' ${CONF_DIR}/submitty.json)
PHP_USER=$(jq -r '.php_user' ${CONF_DIR}/submitty_users.json)
PHP_GROUP=${PHP_USER}
CGI_USER=$(jq -r '.cgi_user' ${CONF_DIR}/submitty_users.json)
CGI_GROUP=${CGI_USER}

mkdir -p ${SUBMITTY_INSTALL_DIR}/site/public
echo "Submitty is being updated. Please try again in 2 minutes." > /tmp/index.html
chmod 644 /tmp/index.html
chown ${CGI_USER}:${CGI_GROUP} /tmp/index.html
mv /tmp/index.html ${SUBMITTY_INSTALL_DIR}/site/public

# copy the website from the repo. We don't need the tests directory in production and then
# we don't want vendor as if it exists, it was generated locally for testing purposes, so
# we don't want it
result=$(rsync -rtz -i --exclude 'tests' --exclude '/site/cache' --exclude '/site/vendor' --exclude 'site/node_modules/' --exclude '/site/phpstan.neon' --exclude '/site/phpstan-baseline.neon' --exclude '/site/.eslintrc.json' --exclude '/site/.eslintignore' --exclude '/site/cypress/' --exclude '/site/cypress.json' --exclude '/site/jest.config.js' --exclude '/site/.babelrc.json' --exclude '/site/.stylelintrc.json' ${SUBMITTY_REPOSITORY}/site ${SUBMITTY_INSTALL_DIR})

# check for either of the dependency folders, and if they do not exist, pretend like
# their respective json file was edited. Composer needs the folder to exist to even
# run, but it could be someone just deleted the folders to try and fix some
# weird dependency installation issue (common with npm troubleshooting).
if [ ! -d ${SUBMITTY_INSTALL_DIR}/site/vendor ]; then
    mkdir ${SUBMITTY_INSTALL_DIR}/site/vendor
    chown -R ${PHP_USER}:${PHP_USER} ${SUBMITTY_INSTALL_DIR}/site/vendor
    result=$(echo -e "${result}\n>f+++++++++ site/composer.json")
fi

if [ ! -d ${SUBMITTY_INSTALL_DIR}/site/node_modules ]; then
    result=$(echo -e "${result}\n>f+++++++++ site/package.json")
fi

readarray -t result_array <<< "${result}"

# clear old twig cache
if [ -d "${SUBMITTY_INSTALL_DIR}/site/cache/twig" ]; then
    rm -rf "${SUBMITTY_INSTALL_DIR}/site/cache/twig"
fi
# create twig cache directory
mkdir -p ${SUBMITTY_INSTALL_DIR}/site/cache/twig

# Update ownership to PHP_USER for affected files and folders
chown ${PHP_USER}:${PHP_GROUP} ${SUBMITTY_INSTALL_DIR}/site
for entry in "${result_array[@]}"; do
    chown ${PHP_USER}:${PHP_GROUP} "${SUBMITTY_INSTALL_DIR}/${entry:12}"
done

# Update permissions & ownership for cache directory
chmod -R 751 ${SUBMITTY_INSTALL_DIR}/site/cache
chown -R ${PHP_USER}:${PHP_GROUP} ${SUBMITTY_INSTALL_DIR}/site/cache

# Update ownership for cgi-bin to CGI_USER
find ${SUBMITTY_INSTALL_DIR}/site/cgi-bin -exec chown ${CGI_USER}:${CGI_GROUP} {} \;

# set the mask for composer so that it'll run properly and be able to delete/modify
# files under it
if [ -d "${SUBMITTY_INSTALL_DIR}/site/vendor/composer" ]; then
    chmod 640 ${SUBMITTY_INSTALL_DIR}/site/composer.lock
    chmod -R 740 ${SUBMITTY_INSTALL_DIR}/site/vendor
fi

# Set proper read/execute for "other" on files with certain extensions
# so apache can properly handle them
echo "Set permissions"
for entry in "${result_array[@]}"; do
    if echo ${entry} | grep -E -q "^.d"; then
        if [ ! -z "${entry}" ]; then
            chmod 551 ${SUBMITTY_INSTALL_DIR}/${entry:12}
        fi
    elif echo ${entry} | grep -E -q "site/public"; then
        if [ ! -z "${entry}" ]; then
            set_permissions "${SUBMITTY_INSTALL_DIR}/${entry:12}"
        fi
    elif echo ${entry} | grep -E -q "^.f"; then
        if [ ! -z "${entry}" ]; then
            chmod 440 ${SUBMITTY_INSTALL_DIR}/${entry:12}
        fi
    fi
done

if echo "${result}" | grep -E -q "composer\.(json|lock)" || [ ${VAGRANT} == 1 ]; then
    # install composer dependencies and generate classmap
    su - ${PHP_USER} -c "composer install -d \"${SUBMITTY_INSTALL_DIR}/site\" --no-dev --prefer-dist --optimize-autoloader --no-suggest"
    chown -R ${PHP_USER}:${PHP_USER} ${SUBMITTY_INSTALL_DIR}/site/vendor
else
    su - ${PHP_USER} -c "composer dump-autoload -d \"${SUBMITTY_INSTALL_DIR}/site\" --optimize --no-dev"
    chown -R ${PHP_USER}:${PHP_USER} ${SUBMITTY_INSTALL_DIR}/site/vendor/composer
fi
find ${SUBMITTY_INSTALL_DIR}/site/vendor -type d -exec chmod 551 {} \;
find ${SUBMITTY_INSTALL_DIR}/site/vendor -type f -exec chmod 440 {} \;
chmod 440 ${SUBMITTY_INSTALL_DIR}/site/composer.lock

if echo "{$result}" | grep -E -q "package(-lock)?.json"; then
    # Install JS dependencies and then copy them into place
    # We need to create the node_modules folder initially if it
    # doesn't exist, or else submitty_php won't be able to make it
    if [ ! -d "${SUBMITTY_INSTALL_DIR}/site/node_modules" ]; then
        mkdir -p ${SUBMITTY_INSTALL_DIR}/site/node_modules
        chown submitty_php:submitty_php ${SUBMITTY_INSTALL_DIR}/site/node_modules
    fi

    chmod -R 740 ${SUBMITTY_INSTALL_DIR}/site/node_modules
    if [ -f "${SUBMITTY_INSTALL_DIR}/site/package-lock.json" ]; then
        chmod 640 ${SUBMITTY_INSTALL_DIR}/site/package-lock.json
    fi

    su - ${PHP_USER} -c "cd ${SUBMITTY_INSTALL_DIR}/site && npm install --loglevel=error --no-save"

    NODE_FOLDER=${SUBMITTY_INSTALL_DIR}/site/node_modules
    VENDOR_FOLDER=${SUBMITTY_INSTALL_DIR}/site/public/vendor

    chown -R ${PHP_USER}:${PHP_USER} ${NODE_FOLDER}

    echo "Copy NPM packages into place"
    # clean out the old install so we don't leave anything behind
    rm -rf ${VENDOR_FOLDER}
    mkdir ${VENDOR_FOLDER}
    # fontawesome
    mkdir ${VENDOR_FOLDER}/fontawesome
    mkdir ${VENDOR_FOLDER}/fontawesome/css
    cp ${NODE_FOLDER}/@fortawesome/fontawesome-free/css/all.min.css ${VENDOR_FOLDER}/fontawesome/css/all.min.css
    cp -R ${NODE_FOLDER}/@fortawesome/fontawesome-free/webfonts/ ${VENDOR_FOLDER}/fontawesome/
    # bootstrap
    cp -R ${NODE_FOLDER}/bootstrap/dist/ ${VENDOR_FOLDER}/bootstrap
    # chosen.js
    cp -R ${NODE_FOLDER}/chosen-js ${VENDOR_FOLDER}/chosen-js
    # codemirror
    mkdir ${VENDOR_FOLDER}/codemirror
    mkdir ${VENDOR_FOLDER}/codemirror/theme
    cp ${NODE_FOLDER}/codemirror/lib/codemirror.js ${VENDOR_FOLDER}/codemirror/
    cp ${NODE_FOLDER}/codemirror/lib/codemirror.css ${VENDOR_FOLDER}/codemirror/
    cp -R ${NODE_FOLDER}/codemirror/mode/ ${VENDOR_FOLDER}/codemirror
    cp ${NODE_FOLDER}/codemirror/theme/monokai.css ${VENDOR_FOLDER}/codemirror/theme
    cp ${NODE_FOLDER}/codemirror/theme/eclipse.css ${VENDOR_FOLDER}/codemirror/theme
    cp -R ${NODE_FOLDER}/codemirror/addon ${VENDOR_FOLDER}/codemirror/addon
    # flatpickr
    mkdir ${VENDOR_FOLDER}/flatpickr
    cp -R ${NODE_FOLDER}/flatpickr/dist/* ${VENDOR_FOLDER}/flatpickr
    # shortcut-buttons-flatpickr
    mkdir ${VENDOR_FOLDER}/flatpickr/plugins/shortcutButtons
    cp -R ${NODE_FOLDER}/shortcut-buttons-flatpickr/dist/* ${VENDOR_FOLDER}/flatpickr/plugins/shortcutButtons
    # jquery
    mkdir ${VENDOR_FOLDER}/jquery
    cp ${NODE_FOLDER}/jquery/dist/jquery.min.* ${VENDOR_FOLDER}/jquery
    # jquery.are-you-sure
    mkdir ${VENDOR_FOLDER}/jquery.are-you-sure
    cp ${NODE_FOLDER}/jquery.are-you-sure/jquery.are-you-sure.js ${VENDOR_FOLDER}/jquery.are-you-sure
    # jquery-ui
    mkdir ${VENDOR_FOLDER}/jquery-ui
    cp ${NODE_FOLDER}/jquery-ui-dist/*.min.* ${VENDOR_FOLDER}/jquery-ui
    cp -R ${NODE_FOLDER}/jquery-ui-dist/images ${VENDOR_FOLDER}/jquery-ui/
    # pdfjs
    mkdir ${VENDOR_FOLDER}/pdfjs
    cp ${NODE_FOLDER}/pdfjs-dist/build/pdf.min.js ${VENDOR_FOLDER}/pdfjs
    cp ${NODE_FOLDER}/pdfjs-dist/build/pdf.worker.min.js ${VENDOR_FOLDER}/pdfjs
    cp ${NODE_FOLDER}/pdfjs-dist/web/pdf_viewer.css ${VENDOR_FOLDER}/pdfjs/pdf_viewer.css
    cp ${NODE_FOLDER}/pdfjs-dist/web/pdf_viewer.js ${VENDOR_FOLDER}/pdfjs/pdf_viewer.js
    cp -R ${NODE_FOLDER}/pdfjs-dist/cmaps ${VENDOR_FOLDER}/pdfjs
    # plotly
    mkdir ${VENDOR_FOLDER}/plotly
    cp ${NODE_FOLDER}/plotly.js-dist/plotly.js ${VENDOR_FOLDER}/plotly

    mkdir ${VENDOR_FOLDER}/mermaid
    cp ${NODE_FOLDER}/mermaid/dist/*.min.* ${VENDOR_FOLDER}/mermaid

    # pdf-annotate.js
    cp -R "${NODE_FOLDER}/@submitty/pdf-annotate.js/dist" ${VENDOR_FOLDER}/pdf-annotate.js
    # twig.js
    mkdir ${VENDOR_FOLDER}/twigjs
    cp ${NODE_FOLDER}/twig/twig.min.js ${VENDOR_FOLDER}/twigjs/
    # jspdf
    mkdir ${VENDOR_FOLDER}/jspdf
    cp ${NODE_FOLDER}/jspdf/dist/jspdf.umd.min.js ${VENDOR_FOLDER}/jspdf/jspdf.min.js
    cp ${NODE_FOLDER}/jspdf/dist/jspdf.umd.min.js.map ${VENDOR_FOLDER}/jspdf/jspdf.min.js.map

    find ${NODE_FOLDER} -type d -exec chmod 551 {} \;
    find ${NODE_FOLDER} -type f -exec chmod 440 {} \;
    find ${VENDOR_FOLDER} -type d -exec chmod 551 {} \;
    find ${VENDOR_FOLDER} -type f | while read file; do set_permissions "$file"; done
fi

chmod 440 ${SUBMITTY_INSTALL_DIR}/site/package-lock.json

# Set cgi-bin permissions
chown -R ${CGI_USER}:${CGI_USER} ${SUBMITTY_INSTALL_DIR}/site/cgi-bin
chmod 540 ${SUBMITTY_INSTALL_DIR}/site/cgi-bin/*
chmod 550 ${SUBMITTY_INSTALL_DIR}/site/cgi-bin/git-http-backend

# cache needs to be writable
find ${SUBMITTY_INSTALL_DIR}/site/cache -type d -exec chmod u+w {} \;

# reload PHP-FPM before we re-enable website, but only if PHP-FPM is actually being used
# as expected (Travis for example will fail here otherwise).
PHP_VERSION=$(php -r 'print PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
set +e
systemctl is-active --quiet php${PHP_VERSION}-fpm
if [[ "$?" == "0" ]]; then
    systemctl reload php${PHP_VERSION}-fpm
fi
set -e

rm -f ${SUBMITTY_INSTALL_DIR}/site/public/index.html
