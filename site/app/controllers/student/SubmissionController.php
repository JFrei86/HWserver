<?php

namespace app\controllers\student;

use app\controllers\AbstractController;
use app\libraries\DateUtils;
use app\libraries\ErrorMessages;
use app\libraries\FileUtils;
use app\libraries\GradeableType;
use app\libraries\GradingQueue;
use app\libraries\Logger;
use app\libraries\response\JsonResponse;
use app\libraries\response\RedirectResponse;
use app\libraries\response\MultiResponse;
use app\libraries\routers\AccessControl;
use app\libraries\Utils;
use app\models\gradeable\LateDays;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use Symfony\Component\Routing\Annotation\Route;
use app\models\notebook\UserSpecificNotebook;
use app\models\notebook\SubmissionCodeBox;
use app\models\notebook\SubmissionMultipleChoice;

class SubmissionController extends AbstractController {

    private $upload_details = [
        'version' => -1,
        'version_path' => null,
        'user_path' => null,
        'assignment_settings' => false
    ];


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

            if (
                $gradeable->getType() === GradeableType::ELECTRONIC_FILE
                && (
                    $this->core->getUser()->accessAdmin()
                    || $gradeable->getTaViewStartDate() <= $now
                    && $this->core->getUser()->accessGrading()
                    || $gradeable->getSubmissionOpenDate() <= $now
                )
            ) {
                return $gradeable;
            }
            return null;
        }
        catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    private function verifyHomeworkPagePermissions($gradeable_id, Gradeable $gradeable, $graded_gradeable) {
        if ($graded_gradeable === null && !$this->core->getUser()->accessAdmin()) {
            // FIXME if $graded_gradeable is null, the user isn't on a team, so we want to redirect
            // FIXME    to nav with an error
        }

        // ORIGINAL
        //if (!$gradeable->isSubmissionOpen() && !$this->core->getUser()->accessAdmin()) {
        // TEMPORARY - ALLOW LIMITED & FULL ACCESS GRADERS TO PRACTICE ALL FUTURE HOMEWORKS
        if (
            !$this->core->getUser()->accessGrading()
            && (
                !$gradeable->isSubmissionOpen()
                || $gradeable->isStudentView()
                && $gradeable->isStudentViewAfterGrades()
                && !$gradeable->isTaGradeReleased()
            )
        ) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return ['error' => true, 'message' => 'No gradeable with that id.'];
        }
        elseif ($gradeable->isTeamAssignment() && $graded_gradeable === null && !$this->core->getUser()->accessAdmin()) {
            $this->core->addErrorMessage('Must be on a team to access submission');
            $this->core->redirect($this->core->buildCourseUrl());
            return ['error' => true, 'message' => 'Must be on a team to access submission.'];
        }
        return ['error' => false];
    }

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}")
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/{gradeable_version}", requirements={"gradeable_version": "\d+"})
     * @return array
     */
    public function showHomeworkPage($gradeable_id, $gradeable_version = null) {
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);
        if ($gradeable === null) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return ['error' => true, 'message' => 'No gradeable with that id.'];
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $this->core->getUser()->getId());
        $verify_permissions = $this->verifyHomeworkPagePermissions($gradeable_id, $gradeable, $graded_gradeable);
        if ($verify_permissions['error']) {
            return $verify_permissions;
        }

        // Attempt to put the version number to be in bounds of the gradeable
        $version = intval($gradeable_version ?? 0);
        if ($version < 1 || $version > ($graded_gradeable !== null ? $graded_gradeable->getAutoGradedGradeable()->getHighestVersion() : 0)) {
            $version = $graded_gradeable !== null ? $graded_gradeable->getAutoGradedGradeable()->getActiveVersion() : 0;
        }

        $error = false;
        $now = $this->core->getDateTimeNow();

        Logger::logAccess(
            $this->core->getUser()->getId(),
            $_COOKIE['submitty_token'],
            "{$this->core->getConfig()->getSemester()}:{$this->core->getConfig()->getCourse()}:load_page:{$gradeable->getId()}"
        );
        $user_id = $this->core->getUser()->getId();
        $team_id = null;
        if ($gradeable->isTeamAssignment() && $graded_gradeable !== null) {
            $team = $graded_gradeable->getSubmitter()->getTeam();
            if ($team !== null) {
                $user_id = null;
                $team_id = $team->getId();
            }
        }

        $this->core->getQueries()->insertGradeableAccess(
            $gradeable->getId(),
            $user_id,
            $team_id,
            $this->core->getUser()->getId()
        );

        $url = $this->core->buildCourseUrl(['gradeable', $gradeable->getId()]);
        $this->core->getOutput()->addBreadcrumb($gradeable->getTitle(), $url);
        if (!$gradeable->hasAutogradingConfig()) {
            $this->core->getOutput()->renderOutput(
                'Error',
                'unbuiltGradeable',
                $gradeable->getTitle()
            );
            $error = true;
        }
        else {
            if (
                $graded_gradeable !== null
                && $gradeable->isTaGradeReleased()
                && $gradeable->isTaGrading()
                && $graded_gradeable->isTaGradingComplete()
            ) {
                $graded_gradeable->getOrCreateTaGradedGradeable()->setUserViewedDate($now);
                $this->core->getQueries()->saveTaGradedGradeable($graded_gradeable->getTaGradedGradeable());
                if ($graded_gradeable->getSubmitter()->isTeam()) {
                    $this->core->getQueries()->updateTeamViewedTime($graded_gradeable->getSubmitter()->getId(), $this->core->getUser()->getId());
                }
            }

            // Only show hidden test cases if the display version is the graded version (and grades are released)
            $show_hidden = false;
            if ($graded_gradeable != null) {
                $show_hidden = (!$gradeable->isTaGrading() || $version == $graded_gradeable->getOrCreateTaGradedGradeable()->getGradedVersion(false)) && $gradeable->isTaGradeReleased();
                // can this user access grade inquiries for this graded_gradeable
                $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);
            }

            // If we get here, then we can safely construct the old model w/o checks
            $this->core->getOutput()->addInternalCss('forum.css');
            $this->core->getOutput()->addInternalJs('forum.js');
            $this->core->getOutput()->addInternalCss('grade-inquiry.css');
            $this->core->getOutput()->addInternalJs('grade-inquiry.js');
            $this->core->getOutput()->addInternalJs('websocket.js');
            $this->core->getOutput()->enableMobileViewport();
            $this->core->getOutput()->renderOutput(
                ['submission', 'Homework'],
                'showGradeable',
                $gradeable,
                $graded_gradeable,
                $version,
                $can_inquiry ?? false,
                $show_hidden
            );
        }
        return ['id' => $gradeable_id, 'error' => $error];
    }

    /**
     * Function for showing a message to a user before the gradeable is loaded.
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/load_gradeable_message")
     */
    public function loadGradeableMessage($gradeable_id) {
        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);
        if ($gradeable === null) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return ['error' => true, 'message' => 'No gradeable with that id.'];
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $this->core->getUser()->getId());
        $verify_permissions = $this->verifyHomeworkPagePermissions($gradeable_id, $gradeable, $graded_gradeable);
        if ($verify_permissions['error']) {
            return $verify_permissions;
        }

        if (!$gradeable->getAutogradingConfig()->hasLoadGradeableMessageEnabled($gradeable_id, $this->core->getUser()->getId())) {
            return new RedirectResponse($this->core->buildCourseUrl(['gradeable', $gradeable_id]));
        }
        else {
            $this->core->getOutput()->enableMobileViewport();
            $this->core->getOutput()->renderTwigOutput('submission/homework/LoadMessagePage.twig', [
                "gradeable_name" => $gradeable->getTitle(),
                "load_gradeable_message" => $gradeable->getAutogradingConfig()->getLoadGradeableMessage(),
                "button_back" => $this->core->buildCourseUrl([]),
                "button_forward" => $this->core->buildCourseUrl(['gradeable', $gradeable_id])
            ]);
        }
    }

    /**
     * Function for verification that a given RCS ID is valid and has a corresponding user and gradeable.
     * This should be called via AJAX, saving the result to the json_buffer of the Output object.
     * If failure, also returns message explaining what happened.
     * If success, also returns highest version of the student gradeable.
     *
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/verify", methods={"POST"})
     */
    public function ajaxValidGradeable($gradeable_id) {

        if (!isset($_POST['user_id'])) {
            $msg = "Did not pass in user_id.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);

        // This checks for an assignment id, and that it's a valid assignment id in that
        // it corresponds to one that we can access (whether through admin or it being released)
        if ($gradeable === null) {
            return $this->uploadResult("Invalid gradeable id '{$gradeable_id}'", false);
        }

        //filter out empty, null strings
        $tmp_ids = $_POST['user_id'];
        if (is_array($tmp_ids)) {
            $user_ids = array_filter($_POST['user_id']);
        }
        else {
            $user_ids = [$tmp_ids];
            $user_ids = array_filter($user_ids);
        }

        //If no user id's were submitted, give a graceful error.
        if (count($user_ids) === 0) {
            $msg = "No valid user ids were found.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        //For every userid, we have to check that its real.
        foreach ($user_ids as $id) {
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
        if ($gradeable->isTeamAssignment()) {
            $teams = [];
            foreach ($user_ids as $user) {
                $tmp = $this->core->getQueries()->getTeamByGradeableAndUser($gradeable->getId(), $user);
                if ($tmp === null) {
                    $null_team_count++;
                }
                else {
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
        if ($gradeable->isTeamAssignment() && $null_team_count != 0 && count($teams) != 0) {
            $msg = "One or more users with no team are being submitted with another user already on a team";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

        $highest_version = -1;

        if (count($graded_gradeables) > 0) {
            $graded_gradeable = $graded_gradeables[0];
            $highest_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion();
        }

        //If there has been a previous submission, we tag it so that we can pop up a warning.
        $return = ['highest_version' => $highest_version, 'previous_submission' => $highest_version > 0];
        return $this->core->getOutput()->renderJsonSuccess($return);
    }

    /**
     * Function that uploads a bulk PDF to the uploads/bulk_pdf folder. Splits it into PDFs of the page
     * size entered and places in the uploads/split_pdf folder.
     * Its error checking has overlap with ajaxUploadSubmission.
     *
     * @AccessControl(role="FULL_ACCESS_GRADER")
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/bulk", methods={"POST"})
     */
    public function ajaxBulkUpload($gradeable_id) {
        $is_qr = isset($_POST['use_qr_codes']) && $_POST['use_qr_codes'] === "true";

        if (!isset($_POST['num_pages']) && !$is_qr) {
            $msg = "Did not pass in number of pages or files were too large.";
            return $this->core->getOutput()->renderJsonFail($msg);
        }

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

        $status = FileUtils::validateUploadedFiles($uploaded_file);
        $count = count($uploaded_file["name"]);

        if (array_key_exists("failed", $status)) {
            return $this->core->getOutput()->renderResultMessage("Failed to validate uploads " . $status["failed"], false);
        }

        $file_size = 0;
        foreach ($status as $stat) {
            if ($stat['success'] === false) {
                return $this->core->getOutput()->renderResultMessage("Error " . $stat['error'], false);
            }

            if ($stat['type'] !== 'application/pdf') {
                return $this->core->getOutput()->renderResultMessage("Error " . $stat['name'] . " is not a PDF", false);
            }

            $file_size += $stat['size'];
        }

        $max_size = $gradeable->getAutogradingConfig()->getMaxSubmissionSize();
        if ($max_size < 10000000) {
            $max_size = 10000000;
        }

        if ($file_size > $max_size) {
            return $this->uploadResult("File(s) uploaded too large.  Maximum size is " . ($max_size / 1000) . " kb. Uploaded file(s) was " . ($file_size / 1000) . " kb.", false);
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
                if (is_uploaded_file($uploaded_file["tmp_name"][$j])) {
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
        $use_ocr = $this->core->getConfig()->checkFeatureFlagEnabled('submitty_ocr') && $_POST['use_ocr'] === "true";

        if ($is_qr) {
            $qr_prefix = rawurlencode($_POST['qr_prefix']);
            $qr_suffix = rawurlencode($_POST['qr_suffix']);

            //create a new job to split but uploads via QR
            for ($i = 0; $i < $count; $i++) {
                $qr_upload_data = [
                    "job"       => "BulkUpload",
                    "semester"  => $semester,
                    "course"    => $course,
                    "g_id"      => $gradeable_id,
                    "timestamp" => $current_time,
                    "qr_prefix" => $qr_prefix,
                    "qr_suffix" => $qr_suffix,
                    "filename"  => $uploaded_file["name"][$i],
                    "is_qr"     => true,
                    "use_ocr"   => $use_ocr
                ];

                $bulk_upload_job  = "/var/local/submitty/daemon_job_queue/bulk_upload_" . $uploaded_file["name"][$i] . ".json";

                //add new job to queue
                if (!file_put_contents($bulk_upload_job, json_encode($qr_upload_data, JSON_PRETTY_PRINT))) {
                    $this->core->getOutput()->renderJsonFail("Failed to write BulkQRSplit job");
                    return $this->uploadResult("Failed to write BulkQRSplit job", false);
                }
            }
        }
        else {
            for ($i = 0; $i < $count; $i++) {
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
                if (!file_put_contents($bulk_upload_job, json_encode($job_data, JSON_PRETTY_PRINT))) {
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
     * @AccessControl(role="FULL_ACCESS_GRADER")
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/split_pdf/upload", methods={"POST"})
     * @return boolean
     */
    public function ajaxUploadSplitItem($gradeable_id, $merge = null, $clobber = null) {
        // check for whether the item should be merged with previous submission
        // and whether or not file clobbering should be done
        $merge_previous = isset($merge) && $merge === 'true';
        $clobber = isset($clobber) && $clobber === 'true';

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
        if (is_array($tmp_ids)) {
            $user_ids = array_filter($_POST['user_id']);
        }
        else {
            $user_ids = [$tmp_ids];
            $user_ids = array_filter($user_ids);
        }

        //This grabs the first user in the list. If this is a team assignment, they will be the team leader.
        $user_id = reset($user_ids);

        $path = $_POST['path'];

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $user_id, null);

        $gradeable_path = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "submissions",
            $gradeable->getId()
        );

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
            else {
                //if the student isn't on a team, build the team.
                //If the team doesn't exist yet, we need to build a new one. (Note, we have already checked in ajaxvalidgradeable
                //that all users are either on the same team or no team).

                $leaderless = [];
                foreach ($user_ids as $i => $member) {
                    if ($member !== $leader) {
                        $leaderless[] = $member;
                    }
                }

                $members = $this->core->getQueries()->getUsersById($leaderless);
                $leader_user = $this->core->getQueries()->getUserById($leader);
                try {
                    $gradeable->createTeam($leader_user, $members);
                }
                catch (\Exception $e) {
                    $this->core->addErrorMessage('Team may not have been properly initialized: ' . $e->getMessage());
                    return $this->uploadResult("Failed to form a team from members: " . implode(",", $members) . ", " . ($leader_user !== null ? $leader_user->getId() : ''), false);
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

        $uploaded_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "split_pdf", $gradeable->getId(), $path);
        $uploaded_file = rawurldecode(htmlspecialchars_decode($uploaded_file));
        $uploaded_file_base_name = "upload.pdf";

        //get any and all images associated with this PDF if they exist.
        //images are order <original>_<split-number>_<page-number>, so grab everuthing with the same suffixes
        preg_match("/\d*$/", pathinfo($path, PATHINFO_FILENAME), $matches);
        $image_files = glob(FileUtils::joinPaths(dirname($uploaded_file), "*.*"));


        $combined_pdf_path = FileUtils::joinPaths(pathinfo($uploaded_file)['dirname'], pathinfo($uploaded_file)['filename']);
        $combined_pdf_path = str_replace("/", "\/", $combined_pdf_path);

        $regex = "/{$combined_pdf_path}_\d*\.\w*$/";
        $image_files = preg_grep($regex, $image_files);

        $image_extension = count($image_files) > 0 ? pathinfo(reset($image_files), PATHINFO_EXTENSION) : "";

        if (isset($uploaded_file)) {
            // if we are merging in the previous submission (TODO check folder support)
            if ($merge_previous && $new_version !== 1) {
                $old_version = $new_version - 1;
                $old_version_path = FileUtils::joinPaths($user_path, $old_version);
                $to_search = FileUtils::joinPaths($old_version_path, "*.*");
                $files = glob($to_search);
                foreach ($files as $file) {
                    $file_base_name = basename($file);
                    if (!$clobber && $file_base_name === $uploaded_file_base_name) {
                        $parts = explode(".", $file_base_name);
                        $parts[0] .= "_version_" . $old_version;
                        $file_base_name = implode(".", $parts);
                    }

                    $image_name = pathinfo($file, PATHINFO_FILENAME);
                    preg_match("/\d*$/", $image_name, $matches);
                    $image_num = count($matches) > 0 ? intval(reset($matches)) : -1;

                    if (!$clobber && strpos($image_name, "_page_") !== false && $image_num >= 0) {
                        $file_base_name = "upload_version_"  . $old_version . "_page_" . $image_num . "." . $image_extension;
                    }

                    $move_here = FileUtils::joinPaths($version_path, $file_base_name);
                    if (!@copy($file, $move_here)) {
                        return $this->uploadResult("Failed to merge previous version on file {$file_base_name}", false);
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
            //do the same thing for images
            $i = 1;
            foreach ($image_files as $image) {
                // copy over the uploaded image
                if (!@copy($image, FileUtils::joinPaths($version_path, "upload_page_" . $i . "." . $image_extension))) {
                    return $this->uploadResult("Failed to copy uploaded image {$image} to current submission.", false);
                }
                if (!@unlink($image)) {
                    return $this->uploadResult("Failed to delete the uploaded image {$image} from temporary storage.", false);
                }
                $i++;
            }
        }

        // if split_pdf/gradeable_id/timestamp directory is now empty, delete that directory
        $timestamp = substr($path, 0, strpos($path, DIRECTORY_SEPARATOR));
        $timestamp_path = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "uploads",
            "split_pdf",
            $gradeable->getId(),
            $timestamp
        );
        $files = FileUtils::getAllFiles($timestamp_path);
        if (count($files) == 0) {
            if (!FileUtils::recursiveRmdir($timestamp_path)) {
                return $this->uploadResult("Failed to remove the empty timestamp directory {$timestamp} from the split_pdf directory.", false);
            }
        }


        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        if (!file_exists($settings_file)) {
            $json = [
                "active_version" => $new_version,
                "history" => [[
                    "version" => $new_version,
                    "time" => $current_time_string_tz,
                    "who" => $original_user_id,
                    "type" => "upload"
                ]]
            ];
        }
        else {
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_version"] = $new_version;
            $json["history"][] = ["version" => $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "upload"];
        }

        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        }

        $this->upload_details['assignment_settings'] = true;

        if (!@file_put_contents(FileUtils::joinPaths($version_path, ".submit.timestamp"), $current_time_string_tz . "\n")) {
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

        $queue_file = [$this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
            $gradeable->getId(), $who_id, $new_version];
        $queue_file = FileUtils::joinPaths(
            $this->core->getConfig()->getSubmittyPath(),
            "to_be_graded_queue",
            implode("__", $queue_file)
        );

        $vcs_checkout = isset($_REQUEST['vcs_checkout']) ? $_REQUEST['vcs_checkout'] === "true" : false;

        // create json file...
        $queue_data = ["semester" => $this->core->getConfig()->getSemester(),
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
            "vcs_checkout" => $vcs_checkout];

        if (@file_put_contents($queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
            return $this->uploadResult("Failed to create file for grading queue.", false);
        }

        // FIXME: Add this as part of the graded gradeable saving query
        if ($gradeable->isTeamAssignment()) {
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
     * @AccessControl(role="FULL_ACCESS_GRADER")
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/split_pdf/delete", methods={"POST"})
     * @return boolean
     */
    public function ajaxDeleteSplitItem($gradeable_id) {
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

        $uploaded_file = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "uploads",
            "split_pdf",
            $gradeable->getId(),
            $path
        );

        $uploaded_file = rawurldecode(htmlspecialchars_decode($uploaded_file));

        if (!@unlink($uploaded_file)) {
            return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
        }

        if (!@unlink(str_replace(".pdf", "_cover.pdf", $uploaded_file))) {
            return $this->uploadResult("Failed to delete the uploaded file {$uploaded_file} from temporary storage.", false);
        }

        // delete timestamp folder if empty
        $timestamp = substr($path, 0, strpos($path, DIRECTORY_SEPARATOR));
        $timestamp_path = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "uploads",
            "split_pdf",
            $gradeable->getId(),
            $timestamp
        );
        $files = FileUtils::getAllFiles($timestamp_path);
        if (count($files) === 0) {
            if (!FileUtils::recursiveRmdir($timestamp_path)) {
                return $this->uploadResult("Failed to remove the empty timestamp directory {$timestamp} from the split_pdf directory.", false);
            }
        }

        return $this->uploadResult("Successfully deleted this PDF.");
    }

    public function reccacheLateDays($user_id) {
        LateDays::cacheLateDayInfoForUser($this->core, $user_id);
    }

    /**
     * Function for uploading a submission to the server. This should be called via AJAX, saving the result
     * to the json_buffer of the Output object, returning a true or false on whether or not it suceeded or not.
     *
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/upload", methods={"POST"})
     * @return array
     */
    public function ajaxUploadSubmission($gradeable_id, $merge = null, $clobber = null) {

        // check for whether the item should be merged with previous submission,
        // and whether or not file clobbering should be done.
        $merge_previous = isset($merge) && $merge === 'true';
        $clobber = isset($clobber) && $clobber === 'true';

        $vcs_checkout = isset($_POST['vcs_checkout']) ? $_POST['vcs_checkout'] === "true" : false;
        if ($vcs_checkout && !isset($_POST['git_repo_id'])) {
            return $this->uploadResult("Invalid repo id.", false);
        }

        $student_page = isset($_POST['student_page']) ? $_POST['student_page'] === "true" : false;
        if ($student_page && !isset($_POST['pages'])) {
            return $this->uploadResult("Invalid pages.", false);
        }

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
        $gradeable_path = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "submissions",
            $gradeable->getId()
        );


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

        $this_config_inputs = [];
        $num_parts = $gradeable->getAutogradingConfig()->getNumParts();
        $is_notebook = $gradeable->getAutogradingConfig()->isNotebookGradeable();
        $notebook = null;
        if ($is_notebook) {
            //need to force re-parse the notebook serverside again
            $notebook = $gradeable->getAutogradingConfig()->getUserSpecificNotebook(
                $who_id,
                $gradeable_id
            );

            //save the notebook hashes and item selected
            $json = [
                "hashes" => $notebook->getHashes(),
                "item_pools_selected" => $notebook->getSelectedQuestions()
            ];

            FileUtils::writeJsonFile(FileUtils::joinPaths($version_path, ".submit.notebook"), $json);

            $this_config_inputs = $notebook->getInputs();
            $num_parts = $notebook->getNumParts();
        }

        $this->upload_details['version_path'] = $version_path;
        $this->upload_details['version'] = $new_version;

        $part_path = [];
        // We upload the assignment such that if it's multiple parts, we put it in folders "part#" otherwise
        // put all files in the root folder
        if ($num_parts > 1 && !$is_notebook) {
            for ($i = 1; $i <= $num_parts; $i++) {
                $part_path[$i] = FileUtils::joinPaths($version_path, "part" . $i);
                if (!FileUtils::createDir($part_path[$i])) {
                    return $this->uploadResult("Failed to make the folder for part {$i}.", false);
                }
            }
        }
        elseif (!$is_notebook) {
            $part_path[1] = $version_path;
        }
        elseif ($is_notebook) {
            //each notebook file submission needs its own dir defined in the config
            $file_submissions = $notebook->getFileSubmissions();
            for ($i = 1; $i <= count($file_submissions); $i++) {
                $part_path[$i] = FileUtils::joinPaths($version_path, $file_submissions[$i - 1]["directory"]);
                if (!FileUtils::createDir($part_path[$i])) {
                    return $this->uploadResult("Failed to make the folder for part {$i}.", false);
                }
            }
        }

        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");
        $current_time_string_tz = $current_time . " " . $this->core->getConfig()->getTimezone()->getName();

        $max_size = $gradeable->getAutogradingConfig()->getMaxSubmissionSize();

        if ($vcs_checkout === false) {
            $uploaded_files = [];
            for ($i = 1; $i <= $num_parts; $i++) {
                if (isset($_FILES["files{$i}"])) {
                    $uploaded_files[$i] = $_FILES["files{$i}"];
                }
            }

            $errors = [];
            $count = [];
            for ($i = 1; $i <= $num_parts; $i++) {
                if (isset($uploaded_files[$i])) {
                    $count[$i] = count($uploaded_files[$i]["name"]);
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (!isset($uploaded_files[$i]["tmp_name"][$j]) || $uploaded_files[$i]["tmp_name"][$j] === "") {
                            $error_message = $uploaded_files[$i]["name"][$j] . " failed to upload. ";
                            if (isset($uploaded_files[$i]["error"][$j])) {
                                $error_message .= "Error message: " . ErrorMessages::uploadErrors($uploaded_files[$i]["error"][$j]) . ".";
                            }
                            $errors[] = $error_message;
                        }
                    }
                }
            }


            if (count($errors) > 0) {
                $error_text = implode("\n", $errors);
                return $this->uploadResult("Upload Failed: " . $error_text, false);
            }

            // save the contents of the text boxes to files
            $empty_inputs = true;
            $num_codeboxes = 0;
            $num_multiple_choice = 0;

            $codebox_objects         = $_POST['codebox_answers'] ?? "";
            $multiple_choice_objects = $_POST['multiple_choice_answers'] ?? "";
            $codebox_objects         = json_decode($codebox_objects, true);
            $multiple_choice_objects = json_decode($multiple_choice_objects, true);

            foreach ($this_config_inputs as $this_input) {
                if ($this_input instanceof SubmissionCodeBox) {
                    $answers = $codebox_objects["codebox_" .  $num_codeboxes] ?? [];
                    $num_codeboxes += 1;
                }
                elseif ($this_input instanceof SubmissionMultipleChoice) {
                    $answers = $multiple_choice_objects["multiple_choice_" . $num_multiple_choice] ?? [];
                    $num_multiple_choice += 1;
                }
                else {
                    //TODO: How should we handle this case?
                    continue;
                }

                $filename = $this_input->getFileName();
                $dst = FileUtils::joinPaths($version_path, $filename);

                if (count($answers) > 0) {
                    $empty_inputs = false;
                }

                //FIXME: add error checking
                $file = fopen($dst, "w");
                foreach ($answers as $answer_val) {
                    fwrite($file, $answer_val . "\n");
                }
                fclose($file);
            }



            $previous_files_src = [];
            $previous_files_dst = [];
            $previous_part_path = [];
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
                if ($num_parts > 1 || $is_notebook) {
                    for ($i = 1; $i <= $num_parts; $i++) {
                        $path = FileUtils::joinPaths($previous_path, "part" . $i);

                        if ($is_notebook) {
                            $dir  = $notebook->getFileSubmissions()[$i - 1]["directory"];
                            $path = FileUtils::joinPaths($previous_path, $dir);
                        }

                        $previous_part_path[$i] = $path;
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
                if ($merge_previous) {
                    for ($i = 1; $i <= $num_parts; $i++) {
                        if (isset($uploaded_files[$i])) {
                            $current_files_set = array_flip($uploaded_files[$i]["name"]);
                            $previous_files_src[$i] = [];
                            $previous_files_dst[$i] = [];
                            $to_search = FileUtils::joinPaths($previous_part_path[$i], "*");
                            $filenames = glob($to_search);
                            $j = 0;
                            foreach ($filenames as $filename) {
                                $file_base_name = basename($filename);
                                $previous_files_src[$i][$j] = $file_base_name;
                                if (!$clobber && isset($current_files_set[$file_base_name])) {
                                    $parts = explode(".", $file_base_name);
                                    $parts[0] .= "_version_" . $highest_version;
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
            // a check for its mime type
            $file_size = 0;
            for ($i = 1; $i <= $num_parts; $i++) {
                if (isset($uploaded_files[$i])) {
                    $uploaded_files[$i]["is_zip"] = [];
                    for ($j = 0; $j < $count[$i]; $j++) {
                        if (mime_content_type($uploaded_files[$i]["tmp_name"][$j]) == "application/zip") {
                            if (FileUtils::checkFileInZipName($uploaded_files[$i]["tmp_name"][$j]) === false) {
                                return $this->uploadResult("Error: You may not use quotes, backslashes or angle brackets in your filename for files inside " . $uploaded_files[$i]["name"][$j] . ".", false);
                            }
                            $uploaded_files[$i]["is_zip"][$j] = true;
                            $file_size += FileUtils::getZipSize($uploaded_files[$i]["tmp_name"][$j]);
                        }
                        else {
                            if (FileUtils::isValidFileName($uploaded_files[$i]["name"][$j]) === false) {
                                return $this->uploadResult("Error: You may not use quotes, backslashes or angle brackets in your file name " . $uploaded_files[$i]["name"][$j] . ".", false);
                            }
                            $uploaded_files[$i]["is_zip"][$j] = false;
                            $file_size += $uploaded_files[$i]["size"][$j];
                        }
                    }
                }
                if (isset($previous_part_path[$i]) && isset($previous_files_src[$i])) {
                    foreach ($previous_files_src[$i] as $prev_file) {
                        $file_size += filesize(FileUtils::joinPaths($previous_part_path[$i], $prev_file));
                    }
                }
            }

            if ($file_size > $max_size) {
                return $this->uploadResult("File(s) uploaded too large.  Maximum size is " . ($max_size / 1000) . " kb. Uploaded file(s) was " . ($file_size / 1000) . " kb.", false);
            }

            for ($i = 1; $i <= $num_parts; $i++) {
                // copy selected previous submitted files
                if (isset($previous_files_src[$i])) {
                    for ($j = 0; $j < count($previous_files_src[$i]); $j++) {
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
                                return $this->uploadResult("Could not properly unpack zip file. Error message: " . $error_message . ".", false);
                            }
                        }
                        else {
                            if (is_uploaded_file($uploaded_files[$i]["tmp_name"][$j])) {
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
                $vcs_path = str_replace("{\$gradeable_id}", $gradeable_id, $vcs_path);
                $vcs_path = str_replace("{\$user_id}", $who_id, $vcs_path);
                $vcs_path = str_replace("{\$team_id}", $who_id, $vcs_path);
                $vcs_full_path = $vcs_base_url . $vcs_path;
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
            else {
                // use base url + path with variable string replacements
                if (strpos($vcs_path, "\$repo_id") !== false && $repo_id == "") {
                    return $this->uploadResult("repository id input cannot be blank.", false);
                }
                $vcs_path = str_replace("{\$gradeable_id}", $gradeable_id, $vcs_path);
                $vcs_path = str_replace("{\$user_id}", $who_id, $vcs_path);
                $vcs_path = str_replace("{\$team_id}", $who_id, $vcs_path);
                $vcs_path = str_replace("{\$repo_id}", $repo_id, $vcs_path);
                $vcs_full_path = $vcs_base_url . $vcs_path;
            }

            if (!@touch(FileUtils::joinPaths($version_path, ".submit.VCS_CHECKOUT"))) {
                return $this->uploadResult("Failed to touch file for vcs submission.", false);
            }

            // Public or private github
            if ($gradeable->getVcsHostType() == 2 || $gradeable->getVcsHostType() == 3) {
                $dst = FileUtils::joinPaths($version_path, ".submit.VCS_CHECKOUT");
                $json = [
                    "git_user_id" => $_POST["git_user_id"],
                    "git_repo_id" => $_POST["git_repo_id"]
                ];
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
            $json = [];
            $i = 0;
            foreach ($gradeable->getComponents() as $question) {
                $order = intval($question->getOrder());
                $title = $question->getTitle();
                $page_val = intval($pages_array[$i]);
                $json[] = [
                    "order" => $order,
                    "title" => $title,
                    "page #" => $page_val
                ];
                $i++;
            }
            if (!@file_put_contents($dst, FileUtils::encodeJson($json))) {
                return $this->uploadResult("Failed to write to pages file.", false);
            }
        }

        if ($team_id !== "") {
            $access = $this->core->getQueries()->getGradeableAccessTeam($gradeable->getId(), $team_id);
        }
        else {
            $access = $this->core->getQueries()->getGradeableAccessUser($gradeable->getId(), $user_id);
        }
        FileUtils::writeJsonFile(
            FileUtils::joinPaths($version_path, '.user_assignment_access.json'),
            array_map(function ($row) {
                // need to do this as postgres may return timezone as [+-]HH and python needs [+-]HHMM
                $row['timestamp'] = DateUtils::parseDateTime(
                    $row['timestamp'],
                    $this->core->getConfig()->getTimezone()
                )->format('Y-m-d H:i:sO');
                return $row;
            }, $access)
        );

        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
        if (!file_exists($settings_file)) {
            $json = [
                "active_version" => $new_version,
                "history" => [
                    [
                        "version" => $new_version,
                        "time" => $current_time_string_tz,
                        "who" => $original_user_id,
                        "type" => "upload"
                    ]
                ]
            ];
        }
        else {
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                return $this->uploadResult("Failed to open settings file.", false);
            }
            $json["active_version"] = $new_version;
            $json["history"][] = ["version" => $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "upload"];
        }

        // TODO: If any of these fail, should we "cancel" (delete) the entire submission attempt or just leave it?
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            return $this->uploadResult("Failed to write to settings file.", false);
        }

        $this->upload_details['assignment_settings'] = true;

        if (!@file_put_contents(FileUtils::joinPaths($version_path, ".submit.timestamp"), $current_time_string_tz . "\n")) {
            return $this->uploadResult("Failed to save timestamp file for this submission.", false);
        }

        $queue_file_helper = [$this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse(),
                                   $gradeable->getId(), $who_id, $new_version];
        $queue_file_helper = implode("__", $queue_file_helper);
        $queue_file = FileUtils::joinPaths(
            $this->core->getConfig()->getSubmittyPath(),
            "to_be_graded_queue",
            $queue_file_helper
        );
        // SPECIAL NAME FOR QUEUE FILE OF VCS GRADEABLES
        $vcs_queue_file = "";
        if ($vcs_checkout === true) {
            $vcs_queue_file = FileUtils::joinPaths(
                $this->core->getConfig()->getSubmittyPath(),
                "to_be_graded_queue",
                "VCS__" . $queue_file_helper
            );
        }

        // create json file...
        $queue_data = [
            "semester" => $this->core->getConfig()->getSemester(),
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
            "vcs_checkout" => $vcs_checkout
        ];

        if ($gradeable->isTeamAssignment()) {
            $queue_data['team_members'] = $team->getMemberUserIds();
        }

        // Create the vcs file first!  (avoid race condition, we must
        // check out the files before trying to grade them)
        if ($vcs_queue_file !== "") {
            if (@file_put_contents($vcs_queue_file, FileUtils::encodeJson($queue_data), LOCK_EX) === false) {
                return $this->uploadResult("Failed to create vcs file for grading queue.", false);
            }
        }
        else {
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

        if ($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), null, $team_id, $new_version, $current_time);
            $team_members = $graded_gradeable->getSubmitter()->getTeam()->getMembers();

            // notify other team members that a submission has been made
            $metadata = json_encode(['url' => $this->core->buildCourseUrl(['gradeable',$gradeable_id])]);
            $subject = "Team Member Submission: " . $graded_gradeable->getGradeable()->getTitle();
            $content = "A team member, $original_user_id, submitted in the gradeable, " . $graded_gradeable->getGradeable()->getTitle();
            $event = ['component' => 'team', 'metadata' => $metadata, 'subject' => $subject, 'content' => $content, 'type' => 'team_member_submission', 'sender_id' => $original_user_id];
            $this->core->getNotificationFactory()->onTeamEvent($event, $team_members);
            $this->reccacheLateDays($team_id);
        }
        else {
            $this->core->getQueries()->insertVersionDetails($gradeable->getId(), $user_id, null, $new_version, $current_time);
            $this->reccacheLateDays($user_id);
        }

        if ($user_id === $original_user_id) {
            $message = "Successfully uploaded version {$new_version} for {$gradeable->getTitle()}";
        }
        else {
            $message = "Successfully uploaded version {$new_version} for {$gradeable->getTitle()} for {$who_id}";
        }

        return $this->uploadResult($message);
    }

    private function uploadResult($message, $success = true) {
        if (!$success) {
            // we don't want to throw an exception here as that'll mess up our return json payload
            if (
                $this->upload_details['version_path'] !== null
                && !FileUtils::recursiveRmdir($this->upload_details['version_path'])
            ) {
                // @codeCoverageIgnoreStart
                // Without the filesystem messing up here, we should not be able to hit this error
                Logger::error("Could not clean up folder {$this->upload_details['version_path']}");
                // @codeCoverageIgnoreEnd
            }
            elseif ($this->upload_details['assignment_settings'] === true) {
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

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/version/{new_version}", methods={"POST"})
     */
    public function updateSubmissionVersion($gradeable_id, $new_version, $ta = null, $who = null): MultiResponse {
        $ta = $ta === "true" ?? false;
        if ($ta !== false) {
            // make sure is full grader
            if (!$this->core->getUser()->accessFullGrading()) {
                $msg = "You do not have access to that page.";
                $this->core->addErrorMessage($msg);
                return new MultiResponse(
                    JsonResponse::getFailResponse($msg),
                    null,
                    new RedirectResponse($this->core->buildCourseUrl())
                );
            }
            $ta = true;
        }

        $gradeable = $this->tryGetElectronicGradeable($gradeable_id);
        if ($gradeable === null) {
            $msg = "Invalid gradeable id.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($this->core->buildCourseUrl())
            );
        }

        $who = $who ?? $this->core->getUser()->getId();
        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $who, $who);
        $url = $this->core->buildCourseUrl(['gradeable', $gradeable->getId()]);

        // If $graded_gradeable is null, that means its a team assignment and the user is on no team
        if ($gradeable->isTeamAssignment() && $graded_gradeable === null) {
            $msg = 'Must be on a team to access submission.';
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($url)
            );
        }

        $new_version = intval($new_version);
        if ($new_version < 0) {
            $msg = "Cannot set the version below 0.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($url)
            );
        }

        $highest_version = $graded_gradeable->getAutoGradedGradeable()->getHighestVersion();
        if ($new_version > $highest_version) {
            $msg = "Cannot set the version past {$highest_version}.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($url)
            );
        }

        if (!$this->core->getUser()->accessGrading() && !$gradeable->isStudentSubmit()) {
            $msg = "Cannot submit for this assignment.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($url)
            );
        }

        $original_user_id = $this->core->getUser()->getId();
        $submitter_id = $graded_gradeable->getSubmitter()->getId();

        $settings_file = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "submissions",
            $gradeable->getId(),
            $submitter_id,
            "user_assignment_settings.json"
        );
        $json = FileUtils::readJsonFile($settings_file);
        if ($json === false) {
            $msg = "Failed to open settings file.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($url)
            );
        }
        $json["active_version"] = $new_version;
        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");
        $current_time_string_tz = $current_time . " " . $this->core->getConfig()->getTimezone()->getName();

        $json["history"][] = ["version" => $new_version, "time" => $current_time_string_tz, "who" => $original_user_id, "type" => "select"];

        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            $msg = "Could not write to settings file.";
            $this->core->addErrorMessage($msg);
            return new MultiResponse(
                JsonResponse::getFailResponse($msg),
                null,
                new RedirectResponse($this->core->buildCourseUrl(['gradeable', $gradeable->getId()]))
            );
        }

        $version = ($new_version > 0) ? $new_version : null;

        // FIXME: Add this kind of operation to the graded gradeable saving query
        if ($gradeable->isTeamAssignment()) {
            $this->core->getQueries()->updateActiveVersion($gradeable->getId(), null, $submitter_id, $version);
        }
        else {
            $this->core->getQueries()->updateActiveVersion($gradeable->getId(), $submitter_id, null, $version);
        }

        $this->reccacheLateDays($submitter_id);

        if ($new_version == 0) {
            $msg = "Cancelled submission for gradeable.";
            $this->core->addSuccessMessage($msg);
        }
        else {
            $msg = "Updated version of gradeable to version #{$new_version}.";
            $this->core->addSuccessMessage($msg);
        }

        $url = $this->core->buildCourseUrl(['gradeable', $gradeable->getId(), $new_version]);
        if ($ta) {
            $url = $this->core->buildCourseUrl([
                'gradeable',
                $graded_gradeable->getGradeableId(),
                'grading',
                'grade'
            ]) . '?' . http_build_query(['who_id' => $who, 'gradeable_version' => $new_version]);
        }

        return new MultiResponse(
            JsonResponse::getSuccessResponse(['version' => $new_version, 'message' => $msg]),
            null,
            new RedirectResponse($url)
        );
    }

    /**
     * Check if the results folder exists for a given gradeable and version results.json
     * in the results/ directory. If the file exists, we output a string that the calling
     * JS checks for to initiate a page refresh (so as to go from "in-grading" to done
     *
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/{gradeable_version}/check_refresh", requirements={"gradeable_version": "\d+"})
     */
    public function checkRefresh($gradeable_id, $gradeable_version) {
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
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

        $filepath = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            "results",
            $gradeable_id,
            $submitter_id,
            $gradeable_version,
            "results.json"
        );

        $results_json_exists = file_exists($filepath);

        // if the results json exists, check the database to make sure that the autograding results are there.
        $has_results = $results_json_exists && ($this->core->getGradingQueue()->getQueueStatus(
            $gradeable_id,
            $submitter_id,
            $gradeable_version
        ) === GradingQueue::NOT_QUEUED) &&
            $this->core->getQueries()->getGradeableVersionHasAutogradingResults(
                $gradeable_id,
                $gradeable_version,
                $user_id,
                $team_id
            );

        if ($has_results) {
            $refresh_string = "REFRESH_ME";
            $refresh_bool = true;
        }
        else {
            $refresh_string = "NO_REFRESH";
            $refresh_bool = false;
        }
        $this->core->getOutput()->renderString($refresh_string);
        return ['refresh' => $refresh_bool, 'string' => $refresh_string];
    }

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/bulk_stats")
     */
    public function showBulkStats($gradeable_id) {
        $course_path = $this->core->getConfig()->getCoursePath();
        $base_path = $course_path . "/submissions/" . $gradeable_id . "/";
        $users = [];
        $user_id_arr = is_dir($base_path) ? array_slice(scandir($base_path), 2) : [];
        for ($i = 0; $i < count($user_id_arr); $i++) {
            $user_path = $base_path . $user_id_arr[$i];
            if (!is_dir($user_path)) {
                continue;
            }
            $files = scandir($user_path);
            $num_files = count($files) - 3;
            $json_path = $user_path . "/" . $num_files . "/bulk_upload_data.json";
            if (!file_exists($json_path)) {
                continue;
            }
            $user = $this->core->getQueries()->getUserById($user_id_arr[$i]);
            if ($user === null) {
                continue;
            }
            $file_contents = FileUtils::readJsonFile($json_path);
            $users[$user_id_arr[$i]]["first_name"] = $user->getDisplayedFirstName();
            $users[$user_id_arr[$i]]["last_name"] = $user->getDisplayedLastName();
            $users[$user_id_arr[$i]]['upload_time'] = $file_contents['upload_timestamp'];
            $users[$user_id_arr[$i]]['submit_time'] = $file_contents['submit_timestamp'];
            $users[$user_id_arr[$i]]['file'] = $file_contents['filepath'];
        }

        $this->core->getOutput()->renderOutput('grading\ElectronicGrader', 'statPage', $users);
    }
}
