#include <iostream>
#include <fstream>
#include <cstdlib>
#include <string>
#include <sstream>
#include <cassert>
#include <map>
#include <set>
#include <iomanip>


// =====================================================================
//
// This is a utility program to study student programs and determine
// what extra system calls might need to be allowed when run within
// the sandbox for autograding.
//
// To use:  first run the program in question through strace:
//
//    strace -f -q student_executable.out  arg1 arg2  2>  strace_output.txt
//
// Then, give the strace output to this program:
//
//    system_call_check.out strace_output.txt
//
// =====================================================================


#define SYSTEM_CALL_CATEGORIES_HEADER "/usr/local/hss/GIT_CHECKOUT_HWserver/grading/system_call_categories.h"

// ================================================================================================
// ================================================================================================
// read master file with categorization of system calls into groups
// that are whitelisted, restricted, or forbidden

void parse_system_calls(std::ifstream& system_call_categories_file, 
                        std::map<std::string,std::string>& all_system_calls, 
                        std::map<std::string,std::string>& categories) {
  std::string line;
  std::string category;
  bool in_preprocessor_if = false;

  // loop over all lines of the file
  while (std::getline(system_call_categories_file,line)) {

    // if it's a system call
    if (line.find("ALLOW_SYSCALL(") != std::string::npos) {
      int endpoint = line.find(");");
      assert (endpoint != std::string::npos);
      assert (category != "");
      // there should be nothing else on this line
      assert (line.size() == endpoint+2);
      assert (endpoint-14 > 1);
      std::string system_call = line.substr(14,endpoint-14);
      // make sure there aren't duplicates
      assert (all_system_calls.find(system_call) == all_system_calls.end());
      all_system_calls[system_call] = category;
    } 

    // handle blank lines or #endif (end of category) 
    else if (line == "") {
      category = "";
    } 
    else if (line == "#endif") {
      assert (in_preprocessor_if);
      in_preprocessor_if = false;
      category = "";
    } 
    
    else {
      //std::cout << "LINE " << line << std::endl;
      std::stringstream ss(line);
      std::string token, type;
      ss >> token; 
      assert (token == "//");
      ss >> type;
      if (type != "WHITELIST" && type != "RESTRICTED" && type != "FORBIDDEN") {
        // just a comment
        continue;
      };

      // otherwise this is a category
      ss >> token;
      assert (token == ":");
      ss >> category;
      assert (category != "");
      assert (categories.find(category) == categories.end());
      categories[category] = type;
      // make sure nothing else is on that line!
      assert (ss.rdbuf()->in_avail() == 0);
      assert (in_preprocessor_if == false);

      if (type == "RESTRICTED") {
        std::getline(system_call_categories_file,line);
        assert (line == "#ifdef ALLOW_SYSTEM_CALL_CATEGORY_"+category);
        in_preprocessor_if = true;
      } else if (type == "FORBIDDEN") {
        std::getline(system_call_categories_file,line);
        assert (line == "#if 0");
        in_preprocessor_if = true;
      } else {

      }
    }
  }

  // verify that we have all of the linux system calls (32 & 64 bit)
  assert (all_system_calls.size() == 385);
}


// ================================================================================================
// ================================================================================================
// parse the strace output to create a list of all system calls used
// by the program

void parse_strace_output(std::ifstream &strace_output_file,
                         const std::map<std::string,std::string>& all_system_calls,
                         std::map<std::string, std::map<std::string,int> >& USED_CATEGORIES) {

  std::string line;
  bool first = true;

  // loop over all lines of the file
  while (std::getline(strace_output_file,line)) {

    if (line.substr(0,5) == "[pid ") {
      int pos = line.find("] ");
      assert (pos != std::string::npos);
      line = line.substr(pos+2,line.size()-pos-2);
      std::cout << "AFTER TRIMMING '" << line << "'" << std::endl;
    }



    // look for the system call name
    int pos = line.find("(");
    if (pos != std::string::npos) {    
      std::string sc = line.substr(0,pos);
      std::string full_name = sc; //"__NR_"+sc;

      // Skip the first system call.  The 'exec' that strace uses to
      // run the program to be monitored.
      if (first == true) {
        //assert (full_name == "__NR_execve");
        assert (full_name == "execve");
        first = false;
        continue;
      }

      // look up the category for this call
      std::map<std::string,std::string>::const_iterator itr = all_system_calls.find(full_name);
      std::cout << "STRACE LINE '" << line << "'" << std::endl;
      std::cout << "attempt " << full_name << std::endl;

      if (line[0] == '<') {
        // skip lines like: '<... wait4 resumed> [{WIFEXITED(s) && WEXITSTATUS(s) == 0}], 0, NULL) = 2200'
        continue;
      }

      if (full_name.substr(0,5) == "[pid ") {
        int pos = full_name.find("] ");
        assert (pos != std::string::npos);
        full_name = full_name.substr(pos+2,full_name.size()-pos-2);
        std::cout << "AFTER TRIMMING '" << full_name << "'" << std::endl;
        itr = all_system_calls.find(full_name);
      }
      
      if (full_name == "pread") {
        // seems to be a bug/omission in strace
        full_name = "pread64";
        itr = all_system_calls.find(full_name);
      }


      assert (itr != all_system_calls.end());
      std::string which_category = itr->second;

      // is this the first time we use this category?
      std::map<std::string,std::map<std::string,int> >::iterator itr2 = USED_CATEGORIES.find(which_category);
      if (itr2 == USED_CATEGORIES.end()) {
        // add it!
        itr2 = USED_CATEGORIES.insert(std::make_pair(which_category,std::map<std::string,int>())).first;
      }
      assert (itr2 != USED_CATEGORIES.end());

      // is this the first time we use this system call?
      std::map<std::string,int>::iterator itr3 = itr2->second.find(full_name);
      if (itr3 == itr2->second.end()) {
        // add it!
        itr2->second.insert(std::make_pair(full_name,1));
      } else {
        // just increment
        itr3->second++;
      }
    }
  }
}

