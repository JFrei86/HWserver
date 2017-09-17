<?php
namespace app\models;

use app\models\LateDaysCalculation;
use app\libraries\DatabaseUtils;
use app\libraries\Core;
use app\libraries\FileUtils; 

class HWReport extends AbstractModel {
    /*var Core */
    protected $core;
    
    public function __construct(Core $main_core) {
        $this->core = $main_core;
    }
    
    private function generateReport($gradeable, $ldu) {
        // Make sure we have a good directory
        if (!is_dir(implode(DIRECTORY_SEPARATOR, array($this->core->getConfig()->getCoursePath(), "reports")))) {
            mkdir(implode(DIRECTORY_SEPARATOR, array($this->core->getConfig()->getCoursePath(), "reports")));
        }
        $nl = "\n";
        $TEMP_EMAIL = $this->core->getConfig()->getCourseEmail();
        $write_output = True;
        $g_id = $gradeable->getId();
        $rubric_total = 0;
        $ta_max_score = 0;
        // Gather student info, set output filename, reset output
        $student_output_text_main = "";
        $student_output_text = "";
        $student_final_output = "";
        $student_grade = 0;
        $grade_comment = "";
        
        $student_id = $gradeable->isTeamAssignment() ? $gradeable->getTeam()->getId() : $gradeable->getUser()->getId();
        $student_output_filename = $student_id.".txt";
        $late_days_used_overall = 0;
        
        // Only generate full report when the TA has graded the work, may want to change
        if($gradeable->beenTAgraded()) {
            $student_output_text_main .= strtoupper($gradeable->getName())." GRADE".$nl;
            $student_output_text_main .= "----------------------------------------------------------------------" . $nl;
            $names = array();
            $name_and_emails = array();
            $peer_component_count = 0;
            foreach($gradeable->getComponents() as $component){
                if(is_array($component)) {
                    $peer_component_count++;
                    foreach($component as $cmpt) {
                        if(!$cmpt->getGrader() == null) {
                            $names[] = "Peers";
                        }
                    }
                    continue;
                }
                else if($component->getGrader() === null) {
                    //nothing happens, this is the case when a ta has not graded a component
                } 
                else if($component->getGrader()->accessFullGrading()) {
                    $names[] = "{$component->getGrader()->getDisplayedFirstName()} {$component->getGrader()->getLastName()}";
                    $name_and_emails[] = "{$component->getGrader()->getDisplayedFirstName()} {$component->getGrader()->getLastName()} <{$component->getGrader()->getEmail()}>";
                } else {
                    $name_and_emails[] = $TEMP_EMAIL;
                }
                
            }

            $names = array_unique($names);
            $names = implode(", ", $names);
            $name_and_emails = array_unique($name_and_emails);
            $name_and_emails = implode(", ", $name_and_emails);

            $student_output_text_main .= "Graded by : " . $names;

            // Calculate late days for this gradeable
            $late_days = $ldu->getGradeable($gradeable->getUser()->getId(), $g_id);
            // TODO: add functionality to choose who regrade requests will be sent to
            $student_output_text_main .= $nl;
            $student_output_text_main .= "Any regrade requests are due within 7 days of posting to: ".$name_and_emails.$nl;
//            if($gradeable->getDaysLate() > 0) {
//                $student_output_text_main .= "This submission was submitted ".$gradeable->getDaysLate()." day(s) after the due date.".$nl;
//            }

//	    $student_output_text_main .= "DEBUGGING LATE DAYS..  THE INFORMATION BELOW MAY BE INCORRECT - PLEASE CHECK BACK LATER".$nl;
    	    $student_output_text_main .= "DEBUGGING LATE DAYS..   PLEASE CHECK BACK LATER".$nl;

            if($late_days['extensions'] > 0) {
//                $student_output_text_main .= "You have a ".$late_days['extensions']." day extension on this assignment.".$nl;
            }
            if(substr($late_days['status'], 0, 3) == 'Bad') {
                $student_output_text_main .= "NOTE: THIS ASSIGNMENT WILL BE RECORDED AS ZERO".$nl;
                $student_output_text_main .= "  Contact your TA or instructor if you believe this is an error".$nl.$nl;
            }
//            if($late_days['late_days_charged'] > 0) {
//                $student_output_text_main .= "Number of late days used for this homework: " . $late_days['late_days_charged'] . $nl;
//            }
//            $student_output_text_main .= "Total late days used this semester: " . $late_days['total_late_used'] . " (up to and including this assignment)" . $nl;
//            $student_output_text_main .= "Late days remaining for the semester: " . $late_days['remaining_days'] . " (as of the due date of this homework)" . $nl;

//	    $student_output_text_main .= "END DEBUGGING LATE DAYS".$nl;

            $student_output_text_main .= "----------------------------------------------------------------------" . $nl;

            if($gradeable->validateVersions()) {
                $active_version = $gradeable->getActiveVersion();
                $submit_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "results", $g_id, $student_id, $active_version, "grade.txt");
                $auto_grading_awarded = 0;
                $auto_grading_max_score = 0;
                if(!file_exists($submit_file)) {
                    $student_output_text .= $nl.$nl."NO AUTO-GRADE RECORD FOUND (contact the instructor if you did submit this assignment)".$nl.$nl;
                }
                else {
                    $auto_grading_awarded = $gradeable->getGradedAutograderPoints();
                    $auto_grading_max_score = $gradeable->getTotalAutograderNonExtraCreditPoints();
                    $student_output_text .= "AUTO-GRADING TOTAL [ " . $auto_grading_awarded . " / " . $auto_grading_max_score . " ]" . $nl;
                    $gradefilecontents = file_get_contents($submit_file);
                    $student_output_text .= "submission version #" . $active_version .$nl;
                    $student_output_text .= $nl.$gradefilecontents.$nl;
                }

                foreach($gradeable->getComponents() as $component) {

                    // it's already a component...
                    // if($component->getOrder() == -1) {
                    //     $grading_units = $gradeable->getPeerGradeSet() * $peer_component_count;
                    //     $completed_components = $this->core->getQueries()->getNumGradedPeerComponents($gradeable->getId(), $this->core->getUser()->getId());
                    //     $score = $gradeable->roundToPointPrecision($completed_components * $component->getMaxValue() / $grading_units);
                    //     $student_output_text .= "Points for Grading Completion: [". $score. " / ".$component->getMaxValue()."]".$nl;
                    //     continue;
                    // }

                    if(is_array($component)) {
                        $peer_score = 0;
                        $temp_notes = "Peer graded question." . $nl;
                        $stu_count = 0;
                        $peer_score = 0;
                        foreach($component as $peer_comp){
                            $stu_count++;
                            $peer_score += $peer_comp->getGradedTAPoints();
                            $temp_notes .= "Student " . $stu_count . "'s score: " . $peer_comp->getGradedTAPoints() . $nl . $peer_comp->getGradedTAComments($nl) . $nl;
                        }
                        $temp_score = $peer_score/$stu_count;
                        $title = $component[0]->getTitle();
                        $max_value = $component[0]->getMaxValue();
                        $student_comment = $component[0]->getStudentComment();
                    }
                    else {
                        $title = $component->getTitle();
                        $max_value = $component->getMaxValue();
                        $student_comment = $component->getStudentComment();
                        $temp_score = $component->getGradedTAPoints();
                        $temp_notes = $component->getGradedTAComments($nl) . $nl;
                    }
                    
                    $student_output_text .= $title . "[" . $temp_score . "/" . $max_value . "] ";
                    if (!is_array($component) && $component->getGrader()->accessFullGrading()) {
                        $student_output_text .= "(Graded by {$component->getGrader()->getId()})".$nl;
                    } else {
                        $student_output_text .= $nl;
                    }
                    
                    if($student_comment != "") {
                        $student_output_text .= "Rubric: " . $student_comment . $nl;
                    }

                    $student_output_text .= $temp_notes;

                    $student_output_text .= $nl;
                    
                    $student_grade += $temp_score;
                    $rubric_total += $max_value;
                    $ta_max_score += $max_value;
                }
                $student_output_text .= "TA GRADING TOTAL [ " . $student_grade . " / " . $ta_max_score . " ]". $nl;
                $student_output_text .= "----------------------------------------------------------------------" . $nl;
                $rubric_total += $auto_grading_max_score;
                $student_grade += $auto_grading_awarded;
                
                $student_final_grade = max(0,$student_grade);
                $student_output_last = strtoupper($gradeable->getName()) . " GRADE [ " . $student_final_grade . " / " . $rubric_total . " ]" . $nl;
                $student_output_last .= $nl;
                $student_output_last .= "OVERALL NOTE FROM TA: " . ($gradeable->getOverallComment() != "" ? $gradeable->getOverallComment() . $nl : "No Note") . $nl;
                $student_output_last .= "----------------------------------------------------------------------" . $nl;
            }
            else {
                $student_output_text_main .= "NOTE: THIS ASSIGNMENT WILL BE RECORDED AS ZERO".$nl;
                $student_output_last = "[ THERE ARE GRADING VERSION CONFLICTS WITH THIS ASSIGNMENT. PLEASE CONTACT YOUR INSTRUCTOR OR TA TO RESOLVE THE ISSUE]".$nl;
            }

            $student_final_output = $student_output_text_main . $student_output_text. $student_output_last;
        }
        else {
            $student_final_output = "[ TA HAS NOT GRADED ASSIGNMENT, CHECK BACK LATER ]";
        }

