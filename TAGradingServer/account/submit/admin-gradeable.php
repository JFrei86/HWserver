<?php

<<<<<<< HEAD
//TODO MORE error checking

=======
>>>>>>> numerictext gradeable
include "../../toolbox/functions.php";

check_administrator();

<<<<<<< HEAD
if($user_is_administrator)
{
    $have_old = false;
    $has_grades = false;
    $old_gradeable = array(
        'g_id' => -1,
        'g_title' => "",
        'g_overall_ta_instructions' => '',
        'g_team_assignment' => false,
        'g_gradeable_type' => 0,
        'g_grade_by_registration' => false,
        'g_grade_start_date' => date('Y/m/d 23:59:59'),
        'g_grade_released_date' => date('Y/m/d 23:59:59'),
        'g_syllabus_bucket' => '',
        'g_min_grading_group' => ''
    );
    $old_questions = array();
    $old_components = array();
=======
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf']) {
    die("invalid csrf token");
}
>>>>>>> numerictext gradeable

 $gradeableJSON = $_POST['gradeableJSON'];
 
 $fp = fopen(__SUBMISSION_SERVER__ . '/config/gradeable.json', 'w');
<<<<<<< HEAD

=======
>>>>>>> numerictext gradeable
 if (!$fp){
    die('failed to open file');
 }
 
 #decode for pretty print
 fwrite($fp, json_encode(json_decode($gradeableJSON), JSON_PRETTY_PRINT));
 fclose($fp);

 # for debugging
 echo print_r($_POST);
<<<<<<< HEAD

=======
 
 
>>>>>>> numeric text gradeables, remove parts from gradeable from, new schema install script
 $g_id = $_POST['gradeable_id'];
 $g_title = $_POST['gradeable_title'];
 $g_overall_ta_instr = $_POST['ta_instructions'];
 $g_use_teams = ($_POST['team-assignment'] === 'yes') ? "true" : "false";
 $g_gradeable_type = null; 
 $g_min_grading_group=intval($_POST['minimum-grading-group']);

 abstract class GradeableType{
    const electronic_file = 0;
    const checkpoints = 1;
    const numeric = 2;
}
 
 if ($_POST['gradeable-type'] === "Electronic File"){
    $g_gradeable_type = GradeableType::electronic_file;
 }
 else if ($_POST['gradeable-type'] === "Checkpoints"){
    $g_gradeable_type = GradeableType::checkpoints;
 }
 else if ($_POST['gradeable-type'] === "Numeric"){
    $g_gradeable_type = GradeableType::numeric;
 }
 
 $g_grade_by_registration = ($_POST['section-type'] === 'reg-section') ? "true" : "false";
 $g_grade_start_date = ($_POST['date_grade']);
 $g_grade_released_date = ($_POST['date_released']);
 $g_syllabus_bucket = ($_POST['gradeable-buckets']);
 
 function deleteComponents($lb,$ub, $g_id){
    for($i=$lb; $i<=$ub; ++$i){
        //DELETE all grades associated with these gcs
        $params = array($g_id,$i);
        $db->query("SELECT gc_id FROM gradeable_component WHERE g_id=? AND gc_order=?",$params);
        $gc_id = $db->row()['gc_id'];
        
        $db->query("DELETE FROM gradeable_component_data AS gcd WHERE gc_id=?",array($gc_id));
        $db->query("DELETE FROM gradeable_component WHERE gc_id=?", array($gc_id));
    } 
}
 
$action = $_GET['action'];

