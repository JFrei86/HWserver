#ifndef __testResults__
#define __testResults__

#include <cassert>
#include <iostream>
#include <fstream>
#include <string>
#include <vector>
#include <utility>
#include <string.h>
#include <sstream>

#define TEST_RESULT_DIFF_SIZE 1000000
#define TEST_RESULT_MESSAGES_SIZE 10000


// ===================================================================================
// ===================================================================================
// helper class needed to allow validation to use shared memory
//
class TestResultsFixedSize {
public:

  void initialize() {
    distance = 0;
    grade = 0;
    compilation_error = false;
    compilation_warning = false;
    strcpy(diff,"");
    char default_message[] = "ERROR: TestResults not initialized.\nProbably caused by error in validation.\nIf you cannot debug the issue, ask your instructor to check results_log_validator.txt";
    assert (strlen(default_message) < TEST_RESULT_MESSAGES_SIZE-1);
    strcpy(messages,default_message);

    char default_message2[] = "ERROR: TestResults not initialized. Probably caused by error in validation. If you cannot debug the issue, ask your instructor to check results_log_validator.txt\nfailure";
    assert (strlen(default_message2) < TEST_RESULT_MESSAGES_SIZE-1);
    strcpy(messages2,default_message2);
  }

  void PACK(std::string d, int dist, std::vector<std::string> m, std::vector<std::pair<std::string, std::string> > m2, float g, bool ce, bool cw) {
    grade = g;
    distance = dist;
    compilation_error = ce;
    compilation_warning = cw;
    if (d.size() >= TEST_RESULT_DIFF_SIZE-1) {
      m.push_back("ERROR: diff too large to calculate/display.");
      m2.push_back(std::make_pair("ERROR: diff too large to calculate/display.", "failure"));
      strcpy(diff,"");
    } else {
      assert (d.size() < TEST_RESULT_DIFF_SIZE-1);
      strcpy(diff,d.c_str());
    }
    std::string tmp = "";
    for (int i = 0; i < m.size(); i++) {
      if (i != 0) tmp += "\n";
      tmp += m[i];
    }
    std::string tmp2 = "";
    for (int i = 0; i < m2.size(); i++) {
      if (i != 0) tmp2 += "\n";
      tmp2 += std::get<0>(m2[i]);
      tmp2 += "\n";
      tmp2 += std::get<1>(m2[i]);
    }
    if (tmp.size() >= TEST_RESULT_MESSAGES_SIZE-1) {
      char default_message[] = "ERROR: messages too large to display.";
      assert (strlen(default_message) < TEST_RESULT_MESSAGES_SIZE-1);
      strcpy(messages,default_message);
    } else {
      assert (tmp.size() < TEST_RESULT_MESSAGES_SIZE-1);
      strcpy(messages,tmp.c_str());
    }
    if (tmp2.size() >= TEST_RESULT_MESSAGES_SIZE-1) {
      char default_message2[] = "ERROR: messages too large to display.\nfailure";
      assert (strlen(default_message2) < TEST_RESULT_MESSAGES_SIZE-1);
      strcpy(messages2,default_message2);
    } else {
      assert (tmp2.size() < TEST_RESULT_MESSAGES_SIZE-1);
      strcpy(messages2,tmp2.c_str());
    }
  }
  virtual void printJSON(std::ostream & file_out) {
    if (strcmp(diff,"") != 0) {
      file_out << diff << std::endl;
    } else {
      file_out << "{" << std::endl;
      file_out << "}" << std::endl;
    }
  }
  float getGrade() const { return grade; }
  const std::vector<std::string> getMessages() const { 
    int length = strlen(messages);
    std::vector<std::string> answer;
    std::string tmp;
    for (int i = 0; i < length; i++) {
      if (messages[i] == '\n') {
        if (tmp != "") {
          answer.push_back(tmp);
          tmp = "";
        }
      } else {
        tmp.push_back(messages[i]);
      }
    }
    if (tmp != "") {
      answer.push_back(tmp);
      tmp = "";
    }
    return answer;
  }
  const std::vector<std::pair<std::string, std::string> > getMessages2() const { 
    int length = strlen(messages2);
    std::vector<std::pair<std::string, std::string> > answer;
    std::string msg = "";
    std::string color = "";
    for (int i = 0; i < length; i++) {
      if (messages2[i] == '\n') {
        if (msg != "" && color != "") {
          answer.push_back(std::make_pair(msg,color));
          msg = "";
          color = "";
        } else if (color == "") {
          color.push_back(messages2[i]);
        }
      } else if (color == ""){
        msg.push_back(messages2[i]);
      } else {
        color.push_back(messages2[i]);
      }
    }
    if (msg != "" && color != "") {
      answer.push_back(std::make_pair(msg,color));
      msg = "";
      color = "";
    }
    return answer;
  }

  bool hasCompilationError() const { return compilation_error; }
  bool hasCompilationWarning() const { return compilation_warning; }

  friend std::ostream& operator<< (std::ostream& ostr, const TestResultsFixedSize &tr) {
    ostr << "TR grade=" << tr.grade << " messages='" << tr.messages << "'";
    return ostr;
  }

private:
  int distance;
  float grade;
  char diff[TEST_RESULT_DIFF_SIZE];
  char messages[TEST_RESULT_MESSAGES_SIZE];
  char messages2[TEST_RESULT_MESSAGES_SIZE];
  bool compilation_error;
  bool compilation_warning;
};

// ==============================================================================================

class TestResults {
public:

  // CONSTRUCTOR
  TestResults(float g=0.0, 
              const std::vector<std::string> &m = {},
              const std::string &sd="",
              bool ce=false,
              bool cw=false, 
              const std::vector<std::pair<std::string, std::string> > &m2 = {}) :
    my_grade(g),swap_difference(sd),distance(0) {
    for (int i= 0; i < m.size(); i++) {
      if (m[i].size() != 0) {
        messages.push_back(m[i]);
      } else {
        std::cout << "warning: a blank message string" << std::endl;
      }
    }
    for (int i= 0; i < m2.size(); i++) {
      if (std::get<0>(m2[i]).size() != 0) {
        messages2.push_back(m2[i]);
      } else {
        std::cout << "warning: a blank message string" << std::endl;
      }
    }
    compilation_error = ce;
    compilation_warning = cw;
  }
  
  virtual void printJSON(std::ostream & file_out) {
    if (swap_difference != "") {
      file_out << swap_difference << std::endl;
    } else {
      file_out << "{" << std::endl;
      file_out << "}" << std::endl;
    }
  }

  // ACCESSORS
  float getGrade() const { assert (my_grade >= 0); return my_grade; }
  const std::vector<std::string>& getMessages() const { return messages; }
  const std::vector<std::pair<std::string, std::string> >& getMessages2() const { return messages2; }
  int getDistance() const { return distance; }
  bool hasCompilationError() const { return compilation_error; }
  bool hasCompilationWarning() const { return compilation_warning; }

  // MODIFIERS
  void setGrade(float g) { assert (g >= 0); my_grade = g; }
  void setDistance(int d) { distance = d; }

  void PACK(TestResultsFixedSize* ptr) {
    std::string myself;
    std::stringstream tmp;
    printJSON(tmp);
    myself = tmp.str();
    ptr->PACK(myself,distance,messages,messages2,my_grade,compilation_error,compilation_warning);
  }

protected:

  // REPRESENTATION
  std::vector<std::string> messages;
  std::vector<std::pair<std::string, std::string> > messages2;
  float my_grade;
  std::string swap_difference;
  int distance;
  bool compilation_warning;
  bool compilation_error;

};

// ===================================================================================

#endif
