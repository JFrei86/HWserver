#include <unistd.h>
#include <cstdlib>
#include <string>
#include <iostream>
#include <cassert>
#include <algorithm>
#include <ctype.h>


#include "TestCase.h"
#include "load_config_json.h"
#include "execute.h"

extern const char *GLOBAL_config_json_string;  // defined in json_generated.cpp

void AddAutogradingConfiguration(nlohmann::json &whole_config) {

  std::vector<std::string> all_testcase_ids = gatherAllTestcaseIds(whole_config);

  if (whole_config["autograding"].find("submission_to_compilation") == whole_config["autograding"].end()) {
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.cpp");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.cxx");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.c");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.h");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.hpp");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.hxx");
    whole_config["autograding"]["submission_to_compilation"].push_back("**/*.java");
  }

  if (whole_config["autograding"].find("submission_to_runner") == whole_config["autograding"].end()) {
    whole_config["autograding"]["submission_to_runner"].push_back("**/*.py");
    whole_config["autograding"]["submission_to_runner"].push_back("**/*.pdf");
  }

  if (whole_config["autograding"].find("compilation_to_runner") == whole_config["autograding"].end()) {
    whole_config["autograding"]["compilation_to_runner"].push_back("**/*.out");
    whole_config["autograding"]["compilation_to_runner"].push_back("**/*.class");
  }

  if (whole_config["autograding"].find("compilation_to_validation") == whole_config["autograding"].end()) {
    for(int i = 0; i < all_testcase_ids.size(); i++) {
      whole_config["autograding"]["compilation_to_validation"].push_back(all_testcase_ids[i] + "/STDOUT*.txt");
      whole_config["autograding"]["compilation_to_validation"].push_back(all_testcase_ids[i] + "/STDERR*.txt");
    }
  }

  if (whole_config["autograding"].find("submission_to_validation") == whole_config["autograding"].end()) {
    whole_config["autograding"]["submission_to_validation"].push_back("**/README.txt");
    whole_config["autograding"]["submission_to_validation"].push_back("**/*.pdf");
    whole_config["autograding"]["submission_to_validation"].push_back(".user_assigment_access.json");
  }

  if (whole_config["autograding"].find("work_to_details") == whole_config["autograding"].end()) {
    for(int i = 0; i < all_testcase_ids.size(); i++) {
      whole_config["autograding"]["work_to_details"].push_back(all_testcase_ids[i] + "/*.txt");
      whole_config["autograding"]["work_to_details"].push_back(all_testcase_ids[i] + "/*_diff.json");
    }
    whole_config["autograding"]["work_to_details"].push_back("**/README.txt");
    whole_config["autograding"]["work_to_details"].push_back("input_*.txt");
    // archive the timestamped dispatcher actions
    whole_config["autograding"]["work_to_details"].push_back("**/dispatched_actions.txt");
  }

  if (whole_config["autograding"].find("use_checkout_subdirectory") == whole_config["autograding"].end()) {
    whole_config["autograding"]["use_checkout_subdirectory"] = "";
  }
}

/**
* Add global booleans and configuration options to the configuration
**/
void AddGlobalDefaults(nlohmann::json &whole_config) {

  /*************************************************
  * Add Global Booleans
  **************************************************/
  if (!whole_config["timestamped_stdout"].is_boolean()) {
    whole_config["timestamped_stdout"] = false;
  }

  if (!whole_config["publish_actions"].is_boolean()) {
    whole_config["publish_actions"] = false;
  }

  /*************************************************
  * Global Docker Configuration
  **************************************************/

  if (!whole_config["autograding_method"].is_string()) {
    whole_config["autograding_method"] = "jailed_sandbox";
  }else{
    assert(whole_config["autograding_method"] == "docker"
        || whole_config["autograding_method"]  == "jailed_sandbox");
  }

  //check if there are global container defaults present. If not, add an empty object.
  if(whole_config["container_options"].is_null()){
    whole_config["container_options"] = nlohmann::json::object();
  }

  //Fill in the defaults.
  if(!whole_config["container_options"]["container_image"].is_string()){
    whole_config["container_options"]["container_image"] = "submitty/autograding-default:latest";
  }

  if (!whole_config["container_options"]["use_router"].is_boolean()){
    whole_config["container_options"]["use_router"] = false;
  }

  if (!whole_config["container_options"]["single_port_per_container"].is_boolean()){
    whole_config["container_options"]["single_port_per_container"] = false; // connection
  }

  if (!whole_config["container_options"]["number_of_ports"].is_number_integer()){
    whole_config["container_options"]["number_of_ports"] = 1; // connection
  }

  /*************************************************
  * Defaults previously found in main_configure
  **************************************************/

  if (whole_config.find("assignment_message") != whole_config.end()) {
    whole_config["gradeable_message"] = whole_config.value("assignment_message","");
    whole_config.erase("assignment_message");
  }
  whole_config["gradeable_message"] = whole_config.value("gradeable_message", "");

  whole_config["load_gradeable_message"] = whole_config.value("load_gradeable_message", nlohmann::json::object());
  whole_config["load_gradeable_message"]["message"] = whole_config["load_gradeable_message"].value("message", "");
  whole_config["load_gradeable_message"]["first_time_only"] = whole_config["load_gradeable_message"].value("first_time_only", false);

  whole_config["early_submission_incentive"] = whole_config.value("early_submission_incentive", nlohmann::json::object());
  whole_config["early_submission_incentive"]["message"] = whole_config["early_submission_incentive"].value("message", "");
  whole_config["early_submission_incentive"]["minimum_days_early"] = whole_config["early_submission_incentive"].value("minimum_days_early", 0);
  whole_config["early_submission_incentive"]["minimum_points"] = whole_config["early_submission_incentive"].value("minimum_points", 0);
  whole_config["early_submission_incentive"]["test_cases"] = whole_config["early_submission_incentive"].value("test_cases", std::vector<int>());

  whole_config["max_submission_size"] = whole_config.value("max_submission_size",MAX_SUBMISSION_SIZE);

  whole_config["required_capabilities"] = whole_config.value("required_capabilities","default");

  // Configure defaults for hide_submitted_files
  whole_config["hide_submitted_files"] = whole_config.value("hide_submitted_files", false);

  // Configure defaults for hide_version_and_test_details
  whole_config["hide_version_and_test_details"] = whole_config.value("hide_version_and_test_details", false);

  // By default, we have one drop zone without a part label / sub
  // directory.
  nlohmann::json::iterator parts = whole_config.find("part_names");
  if (parts != whole_config.end()) {
    nlohmann::json tmp =  nlohmann::json::array();
    for (int i = 0; i < parts->size(); i++) {
      tmp.push_back((*parts)[i]);
    }
    whole_config["part_names"] = tmp;
  }

  // But, if there are input fields, but there are no explicit parts
  // (drag & drop zones / "bucket"s for file upload), set part_names
  // to an empty array (no zones for file drag & drop).

  nlohmann::json::iterator in_notebook_cells = whole_config.find("notebook");
  if (parts == whole_config.end() && in_notebook_cells != whole_config.end()) {
    whole_config["part_names"] =  nlohmann::json::array();
  }
}

