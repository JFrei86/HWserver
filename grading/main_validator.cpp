#include <sys/types.h>
#include <sys/stat.h>

#include <typeinfo>

#include <cstdio>
#include <cstdlib>
#include <cstring>
#include <cmath>
#include <unistd.h>

#include <iostream>
#include <fstream>
#include <sstream>

#include <vector>
#include <string>
#include <algorithm>
#include <tclap/CmdLine.h>

#include "TestCase.h"
#include "default_config.h"
#include "load_config_json.h"
#include "execute.h"
#include "json.hpp"

// =====================================================================
// =====================================================================

int validateTestCases(const std::string &hw_id, const std::string &rcsid, int subnum, const std::string &subtime, const bool generate_all_output);

int main(int argc, char *argv[]) {
  std::string hw_id;
  std::string rcsid;
  int subnum;
  std::string time_of_submission;
  bool generate_all_output = false;


  TCLAP::CmdLine cmd("Submitty's assignment validation program.", ' ', "0.9");
  TCLAP::UnlabeledValueArg<std::string> homework_id_argument("homework_id", "The unique id for this gradeable", true, "", "string" , cmd);
  TCLAP::UnlabeledValueArg<std::string> student_id_argument("student_id", "The unique id for this student", true, "", "string" , cmd);
  TCLAP::UnlabeledValueArg<int> submission_number_argument("submission_number", "The numeric value for this assignment attempt", true, -1, "integer" , cmd);
  TCLAP::UnlabeledValueArg<std::string> submission_time_argument("submission_time", "The time at which this submission as made", true, "", "string" , cmd);
  TCLAP::SwitchArg generate_output_argument("g", "generate_all_output", "Run global and all itempool testcases", cmd, false);

  //parse arguments.
  try {
    cmd.parse(argc, argv);
    hw_id = homework_id_argument.getValue();
    rcsid = student_id_argument.getValue();
    subnum = submission_number_argument.getValue();
    time_of_submission = submission_time_argument.getValue();
    generate_all_output = generate_output_argument.getValue();

    std::cout << "hw_id " << hw_id << std::endl;
    std::cout << "rcsid " << rcsid << std::endl;
    std::cout << "subnum " << subnum << std::endl;
    std::cout << "time_of_submission " << time_of_submission << std::endl;
  }
  catch (TCLAP::ArgException &e)  // catch any exceptions
  {
    std::cerr << "INCORRECT ARGUMENTS TO Validator" << std::endl;
    std::cerr << "error: " << e.error() << " for arg " << e.argId() << std::endl;
    return 1;
  }

  int rc = validateTestCases(hw_id,rcsid,subnum,time_of_submission,generate_all_output);
  if (rc > 0) {
    std::cerr << "Validator terminated" << std::endl;
    return 1;
  }

  return 0;
}



bool ShowHelper(const std::string& when, bool success) {
  if (when == "always") return true;
  if (when == "never") return false;
  if (when == "on_success" && success) return true;
  if (when == "on_failure" && !success) return true;
  return false;
}


