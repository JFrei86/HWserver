echo "Setting up auto-grader test suite"

SUBMITTY_INSTALL_DIR=/usr/local/submitty
SUBMITTY_DATA_DIR=/var/local/submitty

apt-get install -yqq --force-yes python automake cmake make clang gcc g++ g++-multilib libseccomp2 seccomp libseccomp-dev valgrind

mkdir -p ${SUBMITTY_INSTALL_DIR}
mkdir -p ${SUBMITTY_DATA_DIR}
mkdir -p ${SUBMITTY_INSTALL_DIR}/src
mkdir -p ${SUBMITTY_INSTALL_DIR}/test_suite
mkdir -p ${SUBMITTY_INSTALL_DIR}/test_suite/log
cp -r tests/. ${SUBMITTY_INSTALL_DIR}/test_suite
cp -r sample_files ${SUBMITTY_INSTALL_DIR}/sample_files
cp -r grading/ ${SUBMITTY_INSTALL_DIR}/src/

sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_INSTALL_DIR__|${SUBMITTY_INSTALL_DIR}|g" ${SUBMITTY_INSTALL_DIR}/test_suite/integrationTests/lib.py
sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_INSTALL_DIR__|${SUBMITTY_INSTALL_DIR}|g" ${SUBMITTY_INSTALL_DIR}/src/grading/system_call_check.cpp
sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_INSTALL_DIR__|${SUBMITTY_INSTALL_DIR}|g" ${SUBMITTY_INSTALL_DIR}/src/grading/Sample_CMakeLists.txt
sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_DATA_DIR__|${SUBMITTY_DATA_DIR}|g" ${SUBMITTY_INSTALL_DIR}/src/grading/Sample_CMakeLists.txt
sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_INSTALL_DIR__|${SUBMITTY_INSTALL_DIR}|g" ${SUBMITTY_INSTALL_DIR}/src/grading/CMakeLists.txt
sed -i -e "s|__INSTALL__FILLIN__SUBMITTY_DATA_DIR__|${SUBMITTY_DATA_DIR}|g" ${SUBMITTY_INSTALL_DIR}/src/grading/CMakeLists.txt

# building the autograding library
mkdir -p ${SUBMITTY_INSTALL_DIR}/src/grading/lib
pushd ${SUBMITTY_INSTALL_DIR}/src/grading/lib
cmake ..
make
popd

chmod -R 777 ${SUBMITTY_INSTALL_DIR}

echo "Getting DrMemory..."
mkdir -p ${SUBMITTY_INSTALL_DIR}/DrMemory
pushd /tmp
DRMEM_TAG=release_1.10.1
DRMEM_VER=1.10.1-3
wget https://github.com/DynamoRIO/drmemory/releases/download/${DRMEM_TAG}/DrMemory-Linux-${DRMEM_VER}.tar.gz -o /dev/null > /dev/null 2>&1
tar -xpzf DrMemory-Linux-${DRMEM_VER}.tar.gz -C ${SUBMITTY_INSTALL_DIR}/DrMemory
ln -s ${SUBMITTY_INSTALL_DIR}/DrMemory/DrMemory-Linux-${DRMEM_VER} ${SUBMITTY_INSTALL_DIR}/drmemory
rm DrMemory-Linux-${DRMEM_VER}.tar.gz
popd


echo "Getting JUnit..."
mkdir -p ${SUBMITTY_INSTALL_DIR}/JUnit
cd ${SUBMITTY_INSTALL_DIR}/JUnit

wget http://search.maven.org/remotecontent?filepath=junit/junit/4.12/junit-4.12.jar -o /dev/null > /dev/null 2>&1
mv remotecontent?filepath=junit%2Fjunit%2F4.12%2Fjunit-4.12.jar junit-4.12.jar
wget http://search.maven.org/remotecontent?filepath=org/hamcrest/hamcrest-core/1.3/hamcrest-core-1.3.jar -o /dev/null > /dev/null 2>&1
mv remotecontent?filepath=org%2Fhamcrest%2Fhamcrest-core%2F1.3%2Fhamcrest-core-1.3.jar hamcrest-core-1.3.jar

# EMMA is a tool for computing code coverage of Java programs

echo "Getting emma..."
wget http://downloads.sourceforge.net/project/emma/emma-release/2.0.5312/emma-2.0.5312.zip -o /dev/null > /dev/null 2>&1
unzip emma-2.0.5312.zip > /dev/null
mv emma-2.0.5312/lib/emma.jar emma.jar
rm -rf emma-2.0.5312
rm emma-2.0.5312.zip
rm index.html* > /dev/null 2>&1

chmod o+r . *.jar

cd ${SUBMITTY_INSTALL_DIR}
cd ${SUBMITTY_INSTALL_DIR}/JUnit
