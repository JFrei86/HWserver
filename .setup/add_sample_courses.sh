#!/usr/bin/env bash


# A FEW GLOBAL VARIABLES
SUBMITTY_REPOSITORY=/usr/local/submitty/GIT_CHECKOUT_Submitty
SUBMITTY_INSTALL_DIR=/usr/local/submitty
SUBMITTY_DATA_DIR=/var/local/submitty
sample_assignment_dir=$SUBMITTY_INSTALL_DIR/sample_files/sample_assignment_config


# MAKE A REASONABLE GUESS AT THE SEMESTER BASED ON THE CURRENT DATE
year=`date +%y`
month=`date +%m`
if [[ "$month" < "07" ]] 
then 
    # spring!
    semester="s"$year
else
    # fall!
    semester="f"$year
fi


# CREATE TOP LEVEL COURSE DIRECTORY
mkdir -p $SUBMITTY_DATA_DIR/courses


#####################################################################
#####################################################################
# HELPER FUNCTION TO SETUP A COURSE

function one_course {

    # ---------------------------------------------------------------
    # ARGUMENTS
    course=$1
    shift
    course_group=$1
    shift
    assignments_array=("${@}")


    # ---------------------------------------------------------------
    # CREATE THE COURSE
    ${SUBMITTY_INSTALL_DIR}/bin/create_course.sh $semester $course instructor $course_group

    
    # ---------------------------------------------------------------
    # WRITE THE ASSIGNMENTS FILE (NORMALLY AUTO GENERATED BY CREATE/EDIT GRADEABLE WEBPAGE)
    assignments_file=/var/local/submitty/courses/$semester/$course/ASSIGNMENTS.txt
    rm -f $assignments_file
    for i in "${assignments_array[@]}"
    do
        echo "build_homework   $sample_assignment_dir/$i   $semester   $course   $i"  >> $assignments_file
    done

    chown hwphp $assignments_file
    
    # ---------------------------------------------------------------
    # WRITE THE CONFIG/CLASS.JSON (NORMALLY AUTO GENERATED BY CREATE/EDIT GRADEABLE WEBPAGE)
    class_json_file=/var/local/submitty/courses/$semester/$course/config/class.json
    echo "{"                                                              >  $class_json_file
    echo "   \"dev_team\": [],"                                           >> $class_json_file
    echo "   \"upload_message\": \"Prepare your assignment for submission exactly as described on the course webpage.  By clicking \\\"Submit File\\\" you are confirming that you have read, understand, and agree to follow the Academic Integrity Policy\","  >> $class_json_file
    echo "   \"assignments\": ["                                          >> $class_json_file
    last_index=${#assignments_array[@]}
    for (( i=0; i<$last_index; i++ ))
    do
        val=${assignments_array[$i]}
        # set the homework deadlines 2 days apart, starting 2 days ago
        my_date=`date --date="$(($i*2-2)) day" +"%Y-%m-%d "`
        echo "        {"                                                  >> $class_json_file
        #FIXME:  Using the full directory name as the assignment_id is rather bulky...
        echo "               \"assignment_id\":\"$val\","                 >> $class_json_file
        echo "               \"assignment_name\":\"$val\","               >> $class_json_file
        echo "               \"released\":true,"                          >> $class_json_file
        echo "               \"ta_grade_released\":false,"                >> $class_json_file
	echo "               \"due_date\":\"$my_date 23:59:59.0\""        >> $class_json_file
        if [ "$i" -eq "$(($last_index-1))" ]
        then
            echo "        }"                                              >> $class_json_file
        else
            echo "        },"                                             >> $class_json_file
        fi
    done
    echo "    ]"                                                          >> $class_json_file
    echo "}"                                                              >> $class_json_file

    # ---------------------------------------------------------------
    # CREATE THE FORM DIRECTORY (FOR GRADEABLE FORM JSON TEMPLATES)
    class_form_directory=/var/local/submitty/courses/$semester/$course/config/form/
    mkdir $class_form_directory
    chown hwphp $class_form_directory
    
    # ---------------------------------------------------------------
    # RUN THE BUILD SCRIPT
    ${SUBMITTY_DATA_DIR}/courses/$semester/$course/BUILD_${course}.sh


    # ---------------------------------------------------------------
    # CREATE & POPULATE THE DATABASE
    export PGPASSWORD="hsdbu"
    DATABASE_NAME=submitty_${semester}_${course}
    echo 'here' $DATABASE_NAME
    psql -d postgres -h localhost -U hsdbu -c "CREATE DATABASE $DATABASE_NAME"   
    psql -d submitty_${semester}_${course} -h localhost -U hsdbu -f ${SUBMITTY_REPOSITORY}/TAGradingServer/data/tables.sql
    psql -d submitty_${semester}_${course} -h localhost -U hsdbu -f ${SUBMITTY_REPOSITORY}/TAGradingServer/data/inserts.sql
    psql -d submitty_${semester}_${course} -h localhost -U hsdbu -f ${SUBMITTY_REPOSITORY}/.setup/vagrant/db_inserts.sql
    unset PGPASSWORD
}


#####################################################################
#####################################################################
# CREATE A FEW SAMPLE COURSES

python_homework=( python_simple_homework python_buggy_output python_simple_homework_multipart )
one_course csci1100 csci1100_tas_www  "${python_homework[@]}"

cpp_homework=( cpp_simple_lab cpp_cats cpp_memory_debugging )
one_course csci1200 csci1200_tas_www  "${cpp_homework[@]}"

java_homework=( java_factorial java_coverage_factorial )
one_course csci2600 csci2600_tas_www  "${java_homework[@]}"

#####################################################################
