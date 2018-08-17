#include <unistd.h>
#include <cstdlib>
#include <string>
#include <iostream>
#include <cassert>

#include "TestCase.h"
#include "execute.h"

extern const char *GLOBAL_config_json_string;  // defined in json_generated.cpp

void AddAutogradingConfiguration(nlohmann::json &whole_config) {
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.cpp");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.cxx");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.c");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.h");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.hpp");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.hxx");
  whole_config["autograding"]["submission_to_compilation"].push_back("**/*.java");

  whole_config["autograding"]["submission_to_runner"].push_back("**/*.py");
  whole_config["autograding"]["submission_to_runner"].push_back("**/*.pdf");

  whole_config["autograding"]["compilation_to_runner"].push_back("**/*.out");
  whole_config["autograding"]["compilation_to_runner"].push_back("**/*.class");

  whole_config["autograding"]["compilation_to_validation"].push_back("test*.txt");

  whole_config["autograding"]["submission_to_validation"].push_back("**/README.txt");
  whole_config["autograding"]["submission_to_validation"].push_back("textbox_*.txt");
  whole_config["autograding"]["submission_to_validation"].push_back("**/*.pdf");

  whole_config["autograding"]["work_to_details"].push_back("test*/*.txt");
  whole_config["autograding"]["work_to_details"].push_back("test*/*_diff.json");
  whole_config["autograding"]["work_to_details"].push_back("**/README.txt");
  whole_config["autograding"]["work_to_details"].push_back("textbox_*.txt");
  //todo check up on how this works.
  whole_config["autograding"]["work_to_details"].push_back("test*/textbox_*.txt");

  if (whole_config["autograding"].find("use_checkout_subdirectory") == whole_config["autograding"].end()) {
    whole_config["autograding"]["use_checkout_subdirectory"] = "";
  }
}

void AddDockerConfiguration(nlohmann::json &whole_config) {
  
  if (!whole_config["docker_enabled"].is_boolean()){
    whole_config["docker_enabled"] = false;
  }


  // for each testcase{
  //   nlohmann::json this_testcase = whole_config["testcases"][i];
  //   std::vector<std::string> commands;

  //   // if "command" exists in whole_config, we must wrap it in a container.
  //   if(this_testcase["command"]){
  //     if (this_testcase["command"].is_array()){
  //       commands = this_testcase["command"];
  //     }
  //     else{
  //       commands.push_back(this_testcase["command"]);
  //     }

  //     this_testcase.remove("command");
  //   }

  //   assert (this_testcase["containers"].is_null() || commans.size() == 0);
    
  //   if(!this_testcase["containers"].is_null()){
  //     assert(this_testcase["containers"]is_structured());
  //   }

  //   if(this_testcase["containers"].is_null()){
  //     this_testcase["containers"] = json::object();
  //     //commands may have to be a json::array();
  //     this_testcase["containers"]["commands"] = commands;
  //   }

  //   assert(this_testcase["containers"]["commands"].size() > 0);

  //   if(this_testcase["containers"]["container_name"].is_null()){
  //     //pad this out correctly?
  //     this_testcase["containers"]["container_name"] = "container" + testcase_num; 
  //   }

  //   if(this_testcase["containers"]["outgoing_connections"].is_null()){
  //     this_testcase["containers"]["outgoing_connections"] = json::array();
  //   }

  //   if(this_testcase["containers"]["container_image"].is_null()){
  //     //TODO: store the default system image somewhere and fill it in here.
  //     this_testcase["containers"]["container_image"] = "ubuntu:custom";
  //   }

  //   whole_config["testcases"][i] = this_testcase;
  // }
  
}

void RewriteDeprecatedMyersDiff(nlohmann::json &whole_config) {

  nlohmann::json::iterator tc = whole_config.find("testcases");
  if (tc == whole_config.end()) { /* no testcases */ return; }

  // loop over testcases
  int which_testcase = 0;
  for (nlohmann::json::iterator my_testcase = tc->begin();
       my_testcase != tc->end(); my_testcase++,which_testcase++) {
    nlohmann::json::iterator validators = my_testcase->find("validation");
    if (validators == my_testcase->end()) { /* no autochecks */ continue; }

    // loop over autochecks
    for (int which_autocheck = 0; which_autocheck < validators->size(); which_autocheck++) {
      nlohmann::json& autocheck = (*validators)[which_autocheck];
      std::string method = autocheck.value("method","");

      // if autocheck is old myersdiff format...  rewrite it!
      if (method == "myersDiffbyLinebyChar") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLinebyChar";
      } else if (method == "myersDiffbyLinebyWord") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLinebyWord";
      } else if (method == "myersDiffbyLine") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLine";
      } else if (method == "myersDiffbyLineNoWhite") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLine";
        assert (autocheck.find("ignoreWhitespace") == autocheck.end());
        autocheck["ignoreWhitespace"] = true;
      } else if (method == "diffLineSwapOk") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLine";
        assert (autocheck.find("lineSwapOk") == autocheck.end());
        autocheck["lineSwapOk"] = true;
      }
    }
  }
}


// =====================================================================
// =====================================================================

nlohmann::json LoadAndProcessConfigJSON(const std::string &rcsid) {

  nlohmann::json answer;
  std::stringstream sstr(GLOBAL_config_json_string);
  sstr >> answer;

  AddSubmissionLimitTestCase(answer);
  AddAutogradingConfiguration(answer);
  AddDockerConfiguration(answer);

  if (rcsid != "") {
    CustomizeAutoGrading(rcsid,answer);
  }

  RewriteDeprecatedMyersDiff(answer);

  std::cout << "JSON PARSED" << std::endl;
  
  return answer;
}
