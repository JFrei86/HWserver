#!/usr/bin/env bash

# this script must be run by root or sudo
if [[ "$UID" -ne "0" ]] ; then
    echo "ERROR: This script must be run by root or sudo"
    exit
fi

apt-get install software-properties-common
echo "\n" | add-apt-repository ppa:webupd8team/java
echo oracle-java8-installer shared/accepted-oracle-license-v1-1 select true | sudo /usr/bin/debconf-set-selections
apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys EEA14886

apt-get update
apt-get install -qqy python python-dev python3 python3-dev libpython3.5
if [ ! -x "$(command -v pip)" ]; then
    wget --tries=5 https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py
    python2 /tmp/get-pip.py
    python3 /tmp/get-pip.py
    rm -f /tmp/get-pip.py
else
    pip2 install -U pip
    pip3 install -U pip
fi

if [ ${VAGRANT} == 1 ]; then
   #
    # The goal here is to ensure the VM is accessible from your own
    # computer for code testing, has an outgoing connection to the
    # Internet to access github and receive Ubuntu updates, but is also
    # unreachable via incoming Internet connections so to block uninvited
    # guests.
    #
    # The VM’s host-only adapter provides a private connection to the VM,
    # but Ubuntu also needs to be configured to use this adapter.

    echo "Binding static IPs to \"Host-Only\" virtual network interface."

    # Note: Ubuntu 16.04 switched from the eth# scheme to ep0s# scheme.
    # enp0s3 is auto-configured by Vagrant as NAT.  enp0s8 is a host-only adapter and
    # not auto-configured.  enp0s8 is manually set so that the host-only network
    # interface remains consistent among VM reboots as Vagrant has a bad habit of
    # discarding and recreating networking interfaces everytime the VM is restarted.
    # ep0s8 is statically bound to 192.168.56.101.
    echo -e "auto enp0s8\niface enp0s8 inet static\naddress 192.168.56.101\nnetmask 255.255.255.0\n\n" >> /etc/network/interfaces.d/00-vagrant.cfg

    # Turn them on.
    ifup enp0s8

    export SUBMISSION_URL='http://192.168.56.101'
    chmod -x /etc/update-motd.d/*
    chmod -x /usr/share/landscape/landscape-sysinfo.wrapper
    chmod +x /etc/update-motd.d/00-header

    echo -e '
 _______  __   __  _______  __   __  ___   _______  _______  __   __
|       ||  | |  ||  _    ||  |_|  ||   | |       ||       ||  | |  |
|  _____||  | |  || |_|   ||       ||   | |_     _||_     _||  |_|  |
| |_____ |  |_|  ||       ||       ||   |   |   |    |   |  |       |
|_____  ||       ||  _   | |       ||   |   |   |    |   |  |_     _|
 _____| ||       || |_|   || ||_|| ||   |   |   |    |   |    |   |
|_______||_______||_______||_|   |_||___|   |___|    |___|    |___|

############################################################
##  All user accounts have same password unless otherwise ##
##  noted below. The following user accounts exist:       ##
##    vagrant/vagrant, root/vagrant, hsdbu, hwphp,        ##
##    hwcgi hwcron, ta, instructor, developer,            ##
##    postgres                                            ##
##                                                        ##
##  The following accounts have database accounts         ##
##  with same password as above:                          ##
##    hsdbu, postgres, root, vagrant                      ##
##                                                        ##
##  The VM can be accessed with the following urls:       ##
##    http://192.168.56.101 (submission)                  ##
##    http://192.168.56.101/cgi-bin (cgi-bin scripts)     ##
##    http://192.168.56.101/hwgrading (tagrading)         ##
##                                                        ##
##  The database can be accessed on the host machine at   ##
##   localhost:15432                                      ##
##                                                        ##
##  Happy developing!                                     ##
############################################################
' > /etc/motd
    chmod +rx /etc/motd
fi

#################################################################
# PACKAGE SETUP
#################

apt-get -qq update

############################
# NTP: Network Time Protocol
# You want to be sure the clock stays in sync, especially if you have
# deadlines for homework to be submitted.
#
# The default settings are ok, but you can edit /etc/ntp.conf and
# replace the default servers with your local ntp server to reduce
# traffic through your campus uplink (To replace the default servers
# with your own, comment out the default servers by adding a # before
# the lines that begin with “server” and add your server under the
# line that says “Specify one or more NTP servers” with something
# along the lines of “server xxx.xxx.xxx.xxx”)

apt-get install -qqy ntp
service ntp restart

echo "Preparing to install packages.  This may take a while."

apt-get install -qqy libpam-passwdqc

# Set up apache to run with suphp in pre-fork mode since not all
# modules are thread safe (do not combine the commands or you may get
# the worker/threaded mode instead)

apt-get install -qqy ssh sshpass unzip
apt-get install -qqy postgresql-9.5
apt-get install -qqy apache2 apache2-suexec-custom libapache2-mod-authnz-external libapache2-mod-authz-unixgroup
apt-get install -qqy php7.0 php7.0-cli php-xdebug libapache2-mod-fastcgi php7.0-fpm php7.0-curl php7.0-pgsql php7.0-mcrypt
apt-get install -qqy php7.0-zip

# Add additional packages for compiling, authentication, and security,
# and program support

# DOCUMENTATION FIXME: Go through this list and categorize purpose of
# these packages (as appropriate.. )

apt-get install -qqy clang autoconf automake autotools-dev clisp diffstat emacs finger gdb git git-man \
hardening-includes p7zip-full patchutils \
libpq-dev unzip valgrind zip libmagic-ocaml-dev common-lisp-controller libboost-all-dev \
javascript-common  \
libfile-mmagic-perl libgnupg-interface-perl libbsd-resource-perl libarchive-zip-perl gcc g++ \
g++-multilib jq libseccomp-dev libseccomp2 seccomp junit flex bison spim poppler-utils

#CMAKE
echo "installing cmake"
apt-get install -qqy cmake

#GLEW and GLM
echo "installing graphics libraries"
apt-get install -qqy glew-utils libglew-dev libglm-dev
apt-get install -qqy libxrandr-dev xorg-dev

#CMAKE permissions
#These permissions are necessary so that untrusted user can use pkgconfig with cmake.
#Note that pkgconfig does not appear until after graphics installs (Section above)
chmod -R o+rx /usr/local/lib/pkgconfig
chmod -R o+rx /usr/local/lib/cmake

# Install Oracle 8 Non-Interactively
echo "installing java8"
apt-get install -qqy oracle-java8-installer > /dev/null 2>&1
apt-get install -qqy oracle-java8-set-default

# Install Racket and Swi-prolog for Programming Languages
echo "installing Racket and Swi-prolog"
apt-add-repository -y ppa:plt/racket  > /dev/null 2>&1
apt-get install -qqy racket > /dev/null 2>&1
apt-get install -qqy swi-prolog > /dev/null 2>&1

# Install Image Magick for image comparison, etc.
apt-get install -qqy imagemagick

# Used by Network Programming class
apt-get install -qqy libssl-dev

#GLFW
echo "installing GLFW"
wget https://github.com/glfw/glfw/releases/download/3.2.1/glfw-3.2.1.zip
unzip glfw-3.2.1.zip
cd glfw-3.2.1
mkdir build
cd build
cmake ..
make
sudo make install
cd ../..
rm -R glfw-3.2.1
rm glfw-3.2.1.zip

apt-get -qqy autoremove