double ValidateAutoCheck(const TestCase &my_testcase, int which_autocheck, nlohmann::json &autocheck_js,
                         const std::string &hw_id, std::string &testcase_message, nlohmann::json complete_config,
                         const std::string& username) {

  std::cout << "\nAUTOCHECK #" << which_autocheck+1 << " / " << my_testcase.numFileGraders() << std::endl;

  // details on what the grading is
  const nlohmann::json& tcg = my_testcase.getGrader(which_autocheck);

  // do the work
  TestResultsFixedSize result = my_testcase.do_the_grading(which_autocheck, complete_config, username);

  // calculations
  float grade = result.getGrade();
  //std::cout << "  grade=" << grade << "  ";
  assert (grade >= 0.0 && grade <= 1.0);
  double deduction = tcg.value("deduction",1.0);
  assert (deduction >= -0.001 && deduction <= 1.001);
  //std::cout << "deduction_multiplier=" << deduction << "  ";
  double score = deduction*(1-grade);
  //std::cout << "score=" << score << std::endl;

  int full_points = my_testcase.getPoints();
  //  std::cout << "FULL POINTS " << full_points << std::endl;

  bool test_case_success = !(result.hasError() || result.hasWarning() || (deduction > 0.0 && grade < 1.0));
  bool show_message    = ShowHelper(tcg.value("show_message", "never"),test_case_success);
  bool show_actual     = ShowHelper(tcg.value("show_actual",  "never"),test_case_success);
  bool show_image_diff = ShowHelper(tcg.value("show_difference_image",  "never"),test_case_success);
  bool show_expected   = ShowHelper(tcg.value("show_expected","never"),test_case_success);
  std::string BROKEN_CONFIG_ERROR_MESSAGE;

  std::vector<std::string> filenames = stringOrArrayOfStrings(tcg,"actual_file");
  for (int FN = 0; FN < filenames.size(); FN++) {

    // JSON FOR THIS FILE DISPLAY
    nlohmann::json autocheck_j;
    autocheck_j["description"] = tcg.value("description",filenames[FN]);
    if(tcg.find("sequence_diagram") != tcg.end()){
      autocheck_j["display_as_sequence_diagram"] = tcg.value("sequence_diagram", false);
    }
    bool actual_file_to_print = false;
    std::string autocheckid = std::to_string(which_autocheck);
    if (filenames.size() > 1) {
      autocheckid += "_" + std::to_string(FN);
    }
    if (my_testcase.isCompilation() && autocheck_j.value("description","") == "Create Executable") {
      // MISSING EXECUTABLE
    } else {
      std::string actual_file = filenames[FN];
      std::vector<std::string> files;
      // try with and without the prefix
      wildcard_expansion(files, my_testcase.getPrefix() + actual_file, std::cout);
      if (files.size() == 0) {
        wildcard_expansion(files, actual_file, std::cout);
      }
      for (int i = 0; i < files.size(); i++) {
        actual_file = files[i];
        std::cout << "FILE MATCH " << files[i] << std::endl;
      }
      bool studentFileExists, studentFileEmpty;
      bool expectedFileExists=false, expectedFileEmpty=false;
      fileStatus(actual_file, studentFileExists,studentFileEmpty);
      std::string expected_file;
      std::string expected_string;
      std::string expected;
      if (studentFileExists) {
        if (show_actual) {
          autocheck_j["actual_file"] = actual_file;
          // pdf files must be loaded from results public
          // TODO: error checking, help debug if the config is missing the appropriate "work_to_public" statements
          if (actual_file.substr(actual_file.size()-4,4) == ".pdf") {
            autocheck_j["results_public"] = true;
          }
        }
        expected_file = tcg.value("expected_file", "");
        expected_string = tcg.value("expected_string", "");
        assert(!(expected_file != "" && expected_string != ""));
        if (expected_file != "") {
          expected = expected_file;
        } else if (expected_string != "") {
          //if expected file doesn't exist, use actual_file name to get the name of the expected string output file
          //ex: actual_file = math_1.txt, expected = expected_string_math_1.txt
          expected = "expected_string_" + actual_file.substr(actual_file.find('/')+1);
        }
        std::cout << "expected: " << expected << std::endl;
        std::cout << "expected_file: " << expected_file << std::endl;
        std::cout << "expected_string: " << expected_string << std::endl;
        std::cout << "actual_file: " << actual_file << std::endl;


        if (expected != "") {
          std::string expectedWithFolder = getOutputContainingFolderPath(my_testcase, expected) + expected;
          fileStatus(expectedWithFolder, expectedFileExists,expectedFileEmpty);
          std::cout << "expectedFileExists: " << expectedFileExists << std::endl;
          if (!expectedFileExists) {
            BROKEN_CONFIG_ERROR_MESSAGE = "ERROR!  Expected File '" + expected + "' does not exist";
            std::cout << BROKEN_CONFIG_ERROR_MESSAGE << std::endl;
          }
          else {
            // PREPARE THE JSON DIFF FILE
            std::stringstream diff_path;
            diff_path << my_testcase.getPrefix() << which_autocheck << "_diff.json";
            std::ofstream diff_stream(diff_path.str().c_str());
            result.printJSON(diff_stream);
            std::stringstream expected_path;
            std::string id = hw_id;
            std::string expected_out_dir = getPathForOutputFile(my_testcase, expected, id);
            std::cout << "expected_out_dir: " << expected_out_dir << std::endl;
            expected_path << expected_out_dir << expected;
            std::cout << "expected_path: " << expected_path.str() << std::endl;
            if (show_expected) {
             autocheck_j["expected_file"] = expected_path.str();
            }
            if (show_image_diff){
              autocheck_j["image_difference_file"] = my_testcase.getPrefix() + tcg.value("image_difference_file", std::to_string(which_autocheck) + "_difference.png");
            }
            if (show_actual) {
             autocheck_j["difference_file"] = my_testcase.getPrefix() + std::to_string(which_autocheck) + "_diff.json";
            }
          }
        }
      }
      if (studentFileExists && !studentFileEmpty) {
        actual_file_to_print = true;
      }
    }

    std::vector<std::pair<TEST_RESULTS_MESSAGE_TYPE, std::string> > messages = result.getMessages();

    if (BROKEN_CONFIG_ERROR_MESSAGE != "") {
      messages.push_back(std::make_pair(MESSAGE_FAILURE,BROKEN_CONFIG_ERROR_MESSAGE));
    }

    std::string fm = tcg.value("failure_message","");
    if (!test_case_success) {
      bool failure_message_already_added = false;
      if (FN==0) {
        for (int m = 0; m < messages.size(); m++) {
          assert (messages[m].second != "");
          if (messages[m].second == fm) failure_message_already_added = true;
          nlohmann::json new_message;
          new_message["message"] = messages[m].second;
          if (messages[m].first == MESSAGE_FAILURE) new_message["type"] = "failure";
          else if (messages[m].first == MESSAGE_WARNING) new_message["type"] = "warning";
          else if (messages[m].first == MESSAGE_SUCCESS) new_message["type"] = "success";
          else { assert (messages[m].first == MESSAGE_INFORMATION); new_message["type"] = "information"; }
          autocheck_j["messages"].push_back(new_message);
        }
      }
      if (fm != "" && !failure_message_already_added) {
        nlohmann::json new_message;
        new_message["message"] = fm;
        new_message["type"] = "failure";
        autocheck_j["messages"].push_back(new_message);
      }
    }

    std::cout << "AUTOCHECK GRADE " << grade << std::endl;
    int num_messages = 0;
    if (autocheck_j.find("messages") != autocheck_j.end()) {
      num_messages = autocheck_j.find("messages")->size();
      assert (num_messages > 0);
    }

    if ((show_message && num_messages > 0)
        || show_actual
        || show_expected) {
      autocheck_js.push_back(autocheck_j);
      if (my_testcase.isFileCheck() && num_messages > 0 && messages.size() > 0 && messages[0].second.find("README") != std::string::npos) {
        testcase_message = "README missing.";
      } else if (my_testcase.isCompilation() && num_messages > 0) {
        testcase_message = "Compilation Errors and/or Warnings.";
      }
    }
  }
  return score;
}