void ComputeGlobalValues(nlohmann::json &whole_config, const std::string& assignment_id) {

  whole_config["id"] = assignment_id;
  int total_nonec = 0;
  int total_ec = 0;

  int visible = 0;
  nlohmann::json::iterator testcases = whole_config.find("testcases");

  assert (testcases != whole_config.end());

  int max_submissions = MAX_NUM_SUBMISSIONS;
  int total_time = 0;

  nlohmann::json::iterator rl = whole_config.find("resource_limits");

  for (typename nlohmann::json::iterator itr = testcases->begin(); itr != testcases->end(); itr++) {
    int points = itr->value("points",0);
    bool extra_credit = itr->value("extra_credit",false);
    bool hidden = itr->value("hidden",false);
    std::string test_id = itr->value("testcase_id","");
    assert(test_id != "");

    if (points > 0) {
      if (!extra_credit)
        total_nonec += points;
      else
        total_ec += points;
      if (!hidden)
        visible += points;
    }
    //container name only matters if we try to get the commands for this testcase.
    std::string container_name = "";
    nlohmann::json my_testcase_json = *itr;
    TestCase my_testcase(my_testcase_json, test_id, container_name);
    if (my_testcase.isSubmissionLimit()) {
      max_submissions = my_testcase.getMaxSubmissions();
    }
  }
  // DEPRECATED: Because of the addition of itempool grading, this should be computed in the shipper.
  whole_config["max_possible_grading_time"] = 0;

  //TODO: This will break down for itempool grading.
  nlohmann::json grading_parameters = whole_config.value("grading_parameters",nlohmann::json::object());
  int AUTO_POINTS         = grading_parameters.value("AUTO_POINTS",total_nonec);
  int EXTRA_CREDIT_POINTS = grading_parameters.value("EXTRA_CREDIT_POINTS",total_ec);
  int TA_POINTS           = grading_parameters.value("TA_POINTS",0);
  int TOTAL_POINTS        = grading_parameters.value("TOTAL_POINTS",AUTO_POINTS+TA_POINTS);

  std::string start_red_text = "\033[1;31m";
  std::string end_red_text   = "\033[0m";
  if (total_nonec != AUTO_POINTS) {
    std::cout << "\n" << start_red_text << "ERROR: Automated Points do not match testcases." << total_nonec
        << "!=" << AUTO_POINTS << end_red_text << "\n" << std::endl;
    throw "ERROR: Automated Points do not match testcases";
  }

  if (total_ec != EXTRA_CREDIT_POINTS) {
    std::cout << "\n" << start_red_text << "ERROR: Extra Credit Points do not match testcases." << total_ec
        << "!=" << EXTRA_CREDIT_POINTS << end_red_text << "\n" << std::endl;
    throw "ERROR: Extra Credit Points do not match testcases.";
  }

  if (total_nonec + TA_POINTS != TOTAL_POINTS) {
    std::cout << "\n" << start_red_text << "ERROR: Automated Points and TA Points do not match total."
        << end_red_text << "\n" << std::endl;
    throw "ERROR: Automated Points and TA Points do not match total.";
  }




  whole_config["max_submissions"] = max_submissions;
  whole_config["auto_pts"] = AUTO_POINTS;
  whole_config["points_visible"] = visible;
  whole_config["ta_pts"] = TA_POINTS;
  whole_config["total_pts"] = TOTAL_POINTS;
}

// This function ensures that any file explicitly tagged as an
// executable in a compilation step is validated and copied
// to each testcase directory.
void PreserveCompiledFiles(nlohmann::json& testcases, nlohmann::json &whole_config) {

  // Loop over all of the test cases
  int which_testcase = 0;
  for (nlohmann::json::iterator my_testcase = testcases.begin();
       my_testcase != testcases.end(); my_testcase++,which_testcase++) {

    std::string testcase_type = my_testcase->value("type","Execution");
    std::string test_id = (*my_testcase)["testcase_id"];
    //Skip non compilation tests
    if(testcase_type != "Compilation"){
      continue;
    }

    std::vector<std::string> executable_names = stringOrArrayOfStrings(*my_testcase,"executable_name");
    // Add all executables to compilation_to_runner and compilation_to_validation
    for(std::vector<std::string>::iterator exe = executable_names.begin(); exe != executable_names.end(); exe++){
      std::string executable_name = test_id + "/" + *exe;

      if(whole_config["autograding"]["compilation_to_runner"].find("executable_name") == whole_config["autograding"]["compilation_to_runner"].end()){
        whole_config["autograding"]["compilation_to_runner"].push_back(executable_name);
      }

      if(whole_config["autograding"]["compilation_to_validation"].find("executable_name") == whole_config["autograding"]["compilation_to_validation"].end()){
         whole_config["autograding"]["compilation_to_validation"].push_back(executable_name);
      }
    }
  }
}

// This function will automatically archive all non-executable files
// that are validated.  This ensures that the web viewers will have
// the necessary files to display the results to students and graders.
void ArchiveValidatedFiles(nlohmann::json &testcases, nlohmann::json &whole_config) {

  // FIRST, loop over all of the test cases
  int which_testcase = 0;
  for (nlohmann::json::iterator my_testcase = testcases.begin();
       my_testcase != testcases.end(); my_testcase++,which_testcase++) {

    std::string test_id = (*my_testcase)["testcase_id"];
    nlohmann::json::iterator validators = my_testcase->find("validation");
    if (validators == my_testcase->end()) { /* no autochecks */ continue; }
    std::vector<std::string> executable_names = stringOrArrayOfStrings(*my_testcase,"executable_name");

    // SECOND loop over all of the autocheck validations
    for (int which_autocheck = 0; which_autocheck < validators->size(); which_autocheck++) {
      nlohmann::json& autocheck = (*validators)[which_autocheck];
      std::string method = autocheck.value("method","");

      // IF the autocheck has a file to compare (and it's not an executable)...
      if (autocheck.find("actual_file") == autocheck.end()) continue;

      std::vector<std::string> actual_filenames = stringOrArrayOfStrings(autocheck,"actual_file");
      for (int i = 0; i < actual_filenames.size(); i++) {
        std::string actual_file = actual_filenames[i];

        // skip the executables
        bool skip = false;
        for (int j = 0; j < executable_names.size(); j++) {
          if (executable_names[j] == actual_file) { skip = true; continue; }
        }
        if (skip) { continue; }

        // THEN add each actual file to the list of files to archive
        actual_file = test_id + "/" + actual_file;
        whole_config["autograding"]["work_to_details"].push_back(actual_file);
      }
    }
  }
}

void AddDockerConfiguration(nlohmann::json &testcases, nlohmann::json &whole_config) {

  int testcase_num = 0;
  for (typename nlohmann::json::iterator itr = testcases.begin(); itr != testcases.end(); itr++,testcase_num++) {
    std::string title = (*itr).value("title","BAD_TITLE");

    if (!(*itr)["use_router"].is_boolean()){
      (*itr)["use_router"] = whole_config["container_options"]["use_router"];
    }

    if (!(*itr)["single_port_per_container"].is_boolean()){
      (*itr)["single_port_per_container"] = whole_config["container_options"]["single_port_per_container"];
    }

    assert(!((*itr)["single_port_per_container"] && (*itr)["use_router"]));


    // if "command" or "solution_commands" exists in whole_config, we must wrap it in a container.
    for(std::pair<std::string, std::string> option : std::vector<std::pair<std::string, std::string>>{
                                                       std::pair<std::string, std::string>{"command", "containers"},
                                                       std::pair<std::string, std::string>{"solution_commands", "solution_containers"}
                                                     }){
      nlohmann::json commands = nlohmann::json::array();
      std::string command_type = option.first;
      std::string container_type = option.second;
      bool found_commands = false;
      if((*itr).find(command_type) != (*itr).end()){
        found_commands = true;
        if ((*itr)[command_type].is_array()){
          commands = (*itr)[command_type];
        }
        else{
          commands.push_back((*itr)[command_type]);
        }

        (*itr).erase(command_type);
      }

      assert ((*itr)[container_type].is_null() || !found_commands);

      if(!(*itr)[container_type].is_null()){
        assert((*itr)[container_type].is_array());
      }else{
        (*itr)[container_type] = nlohmann::json::array();
        //commands may have to be a json::array();
        (*itr)[container_type][0] = nlohmann::json::object();
        (*itr)[container_type][0]["commands"] = commands;
      }

      //get the testcase type
      std::string testcase_type = (*itr).value("type","Execution");
      if (testcase_type == "Compilation"){
        assert((*itr)[container_type].size() == 1);
      }

      if((*itr)[container_type].size() > 1){
        assert(whole_config["autograding_method"] == "docker");
      }

      int router_container = -1;
      bool found_non_server = false;

      for (int container_num = 0; container_num < (*itr)[container_type].size(); container_num++){
        if((*itr)[container_type][container_num]["commands"].is_string()){
          std::string this_command = (*itr)[container_type][container_num].value("commands", "");
          (*itr)[container_type][container_num].erase("commands");
          (*itr)[container_type][container_num]["commands"] = nlohmann::json::array();
          (*itr)[container_type][container_num]["commands"].push_back(this_command);
        }

        if(!(*itr)[container_type][container_num]["commands"].is_array()){
          (*itr)[container_type][container_num]["commands"] = nlohmann::json::array();
        }

        if((*itr)[container_type][container_num]["container_name"].is_null()){
          (*itr)[container_type][container_num]["container_name"] = "container" + std::to_string(container_num);
        }

        if (!(*itr)[container_type][container_num]["number_of_ports"].is_number_integer()){
          (*itr)[container_type][container_num]["number_of_ports"] = whole_config["container_options"]["number_of_ports"];
        }

        if ((*itr)[container_type][container_num]["container_name"] == "router"){
          assert(router_container == -1);
          router_container = container_num;
        }

        if(!(*itr)[container_type][container_num]["server"].is_boolean()){
          (*itr)[container_type][container_num]["server"] = false;
        }

        if((*itr)[container_type][container_num]["server"] == true){
          assert((*itr)[container_type][container_num]["commands"].size() == 0);
        }else{
          found_non_server = true;
        }

        std::string container_name = (*itr)[container_type][container_num]["container_name"];

        assert(std::find_if(container_name.begin(), container_name.end(), isspace) == container_name.end());

        if((*itr)[container_type][container_num]["outgoing_connections"].is_null()){
          (*itr)[container_type][container_num]["outgoing_connections"] = nlohmann::json::array();
        }

        if((*itr)[container_type][container_num]["container_image"].is_null()){
          //TODO: store the default system image somewhere and fill it in here.
          (*itr)[container_type][container_num]["container_image"] = whole_config["container_options"]["container_image"];
        }
      }

      if((*itr)[container_type].size() <= 2 && (*itr)["use_router"]){
        // If there are only two containers specified, make sure that neither is the router.
        // The router MUST go between at least two containers.
        assert(router_container == -1);
      }

      // If we are using the router, and it isn't specified, and this testcase is a valid candidate for a router (2 or more containers specified).
      if((*itr)["use_router"] && router_container == -1 && (*itr)[container_type].size() > 1){
        nlohmann::json insert_router = nlohmann::json::object();
        insert_router["outgoing_connections"] = nlohmann::json::array();
        insert_router["commands"] = nlohmann::json::array();
        insert_router["commands"].push_back("python3 -u submitty_router.py");
        insert_router["container_name"] = "router";
        insert_router["import_default_router"] = true;
        insert_router["container_image"] = "submitty/autograding-default:latest";
        insert_router["server"] = false;
        (*itr)[container_type].push_back(insert_router);
      }
      //We now always add the default router in case of instructor overriding
      else if((*itr)["use_router"] && router_container != -1){
        (*itr)[container_type][router_container]["import_default_router"] = true;
      }
      assert(found_non_server == true);
    }
    assert(!(*itr)["title"].is_null());
    assert(!(*itr)["containers"].is_null());
  }
  return;
}

