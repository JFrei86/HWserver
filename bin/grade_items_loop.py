#!/usr/bin/env python3

import sys
import datetime
import os
import portalocker
import submitty_utils

# these variables will be replaced by INSTALL_SUBMITTY.sh
AUTOGRADING_LOG_PATH="__INSTALL__FILLIN__AUTOGRADING_LOG_PATH__"
SUBMITTY_DATA_DIR = "__INSTALL__FILLIN__SUBMITTY_DATA_DIR__"


def log_message(is_batch,jobname,timelabel,elapsed_time,message):
    now=datetime.datetime.now()
    datefile=datetime.datetime.strftime(now,"%Y%m%d")+".txt"
    autograding_log_file=os.path.join(AUTOGRADING_LOG_PATH,datefile)
    easy_to_read_date=submitty_utils.write_submitty_date(now)
    my_pid = os.getpid()
    parent_pid = os.getppid()
    batch_string = "BATCH" if is_batch else ""
    abbrev_jobname = jobname[len(SUBMITTY_DATA_DIR+"/courses/"):]
    time_unit = "" if elapsed_time=="" else "sec"
    with open(autograding_log_file,'a') as myfile:
        portalocker.lock(myfile,portalocker.LOCK_EX)
        print ("%s | %6s | %5s | %-70s | %-6s %5s %3s | %s"
               % (easy_to_read_date,parent_pid,batch_string,
                  abbrev_jobname,timelabel,elapsed_time,time_unit,message),
               file=myfile)
        portalocker.unlock(myfile)

    
def log_error(jobname,message):
    log_message("",jobname,"","","ERROR: "+message)
    print ("ERROR :",jobname,":",message)


def log_exit(jobname,message):
    log_error(jobname,message)
    log_error(jobname,"EXIT grade_items_loop.py")
