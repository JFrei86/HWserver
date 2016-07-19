<?php

namespace app\controllers\student;

use app\controllers\IController;
use app\libraries\Core;
use app\libraries\ErrorMessages;
use app\libraries\FileUtils;
use app\libraries\Utils;
use app\libraries\Output;
use app\models\ClassJson;

class SubmissionController implements IController {

    /**
     * @var Core
     */
    private $core;

    /**
     * @var ClassJson
     */
    private $class_info;

    public function __construct(Core $core, ClassJson $class_info) {
        $this->core = $core;
        $this->class_info = $class_info;
    }

    public function run() {
        switch($_REQUEST['action']) {
            case 'upload':
                $this->uploadSubmission();
                break;
            case 'update':
                break;
            case 'display':
            default:
                $this->showHomeworkPage();
                break;
        }
    }

    private function showHomeworkPage() {
        if (count($this->class_info->getAssignments()) > 0) {
            $select = $this->core->getOutput()->renderTemplate(array('submission', 'Homework'), 'assignmentSelect', $this->class_info->getAssignments(), $this->class_info->getCurrentAssignment()->getAssignmentId());
    
            $this->core->getOutput()->renderOutput(array('submission', 'Homework'), 'showAssignment', $select, $this->class_info->getCurrentAssignment());
        }
        else {
            $this->core->getOutput()->renderOutput(array('submission', 'Homework'), 'noAssignments');
        }
    }
    
    /**
     * Function for uploading a submission to the server. This should be called via AJAX, saving the result
     * to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     *
     * @return boolean
     */
    private function uploadSubmission() {
        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->uploadResult("Invalid CSRF token: {$_POST['csrf_token']}.", false);
        }
        $svn_checkout = isset($_REQUEST['svn_checkout']) ? $_REQUEST['svn_checkout'] === "true" : false;
        
        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if (!isset($_REQUEST['assignment_id']) || !array_key_exists($_REQUEST['assignment_id'], $this->class_info->getAssignments())) {
            return $this->uploadResult("Invalid assignment id '{$_REQUEST['assignment_id']}'", false);
        }
    
        $assignment = $this->class_info->getCurrentAssignment();
        $assignment_path = $this->core->getConfig()->getCoursePath()."/submissions/".$assignment->getAssignmentId();
        
        /*
         * Perform checks on the following folders (and whether or not they exist):
         * 1) the assignment folder in the submissions directory
         * 2) the student's folder in the assignment folder
         * 3) the version folder in the student folder
         * 4) the part folders in the version folder in the version folder
         */
        if (!FileUtils::createDir($assignment_path)) {
            return $this->uploadResult("Failed to make folder for this assignment.", false);
        }
    
        $user_path = $assignment_path."/".$this->core->getUser()->getUserId();
        if (!FileUtils::createDir($user_path)) {
                return $this->uploadResult("Failed to make folder for this assignment for the user.", false);
        }
    
        $new_version = $assignment->getHighestVersion() + 1;
        $version_path = $user_path."/".$new_version;
        if (!FileUtils::createDir($version_path)) {
            return $this->uploadResult("Failed to make folder for the current version.", false);
        }
    