void FormatDispatcherActions(nlohmann::json &testcases, const nlohmann::json &whole_config) {

  bool docker_enabled = (whole_config["autograding_method"] == "docker");

  int testcase_num = 0;
  for (typename nlohmann::json::iterator itr = testcases.begin(); itr != testcases.end(); itr++,testcase_num++){

    if((*itr)["dispatcher_actions"].is_null()){
      (*itr)["dispatcher_actions"] = nlohmann::json::array();
      continue;
    }

    std::vector<nlohmann::json> dispatcher_actions = mapOrArrayOfMaps((*itr), "dispatcher_actions");

    if(dispatcher_actions.size() > 0){
      assert(docker_enabled);
    }

    for (int i = 0; i < dispatcher_actions.size(); i++){
      nlohmann::json dispatcher_action = dispatcher_actions[i];

      std::string action = dispatcher_action.value("action","");
      assert(action != "");

      if(action == "delay"){
        assert(!dispatcher_action["seconds"].is_null());
        assert(!dispatcher_action["delay"].is_string());

        float delay_time_in_seconds = 1.0;
        delay_time_in_seconds = float(dispatcher_action.value("seconds",1.0));
        dispatcher_action["seconds"] = delay_time_in_seconds;
      }else{

        assert(!dispatcher_action["containers"].is_null());
        nlohmann::json containers = nlohmann::json::array();
        if (dispatcher_action["containers"].is_array()){
          containers = dispatcher_action["containers"];
        }
        else{
          containers.push_back(dispatcher_action["containers"]);
        }

        dispatcher_action.erase("containers");
        dispatcher_action["containers"] = containers;

        if(action == "stdin"){
          assert(!dispatcher_action["string"].is_null());
          (*itr)["dispatcher_actions"][i] = dispatcher_action;
        }else{
          assert(action == "stop" || action == "start" || action == "kill");
        }
      }
    }
  }
}


/*
* Given an action, makes sure that the mouse button in the action is valid (left, right, middle).
* If there is no mouse button specified, "left" is added.
*/
void validate_mouse_button(nlohmann::json& action){
  if(action["mouse_button"].is_null()){
    action["mouse_button"] = "left";
  }else{
    assert(action["mouse_button"].is_string());
    assert(action["mouse_button"] == "left" || action["mouse_button"] == "middle" || action["mouse_button"] == "right");
  }
}

/*
* Given an action and a field, makes certain that the value in the field is an integer greater than min_val.
* if the field doesn't exist and populate_default is true, the field is set to default_value.
*/
void validate_integer(nlohmann::json& action, std::string field, bool populate_default, int min_val, int default_value){
  if(action.find(field) != action.end()){
    std::string action_name = action["action"];

    if(!action[field].is_number_integer()){
      std::cout << "ERROR: For the " << action_name << " action, " << field << " must be an integer." << std::endl;
    }
    assert(action[field].is_number_integer());

    if(action[field] < min_val){
      std::cout << "ERROR: For the " << action_name << " action, " << field << " must be greater than " << min_val << "." << std::endl;
    }
    assert(action[field] >= min_val);
  } else{
    if(populate_default){
      action[field] = default_value;
    }
  }
}

void validate_gif_or_screenshot_name(std::string filename){

  if(filename.find(".") != std::string::npos){
    std::cout << "ERROR: screenshot and gif names should not contain file extensions. File extensions will be added automatically." << std::endl;
  }
  if(filename.find(" ") != std::string::npos){
    std::cout << "ERROR: screenshot and gif names should not contain spaces." << std::endl;
  }
  if(filename.find("/") != std::string::npos||
     filename.find("$") != std::string::npos||
     filename.find("'") != std::string::npos||
     filename.find("\"") != std::string::npos||
     filename.find("\\") != std::string::npos){
    std::cout << "ERROR: screenshot and gif names should not contain special characters." << std::endl;
  }
  assert(filename.find(" ") == std::string::npos);
  assert(filename.find(".") == std::string::npos);
  assert(filename.find("/") == std::string::npos);
  assert(filename.find("$") == std::string::npos);
  assert(filename.find("'") == std::string::npos);
  assert(filename.find("\"") == std::string::npos);
  assert(filename.find("\\") == std::string::npos);
  assert(filename.find("*") == std::string::npos);
}