void WriteToResultsJSON(const TestCase &my_testcase,
                        const std::string &title,
                        bool view_testcase,
                        nlohmann::json& autocheck_js,
                        const std::string &testcase_label,
                        const std::string &testcase_message,
                        int testcase_pts,
                        nlohmann::json &all_testcases) {

  nlohmann::json tc_j;
  tc_j["test_name"] = title;

  if (view_testcase == false) {
    tc_j["view_testcase"] = view_testcase;
  }
  if (autocheck_js.size() > 0) {
    tc_j["autochecks"] = autocheck_js;
  }

  if (testcase_label != "") tc_j["testcase_label"] = testcase_label;

  if (testcase_message != "") tc_j["testcase_message"] = testcase_message;
  tc_j["points_awarded"] = testcase_pts;

  all_testcases.push_back(tc_j);
}


void WriteToGradefile(const TestCase &my_testcase,std::ofstream& gradefile,int testcase_pts) {
    gradefile << my_testcase.getID() << ": "
              << std::setw(50) << std::left << my_testcase.getTitle() << " ";
    if (my_testcase.getExtraCredit()) {
      if (testcase_pts > 0) {
        gradefile << std::setw(3) << std::right << "+"+std::to_string(testcase_pts) << " points";
      } else {
        gradefile << std::setw(10) << "";
      }
    } else if (my_testcase.getPoints() < 0) {
      if (testcase_pts < 0) {
        gradefile << std::setw(3) << std::right << std::to_string(testcase_pts) << " points";
      } else {
        gradefile << std::setw(10) << "";
      }
    } else {
      gradefile << std::setw(3) << std::right << testcase_pts << " /"
                << std::setw(3) << std::right << my_testcase.getPoints()
                << std::setw(2) << "";
    }
    if (my_testcase.getHidden()) {
      gradefile << "  [ HIDDEN ]";
    }
    gradefile << std::endl;
}


