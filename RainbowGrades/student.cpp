#include "student.h"

const std::string GradeColor(const std::string &grade);

// =============================================================================================
// =============================================================================================
// CONSTRUCTOR

Student::Student() { 

  // personal data
  // (defaults to empty string)

  // registration status
  section = 0;  
  audit = false;
  withdraw = false;
  independentstudy = false;

  // grade data
  for (unsigned int i = 0; i < ALL_GRADEABLES.size(); i++) { 
    GRADEABLE_ENUM g = ALL_GRADEABLES[i];
    all_values[g]       = std::vector<float>(GRADEABLES[g].getCount(),0);
    all_notes[g]       = std::vector<std::string>(GRADEABLES[g].getCount(),"");
  }
  // (iclicker defaults to empty map)
  hws_late_days                             = std::vector<float>(GRADEABLES[GRADEABLE_ENUM::HOMEWORK].getCount(),0);
  zones = std::vector<std::string>(GRADEABLES[GRADEABLE_ENUM::TEST].getCount(),"");
  moss_penalty = 0;
  cached_hw = -1;

  // other grade-like data
  // (remote id defaults to empty string)
  academic_integrity_form = false;
  participation = 0;
  understanding = 0;

  // info about exam assignments
  // (defaults to empty string)

  // per student notes
  // (defaults to empty string)
}




// lookup a student by username
Student* GetStudent(const std::vector<Student*> &students, const std::string& username) {
  for (unsigned int i = 0; i < students.size(); i++) {
    if (students[i]->getUserName() == username) return students[i];
  }
  return NULL;
}





// =============================================================================================
// =============================================================================================
// accessor & modifier for grade data

float Student::getGradeableValue(GRADEABLE_ENUM g, int i) const {
  assert (i >= 0 && i < GRADEABLES[g].getCount());
  std::map<GRADEABLE_ENUM,std::vector<float> >::const_iterator itr = all_values.find(g);
  assert (itr != all_values.end());
  assert (int(itr->second.size()) > i);

  float value = itr->second[i];
  if (g == GRADEABLE_ENUM::HOMEWORK && LATE_DAY_PERCENTAGE_PENALTY > 0) {
    int d = getUsedLateDays(i);

    // grab the maximum score for this homework
    assert (PERFECT_STUDENT_POINTER != NULL);
    std::map<GRADEABLE_ENUM,std::vector<float> >::const_iterator ps_itr = PERFECT_STUDENT_POINTER->all_values.find(g);
    assert (ps_itr != all_values.end());
    float ps_value = ps_itr->second[i];

    // adjust the homework score
    value = std::max(0.0f, value - d*LATE_DAY_PERCENTAGE_PENALTY*ps_value);
  }

  return value; 
}


void Student::setGradeableValue(GRADEABLE_ENUM g, int i, float value) {
  assert (i >= 0 && i < GRADEABLES[g].getCount());
  std::map<GRADEABLE_ENUM,std::vector<float> >::iterator itr = all_values.find(g);
  assert (itr != all_values.end());
  assert (int(itr->second.size()) > i);
  itr->second[i] = value;
}

const std::string& Student::getGradeableNote(GRADEABLE_ENUM g, int i) const {
  assert (i >= 0 && i < GRADEABLES[g].getCount());
  std::map<GRADEABLE_ENUM,std::vector<std::string> >::const_iterator itr = all_notes.find(g);
  assert (itr != all_notes.end());
  assert (int(itr->second.size()) > i);
  return itr->second[i];
}

void Student::setGradeableNote(GRADEABLE_ENUM g, int i, const std::string &note) {
  assert (i >= 0 && i < GRADEABLES[g].getCount());
  std::map<GRADEABLE_ENUM,std::vector<std::string> >::iterator itr = all_notes.find(g);
  assert (itr != all_notes.end());
  assert (int(itr->second.size()) > i);
  itr->second[i] = note;
}


// =============================================================================================
// GRADER CALCULATION HELPER FUNCTIONS

extern std::vector<std::vector<std::string> > HACKMAXPROJECTS;