void FormatGraphicsActions(nlohmann::json &testcases, nlohmann::json &whole_config) {

  int testcase_num = 0;
  for (typename nlohmann::json::iterator itr = testcases.begin(); itr != testcases.end(); itr++,testcase_num++){
    //screenshot number resets per testcase.
    int number_of_screenshots = 0;
    int number_of_gifs = 0;

    if((*itr).find("actions") == (*itr).end()){
      continue;
    }

    std::vector<nlohmann::json> actions = mapOrArrayOfMaps((*itr), "actions");

    for (int action_num = 0; action_num < actions.size(); action_num++){
      nlohmann::json action = actions[action_num];

      std::string action_name = action.value("action","");
      assert(action != "");

      //Origin and center have no additional fields.
      if(action_name == "origin" || action_name == "center"){
        continue;
      }
      // Delay requires a float number of seconds, which must be greater than 0
      // and defaults to 1.
      else if(action_name == "delay"){

        if(action["seconds"].is_null()){
          std::cout << "ERROR: all delay actions must have a number of seconds specified." << std::endl;
        }
        assert(!action["seconds"].is_null());

        if(!action["seconds"].is_number()){
          std::cout << "ERROR: all delay actions must have a number of seconds specified." << std::endl;
        }
        assert(action["seconds"].is_number());

        float delay_time_in_seconds = float(action.value("seconds",1.0));

        assert(delay_time_in_seconds > 0);
        action["seconds"] = delay_time_in_seconds;
      }
      //screenshot can contain an optional name. Otherwise, it is labeled in numerical order.
      else if(action_name == "screenshot"){
        if(action["name"].is_null()){
          action["name"] = "screenshot_" + std::to_string(number_of_screenshots) + ".png";
        }else{
          if(!action["name"].is_string()){
            std::cout << "ERROR: if a screenshot name is specified, it must be a string." << std::endl;
          }
          assert(action["name"].is_string());
          assert(action["name"] != "");
          std::string screenshot_name = action["name"];
          validate_gif_or_screenshot_name(screenshot_name);
          action["name"] = screenshot_name + ".png";
        }
        number_of_screenshots++;
      }
      //Type requires a string to type and can have an optional "delay_in_seconds" and "presses"
      else if(action_name == "type"){
        float delay_time_in_seconds = action.value("delay_in_seconds",0.1);
        if(delay_time_in_seconds <= 0){
          std::cout << "ERROR: In the type command, delay must be greater than zero." << std::endl;
        }
        assert(delay_time_in_seconds > 0);
        action["delay_in_seconds"] = delay_time_in_seconds;

        validate_integer(action, "presses", true, 1, 1);

        if(action["string"].is_null()){
          std::cout << "ERROR: an output string must be specified in the type command." << std::endl;
        }
        assert(!action["string"].is_null());
        assert( action["string"].is_string());
        assert( action["string"] != "");

      }
      //Type requires a key_combination to press and can have an optional "delay_in_seconds" and "presses"
      else if(action_name == "key"){
        float delay_time_in_seconds = action.value("delay_in_seconds",0.1);

        if(delay_time_in_seconds <= 0){
          std::cout << "ERROR: In the key command, delay must be greater than zero." << std::endl;
        }
        assert(delay_time_in_seconds > 0);
        action["delay_in_seconds"] = delay_time_in_seconds;

        validate_integer(action, "presses", true, 1, 1);

        if(action["key_combination"].is_null()){
          std::cout << "ERROR: key combination to be pressed must be specified in the key command." << std::endl;
        }
        assert(!action["key_combination"].is_null());
        assert( action["key_combination"].is_string());
        assert( action["key_combination"] != "");
      }
      //Click and drag can have an optional start_x and start_y, and must have an end_x and end_y
      // which are greater than 0.
      else if(action_name == "click and drag"){

        validate_mouse_button(action);

        validate_integer(action, "start_x", false, 0, 0);
        validate_integer(action, "start_y", false, 0, 0);
        validate_integer(action, "end_x",   true,  0, 0);
        validate_integer(action, "end_y",   true,  0, 0);

        if(action["end_x"] == 0 && action["end_y"] == 0){
          std::cout << "ERROR: some movement must be specified in click and drag" << std::endl;
        }
        assert(action["end_x"] != 0 || action["end_y"] != 0);

      }
      //Click and drag delta can have an optional mouse button, and must have and end_x and end_y.
      else if(action_name == "click and drag delta"){

        validate_mouse_button(action);

        validate_integer(action, "end_x",   true,  -100000, 0);
        validate_integer(action, "end_y",   true,  -100000, 0);

        if(action["end_x"] == 0 && action["end_y"] == 0){
          std::cout << "ERROR: some movement must be specified in click and drag" << std::endl;
        }

        assert(action["end_x"] != 0 || action["end_y"] != 0);

      }
      //Click has an optional mouse button.
      else if(action_name == "click"){
        validate_mouse_button(action);
      }
      //Mouse move has an optional end_x and end_y.
      else if(action_name == "mouse move" || action_name == "move mouse" || action_name == "move"){
        validate_integer(action, "end_x", true, 0, 0);
        validate_integer(action, "end_y", true, 0, 0);
        action["action"] = "move mouse";
      }
      //gif requires a duration and can optionally have a name.
      else if(action_name == "gif"){

        if(action["seconds"].is_null()){
          std::cout << "ERROR: all delay actions must have a number of seconds specified." << std::endl;
        }
        assert(!action["seconds"].is_null());

        if(!action["seconds"].is_number()){
          std::cout << "ERROR: all delay actions must have a number of seconds specified." << std::endl;
        }
        assert(action["seconds"].is_number());

        float gif_duration = float(action.value("seconds",1.0));
        assert(gif_duration > 0);
        action["seconds"] = gif_duration;

        if(action["name"].is_null()){
          action["name"] = "gif_" + std::to_string(number_of_gifs);
        }
        else{
          if(!action["name"].is_string()){
            std::cout << "ERROR: if a screenshot name is specified, it must be a string." << std::endl;
          }
          assert(action["name"].is_string());
          assert(action["name"] != "");
          std::string gif_name = action["name"];
          validate_gif_or_screenshot_name(gif_name);
        }

        if(action["preserve_individual_frames"].is_null()){
          action["preserve_individual_frames"] = false;
        }else{
          assert(action["preserve_individual_frames"].is_boolean());
        }
        //minimum frames_per_second is 1, default is 10.
        validate_integer(action, "frames_per_second", true, 1, 10);

        if(action["frames_per_second"] > 30){
          std::cout << "ERROR: Submitty does not allow gifs with an fps greater than 30." << std::endl;
          assert(action["frames_per_second"] <= 30);
        }

        number_of_gifs++;
      }

      //Fail if the action is not valid.
      else{
        bool valid_action_type = false;
        if(action_name == ""){
          std::cout << "ERROR: no 'action' field defined." << std::endl;
        }else{
          std::cout << "ERROR: Could not recognize action " << action_name << std::endl;
        }
        assert(valid_action_type == true);
      }
      (*itr)["actions"][action_num] = action;
    }
  }
}

void formatPreActions(nlohmann::json &testcases, nlohmann::json &whole_config) {
  // loop over testcases
  int which_testcase = 0;
  for (nlohmann::json::iterator my_testcase = testcases.begin(); my_testcase != testcases.end(); my_testcase++, which_testcase++) {

    if((*my_testcase)["pre_commands"].is_null()){
      (*my_testcase)["pre_commands"] = nlohmann::json::array();
      continue;
    }

    std::vector<nlohmann::json> pre_commands = mapOrArrayOfMaps((*my_testcase), "pre_commands");

    for (int i = 0; i < pre_commands.size(); i++){
      nlohmann::json pre_command = pre_commands[i];

      //right now we only support copy.
      assert(pre_command["command"] == "cp");

      if(!pre_command["option"].is_string()){
        pre_command["option"] = "-R";
      }

      //right now we only support recursive.
      assert(pre_command["option"] == "-R");

      assert(pre_command["testcase"].is_string());

      std::string testcase = pre_command["testcase"];

      //remove trailing slash
      if(testcase.length() == 7){
        testcase = testcase.substr(0,6);
      }

      assert(testcase.length() == 6);

      // TODO: This was removed due to testcase_ids.
      // std::string prefix = testcase.substr(0,4);
      // assert(prefix == "test");

      std::string number = testcase.substr(4,6);
      int remainder = std::stoi( number );

      //we must be referencing a previous testcase. (+1 because we start at 0)
      assert(remainder > 0);
      assert(remainder < which_testcase+1);

      pre_command["testcase"] = testcase;


      //The source must be a string.
      assert(pre_command["source"].is_string());

      //the source must be of the form prefix = test, remainder is less than size 3 and is an int.
      std::string source_name = pre_command["source"];

      assert(source_name[0] != '/');

      //The command must not container .. or $.
      assert(source_name.find("..") == std::string::npos);
      assert(source_name.find("$")  == std::string::npos);
      assert(source_name.find("~")  == std::string::npos);
      assert(source_name.find("\"") == std::string::npos);
      assert(source_name.find("\'") == std::string::npos);

      if(!pre_command["pattern"].is_string()){
         pre_command["pattern"] = "";
      }else{
        std::string pattern = pre_command["pattern"];
        //The pattern must not container .. or $
        assert(pattern.find("..") == std::string::npos);
        assert(pattern.find("$") == std::string::npos);
        assert(pattern.find("~") == std::string::npos);
        assert(pattern.find("\"") == std::string::npos);
        assert(pattern.find("\'") == std::string::npos);
      }

      //there must be a destination
      assert(pre_command["destination"].is_string());

      std::string destination = pre_command["destination"];

      //The destination must not container .. or $
      assert(destination.find("..") == std::string::npos);
      assert(destination.find("$") == std::string::npos);
      assert(destination.find("~") == std::string::npos);
      assert(destination.find("\"") == std::string::npos);
      assert(destination.find("\'") == std::string::npos);

      (*my_testcase)["pre_commands"][i] = pre_command;
    }
  }
}

