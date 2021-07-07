#include <unistd.h>
#include <set>
#include <sys/stat.h>
#include <sys/time.h>
#include <sys/wait.h>
#include <sys/types.h>
#include <sys/stat.h>
#include <unistd.h>
#include <errno.h>
#include <sys/ipc.h>
#include <sys/shm.h>
#include <unistd.h>
#include <iostream>
#include <fstream>

#include "TestCase.h"
#include "dispatch.h"
#include "myersDiff.h"
#include "tokenSearch.h"
#include "execute.h"

// Set mode bits on shared memory
#define SHM_MODE (SHM_W | SHM_R | IPC_CREAT)


std::string rlimit_name_decoder(int i);

void TerminateProcess(float &elapsed, int childPID);
int resident_set_size(int childPID);

void adjust_test_case_limits(nlohmann::json &modified_test_case_limits,
                             int rlimit_name, rlim_t value) {

  std::string rlimit_name_string = rlimit_name_decoder(rlimit_name);

  // first, see if this quantity already has a value
  nlohmann::json::iterator t_itr = modified_test_case_limits.find(rlimit_name_string);

  if (t_itr == modified_test_case_limits.end()) {
    // if it does not, add it
    modified_test_case_limits[rlimit_name_string] = value;
  } else {
    // otherwise set it to the max
    //t_itr->second = std::max(value,t_itr->second);
    if (int(value) > int(modified_test_case_limits[rlimit_name_string]))
      modified_test_case_limits[rlimit_name_string] = value;
  }
}


std::vector<std::string> stringOrArrayOfStrings(nlohmann::json j, const std::string what) {
  std::vector<std::string> answer;
  nlohmann::json::const_iterator itr = j.find(what);
  if (itr == j.end())
    return answer;
  if (itr->is_string()) {
    answer.push_back(*itr);
  } else {
    assert (itr->is_array());
    nlohmann::json::const_iterator itr2 = itr->begin();
    while (itr2 != itr->end()) {
      assert (itr2->is_string());
      answer.push_back(*itr2);
      itr2++;
    }
  }
  return answer;
}

std::vector<nlohmann::json> mapOrArrayOfMaps(nlohmann::json j, const std::string what){
  std::vector<nlohmann::json> answer;
  nlohmann::json::const_iterator itr = j.find(what);
  if (itr == j.end())
    return answer;
  if (!itr->is_array()) {
    answer.push_back(*itr);
  } else {
    assert (itr->is_array());
    nlohmann::json::const_iterator itr2 = itr->begin();
    while (itr2 != itr->end()) {
      assert (itr2->is_object());
      answer.push_back(*itr2);
      itr2++;
    }
  }
  return answer;
}

void fileStatus(const std::string &filename, bool &fileExists, bool &fileEmpty) {
  struct stat st;
  if (stat(filename.c_str(), &st) < 0) {
    // failure
    fileExists = false;
  }
  else {
    fileExists = true;
    if (st.st_size == 0) {
      fileEmpty = true;
    } else {
      fileEmpty = false;
    }
  }
}

std::string getOutputContainingFolderPath(const TestCase &tc, std::string &filename){
  struct stat st;
  std::string expectedFolder;
  std::string test_output_path = "test_output/";
  std::string generated_output_path = "generated_output/" + tc.getPrefix();
  std::string random_output_path = "random_output/" + tc.getPrefix();
  if (stat((test_output_path + filename).c_str(), &st) >= 0) {
    expectedFolder = test_output_path;
  } else if (stat((generated_output_path + filename).c_str(), &st) >= 0){
    expectedFolder = generated_output_path;
  } else if (stat((random_output_path + filename).c_str(), &st) >= 0){
    expectedFolder = random_output_path;
  }
  return expectedFolder;
}

std::string getPathForOutputFile(const TestCase &tc, std::string &filename, std::string &id){
  std::string expectedPath = getOutputContainingFolderPath(tc, filename);
  std::string requiredPath ;
  if (expectedPath.substr(0,11) == "test_output"){
    requiredPath = expectedPath + id + "/";
  } else if (expectedPath.substr(0,16) == "generated_output") {
    requiredPath = expectedPath;
  } else if (expectedPath.substr(0,13) == "random_output") {
    requiredPath = expectedPath;
  }
  return requiredPath;
}

bool getFileContents(const std::string &filename, std::string &file_contents) {
  std::ifstream file(filename);
  if (!file.good()) { return false; }
  file_contents = std::string(std::istreambuf_iterator<char>(file), std::istreambuf_iterator<char>());
  //std::cout << "file contents size = " << file_contents.size() << std::endl;
  return true;
}


