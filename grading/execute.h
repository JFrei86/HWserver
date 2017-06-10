#include <string>

#include "json.hpp"


// implemented in execute.cpp
int execute(const std::string &cmd, 
	    const std::string &execute_logfile, 
	    const nlohmann::json &test_case_limits,
            const nlohmann::json &assignment_limits,
            const nlohmann::json &whole_config);

int exec_this_command(const std::string &cmd, std::ofstream &logfile);

int install_syscall_filter(bool is_32, const std::string &my_program, std::ofstream &logfile);

// implemented in execute_limits.cpp
void enable_all_setrlimit(const std::string &program_name,
                          const nlohmann::json &test_case_limits,
                          const nlohmann::json &assignment_limits);

rlim_t get_the_limit(const std::string &program_name, int which_limit,
                     const nlohmann::json &test_case_limits,
                     const nlohmann::json &assignment_limits);

std::string get_program_name(const std::string &cmd,const nlohmann::json &whole_config);

void wildcard_expansion(std::vector<std::string> &my_args, const std::string &full_pattern, std::ostream &logfile);

std::string replace_slash_with_double_underscore(const std::string& input);
std::string escape_spaces(const std::string& input);