void RewriteDeprecatedMyersDiff(nlohmann::json &testcases, nlohmann::json &whole_config) {
  // loop over testcases
  int which_testcase = 0;
  for (nlohmann::json::iterator my_testcase = testcases.begin(); my_testcase != testcases.end(); my_testcase++,which_testcase++) {
    nlohmann::json::iterator validators = my_testcase->find("validation");
    if (validators == my_testcase->end()) {
      /* no autochecks */
      continue;
    }
    // loop over autochecks
    for (int which_autocheck = 0; which_autocheck < validators->size(); which_autocheck++) {
      nlohmann::json& autocheck = (*validators)[which_autocheck];
      std::string method = autocheck.value("method","");
      std::string comparison = autocheck.value("comparison","");

      // if autocheck if old byLinebyWord format... make it byLinebyChar
      if (comparison == "byLinebyWord") {
          autocheck["comparison"] = "byLinebyChar";
      }

      // if autocheck is old myersdiff format...  rewrite it!
      if (method == "myersDiffbyLinebyChar") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLinebyChar";
      } else if (method == "myersDiffbyLinebyWord") {
        autocheck["method"] = "diff";
        assert (autocheck.find("comparison") == autocheck.end());
        autocheck["comparison"] = "byLinebyChar";
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

void InflateTestcases(nlohmann::json &testcases, nlohmann::json &whole_config, int& testcase_id){

  for (typename nlohmann::json::iterator itr = testcases.begin(); itr != testcases.end(); itr++){
      InflateTestcase(*itr, whole_config, testcase_id);
  }
}

bool validShowValue(const nlohmann::json& v) {
  return (v.is_string() &&
          (v == "always" ||
           v == "never" ||
           v == "on_failure" ||
           v == "on_success"));
}

void InflateTestcase(nlohmann::json &single_testcase, nlohmann::json &whole_config, int& testcase_id) {
  //move to load_json
  General_Helper(single_testcase);
  // TODO: Make certain that testcase ids are unique.
  // For now we overwrite this field.
  std::stringstream ss;
  ss << "test" << std::setw(2) << std::setfill('0') << testcase_id + 1;
  single_testcase["testcase_id"] =  ss.str();
  testcase_id++;

  if (!single_testcase["timestamped_stdout"].is_boolean()){
    single_testcase["timestamped_stdout"] = whole_config["timestamped_stdout"];
  }

  if (!single_testcase["publish_actions"].is_boolean()) {
    single_testcase["publish_actions"] = whole_config["publish_actions"];
  }

  if (single_testcase.value("type","Execution") == "FileCheck") {
    FileCheck_Helper(single_testcase);
  } else if (single_testcase.value("type","Execution") == "Compilation") {
    Compilation_Helper(single_testcase);
  } else {
    assert (single_testcase.value("type","Execution") == "Execution");
    Execution_Helper(single_testcase);
  }

  single_testcase["testcase_label"] = single_testcase.value("testcase_label", "");
  single_testcase["details"] = single_testcase.value("details","");
  single_testcase["extra_credit"] = single_testcase.value("extra_credit",false);
  single_testcase["release_hidden_details"] = single_testcase.value("release_hidden_details", false);
  single_testcase["hidden"] = single_testcase.value("hidden", false);
  assert(!(single_testcase["release_hidden_details"] && !single_testcase["hidden"]));
  single_testcase["view_testcase_message"] = single_testcase.value("view_testcase_message",true);
  single_testcase["publish_actions"] = single_testcase.value("publish_actions", false);


  nlohmann::json::iterator itr = single_testcase.find("validation");
  if (itr != single_testcase.end()) {
    assert (itr->is_array());
    VerifyGraderDeductions(*itr);
    std::vector<nlohmann::json> containers = mapOrArrayOfMaps(single_testcase, "containers");
    AddDefaultGraphicsChecks(*itr,single_testcase);
    AddDefaultGraders(containers,*itr,whole_config);
     for (int i = 0; i < (*itr).size(); i++) {
      nlohmann::json& grader = (*itr)[i];
      nlohmann::json::iterator itr2;
      std::string method = grader.value("method","MISSING METHOD");
      itr2 = grader.find("show_message");
      if (itr2 == grader.end()) {
        if (method == "warnIfNotEmpty" || method == "warnIfEmpty") {
          grader["show_message"] = "on_failure";
        } else {
          if (grader.find("actual_file") != grader.end() &&
              *(grader.find("actual_file")) == "execute_logfile.txt" &&
              grader.find("show_actual") != grader.end() &&
              *(grader.find("show_actual")) == "never") {
            grader["show_message"] = "never";
          } else {
            grader["show_message"] = "always";
          }
        }
      } else {
        assert (validShowValue(*itr2));
      }
      if (grader.find("actual_file") != grader.end()) {
        itr2 = grader.find("show_actual");
        if (itr2 == grader.end()) {
          if (method == "warnIfNotEmpty" || method == "warnIfEmpty") {
            grader["show_actual"] = "on_failure";
          } else {
            grader["show_actual"] = "always";
          }
        } else {
          assert (validShowValue(*itr2));
        }
      }
      if (grader.find("expected_file") != grader.end()) {
        itr2 = grader.find("show_expected");
        if (itr2 == grader.end()) {
          grader["show_expected"] = "always";
        } else {
          assert (validShowValue(*itr2));
        }
      }
      if (grader.find("expected_string") != grader.end()) {
        itr2 = grader.find("show_expected");
        if (itr2 == grader.end()) {
          grader["show_expected"] = "always";
        } else {
          assert (validShowValue(*itr2));
        }
      }
    }
  }
}

// =====================================================================
// =====================================================================
nlohmann::json LoadAndCustomizeConfigJson(const std::string &student_id) {
    nlohmann::json config_json;
    std::stringstream sstr(GLOBAL_config_json_string);

    sstr >> config_json;

    if (student_id != "") {
      CustomizeAutoGrading(student_id, config_json);
    }
    return config_json;
}


nlohmann::json FillInConfigDefaults(nlohmann::json& config_json, const std::string& assignment_id) {

  AddGlobalDefaults(config_json);
  int testcase_id = 0;
  /**
  * Validate/complete-ify the global testcases array.
  **/
  nlohmann::json::iterator testcases = config_json.find("testcases");
  if(testcases == config_json.end()) {
    config_json["testcases"] = nlohmann::json::array();
    testcases = config_json.find("testcases");
  }

  AddDockerConfiguration(*testcases, config_json);
  FormatDispatcherActions(*testcases, config_json);
  formatPreActions(*testcases, config_json);
  FormatGraphicsActions(*testcases, config_json);
  RewriteDeprecatedMyersDiff(*testcases, config_json);
  std::cout << "Inflating testcases" << std::endl;
  InflateTestcases(*testcases, config_json, testcase_id);

  /**
  * Validate/complete-ify the testcases in each item in the item_pool.
  **/
  nlohmann::json::iterator item_pool = config_json.find("item_pool");
  if(item_pool != config_json.end()) {
    for(nlohmann::json::iterator item = config_json["item_pool"].begin(); item != config_json["item_pool"].end(); item++) {
      nlohmann::json::iterator item_testcases = item->find("testcases");
      if(item_testcases == item->end()) {
        (*item)["testcases"] = nlohmann::json::array();
        item_testcases = item->find("testcases");
      }
      AddDockerConfiguration(*item_testcases, config_json);
      FormatDispatcherActions(*item_testcases, config_json);
      formatPreActions(*item_testcases, config_json);
      FormatGraphicsActions(*item_testcases, config_json);
      RewriteDeprecatedMyersDiff(*item_testcases, config_json);
      InflateTestcases(*item_testcases, config_json, testcase_id);
    }
  } else {
    config_json["item_pool"] = nlohmann::json::array();
  }

  ValidateNotebooks(config_json);
  AddSubmissionLimitTestCase(config_json);

  /*************************************************
  * Add Global File Copy Commands
  * This step must come last, as it requires all
  * testcase ids to be in place.
  **************************************************/
  AddAutogradingConfiguration(config_json);
  // Archive validated and compiled files (must be done after AddAutogradingConfiguration)
  ArchiveValidatedFiles(*testcases, config_json);
  PreserveCompiledFiles(*testcases, config_json);
  if(item_pool != config_json.end()) {
    for(nlohmann::json::iterator item = config_json["item_pool"].begin(); item != config_json["item_pool"].end(); item++) {
      nlohmann::json::iterator item_testcases = item->find("testcases");
      ArchiveValidatedFiles(*item_testcases, config_json);
      PreserveCompiledFiles(*item_testcases, config_json);
    }
  }

  ComputeGlobalValues(config_json, assignment_id);

  return config_json;
}

/**
* Add automatic GIF filechecks.
*/
void AddDefaultGraphicsChecks(nlohmann::json &json_graders, const nlohmann::json &testcase){
  if(testcase.find("actions") == testcase.end()){
    return;
  }

  std::vector<nlohmann::json> actions = mapOrArrayOfMaps(testcase, "actions");

  for (int action_num = 0; action_num < actions.size(); action_num++){
    nlohmann::json action = actions[action_num];
    std::string action_name = action.value("action","");
    if(action_name != "gif"){
      continue;
    }
    /*
    //We found a gif! Add a filecheck for it.
    // let's not add it automatically right now, more useful to leave it to configuration to specify the description etc.
    nlohmann::json j;
    std::string gif_name = action["name"];
    std::string full_gif_name = gif_name + ".gif";

    j["actual_file"] = full_gif_name;
    j["deduction"]   = 0;
    j["description"] = "Student GIF: " + gif_name;
    j["method"]      = "warnIfEmpty";
    j["show_actual"] = "always";
    j["show_message"]= "never";
    json_graders.push_back(j);
    */
  }
}

/*
* Start
*/

// Every command sends standard output and standard error to two
// files.  Make sure those files are sent to a grader.
void AddDefaultGraders(const std::vector<nlohmann::json> &containers,
                       nlohmann::json &json_graders,
                       const nlohmann::json &whole_config) {
  std::set<std::string> files_covered;
  assert (json_graders.is_array());

  //Find the names of every file explicitly checked for by the instructor (we don't have to add defaults for these.)
  for (int i = 0; i < json_graders.size(); i++) {
    std::vector<std::string> filenames = stringOrArrayOfStrings(json_graders[i],"actual_file");
    for (int j = 0; j < filenames.size(); j++) {
      files_covered.insert(filenames[j]);
    }
  }

  //For every container, for every command, we want to add default graders for the appropriate files.
  for (int i = 0; i < containers.size(); i++) {

    if(containers[i]["server"] == true){
      continue;
    }

    std::string prefix = "";
    //If there are more than one containers, we do need to prepend its directory (e.g. container1/).
    if(containers.size() > 1){
      std::string container_name = containers[i].value("container_name","");
      assert(container_name != "");
      prefix = container_name + "/";
    }
    std::vector<std::string> commands = stringOrArrayOfStrings(containers[i],"commands");
    for(int j = 0; j < commands.size(); j++){
      std::string suffix = ".txt";

      //If this container contains multiple commands, we need to append a number to its STDOUT/ERR
      if (commands.size() > 1){
        suffix = "_"+std::to_string(j)+".txt";
      }

      AddDefaultGrader(containers[i]["commands"][j],files_covered,json_graders,prefix+"STDOUT"+suffix,whole_config);
      AddDefaultGrader(containers[i]["commands"][j],files_covered,json_graders,prefix+"STDERR"+suffix,whole_config);
    }
  }
}

// If we don't already have a grader for the indicated file, add a
// simple "WarnIfNotEmpty" check, that will print the contents of the
// file to help the student debug if their output has gone to the
// wrong place or if there was an execution error
void AddDefaultGrader(const std::string &command,
                      const std::set<std::string> &files_covered,
                      nlohmann::json& json_graders,
                      const std::string &filename,
                      const nlohmann::json &whole_config) {
  if (files_covered.find(filename) != files_covered.end())
    return;
  //std::cout << "ADD GRADER WarnIfNotEmpty test for " << filename << std::endl;
  nlohmann::json j;
  j["method"] = "warnIfNotEmpty";
  j["actual_file"] = filename;
  if (filename.find("STDOUT") != std::string::npos) {
    j["description"] = "Standard Output ("+filename+")";
  } else if (filename.find("STDERR") != std::string::npos) {
    std::string program_name = get_program_name(command,whole_config);
    if (program_name == "/usr/bin/python") {
      j["description"] = "syntax error output from running python";
    } else if (program_name.find("java") != std::string::npos) {
      j["description"] = "syntax error output from running java";
      if (program_name.find("javac") != std::string::npos) {
        j["description"] = "syntax error output from compiling java";
      }
      if (j["method"] == "warnIfNotEmpty" || j["method"] == "errorIfNotEmpty") {
        j["jvm_memory"] = true;
      }
    } else {
      j["description"] = "Standard Error ("+filename+")";
    }
  } else {
    j["description"] = "DEFAULTING TO "+filename;
  }
  j["deduction"] = 0.0;
  j["show_message"] = "on_failure";
  j["show_actual"] = "on_failure";
  json_graders.push_back(j);
}

void General_Helper(nlohmann::json &single_testcase) {
  nlohmann::json::iterator itr;

  // Check the required fields for all test types
  itr = single_testcase.find("title");
  assert (itr != single_testcase.end() && itr->is_string());

  // Check the type of the optional fields
  itr = single_testcase.find("description");
  if (itr != single_testcase.end()) { assert (itr->is_string()); }
  itr = single_testcase.find("points");
  if (itr != single_testcase.end()) { assert (itr->is_number()); }
}

void FileCheck_Helper(nlohmann::json &single_testcase) {
  nlohmann::json::iterator f_itr,v_itr,m_itr,itr;

  // Check the required fields for all test types
  f_itr = single_testcase.find("actual_file");
  v_itr = single_testcase.find("validation");
  m_itr = single_testcase.find("max_submissions");

  if (f_itr != single_testcase.end()) {
    // need to rewrite to use a validation
    assert (m_itr == single_testcase.end());
    assert (v_itr == single_testcase.end());
    nlohmann::json v;
    v["method"] = "fileExists";
    v["actual_file"] = (*f_itr);
    std::vector<std::string> filenames = stringOrArrayOfStrings(single_testcase,"actual_file");
    std::string desc;
    for (int i = 0; i < filenames.size(); i++) {
      if (i != 0) desc += " ";
      desc += filenames[i];
    }
    v["description"] = desc;
    if (filenames.size() != 1) {
      v["show_actual"] = "never";
    }
    if (single_testcase.value("one_of",false)) {
      v["one_of"] = true;
    }
    single_testcase["validation"].push_back(v);
    single_testcase.erase(f_itr);
  } else if (v_itr != single_testcase.end()) {
    // already has a validation
  } else {
    assert (m_itr != single_testcase.end());
    assert (m_itr->is_number_integer());
    assert ((int)(*m_itr) >= 1);

    itr = single_testcase.find("points");
    if (itr == single_testcase.end()) {
      single_testcase["points"] = -5;
    } else {
      assert (itr->is_number_integer());
      assert ((int)(*itr) <= 0);
    }
    itr = single_testcase.find("penalty");
    if (itr == single_testcase.end()) {
      single_testcase["penalty"] = -0.1;
    } else {
      assert (itr->is_number());
      assert ((*itr) <= 0);
    }
    itr = single_testcase.find("title");
    if (itr == single_testcase.end()) {
      single_testcase["title"] = "Submission Limit";
    } else {
      assert (itr->is_string());
    }
  }
}

bool HasActualFileCheck(const nlohmann::json &v_itr, const std::string &actual_file) {
  assert (actual_file != "");
  const std::vector<nlohmann::json> tmp = v_itr.get<std::vector<nlohmann::json> >();
  for (int i = 0; i < tmp.size(); i++) {
    if (tmp[i].value("actual_file","") == actual_file) {
      return true;
    }
  }
  return false;
}

void Compilation_Helper(nlohmann::json &single_testcase) {
  nlohmann::json::iterator f_itr,v_itr,w_itr;

  // Check the required fields for all test types
  f_itr = single_testcase.find("executable_name");
  v_itr = single_testcase.find("validation");

  if (v_itr != single_testcase.end()) {
    assert (v_itr->is_array());
    std::vector<nlohmann::json> tmp = v_itr->get<std::vector<nlohmann::json> >();
  }

  if (f_itr != single_testcase.end()) {
    //assert that there is exactly 1 container
    assert(!single_testcase["containers"].is_null());
    //assert that the container has commands
    assert(!single_testcase["containers"][0]["commands"].is_null());
    nlohmann::json commands = single_testcase["containers"][0]["commands"];
    //grab the container's commands.
    assert (commands.size() > 0);
    for (int i = 0; i < commands.size(); i++) {
      w_itr = single_testcase.find("warning_deduction");
      float warning_fraction = 0.0;
      if (w_itr != single_testcase.end()) {
        assert (w_itr->is_number());
        warning_fraction = (*w_itr);
        single_testcase.erase(w_itr);
      }
      assert (warning_fraction >= 0.0 && warning_fraction <= 1.0);
      nlohmann::json v2;
      v2["method"] = "errorIfNotEmpty";
      if (commands.size() == 1) {
        v2["actual_file"] = "STDERR.txt";
      } else {
        v2["actual_file"] = "STDERR_" + std::to_string(i) + ".txt";
      }
      v2["description"] = "Compilation Errors and/or Warnings";
      nlohmann::json command_json = commands[i];
      assert (command_json.is_string());
      std::string command = command_json;
      if (command.find("java") != std::string::npos) {
        v2["jvm_memory"] = true;
      }
      v2["show_actual"] = "on_failure";
      v2["show_message"] = "on_failure";
      v2["deduction"] = warning_fraction;

      v_itr = single_testcase.find("validation");
      if (v_itr == single_testcase.end() ||
          !HasActualFileCheck(*v_itr,v2["actual_file"])) {
        single_testcase["validation"].push_back(v2);
      }
    }


    std::vector<std::string> executable_names = stringOrArrayOfStrings(single_testcase,"executable_name");
    assert (executable_names.size() > 0);
    for (int i = 0; i < executable_names.size(); i++) {
      nlohmann::json v;
      v["method"] = "fileExists";
      v["actual_file"] = executable_names[i];
      v["description"] = "Create Executable";
      v["show_actual"] = "on_failure";
      v["show_message"] = "on_failure";
      v["deduction"] = 1.0/executable_names.size();

      v_itr = single_testcase.find("validation");
      if (v_itr == single_testcase.end() ||
          !HasActualFileCheck(*v_itr,v["actual_file"])) {
        single_testcase["validation"].push_back(v);
      }
    }
  }

  v_itr = single_testcase.find("validation");

  if (v_itr != single_testcase.end()) {
    assert (v_itr->is_array());
    std::vector<nlohmann::json> tmp = v_itr->get<std::vector<nlohmann::json> >();
  }

  assert (v_itr != single_testcase.end());
}

void Execution_Helper(nlohmann::json &single_testcase) {
  nlohmann::json::iterator itr = single_testcase.find("validation");
  assert (itr != single_testcase.end());
  for (nlohmann::json::iterator itr2 = (itr)->begin(); itr2 != (itr)->end(); itr2++) {
    nlohmann::json& j = *itr2;
    std::string method = j.value("method","");
    std::string description = j.value("description","");
    if (description=="") {
      if (method =="JUnitTestGrader") {
        j["description"] = "JUnit output";
      } else if (method =="JaCoCoCoverageReportGrader") {
        j["description"] = "JaCoCo coverage report";
      } else if (method =="MultipleJUnitTestGrader") {
        j["description"] = "TestRunner output";
      }
    }
  }

  //assert (commands.size() > 0);

}

// Go through the instructor-written test cases.
//   If the autograding points are non zero, and
//   if the instructor didn't add a penalty for excessive submissions, then
//   add a standard small penalty for > 20 submissions.
//
void AddSubmissionLimitTestCase(nlohmann::json &config_json) {
  int total_points = 0;
  bool has_limit_test = false;

  // count total points and search for submission limit testcase
  nlohmann::json::iterator testcases = config_json.find("testcases");
  assert (testcases != config_json.end());
  for (nlohmann::json::iterator tc = testcases->begin(); tc != testcases->end(); tc++) {
    //This input to testcase is only necessary if the testcase needs to retrieve its 'commands'
    std::string container_name = "";
    std::string testcase_id = tc->value("testcase_id", "");
    assert(testcase_id != "");
    TestCase my_testcase(*tc, testcase_id, container_name);
    int points = tc->value("points", 0);
    if (points > 0) {
      total_points += points;
    }
    if (my_testcase.isSubmissionLimit()) {
      has_limit_test = true;
    }
  }

  // add submission limit test case
  if (!has_limit_test) {
    nlohmann::json limit_test;
    limit_test["type"] = "FileCheck";
    limit_test["title"] = "Submission Limit";
    limit_test["max_submissions"] = MAX_NUM_SUBMISSIONS;
    // TODO: check that there are no other testcases with id SubmissionLimit
    limit_test["testcase_id"] = "SubmissionLimit";
    if (total_points > 0) {
      limit_test["points"] = -5;
      limit_test["penalty"] = -0.1;
    } else {
      limit_test["points"] = 0;
      limit_test["penalty"] = 0;
    }
    config_json["testcases"].push_back(limit_test);
  }
}

void CustomizeAutoGrading(const std::string& username, nlohmann::json& j) {
  if (j.find("string_replacement") != j.end()) {
    // Read and check string replacement variables
    nlohmann::json j2 = j["string_replacement"];
    std::string placeholder = j2.value("placeholder","");
    assert (placeholder != "");
    std::string replacement = j2.value("replacement","");
    assert (replacement != "");
    assert (replacement == "hashed_username");
    int mod_value = j2.value("mod",-1);
    assert (mod_value > 0);

    int A = 54059; /* a prime */
    int B = 76963; /* another prime */
    int FIRSTH = 37; /* also prime */
    unsigned int sum = FIRSTH;
    for (int i = 0; i < username.size(); i++) {
      sum = (sum * A) ^ (username[i] * B);
    }
    int assigned = (sum % mod_value)+1;

    std::string repl = std::to_string(assigned);

    nlohmann::json::iterator association = j2.find("association");
    if (association != j2.end()) {
      repl = (*association)[repl];
    }

    nlohmann::json::iterator itr = j.find("testcases");
    if (itr != j.end()) {
      RecursiveReplace(*itr,placeholder,repl);
    }
  }
}

void RecursiveReplace(nlohmann::json& j, const std::string& placeholder, const std::string& replacement) {
  if (j.is_string()) {
    std::string str = j.get<std::string>();
    int pos = str.find(placeholder);
    if (pos != std::string::npos) {
      std::cout << "REPLACING '" << str << "' with '";
      str.replace(pos,placeholder.length(),replacement);
      std::cout << str << "'" << std::endl;
      j = str;
    }
  } else if (j.is_array() || j.is_object()) {
    for (nlohmann::json::iterator itr = j.begin(); itr != j.end(); itr++) {
      RecursiveReplace(*itr,placeholder,replacement);
    }
  }
}

// Make sure the sum of deductions across graders adds to at least 1.0.
// If a grader does not have a deduction setting, set it to 1/# of (non default) graders.
void VerifyGraderDeductions(nlohmann::json &json_graders) {
  assert (json_graders.is_array());
  assert (json_graders.size() > 0);

  int json_grader_count = 0;
  for (int i = 0; i < json_graders.size(); i++) {
    nlohmann::json::const_iterator itr = json_graders[i].find("method");
    if (itr != json_graders[i].end()) {
      json_grader_count++;
    }
  }

  assert (json_grader_count > 0);

  float default_deduction = 1.0 / float(json_grader_count);
  float sum = 0.0;
  for (int i = 0; i < json_graders.size(); i++) {
    nlohmann::json::const_iterator itr = json_graders[i].find("method");
    if (itr == json_graders[i].end()) {
      json_graders[i]["deduction"] = 0;
      continue;
    }
    itr = json_graders[i].find("deduction");
    float deduction;
    if (itr == json_graders[i].end()) {
      json_graders[i]["deduction"] = default_deduction;
      deduction = default_deduction;
    } else {
      assert (itr->is_number());
      deduction = (*itr);
    }
    sum += deduction;
  }

  if (sum < 0.99) {
    std::cout << "ERROR! DEDUCTION SUM < 1.0: " << sum << std::endl;
  }
}

std::vector<std::string> gatherAllTestcaseIds(const nlohmann::json& complete_config){
  std::vector<std::string> testcase_names;
  nlohmann::json::const_iterator testcases = complete_config.find("testcases");
  assert (testcases != complete_config.end());
  for (nlohmann::json::const_iterator tc = testcases->begin(); tc != testcases->end(); tc++) {
    std::string testcase_id = tc->value("testcase_id", "");
    assert(testcase_id != "");
    testcase_names.push_back(testcase_id);
  }

  nlohmann::json::const_iterator item_pool = complete_config.find("item_pool");
  if (item_pool != complete_config.end()){
    for(nlohmann::json::const_iterator item_itr = item_pool->begin(); item_itr != item_pool->end(); item_itr++) {
      nlohmann::json item_testcases = item_itr->value("testcases", nlohmann::json::array());
      for(nlohmann::json::iterator it_itr = item_testcases.begin(); it_itr != item_testcases.end(); it_itr++) {
        std::string testcase_id = it_itr->value("testcase_id", "");
        assert(testcase_id != "");
        testcase_names.push_back(testcase_id);
      }
    }
  }

  return testcase_names;
}

void ValidateNotebooks(nlohmann::json& config_json) {
  if (config_json.find("notebook") != config_json.end()){
    config_json["notebook"] = ValidateANotebook(config_json["notebook"], config_json);
  }

  if(config_json.find("item_pool") != config_json.end()){
    int i = 0;
    for(nlohmann::json::iterator itr = config_json["item_pool"].begin(); itr != config_json["item_pool"].end(); itr++, i++) {
      config_json["item_pool"][i]["item_name"] = (*itr)["item_name"];
      config_json["item_pool"][i]["notebook"] = ValidateANotebook((*itr)["notebook"], config_json);
    }
  }
}

nlohmann::json ValidateANotebook(const nlohmann::json& notebook, const nlohmann::json& config_json) {
    // Setup "notebook" items inside the 'j' json item that will be passed forward
    nlohmann::json complete = nlohmann::json::array();
    nlohmann::json::const_iterator itempool_definitions = config_json.find("item_pool");
    int i = 0;
    for (nlohmann::json::const_iterator itr = notebook.begin(); itr != notebook.end(); itr++, i++){
      const nlohmann::json in_notebook_cell = (*itr);
      nlohmann::json out_notebook_cell;

      // Get type field
      std::string type = in_notebook_cell.value("type", "");
      assert(type != "");
      out_notebook_cell["type"] = type;

      // Get testcase_ref if it exists
      std::string testcase_ref = in_notebook_cell.value("testcase_ref", "");
      if(testcase_ref != ""){
        out_notebook_cell["testcase_ref"] = testcase_ref;
      }

      // Handle each specific note book cell type
      // Handle markdown data
      if(type == "markdown"){
          // Get markdown items
          std::string markdown_string = in_notebook_cell.value("markdown_string", "");
          std::string markdown_file = in_notebook_cell.value("markdown_file", "");

          // Assert only one was passed in
          assert(
                  (markdown_string != "" && markdown_file == "") ||
                  (markdown_string == "" && markdown_file != "")
                  );

          // Pass forward the item that was passed in
          if(markdown_string != ""){
              out_notebook_cell["markdown_string"] = markdown_string;
          }
          else{
              out_notebook_cell["markdown_file"] = markdown_file;
          }
      }
      // Handle image data
      else if(type == "image"){
          // Get req image items
          std::string image = in_notebook_cell.value("image", "");

          // Assert req fields were not empty
          assert(image != "");

          // Get optional image items
          int height = in_notebook_cell.value("height", 0);
          int width = in_notebook_cell.value("width", 0);
          std::string alt_text = in_notebook_cell.value("alt_text", "Instructor provided image");

          // Pass forward populated items
          out_notebook_cell["image"] = image;
          out_notebook_cell["alt_text"] = alt_text;

          if(height > 0){
              out_notebook_cell["height"] = height;
          }

          if(width > 0){
              out_notebook_cell["width"] = width;
          }
      }
      // Handle file_submission
      else if(type == "file_submission"){
          // required field
          std::string directory = in_notebook_cell.value("directory","");
          assert (directory != "");

          // optional field
          std::string label = in_notebook_cell.value("label","");

          // Pass forward populated items
          out_notebook_cell["directory"] = directory;
          out_notebook_cell["label"] = label;
      }
      // Handle short_answer data
      else if(type == "short_answer"){
          // Get req short_answer items
          std::string filename = in_notebook_cell.value("filename", "");

          // Assert req fields were not empty
          assert(filename != "");

          // Get optional short_answer items
          std::string initial_value = in_notebook_cell.value("initial_value", "");
          std::string programming_language = in_notebook_cell.value("programming_language", "");
          int rows = in_notebook_cell.value("rows", 0);

          // Pass forward populated items
          out_notebook_cell["filename"] = filename;
          out_notebook_cell["initial_value"] = initial_value;
          out_notebook_cell["rows"] = rows;

          if(programming_language != ""){
              out_notebook_cell["programming_language"] = programming_language;
          }
      }
      // Handle multiple choice data
      else if(type == "multiple_choice"){
          // Get req multiple choice items
          std::string filename = in_notebook_cell.value("filename", "");

          // Assert filename was present
          assert(filename != "");

          // Get choices
          nlohmann::json choices = in_notebook_cell.value("choices", nlohmann::json::array());

          int num_of_choices = 0;
          for (auto it = choices.begin(); it != choices.end(); ++it){
              // Reassign the value of this iteration to choice
              nlohmann::json choice = it.value();

              // Get value and description
              std::string value = choice.value("value", "");
              std::string description = choice.value("description", "");

              // Assert choice value and description were in fact present
              assert(value != "");
              assert(description != "");

              num_of_choices++;
          }

          // Assert choices was not empty
          assert(num_of_choices > 0);

          bool allow_multiple = in_notebook_cell.value("allow_multiple", false);
          bool randomize_order = in_notebook_cell.value("randomize_order", false);

          // Pass forward items
          out_notebook_cell["filename"] = filename;
          out_notebook_cell["choices"] = choices;
          out_notebook_cell["allow_multiple"] = allow_multiple;
          out_notebook_cell["randomize_order"] = randomize_order;
      }
      else if(type == "item"){
        std::string item_label = in_notebook_cell.value("item_label", "");
        nlohmann::json user_item_map = in_notebook_cell.value("user_item_map", nlohmann::json::object());

        if(itempool_definitions == config_json.end()){
          std::cout << "ERROR: Found an \"item\" cell but no global item_pool was defined!" << std::endl;
          throw -1;
        }
        //Search through the global item_pool to find if the items in this itempool exist
        if(in_notebook_cell.find("from_pool") == in_notebook_cell.end()){
          std::cout << "ERROR: item with label " << (item_label.empty() ? "\"[no label]\"" : item_label)
                    << " does not have a from_pool" << std::endl;
          throw -1;
        }
        nlohmann::json in_notebook_cell_from_pool = in_notebook_cell.value("from_pool", nlohmann::json::array());
        //std::cout << "Checking for " << in_notebook_cell_item_pool.size() << " items among a global set of "
        //          << itempool_definitions->size() << " items" << std::endl;

        if(in_notebook_cell_from_pool.size() == 0){
          std::cout << "ERROR: item with label " << (item_label.empty() ? "\"[no label]\"" : item_label)
                    << " has an empty from_pool, requires at least one item!" << std::endl;
          throw -1;
        }

        for(int j=0; j<in_notebook_cell_from_pool.size(); j++){
          bool found_global_itempool_item = false;
          for(int k=0; k<itempool_definitions->size(); k++) {
            if ((*itempool_definitions)[k]["item_name"] == in_notebook_cell_from_pool[j]) {
              found_global_itempool_item = true;
              break;
            }
          }
          if(!found_global_itempool_item){
            std::cout << "ERROR: item with label \"" << (item_label.empty() ? "[no label]" : item_label);
            std::cout << "\" requested undefined item: " << in_notebook_cell_from_pool[j] << std::endl;
            throw -1;
          }
          /*else{
            std::cout << "Found global itempool item " << in_notebook_cell_from_pool[j] << " for item with label: "
                      << (item_label.empty() ? "[no label]" : item_label) << std::endl;
          }*/
        }
        // Check if the indices mapped to users are in 'from_pool' array range
        for (auto i=user_item_map.begin(); i!=user_item_map.end(); i++) {
           int item_index = i.value();
           if(item_index < 0 || item_index >= in_notebook_cell_from_pool.size()) {
               std::cout << "ERROR: user (" << i.key() <<") mapped with index \"" << item_index;
               std::cout << "\" which is out of 'from_pool' array range: 0 to " << user_item_map.size() << std::endl;
               throw -1;
           }
        }

        // Write the empty string if no label provided, otherwise pass forward item_label
        out_notebook_cell["item_label"] = item_label;

        // Write the empty object if no 'mapping' provided, otherwise pass forward user_item_map
        out_notebook_cell["user_item_map"] = user_item_map;

        // Pass forward other items
        out_notebook_cell["from_pool"] = in_notebook_cell["from_pool"];
        //TODO: Add support for the other types of points
        if(in_notebook_cell.find("points") != in_notebook_cell.end()){
          out_notebook_cell["points"] = in_notebook_cell["points"];
          out_notebook_cell["non_hidden_non_extra_credit_points"] = in_notebook_cell["points"];
        }
        else {
          out_notebook_cell["points"] = 0;
          out_notebook_cell["non_hidden_non_extra_credit_points"] = 0;
        }
      }
      // Else unknown type was passed in throw exception
      else{
        std::cout << "An unknown notebook cell type was detected in the supplied config.json file: '" << type << "'. Build failed." << std::endl;
        throw -1;
      }

      // Add this newly validated notebook cell to the one being sent forward
      assert(type != "item" || out_notebook_cell.find("item_label") != out_notebook_cell.end());
      complete.push_back(out_notebook_cell);
    }
    return complete;
}





