        $dir = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "reports", $g_id);
        FileUtils::createDir($dir);

        $save_filename = FileUtils::joinPaths($dir, $student_output_filename);
        if(file_put_contents($save_filename, $student_final_output) === false) {
            // Need to change failure status, unsure how yet
            print "failed to write {$save_filename}\n";
        }
    }
    
    public function generateAllReports() {
        $students = $this->core->getQueries()->getAllUsers();
        $stu_ids = array_map(function($stu) {return $stu->getId();}, $students);
        $gradeables = $this->core->getQueries()->getGradeables(null, $stu_ids, "registration_section", "u.user_id", 0);
        $graders = $this->core->getQueries()->getAllGraders();
        $ldu = new LateDaysCalculation($this->core);
        foreach($gradeables as $gradeable) {
            $this->generateReport($gradeable, $ldu);
        }
    }
    
    public function generateSingleReport($student_id, $gradeable_id) {
        $gradeables = $this->core->getQueries()->getGradeables($gradeable_id, $student_id, "registration_section", "u.user_id", 0);
        $graders = $this->core->getQueries()->getAllGraders();
        $ldu = new LateDaysCalculation($this->core, $student_id);
        foreach($gradeables as $gradeable) {
            $this->generateReport($gradeable, $ldu);
        }
    }
    
    public function generateAllReportsForGradeable($g_id) {
        $students = $this->core->getQueries()->getAllUsers();
        $stu_ids = array_map(function($stu) {return $stu->getId();}, $students);
        $gradeables = $this->core->getQueries()->getGradeables($g_id, $stu_ids, "registration_section", "u.user_id", 0);
        $graders = $this->core->getQueries()->getAllGraders();
        $ldu = new LateDaysCalculation($this-core);
        foreach($gradeables as $gradeable) {
            $this->generateReport($gradeable, $ldu);
        }
    }
    
    public function generateAllReportsForStudent($stu_id) {
        $gradeables = $this->core->getQueries()->getGradeables(null, $stu_id, "registration_section", "u.user_id", 0);
        $graders = $this->core->getQueries()->getAllGraders();
        $ldu = new LateDaysCalculation($this->core, $stu_id);
        foreach($gradeables as $gradeable) {
            $this->generateReport($gradeable, $ldu);
        }
    }
}