void ValidateATestCase(nlohmann::json config_json, const TestCase my_testcase,
                       int subnum, const std::string &hw_id,
                       int &automated_points_awarded,
                       int &automated_points_possible,
                       int &nonhidden_automated_points_awarded,
                       int &nonhidden_automated_points_possible,
                       int &max_penalty_possible,
                       nlohmann::json &all_testcases,
                       std::ofstream& gradefile,
                       const std::string& username) {
    //This input to the testcase constructor does nothing unless we attempt to access the 'commands' object.
    std::string container_name = "";
    std::string title = my_testcase.getTitle();
    int possible_points = my_testcase.getPoints();
    bool allow_partial_credit = my_testcase.allowPartialCredit();
    std::cout << title << " - points: " << possible_points << std::endl;
    std::string testcase_message = "";
    nlohmann::json autocheck_js;
    int testcase_pts = 0;
    bool view_testcase = true;

    if (my_testcase.isSubmissionLimit()) {
      /////////////////////////////////////////////////////////////////////////
      //
      // NOTE: Editing this? Make sure this branch stays in-sync with the
      //       check_submission_limit_penalty_inline() function in
      //       autograder/submitty_autograding_shipper.py
      //
      /////////////////////////////////////////////////////////////////////////
      int max = my_testcase.getMaxSubmissions();
      float penalty = my_testcase.getPenalty();
      assert (penalty <= 0);
      int excessive_submissions = std::max(0,subnum-max);
      // round down to the biggest negative full point penalty
      testcase_pts = std::floor(excessive_submissions * penalty);
      if (testcase_pts < possible_points) testcase_pts = possible_points;
      if (testcase_pts == 0) {
        view_testcase = false;
      } else {
        std::cout << "EXCESSIVE SUBMISSIONS PENALTY = " << testcase_pts << std::endl;
      }
    }
    else {
      double my_score = 1.0;
      std::cout << "NUM AUTOCHECKS / FILE GRADERS " << my_testcase.numFileGraders() << std::endl;
      assert (my_testcase.numFileGraders() > 0);
      for (int which_autocheck = 0; which_autocheck < my_testcase.numFileGraders(); which_autocheck++) {
        my_score -= ValidateAutoCheck(my_testcase, which_autocheck, autocheck_js, hw_id, testcase_message,config_json, username);
      }
      bool fileExists, fileEmpty;
      std::string execute_logfile = my_testcase.getPrefix() + "execute_logfile.txt";
      fileStatus(execute_logfile, fileExists,fileEmpty);
      bool show_execute_logfile = my_testcase.ShowExecuteLogfile("execute_logfile.txt");
      if (fileExists && !fileEmpty && show_execute_logfile) {
        nlohmann::json autocheck_j;
        autocheck_j["actual_file"] = my_testcase.getPrefix() + "execute_logfile.txt";
        autocheck_j["description"] = "Execution Logfile";
        autocheck_js.push_back(autocheck_j);
      }
      assert (my_score <= 1.00002);
      my_score += 0.00001;
      assert (my_score <= 1.00002);
      my_score = std::max(0.0,std::min(1.0,my_score));
      std::cout << "[ FINISHED ] my_score = " << my_score << std::endl;
      if (!allow_partial_credit && my_score < 0.99999) {
        std::cout << "PARTIAL CREDIT NOT ALLOWED FOR THIS TEST CASE" << my_score << " -> 0.0" << std::endl;
        my_score = 0;
      }
      testcase_pts = my_score * possible_points;
      std::cout << "thing " << testcase_pts << " " << my_score * possible_points << std::endl;
      std::cout << "Grade: " << testcase_pts << std::endl;
    }

    // UPDATE CUMMULATIVE POINTS
    automated_points_awarded += testcase_pts;
    if (!my_testcase.getHidden()) {
      nonhidden_automated_points_awarded += testcase_pts;
    }
    if (possible_points > 0 && !my_testcase.getExtraCredit()) {
      automated_points_possible += possible_points;
      if (!my_testcase.getHidden()) {
        nonhidden_automated_points_possible += possible_points;
      }
    }
    if (possible_points < 0) {
      max_penalty_possible += possible_points;
    }

    // EXPORT TO results.json and grade.txt
    WriteToResultsJSON(
    my_testcase,
    title,
    view_testcase,
    autocheck_js,
    my_testcase.getTestcaseLabel(),
    testcase_message,
    testcase_pts,
    all_testcases);
    WriteToGradefile(my_testcase,gradefile,testcase_pts);
}

