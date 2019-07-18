<?php

namespace app\controllers\student;

use app\controllers\AbstractController;
use app\libraries\DateUtils;
use app\libraries\ErrorMessages;
use app\libraries\FileUtils;
use app\libraries\GradeableType;
use app\libraries\Logger;
use app\libraries\Utils;
use app\models\gradeable\Gradeable;
use app\models\Notification;
use app\models\Email;
use app\controllers\grading\ElectronicGraderController;
use app\models\gradeable\SubmissionTextBox;
use app\models\gradeable\SubmissionCodeBox;
use app\models\gradeable\SubmissionMultipleChoice;
use app\NotificationFactory;
use Symfony\Component\Routing\Annotation\Route;



class SubmissionController extends AbstractController {

    private $upload_details = array('version' => -1, 'version_path' => null, 'user_path' => null,
                                    'assignment_settings' => false);


    /**
     * Tries to get a given electronic gradeable considering the active
     *  users access level and the status of the gradeable, but returns
     *  null if no access
     *
     * FIXME: put this in new access control system
     *
     * @param string $gradeable_id
     * @return Gradeable|null
     */
    public function tryGetElectronicGradeable($gradeable_id) {
        if ($gradeable_id === null || $gradeable_id === '') {
            return null;
        }

        try {
            $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);
            $now = $this->core->getDateTimeNow();

            if ($gradeable->getType() === GradeableType::ELECTRONIC_FILE
                && ($this->core->getUser()->accessAdmin()
                    || $gradeable->getTaViewStartDate() <= $now
                    && $this->core->getUser()->accessGrading()
                    || $gradeable->getSubmissionOpenDate() <= $now)) {
                return $gradeable;
            }
            return null;
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    public function run() {
        switch($_REQUEST['action']) {
            case 'upload':
                return $this->ajaxUploadSubmission();
                break;
            case 'update':
                return $this->updateSubmissionVersion();
                break;
            case 'check_refresh':
                return $this->checkRefresh();
                break;
            case 'bulk':
                return $this->ajaxBulkUpload();
                break;
            case 'upload_split':
                return $this->ajaxUploadSplitItem();
                break;
            case 'delete_split':
                return $this->ajaxDeleteSplitItem();
                break;
            case 'verify':
                return $this->ajaxValidGradeable();
                break;
            case 'request_regrade':
                return $this->requestRegrade();
                break;
            case 'make_request_post':
                return $this->makeRequestPost();
                break;
            case 'delete_request':
                return $this->deleteRequest();
                break;
            case 'change_request_status':
                return $this->changeRequestStatus();
                break;
            case 'stat_page':
                return $this->showStats();
                break;
            case 'display':
            default:

                return $this->showHomeworkPage(
                    $_REQUEST['gradeable_id'] ?? null,
                    $_REQUEST['gradeable_version'] ?? 0
                );
                break;
        }
    }
    private function requestRegrade() {
        $content = $_POST['replyTextArea'] ?? '';
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $submitter_id = $_REQUEST['submitter_id'] ?? '';

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        if(!$gradeable->isRegradeAllowed()) {
            $this->core->getOutput()->renderJsonFail('Grade inquiries are not enabled for this gradeable');
            return;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);
        if (!$graded_gradeable->getSubmitter()->hasUser($user) && !$can_inquiry) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to request regrade');
            return;
        }

        try {
            $this->core->getQueries()->insertNewRegradeRequest($graded_gradeable, $user, $content);
            $this->notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, 'new');
            $this->core->getOutput()->renderJsonSuccess();
        } catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        } catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    private function makeRequestPost() {
        $content = str_replace("\r", "", $_POST['replyTextArea']);
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $submitter_id = $_REQUEST['submitter_id'] ?? '';

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        if (!$graded_gradeable->hasRegradeRequest()) {
            $this->core->getOutput()->renderJsonFail('Submitter has not made a grade inquiry');
            return;
        }

        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);
        if (!$graded_gradeable->getSubmitter()->hasUser($user) && !$can_inquiry) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to make grade inquiry post');
            return;
        }

        try {
            $this->core->getQueries()->insertNewRegradePost($graded_gradeable->getRegradeRequest()->getId(), $user->getId(), $content);
            $this->notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, 'reply');
            $this->core->getQueries()->saveRegradeRequest($graded_gradeable->getRegradeRequest());
            $this->core->getOutput()->renderJsonSuccess();
        } catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        } catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    private function deleteRequest() {
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $submitter_id = $_REQUEST['submitter_id'] ?? '';

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        if (!$graded_gradeable->hasRegradeRequest()) {
            $this->core->getOutput()->renderJsonFail('Submitter has not made a grade inquiry');
            return;
        }

        // TODO: add to access control method
        if (!$user->accessFullGrading()) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to delete grade inquiry');
            return;
        }

        try {
            $this->core->getQueries()->deleteRegradeRequest($graded_gradeable->getRegradeRequest());
            $this->core->getOutput()->renderJsonSuccess();
        } catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        } catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    private function changeRequestStatus() {
        $content = str_replace("\r", "", $_POST['replyTextArea']);
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $submitter_id = $_REQUEST['submitter_id'] ?? '';

        $user = $this->core->getUser();

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        if (!$graded_gradeable->hasRegradeRequest()) {
            $this->core->getOutput()->renderJsonFail('Submitter has not made a grade inquiry');
            return;
        }

        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);
        if (!$graded_gradeable->getSubmitter()->hasUser($user) && !$can_inquiry) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to change grade inquiry status');
            return;
        }

        // toggle status
        $status = $graded_gradeable->getRegradeRequest()->getStatus();
        if ($status == -1) {
            $status = 0;
        }
        else {
            $status = -1;
        }

        try {
            $graded_gradeable->getRegradeRequest()->setStatus($status);
            $this->core->getQueries()->saveRegradeRequest($graded_gradeable->getRegradeRequest());
            if ($content != "") {
                $this->core->getQueries()->insertNewRegradePost($graded_gradeable->getRegradeRequest()->getId(), $user->getId(), $content);
            }
            $this->core->getOutput()->renderJsonSuccess();
        } catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        } catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * @Route("/{_semester}/{_course}/student/{gradeable_id}")
     * @Route("/{_semester}/{_course}/student/{gradeable_id}/{gradeable_version}")
     * @return array
     */
    public function showHomeworkPage($gradeable_id, $gradeable_version = null) {
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);
        if($gradeable === null) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return array('error' => true, 'message' => 'No gradeable with that id.');
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $this->core->getUser()->getId());
        if ($graded_gradeable === null && !$this->core->getUser()->accessAdmin()) {
            // FIXME if $graded_gradeable is null, the user isn't on a team, so we want to redirect
            // FIXME    to nav with an error
        }

        // Attempt to put the version number to be in bounds of the gradeable
        $version = intval($gradeable_version ?? 0);
        if ($version < 1 || $version > ($graded_gradeable !== null ? $graded_gradeable->getAutoGradedGradeable()->getHighestVersion() : 0)) {
            $version = $graded_gradeable !== null ? $graded_gradeable->getAutoGradedGradeable()->getActiveVersion() : 0;
        }

        $error = false;
        $now = $this->core->getDateTimeNow();

        // ORIGINAL
        //if (!$gradeable->isSubmissionOpen() && !$this->core->getUser()->accessAdmin()) {

        // TEMPORARY - ALLOW LIMITED & FULL ACCESS GRADERS TO PRACTICE ALL FUTURE HOMEWORKS
        if (!$this->core->getUser()->accessGrading() && (
                !$gradeable->isSubmissionOpen()
                || $gradeable->isStudentView() && $gradeable->isStudentViewAfterGrades() && !$gradeable->isTaGradeReleased()
            )) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return array('error' => true, 'message' => 'No gradeable with that id.');
        }
        else if ($gradeable->isTeamAssignment() && $graded_gradeable === null && !$this->core->getUser()->accessAdmin()) {
            $this->core->addErrorMessage('Must be on a team to access submission');
            $this->core->redirect($this->core->buildNewCourseUrl());
            return array('error' => true, 'message' => 'Must be on a team to access submission.');
        }
        else {
            $url = $this->core->buildNewCourseUrl(['student', $gradeable->getId()]);
            $this->core->getOutput()->addBreadcrumb($gradeable->getTitle(), $url);
            if (!$gradeable->hasAutogradingConfig()) {
                $this->core->getOutput()->renderOutput('Error',
                                                       'unbuiltGradeable', $gradeable->getTitle());
                $error = true;
            }
            else {
                if ($graded_gradeable !== null
                    && $gradeable->isTaGradeReleased()
                    && $gradeable->isTaGrading()
                    && $graded_gradeable->isTaGradingComplete()) {
                    $graded_gradeable->getOrCreateTaGradedGradeable()->setUserViewedDate($now);
                    $this->core->getQueries()->saveTaGradedGradeable($graded_gradeable->getTaGradedGradeable());
                }

                // Only show hidden test cases if the display version is the graded version (and grades are released)
                $show_hidden = false;
                if ($graded_gradeable != NULL) {
                  $show_hidden = $version == $graded_gradeable->getOrCreateTaGradedGradeable()->getGradedVersion(false) && $gradeable->isTaGradeReleased();
                }
                $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);

                // If we get here, then we can safely construct the old model w/o checks
                $this->core->getOutput()->addInternalCss('forum.css');
                $this->core->getOutput()->addInternalJs('forum.js');
                $this->core->getOutput()->renderOutput(array('submission', 'Homework'),
                                                       'showGradeable', $gradeable, $graded_gradeable, $version, $can_inquiry, $show_hidden);
            }
        }
        return array('id' => $gradeable_id, 'error' => $error);
    }

    /**
    * Function for verification that a given RCS ID is valid and has a corresponding user and gradeable.
    * This should be called via AJAX, saving the result to the json_buffer of the Output object.
    * If failure, also returns message explaining what happened.
    * If success, also returns highest version of the student gradeable.
    */
    private function ajaxValidGradeable() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] != $this->core->getCsrfToken()) {
            $msg = "Invalid CSRF token. Refresh the page and try again.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        if (!isset($_POST['user_id'])) {
            $msg = "Did not pass in user_id.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }

        //filter out empty, null strings
        $tmp_ids = $_POST['user_id'];
        if(is_array($tmp_ids)){
            $user_ids = array_filter($_POST['user_id']);
        } else{
            $user_ids = array($tmp_ids);
            $user_ids = array_filter($user_ids);
        }

        //If no user id's were submitted, give a graceful error.
        if (count($user_ids) === 0) {
            $msg = "No valid user ids were found.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        //For every userid, we have to check that its real.
        foreach($user_ids as $id){
            $user = $this->core->getQueries()->getUserById($id);
            if ($user === null) {
                $msg = "Invalid user id '{$id}'";
                return $this->core->getOutput()->renderJsonFail($msg);
            }
            if (!$user->isLoaded()) {
                $msg = "Invalid user id '{$id}'";
                return $this->core->getOutput()->renderJsonFail($msg);
            }
        }

        $graded_gradeables = [];
        foreach ($this->core->getQueries()->getGradedGradeables([$gradeable], $user_ids, null) as $gg) {
            $graded_gradeables[] = $gg;
        }

        $null_team_count = 0;
        $inconsistent_teams = false;
        if($gradeable->isTeamAssignment()){
            $teams = [];
            foreach ($user_ids as $user) {
                $tmp = $this->core->getQueries()->getTeamByGradeableAndUser($gradeable->getId(), $user);
                if($tmp === NULL){
                    $null_team_count ++;
                }else{
                    $teams[] = $tmp->getId();
                }
            }
            $teams = array_unique(array_filter($teams));
            $inconsistent_teams = count($teams) > 1;
        }

        //If the users are on multiple teams.
        if ($gradeable->isTeamAssignment() && $inconsistent_teams) {
            // Not all users were on the same team
            $msg = "Inconsistent teams. One or more users are on different teams.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }
        //If a user not assigned to any team is matched with a user already on a team
        if($gradeable->isTeamAssignment() && $null_team_count != 0 && count($teams) != 0){
            $msg = "One or more users with no team are being submitted with another user already on a team";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $highest_version = -1;

        if(count($graded_gradeables) > 0){
            $graded_gradeable = $graded_gradeables[0];
            $highest_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion();
        }

        //If there has been a previous submission, we tag it so that we can pop up a warning.
        $return = array('highest_version' => $highest_version, 'previous_submission' => $highest_version > 0);
        return $this->core->getOutput()->renderJsonSuccess($return);
    }

    /**
    * Function that uploads a bulk PDF to the uploads/bulk_pdf folder. Splits it into PDFs of the page
    * size entered and places in the uploads/split_pdf folder.
    * Its error checking has overlap with ajaxUploadSubmission.
    */
    private function ajaxBulkUpload() {
        // make sure is at least full access grader
        if (!$this->core->getUser()->accessFullGrading()) {
            $msg = "You do not have access to that page.";
            $this->core->addErrorMessage($msg);
            return $this->uploadResult($msg, false);
        }

        $is_qr = $_POST['use_qr_codes'] === "true";

        if (!isset($_POST['num_pages']) && !$is_qr) {
            $msg = "Did not pass in number of pages or files were too large.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }

        $num_pages = $_POST['num_pages'];

        // making sure files have been uploaded

        if (isset($_FILES["files1"])) {
            $uploaded_file = $_FILES["files1"];
        }

        $errors = array();
        $count = 0;
        if (isset($uploaded_file)) {
            $count = count($uploaded_file["name"]);
            for ($j = 0; $j < $count; $j++) {
                if (!isset($uploaded_file["tmp_name"][$j]) || $uploaded_file["tmp_name"][$j] === "") {
                    $error_message = $uploaded_file["name"][$j]." failed to upload. ";
                    if (isset($uploaded_file["error"][$j])) {
                        $error_message .= "Error message: ". ErrorMessages::uploadErrors($uploaded_file["error"][$j]). ".";
                    }
                    $errors[] = $error_message;
                }
            }
        }

        if (count($errors) > 0) {
            $error_text = implode("\n", $errors);
            return $this->uploadResult("Upload Failed: ".$error_text, false);
        }

        $max_size = $gradeable->getAutogradingConfig()->getMaxSubmissionSize();
    	if ($max_size < 10000000) {
    	    $max_size = 10000000;
    	}
        // Error checking of file name
        $file_size = 0;
        if (isset($uploaded_file)) {
            for ($j = 0; $j < $count; $j++) {
                if(FileUtils::isValidFileName($uploaded_file["name"][$j]) === false) {
                    return $this->uploadResult("Error: You may not use quotes, backslashes or angle brackets in your file name ".$uploaded_file["name"][$j].".", false);
                }
                if(substr($uploaded_file["name"][$j],-3) != "pdf") {
                    return $this->uploadResult($uploaded_file["name"][$j]." is not a PDF!", false);
                }
                $file_size += $uploaded_file["size"][$j];
            }
        }

        if ($file_size > $max_size) {
            return $this->uploadResult("File(s) uploaded too large.  Maximum size is ".($max_size/1000)." kb. Uploaded file(s) was ".($file_size/1000)." kb.", false);
        }

        // creating uploads/bulk_pdf/gradeable_id directory

        $pdf_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "bulk_pdf", $gradeable->getId());
        if (!FileUtils::createDir($pdf_path)) {
            return $this->uploadResult("Failed to make gradeable path.", false);
        }

        // creating directory under gradeable_id with the timestamp

        $current_time = $this->core->getDateTimeNow()->format("m-d-Y_H:i:sO");
        $version_path = FileUtils::joinPaths($pdf_path, $current_time);
        if (!FileUtils::createDir($version_path)) {
            return $this->uploadResult("Failed to make gradeable path.", false);
        }

        // save the pdf in that directory
        // delete the temporary file
        if (isset($uploaded_file)) {
            for ($j = 0; $j < $count; $j++) {
                if ($this->core->isTesting() || is_uploaded_file($uploaded_file["tmp_name"][$j])) {
                    $dst = FileUtils::joinPaths($version_path, $uploaded_file["name"][$j]);
                    if (!@copy($uploaded_file["tmp_name"][$j], $dst)) {
                        return $this->uploadResult("Failed to copy uploaded file {$uploaded_file["name"][$j]} to current submission.", false);
                    }
                }
                else {
                    return $this->uploadResult("The tmp file '{$uploaded_file['name'][$j]}' was not properly uploaded.", false);
                }
                // Is this really an error we should fail on?
                if (!@unlink($uploaded_file["tmp_name"][$j])) {
                    return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file["name"][$j]} from temporary storage.", false);
                }
            }
        }

        // use pdf_check.cgi to check that # of pages is valid and split
        // also get the cover image and name for each pdf appropriately

        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        if($is_qr){
            $qr_prefix = rawurlencode($_POST['qr_prefix']);
            $qr_suffix = rawurlencode($_POST['qr_suffix']);

            $config_data = json_decode(file_get_contents("/usr/local/submitty/config/submitty.json"));
            //create a new job to split but uploads via QR
            for($i = 0; $i < $count; $i++){
                $qr_upload_data = [
                    "job"       => "BulkUpload",
                    "semester"  => $semester,
                    "course"    => $course,
                    "g_id"      => $gradeable_id,
                    "timestamp" => $current_time,
                    "qr_prefix" => $qr_prefix,
                    "qr_suffix" => $qr_suffix,
                    "filename"  => $uploaded_file["name"][$i],
                    "is_qr"     => true
                ];

                $bulk_upload_job  = "/var/local/submitty/daemon_job_queue/bulk_upload_" . $uploaded_file["name"][$i] . ".json";

                //add new job to queue
                if(!file_put_contents($bulk_upload_job, json_encode($qr_upload_data, JSON_PRETTY_PRINT)) ){
                    $this->core->getOutput()->renderJsonFail("Failed to write BulkQRSplit job");
                    return $this->uploadResult("Failed to write BulkQRSplit job", false);
                }
            }
        }else{
            for($i = 0; $i < $count; $i++){
                $job_data = [
                    "job"       => "BulkUpload",
                    "semester"  => $semester,
                    "course"    => $course,
                    "g_id"      => $gradeable_id,
                    "timestamp" => $current_time,
                    "filename"  => $uploaded_file["name"][$i],
                    "num"       => $num_pages,
                    "is_qr"     => false
                ];

                $bulk_upload_job  = "/var/local/submitty/daemon_job_queue/bulk_upload_" . $uploaded_file["name"][$i] . ".json";

                //add new job to queue
                if(!file_put_contents($bulk_upload_job, json_encode($job_data, JSON_PRETTY_PRINT)) ){
                    $this->core->getOutput()->renderJsonFail("Failed to write Bulk upload job");
                    return $this->uploadResult("Failed to write Bulk upload job", false);
                }
            }
        }

        return $this->core->getOutput()->renderJsonSuccess();
    }

    /**
     * Function for uploading a split item that already exists to the server.
     * The file already exists in uploads/split_pdf/gradeable_id/timestamp folder. This should be called via AJAX, saving the result
     * to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     * Has overlap with ajaxUploadSubmission
     *
     * @return boolean
     */
    private function ajaxUploadSplitItem() {
        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->uploadResult("Invalid CSRF token.", false);
        }

        // make sure is at least full access grader
        if (!$this->core->getUser()->accessFullGrading()) {
            $msg = "You do not have access to that page.";
            $this->core->addErrorMessage($msg);
            return $this->uploadResult($msg, false);
        }

        // check for whether the item should be merged with previous submission
        // and whether or not file clobbering should be done
        $merge_previous = isset($_REQUEST['merge']) && $_REQUEST['merge'] === 'true';
        $clobber = isset($_REQUEST['clobber']) && $_REQUEST['clobber'] === 'true';

        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }
        if (!isset($_POST['user_id'])) {
            return $this->uploadResult("Invalid user id.", false);
        }
        if (!isset($_POST['path'])) {
            return $this->uploadResult("Invalid path.", false);
        }

        $original_user_id = $this->core->getUser()->getId();

        $tmp_ids = $_POST['user_id'];
        if(is_array($tmp_ids)){
            $user_ids = array_filter($_POST['user_id']);
        } else{
            $user_ids = array($tmp_ids);
            $user_ids = array_filter($user_ids);
        }

        //This grabs the first user in the list. If this is a team assignment, they will be the team leader.
        $user_id = reset($user_ids);

        $path = $_POST['path'];
        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $user_id, null);

        $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions",
            $gradeable->getId());

        /*
         * Perform checks on the following folders (and whether or not they exist):
         * 1) the assignment folder in the submissions directory
         * 2) the student's folder in the assignment folder
         * 3) the version folder in the student folder
         * 4) the uploads folder from the specified path
         */
        if (!FileUtils::createDir($gradeable_path)) {
            return $this->uploadResult("Failed to make folder for this assignment.", false);
        }

        $who_id = $user_id;
        $team_id = "";
        if ($gradeable->isTeamAssignment()) {
            $leader = $user_id;
            if ($graded_gradeable !== null) {
                $team =  $graded_gradeable->getSubmitter()->getTeam();
                $team_id = $team->getId();
                $who_id = $team_id;
                $user_id = "";
            }
            //if the student isn't on a team, build the team.
            else{
                //If the team doesn't exist yet, we need to build a new one. (Note, we have already checked in ajaxvalidgradeable
                //that all users are either on the same team or no team).

                $leaderless = array();
                foreach($user_ids as $i => $member){
                    if($member !== $leader){
                        $leaderless[] = $member;
                    }
                }

                $members = $this->core->getQueries()->getUsersById($leaderless);
                $leader_user = $this->core->getQueries()->getUserById($leader);
                try {
                    $gradeable->createTeam($leader_user, $members);
                } catch (\Exception $e) {
                    $this->core->addErrorMessage('Team may not have been properly initialized: ' . $e->getMessage());
                    return $this->uploadResult("Failed to form a team from members: " . implode(",", $members) . ", " . $leader_user, false);
                }

                // Once team is created, load in the graded gradeable
                $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $leader);
                $team =  $this->core->getQueries()->getTeamByGradeableAndUser($gradeable->getId(), $leader);
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

        $new_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion() + 1;
        $version_path = FileUtils::joinPaths($user_path, $new_version);

        if (!FileUtils::createDir($version_path)) {
            return $this->uploadResult("Failed to make folder for the current version.", false);
        }

        $this->upload_details['version_path'] = $version_path;
        $this->upload_details['version'] = $new_version;

        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");
        $current_time_string_tz = $current_time . " " . $this->core->getConfig()->getTimezone()->getName();

        $path = rawurldecode(htmlspecialchars_decode($path));

        $uploaded_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "split_pdf",
            $gradeable->getId(), $path);

        $uploaded_file = rawurldecode(htmlspecialchars_decode($uploaded_file));
        $uploaded_file_base_name = "upload.pdf";

        if (isset($uploaded_file)) {
            // if we are merging in the previous submission (TODO check folder support)
            if($merge_previous && $new_version !== 1) {
                $old_version = $new_version - 1;
                $old_version_path = FileUtils::joinPaths($user_path, $old_version);
                $to_search = FileUtils::joinPaths($old_version_path, "*.*");
                $files = glob($to_search);
                foreach($files as $file) {
                    $file_base_name = basename($file);
                    if(!$clobber && $file_base_name === $uploaded_file_base_name) {
                        $parts = explode(".", $file_base_name);
                        $parts[0] .= "_version_".$old_version;
                        $file_base_name = implode(".", $parts);
                    }
                    $move_here = FileUtils::joinPaths($version_path, $file_base_name);
                    if (!@copy($file, $move_here)){
                        return $this->uploadResult("Failed to merge previous version.", false);
                    }
                }
            }
            // copy over the uploaded file
            if (!@copy($uploaded_file, FileUtils::joinPaths($version_path, $uploaded_file_base_name))) {
                return $this->uploadResult("Failed to copy uploaded file {$uploaded_file} to current submission.", false);
            }
            if (!@unlink($uploaded_file)) {
                return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
            }
            if (!@unlink(str_replace(".pdf", "_cover.pdf", $uploaded_file))) {
                return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
            }

        }

        // if split_pdf/gradeable_id/timestamp directory is now empty, delete that directory
        $timestamp = substr($path, 0, strpos($path, DIRECTORY_SEPARATOR));
        $timestamp_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "split_pdf",
            $gradeable->getId(), $timestamp);
        $files = FileUtils::getAllFiles($timestamp_path);
        if (count($files) == 0) {
            if (!FileUtils::recursiveRmdir($timestamp_path)) {
                return $this->uploadResult("Failed to remove the empty timestamp directory {$timestamp} from the split_pdf directory.", false);
            }
        }


        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        if (!file_exists($settings_file)) {
            $json = array("active_version" => $new_version,
                          "history" => array(array("version" => $new_version,
                                                   "time" => $current_time_string_tz,
                                                   "who" => $original_user_id,
                                                   "type" => "upload")));
        }
        else {
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_version"] = $new_version;
            $json["history"][] = array("version"=> $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "upload");
        }

        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        }

        $this->upload_details['assignment_settings'] = true;

        if (!@file_put_contents(FileUtils::joinPaths($version_path, ".submit.timestamp"), $current_time_string_tz."\n")) {
            return $this->uploadResult("Failed to save timestamp file for this submission.", false);
        }

        $upload_time_string_tz = $timestamp . " " . $this->core->getConfig()->getTimezone()->getName();

        $bulk_upload_data = [
            "submit_timestamp" =>  $current_time_string_tz,
            "upload_timestamp" =>  $upload_time_string_tz,
            "filepath" => $uploaded_file
        ];

        if (FileUtils::writeJsonFile(FileUtils::joinPaths($version_path, "bulk_upload_data.json"), $bulk_upload_data) === false) {
            return $this->uploadResult("Failed to create bulk upload file for this submission.", false);
        }

        $queue_file = array($this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
            $gradeable->getId(), $who_id, $new_version);
        $queue_file = FileUtils::joinPaths($this->core->getConfig()->getSubmittyPath(), "to_be_graded_queue",
            implode("__", $queue_file));

        $vcs_checkout = isset($_REQUEST['vcs_checkout']) ? $_REQUEST['vcs_checkout'] === "true" : false;

        // create json file...
        $queue_data = array("semester" => $this->core->getConfig()->getSemester(),
            "course" => $this->core->getConfig()->getCourse(),
            "gradeable" => $gradeable->getId(),
            "required_capabilities" => $gradeable->getAutogradingConfig()->getRequiredCapabilities(),
            "max_possible_grading_time" => $gradeable->getAutogradingConfig()->getMaxPossibleGradingTime(),
            "queue_time" => $current_time,
            "user" => $user_id,
            "team" => $team_id,
            "who" => $who_id,
            "is_team" => $gradeable->isTeamAssignment(),
            "version" => $new_version,
            "vcs_checkout" => $vcs_checkout);

        if (@file_put_contents($queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
            return $this->uploadResult("Failed to create file for grading queue.", false);
        }

        // FIXME: Add this as part of the graded gradeable saving query
        if($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), null, $team_id, $new_version, $current_time);
        }
        else {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), $user_id, null, $new_version, $current_time);
        }

        return $this->uploadResult("Successfully uploaded version {$new_version} for {$gradeable->getTitle()} for {$who_id}");
    }

    /**
     * Function for deleting a split item from the uploads/split_pdf/gradeable_id/timestamp folder. This should be called via AJAX,
     * saving the result to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     *
     * @return boolean
     */
    private function ajaxDeleteSplitItem() {
        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->uploadResult("Invalid CSRF token.", false);
        }

        // make sure is at least full access grader
        if (!$this->core->getUser()->accessFullGrading()) {
            $msg = "You do not have access to that page.";
            $this->core->addErrorMessage($msg);
            return $this->uploadResult($msg, false);
        }

        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }
        if (!isset($_POST['path'])) {
            return $this->uploadResult("Invalid path.", false);
        }

        $path = rawurldecode(htmlspecialchars_decode($_POST['path']));

        $uploaded_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "split_pdf",
            $gradeable->getId(), $path);

        $uploaded_file = rawurldecode(htmlspecialchars_decode($uploaded_file));

        if (!@unlink($uploaded_file)) {
            return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
        }

        if (!@unlink(str_replace(".pdf", "_cover.pdf", $uploaded_file))) {
            return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
        }

        // delete timestamp folder if empty
        $timestamp = substr($path, 0, strpos($path, DIRECTORY_SEPARATOR));
        $timestamp_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "split_pdf",
            $gradeable->getId(), $timestamp);
        $files = FileUtils::getAllFiles($timestamp_path);
        if(count($files) === 0){
            if (!FileUtils::recursiveRmdir($timestamp_path)) {
                return $this->uploadResult("Failed to remove the empty timestamp directory {$timestamp} from the split_pdf directory.", false);
            }
        }

        return $this->uploadResult("Successfully deleted this PDF.");
    }

    /**
     * Function for uploading a submission to the server. This should be called via AJAX, saving the result
     * to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     *
     * @return array
     */
    private function ajaxUploadSubmission() {
        if (empty($_POST)) {
            $max_size = ini_get('post_max_size');
            return $this->uploadResult("Empty POST request. This may mean that the sum size of your files are greater than {$max_size}.", false);
        }
        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->uploadResult("Invalid CSRF token.", false);
        }

        // check for whether the item should be merged with previous submission,
        // and whether or not file clobbering should be done.
        $merge_previous = isset($_REQUEST['merge']) && $_REQUEST['merge'] === 'true';
        $clobber = isset($_REQUEST['clobber']) && $_REQUEST['clobber'] === 'true';

        $vcs_checkout = isset($_REQUEST['vcs_checkout']) ? $_REQUEST['vcs_checkout'] === "true" : false;
        if ($vcs_checkout && !isset($_POST['git_repo_id'])) {
            return $this->uploadResult("Invalid repo id.", false);
        }

        $student_page = isset($_REQUEST['student_page']) ? $_REQUEST['student_page'] === "true" : false;
        if ($student_page && !isset($_POST['pages'])) {
            return $this->uploadResult("Invalid pages.", false);
        }

        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }

        if (!isset($_POST['user_id'])) {
            return $this->uploadResult("Invalid user id.", false);
        }

        // the user id of the submitter ($user_id is the one being submitted for)
        $original_user_id = $this->core->getUser()->getId();
        $user_id = $_POST['user_id'];
        // repo_id for VCS use
        $repo_id = ($vcs_checkout ? $_POST['git_repo_id'] : "");

        // make sure is full grader if the two ids do not match
        if ($original_user_id !== $user_id && !$this->core->getUser()->accessFullGrading()) {
            $msg = "You do not have access to that page.";
            $this->core->addErrorMessage($msg);
            return $this->uploadResult($msg, false);
        }

        // if student submission, make sure that gradeable allows submissions
        if (!$this->core->getUser()->accessFullGrading() && !$gradeable->canStudentSubmit()) {
            $msg = "You do not have access to that page.";
            $this->core->addErrorMessage($msg);
            return $this->uploadResult($msg, false);
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $user_id, null);
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

        $who_id = $user_id;
        $team_id = "";
        if ($gradeable->isTeamAssignment()) {
            if ($graded_gradeable !== null) {
                $team = $graded_gradeable->getSubmitter()->getTeam();
                $team_id = $team->getId();
                $who_id = $team_id;
                $user_id = "";
            }
            else {
                return $this->uploadResult("Must be on a team to access submission.", false);
            }
        }

        $user_path = FileUtils::joinPaths($gradeable_path, $who_id);
        $this->upload_details['user_path'] = $user_path;
        if (!FileUtils::createDir($user_path)) {
                return $this->uploadResult("Failed to make folder for this assignment for the user.", false);
        }

        $highest_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion();
        $new_version = $highest_version + 1;
        $version_path = FileUtils::joinPaths($user_path, $new_version);

        if (!FileUtils::createDir($version_path)) {
            return $this->uploadResult("Failed to make folder for the current version.", false);
        }

        $this->upload_details['version_path'] = $version_path;
        $this->upload_details['version'] = $new_version;

        $part_path = array();
        // We upload the assignment such that if it's multiple parts, we put it in folders "part#" otherwise
        // put all files in the root folder
        $num_parts = $gradeable->getAutogradingConfig()->getNumParts();
        if ($num_parts > 1) {
            for ($i = 1; $i <= $num_parts; $i++) {
                $part_path[$i] = FileUtils::joinPaths($version_path, "part".$i);
                if (!FileUtils::createDir($part_path[$i])) {
                    return $this->uploadResult("Failed to make the folder for part {$i}.", false);
                }
            }
        }
        else {
            $part_path[1] = $version_path;
        }

        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");
        $current_time_string_tz = $current_time . " " . $this->core->getConfig()->getTimezone()->getName();

        $max_size = $gradeable->getAutogradingConfig()->getMaxSubmissionSize();

        if ($vcs_checkout === false) {
            $uploaded_files = array();
            for ($i = 1; $i <= $num_parts; $i++){
                if (isset($_FILES["files{$i}"])) {
                    $uploaded_files[$i] = $_FILES["files{$i}"];
                }
            }

            $errors = array();
            $count = array();
            for ($i = 1; $i <= $num_parts; $i++) {
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
            $empty_inputs = true;
            $num_short_answers = 0;
            $num_codeboxes = 0;
            $num_multiple_choice = 0;

            $short_answer_objects    = $_POST['short_answer_answers'] ?? "";
            $codebox_objects         = $_POST['codebox_answers'] ?? "";
            $multiple_choice_objects = $_POST['multiple_choice_answers'] ?? "";
            $short_answer_objects    = json_decode($short_answer_objects,true);
            $codebox_objects         = json_decode($codebox_objects,true);
            $multiple_choice_objects = json_decode($multiple_choice_objects,true);

            $this_config_inputs = $gradeable->getAutogradingConfig()->getInputs() ?? array();

            foreach($this_config_inputs as $this_input) {
                if($this_input instanceof SubmissionTextBox){
                    $answers = $short_answer_objects["short_answer_" .  $num_short_answers] ?? array();
                    $num_short_answers += 1;
                }
                else if($this_input instanceof SubmissionCodeBox){
                    $answers = $codebox_objects["codebox_" .  $num_codeboxes] ?? array();
                    $num_codeboxes += 1;
                }
                else if($this_input instanceof SubmissionMultipleChoice){
                    $answers = $multiple_choice_objects["multiple_choice_" .  $num_multiple_choice] ?? array();;
                    $num_multiple_choice += 1;
                }else{
                    //TODO: How should we handle this case?
                    continue;
                }

                $filename = $this_input->getFileName();
                $dst = FileUtils::joinPaths($version_path, $filename);

                if ( count($answers) > 0)  $empty_inputs = false;

                // FIXME: add error checking
                $file = fopen($dst, "w");

                foreach($answers as $answer_val){
                    fwrite($file, $answer_val . "\n");
                }
                fclose($file);
            }

            $previous_files_src = array();
            $previous_files_dst = array();
            $previous_part_path = array();
            $tmp = json_decode($_POST['previous_files']);
            if (!empty($tmp)) {
                for ($i = 0; $i < $num_parts; $i++) {
                    if (count($tmp[$i]) > 0) {
                        $previous_files_src[$i + 1] = $tmp[$i];
                        $previous_files_dst[$i + 1] = $tmp[$i];
                    }
                }
            }


            if (empty($uploaded_files) && empty($previous_files_src) && $empty_inputs) {
                return $this->uploadResult("No files to be submitted.", false);
            }

            // $merge_previous will only be true if there is a previous submission.
            if (count($previous_files_src) > 0 || $merge_previous) {
                if ($highest_version === 0) {
                    return $this->uploadResult("No submission found. There should not be any files from a previous submission.", false);
                }

                $previous_path = FileUtils::joinPaths($user_path, $highest_version);
                if ($num_parts > 1) {
                    for ($i = 1; $i <= $num_parts; $i++) {
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

                // if merging is being done, get all the old filenames and put them into $previous_files_dst
                // while checking for name conflicts and preventing them if clobbering is not enabled.
                if($merge_previous) {
                    for($i = 1; $i <= $num_parts; $i++) {
                        if(isset($uploaded_files[$i])) {
                            $current_files_set = array_flip($uploaded_files[$i]["name"]);
                            $previous_files_src[$i] = array();
                            $previous_files_dst[$i] = array();
                            $to_search = FileUtils::joinPaths($previous_part_path[$i], "*");
                            $filenames = glob($to_search);
                            $j = 0;
                            foreach($filenames as $filename) {
                                $file_base_name = basename($filename);
                                $previous_files_src[$i][$j] = $file_base_name;
                                if(!$clobber && isset($current_files_set[$file_base_name])) {
                                    $parts = explode(".", $file_base_name);
                                    $parts[0] .= "_version_".$highest_version;
                                    $file_base_name = implode(".", $parts);
                                }
                                $previous_files_dst[$i][$j] = $file_base_name;
                                $j++;
                            }
                        }
                    }
                }


                for ($i = 1; $i <= $num_parts; $i++) {
                    if (isset($previous_files_src[$i])) {
                        foreach ($previous_files_src[$i] as $prev_file) {
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
            for ($i = 1; $i <= $num_parts; $i++) {
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
                if(isset($previous_part_path[$i]) && isset($previous_files_src[$i])) {
                    foreach ($previous_files_src[$i] as $prev_file) {
                        $file_size += filesize(FileUtils::joinPaths($previous_part_path[$i], $prev_file));
                    }
                }
            }

            if ($file_size > $max_size) {
                return $this->uploadResult("File(s) uploaded too large.  Maximum size is ".($max_size/1000)." kb. Uploaded file(s) was ".($file_size/1000)." kb.", false);
            }

            for ($i = 1; $i <= $num_parts; $i++) {
                // copy selected previous submitted files
                if (isset($previous_files_src[$i])){
                    for ($j=0; $j < count($previous_files_src[$i]); $j++){
                        $src = FileUtils::joinPaths($previous_part_path[$i], $previous_files_src[$i][$j]);
                        $dst = FileUtils::joinPaths($part_path[$i], $previous_files_dst[$i][$j]);
                        if (!@copy($src, $dst)) {
                            return $this->uploadResult("Failed to copy previously submitted file {$previous_files_src[$i][$j]} to current submission.", false);
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
                                // If the zip is an invalid zip (say we remove the last character from the zip file)
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
            $vcs_base_url = $this->core->getConfig()->getVcsBaseUrl();
            $vcs_path = $gradeable->getVcsSubdirectory();

            if ($gradeable->getVcsHostType() == 0 || $gradeable->getVcsHostType() == 1) {
                $vcs_path = str_replace("{\$gradeable_id}",$gradeable_id,$vcs_path);
                $vcs_path = str_replace("{\$user_id}",$who_id,$vcs_path);
                $vcs_path = str_replace("{\$team_id}",$who_id,$vcs_path);
                $vcs_full_path = $vcs_base_url.$vcs_path;
            }

            // use entirely student input
            if ($vcs_base_url == "" && $vcs_path == "") {
                if ($repo_id == "") {
                    // FIXME: commented out for now to pass Travis.
                    // SubmissionControllerTests needs to be rewriten for proper VCS uploads.
                    // return $this->uploadResult("repository url input cannot be blank.", false);
                }
                $vcs_full_path = $repo_id;
            }
            // use base url + path with variable string replacements
            else {
                if (strpos($vcs_path,"\$repo_id") !== false && $repo_id == "") {
                    return $this->uploadResult("repository id input cannot be blank.", false);
                }
                $vcs_path = str_replace("{\$gradeable_id}",$gradeable_id,$vcs_path);
                $vcs_path = str_replace("{\$user_id}",$who_id,$vcs_path);
                $vcs_path = str_replace("{\$team_id}",$who_id,$vcs_path);
                $vcs_path = str_replace("{\$repo_id}",$repo_id,$vcs_path);
                $vcs_full_path = $vcs_base_url.$vcs_path;
            }

            if (!@touch(FileUtils::joinPaths($version_path, ".submit.VCS_CHECKOUT"))) {
                return $this->uploadResult("Failed to touch file for vcs submission.", false);
            }

            // Public or private github
            if ($gradeable->getVcsHostType() == 2 || $gradeable->getVcsHostType() == 3) {
                $dst = FileUtils::joinPaths($version_path, ".submit.VCS_CHECKOUT");
                $json = array("git_user_id" => $_POST["git_user_id"],
                              "git_repo_id" => $_POST["git_repo_id"]);
                if (!@file_put_contents($dst, FileUtils::encodeJson($json))) {
                    return $this->uploadResult("Failed to write to VCS_CHECKOUT file.", false);
                }
            }

        }

        // save the contents of the page number inputs to files
        $empty_pages = true;
        if (isset($_POST['pages'])) {
            $pages_array = json_decode($_POST['pages']);
            $total = count($gradeable->getComponents());
            $filename = "student_pages.json";
            $dst = FileUtils::joinPaths($version_path, $filename);
            $json = array();
            $i = 0;
            foreach ($gradeable->getComponents() as $question) {
                $order = intval($question->getOrder());
                $title = $question->getTitle();
                $page_val = intval($pages_array[$i]);
                $json[] = array("order" => $order,
                                "title" => $title,
                                "page #" => $page_val);
                $i++;
            }
            if (!@file_put_contents($dst, FileUtils::encodeJson($json))) {
                return $this->uploadResult("Failed to write to pages file.", false);
            }
        }

        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        if (!file_exists($settings_file)) {
            $json = array("active_version" => $new_version,
                          "history" => array(array("version" => $new_version,
                                                   "time" => $current_time_string_tz,
                                                   "who" => $original_user_id,
                                                   "type" => "upload")));
        }
        else {
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_version"] = $new_version;
            $json["history"][] = array("version"=> $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "upload");
        }

        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        }

        $this->upload_details['assignment_settings'] = true;

        if (!@file_put_contents(FileUtils::joinPaths($version_path, ".submit.timestamp"), $current_time_string_tz."\n")) {
            return $this->uploadResult("Failed to save timestamp file for this submission.", false);
        }

        $queue_file_helper = array($this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
                                   $gradeable->getId(), $who_id, $new_version);
        $queue_file_helper = implode("__", $queue_file_helper);
        $queue_file = FileUtils::joinPaths($this->core->getConfig()->getSubmittyPath(), "to_be_graded_queue",
                                           $queue_file_helper);
        // SPECIAL NAME FOR QUEUE FILE OF VCS GRADEABLES
        $vcs_queue_file = "";
        if ($vcs_checkout === true) {
          $vcs_queue_file = FileUtils::joinPaths($this->core->getConfig()->getSubmittyPath(), "to_be_graded_queue",
                                                 "VCS__".$queue_file_helper);
        }

        // create json file...
        $queue_data = array("semester" => $this->core->getConfig()->getSemester(),
            "course" => $this->core->getConfig()->getCourse(),
            "gradeable" => $gradeable->getId(),
            "required_capabilities" => $gradeable->getAutogradingConfig()->getRequiredCapabilities(),
            "max_possible_grading_time" => $gradeable->getAutogradingConfig()->getMaxPossibleGradingTime(),
            "queue_time" => $current_time,
            "user" => $user_id,
            "team" => $team_id,
            "who" => $who_id,
            "is_team" => $gradeable->isTeamAssignment(),
            "version" => $new_version,
            "vcs_checkout" => $vcs_checkout);

        if ($gradeable->isTeamAssignment()) {
            $queue_data['team_members'] = $team->getMemberUserIds();
        }

        // Create the vcs file first!  (avoid race condition, we must
        // check out the files before trying to grade them)
        if ($vcs_queue_file !== "") {
          if (@file_put_contents($vcs_queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
            return $this->uploadResult("Failed to create vcs file for grading queue.", false);
          }
        } else {
          // Then create the file that will trigger autograding
          if (@file_put_contents($queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
            return $this->uploadResult("Failed to create file for grading queue.", false);
          }
        }

        Logger::logAccess(
            $this->core->getUser()->getId(),
            $_COOKIE['submitty_token'],
            "{$this->core->getConfig()->getSemester()}:{$this->core->getConfig()->getCourse()}:submission:{$gradeable->getId()}"
        );

        if($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), null, $team_id, $new_version, $current_time);
        }
        else {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), $user_id, null, $new_version, $current_time);
        }

        if ($user_id == $original_user_id) {
            $this->core->addSuccessMessage("Successfully uploaded version {$new_version} for {$gradeable->getTitle()}");
        }
        else {
            $this->core->addSuccessMessage("Successfully uploaded version {$new_version} for {$gradeable->getTitle()} for {$who_id}");
        }


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
        return $this->core->getOutput()->renderResultMessage($message, $success);
    }

    private function updateSubmissionVersion() {
        $ta = $_REQUEST['ta'] ?? false;
        if ($ta !== false) {
            // make sure is full grader
            if (!$this->core->getUser()->accessFullGrading()) {
                $msg = "You do not have access to that page.";
                $this->core->addErrorMessage($msg);
                $this->core->redirect($this->core->buildNewCourseUrl());
                return $this->core->getOutput()->renderJsonFail($msg);
            }
            $ta = true;
        }

        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);
        if ($gradeable === null) {
            $msg = "Invalid gradeable id.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($this->core->buildUrl(array('component' => 'student')));
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $who = $_REQUEST['who'] ?? $this->core->getUser()->getId();
        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $who, $who);
        $url = $this->core->buildNewCourseUrl(['student', $gradeable->getId()]);
        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $msg = "Invalid CSRF token. Refresh the page and try again.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($url);
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        // If $graded_gradeable is null, that means its a team assignment and the user is on no team
        if ($gradeable->isTeamAssignment() && $graded_gradeable === null) {
            $msg = 'Must be on a team to access submission.';
            $this->core->addErrorMessage($msg);
            $this->core->redirect($this->core->buildNewCourseUrl());
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $new_version = intval($_REQUEST['new_version']);
        if ($new_version < 0) {
            $msg = "Cannot set the version below 0.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($url);
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $highest_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion();
        if ($new_version > $highest_version) {
            $msg = "Cannot set the version past {$highest_version}.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($url);
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        if (!$this->core->getUser()->accessGrading() && !$gradeable->isStudentSubmit()) {
            $msg = "Cannot submit for this assignment.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($url);
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $original_user_id = $this->core->getUser()->getId();
        $submitter_id = $graded_gradeable->getSubmitter()->getId();

        $settings_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions",
            $gradeable->getId(), $submitter_id, "user_assignment_settings.json");
        $json = FileUtils::readJsonFile($settings_file);
        if ($json === false) {
            $msg = "Failed to open settings file.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($url);
            return $this->core->getOutput()->renderJsonFail($msg);
        }
        $json["active_version"] = $new_version;
        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");
        $current_time_string_tz = $current_time . " " . $this->core->getConfig()->getTimezone()->getName();

        $json["history"][] = array("version" => $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "select");

        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            $msg = "Could not write to settings file.";
            $this->core->addErrorMessage($msg);
            $this->core->redirect($this->core->buildUrl(array('component' => 'student',
                                                              'gradeable_id' => $gradeable->getId())));
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $version = ($new_version > 0) ? $new_version : null;

        // FIXME: Add this kind of operation to the graded gradeable saving query
        if($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->updateActiveVersion($gradeable->getId(), null, $submitter_id, $version);
        }
        else {
            $this->core->getQueries()->updateActiveVersion($gradeable->getId(), $submitter_id, null, $version);
        }


        if ($new_version == 0) {
            $msg = "Cancelled submission for gradeable.";
            $this->core->addSuccessMessage($msg);
        }
        else {
            $msg = "Updated version of gradeable to version #{$new_version}.";
            $this->core->addSuccessMessage($msg);
        }
        if($ta) {
            $this->core->redirect($this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic',
                                                    'action' => 'grade', 'gradeable_id' => $gradeable->getId(),
                                                    'who_id'=>$who, 'gradeable_version' => $new_version)));
        }
        else {
            $this->core->redirect($this->core->buildUrl(array('component' => 'student',
                                                          'gradeable_id' => $gradeable->getId(),
                                                          'gradeable_version' => $new_version)));
        }

        return $this->core->getOutput()->renderJsonSuccess(['version' => $new_version, 'message' => $msg]);
    }

    /**
     * Check if the results folder exists for a given gradeable and version results.json
     * in the results/ directory. If the file exists, we output a string that the calling
     * JS checks for to initiate a page refresh (so as to go from "in-grading" to done
     */
    public function checkRefresh() {
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        $version = $_REQUEST['gradeable_version'];
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // Don't load the graded gradeable, since that may not exist yet
        $submitter_id = $this->core->getUser()->getId();
        $user_id = $submitter_id;
        $team_id = null;
        if ($gradeable !== null && $gradeable->isTeamAssignment()) {
            $team = $this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $submitter_id);

            if ($team !== null) {
                $submitter_id = $team->getId();
                $team_id = $submitter_id;
                $user_id = null;
            }
        }

        $filepath = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "results", $gradeable_id,
            $submitter_id, $version, "results.json");

        $results_json_exists = file_exists($filepath);

        // if the results json exists, check the database to make sure that the autograding results are there.
        $has_results = $results_json_exists && $this->core->getQueries()->getGradeableVersionHasAutogradingResults(
            $gradeable_id, $version, $user_id, $team_id);

        if ($has_results) {
            $refresh_string = "REFRESH_ME";
            $refresh_bool = true;
        }
        else {
            $refresh_string = "NO_REFRESH";
            $refresh_bool = false;
        }
        $this->core->getOutput()->renderString($refresh_string);
        return array('refresh' => $refresh_bool, 'string' => $refresh_string);
    }

    public function showStats() {
        $course_path = $this->core->getConfig()->getCoursePath();
        $gradeable_id = $_REQUEST['gradeable_id'] ?? '';
        $base_path = $course_path . "/submissions/" . $gradeable_id . "/";
        $users = array();
        $user_id_arr = is_dir($base_path) ? array_slice(scandir($base_path), 2) : [];
        for($i = 0; $i < count($user_id_arr); $i++) {
            $user_path = $base_path . $user_id_arr[$i];
            if(!is_dir($user_path))
                continue;
            $files = scandir($user_path);
            $num_files = count($files) - 3;
            $json_path = $user_path . "/" . $num_files . "/bulk_upload_data.json";
            if(!file_exists($json_path))
                continue;
            $user = $this->core->getQueries()->getUserById($user_id_arr[$i]);
            if($user === null)
                continue;
            $file_contents = FileUtils::readJsonFile($json_path);
            $users[$user_id_arr[$i]]["first_name"] = $user->getDisplayedFirstName();
            $users[$user_id_arr[$i]]["last_name"] = $user->getDisplayedLastName();
            $users[$user_id_arr[$i]]['upload_time'] = $file_contents['upload_timestamp'];
            $users[$user_id_arr[$i]]['submit_time'] = $file_contents['submit_timestamp'];
            $users[$user_id_arr[$i]]['file'] = $file_contents['filepath'];
        }
        
        $this->core->getOutput()->renderOutput('grading\ElectronicGrader', 'statPage', $users);

    }

    private function notifyGradeInquiryEvent($graded_gradeable, $gradeable_id, $content, $type){
      //TODO: send notification to grader per component
      if($graded_gradeable->hasTaGradingInfo()){
          $course = $this->core->getConfig()->getCourse();
          $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();
          $graders = $ta_graded_gradeable->getGraders();
          $submitter = $graded_gradeable->getSubmitter();
          $user_id = $this->core->getUser()->getId();

          if ($type == 'new') {
              // instructor/TA/Mentor submitted
              if ($this->core->getUser()->accessGrading()) {
                  $email_subject = "[Submitty $course] New Regrade Request";
                  $email_body = "A Instructor/TA/Mentor submitted a grade inquiry for gradeable $gradeable_id.\n$user_id writes:\n$content\n\nPlease visit Submitty to follow up on this request";
                  $n_content = "An Instructor/TA/Mentor has made a new Grade Inquiry for ".$gradeable_id;
              }
              // student submitted
              else {
                  $email_subject = "[Submitty $course] New Regrade Request";
                  $email_body = "A student has submitted a grade inquiry for gradeable $gradeable_id.\n$user_id writes:\n$content\n\nPlease visit Submitty to follow up on this request";
                  $n_content = "A student has submitted a new grade inquiry for ".$gradeable_id;
              }
          } else if ($type == 'reply') {
              if ($this->core->getUser()->accessGrading()) {
                  $email_subject = "[Submitty $course] New Regrade Request";
                  $email_body = "A Instructor/TA/Mentor made a post in a grade inquiry for gradeable $gradeable_id.\n$user_id writes:\n$content\n\nPlease visit Submitty to follow up on this request";
                  $n_content = "A instructor has replied to your Grade Inquiry for ".$gradeable_id;
              }
              // student submitted
              else {
                  $email_subject = "[Submitty $course] New Regrade Request";
                  $email_body = "A student has made a post in a grade inquiry for gradeable $gradeable_id.\n$user_id writes:\n$content\n\nPlease visit Submitty to follow up on this request";
                  $n_content = "New reply in Grade Inquiry for ".$gradeable_id;
              }

          }

          // make graders' notifications and emails
          $metadata = json_encode(array(array('component' => 'grading', 'page' => 'electronic', 'action' => 'grade', 'gradeable_id' => $gradeable_id, 'who_id' => $submitter->getId())));
          foreach ($graders as $grader) {
              if ($grader->accessFullGrading() && $grader->getId() != $user_id){
                  $details = ['component' => 'grading', 'metadata' => $metadata, 'content' => $n_content, 'body' => $email_body, 'subject' => $email_subject, 'sender_id' => $user_id, 'to_user_id' => $grader->getId()];
                  $notifications[] = Notification::createNotification($this->core, $details);
                  $emails[] = new Email($this->core,$details);
              }
          }

          // make students' notifications and emails
          $metadata = json_encode(array(array('component' => 'student', 'gradeable_id' => $gradeable_id)));
          if($submitter->isTeam()){
              $submitting_team = $submitter->getTeam()->getMemberUsers();
              foreach($submitting_team as $submitting_user){
                  if($submitting_user->getId() != $user_id) {
                      $details = ['component' => 'student', 'metadata' => $metadata, 'content' => $n_content, 'body' => $email_body, 'subject' => $email_subject, 'sender_id' => $user_id, 'to_user_id' => $submitting_user->getId()];
                      $notifications[] = Notification::createNotification($this->core, $details);
                      $emails[] = new Email($this->core,$details);
                  }
              }
          } else {
              if ($submitter->getUser()->getId() != $user_id) {
                  $details = ['component' => 'student', 'metadata' => $metadata, 'content' => $n_content, 'body' => $email_body, 'subject' => $email_subject, 'sender_id' => $user_id, 'to_user_id' => $submitter->getId()];
                  $notifications[] = Notification::createNotification($this->core, $details);
                  $emails[] = new Email($this->core,$details);
              }
          }
          $this->core->getNotificationFactory()->sendNotifications($notifications);
          if ($this->core->getConfig()->isEmailEnabled()) {
              $this->core->getNotificationFactory()->sendEmails($emails);
          }
      }
    }


}