float Student::GradeablePercent(GRADEABLE_ENUM g) const {
  if (GRADEABLES[g].getCount() == 0) return 0;
  assert (GRADEABLES[g].getMaximum() > 0);
  assert (GRADEABLES[g].getPercent() > 0);

  // special rules for tests
  if (g == GRADEABLE_ENUM::TEST && TEST_IMPROVEMENT_AVERAGING_ADJUSTMENT) {
    return adjusted_test_pct();
  }
  if (g == GRADEABLE_ENUM::TEST && LOWEST_TEST_COUNTS_HALF) {
    return lowest_test_counts_half_pct();
  }


  // ============================================================================
  // end special rule for projects for op sys
  // HACK, NOT PERMANENT!

  if (g == GRADEABLE_ENUM::PROJECT && HACKMAXPROJECTS.size() > 0) {

    // collect the scores in a vector
    std::map<std::string,float> scores;

    for (int i = 0; i < GRADEABLES[g].getCount(); i++) {
      float my_value = getGradeableValue(g,i);
      std::string my_id = GRADEABLES[g].getID(i);
      std::cout << "PROJECT THING val=" << my_value << " id=" << my_id << std::endl;
      scores[my_id] = my_value;
    }

    // sum the projects
    float sum = 0;
    for (unsigned int i = 0; i < HACKMAXPROJECTS.size(); i++) {
      float my_max = 0;
      for (unsigned int j = 0; j < HACKMAXPROJECTS[i].size(); j++) {
        my_max = std::max(my_max,scores[HACKMAXPROJECTS[i][j]]);
      }
      std::cout << "i=" << i << " max=" << my_max << std::endl;
      sum += my_max;
    }
    std::cout << "sum=" << sum << std::endl;

    return 100*GRADEABLES[g].getPercent()*sum/GRADEABLES[g].getMaximum();

  }
  // HACK, NOT PERMANENT!
  // end special rule for projects for op sys
  // ============================================================================


  // collect the scores in a vector
  std::vector<float> scores;
  for (int i = 0; i < GRADEABLES[g].getCount(); i++) {
    scores.push_back(getGradeableValue(g,i));
  }

  // sort the scores (smallest first)
  std::sort(scores.begin(),scores.end());

  assert (GRADEABLES[g].getRemoveLowest() >= 0 &&
          GRADEABLES[g].getRemoveLowest() < GRADEABLES[g].getCount());

  // sum the remaining (higher) scores
  float sum = 0;
  for (int i = GRADEABLES[g].getRemoveLowest(); i < GRADEABLES[g].getCount(); i++) {
    sum += scores[i];
  }

  return 100*GRADEABLES[g].getPercent()*sum/GRADEABLES[g].getMaximum();
}




float Student::adjusted_test(int i) const {
  assert (i >= 0 && i <  GRADEABLES[GRADEABLE_ENUM::TEST].getCount());
  float a = getGradeableValue(GRADEABLE_ENUM::TEST,i);
  float b;
  if (i+1 < GRADEABLES[GRADEABLE_ENUM::TEST].getCount()) {
    b = getGradeableValue(GRADEABLE_ENUM::TEST,i+1);
  } else {
    assert (GRADEABLES[GRADEABLE_ENUM::EXAM].getCount() == 1);
    b = getGradeableValue(GRADEABLE_ENUM::EXAM,0);
    // HACK  need to scale the final exam!
    b *= 0.6667;
  }

  if (a > b) return a;
  return (a+b) * 0.5;
}


float Student::adjusted_test_pct() const {
  float sum = 0;
  for (int i = 0; i < GRADEABLES[GRADEABLE_ENUM::TEST].getCount(); i++) {
    sum += adjusted_test(i);
  }
  float answer =  100 * GRADEABLES[GRADEABLE_ENUM::TEST].getPercent() * sum / float (GRADEABLES[GRADEABLE_ENUM::TEST].getMaximum());
  return answer;
}


float Student::lowest_test_counts_half_pct() const {

  int num_tests = GRADEABLES[GRADEABLE_ENUM::TEST].getCount();
  assert (num_tests > 0);

  // first, collect & sort the scores
  std::vector<float> scores;
  for (int i = 0; i < num_tests; i++) {
    scores.push_back(getGradeableValue(GRADEABLE_ENUM::TEST,i));
  }
  std::sort(scores.begin(),scores.end());

  // then sum the scores 
  float sum = 0.5 * scores[0];
  float weight_total = 0.5;
  for (int i = 1; i < num_tests; i++) {
    sum += scores[i];
    weight_total += 1.0;
  }
  
  // renormalize!
  sum *= float(num_tests) / weight_total;
  
  // scale to percent;
  return 100 * GRADEABLES[GRADEABLE_ENUM::TEST].getPercent() * sum / float (GRADEABLES[GRADEABLE_ENUM::TEST].getMaximum());
}