bool openStudentFile(const TestCase &tc, const nlohmann::json &j, std::string &student_file_contents,
                     std::vector<std::pair<TEST_RESULTS_MESSAGE_TYPE, std::string> > &messages) {

  std::vector<std::string> filenames = stringOrArrayOfStrings(j,"actual_file");
  if (filenames.size() != 1) {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  STUDENT FILENAME MISSING"));
    return false;
  }

  std::string filename = filenames[0];
  std::string p_filename = tc.getPrefix() + filename;

  // check for wildcard
  if (p_filename.find('*') != std::string::npos) {
    std::cout << "HAS WILDCARD!  MUST EXPAND '" << p_filename << "'" << std::endl;
    std::vector<std::string> files;
    wildcard_expansion(files, p_filename, std::cout);
    if (files.size() == 0) {
      wildcard_expansion(files, filename, std::cout);
    }
    if (files.size() == 0) {
      messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  No matches to wildcard pattern"));
      return false;
    } else if (files.size() > 1) {
      messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  Multiple matches to wildcard pattern"));
      return false;
    } else {
      p_filename = files[0];
      std::cout << "FOUND MATCH" << p_filename << std::endl;
    }
  }

  if (!getFileContents(p_filename,student_file_contents)) {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  Could not open student file: '" + p_filename + "'"));
    return false;
  }
  if (student_file_contents.size() > MYERS_DIFF_MAX_FILE_SIZE_HUGE) {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  Student file '" + p_filename + "' too large for grader (" +
                                      std::to_string(student_file_contents.size()) + " vs. " +
                                      std::to_string(MYERS_DIFF_MAX_FILE_SIZE_HUGE) + ")"));
    return false;
  }
  return true;
}


bool openExpectedFile(const TestCase &tc, const nlohmann::json &j, std::string &expected_file_contents,
                      std::vector<std::pair<TEST_RESULTS_MESSAGE_TYPE, std::string> > &messages) {

  std::cout << "opening expected file..." << std::endl;
  std::string expected_file = j.value("expected_file", "");
  std::string expected_string = j.value("expected_string", "");
  std::string actual_file = j.value("actual_file", "");

  std::cout << "expected_file: " << expected_file << std::endl;
  std::cout << "expected_string: " << expected_string << std::endl;
  std::cout << "actual_file: " << expected_string << std::endl;
  assert(!(expected_file != "" && expected_string != ""));

  std::string filename;
  if (expected_file == "") {
    //if the expected file doesn't exist, write expected_string to it (but where?)
    //use actual_file name to get the name of the expected string output file
    //ex: actual_file = math_1.txt, expected = expected_string_math_1.txt
    expected_file = "expected_string_" + actual_file.substr(actual_file.find('/')+1);
    filename = "test_output/" + expected_file;
    std::ofstream expected_file_stream(filename);
    expected_file_stream << expected_string;
    expected_file_stream.close();
  } else {
    filename = getOutputContainingFolderPath(tc, expected_file) + expected_file;
  }

  if (filename == "") {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  EXPECTED FILENAME MISSING"));
    return false;
  }
  if (!getFileContents(filename,expected_file_contents)) {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  Could not open expected file: '" + filename));
    return false;
  }
  if (expected_file_contents.size() > MYERS_DIFF_MAX_FILE_SIZE_HUGE) {
    messages.push_back(std::make_pair(MESSAGE_FAILURE,"ERROR!  Expected file '" + filename + "' too large for grader (" +
                                      std::to_string(expected_file_contents.size()) + " vs. " +
                                      std::to_string(MYERS_DIFF_MAX_FILE_SIZE_HUGE) + ")"));
    return false;
  }
  return true;
}

// =================================================================================
// =================================================================================

