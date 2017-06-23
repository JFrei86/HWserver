<?php

namespace app\controllers\grading;

use app\controllers\AbstractController;
use app\libraries\database\DatabaseQueriesPostgresql;
use app\libraries\Core;
use app\libraries\GradeableType;
use app\views\submission\HomeworkView;

use app\libraries\DateUtils;
use app\libraries\ErrorMessages;
use app\libraries\FileUtils;
use app\libraries\Logger;
use app\libraries\Utils;
use app\models\GradeableList;

class UploadController extends AbstractController {
    public function __construct(Core $core) {
        parent::__construct($core);
        $this->gradeables_list = $this->core->loadModel("GradeableList", $this->core);
    }

    public function run() {
        switch($_REQUEST['action']) {
            case 'upload':
                return $this->ajaxUploadSubmission();
                break;
            case 'verify':
                return $this->validGradeable();
                break;
            case 'get':
                return $this->getGradeable();
                break;
            case 'update':
                return $this->updateSubmissionVersion();
                break;
            case 'check_refresh':
                //return $this->checkRefresh();
                break;
            case 'display':
            default:
                return $this->showUploadPage();
                break;
        }
    }

    public function showUploadPage() {
        $gradeable_id = (isset($_REQUEST['gradeable_id'])) ? $_REQUEST['gradeable_id'] : null;
        $gradeable = $this->gradeables_list->getGradeable($gradeable_id, GradeableType::ELECTRONIC_FILE);
        if ($gradeable !== null) {
            $error = false;
            $now = new \DateTime("now", $this->core->getConfig()->getTimezone());

            if ($gradeable->getOpenDate() > $now) {
                // rewrite to not use submission functions, reference simple grader
                $this->core->getOutput()->renderOutput(array('submission', 'Homework'), 'noGradeable', $gradeable_id);
                return array('error' => true, 'message' => 'No gradeable with that id.');
            }
            else {
                $loc = array('component' => 'grading', 'page' => 'upload');
                $this->core->getOutput()->addBreadcrumb($gradeable->getName(), $this->core->buildUrl($loc));
                if (!$gradeable->hasConfig()) {
                    // rewrite to not use submission functions, reference simple grader
                    $this->core->getOutput()->renderOutput(array('submission', 'Homework'),
                                                           'showGradeableError', $gradeable);
                    $error = true;
                }
                else {
                    $gradeable->loadResultDetails();
                    $days_late = DateUtils::calculateDayDiff($gradeable->getDueDate());
                    if ($gradeable->beenTAgraded() && $gradeable->hasGradeFile()) {
                        $gradeable->updateUserViewedDate();
                    }
                    $this->core->getOutput()->renderOutput(array('grading', 'Upload'), 'showUpload', $gradeable, $days_late);
                }
            }
            return array('id' => $gradeable_id, 'error' => $error);
        }
        else {
            // rewrite to not use submission functions, reference simple grader
            $this->core->getOutput()->renderOutput(array('submission', 'Homework'), 'noGradeable', $gradeable_id);
            return array('error' => true, 'message' => 'No gradeable with that id.');
        }
    }