// =============================================================================================
// =============================================================================================

int Student::getAllowedLateDays(int which_lecture) const {
  if (getSection() == 0) return 0;
  
  int answer = 2;
  
  // average 4 questions per lecture 2-28 ~= 112 clicker questions
  //   30 questions => 3 late days
  //   60 questions => 4 late days
  //   90 qustions  => 5 late days
  
  float total = getIClickerTotal(which_lecture,0);
  
  for (unsigned int i = 0; i < bonus_late_days_which_lecture.size(); i++) {
    if (bonus_late_days_which_lecture[i] <= which_lecture) {
      answer ++;
    }
  }


  //return answer += int(total / 30); //25);
  return answer += int(total / 25);
  
}


// get the used late days for a particular homework
int Student::getUsedLateDays(int which) const {
  if (getSection() == 0) return 0;
  assert (which >= 0 && which < GRADEABLES[GRADEABLE_ENUM::HOMEWORK].getCount());
  return hws_late_days[which];
}

// get the total used late days
int Student::getUsedLateDays() const {
  int answer = 0;
  for (int i = 0; i < GRADEABLES[GRADEABLE_ENUM::HOMEWORK].getCount(); i++) {
    answer += getUsedLateDays(i);
  }
  return answer;
}

// =============================================================================================

float Student::overall_b4_moss() const {
  float answer = 0;
  for (unsigned int i = 0; i < ALL_GRADEABLES.size(); i++) { 
    GRADEABLE_ENUM g = ALL_GRADEABLES[i];
    answer += GradeablePercent(g);
  }
  return answer;
}

std::string Student::grade(bool flag_b4_moss, Student *lowest_d) const {

  if (section == 0) return "";
  if (section == 11) return "";  // fake section
  if (section == 12) return "";  // fake section

  if (!flag_b4_moss && manual_grade != "") return manual_grade;
  
  float over = overall();
  if (flag_b4_moss) {
    over = overall_b4_moss();
  }


  // some criteria that might indicate automatica failure of course
  // (instructor can override with manual grade)
  int failed_lab   = (GradeablePercent(GRADEABLE_ENUM::LAB)       < 1.01 * lowest_d->GradeablePercent(GRADEABLE_ENUM::LAB)       ) ? true : false;
  int failed_hw    = (GradeablePercent(GRADEABLE_ENUM::HOMEWORK)  < 0.95 * lowest_d->GradeablePercent(GRADEABLE_ENUM::HOMEWORK)  ) ? true : false;
  int failed_testA = (GradeablePercent(GRADEABLE_ENUM::TEST)      < 0.90 * lowest_d->GradeablePercent(GRADEABLE_ENUM::TEST)      ) ? true : false;
  int failed_testB = (GradeablePercent(GRADEABLE_ENUM::EXAM)      < 0.90 * lowest_d->GradeablePercent(GRADEABLE_ENUM::EXAM)      ) ? true : false;
  int failed_testC = (GradeablePercent(GRADEABLE_ENUM::TEST) + GradeablePercent(GRADEABLE_ENUM::EXAM) < 
                      0.90 * lowest_d->GradeablePercent(GRADEABLE_ENUM::TEST) + lowest_d->GradeablePercent(GRADEABLE_ENUM::EXAM) ) ? true : false;
  if (failed_lab || failed_hw ||
      ( failed_testA +
        failed_testB +
        failed_testC ) > 1) {
    return "F";
  }
  

  // otherwise apply the cutoffs
  if (over >= CUTOFFS["A"])  return "A";
  if (over >= CUTOFFS["A-"]) return "A-";
  if (over >= CUTOFFS["B+"]) return "B+";
  if (over >= CUTOFFS["B"])  return "B";
  if (over >= CUTOFFS["B-"]) return "B-";
  if (over >= CUTOFFS["C+"]) return "C+";
  if (over >= CUTOFFS["C"])  return "C";
  if (over >= CUTOFFS["C-"]) return "C-";
  if (over >= CUTOFFS["D+"]) return "D+";
  if (over >= CUTOFFS["D"])  return "D";
  else return "F";

  return "?";
}