TestResults* TestCase::dispatch(const nlohmann::json& grader, int autocheck_number, const nlohmann::json whole_config, const std::string& username) const {
  std::string method = grader.value("method","");
  if      (method == "")                           { return NULL;                                                       }
  else if (method == "JUnitTestGrader")            { return dispatch::JUnitTestGrader_doit(*this,grader);               }
  else if (method == "MultipleJUnitTestGrader")    { return dispatch::MultipleJUnitTestGrader_doit(*this,grader);       }
  else if (method == "JaCoCoCoverageReportGrader") { return dispatch::JaCoCoCoverageReportGrader_doit(*this,grader);    }
  else if (method == "DrMemoryGrader")             { return dispatch::DrMemoryGrader_doit(*this,grader);                }
  else if (method == "PacmanGrader")               { return dispatch::PacmanGrader_doit(*this,grader);                  }
  else if (method == "searchToken")                { return dispatch::searchToken_doit(*this,grader);                   }
  else if (method == "intComparison")              { return dispatch::intComparison_doit(*this,grader);                 }
  else if (method == "diff")                       { return dispatch::diff_doit(*this,grader);                          }
  else if (method == "fileExists")                 { return dispatch::fileExists_doit(*this,grader);                    }
  else if (method == "warnIfNotEmpty")             { return dispatch::warnIfNotEmpty_doit(*this,grader);                }
  else if (method == "warnIfEmpty")                { return dispatch::warnIfEmpty_doit(*this,grader);                   }
  else if (method == "errorIfNotEmpty")            { return dispatch::errorIfNotEmpty_doit(*this,grader);               }
  else if (method == "errorIfEmpty")               { return dispatch::errorIfEmpty_doit(*this,grader);                  }
  else if (method == "ImageDiff")                  { return dispatch::ImageDiff_doit(*this,grader, autocheck_number);   }
  else if (method == "custom_validator")           { return dispatch::custom_doit(*this,grader,whole_config, username, autocheck_number); }
  else                                             { return custom_dispatch(grader);                                    }
}

/*
  Find the testcase within whole_config which has testcase_id == which_testcase_id and return it
  Checks both the global testcase array and any testcases within an itempool.
  This function assumes that the student calling it has been assigned which_testcase_id.
*/
nlohmann::json find_testcase_by_id(nlohmann::json &whole_config, std::string which_testcase_id) {

  // First, check the global testcase array
  nlohmann::json::iterator testcases = whole_config.find("testcases");
  if(testcases != whole_config.end()) {
    for (nlohmann::json::iterator my_testcase = testcases->begin(); my_testcase != testcases->end(); my_testcase++) {
      if(my_testcase->value("testcase_id", "") == which_testcase_id) {
        return *my_testcase;
      }
    }
  }

  // If we didn't find the testcase in the global array, it might be in the item_pool.
  nlohmann::json::iterator itempool = whole_config.find("item_pool");
  if(testcases != whole_config.end()) {
    //Each item in the itempool has zero or more testcases
    for (nlohmann::json::iterator item = itempool->begin(); item != itempool->end(); item++) {
      nlohmann::json::iterator itempool_testcases = item->find("testcases");
      // Some items might have no testcases.
      if(itempool_testcases != item->end()) {
        // Once we've found an item with testcases, iterate across them to see if they have the id we're looking for
        for(nlohmann::json::iterator itempool_testcase = itempool_testcases->begin(); itempool_testcase != itempool_testcases->end(); itempool_testcase++) {
          if((*itempool_testcase)["testcase_id"] == which_testcase_id) {
            return *itempool_testcase;
          }
        }
      }
    }
  }

  // If we could not find the testcase, something has gone wrong, so raise an error.
  throw "ERROR: could not find testcase_id " + which_testcase_id;
}


// =================================================================================
// =================================================================================
// CONSTRUCTOR
TestCase::TestCase(nlohmann::json my_json, std::string test_case_id, std::string container_name) :
  _json(my_json), test_case_id(test_case_id), CONTAINER_NAME(container_name){
}

std::vector<std::string> TestCase::getCommands() const {

  //TODO potential point of failure
  std::vector<nlohmann::json> containers = mapOrArrayOfMaps(this->_json, "containers");

  assert(containers.size() > 0);

  if (this->CONTAINER_NAME == ""){
    //TODO add back in if possible.
    //assert(containers.size() == 1);
    return stringOrArrayOfStrings(containers[0], "commands");
  }

  bool found = false;
  nlohmann::json command_map;
  //If we ARE running in a docker container, we must find the commands that are bound for us.
  for(std::vector<nlohmann::json>::const_iterator it = containers.begin(); it != containers.end(); ++it) {
    nlohmann::json::const_iterator val = it->find("container_name");
    std::string curr_target = *val;
    if(curr_target == this->CONTAINER_NAME){
      found = true;
      command_map = *it;
      break;
    }
  }

  if(!found){
    std::cout << "ERROR: Could not find " << this->CONTAINER_NAME << " in the command map." << std::endl;
    std::vector<std::string> empty;
    return empty;
  }

  return stringOrArrayOfStrings(command_map, "commands");
}


