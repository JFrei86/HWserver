#include <sys/types.h>
#include <sys/stat.h>
#include <cstdio>
#include <cstddef>
#include <cstdlib>
#include <unistd.h>
#include <fcntl.h>
#include <elf.h>

// COMPILATION NOTE: Must pass -lseccomp to build
#include <seccomp.h>

#include <vector>
#include <string>

//
//
// FIXME LONGTERM: config.h should be redesigned (cannot do this
//     midsemester) In order to allow #including config.h in multiple
//     files, do this hack to change the name.
//    
//
#define testcases IGNORE_TESTCASES
#define assignment_limits IGNORE_ASSIGNMENT_LIMITS
#include "default_config.h"
//
// END FIXME LONGTERM
//

// ===========================================================================
// ===========================================================================
// Helper macro that disallows certain system calls using the seccomp library
#define ALLOW_SYSCALL(name) do {\
  int __res__ = seccomp_rule_add(sc, SCMP_ACT_ALLOW, SCMP_SYS(name), 0); \
  if (__res__ < 0) {\
    fprintf(stderr, "Error %d installing seccomp rule for %s\n", __res__, #name); \
    return 1;\
  }\
} while (0)

// ===========================================================================
// ===========================================================================

int install_syscall_filter(bool is_32, const std::string &my_program) {
    
  int res;
  scmp_filter_ctx sc = seccomp_init(SCMP_ACT_KILL);
  int target_arch = is_32 ? SCMP_ARCH_X86 : SCMP_ARCH_X86_64;
  if (seccomp_arch_native() != target_arch) {
    res = seccomp_arch_add(sc, target_arch);
    if (res != 0) {
      //fprintf(stderr, "seccomp_arch_add failed: %d\n", res);
      return 1;
    }
  }

  // libseccomp uses pseudo-syscalls to let us use the 64-bit split
  // system call names for SYS_socketcall on 32-bit.  The translation
  // being on their side means we have no choice in the matter as we
  // cannot pass them the number for the target: only for the source.
  // We could use raw seccomp-bpf instead.


  // C/C++ COMPILATION
  if (my_program == "/usr/bin/g++" ||
      my_program == "/usr/bin/clang++" ||
      my_program == "/usr/bin/gcc") {
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_MOVE_DELETE_RENAME_FILE_DIRECTORY
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_PERMISSIONS
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_RARE
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_ADVANCED
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_NEW_PROCESS_THREAD
  }


  // PYTHON 
  if (my_program == "/usr/bin/python") {
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_PERMISSIONS
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_ADVANCED
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_GET_SET_USER_GROUP_ID
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_NEW_PROCESS_THREAD
  }
  

  // JAVA
  if (my_program == "/usr/bin/javac" ||
      my_program == "/usr/bin/java") {
#define ALLOW_SYSTEM_CALL_CATEGORY_COMMUNICATIONS_AND_NETWORKING_SOCKETS_MINIMAL
#define ALLOW_SYSTEM_CALL_CATEGORY_COMMUNICATIONS_AND_NETWORKING_SOCKETS

#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_MOVE_DELETE_RENAME_FILE_DIRECTORY
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_PERMISSIONS
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_RARE
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_ADVANCED
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_NEW_PROCESS_THREAD
  }

  // HELPER UTILTIY PROGRAMS
  if (my_program == "/usr/bin/time") {
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_NEW_PROCESS_THREAD    
  } 

  // IMAGE COMPARISON
  if (my_program == "/usr/bin/compare") {
#define ALLOW_SYSTEM_CALL_CATEGORY_FILE_MANAGEMENT_PERMISSIONS
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_ADVANCED
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_NEW_PROCESS_THREAD
#define ALLOW_SYSTEM_CALL_CATEGORY_PROCESS_CONTROL_SCHEDULING
  }
  

  // ======================
  // ======================
  // ======================
  // INCLUDE THE COMPLETE LIST OF SYSTEM CALLS ORGANIZED INTO CATEGORIES 
#include "system_call_categories.h"
  // ======================
  // ======================
  // ======================

  if (seccomp_load(sc) < 0)
    return 1; // failure                                                                                   
  
  /* This does not remove the filter */
  seccomp_release(sc);

  return 0;
}


// ===========================================================================
// ===========================================================================