void Student::mossify(int hw, float penalty) {

  // if the penalty is "a whole or partial letter grade"....
  float average_letter_grade = (CUTOFFS["A"]-CUTOFFS["B"] +
                                 CUTOFFS["B"]-CUTOFFS["C"] +
                                 CUTOFFS["C"]-CUTOFFS["D"]) / 3.0;

  if (!(getGradeableValue(GRADEABLE_ENUM::HOMEWORK,hw-1) > 0)) {
    std::cerr << "WARNING:  the grade for this homework is already 0, moss penalty error?" << std::endl;
  }
  setGradeableValue(GRADEABLE_ENUM::HOMEWORK,hw-1,0);

  // the penalty is positive
  // but it will be multiplied by a negative and added to the total;
  assert (penalty >= 0);

  moss_penalty += -0.0000001;
  moss_penalty += -average_letter_grade * penalty;

  other_note += "[MOSS PENALTY " + std::to_string(penalty) + "]";
}



void Student::ManualGrade(const std::string &grade, const std::string &message) {
  assert (grade == "A" ||
          grade == "A-" ||
          grade == "B+" ||
          grade == "B" ||
          grade == "B-" ||
          grade == "C+" ||
          grade == "C" ||
          grade == "C-" ||
          grade == "D+" ||
          grade == "D" ||
          grade == "F");
  manual_grade = grade;
  other_note += "awarding a " + grade + " because " + message;
}


void Student::outputgrade(std::ostream &ostr,bool flag_b4_moss,Student *lowest_d) const {
  std::string g = grade(flag_b4_moss,lowest_d);
  
  std::string color = GradeColor(g);
  if (moss_penalty < -0.01) {
    ostr << "<td align=center bgcolor=" << color << ">" << g << " @</td>";
  } else {
    ostr << "<td align=center bgcolor=" << color << ">" << g << "</td>";
  }
}


// =============================================================================================

// zones for exams...
//const std::string& Student::getZone(int i) const {
std::string Student::getZone(int i) const {
  assert (i >= 0 && i < GRADEABLES[GRADEABLE_ENUM::TEST].getCount()); return zones[i]; 
}



// =============================================================================================


bool iclickertotalhelper(const std::string &clickername,int which_lecture) {
  std::stringstream ss(clickername);
  int foo;
  ss >> foo;
  if (foo <= which_lecture) return true;
  return false;
}


void Student::addIClickerAnswer(const std::string& which_question, char which_answer, iclicker_answer_enum grade) { 
  iclickeranswers[which_question] = std::make_pair(which_answer,grade);  }

float Student::getIClickerRecent() const {
  if (getUserName() == "PERFECT") { return std::min((int)ICLICKER_QUESTION_NAMES.size(),ICLICKER_RECENT); }
  return getIClickerTotal(100, std::max(0,(int)ICLICKER_QUESTION_NAMES.size()-ICLICKER_RECENT));
}

float Student::getIClickerTotal(int which_lecture, int start) const {
  if (getUserName() == "PERFECT") { return MAX_ICLICKER_TOTAL; } 
  float ans = 0;
  for (unsigned int i = start; i < ICLICKER_QUESTION_NAMES.size(); i++) {
    std::map<std::string,std::pair<char,iclicker_answer_enum> >::const_iterator itr = iclickeranswers.find(ICLICKER_QUESTION_NAMES[i]);
    if (itr == iclickeranswers.end()) continue;
    if (!iclickertotalhelper(itr->first,which_lecture)) continue;
    if (itr->second.second == ICLICKER_CORRECT ||
        itr->second.second == ICLICKER_PARTICIPATED) {
      ans += 1.0;
    } else if (itr->second.second == ICLICKER_INCORRECT) {
      ans += 0.5;
    }
  }
  return ans;
}

std::pair<std::string,iclicker_answer_enum> Student::getIClickerAnswer(const std::string& which_question) const {
  std::pair<std::string,iclicker_answer_enum> noanswer = std::make_pair("",ICLICKER_NOANSWER);
  std::map<std::string,std::pair<char,iclicker_answer_enum> >::const_iterator itr = iclickeranswers.find(which_question); 
  if (itr == iclickeranswers.end()) return noanswer;
  
  char x = itr->second.first;
  std::string tmp(1,x);
  iclicker_answer_enum val = itr->second.second;
  return std::make_pair(tmp,val);
}

// =============================================================================================