std::vector<std::string> TestCase::getSolutionCommands() const {

  //TODO potential point of failure
  std::vector<nlohmann::json> containers = mapOrArrayOfMaps(this->_json, "solution_containers");

  assert(containers.size() > 0);

  if (this->CONTAINER_NAME == ""){
    //TODO add back in if possible.
    //assert(containers.size() == 1);
    return stringOrArrayOfStrings(containers[0], "commands");
  }

  bool found = false;
  nlohmann::json command_map;
  //If we ARE running in a docker container, we must find the commands that are bound for us.
  for(std::vector<nlohmann::json>::const_iterator it = containers.begin(); it != containers.end(); ++it) {
    nlohmann::json::const_iterator val = it->find("container_name");
    std::string curr_target = *val;
    if(curr_target == this->CONTAINER_NAME){
      found = true;
      command_map = *it;
      break;
    }
  }

  if(!found){
    std::cout << "ERROR: Could not find " << this->CONTAINER_NAME << " in the command map." << std::endl;
    std::vector<std::string> empty;
    return empty;
  }

  return stringOrArrayOfStrings(command_map, "commands");
}

// =================================================================================
// =================================================================================
// ACCESSORS

std::vector <std::string> TestCase::getInputGeneratorCommands() const {
    std::vector <std::string> commands = stringOrArrayOfStrings(_json, "input_generation_commands");
    return commands;
}

std::string TestCase::getTitle() const {
  const nlohmann::json::const_iterator& itr = _json.find("title");
  if (itr == _json.end()) {
    std::cerr << "ERROR! MISSING TITLE" << std::endl;
  }
  assert (itr->is_string());
  return (*itr);
}

std::string TestCase::getTestcaseLabel() const {

  // Get testcase_label
  std::string testcase_label = _json.value("testcase_label", "");

  return testcase_label;
}

std::string TestCase::getPrefix() const {
  //TODO: Remove hardcoded /
  return test_case_id + "/";
}


std::vector<std::vector<std::string>> TestCase::getFilenames() const {
  //std::cout << "getfilenames of " << _json << std::endl;
  std::vector<std::vector<std::string>> filenames;

  assert (_json.find("actual_file") == _json.end());
  int num = numFileGraders();
  assert (num > 0);
  for (int v = 0; v < num; v++) {
    filenames.push_back(stringOrArrayOfStrings(getGrader(v),"actual_file"));


    assert (filenames[v].size() > 0);
  }

  return filenames;
}



const nlohmann::json TestCase::get_test_case_limits() const {
  nlohmann::json _test_case_limits = _json.value("resource_limits", nlohmann::json());

  if (isCompilation()) {
    // compilation (g++, clang++, javac) usually requires multiple
    // threads && produces a large executable

    // Over multiple semesters of Data Structures C++ assignments, the
    // maximum number of vfork (or fork or clone) system calls needed
    // to compile a student submissions was 28.
    //
    // It seems that g++     uses approximately 2 * (# of .cpp files + 1) processes
    // It seems that clang++ uses approximately 2 +  # of .cpp files      processes

    adjust_test_case_limits(_test_case_limits,RLIMIT_NPROC,100);

    // 10 seconds was sufficient time to compile most Data Structures
    // homeworks, but some submissions required slightly more time
    adjust_test_case_limits(_test_case_limits,RLIMIT_CPU,60);              // 60 seconds
    adjust_test_case_limits(_test_case_limits,RLIMIT_FSIZE,10*1000*1000);  // 10 MB executable

    adjust_test_case_limits(_test_case_limits,RLIMIT_RSS,1000*1000*1000);  // 1 GB
  }

  if (isSubmittyCount()) {
    // necessary for the analysis tools count program
    adjust_test_case_limits(_test_case_limits,RLIMIT_NPROC,1000);
    adjust_test_case_limits(_test_case_limits,RLIMIT_NOFILE,1000);
    adjust_test_case_limits(_test_case_limits,RLIMIT_CPU,60);
    adjust_test_case_limits(_test_case_limits,RLIMIT_AS,RLIM_INFINITY);
    adjust_test_case_limits(_test_case_limits,RLIMIT_SIGPENDING,100);
  }

  return _test_case_limits;
}

bool TestCase::ShowExecuteLogfile(const std::string &execute_logfile) const {
  for (int i = 0; i < numFileGraders(); i++) {
    const nlohmann::json& grader = getGrader(i);
    nlohmann::json::const_iterator a = grader.find("actual_file");
    if (a != grader.end()) {
      if (*a == execute_logfile) {
        nlohmann::json::const_iterator s = grader.find("show_actual");
        if (s != grader.end()) {
          if (*s == "never") return false;
        }
      }
    }
  }
  return true;
}