    private function validGradeable() {
        // gets gradeable_id, student_id

        if (!isset($_REQUEST['gradeable_id'])) {
            $msg = "Did not pass in gradeable_id.";
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload')));
            return array('error' => true, 'message' => $msg);
        }
        if (!isset($_REQUEST['days_late'])) {
            $msg = "Did not pass in days_late.";
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload')));
            return array('error' => true, 'message' => $msg);
        }
        if (!isset($_POST['student_id'])) {
            $msg = "Did not pass in student_id.";
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload')));
            return array('error' => true, 'message' => $msg);
        }

        $gradeable_id = $_REQUEST['gradeable_id'];
        $days_late = $_REQUEST['days_late'];
        $student_id = $_POST['student_id'];
        $student_user = $this->core->getQueries()->getUserById($student_id);
        $student_gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $student_id);

        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] != $this->core->getCsrfToken()) {
            $msg = "Invalid CSRF token. Refresh the page and try again.";
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload', 'gradeable_id' => $gradeable_id)));
            return array('error' => true, 'message' => $msg);
        }
        else 
        if (!$student_user->isLoaded()) {
            $msg = "Not a valid student id.";
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload', 'gradeable_id' => $gradeable_id, 'days_late' => $days_late)));
            return array('error' => true, 'message' => $msg);
        }
        else if ($student_gradeable === null) {
            $msg = "Not a valid gradeable.";
            $msg .= $student_id;
            $msg .= $gradeable_id;
            $_SESSION['messages']['error'][] = $msg;
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'upload', 'gradeable_id' => $gradeable_id, 'days_late' => $days_late)));
            return array('error' => true, 'message' => $msg);
        }
        $this->student_user = $student_user;
        $this->student_gradeable = $student_gradeable; 
        $this->core->getOutput()->renderOutput(array('grading', 'Upload'), 'showUpload', $student_gradeable, $days_late, $student_id);
        return array('id' => $gradeable_id, 'error' => false);
    }

    /**
     * Function for uploading a submission to the server. This should be called via AJAX, saving the result
     * to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     *
     * @return boolean
     */
    private function ajaxUploadSubmission() {
        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->uploadResult("Invalid CSRF token.", false);
        }
        $svn_checkout = isset($_REQUEST['svn_checkout']) ? $_REQUEST['svn_checkout'] === "true" : false;
    
        $gradeable_list = $this->gradeables_list->getSubmittableElectronicGradeables();
        
        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if (!isset($_REQUEST['gradeable_id']) || !array_key_exists($_REQUEST['gradeable_id'], $gradeable_list)) {
            return $this->uploadResult("Invalid gradeable id '{$_REQUEST['gradeable_id']}'", false);
        }
        
        $gradeable = $this->student_gradeable;
        $gradeable->loadResultDetails();
        $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions",
            $gradeable->getId());
        
        /*
         * Perform checks on the following folders (and whether or not they exist):
         * 1) the assignment folder in the submissions directory
         * 2) the student's folder in the assignment folder
         * 3) the version folder in the student folder
         * 4) the part folders in the version folder in the version folder
         */
        if (!FileUtils::createDir($gradeable_path)) {
            return $this->uploadResult("Failed to make folder for this assignment.", false);
        }

        $user_id = $gradeable->getUser()->getId();
        $who_id = $user_id;
        $team_id = "";
        if ($gradeable->isTeamAssignment()) {
            $team = $this->core->getQueries()->getTeamByUserId($gradeable->getId(), $user_id);
            if ($team !== null) {
                $team_id = $team->getId();
                $who_id = $team_id;
                $user_id = "";
            }
        }
        
        $user_path = FileUtils::joinPaths($gradeable_path, $who_id);
        $this->upload_details['user_path'] = $user_path;
        if (!FileUtils::createDir($user_path)) {
                return $this->uploadResult("Failed to make folder for this assignment for the user.", false);
        }
    
        $new_version = $gradeable->getHighestVersion() + 1;
        $version_path = FileUtils::joinPaths($user_path, $new_version);
        
        if (!FileUtils::createDir($version_path)) {
            return $this->uploadResult("Failed to make folder for the current version.", false);
        }
    
        $this->upload_details['version_path'] = $version_path;
        $this->upload_details['version'] = $new_version;
    
        $part_path = array();
        // We upload the assignment such that if it's multiple parts, we put it in folders "part#" otherwise
        // put all files in the root folder
        if ($gradeable->getNumParts() > 1) {
            for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                $part_path[$i] = FileUtils::joinPaths($version_path, "part".$i);
                if (!FileUtils::createDir($part_path[$i])) {
                    return $this->uploadResult("Failed to make the folder for part {$i}.", false);
                }
            }
        }
        else {
            $part_path[1] = $version_path;
        }
        
        $current_time = (new \DateTime('now', $this->core->getConfig()->getTimezone()))->format("Y-m-d H:i:s");
        $max_size = $gradeable->getMaxSize();
        
        if ($svn_checkout === false) {
            $uploaded_files = array();
            for ($i = 1; $i <= $gradeable->getNumParts(); $i++){
                if (isset($_FILES["files{$i}"])) {
                    $uploaded_files[$i] = $_FILES["files{$i}"];
                }
            }
            
            $errors = array();
            $count = array();
            for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                if (isset($uploaded_files[$i])) {
                    $count[$i] = count($uploaded_files[$i]["name"]);
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (!isset($uploaded_files[$i]["tmp_name"][$j]) || $uploaded_files[$i]["tmp_name"][$j] === "") {
                            $error_message = $uploaded_files[$i]["name"][$j]." failed to upload. ";
                            if (isset($uploaded_files[$i]["error"][$j])) {
                                $error_message .= "Error message: ". ErrorMessages::uploadErrors($uploaded_files[$i]["error"][$j]). ".";
                            }
                            $errors[] = $error_message;
                        }
                    }
                }
            }
            
            if (count($errors) > 0) {
                $error_text = implode("\n", $errors);
                return $this->uploadResult("Upload Failed: ".$error_text, false);
            }

            // save the contents of the text boxes to files
            $empty_textboxes = true;
            if (isset($_POST['textbox_answers'])) {
                $textbox_answer_array = json_decode($_POST['textbox_answers']);
                for ($i = 0; $i < $gradeable->getNumTextBoxes(); $i++) {
                    $textbox_answer_val = $textbox_answer_array[$i];
                    if ($textbox_answer_val != "") $empty_textboxes = false;
                    $filename = $gradeable->getTextBoxes()[$i]['filename'];
                    $dst = FileUtils::joinPaths($version_path, $filename);
                    // FIXME: add error checking
                    $file = fopen($dst, "w");
                    fwrite($file, $textbox_answer_val);
                    fclose($file);
                }
            }
    
            $previous_files = array();
            $previous_part_path = array();
            $tmp = json_decode($_POST['previous_files']);
            for ($i = 0; $i < $gradeable->getNumParts(); $i++) {
                if (count($tmp[$i]) > 0) {
                    $previous_files[$i + 1] = $tmp[$i];
                }
            }
            
            if (empty($uploaded_files) && empty($previous_files) && $empty_textboxes) {
                return $this->uploadResult("No files to be submitted.", false);
            }
            
            if (count($previous_files) > 0) {
                if ($gradeable->getHighestVersion() === 0) {
                    return $this->uploadResult("No submission found. There should not be any files from a previous submission.", false);
                }
                
                $previous_path = FileUtils::joinPaths($user_path, $gradeable->getHighestVersion());
                if ($gradeable->getNumParts() > 1) {
                    for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                        $previous_part_path[$i] = FileUtils::joinPaths($previous_path, "part".$i);
                    }
                }
                else {
                    $previous_part_path[1] = $previous_path;
                }

                foreach ($previous_part_path as $path) {
                    if (!is_dir($path)) {
                        return $this->uploadResult("Files from previous submission not found. Folder for previous submission does not exist.", false);
                    }
                }
    
                for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                    if (isset($previous_files[$i])) {
                        foreach ($previous_files[$i] as $prev_file) {
                            $filename = FileUtils::joinPaths($previous_part_path[$i], $prev_file);
                            if (!file_exists($filename)) {
                                $name = basename($filename);
                                return $this->uploadResult("File '{$name}' does not exist in previous submission.", false);
                            }
                        }
                    }
                }
            }
            
            // Determine the size of the uploaded files as well as whether or not they're a zip or not.
            // We save that information for later so we know which files need unpacking or not and can save
            // a check to getMimeType()
            $file_size = 0;
            for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                if (isset($uploaded_files[$i])) {
                    $uploaded_files[$i]["is_zip"] = array();
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (FileUtils::getMimeType($uploaded_files[$i]["tmp_name"][$j]) == "application/zip") {
                            if(FileUtils::checkFileInZipName($uploaded_files[$i]["tmp_name"][$j]) === false) {
                                return $this->uploadResult("Error: You may not use quotes, backslashes or angle brackets in your filename for files inside ".$uploaded_files[$i]["name"][$j].".", false);
                            }
                            $uploaded_files[$i]["is_zip"][$j] = true;
                            $file_size += FileUtils::getZipSize($uploaded_files[$i]["tmp_name"][$j]);
                        }
                        else {
                            if(FileUtils::isValidFileName($uploaded_files[$i]["name"][$j]) === false) {
                                return $this->uploadResult("Error: You may not use quotes, backslashes or angle brackets in your file name ".$uploaded_files[$i]["name"][$j].".", false);
                            }
                            $uploaded_files[$i]["is_zip"][$j] = false;
                            $file_size += $uploaded_files[$i]["size"][$j];
                        }
                    }
                }
                if (isset($previous_files[$i]) && isset($previous_part_path[$i])) {
                    foreach ($previous_files[$i] as $prev_file) {
                        $file_size += filesize(FileUtils::joinPaths($previous_part_path[$i], $prev_file));
                    }
                }
            }
            
            if ($file_size > $max_size) {
                return $this->uploadResult("File(s) uploaded too large.  Maximum size is ".($max_size/1000)." kb. Uploaded file(s) was ".($file_size/1000)." kb.", false);
            }

            for ($i = 1; $i <= $gradeable->getNumParts(); $i++) {
                // copy selected previous submitted files
                if (isset($previous_files[$i])){
                    for ($j=0; $j < count($previous_files[$i]); $j++){
                        $src = FileUtils::joinPaths($previous_part_path[$i], $previous_files[$i][$j]);
                        $dst = FileUtils::joinPaths($part_path[$i], $previous_files[$i][$j]);
                        if (!@copy($src, $dst)) {
                            return $this->uploadResult("Failed to copy previously submitted file {$previous_files[$i][$j]} to current submission.", false);
                        }
                    }
                }

                if (isset($uploaded_files[$i])) {
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if ($uploaded_files[$i]["is_zip"][$j] === true) {
                            $zip = new \ZipArchive();
                            $res = $zip->open($uploaded_files[$i]["tmp_name"][$j]);
                            if ($res === true) {
                                $zip->extractTo($part_path[$i]);
                                $zip->close();
                            }
                            else {
                                // If the zip is an invalid zip (say we remove the last character from the zip file
                                // then trying to get the status code will throw an exception and not give us a string
                                // so we have that string hardcoded, otherwise we can just get the status string as
                                // normal.
                                $error_message = ($res == 19) ? "Invalid or uninitialized Zip object" : $zip->getStatusString();
                                return $this->uploadResult("Could not properly unpack zip file. Error message: ".$error_message.".", false);
                            }
                        }
                        else {
                            if ($this->core->isTesting() || is_uploaded_file($uploaded_files[$i]["tmp_name"][$j])) {
                                $dst = FileUtils::joinPaths($part_path[$i], $uploaded_files[$i]["name"][$j]);
                                if (!@copy($uploaded_files[$i]["tmp_name"][$j], $dst)) {
                                    return $this->uploadResult("Failed to copy uploaded file {$uploaded_files[$i]["name"][$j]} to current submission.", false);
                                }
                            }
                            else {
                                return $this->uploadResult("The tmp file '{$uploaded_files[$i]['name'][$j]}' was not properly uploaded.", false);
                            }
                        }
                        // Is this really an error we should fail on?
                        if (!@unlink($uploaded_files[$i]["tmp_name"][$j])) {
                            return $this->uploadResult("Failed to delete the uploaded file {$uploaded_files[$i]["name"][$j]} from temporary storage.", false);
                        }
                    }
                }
            }
        }
        else {
            if (!@touch(FileUtils::joinPaths($version_path, ".submit.SVN_CHECKOUT"))) {
                return $this->uploadResult("Failed to touch file for svn submission.", false);
            }
        }
    
        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        if (!file_exists($settings_file)) {
            $json = array("active_version" => $new_version,
                          "history" => array(array("version" => $new_version,
                                                   "time" => $current_time)));
        }
        else {
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_version"] = $new_version;
            $json["history"][] = array("version"=> $new_version, "time" => $current_time);
        }
    
        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        }
        
        $this->upload_details['assignment_settings'] = true;

        if (!@file_put_contents(FileUtils::joinPaths($version_path, ".submit.timestamp"), $current_time."\n")) {
            return $this->uploadResult("Failed to save timestamp file for this submission.", false);
        }

        $queue_file = array($this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
            $gradeable->getId(), $who_id, $new_version);
        $queue_file = FileUtils::joinPaths($this->core->getConfig()->getSubmittyPath(), "to_be_graded_interactive",
            implode("__", $queue_file));

        // create json file...
        if ($gradeable->isTeamAssignment()) {
            $queue_data = array("semester" => $this->core->getConfig()->getSemester(),
                                "course" => $this->core->getConfig()->getCourse(),
                                "gradeable" =>  $gradeable->getId(),
                                "user" => $user_id,
                                "team" => $team_id,
                                "who" => $who_id,
                                "is_team" => True,
                                "version" => $new_version);
        }
        else {
            $queue_data = array("semester" => $this->core->getConfig()->getSemester(),
                                "course" => $this->core->getConfig()->getCourse(),
                                "gradeable" =>  $gradeable->getId(),
                                "user" => $user_id,
                                "team" => $team_id,
                                "who" => $who_id,
                                "is_team" => False,
                                "version" => $new_version);
        }
        

        if (@file_put_contents($queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
            return $this->uploadResult("Failed to create file for grading queue.", false);
        }

        if($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), null, $team_id, $new_version, $current_time);
        }
        else {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), $user_id, null, $new_version, $current_time);
        }

        $_SESSION['messages']['success'][] = "Successfully uploaded version {$new_version} for {$gradeable->getName()}";
        return $this->uploadResult("Successfully uploaded files");
    }

    private function uploadResult($message, $success = true) {
        if (!$success) {
            // we don't want to throw an exception here as that'll mess up our return json payload
            if ($this->upload_details['version_path'] !== null
                && !FileUtils::recursiveRmdir($this->upload_details['version_path'])) {
                // @codeCoverageIgnoreStart
                // Without the filesystem messing up here, we should not be able to hit this error
                Logger::error("Could not clean up folder {$this->upload_details['version_path']}");

            }
            // @codeCoverageIgnoreEnd
            else if ($this->upload_details['assignment_settings'] === true) {
                $settings_file = FileUtils::joinPaths($this->upload_details['user_path'], "user_assignment_settings.json");
                $settings = json_decode(file_get_contents($settings_file), true);
                if (count($settings['history']) == 1) {
                    unlink($settings_file);
                }
                else {
                    array_pop($settings['history']);
                    $last = Utils::getLastArrayElement($settings['history']);
                    $settings['active_version'] = $last['version'];
                    file_put_contents($settings_file, FileUtils::encodeJson($settings));
                }
            }
        }

        $return = array('success' => $success, 'error' => !$success, 'message' => $message);
        
        $this->core->getOutput()->renderJson($return);
        return $return;
    }


}