        $part_path = array();
        // We upload the assignment such that if it's multiple parts, we put it in folders "part#" otherwise
        // put all files in the root folder
        if ($assignment->getNumParts() > 1) {
            for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                $part_path[$i] = $version_path."/part".$i;
                if (!FileUtils::createDir($part_path[$i])) {
                    return $this->uploadResult("Failed to make the folder for part {$i}.", false);
                }
            }
        }
        else {
            $part_path[1] = $version_path;
        }
        
        $current_time = date("Y-m-d H:i:s");
        $max_size = $assignment->getMaxSize();
        
        if ($svn_checkout === false) {
            $uploaded_files = array();
            for ($i = 0; $i < $assignment->getNumParts(); $i++){
                if (isset($_FILES["files".($i+1)])) {
                    $uploaded_files[$i+1] = $_FILES["files".($i+1)];
                }
            }
            
            $errors = array();
            $count = array();
            for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                if (isset($uploaded_files[$i])) {
                    $count[$i] = count($uploaded_files[$i]["name"]);
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (!isset($uploaded_files[$i]["tmp_name"][$j]) || $uploaded_files[$i]["tmp_name"][$j] == "") {
                            $error_message = $uploaded_files[$i]["name"][$j]." failed to upload. ";
                            if (isset($uploaded_files[$i]["error"][$j])) {
                                $error_message .= "Error message: ". ErrorMessages::uploadErrors($uploaded_files[$i]["error"][$j]);
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
    
            $previous_files = array();
            $previous_part_path = array();
            $tmp = json_decode($_POST['previous_files']);
            for ($i = 0; $i < $assignment->getNumParts(); $i++) {
                if (count($tmp[$i]) > 0) {
                    $previous_files[$i + 1] = $tmp[$i];
                }
            }
            
            if (count($previous_files) > 0) {
                if ($assignment->getHighestVersion() === 0) {
                    return $this->uploadResult("No submission found. There should not be any files kept from previous submission.", false);
                }
                
                $previous_path = $user_path."/".$assignment->getHighestVersion();
                if ($assignment->getNumParts() > 1) {
                    for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                        $previous_part_path[$i] = $previous_path."/part".$i;
                        if (!is_dir($previous_part_path[$i])) {
                            return $this->uploadResult("Files from previous submission not found. Folder for previous submission does not exist.", false);
                        }
                    }
                }
                else {
                    $previous_part_path[1] = $previous_path;
                }
    
                for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                    if (isset($previous_files[$i])) {
                        foreach ($previous_files[$i] as $prev_file) {
                            $filename = $previous_part_path[$i]."/".$prev_file;
                            if (!file_exists($filename)) {
                                return $this->uploadResult("File '{$filename}' does not exist in previous submission.", false);
                            }
                        }
                    }
                }
            }
            
            // Determine the size of the uploaded files as well as whether or not they're a zip or not.
            // We save that information for later so we know which files need unpacking or not and can save
            // a check to getMimeType()
            $file_size = 0;
            for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                if (isset($uploaded_files[$i])) {
                    $uploaded_files[$i]["is_zip"] = array();
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (FileUtils::getMimeType($uploaded_files[$i]["tmp_name"][$j]) == "application/zip") {
                            $uploaded_files[$i]["is_zip"][$j] = true;
                            $file_size += FileUtils::getZipSize($uploaded_files[$i]["tmp_name"][$j]);
                        }
                        else {
                            $uploaded_files[$i]["is_zip"][$j] = false;
                            $file_size += $uploaded_files[$i]["size"][$j];
                        }
                    }
                }
                if (isset($previous_files[$i]) && isset($previous_part_path[$i])) {
                    foreach ($previous_files[$i] as $prev_file) {
                        $file_size += filesize($previous_part_path[$i]."/".$prev_file);
                    }
                }
            }
            
            if ($file_size > $max_size) {
                return $this->uploadResult("File(s) uploaded too large.  Maximum size is ".($max_size/1000)." kb. Uploaded file(s) was ".($file_size/1000)." kb.", false);
            }
            
            for ($i = 1; $i <= $assignment->getNumParts(); $i++) {
                if (isset($uploaded_files[$i])) {
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if ($uploaded_files[$i]["is_zip"][$j] === true) {
                            $zip = new \ZipArchive();
                            $res = $zip->open($uploaded_files[$i]["tmp_name"][$j]);
                            $zip = new \ZipArchive;
                            if ($res === true) {
                                $zip->extractTo($part_path[$i]);
                                $zip->close();
                            }
                            else {
                                return $this->uploadResult("Could not properly unpack zip file. Error message: ".ErrorMessages::uploadErrors($res), false);
                            }
                        }
                        else {
                            if (is_uploaded_file($uploaded_files[$i]["tmp_name"][$j])) {
                                
                                if (!copy($uploaded_files[$i]["tmp_name"][$j], $part_path[$i]."/".$uploaded_files[$i]["name"][$j])) {
                                    return $this->uploadResult("Failed to copy uploaded file ".$uploaded_files[$i]["name"][$j]." to current submission.", false);
                                }
                            }
                            else {
                                return $this->uploadResult("The tmp file '{$uploaded_files[$i]['tmp_name'][$j]}' was not properly uploaded.", false);
                            }
                        }
                        // Is this really an error we should fail on?
                        if (!unlink($uploaded_files[$i]["tmp_name"][$j])) {
                            return $this->uploadResult("Failed to delete the uploaded file ".$uploaded_files[$i]["name"][$j]." from temporary storage.", false);
                        }
                    }
                }
    
                // copy selected previous submitted files
                if (isset($previous_files[$i])){
                    for ($i=0; $i < count($previous_files[$i]); $i++){
                        if (!copy($previous_part_path[$i]."/".$previous_files[$i][$i], $part_path[$i]."/".$previous_files[$i][$i])) {
                            return $this->uploadResult("Failed to copy previously submitted file ".$previous_files[$i][$i]." to current submission.", false);
                        }
                    }
                }
            }
        }
        else {
            if (!touch($version_path."/.submit.SVN_CHECKOUT")) {
                return $this->uploadResult("Failed to touch file for svn submission.", false);
            }
        }
    
        $settings_file = $user_path."/user_assignment_settings.json";
        if (!file_exists($settings_file)) {
            $json = array("active_assignment" => $new_version,
                          "history" => array(array("version" => $new_version,
                                                   "time" => $current_time)));
        }
        else {
            $json = FileUtils::loadJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_assignment"] = $new_version;
            $json["history"][] = array("version"=> $new_version, "time" => $current_time);
        }
    
        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!file_put_contents($settings_file, json_encode($json, JSON_PRETTY_PRINT))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        };
    
        // TODO: should we really be outputting an error on this as we've basically created all other files
        // at this point
        if (!file_put_contents($version_path."/.submit.timestamp", $current_time."\n")) {
            return $this->uploadResult("Failed to save timestamp file for this submission.", false);
        }
    
        $touch_file = array($this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
            $assignment->getAssignmentId(), $this->core->getUser()->getUserId(), $new_version);
        $touch_file = $this->core->getConfig()->getSubmittyPath()."/to_be_graded_interactive/".implode("__", $touch_file);
        if (!touch($touch_file)) {
            return $this->uploadResult("Failed to create file for grading queue.");
        }
        
        return $this->uploadResult("Successfully uploaded files");
    }
    
    private function uploadResult($message, $success = true) {
        $this->core->getOutput()->renderJson(array('success' => $success, 'error' => !$success, 'message' => $message));
        return $success;
    }
}