// ================================================================================================
// ================================================================================================
// create a nice printed summary of the system calls and categories and instructions for
// allowing use of restricted system calls

void print_system_call_categories(const std::map<std::string,std::string>& categories,
                                  const std::map<std::string,std::map<std::string,int> >& USED_CATEGORIES,
                                  const std::string& type) {

  bool first = true;

  // loop over all categories
  for (std::map<std::string,std::map<std::string,int> >::const_iterator itr = USED_CATEGORIES.begin(); 
       itr != USED_CATEGORIES.end(); itr++) {

    // skip categories that don't match the current type (whitelist,restricted,forbidden)
    std::map<std::string,std::string>::const_iterator cat_itr = categories.find(itr->first);
    assert (cat_itr != categories.end());
    if (cat_itr->second != type) continue;
    
    // the first category (if any) prints a little information blurb
    if (first == true) {
      first = false;
      if (cat_itr->second == "WHITELIST") {
        std::cout << "\n*** These system call categories are whitelisted ***\n" << std::endl;
      } else if (cat_itr->second == "RESTRICTED") {
        std::cout << "\n*** WARNING!  These system calls are restricted.  To allow use of these ***\n";
        std::cout <<   "***    system calls add the indicated #define to your config.h file.    ***\n" << std::endl;
      } else {
        assert (cat_itr->second == "FORBIDDEN");
        std::cout << "\n*** ERROR!  These system calls are forbidden in student code on the     ***\n";
        std::cout <<   "***    homework submission server.                                      ***\n" << std::endl;
      } 
    }

    // print out the category name
    std::cout << "    ";
    if (cat_itr->second == "RESTRICTED") {
      std::cout << "[RESTRICTED] ";
    }
    std::cout << "CATEGORY: " << itr->first << std::endl;  
    if (cat_itr->second == "RESTRICTED") {
      std::cout << "    #define ALLOW_SYSTEM_CALL_CATEGORY_" << itr->first << std::endl;
    }

    // print the system calls in that category that were used & the usage count
    for (std::map<std::string,int>::const_iterator itr2 = itr->second.begin(); itr2 != itr->second.end(); itr2++) {
      std::cout << "        " << std::left << std::setw(4) << itr2->second 
                << " instance(s) of system call '" << itr2->first << "'" << std::endl;
    }
  }

  if (!first) {
    std::cout << std::endl;
  }
}


// ================================================================================================
// ================================================================================================

int main(int argc, char* argv[]) {

  // check arguments
  if (argc != 2) {
    std::cerr << "ERROR! program expects a single argument, the name of the strace output file to check" << std::endl;
    exit(0);
  }

  // =======================================================
  // parse the system call categories file
  std::ifstream system_call_categories_file (SYSTEM_CALL_CATEGORIES_HEADER);
  if (!system_call_categories_file.good()) {
    std::cerr << "ERROR! could not open system call cateogies file" << SYSTEM_CALL_CATEGORIES_HEADER << std::endl;
    exit(0);
  }
  std::map<std::string,std::string> all_system_calls;
  std::map<std::string,std::string> categories;
  parse_system_calls(system_call_categories_file,all_system_calls,categories);
  std::cout << "Loaded system call categorization from: '" << SYSTEM_CALL_CATEGORIES_HEADER << "'" << std::endl;
  std::cout << "  " << all_system_calls.size() << " total system calls" << std::endl;
  std::cout << "  " << categories.size() << " categories of system calls" << std::endl;


  // =======================================================
  // parse the strace output file
  std::ifstream strace_output_file (argv[1]);
  if (!strace_output_file.good()) {
    std::cerr << "ERROR! could not open strace output file" << std::endl;
    exit(0);
  }
  std::map<std::string,std::map<std::string,int> > USED_CATEGORIES;
  parse_strace_output(strace_output_file,all_system_calls,USED_CATEGORIES);


  // =======================================================
  // print the categories & whitelist, restricted, or forbidden status
  print_system_call_categories(categories,USED_CATEGORIES,"WHITELIST");
  print_system_call_categories(categories,USED_CATEGORIES,"RESTRICTED");
  print_system_call_categories(categories,USED_CATEGORIES,"FORBIDDEN");


  // =======================================================
  // print summary information
  std::cout << "Used " << USED_CATEGORIES.size() << " different categories of system calls." << std::endl;
  int different_used_system_calls = 0;
  int total_used_system_calls = 0;
  for (std::map<std::string,std::map<std::string,int> >::const_iterator itr = USED_CATEGORIES.begin(); 
       itr != USED_CATEGORIES.end(); itr++) {
    different_used_system_calls += itr->second.size();
    for (std::map<std::string,int>::const_iterator itr2 = itr->second.begin(); itr2 != itr->second.end(); itr2++) {
      total_used_system_calls += itr2->second;
    }
  }
  std::cout << "Used " << different_used_system_calls << " different system calls." << std::endl;
  std::cout << "Used " << total_used_system_calls << " total system calls." << std::endl;
}