$db->beginTransaction();  
if ($action=='edit'){
    $params = array($g_title, $g_overall_ta_instr, $g_use_teams, $g_gradeable_type, 
                $g_grade_by_registration, $g_grade_start_date, $g_grade_released_date, 
                $g_syllabus_bucket,$g_min_grading_group, $g_id);
    $db->query("UPDATE gradeable SET g_title=?, g_overall_ta_instructions=?, g_team_assignment=?, g_gradeable_type=?, 
                g_grade_by_registration=?, g_grade_start_date=?, g_grade_released_date=?, g_syllabus_bucket=?, 
                g_min_grading_group=? WHERE g_id=?", $params);
}  
else{
    $params = array($g_id,$g_title, $g_overall_ta_instr, $g_use_teams, $g_gradeable_type, 
                $g_grade_by_registration, $g_grade_start_date, $g_grade_released_date, 
                $g_syllabus_bucket, $g_min_grading_group);
    $db->query("INSERT INTO gradeable(g_id,g_title, g_overall_ta_instructions, g_team_assignment, 
                g_gradeable_type, g_grade_by_registration, g_grade_start_date, g_grade_released_date,
                g_syllabus_bucket,g_min_grading_group) VALUES (?,?,?,?,?,?,?,?,?,?)", $params);
}

// Now that the assignment is specified create the checkpoints for checkpoint based stuffs
// The type of assignment will determine how the gradeable-component(s) are generated

if ($g_gradeable_type === GradeableType::electronic_file){
    // create the specifics of the electronic file
    $instructions_url = $_POST['instructions-url'];
    $date_submit = $_POST['date_submit'];
    $date_due = $_POST['date_due'];
    $is_repo = ($_POST['upload-type'] == 'Repository')? "true" : "false";
    $subdirectory = (isset($_POST['subdirectory']) && $is_repo == "true")? $_POST['subdirectory'] : '';
    $ta_grading = ($_POST['ta-grading'] == 'yes')? "true" : "false";
    $config_path = $_POST['config-path'];
    
    if ($action=='edit'){
        $params = array($instructions_url, $date_submit, $date_due, $is_repo, $subdirectory, $ta_grading, $config_path, $g_id);
        $db->query("UPDATE electronic_gradeable SET eg_instructions_url=?, eg_submission_open_date=?,eg_submission_due_date=?, 
                    eg_is_repository=?, eg_subdirectory=?, eg_use_ta_grading=?, eg_config_path=? WHERE g_id=?", $params);
    }
    else{
        $params = array($g_id, $instructions_url, $date_submit, $date_due, $is_repo, $subdirectory, $ta_grading, $config_path);
        $db->query("INSERT INTO electronic_gradeable(g_id, eg_instructions_url, eg_submission_open_date, eg_submission_due_date, 
            eg_is_repository, eg_subdirectory, eg_use_ta_grading, eg_config_path) VALUES(?,?,?,?,?,?,?,?)", $params);
    }

    $num_questions = 0;
    foreach($_POST as $k=>$v){
        if(strpos($k,'comment') !== false){
            ++$num_questions;
        }
    }
    $db->query("SELECT COUNT(*) as cnt FROM gradeable_component WHERE g_id=?", array($g_id));
    $num_old_questions = intval($db->row()['cnt']);
    //insert the questions
    for ($i=0; $i<$num_questions; ++$i){
        $gc_title = $_POST["comment-".strval($i)];
        $gc_ta_comment = $_POST["ta-".strval($i)];
        $gc_student_comment = $_POST["student-".strval($i)];
        $gc_max_value = $_POST['point-'.strval($i)];
        $gc_is_text = "false";
        $gc_is_ec = ($_POST['ec-'.strval($i)]=='on')? "true" : "false";
        if($action=='edit' && $i<$num_old_questions){
            //update the values for the electronic gradeable
            $params = array($gc_title, $gc_ta_comment, $gc_student_comment, $gc_max_value, $gc_is_text, $gc_is_ec, $g_id,$i);
            $db->query("UPDATE gradeable_component SET gc_title=?, gc_ta_comment=?,gc_student_comment=?, gc_max_value=?, 
                        gc_is_text=?, gc_is_extra_credit=? WHERE g_id=? AND gc_order=?", $params);
        }
        else{
            $params = array($g_id, $gc_title, $gc_ta_comment, $gc_student_comment, $gc_max_value, $gc_is_text, $gc_is_ec,$i);
            $db->query("INSERT INTO gradeable_component(g_id, gc_title, gc_ta_comment, gc_student_comment, gc_max_value, 
                        gc_is_text, gc_is_extra_credit, gc_order) VALUES(?,?,?,?,?,?,?,?)",$params);
        }
    }
    //deleteComponents($num_questions,$num_old_questions,$g_id);
}
else if($g_gradeable_type === GradeableType::checkpoints){
    // create a gradeable component for each checkpoint
    $num_checkpoints = -1; // remove 1 for the template
    foreach($_POST as $k=>$v){
        if(strpos($k, 'checkpoint-label') !== false){
            ++$num_checkpoints;
        }    
    }
    $db->query("SELECT COUNT(*) as cnt FROM gradeable_component WHERE g_id=?", array($g_id));
    $num_old_checkpoints = intval($db->row()['cnt']);

    // insert the checkpoints
    for($i=1; $i<=$num_checkpoints; ++$i){
        $gc_is_extra_credit = (isset($_POST["checkpoint-extra-".strval($i)])) ? "true" : "false";
        $gc_title = $_POST['checkpoint-label-'. strval($i)];
        
        if($action=='edit' && $i <= $num_old_checkpoints){
            $params = array($gc_title, '', '', 1, "false", $gc_is_extra_credit, $g_id, $i);
            $db->query("UPDATE gradeable_component SET gc_title=?, gc_ta_comment=?, gc_student_comment=?,
                        gc_max_value=?, gc_is_text=?, gc_is_extra_credit=? WHERE g_id=? AND gc_order=?", $params);
        }
        else{
            $params = array($g_id, $gc_title, '','',1,"false",$gc_is_extra_credit,$i);
            $db->query("INSERT INTO gradeable_component(g_id, gc_title, gc_ta_comment, gc_student_comment,
                        gc_max_value,gc_is_text,gc_is_extra_credit,gc_order) VALUES (?,?,?,?,?,?,?,?)", $params);
        }
    }
    // remove deleted checkpoints 
    deleteComponents($num_checkpoints+1,$num_old_checkpoints,$g_id);
}
else if($g_gradeable_type === GradeableType::numeric){
    $db->query("SELECT COUNT(*) as cnt FROM gradeable_component WHERE g_id=?", array($g_id));
    $num_old_numerics = intval($db->row()['cnt']);
    $num_numeric = intval($_POST['num-numeric-items']);
    $num_text= intval($_POST['num-text-items']);
    
    for($i=1; $i<=$num_numeric+$num_text; ++$i){
        //CREATE the numeric items in gradeable component
        $gc_is_text = ($i > $num_numeric)? "true" : "false";
        if($i > $num_numeric){
            $gc_title = (isset($_POST['text-label-'. strval($i-$num_numeric)]))? $_POST['text-label-'. strval($i-$num_numeric)] : '';
            $gc_max_value = 0;
            $gc_is_extra_credit ="false";
        }
        else{
            $gc_title = (isset($_POST['numeric-label-'. strval($i)]))? $_POST['numeric-label-'. strval($i)] : '';
            $gc_max_value = (isset($_POST['max-score-'. strval($i)]))? $_POST['max-score-'. strval($i)] : 0;
            $gc_is_extra_credit = (isset($_POST['numeric-extra-'.strval($i)]))? "true" : "false";
        }
        
        if($action=='edit' && $i<=$num_old_numerics){
            $params = array($gc_title, '','',$gc_max_value, $gc_is_text, $gc_is_extra_credit,$g_id,$i);
            $db->query("UPDATE gradeable_component SET gc_title=?, gc_ta_comment=?, gc_student_comment=?, 
                        gc_max_value=?, gc_is_text=?, gc_is_extra_credit=? WHERE g_id=? AND gc_order=?", $params);
        }
        else{
            $params = array($g_id, $gc_title,'','',$gc_max_value,$gc_is_text,$gc_is_extra_credit,$i);
            $db->query("INSERT INTO gradeable_component(g_id, gc_title, gc_ta_comment, gc_student_comment, gc_max_value,
                        gc_is_text, gc_is_extra_credit, gc_order) VALUES (?,?,?,?,?,?,?,?)",$params);
        }
    //remove deleted numerics
    deleteComponents($num_numeric+$num_text+1, $num_old_numerics,$g_id);
}

$db->commit();
echo 'TRANSACTION COMPLETED';

?>