/**
* Get all testcase objects associated with an assignment.
*/
std::vector<TestCase> getAllTestcases(nlohmann::json config_json) {
  std::vector<TestCase> testcase_array;
  nlohmann::json::const_iterator testcases = config_json.find("testcases");
  assert (testcases != config_json.end());
  for (nlohmann::json::const_iterator tc = testcases->begin(); tc != testcases->end(); tc++) {
    std::string testcase_id = tc->value("testcase_id", "");
    assert(testcase_id != "");
    nlohmann::json testcase_config = *tc;
    TestCase my_testcase(testcase_config, testcase_id, "");
    testcase_array.push_back(my_testcase);
  }

  nlohmann::json::const_iterator item_pool = config_json.find("item_pool");
  if (item_pool != config_json.end()){
    for(nlohmann::json::const_iterator item_itr = item_pool->begin(); item_itr != item_pool->end(); item_itr++) {
      nlohmann::json item_testcases = item_itr->value("testcases", nlohmann::json::array());
      for(nlohmann::json::const_iterator it_itr = item_testcases.begin(); it_itr != item_testcases.end(); it_itr++) {
        std::string testcase_id = it_itr->value("testcase_id", "");
        assert(testcase_id != "");
        nlohmann::json testcase_config = *it_itr;
        TestCase my_testcase(testcase_config, testcase_id, "");
        testcase_array.push_back(my_testcase);
      }
    }
  }

  return testcase_array;
}

nlohmann::json getItemWithName(std::string item_name, const nlohmann::json& config_json) {

    nlohmann::json::const_iterator itempool = config_json.find("item_pool");
    for (nlohmann::json::const_iterator item = itempool->begin(); item != itempool->end(); item++) {
        if((*item)["item_name"] == item_name) {
          return *item;
        }
    }
    throw "ERROR: Could not find an item with item_name " + item_name;
}


/**
* Get all global testcase objects for an assignment + all testcases specified in .submit.notebook
*/
std::vector<TestCase> getTestcasesForSubmission(nlohmann::json& config_json) {

  std::vector<TestCase> testcase_array;
  nlohmann::json::iterator testcases = config_json.find("testcases");
  assert (testcases != config_json.end());
  for (nlohmann::json::iterator tc = testcases->begin(); tc != testcases->end(); tc++) {
    std::string testcase_id = tc->value("testcase_id", "");
    assert(testcase_id != "");
    nlohmann::json testcase_config = *tc;
    TestCase my_testcase(testcase_config, testcase_id, "");
    testcase_array.push_back(my_testcase);
  }

  std::ifstream ifs(".submit.notebook");
  if(ifs.is_open()) {
    nlohmann::json notebook_data = nlohmann::json::parse(ifs);
    nlohmann::json item_selections = notebook_data.value("item_pools_selected", nlohmann::json::array());
    int current_item = 0;
    nlohmann::json::iterator notebook = config_json.find("notebook");

    for (nlohmann::json::iterator part = notebook->begin(); part != notebook->end(); part++) {
      std::string type = part->value("type", "");
      std::cout << "type was " << type << std::endl;
      if (type != "item") {
        continue;
      }

      nlohmann::json item_config;
      int points = part->value("points", 0);

      // If this is an "item," find out what we selected for it using the item_selections iterator
      assert(current_item < item_selections.size());
      assert(item_selections[current_item].is_string());
      std::string item_selected = item_selections[current_item];
      current_item++;

      try {
        item_config = getItemWithName(item_selected, config_json);
      } catch(char* c) {
        std::cout << c << std::endl;
        continue;
      }

      nlohmann::json::iterator item_testcases = item_config.find("testcases");

      if(item_testcases == item_config.end() || item_config["testcases"].size() == 0) {
        continue;
      }

      if(item_testcases->find("points") == item_testcases->end()) {
        (*item_testcases)[0]["points"] = points;
      }

      for(nlohmann::json::iterator item_testcase = item_testcases->begin(); item_testcase != item_testcases->end(); item_testcase++) {
        TestCase my_testcase(*item_testcase, (*item_testcase)["testcase_id"], "");
        testcase_array.push_back(my_testcase);
      }
    }
  }

  return testcase_array;
}