// =================================================================================
// =================================================================================


TestResultsFixedSize TestCase::do_the_grading (int j, nlohmann::json complete_config, const std::string& username) const {

  // ALLOCATE SHARED MEMORY
  int memid;
  TestResultsFixedSize *tr_ptr;
  if ((memid = shmget(IPC_PRIVATE,sizeof(TestResultsFixedSize),SHM_MODE)) == -1) {
    std::cout << "Unsuccessful memory get" << std::endl;
    std::cout << "Errno was " << errno << std::endl;
    exit(-1);
  }


  // FORK A CHILD THREAD TO DO THE VALIDATION
  pid_t childPID = fork();
  // ensure fork was successful
  assert (childPID >= 0);


  if (childPID == 0) {
    // CHILD

    // attach to shared memory
    tr_ptr = (TestResultsFixedSize*) shmat(memid,0 ,0);
    tr_ptr->initialize();

    // perform the validation (this might hang or crash)
    assert (j >= 0 && j < numFileGraders());
    nlohmann::json tcg = getGrader(j);
    TestResults* answer_ptr = this->dispatch(tcg, j, complete_config, username);
    assert (answer_ptr != NULL);

    // write answer to shared memory and terminate this process
    answer_ptr->PACK(tr_ptr);
    //std::cout << "do_the_grading, child completed successfully " << std::endl;
    exit(0);

  } else {
    // PARENT

    // attach to shared memory
    tr_ptr = (TestResultsFixedSize *)  shmat(memid,0 ,0);

    bool time_kill=false;
    bool memory_kill=false;
    pid_t wpid = 0;
    int status;
    float elapsed = 0;
    float next_checkpoint = 0;
    int rss_memory = 0;
    int seconds_to_run = 20;
    int allowed_rss_memory = 1000000;

    // loop while waiting for child to finish
    do {
      wpid = waitpid(childPID, &status, WNOHANG);
      if (wpid == 0) {
        // terminate for excessive time
        if (elapsed > seconds_to_run) {
          std::cout << "do_the_grading error:  Killing child process " << childPID
                    << " after " << elapsed << " seconds elapsed." << std::endl;
          TerminateProcess(elapsed,childPID);
          time_kill=true;
        }
        // terminate for excessive memory usage (RSS = resident set size = RAM)
        if (rss_memory > allowed_rss_memory) {
          std::cout << "do_the_grading error:  Killing child process " << childPID
                    << " for using " << rss_memory << " kb RAM.  (limit is " << allowed_rss_memory << " kb)" << std::endl;
          TerminateProcess(elapsed,childPID);
          memory_kill=true;
        }
        // monitor time & memory usage
        if (!time_kill && !memory_kill) {
          // sleep 1/10 of a second
          usleep(100000);
          elapsed+= 0.1;
        }
        if (elapsed >= next_checkpoint) {
          rss_memory = resident_set_size(childPID);
          //std::cout << "do_the_grading running, time elapsed = " << elapsed
          //          << " seconds,  memory used = " << rss_memory << " kb" << std::endl;
          next_checkpoint = std::min(elapsed+5.0,elapsed*2.0);
        }
      }
    } while (wpid == 0);
  }

  // COPY result from shared memory
  TestResultsFixedSize answer = *tr_ptr;

  // detach shared memory and destroy the memory queue
  shmdt((void *)tr_ptr);
  if (shmctl(memid,IPC_RMID,0) < 0) {
    std::cout << "Problems destroying shared memory ID" << std::endl;
    std::cout << "Errno was " <<  errno << std::endl;
    exit(-1);
  }

  std::cout << "do the grading complete: " << answer << std::endl;
  return answer;
}



std::string getAssignmentIdFromCurrentDirectory(std::string dir) {
  //std::cout << "getassignmentidfromcurrentdirectory '" << dir << "'\n";
  assert (dir.size() >= 1);
  assert (dir[dir.size()-1] != '/');

  int last_slash = -1;
  int second_to_last_slash = -1;
  std::string tmp;
  while (1) {
    int loc = dir.find('/',last_slash+1);
    if (loc == std::string::npos) break;
    second_to_last_slash = last_slash;
    last_slash = loc;
    if (second_to_last_slash != -1) {
      tmp = dir.substr(second_to_last_slash+1,last_slash-second_to_last_slash-1);
    }
    //std::cout << "tmp is now '" << tmp << "'\n";
  }
  assert (tmp.size() >= 1);
  return tmp;
}
