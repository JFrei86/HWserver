#!/bin/bash

SOURCE="${BASH_SOURCE[0]}"
# resolve $SOURCE until the file is no longer a symlink
while [ -h "$SOURCE" ]; do
  DIR="$( cd -P "$( dirname "$SOURCE" )" && pwd )"
  SOURCE="$(readlink "$SOURCE")"
  # if $SOURCE was a relative symlink, we need to resolve
  # it relative to the path where the symlink file was located
  [[ ${SOURCE} != /* ]] && SOURCE="$DIR/$SOURCE"
done
DIR="$( cd -P "$( dirname "$SOURCE" )" && pwd )"

source ${DIR}/../common/common_env.sh

#sudo chmod -R 755 /home/travis/build

#if [ ! -f "$SELENIUM_JAR" ]; then
#    echo "Downloading Selenium"
#    sudo mkdir -p $(dirname "${SELENIUM_JAR}")
#    sudo wget -O "${SELENIUM_JAR}" "${SELENIUM_DOWNLOAD_URL}"
#    echo "Downloaded Selenium"
#fi

sudo bash -c 'echo -e "#%PAM-1.0
auth required pam_unix.so
account required pam_unix.so" > /etc/pam.d/httpd'
sudo sed -i '25s/^/\#/' /etc/pam.d/common-password
sudo sed -i '26s/pam_unix.so obscure use_authtok try_first_pass sha512/pam_unix.so obscure minlen=1 sha512/' /etc/pam.d/common-password
PG_VERSION="$(psql -V | egrep -o '[0-9]{1,}.[0-9]{1,}')"
sudo sed -i -e "s/# ----------------------------------/# ----------------------------------\nhostssl    all    all    192.168.56.0\/24    pam\nhost       all    all    192.168.56.0\/24    pam\nhost       all    all    all                md5/" /etc/postgresql/${PG_VERSION}/main/pg_hba.conf

sudo mkdir -p ${SUBMITTY_INSTALL_DIR}
sudo mkdir -p ${SUBMITTY_DATA_DIR}
sudo ln -s ${TRAVIS_BUILD_DIR} ${SUBMITTY_REPOSITORY}

sudo python ${DIR}/../bin/create_untrusted_users.py

sudo addgroup hwcronphp
sudo addgroup course_builders
sudo adduser hwphp --gecos "First Last,RoomNumber,WorkPhone,HomePhone" --disabled-password
sudo adduser hwcgi --gecos "First Last,RoomNumber,WorkPhone,HomePhone" --disabled-password
sudo adduser hwcgi hwphp
sudo adduser hwphp shadow
sudo adduser hwcgi shadow
sudo adduser hwcron --gecos "First Last,RoomNumber,WorkPhone,HomePhone" --disabled-password
sudo adduser hwphp hwcronphp
sudo adduser hwcron hwcronphp
sudo adduser hsdbu --gecos "First Last,RoomNumber,WorkPhone,HomePhone" --disabled-password
sudo echo "hsdbu:hsdbu" | sudo chpasswd

sudo chown hwphp:hwphp ${SUBMITTY_INSTALL_DIR}
sudo chown hwphp:hwphp ${SUBMITTY_DATA_DIR}
sudo chmod 777         ${SUBMITTY_INSTALL_DIR}
sudo chmod 777         ${SUBMITTY_DATA_DIR}

sudo echo -e "localhost
hsdbu
hsdbu
http://localhost
y" | sudo bash ${SUBMITTY_REPOSITORY}/.setup/CONFIGURE_SUBMITTY.sh

sudo bash ${SUBMITTY_REPOSITORY}/Docs/sample_bin/admin_scripts_setup
sudo bash ${SUBMITTY_INSTALL_DIR}/.setup/INSTALL_SUBMITTY.sh clean