/* Runs through each test case, pulls in the correct files, validates, and outputs the results */
int validateTestCases(const std::string &hw_id, const std::string &rcsid, int subnum, const std::string &subtime, const bool generate_all_output) {

  ///////////////////////////////////////////////////////////////////////////////
  //
  // NOTE: Editing this file? Make sure that the parts of this function that
  //       write to the output files stay in-sync with the write_grading_outputs
  //       function in autograder/submitty_autograding_shipper.py
  //
  ///////////////////////////////////////////////////////////////////////////////

  // LOAD HW CONFIGURATION JSON

  nlohmann::json config_json = LoadAndCustomizeConfigJson(rcsid);
  // PREPARE GRADE.TXT FILE
  std::string grade_path = "grade.txt";
  std::ofstream gradefile(grade_path.c_str());

  int automated_points_awarded = 0;
  int automated_points_possible = 0;
  int nonhidden_automated_points_awarded = 0;
  int nonhidden_automated_points_possible = 0;
  int max_penalty_possible = 0;

  std::stringstream testcase_json;
  nlohmann::json all_testcases;



  system("find . -type f -exec ls -sh {} +");


  // =======================================
  // Loop over all global testcases
  std::vector<TestCase> testcases;
  if (generate_all_output) {
    testcases = getAllTestcases(config_json);
  } else {
    testcases = getTestcasesForSubmission(config_json);
  }

  for (std::vector<TestCase>::iterator tc = testcases.begin(); tc != testcases.end(); tc++) {
    std::cout << "------------------------------------------\n";
    ValidateATestCase(config_json, *tc,
                      subnum,hw_id,
                      automated_points_awarded,
                      automated_points_possible,
                      nonhidden_automated_points_awarded,
                      nonhidden_automated_points_possible,
                      max_penalty_possible,
                      all_testcases,
                      gradefile,
                      rcsid);
  }

  nlohmann::json grading_parameters = config_json.value("grading_parameters",nlohmann::json::object());
  int AUTO_POINTS         = grading_parameters.value("AUTO_POINTS",automated_points_possible);
  assert (AUTO_POINTS == automated_points_possible);
  int EXTRA_CREDIT_POINTS = grading_parameters.value("EXTRA_CREDIT_POINTS",0);
  int PENALTY_POINTS = grading_parameters.value("PENALTY_POINTS",max_penalty_possible);

  // clamp total to zero (no negative total!)
  automated_points_awarded = std::max(PENALTY_POINTS,automated_points_awarded);
  nonhidden_automated_points_awarded = std::max(PENALTY_POINTS,nonhidden_automated_points_awarded);

  // Generate results.json
  nlohmann::json sj;
  sj["testcases"] = all_testcases;
  sj["automatic_grading_total"] = automated_points_awarded;
  sj["nonhidden_automatic_grading_total"] = nonhidden_automated_points_awarded;
  std::ofstream json_file("results.json");
  json_file << sj.dump(4);

  // final line of results_grade.txt
  gradefile << std::setw(64) << std::left << "Automatic grading total:"
            << std::setw(3) << std::right << automated_points_awarded
            << " /" <<  std::setw(3)
            << std::right << automated_points_possible << std::endl;

  gradefile << std::setw(64) << std::left << "Non-hidden automatic grading total:"
            << std::setw(3) << std::right << nonhidden_automated_points_awarded
            << " /" <<  std::setw(3)
            << std::right << nonhidden_automated_points_possible << std::endl;

  return 0;
}


// =====================================================================
// =====================================================================
