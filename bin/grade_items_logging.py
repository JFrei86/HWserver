#!/usr/bin/env python3

import sys
from datetime import datetime
import os
from submitty_utils import dateutils
import fcntl


# these variables will be replaced by INSTALL_SUBMITTY.sh
AUTOGRADING_LOG_PATH="__INSTALL__FILLIN__AUTOGRADING_LOG_PATH__"
SUBMITTY_DATA_DIR = "__INSTALL__FILLIN__SUBMITTY_DATA_DIR__"


def log_message(is_batch=False,which_untrusted="",jobname="",timelabel="",elapsed_time=-1,message=""):
    now = dateutils.get_current_time()
    datefile=datetime.strftime(now,"%Y%m%d")+".txt"
    autograding_log_file=os.path.join(AUTOGRADING_LOG_PATH,datefile)
    easy_to_read_date=dateutils.write_submitty_date(now,True)
    my_pid = os.getpid()
    parent_pid = os.getppid()
    batch_string = "BATCH" if is_batch else ""
    if elapsed_time == "":
        elapsed_time = -1
    elapsed_time_string = "" if elapsed_time<0 else '{:9.3f}'.format(elapsed_time)
    time_unit = "" if elapsed_time<0 else "sec"
    with open(autograding_log_file,'a') as myfile:
        fcntl.flock(myfile,fcntl.LOCK_EX | fcntl.LOCK_NB)
        print ("%s | %6s | %5s | %11s | %-75s | %-6s %9s %3s | %s"
               % (easy_to_read_date,my_pid,batch_string,which_untrusted,
                  jobname,timelabel,elapsed_time_string,time_unit,message),
               file=myfile)
        fcntl.flock(myfile,fcntl.LOCK_UN)

    
def log_error(jobname,message):
    log_message("","",jobname,"",-1,"ERROR: "+message)
    print ("ERROR :",jobname,":",message)


def log_exit(jobname,message):
    log_error(jobname,message)
    log_error(jobname,"EXIT grade_items_scheduler.py")
