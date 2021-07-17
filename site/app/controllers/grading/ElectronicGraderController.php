<?php

namespace app\controllers\grading;

use app\libraries\DateUtils;
use app\libraries\DiffViewer;
use app\libraries\routers\AccessControl;
use app\models\gradeable\AutoGradedTestcase;
use app\models\gradeable\Component;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedComponent;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\LateDayInfo;
use app\models\gradeable\LateDays;
use app\models\gradeable\Mark;
use app\models\gradeable\Submitter;
use app\models\gradeable\TaGradedGradeable;
use app\models\GradeableAutocheck;
use app\libraries\Logger;
use app\models\GradingOrder;
use app\models\User;
use app\libraries\FileUtils;
use app\libraries\response\JsonResponse;
use app\controllers\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class ElectronicGraderController extends AbstractController {
    /**
     * Checks that a given diff viewer option is valid using DiffViewer::isValidSpecialCharsOption
     * @param  string $option
     * @return bool
     */
    private function validateDiffViewerOption(string $option) {
        if (!DiffViewer::isValidSpecialCharsOption($option)) {
            $this->core->getOutput()->renderJsonFail('Invalid diff viewer option parameter');
            return false;
        }
        return true;
    }

    /**
     * Checks that a given diff viewer type is valid using DiffViewer::isValidType
     * @param string $type
     * @return bool
     */
    private function validateDiffViewerType(string $type) {
        if (!DiffViewer::isValidType($type)) {
            $this->core->getOutput()->renderJsonFail('Invalid diff viewer type parameter');
            return false;
        }
        return true;
    }

    /**
     * Gnenerates histogram data needed for the TA stats page
     * @param GradedGradeable[] $overall_scores
     * @return array of histogram data
     */
    public function generateHistogramData($overall_scores) {
        $histogram = [
            "bTA" => [],
            "tTA" => [],
            "bAuto" => [],
            "VerConf" => 0,
            "noSub" => 0,
            "noActive" => 0,
            "GradeInq" => 0,
            "IncompGrading" => 0,
            "cancelledSub" => 0
        ];

        // Iterate through all the Scores
        foreach ($overall_scores as $ov) {
            if ($ov->getTaGradedGradeable() == null) {
                continue;
            }

            // If Autograded, add the points to the array of autograded scores
            if ($ov->getAutoGradedGradeable()->getHighestVersion() != 0 && $ov->getTaGradedGradeable() != null) {
                if ($ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                    if ($ov->getGradeable()->getAutogradingConfig()->getTotalNonExtraCredit() != 0) {
                        if ($ov->getAutoGradedGradeable()->getTotalPoints() >= 0 || $ov->getAutoGradedGradeable()->getTotalPoints() < 0) {
                            $histogram["bAuto"] = array_merge($histogram["bAuto"], [$ov->getAutoGradedGradeable()->getTotalPoints()]);
                        }
                        else {
                            $histogram["cancelledSub"] += 1;
                        }
                    }
                }
            }

            if (!$ov->getAutoGradedGradeable()->hasSubmission()) {
                // if no submission and not in Null section add to count
                if ($ov->getTaGradedGradeable() != null && $ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                    $histogram["noSub"] += 1;
                }
            }
            elseif ($ov->getAutoGradedGradeable()->getActiveVersion() == 0) {
                // if no active version and not in Null section add to count
                if ($ov->getTaGradedGradeable() != null && $ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                    $histogram["noActive"] += 1;
                }
            }
            elseif ($ov->getGradeable()->isTaGrading()) {
                if ($ov->getOrCreateTaGradedGradeable()->anyGrades()) {
                    // if grade inquiry and not in Null section add to count
                    if ($ov->hasActiveRegradeRequest()) {
                        if ($ov->getTaGradedGradeable() != null && $ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                            $histogram["noActive"] += 1;
                        }
                    }
                    elseif ($ov->getTaGradedGradeable() != null && $ov->getTaGradedGradeable()->hasVersionConflict()) {
                        // if version conflict and not in Null section add to count
                        if ($ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                            $histogram["VerConf"] += 1;
                        }
                    }
                    elseif (!$ov->isTaGradingComplete()) {
                        // if assignment incomplete and not in Null section add to count
                        $histogram["IncompGrading"] += 1;
                    }
                    elseif ($ov->isTaGradingComplete()) {
                        // otherwise add the overall grade to array and total score possible to array (possible future use)
                        if ($ov->getTaGradedGradeable() != null && $ov->getTaGradedGradeable()->getGradedGradeable()->getSubmitter()->getRegistrationSection() != null) {
                            $histogram["bTA"] = array_merge($histogram["bTA"], [$ov->getTaGradedGradeable()->getTotalScore()]);
                            $histogram["tTA"] = array_merge($histogram["tTA"], [$ov->getGradeable()->getManualGradingPoints()]);
                        }
                    }
                }
            }
        }
        return $histogram;
    }

    /**
     * Helper function for Randomization
     * @param Array $student_array
     * @param int $number_to_grade
     * @return Array $final_grading_info
     */
    private function setRandomizedGraders(array $student_array, int $number_to_grade) {
        $final_grading_info = [];
        $graded_array = $student_array;
        /*n_array_peers : An Array of arrays that holds information on to be graded peers
        [ [A,B,C,D,E,F], [E,F,A,B,C,D], [C,D,E,F,A,B] ]
        A grades C and E and is graded by C and E.
        */
        $n_array_peers = [];
        shuffle($student_array);
        array_push($n_array_peers, $student_array);
        /*final_grading_info : An Array with clear structure of grading rules for peer grading
        [ [A,[C,E]],[B,[F,D]], ...]
        A grades C and E, B grades F and D ..and so on!
        */
            $max_offset = count($student_array);
            $offset_array = [];
            $temp_offset = [];
        for ($i = 1; $i < $max_offset; ++$i) {
            array_push($temp_offset, $i);
        }
        /* $offset_array contains randomly chosen offsets.
        $temp_offset helps to ensure no duplicate offsets exist (By removing already chosen offsets)
        Upon every random choice of an offset from $temp_offset, the value is removed from it.
        */
        for ($i = 0; $i < $number_to_grade; ++$i) {
            $random_offset = array_rand($temp_offset, 1);
            array_push($offset_array, $temp_offset[$random_offset]);
            unset($temp_offset[$random_offset]);
        }
        foreach ($offset_array as $element) {
            $temp_array = $student_array;
            for ($i = 0; $i < $element; $i++) {
                array_push($temp_array, array_shift($temp_array));
            }
            array_push($n_array_peers, $temp_array);
        }
        for ($i = 0; $i < count($n_array_peers[0]); ++$i) {
            $temp = [];
            for ($j = 1; $j < count($n_array_peers); ++$j) {
                array_push($temp, $n_array_peers[$j][$i]);
            }
            array_push($final_grading_info, [$n_array_peers[0][$i],$temp]);
        }
            return $final_grading_info;
    }

    /**
     * Helper function for all grade all in randomized peer assignments
     * @param Array $student_array
     * @return Array $final_grading_info
     */
    private function setAllGradAllGrading($student_array) {
        $final_grading_info = [];
        for ($grader = 0; $grader < count($student_array); ++$grader) {
            $peer_array = $student_array;
            unset($peer_array[$grader]);
            $peer_array = array_values($peer_array);
            array_push($final_grading_info, [$student_array[$grader],$peer_array]);
        }
        return $final_grading_info;
    }
    /**
     * Route for Getting Gradeable
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/getUserGroup", methods={"GET"})
     * @param string $gradeable_id
     * @return JsonResponse
     */
    public function getUserGroup($gradeable_id) {
        $user_group = $this->core->getUser()->getGroup();
        return JsonResponse::getSuccessResponse($user_group);
    }
    /**
     * Route for randomizing peer assignments with 'One Grades Many'
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/RandomizePeers", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function RandomizePeers($gradeable_id) {
        /* How does this function work?
        1 - Number of students to grade (Y) is taken from the client using POST
            1.1 - If the number is > number of students, then ALL grade ALL.
        2 - Query DB to get students from registration section(X) (Without taking in students in NULL section)
        3 - Randomize the order of students
        4 - Randomly Select Y offsets
        5 - Shift the random order by the offsets to create the matrix, with no duplicates, and exactly Y assignments and & graders for each student.  no student grades self.
        */
        $restrict_to_registration = ($_POST['restrict_to_registration'] ?? '') === "checked";
        $submit_before_grading = ($_POST['submit_before_grading'] ?? '') === "checked";
        $number_to_grade = $_POST['number_to_grade'] ?? '';
        if (!is_numeric($number_to_grade)) {
            $this->core->addErrorMessage("Peer assignment failed: An invalid number of students to grade was assigned");
            return JsonResponse::getFailResponse("Number to grade wasn't an integer");
        }
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            $this->core->addErrorMessage('Invalid Gradeable!');
            $this->core->redirect($this->core->buildCourseUrl());
        }
        /* If Restrict to Registration checkbox is checked, then the randomised peer assignments should be restricted to each registration section" */
        if ($restrict_to_registration) {
            $sections = $this->core->getQueries()->getRegistrationSections();
            foreach ($sections as $i => $section) {
                $sections[$i] = $section['sections_registration_id'];
            }
            /* Duplicating the entire functionality of randomization, but for each registration section rather than whole class" */
            $final_grading_info = [];
            foreach ($sections as $section) {
                $student_list = [];
                $all_grade_all_registration = false;
                $student_array = [];
                $students = $this->core->getQueries()->getUsersByRegistrationSections([$section]);
                foreach ($students as $student) {
                    array_push($student_list, ['user_id' => $student->getId()]);
                    if ($submit_before_grading) {
                        if ($this->core->getQueries()->getUserHasSubmission($gradeable, $student->getId()) == $student->getId()) {
                        }
                        else {
                            array_push($student_array, $student->getId());
                        }
                    }
                    else {
                        array_push($student_array, $student->getId());
                    }
                }

                $number_of_students = count($student_array);
                /* If number of students entered is more than number of students in registration section,
                   then for each registration section with less number of students, everyone will grade everyone */
                if ($number_to_grade >= $number_of_students) {
                    $all_grade_all_registration = true;
                }
                if ($all_grade_all_registration) {
                    for ($grader = 0; $grader < count($student_array); ++$grader) {
                        $peer_array = $student_array;
                        unset($peer_array[$grader]);
                        $peer_array = array_values($peer_array);
                        array_push($final_grading_info, [$student_array[$grader],$peer_array]);
                    }
                }
                else {
                    $final_grading_info = $this->setRandomizedGraders($student_array, $number_to_grade);
                }
            }
            $gradeable->setRandomPeerGradersList($final_grading_info);
            return JsonResponse::getSuccessResponse($final_grading_info);
        }

        $all_grade_all = false;
        $order = new GradingOrder($this->core, $gradeable, $this->core->getUser(), true);
        $student_array = [];
        $student_list = [];
        $students = $this->core->getQueries()->getUsersByRegistrationSections($order->getSectionNames());
        foreach ($students as $student) {
             $reg_sec = ($student->getRegistrationSection() === null) ? 'NULL' : $student->getRegistrationSection();
             $sorted_students[$reg_sec][] = $student;
             array_push($student_list, ['user_id' => $student->getId()]);
            if ($submit_before_grading) {
                if ($this->core->getQueries()->getUserHasSubmission($gradeable, $student->getId()) == $student->getId()) {
                }
                else {
                    array_push($student_array, $student->getId());
                }
            }
            else {
                array_push($student_array, $student->getId());
            }
        }
        $number_of_students = count($student_array);
        if ($number_of_students <= 1) {
            $this->core->addErrorMessage("Peer assignments failed: Not enough submissions");
            return JsonResponse::getFailResponse("Not Enough Submissions");
        }
        if ($number_to_grade > $number_of_students) {
            $all_grade_all = true;
        }
        if ($all_grade_all) {
            $final_grading_info = $this->setAllGradAllGrading($student_array);
            $gradeable->setRandomPeerGradersList($final_grading_info);
            return JsonResponse::getSuccessResponse($final_grading_info);
        }
        $final_grading_info = $this->setRandomizedGraders($student_array, $number_to_grade);
        if ($number_to_grade < 1) {
            $gradeable->setRandomPeerGradersList($final_grading_info);
            return JsonResponse::getSuccessResponse("Clear Peer Matrix");
        }
        $gradeable->setRandomPeerGradersList($final_grading_info);
        $gradeable->setPeerGradingSet($number_to_grade);
        return JsonResponse::getSuccessResponse($final_grading_info);
    }
    /**
     * Route for getting whitespace information for the diff viewer
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/student_output/remove")
     */
    public function ajaxRemoveEmpty(
        $gradeable_id,
        $who_id = '',
        $index = '',
        $option = 'original',
        $version = '',
        $which = 'actual',
        $autocheck_cnt = '0'
    ) {
        //There are three options: original (Don't show empty space), escape (with escape codes), and unicode (with characters)
        if (!$this->validateDiffViewerOption($option)) {
            return;
        }

        // Type can be either 'actual' or 'expected'
        if (!$this->validateDiffViewerType($which)) {
            return;
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $who_id);
        if ($graded_gradeable === false) {
            return;
        }

        // get the requested version
        $version_instance = $this->tryGetVersion($graded_gradeable->getAutoGradedGradeable(), $version);
        if ($version_instance === false) {
            return;
        }

        // Get the requested testcase
        $testcase = $this->tryGetTestcase($version_instance, $index);
        if ($testcase === false) {
            return;
        }

        // Get the requested autocheck
        $autocheck = $this->tryGetAutocheck($testcase, $autocheck_cnt);
        if ($autocheck === false) {
            return;
        }

        try {
            $results = $this->removeEmpty($autocheck, $option, $which);
            $this->core->getOutput()->renderJsonSuccess($results);
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    private function removeEmpty(GradeableAutocheck $autocheck, string $option, string $type) {
        $diff_viewer = $autocheck->getDiffViewer();

        //There are currently two views, the view of student's code and the expected view.
        if ($type === DiffViewer::ACTUAL) {
            $html = $diff_viewer->getDisplayActual($option);
        }
        else {
            $html = $diff_viewer->getDisplayExpected($option);
        }
        $white_spaces = $diff_viewer->getWhiteSpaces();
        return ['html' => $html, 'whitespaces' => $white_spaces];
    }

    /**
     * Route for verifying the grader of a graded component
     * @param string $gradeable_id verify all components or not
     * @param bool $verify_all false be default
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/verify", methods={"POST"})
     * @AccessControl(permission="grading.electronic.verify_grader")
     */
    public function ajaxVerifyComponent($gradeable_id, $verify_all = false) {
        $anon_id = $_POST['anon_id'] ?? '';

        $grader = $this->core->getUser();

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }
        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }
        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }
        // Get / create the TA grade
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();
        if (!$verify_all) {
            $component_id = $_POST['component_id'] ?? '';
            // get the component
            $component = $this->tryGetComponent($gradeable, $component_id);
            if ($component === false) {
                return;
            }

            // Get / create the graded component
            $graded_component = $ta_graded_gradeable->getOrCreateGradedComponent($component, $grader, false);

            // Verifying individual component should fail if its ungraded
            if ($graded_component === null) {
                $this->core->getOutput()->renderJsonFail('Cannot verify ungraded component');
                return;
            }
        }
        try {
            if ($verify_all === 'true') {
                foreach ($gradeable->getComponents() as $comp) {
                    if (!$comp->isPeerComponent()) {
                        $graded_component = $ta_graded_gradeable->getGradedComponent($comp);
                        if ($graded_component !== null && $graded_component->getGraderId() != $grader->getId()) {
                            $graded_component->setVerifier($grader);
                            $graded_component->setVerifyTime($this->core->getDateTimeNow());
                        }
                    }
                }
            }
            else {
                $graded_component->setVerifier($grader);
                $graded_component->setVerifyTime($this->core->getDateTimeNow());
            }
            $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Shows statistics for the grading status of a given electronic submission. This is shown to all full access
     * graders. Limited access graders will only see statistics for the sections they are assigned to.
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/status")
     */
    public function showStatus($gradeable_id) {
        $gradeable = $this->tryGetGradeable($gradeable_id, false);
        if ($gradeable === false) {
            $this->core->addErrorMessage('Invalid gradeable id');
            $this->core->redirect($this->core->buildCourseUrl());
        }

        if (!$this->core->getAccess()->canI("grading.electronic.status", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getTitle()}");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        $gradeableUrl = $this->core->buildCourseUrl(['gradeable', $gradeable->getId(), 'grading', 'status']);
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getTitle()} Grading", $gradeableUrl);

        $isPeerGradeable = false;
        if ($gradeable->hasPeerComponent() && ($this->core->getUser()->getGroup() < User::GROUP_STUDENT)) {
            $isPeerGradeable = true;
        }
        $peer = false;
        if ($gradeable->hasPeerComponent() && ($this->core->getUser()->getGroup() == User::GROUP_STUDENT)) {
            $peer = true;
        }

        //get all graded gradeables for queue stats
        $submissions_in_queue = 0;
        $gradeables[] = $gradeable;
        $graded_gradeables = $this->core->getQueries()->getGradedGradeables($gradeables);
        //check every submission (inactive and active) for every student
        //NOTE: Let's eventually refactor this nested loop to instead look at the items
        //in the autograding queue and check for matches.
        foreach ($graded_gradeables as $g) {
            $highest_version = $g->getAutoGradedGradeable()->getHighestVersion();
            if ($highest_version > 0) {
                for ($i = 1; $i < $highest_version + 1; $i++) {
                    $display_version_instance = $g->getAutoGradedGradeable()->getAutoGradedVersionInstance($i);
                    if ($display_version_instance->isQueued()) {
                        $submissions_in_queue += 1;
                    }
                }
            }
        }

        /*
         * we need number of students per section
         */

        $no_team_users = [];
        $my_grading = 0;
        $num_components = 0;
        $graded_components = [];
        $ta_graded_components = [];
        $graders = [];
        $average_scores = [];
        $sections = [];
        $total_users = [];
        $component_averages = [];
        $histogram_data = [];
        $autograded_average = null;
        $overall_average = null;
        $overall_scores = null;
        $order = null;
        $num_submitted = [];
        $num_unsubmitted = 0;
        $total_indvidual_students = 0;
        $viewed_grade = 0;
        $num_gradeables = 1;
        $total_who_submitted = 0;
        $peers_to_grade = 0;
        $peer_graded_components = 0;
        $total_users_who_submitted = [];

        $regrade_requests = $this->core->getQueries()->getNumberGradeInquiries($gradeable_id, $gradeable->isGradeInquiryPerComponentAllowed());
        if ($isPeerGradeable) {
            $total_users_who_submitted = $this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_id, $sections, 'registration_section');
            $peer_graded_components = 0;
            $order = new GradingOrder($this->core, $gradeable, $this->core->getUser(), true);
            $student_array = [];
            $student_list = [];
            $students = $this->core->getQueries()->getUsersByRegistrationSections($order->getSectionNames());
            foreach ($students as $student) {
                $reg_sec = ($student->getRegistrationSection() === null) ? 'NULL' : $student->getRegistrationSection();
                $sorted_students[$reg_sec][] = $student;
                array_push($student_list, ['user_id' => $student->getId()]);
                array_push($student_array, $student->getId());
            }
            foreach ($student_array as $student) {
                $peer_graded_components += $this->core->getQueries()->getNumGradedPeerComponents($gradeable_id, $student);
            }
        }
        if ($peer) {
            $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, 'registration_section');
            $peer_array = $this->core->getQueries()->getPeerAssignment($gradeable_id, $this->core->getUser()->getId());
            $peers_to_grade = count($peer_array);
            $num_components = count($gradeable->getPeerComponents());
            $graded_components = $this->core->getQueries()->getGradedPeerComponentsByRegistrationSection($gradeable_id, $sections);
            $ta_graded_components = $this->core->getQueries()->getGradedPeerComponentsByRegistrationSection($gradeable_id, $sections);
            $num_gradeables = count($this->core->getQueries()->getPeerGradingAssignmentsForGrader($this->core->getUser()->getId()));
            $my_grading = $this->core->getQueries()->getNumGradedPeerComponents($gradeable_id, $this->core->getUser()->getId());
            $component_averages = [];
            $autograded_average = null;
            $overall_average = null;
            $overall_scores = null;
            $section_key = 'registration_section';
        }
        elseif ($gradeable->isGradeByRegistration()) {
            if (!$this->core->getAccess()->canI("grading.electronic.status.full")) {
                $sections = $this->core->getUser()->getGradingRegistrationSections();
            }
            else {
                $sections = $this->core->getQueries()->getRegistrationSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_registration_id'];
                }
            }
            $section_key = 'registration_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
            }
            $num_components = count($gradeable->getNonPeerComponents());
        }
        else {
            //grading by rotating section
            if (!$this->core->getAccess()->canI("grading.electronic.status.full")) {
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_rotating_id'];
                }
            }
            $section_key = 'rotating_section';
            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable_id, $sections);
            }
        }

        //Check if this is a team project or a single-user project
        if ($gradeable->isTeamAssignment()) {
            $num_submitted = $this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'registration_section');
        }
        else {
            $num_submitted = $this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_id, $sections, $section_key);
        }

        if (count($sections) > 0) {
            if ($gradeable->isTeamAssignment()) {
                $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, $section_key);
                $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_id, $sections, $section_key);
                $team_users = $this->core->getQueries()->getUsersWithTeamByGradingSections($gradeable_id, $sections, $section_key);
                $individual_viewed_grade = $this->core->getQueries()->getNumUsersWhoViewedTeamAssignmentBySection($gradeable, $sections);
            }
            else {
                $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
                $no_team_users = [];
                $team_users = [];
                $individual_viewed_grade = 0;
            }
            $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, $section_key, $gradeable->isTeamAssignment());
            $ta_graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, $section_key, $gradeable->isTeamAssignment());
            $component_averages = $this->core->getQueries()->getAverageComponentScores($gradeable_id, $section_key, $gradeable->isTeamAssignment());
            $autograded_average = $this->core->getQueries()->getAverageAutogradedScores($gradeable_id, $section_key, $gradeable->isTeamAssignment());
            $override_cookie = array_key_exists('include_overridden', $_COOKIE) ? $_COOKIE['include_overridden'] : 'omit';
            $overall_average = $this->core->getQueries()->getAverageForGradeable($gradeable_id, $section_key, $gradeable->isTeamAssignment(), $override_cookie);
            $order = new GradingOrder($this->core, $gradeable, $this->core->getUser(), true);
            $overall_scores = [];
            $overall_scores = $order->getSortedGradedGradeables();
            $num_components = count($gradeable->getNonPeerComponents());
            $viewed_grade = $this->core->getQueries()->getNumUsersWhoViewedGradeBySections($gradeable, $sections);
            $histogram_data = $this->generateHistogramData($overall_scores);
        }
        $sections = [];
        //Either # of teams or # of students (for non-team assignments). Either case
        // this is the max # of submitted copies for this gradeable.
        $total_submissions = 0;
        if (count($total_users) > 0) {
            foreach ($total_users as $key => $value) {
                if ($key == 'NULL') {
                    continue;
                }
                $total_submissions += $value;
            }
            foreach ($total_users_who_submitted as $key => $value) {
                if ($key === 'NULL') {
                    continue;
                }
                $total_who_submitted += $value;
            }
            if (!$gradeable->isTeamAssignment() && $isPeerGradeable) {
                $sections['peer_stu_grad'] = [
                   // Total peer components to grade = Number of peer components * Number of Students who submitted
                   'total_who_submitted' => $total_who_submitted,
                   'total_components' => count($gradeable->getPeerComponents()) * $total_who_submitted,
                   'graded_components' => 0,
                   'view_peer_graded_components' => $peer_graded_components,
                   'ta_graded_components' => 0,
                   'num_gradeables' => $num_gradeables,
                   'graders' => [],
                   'valid_graders' => []
                ];
            }
            if ($peer) {
                 // If a team assignment => Team Peer Grading Stats Should be Visible
                 // Stats are broken, Update this after Teams work fine with Randomized Peer Assignments
                if ($gradeable->isTeamAssignment()) {
                    $sections['stu_grad'] = [
                       'total_components' => count($gradeable->getPeerComponents()), // Multiply it by number of teams assigned to grade
                       'graded_components' => $my_grading,
                       'num_gradeables' => $num_gradeables,
                       'ta_graded_components' => 0,
                       'graders' => [],
                       'valid_graders' => []
                    ];
                    $sections['all'] = [
                       'total_components' => 0,
                       'graded_components' => 0,
                       'graders' => [],
                       'valid_graders' => []
                    ];
                    foreach ($total_users as $key => $value) {
                        if ($key == 'NULL') {
                            continue;
                        }
                        $sections['all']['total_components'] += $value * $num_components;
                        $sections['all']['graded_components'] += isset($graded_components[$key]) ? $graded_components[$key] : 0;
                    }
                    $sections['all']['total_components'] -= $num_components;
                    $sections['all']['graded_components'] -= $my_grading;
                    $sections['stu_grad']['no_team'] = 0;
                    $sections['stu_grad']['team'] = 0;
                    $sections['all']['no_team'] = 0;
                    $sections['all']['team'] = 0;
                }
                else {
                    // If not a team assignment => Individual Peer Grading Stats Should be Visible
                    $sections['stu_grad'] = [
                        'total_components' => $num_components * $peers_to_grade,
                        'graded_components' => $my_grading,
                        'num_gradeables' => $num_gradeables,
                        'ta_graded_components' => 0,
                        'graders' => [],
                        'valid_graders' => []
                    ];
                    $sections['all'] = [
                        'total_components' => 0,
                        'graded_components' => 0,
                        'graders' => [],
                        'valid_graders' => []
                    ];
                    foreach ($total_users as $key => $value) {
                        if ($key == 'NULL') {
                            continue;
                        }
                        $sections['all']['total_components'] += $value * $num_components;
                        $sections['all']['graded_components'] += isset($graded_components[$key]) ? $graded_components[$key] : 0;
                    }
                    $sections['all']['total_components'] -= $num_components;
                    $sections['all']['graded_components'] -= $my_grading;
                }
            }
            else {
                foreach ($total_users as $key => $value) {
                    if (array_key_exists($key, $num_submitted)) {
                        $sections[$key] = [
                            'total_components' => $num_submitted[$key],
                            'graded_components' => 0,
                            'ta_graded_components' => 0,
                            'graders' => [],
                            'valid_graders' => []
                        ];
                    }
                    else {
                        $sections[$key] = [
                            'total_components' => 0,
                            'graded_components' => 0,
                            'graders' => [],
                            'valid_graders' => []
                        ];
                    }
                    if ($gradeable->isTeamAssignment()) {
                        $sections[$key]['no_team'] = $no_team_users[$key];
                        $sections[$key]['team'] = $team_users[$key];
                    }
                    if (isset($graded_components[$key])) {
                        // Clamp to total components if unsubmitted assigment is graded for whatever reason
                        $sections[$key]['graded_components'] = $graded_components[$key];
                        $sections[$key]['ta_graded_components'] = min(intval($graded_components[$key]), $sections[$key]['total_components']);
                    }
                    if (isset($graders[$key])) {
                        $sections[$key]['graders'] = $graders[$key];

                        if ($key !== "NULL") {
                            $valid_graders = [];
                            foreach ($graders[$key] as $valid_grader) {
                                /* @var User $valid_grader */
                                if ($this->core->getAccess()->canUser($valid_grader, "grading.electronic.grade", ["gradeable" => $gradeable])) {
                                    $valid_graders[] = $valid_grader->getDisplayedFirstName();
                                }
                            }
                            $sections[$key]["valid_graders"] = $valid_graders;
                        }
                    }
                }
            }
        }
        $registered_but_not_rotating = count($this->core->getQueries()->getRegisteredUsersWithNoRotatingSection());
        $rotating_but_not_registered = count($this->core->getQueries()->getUnregisteredStudentsWithRotatingSection());

        $show_warnings = $this->core->getAccess()->canI("grading.electronic.status.warnings");

        if (isset($order) && $gradeable->isTeamAssignment()) {
            $total_students_submitted = 0;
            foreach ($order->getSortedGradedGradeables() as $g) {
                $team = $g->getSubmitter()->getTeam();
                $team_section = $gradeable->isGradeByRegistration() ? $team->getRegistrationSection() : $team->getRotatingSection();
                if (array_key_exists($team_section, $total_users)) {
                    if ($this->core->getQueries()->getActiveVersionForTeam($gradeable->getId(), $team->getId()) != 0) {
                        $total_students_submitted += count($team->getMembers());
                    }
                }
            }
        }
        else {
            $total_students_submitted = 0;
        }
        $this->core->getOutput()->renderOutput(
            ['grading', 'ElectronicGrader'],
            'statusPage',
            $gradeable,
            $sections,
            $component_averages,
            $autograded_average,
            $overall_scores,
            $overall_average,
            $histogram_data,
            $total_submissions,
            $individual_viewed_grade ?? 0,
            $total_students_submitted,
            $registered_but_not_rotating,
            $rotating_but_not_registered,
            $viewed_grade,
            $section_key,
            $regrade_requests,
            $show_warnings,
            $submissions_in_queue
        );
    }

    /**
     * Shows the list of submitters
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/details")
     */
    public function showDetails($gradeable_id, $view = null, $sort = "id", $direction = "ASC") {
        // Default is viewing your sections
        // Limited grader does not have "View All" option
        // If nothing to grade, Instructor will see all sections
        $view_all = $view === 'all';

        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            $this->core->addErrorMessage('Invalid Gradeable!');
            $this->core->redirect($this->core->buildCourseUrl());
        }

        $gradeableUrl = $this->core->buildCourseUrl(['gradeable', $gradeable->getId(), 'grading', 'status']);
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getTitle()} Grading", $gradeableUrl);

        $this->core->getOutput()->addBreadcrumb('Student Index');

        $peer = ($gradeable->hasPeerComponent() && $this->core->getUser()->getGroup() == User::GROUP_STUDENT);
        if (!$this->core->getAccess()->canI("grading.electronic.details", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getTitle()}");
            $this->core->redirect($this->core->buildCourseUrl());
        }
        $anon_mode = isset($_COOKIE['anon_mode']) && $_COOKIE['anon_mode'] === 'on';

        //Checks to see if the Grader has access to all users in the course,
        //Will only show the sections that they are graders for if not TA or Instructor
        $can_show_all = $this->core->getAccess()->canI("grading.electronic.details.show_all");
        $show_all = $view_all && $can_show_all;

        $order = new GradingOrder($this->core, $gradeable, $this->core->getUser(), $show_all);

        $order->sort($sort, $direction);

        $section_submitters = $order->getSectionSubmitters();
        $section_key = $order->getSectionKey();
        $graders = $order->getSectionGraders();
        $sections = $order->getSectionNames();

        $student_ids = [];
        foreach ($section_submitters as $section) {
            $student_ids = array_merge($student_ids, array_map(function (Submitter $submitter) {
                return $submitter->getId();
            }, $section));
        }

        $show_empty_teams = $this->core->getAccess()->canI("grading.electronic.details.show_empty_teams");
        $empty_teams = [];
        if ($gradeable->isTeamAssignment()) {
            // Only give getGradeables one User ID per team
            $all_teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
            foreach ($all_teams as $team) {
                $student_ids = array_diff($student_ids, $team->getMembers());
                $team_section = $gradeable->isGradeByRegistration() ? $team->getRegistrationSection() : $team->getRotatingSection();
                if ($team->getSize() > 0 && (in_array($team_section, $sections) || $show_all)) {
                    $student_ids[] = $team->getLeaderId();
                }
                if ($team->getSize() === 0 && $show_empty_teams) {
                    $empty_teams[] = $team;
                }
            }
        }

        $graded_gradeables = [];
        $user_ids = $this->core->getQueries()->getUsersOnTeamsForGradeable($gradeable); // Collect user ids so we know who isn't on a team
        /** @var GradedGradeable $g */
        foreach ($order->getSortedGradedGradeables() as $g) {
            $graded_gradeables[] = $g;
            if ($gradeable->isTeamAssignment()) {
                $user_ids = array_merge($user_ids, $g->getSubmitter()->getTeam()->getMemberUserIds());
            }
        }
        $teamless_users = [];
        if ($gradeable->isTeamAssignment()) {
            //Find teamless users
            if ($show_all) {
                $students = $this->core->getQueries()->getAllUsers();
            }
            else {
                if ($gradeable->isGradeByRegistration()) {
                    $students = $this->core->getQueries()->getUsersByRegistrationSections($order->getSectionNames());
                }
                else {
                    $students = $this->core->getQueries()->getUsersByRotatingSections($order->getSectionNames());
                }
            }
            foreach ($students as $user) {
                if (!in_array($user->getId(), $user_ids)) {
                    $teamless_users[] = $user;
                }
            }
        }
        $show_all_sections_button = $can_show_all;
        $show_edit_teams = $this->core->getAccess()->canI("grading.electronic.show_edit_teams") && $gradeable->isTeamAssignment();
        $show_import_teams_button = $show_edit_teams && (count($all_teams) > count($empty_teams));
        $show_export_teams_button = $show_edit_teams && (count($all_teams) == count($empty_teams));
        $past_grade_start_date = $gradeable->getDates()['grade_start_date'] < $this->core->getDateTimeNow();

        $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'detailsPage', $gradeable, $graded_gradeables, $teamless_users, $graders, $empty_teams, $show_all_sections_button, $show_import_teams_button, $show_export_teams_button, $show_edit_teams, $past_grade_start_date, $view_all, $sort, $direction, $anon_mode);

        if ($show_edit_teams) {
            $all_reg_sections = $this->core->getQueries()->getRegistrationSections();
            $key = 'sections_registration_id';
            foreach ($all_reg_sections as $i => $section) {
                $all_reg_sections[$i] = $section[$key];
            }

            $all_rot_sections = $this->core->getQueries()->getRotatingSections();
            $key = 'sections_rotating_id';

            foreach ($all_rot_sections as $i => $section) {
                $all_rot_sections[$i] = $section[$key];
            }

            $students = $this->core->getQueries()->getAllUsers();
            $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'adminTeamForm', $gradeable, $all_reg_sections, $all_rot_sections, $students);
            $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'importTeamForm', $gradeable);

            $this->core->getOutput()->renderOutput(['grading','ElectronicGrader'], 'randomizeButtonWarning', $gradeable);
        }
    }

    /**
     * Imports teams from a csv file upload
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/teams/import", methods={"POST"})
     */
    public function importTeams($gradeable_id) {
        $gradeable = $this->tryGetGradeable($gradeable_id, false);
        if ($gradeable === false) {
            $this->core->addErrorMessage("Failed to load gradeable: {$gradeable_id}");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        $return_url = $this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details']) . '?view=all';

        if (!$this->core->getAccess()->canI("grading.electronic.import_teams", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to do that.");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        if (!$gradeable->isTeamAssignment()) {
            $this->core->addErrorMessage("{$gradeable->getTitle()} is not a team assignment");
            $this->core->redirect($return_url);
        }

        if ($_FILES['upload_team']['name'] == "") {
            $this->core->addErrorMessage("No input file specified");
            $this->core->redirect($return_url);
        }

        $csv_file = $_FILES['upload_team']['tmp_name'];
        register_shutdown_function(
            function () use ($csv_file) {
                unlink($csv_file);
            }
        );
        ini_set("auto_detect_line_endings", true);

        $contents = file($csv_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($contents === false) {
            $this->core->addErrorMessage("File was not properly uploaded. Contact your sysadmin.");
            $this->core->redirect($return_url);
        }

        $row_num = 1;
        $error_message = "";
        $new_teams_members = [];
        $team_names = [];
        foreach ($contents as $content) {
            $vals = str_getcsv($content);
            $vals = array_map('trim', $vals);
            if (count($vals) != 7) {
                $error_message .= "ERROR on row {$row_num}, csv row do not follow specified format<br>";
                continue;
            }
            if ($row_num == 1) {
                $row_num += 1;
                continue;
            }
            $team_name = $vals[4];
            $team_id = $vals[3];
            $user_id = $vals[2];

            if ($team_name == '') {
                $team_name = null;
            }

            if ($this->core->getQueries()->getUserById($user_id) === null) {
                $error_message .= "ERROR on row {$row_num}, user_id doesn't exists<br>";
                continue;
            }
            if (!array_key_exists($team_id, $new_teams_members)) {
                $new_teams_members[$team_id] = [];
            }
            array_push($new_teams_members[$team_id], $user_id);
            $team_names[$team_id] = $team_name;
        }

        if ($error_message != "") {
            $this->core->addErrorMessage($error_message);
            $this->core->redirect($return_url);
        }

        $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
        if (!FileUtils::createDir($gradeable_path)) {
            $this->core->addErrorMessage("Failed to make folder for this assignment");
            $this->core->redirect($return_url);
        }

        foreach ($new_teams_members as $team_id => $members) {
            $leader_id = $members[0];

            $leader = $this->core->getQueries()->getUserById($leader_id);
            $members = $this->core->getQueries()->getUsersById(array_slice($members, 1));
            try {
                $gradeable->createTeam($leader, $members, '', -1, $team_names[$team_id]);
            }
            catch (\Exception $e) {
                $this->core->addErrorMessage("Team may not have been properly initialized ($leader_id): {$e->getMessage()}");
            }
        }

        $this->core->addSuccessMessage("All Teams are imported to the gradeable");
        $this->core->redirect($return_url);
    }

    /**
     * Exports team into a csv file and displays it to the user
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/teams/export")
     */
    public function exportTeams($gradeable_id) {
        $gradeable = $this->tryGetGradeable($gradeable_id, false);
        if ($gradeable === false) {
            $this->core->addErrorMessage("Failed to load gradeable: {$gradeable_id}");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        if (!$this->core->getAccess()->canI("grading.electronic.export_teams", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to do that.");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        $all_teams = $gradeable->getTeams();
        $nl = "\n";
        $csvdata = "First Name,Last Name,User ID,Team ID,Team Name,Team Registration Section,Team Rotating Section" . $nl;
        foreach ($all_teams as $team) {
            if ($team->getSize() != 0) {
                foreach ($team->getMemberUsers() as $user) {
                    $csvdata .= implode(',', [
                        $user->getDisplayedFirstName(),
                        $user->getDisplayedLastName(),
                        $user->getId(),
                        $team->getId(),
                        $team->getTeamName(),
                        $team->getRegistrationSection(),
                        $team->getRotatingSection()
                    ]);
                    $csvdata .= $nl;
                }
            }
        }
        $filename = $this->core->getConfig()->getCourse() . "_" . $gradeable_id . "_teams.csv";
        $this->core->getOutput()->renderFile($csvdata, $filename);
    }

    /**
     * Randomly redistributes teams with members into Rotating Grading Sections
     * Evenly distributes them between all sections, giving extra teams to Sections numerically if necessary
     * Ex: 13 teams in 3 sections will always give Section 1: 5 teams; Section 2: 4 teams;  Section 3: 4 teams
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/teams/randomize_rotating")
     */
    public function randomizeTeamRotatingSections($gradeable_id) {
        $section_count = $this->core->getQueries()->getMaxRotatingSection();
        $return_url = $this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details']) . '?' . http_build_query(['view' => 'all']);
        $teams = $this->core->getQueries()->getTeamsWithMembersFromGradeableID($gradeable_id);

        //Does nothing if there are no sections or no teams
        if ($section_count <= 0 || empty($teams)) {
            $this->core->redirect($return_url);
            return;
        }

        shuffle($teams);

        $cur_group = 1;
        foreach ($teams as $team_id) {
            $this->core->getQueries()->updateTeamRotatingSection($team_id, $cur_group);
            $cur_group++;
            if ($cur_group > $section_count) {
                $cur_group = 1;
            }
        }

        $this->core->redirect($return_url);
    }

    /**
     * Handle requests to create individual teams via the AdminTeamForm
     * @AccessControl(permission="grading.electronic.submit_team_form")
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/teams/new", methods={"POST"})
     */
    public function adminTeamSubmit($gradeable_id) {
        $view = $_POST['view'] ?? '';
        $new_team = ($_POST['new_team'] ?? '') === 'true';
        $leader_id = $_POST['new_team_user_id'] ?? '';
        $team_id = $_POST['edit_team_team_id'] ?? '';
        $reg_section = $_POST['reg_section'] ?? 'NULL';
        $rot_section = $_POST['rot_section'] ?? 'NULL';

        if ($rot_section === 'NULL') {
            $rot_section = 0;
        }
        else {
            $rot_section = intval($rot_section);
        }

        $gradeable = $this->tryGetGradeable($gradeable_id, false);
        if ($gradeable === false) {
            $this->core->addErrorMessage("Failed to load gradeable: {$gradeable_id}");
            $this->core->redirect($this->core->buildCourseUrl());
        }

        $return_url = $this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details']);
        if ($view !== '') {
            $return_url .= "?view={$view}";
        }

        if (!$gradeable->isTeamAssignment()) {
            $this->core->addErrorMessage("{$gradeable->getTitle()} is not a team assignment");
            $this->core->redirect($return_url);
        }

        $num_users = intval($_POST['num_users']);
        $user_ids = [];
        for ($i = 0; $i < $num_users; $i++) {
            $id = trim(htmlentities($_POST["user_id_{$i}"]));
            if (in_array($id, $user_ids)) {
                $this->core->addErrorMessage("ERROR: {$id} is already on this team");
                $this->core->redirect($return_url);
            }
            // filter empty strings and leader
            if ($id !== "" && $id !== $leader_id) {
                $user_ids[] = $id;
            }
        }

        // Load the user instances from the database
        $users = $this->core->getQueries()->getUsersById($user_ids);
        $invalid_members = array_diff($user_ids, array_keys($users));
        if (count($invalid_members) > 0) {
            $members_message = implode(', ', $invalid_members);
            $this->core->addErrorMessage("ERROR: {$members_message} are not valid User IDs");
            $this->core->redirect($return_url);
        }

        $team_name = $_POST['team_name'] ?? null;

        if ($new_team) {
            $leader = $this->core->getQueries()->getUserById($leader_id);
            try {
                $gradeable->createTeam($leader, $users, $reg_section, $rot_section, $team_name);
                $this->core->addSuccessMessage("Created New Team {$team_id}");
            }
            catch (\Exception $e) {
                $this->core->addErrorMessage("Team may not have been properly initialized: {$e->getMessage()}");
                $this->core->redirect($return_url);
            }
        }
        else {
            $team = $this->core->getQueries()->getTeamById($team_id);
            if ($team === null) {
                $this->core->addErrorMessage("ERROR: {$team_id} is not a valid Team ID");
                $this->core->redirect($return_url);
            }
            $team_members = $team->getMembers();
            $add_user_ids = [];
            foreach ($user_ids as $id) {
                if (!in_array($id, $team_members)) {
                    if ($this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $id) !== null) {
                        $this->core->addErrorMessage("ERROR: {$id} is already on a team");
                        $this->core->redirect($return_url);
                    }
                    $add_user_ids[] = $id;
                }
            }
            $remove_user_ids = [];
            foreach ($team_members as $id) {
                if (!in_array($id, $user_ids)) {
                    $remove_user_ids[] = $id;
                }
            }

            $this->core->getQueries()->updateTeamRegistrationSection($team_id, $reg_section === 'NULL' ? null : $reg_section);
            $this->core->getQueries()->updateTeamRotatingSection($team_id, $rot_section === 0 ? null : $rot_section);
            $this->core->getQueries()->updateTeamName($team_id, $team_name);
            foreach ($add_user_ids as $id) {
                $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $id);
                $this->core->getQueries()->acceptTeamInvitation($team_id, $id);
            }
            foreach ($remove_user_ids as $id) {
                $this->core->getQueries()->leaveTeam($team_id, $id);
            }
            $this->core->addSuccessMessage("Updated Team {$team_id}");

            $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO") . " " . $this->core->getConfig()->getTimezone()->getName();
            $settings_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id, $team_id, "user_assignment_settings.json");
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                $this->core->addErrorMessage("Failed to open settings file");
                $this->core->redirect($return_url);
            }
            foreach ($add_user_ids as $id) {
                $json["team_history"][] = ["action" => "admin_add_user", "time" => $current_time,
                    "admin_user" => $this->core->getUser()->getId(), "added_user" => $id];
            }
            foreach ($remove_user_ids as $id) {
                $json["team_history"][] = ["action" => "admin_remove_user", "time" => $current_time,
                    "admin_user" => $this->core->getUser()->getId(), "removed_user" => $id];
            }
            if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
                $this->core->addErrorMessage("Failed to write to team history to settings file");
            }
        }

        $this->core->redirect($return_url);
    }

    /**
     * Given a gradeable, the logged in user, and if the logged in user is a peer
     * determine if the user should be single, double, or unblind grading
     */

    private function amIBlindGrading($gradeable, $user, $peer) {
        if ($peer && $gradeable->getPeerBlind() === Gradeable::DOUBLE_BLIND_GRADING) {
            return "double";
        }

        if (($peer && $gradeable->getPeerBlind() === Gradeable::SINGLE_BLIND_GRADING) || ($gradeable->getLimitedAccessBlind() === Gradeable::SINGLE_BLIND_GRADING && $this->core->getUser()->getGroup() === User::GROUP_LIMITED_ACCESS_GRADER)) {
            return "single";
        }

        return "unblind";
    }

    /**
     * Display the electronic grading page
     *
     * @param $who_id This is the user we wish to view, this field will only be passed on when the request originates
     *                  on the grading index page
     * @param $from This is the user that was being viewed when a navigation button was clicked on the TA grading
     *                  interface.  Knowing who we were viewing allows us to decide who to view next.
     * @param $to Used to determine the direction to move in, either 'prev' or 'next'
     * @param $to_ungraded Should the next student we go to be the next submission or next ungraded submission?
     *
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/grade")
     */
    public function showGrading(
        $gradeable_id,
        $who_id = '',
        $from = "",
        $to = null,
        $gradeable_version = null,
        $sort = "id",
        $direction = "ASC",
        $component_id = "-1",
        $anon_mode = false,
        $filter = 'default'
    ) {
        if (empty($this->core->getQueries()->getTeamsById([$who_id])) && $this->core->getQueries()->getUserById($who_id) == null) {
            $anon_mode = true;
        }
        /** @var Gradeable $gradeable */

        $gradeable = $this->tryGetGradeable($gradeable_id, false);
        if ($gradeable === false) {
            $this->core->addErrorMessage('Invalid Gradeable!');
            $this->core->redirect($this->core->buildCourseUrl());
        }
        $peer = $gradeable->hasPeerComponent() && $this->core->getUser()->getGroup() == User::GROUP_STUDENT;
        $team = $gradeable->isTeamAssignment();
        if ($gradeable->hasPeerComponent() && $this->core->getUser()->getGroup() == User::GROUP_STUDENT) {
            $peer = true;
        }
        $blind_grading = $this->amIBlindGrading($gradeable, $this->core->getUser(), $peer);

        // If $who_id is empty string then this request came from the TA grading interface navigation buttons
        // We must decide who to display prev/next and assign them to $who_id
        $order_all_sections = null;
        if ($who_id === '') {
            $order_grading_sections = new GradingOrder($this->core, $gradeable, $this->core->getUser());
            $order_grading_sections->sort($sort, $direction);

            // Only need to instantiate this order if the user is a full access grader
            // Limited access graders should never need the order that includes all sections
            if ($this->core->getUser()->accessFullGrading()) {
                $order_all_sections = new GradingOrder($this->core, $gradeable, $this->core->getUser(), true);
                $order_all_sections->sort($sort, $direction);
            }

            // Get the graded gradeable for the $from user
            $from_graded_gradeable = false;
            $id_from_anon = $this->core->getQueries()->getSubmitterIdFromAnonId($from);
            if ($blind_grading !== "unblind" || $anon_mode) {
                $from_graded_gradeable = $this->tryGetGradedGradeable($gradeable, $id_from_anon, false);
            }
            else {
                $from_graded_gradeable = $this->tryGetGradedGradeable($gradeable, $from, false);
            }

            if ($from_graded_gradeable === false) {
                $this->core->redirect($this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details']));
            }

            // Get the user ID of the user we were viewing on the TA grading interface
            $from_id = $from_graded_gradeable->getSubmitter();

            // Determine the student to go to based on the button that was pressed
            // For full access graders, pressing the single arrow should navigate to the next submission, regardless
            // of if that submission is in their assigned section
            // Limited access graders should only be able to navigate to submissions in their assigned sections
            if ($to === 'prev' && $this->core->getUser()->accessFullGrading()) {
                $goToStudent = $order_all_sections->getPrevSubmitter($from_id, is_numeric($component_id) ? $component_id : -1, $filter);
            }
            elseif ($to === 'prev') {
                $goToStudent = $order_grading_sections->getPrevSubmitter($from_id, is_numeric($component_id) ? $component_id : -1, $filter);
            }
            elseif ($to === 'next' && $this->core->getUser()->accessFullGrading()) {
                $goToStudent = $order_all_sections->getNextSubmitter($from_id, is_numeric($component_id) ? $component_id : -1, $filter);
            }
            elseif ($to === 'next') {
                $goToStudent = $order_grading_sections->getNextSubmitter($from_id, is_numeric($component_id) ? $component_id : -1, $filter);
            }
            // Reassign who_id
            if (!is_null($goToStudent)) {
                $who_id = $goToStudent->getId();
                if ($blind_grading || $anon_mode) {
                    $who_id = $goToStudent->getAnonId();
                }
            }
            if (is_null($who_id) || $who_id == '') {
                $this->core->redirect($this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details'])  . '?' . http_build_query(['sort' => $sort, 'direction' => $direction, 'view' => 'all']));
            }
        }
        // Get the graded gradeable for the submitter we are requesting
        $graded_gradeable = false;
        $id_from_anon = $this->core->getQueries()->getSubmitterIdFromAnonId($who_id);
        if ($blind_grading !== "unblind" || $anon_mode) {
            $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $id_from_anon, false);
        }
        else {
            $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $who_id, false);
        }
        if ($graded_gradeable === false) {
            $this->core->redirect($this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details'])  . '?' . http_build_query(['sort' => $sort, 'direction' => $direction, 'view' => 'all']));
            $peer = false;
        }

        $gradeableUrl = $this->core->buildCourseUrl(['gradeable', $gradeable->getId(), 'grading', 'status']);
        $this->core->getOutput()->addBreadcrumb("{$gradeable->getTitle()} Grading", $gradeableUrl);
        $indexUrl = $this->core->buildCourseUrl(['gradeable', $gradeable_id, 'grading', 'details']);
        $this->core->getOutput()->addBreadcrumb('Grading Interface ' . GradingOrder::getGradingOrderMessage($sort, $direction));
        $graded = 0;
        $total = 0;
        $total_submitted = 0;
        if ($peer) {
            $section_key = 'registration_section';
            $total = $gradeable->getPeerGradeSet();
            $graded = $this->core->getQueries()->getNumGradedPeerComponents($gradeable->getId(), $this->core->getUser()->getId()) / count($gradeable->getPeerComponents());
        }
        elseif ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
            $sections = $this->core->getUser()->getGradingRegistrationSections();
            if ($this->core->getAccess()->canI("grading.electronic.grade.if_no_sections_exist") && $sections == null) {
                $sections = $this->core->getQueries()->getRegistrationSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_registration_id'];
                }
            }
            if ($team) {
                $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'registration_section', $team));
                $total = array_sum($this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'registration_section'));
                $total_submitted = array_sum($this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'registration_section'));
            }
            else {
                $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'registration_section', $team));
                $total = array_sum($this->core->getQueries()->getTotalUserCountByGradingSections($sections, 'registration_section'));
                $total_submitted = array_sum($this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_id, $sections, 'registration_section'));
            }
        }
        else {
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            if ($this->core->getAccess()->canI("grading.electronic.grade.if_no_sections_exist") && $sections == null) {
                $sections = $this->core->getQueries()->getRotatingSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_rotating_id'];
                }
            }
            if ($team) {
                //$total = array_sum($this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section'));
                $total_submitted = array_sum($this->core->getQueries()->getSubmittedTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section'));
            }
            else {
                $total_submitted = array_sum($this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable->getId(), $sections, 'rotating_section'));
            }
            $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_id, $sections, 'rotating_section', $team));
        }
        //multiplies users and the number of components a gradeable has together
        if ($team) {
            $total_submitted = ($total_submitted * count($gradeable->getComponents()));
        }
        else {
            $total_submitted = ($total_submitted * count($gradeable->getComponents()));
        }
        if ($total_submitted == 0) {
            $progress = 100;
        }
        else {
            $progress = round(($graded / $total_submitted) * 100, 1);
        }


        if (!$this->core->getAccess()->canI("grading.electronic.grade", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable])) {
            $this->core->addErrorMessage("ERROR: You do not have access to grade the requested student.");
            $this->core->redirect($this->core->buildCourseUrl(['gradeable', $gradeable->getId(), 'grading', 'status']));
        }

        $show_verify_all = false;
        //check if verify all button should be shown or not
        foreach ($gradeable->getComponents() as $component) {
            $graded_component = $graded_gradeable->getOrCreateTaGradedGradeable()->getGradedComponent($component, $this->core->getUser());
            if ($graded_component === null) {
                continue;
            }
            if ($graded_component->getGrader()->getId() !== $this->core->getUser()->getId() && $graded_component->getVerifierId() === '') {
                $show_verify_all = true;
                break;
            }
        }
        $can_inquiry = $this->core->getAccess()->canI("grading.electronic.grade_inquiry", ['graded_gradeable' => $graded_gradeable]);
        $can_verify = $this->core->getAccess()->canI("grading.electronic.verify_grader");
        $show_verify_all = $show_verify_all && $can_verify;

        $show_silent_edit = $this->core->getAccess()->canI("grading.electronic.silent_edit");

        $display_version = intval($gradeable_version ?? '0');
        if ($display_version <= 0) {
            $display_version = $graded_gradeable->getAutoGradedGradeable()->getActiveVersion();
        }

        $late_days_user = null;
        if ($gradeable->isTeamAssignment()) {
            // If its a team assignment, use the leader for late days...
            $late_days_user = $this->core->getQueries()->getUserById($graded_gradeable->getSubmitter()->getTeam()->getLeaderId());
        }
        else {
            $late_days_user = $graded_gradeable->getSubmitter()->getUser();
        }

        $ldi = LateDays::fromUser($this->core, $late_days_user)->getLateDayInfoByGradeable($gradeable);
        if ($ldi === null) {
            $late_status = LateDayInfo::STATUS_GOOD;  // Assume its good
        }
        else {
            $late_status = $ldi->getStatus();
        }
        $rollbackSubmission = -1;
        $previousVersion =  $graded_gradeable->getAutoGradedGradeable()->getActiveVersion() - 1;
        // check for rollback submission only if the Active version is greater than 1 and that too is late.
        if ($previousVersion && $late_status !== LateDayInfo::STATUS_GOOD) {
            while ($previousVersion) {
                $prevVersionInstance = $graded_gradeable->getAutoGradedGradeable()->getAutoGradedVersionInstance($previousVersion);
                if ($prevVersionInstance == null) {
                    $rollbackSubmission = -1;
                    break;
                }
                $lateInfo = LateDays::fromUser($this->core, $late_days_user)->getLateDayInfoByGradeable($gradeable);
                $daysLate = $prevVersionInstance->getDaysLate();

                // If this version is a good submission then it the rollback Submision
                if ($lateInfo == null || ($lateInfo->getStatus($daysLate) == LateDayInfo::STATUS_GOOD)) {
                    $rollbackSubmission = $previousVersion;
                    break;
                }
                // applying same condition for previous version. i.e going back one version
                $previousVersion -= 1;
            }
        }

        $logger_params = [
            "course_semester" => $this->core->getConfig()->getSemester(),
            "course_name" => $this->core->getDisplayedCourseName(),
            "gradeable_id" => $gradeable_id,
            "grader_id" => $this->core->getUser()->getId(),
            "submitter_id" => $who_id,
            "action" => "VIEW_PAGE",
        ];
        Logger::logTAGrading($logger_params);
        $anon_mode = isset($_COOKIE['anon_mode']) && $_COOKIE['anon_mode'] === 'on';
        $submitter_itempool_map = $this->getItempoolMapForSubmitter($gradeable, $graded_gradeable->getSubmitter()->getId());
        $solution_ta_notes = $this->getSolutionTaNotesForGradeable($gradeable, $submitter_itempool_map) ?? [];

        $this->core->getOutput()->addInternalCss('forum.css');
        $this->core->getOutput()->addInternalCss('electronic.css');

        $this->core->getOutput()->addInternalJs('forum.js');
        $this->core->getOutput()->addInternalCss('grade-inquiry.css');
        $this->core->getOutput()->addInternalJs('grade-inquiry.js');
        $this->core->getOutput()->addInternalJs('websocket.js');
        $show_hidden = $this->core->getAccess()->canI("autograding.show_hidden_cases", ["gradeable" => $gradeable]);
        $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'hwGradingPage', $gradeable, $graded_gradeable, $display_version, $progress, $show_hidden, $can_inquiry, $can_verify, $show_verify_all, $show_silent_edit, $late_status, $rollbackSubmission, $sort, $direction, $who_id, $solution_ta_notes, $submitter_itempool_map, $anon_mode, $blind_grading);
        $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'popupStudents');
        $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'popupMarkConflicts');
        $this->core->getOutput()->renderOutput(['grading', 'ElectronicGrader'], 'popupSettings');
    }

    /**
     * Route for fetching a gradeable's rubric information
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/rubric", methods={"GET"})
     */
    public function ajaxGetGradeableRubric($gradeable_id) {
        $grader = $this->core->getUser();
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.grade", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to get gradeable rubric data');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $results = $this->getGradeableRubric($gradeable, $grader);
            $this->core->getOutput()->renderJsonSuccess($results);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function getGradeableRubric(Gradeable $gradeable, User $grader) {
        $return = [
            'id' => $gradeable->getId(),
            'precision' => $gradeable->getPrecision()
        ];
        // Filter out the components that we shouldn't see
        //  TODO: instructors see all components, some may not be visible in non-super-edit-mode
        $return['components'] = array_map(function (Component $component) {
                return $component->toArray();
        }, array_filter($gradeable->getComponents(), function (Component $component) use ($gradeable) {
                return $this->core->getAccess()->canI('grading.electronic.view_component', ['gradeable' => $gradeable, 'component' => $component]);
        }));
        $return['components'] = array_values($return['components']);
        return $return;
    }

    /**
     * Gets a component and all of its marks
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components")
     */
    public function ajaxGetComponent($gradeable_id, $component_id) {
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $this->core->getUser()->getId(), false);

        // Get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.view_component", ["gradeable" => $gradeable, "component" => $component])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to get component');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->core->getOutput()->renderJsonSuccess($component->toArray());
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for getting information about a individual grader
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/graded_gradeable")
     */
    public function ajaxGetGradedGradeable($gradeable_id, $anon_id = '', $all_peers = false) {
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $all_peers = ($all_peers === "true");

        $grader = $this->core->getUser();

        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }

        $section = null;

        if ($gradeable->isGradeByRegistration()) {
            $section = $this->core->getQueries()->getSubmitterById($submitter_id)->getRegistrationSection();
        }
        else {
            $section = $this->core->getQueries()->getSubmitterById($submitter_id)->getRotatingSection();
        }

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.grade", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable, "section" => $section])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to get graded gradeable');
            return;
        }

        // Check if user has permission to view all peer grades
        /*if ($all_peers) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to get view peer panel');
            return;
        }*/

        // Get / create the TA grade
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $response_data = null;
            if ($ta_graded_gradeable !== null) {
                $response_data = $this->getGradedGradeable($ta_graded_gradeable, $grader, $all_peers);
            }
            $this->core->getOutput()->renderJsonSuccess($response_data);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function getGradedGradeable(TaGradedGradeable $ta_graded_gradeable, User $grader, $all_peers = false) {

        // Passing null returns grading for all graders.
        $grading_done_by = ($all_peers ? null : $grader);
        $response_data = $ta_graded_gradeable->toArray($grading_done_by);
        $response_data_with_peer = $ta_graded_gradeable->toArray();
        $graded_gradeable = $ta_graded_gradeable->getGradedGradeable();
        $gradeable = $graded_gradeable->getGradeable();
        $submitter = $graded_gradeable->getSubmitter()->getId();
        $combined_score = 0;
        foreach ($response_data_with_peer['peer_scores'] as $score) {
            $combined_score += $score;
        }
        //remove non peer component scores from combined score to get the combined peer score
        $combined_peer_score = $combined_score - $ta_graded_gradeable->getTotalTaScore($this->core->getUser());

        // If there is autograding, also send that information TODO: this should be restricted to non-peer
        if (count($gradeable->getAutogradingConfig()->getPersonalizedTestcases($submitter)) > 1) {
            // NOTE/REDESIGN FIXME: We might have autograding that is
            // penalty only.  The available positive autograding
            // points might be zero.  Testing for autograding > 1 is
            // ignoring the submission limit test case... but this is
            // also imperfect.  We want to render the column if any
            // student has received the penalty.  But if no one has
            // received the penalty maybe we omit it?  (expensive?/confusing?)
            // See also note in ElectronicGraderView.php
            // if ($gradeable->getAutogradingConfig()->anyPoints()) {
            $response_data['auto_grading_total'] = $gradeable->getAutogradingConfig()->getTotalNonExtraCredit();

            // Furthermore, if the user has a grade, send that information
            if ($graded_gradeable->getAutoGradedGradeable()->hasActiveVersion()) {
                $response_data['auto_grading_earned'] = $graded_gradeable->getAutoGradedGradeable()->getActiveVersionInstance()->getTotalPoints();
            }
        }
        //send ta score information
        $response_data['ta_grading_total'] = $gradeable->getTaPoints();
        if ($gradeable->hasPeerComponent()) {
            $response_data['ta_grading_earned'] = $ta_graded_gradeable->getTotalTaScore($this->core->getUser());
            $response_data['see_peer_grade'] = $ta_graded_gradeable->getTotalPeerScore($grading_done_by);
            $response_data['peer_grade_earned'] = $ta_graded_gradeable->getTotalScore($this->core->getUser());
            $response_data['peer_total'] = $gradeable->getPeerPoints();
            $response_data['user_group'] = $this->core->getUser()->getGroup();
            $response_data['peer_gradeable'] = true;
            $response_data['combined_peer_score'] = $combined_peer_score;
        }
        else {
            $response_data['ta_grading_earned'] = $ta_graded_gradeable->getTotalScore(null);
            $response_data['user_group'] = $this->core->getUser()->getGroup();
            $response_data['peer_gradeable'] = false;
        }

        $response_data['anon_id'] = $graded_gradeable->getSubmitter()->getAnonId();

        $response_data['itempool_items'] = [];
        $components = $gradeable->getComponents();
        $submitter_itempool_map = $this->getItempoolMapForSubmitter($gradeable, $submitter);
        foreach ($components as $key => $value) {
            $response_data['itempool_items'][$value->getId()] = $value->getItempool() === '' ? '' : $submitter_itempool_map[$value->getItempool()];
        }

        return $response_data;
    }

    /**
     * Route for saving the marks the submitter received for a component
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/graded_gradeable/graded_component", methods={"POST"})
     */
    public function ajaxSaveGradedComponent($gradeable_id) {
        $anon_id = $_POST['anon_id'] ?? '';
        $component_id = $_POST['component_id'] ?? '';
        $custom_message = $_POST['custom_message'] ?? null;
        $custom_points = $_POST['custom_points'] ?? null;
        $component_version = $_POST['graded_version'] ?? null;
        // Optional marks parameter
        $marks = $_POST['mark_ids'] ?? [];

        // Validate required parameters
        if ($custom_message === null) {
            $this->core->getOutput()->renderJsonFail('Missing custom_message parameter');
            return;
        }
        if ($custom_points === null) {
            $this->core->getOutput()->renderJsonFail('Missing custom_points parameter');
            return;
        }
        if (!is_numeric($custom_points)) {
            $this->core->getOutput()->renderJsonFail('Invalid custom_points parameter');
            return;
        }
        if ($component_version === null) {
            $this->core->getOutput()->renderJsonFail('Missing graded_version parameter');
            return;
        }
        if (!ctype_digit($component_version)) {
            $this->core->getOutput()->renderJsonFail('Invalid graded_version parameter');
            return;
        }

        // Convert the mark ids to integers
        $numeric_mark_ids = [];
        foreach ($marks as $mark) {
            if (!ctype_digit($mark)) {
                $this->core->getOutput()->renderJsonFail('One of provided mark ids was invalid');
                return;
            }
            $numeric_mark_ids[] = intval($mark);
        }
        $marks = $numeric_mark_ids;

        // Parse the strings into ints/floats
        $component_version = intval($component_version);
        $custom_points = floatval($custom_points);

        // Optional Parameters
        $silent_edit = ($_POST['silent_edit'] ?? 'false') === 'true';

        $grader = $this->core->getUser();

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }
        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }


        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_graded_component", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable, "component" => $component])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save component/marks');
            return;
        }

        //don't allow custom marks if they are disabled
        if ($custom_message != null || $custom_points != null) {
            if (!$gradeable->getAllowCustomMarks()) {
                $this->core->getOutput()->renderJsonFail('Custom marks are disabled for this assignment');
                return;
            }
        }

        //don't allow peer graders to save custom marks no matter how gradeable is configured
        if (($custom_message != null || $custom_points != null) && $gradeable->hasPeerComponent()) {
            if ($this->core->getUser()->getGroup() == User::GROUP_STUDENT) {
                $this->core->getOutput()->renderJsonFail('Insufficient permissions to save component/marks');
                return;
            }
        }
        // Check if the user can silently edit assigned marks
        if (!$this->core->getAccess()->canI('grading.electronic.silent_edit')) {
            $silent_edit = false;
        }

        $logger_params = [
            "course_semester" => $this->core->getConfig()->getSemester(),
            "course_name" => $this->core->getDisplayedCourseName(),
            "gradeable_id" => $gradeable_id,
            "grader_id" => $this->core->getUser()->getId(),
            "component_id" => $component_id,
            "action" => "SAVE_COMPONENT",
            "submitter_id" => $submitter_id
        ];
        Logger::logTAGrading($logger_params);

        // Get / create the TA grade
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        // Get / create the graded component
        $graded_component = $ta_graded_gradeable->getOrCreateGradedComponent($component, $grader, true);

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->saveGradedComponent(
                $ta_graded_gradeable,
                $graded_component,
                $grader,
                $custom_points,
                $custom_message,
                $marks,
                $component_version,
                !$silent_edit
            );
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveGradedComponent(TaGradedGradeable $ta_graded_gradeable, GradedComponent $graded_component, User $grader, float $custom_points, string $custom_message, array $mark_ids, int $component_version, bool $overwrite) {
        // Only update the grader if we're set to overwrite it
        if ($overwrite) {
            $graded_component->setGrader($grader);
        }
        $version_updated = $graded_component->getGradedVersion() !== $component_version;
        if ($version_updated) {
            $graded_component->setGradedVersion($component_version);
        }
        $graded_component->setComment($custom_message);
        $graded_component->setScore($custom_points);
        $graded_component->setGradeTime($this->core->getDateTimeNow());

        // Set the marks the submitter received
        $graded_component->setMarkIds($mark_ids);

        // Check if this graded component should be deleted
        if (
            count($graded_component->getMarkIds()) === 0
            && $graded_component->getScore() === 0.0
            && $graded_component->getComment() === ''
        ) {
            $ta_graded_gradeable->deleteGradedComponent($graded_component->getComponent(), $graded_component->getGrader());
            $graded_component = null;
        }
        else {
            //change the component to be unverified after changing a mark
            if ($graded_component->isMarksModified()) {
                $graded_component->setVerifier();
                $graded_component->setVerifyTime(null);
            }
        }

        // TODO: is this desirable
        // Reset the user viewed date since we updated the grade
        $ta_graded_gradeable->resetUserViewedDate();

        // Finally, save the changes to the database
        $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);
        $submitter = $ta_graded_gradeable->getGradedGradeable()->getSubmitter();
        if ($submitter->isTeam()) {
            $this->core->getQueries()->clearTeamViewedTime($submitter->getId());
        }
    }

    /**
     * Route for saving a component's properties (not its marks)
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/save", methods={"POST"})
     */
    public function ajaxSaveComponent($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $title = $_POST['title'] ?? '';
        $ta_comment = $_POST['ta_comment'] ?? '';
        $student_comment = $_POST['student_comment'] ?? '';
        $lower_clamp = $_POST['lower_clamp'] ?? null;
        $default = $_POST['default'] ?? null;
        $max_value = $_POST['max_value'] ?? null;
        $upper_clamp = $_POST['upper_clamp'] ?? null;
        $is_itempool_linked = $_POST['is_itempool_linked'] ?? false;
        $itempool_option = $_POST['itempool_option'] ?? null;

        // Use 'page_number' since 'page' is used in the router
        $page = $_POST['page_number'] ?? '';

        // Validate required parameters
        if ($lower_clamp === null) {
            $this->core->getOutput()->renderJsonFail('Missing lower_clamp parameter');
            return;
        }
        if ($default === null) {
            $this->core->getOutput()->renderJsonFail('Missing default parameter');
            return;
        }
        if ($max_value === null) {
            $this->core->getOutput()->renderJsonFail('Missing max_value parameter');
            return;
        }
        if ($upper_clamp === null) {
            $this->core->getOutput()->renderJsonFail('Missing upper_clamp parameter');
            return;
        }
        if ($page === '') {
            $this->core->getOutput()->renderJsonFail('Missing page parameter');
        }
        if (!is_numeric($lower_clamp)) {
            $this->core->getOutput()->renderJsonFail('Invalid lower_clamp parameter');
            return;
        }
        if (!is_numeric($default)) {
            $this->core->getOutput()->renderJsonFail('Invalid default parameter');
            return;
        }
        if (!is_numeric($max_value)) {
            $this->core->getOutput()->renderJsonFail('Invalid max_value parameter');
            return;
        }
        if (!is_numeric($upper_clamp)) {
            $this->core->getOutput()->renderJsonFail('Invalid upper_clamp parameter');
            return;
        }
        if (strval(intval($page)) !== $page) {
            $this->core->getOutput()->renderJsonFail('Invalid page parameter');
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_component", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save components');
            return;
        }

        $is_notebook_gradeable = ($gradeable->getAutogradingConfig() !== null) && $gradeable->getAutogradingConfig()->isNotebookGradeable();

        if ($is_notebook_gradeable) {
            if ($is_itempool_linked === 'true') {
                if (!$itempool_option) {
                    $this->core->getOutput()->renderJsonFail('Missing itempool_option parameter');
                    return;
                }
            }
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $component->setTitle($title);
            $component->setTaComment($ta_comment);
            $component->setStudentComment($student_comment);
            $component->setPoints([
                'lower_clamp' => $lower_clamp,
                'default' => $default,
                'max_value' => $max_value,
                'upper_clamp' => $upper_clamp
                ]);
            $component->setPage($page);
            if ($is_notebook_gradeable) {
                if ($is_itempool_linked === 'true') {
                    $component->setIsItempoolLinked(true);
                    $component->setItempool($itempool_option);
                }
                else {
                    $component->setIsItempoolLinked(false);
                    $component->setItempool('');
                }
            }

            $this->core->getQueries()->saveComponent($component);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for saving the order of components in a gradeable
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/order", methods={"POST"})
     */
    public function ajaxSaveComponentOrder($gradeable_id) {
        // Required parameters
        $order = json_decode($_POST['order'] ?? '[]', true);

        // Validate required parameters
        if (count($order) === 0) {
            $this->core->getOutput()->renderJsonFail('Missing order parameter');
            return;
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_component", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save marks');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->saveComponentOrder($gradeable, $order);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveComponentOrder(Gradeable $gradeable, array $orders) {
        foreach ($gradeable->getComponents() as $component) {
            if (!isset($orders[$component->getId()])) {
                throw new \InvalidArgumentException('Missing component id in order array');
            }
            $order = $orders[$component->getId()];
            if (!is_int($order) || $order < 0) {
                throw new \InvalidArgumentException('All order values must be non-negative integers');
            }
            $component->setOrder(intval($order));
        }
        $this->core->getQueries()->updateGradeable($gradeable);
    }

    /**
     * Route for saving the page numbers of the components
     * NOTE: the 'pages' parameter can be an associate array to set the page numbers of each component,
     *  or a single-element array with the key 'page' of the page number to set all components' page to
     *
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/save_pages", methods={"POST"})
     */
    public function ajaxSaveComponentPages($gradeable_id) {
        // Required parameters
        $pages = json_decode($_POST['pages'] ?? '[]', true);

        // Validate required parameters
        if (count($pages) === 0) {
            $this->core->getOutput()->renderJsonFail('Missing pages parameter');
            return;
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_component", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save marks');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            if (isset($pages['page']) && count($pages) === 1) {
                // if one page is sent, set all to that page.  This is useful
                //  for setting the page settings to 'none' or 'student-assign'
                $this->saveComponentsPage($gradeable, $pages['page']);
            }
            else {
                $this->saveComponentPages($gradeable, $pages);
            }
            $this->core->getQueries()->updateGradeable($gradeable);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveComponentPages(Gradeable $gradeable, array $pages) {
        foreach ($gradeable->getComponents() as $component) {
            if (!isset($pages[$component->getId()])) {
                throw new \InvalidArgumentException('Missing component id in pages array');
            }
            $page = $pages[$component->getId()];
            if (!is_int($page)) {
                throw new \InvalidArgumentException('All page values must be integers');
            }
            $component->setPage(max(intval($page), -1));
        }
    }

    public function saveComponentsPage(Gradeable $gradeable, int $page) {
        foreach ($gradeable->getComponents() as $component) {
            $component->setPage(max($page, -1));
        }
    }

    /**
     * Route for adding a new component to a gradeable
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/new", methods={"POST"})
     */
    public function ajaxAddComponent($gradeable_id) {
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        $peer = $_POST['peer'] === 'true';

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.add_component", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to add components');
            return;
        }

        try {
            $page = $gradeable->isPdfUpload() ? ($gradeable->isStudentPdfUpload() ? Component::PDF_PAGE_STUDENT : 1) : Component::PDF_PAGE_NONE;

            // Once we've parsed the inputs and checked permissions, perform the operation
            $component = $gradeable->addComponent(
                'Problem ' . strval(count($gradeable->getComponents()) + 1),
                '',
                '',
                0,
                0,
                0,
                0,
                false,
                $peer,
                $page
            );
            $component->addMark('No Credit', 0.0, false);
            $this->core->getQueries()->updateGradeable($gradeable);
            $this->core->getOutput()->renderJsonSuccess(['component_id' => $component->getId()]);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for deleting a component from a gradeable
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/delete", methods={"POST"})
     */
    public function ajaxDeleteComponent($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.delete_component", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to delete components');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $gradeable->deleteComponent($component);
            $this->core->getQueries()->updateGradeable($gradeable);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for saving a mark's title/point value
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/marks/save", methods={"POST"})
     */
    public function ajaxSaveMark($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $mark_id = $_POST['mark_id'] ?? '';
        $points = $_POST['points'] ?? '';
        $title = $_POST['title'] ?? null;
        $publish = ($_POST['publish'] ?? 'false') === 'true';

        // Validate required parameters
        if ($title === null) {
            $this->core->getOutput()->renderJsonFail('Missing title parameter');
            return;
        }
        if ($points === null) {
            $this->core->getOutput()->renderJsonFail('Missing points parameter');
            return;
        }
        if (!is_numeric($points)) {
            $this->core->getOutput()->renderJsonFail('Invalid points parameter');
            return;
        }

        $points = floatval($points);

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // get the mark
        $mark = $this->tryGetMark($component, $mark_id);
        if ($mark === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_mark", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save marks');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->saveMark($mark, $points, $title, $publish);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveMark(Mark $mark, float $points, string $title, bool $publish) {
        if ($mark->getPoints() !== $points) {
            $mark->setPoints($points);
        }
        if ($mark->getTitle() !== $title) {
            $mark->setTitle($title);
        }
        if ($mark->isPublish() !== $publish) {
            $mark->setPublish($publish);
        }
        $this->core->getQueries()->updateGradeable($mark->getComponent()->getGradeable());
    }

    /**
     * Route for saving a the order of marks in a component
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/marks/save_order", methods={"POST"})
     */
    public function ajaxSaveMarkOrder($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $order = json_decode($_POST['order'] ?? '[]', true);

        // Validate required parameters
        if (count($order) === 0) {
            $this->core->getOutput()->renderJsonFail('Missing order parameter');
            return;
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.save_mark", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save marks');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->saveMarkOrder($component, $order);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveMarkOrder(Component $component, array $orders) {
        foreach ($component->getMarks() as $mark) {
            if (!isset($orders[$mark->getId()])) {
                throw new \InvalidArgumentException('Missing mark id in order array');
            }
            $order = $orders[$mark->getId()];
            if (!is_int($order) || $order < 0) {
                throw new \InvalidArgumentException('All order values must be non-negative integers');
            }
            $mark->setOrder(intval($order));
        }
        $this->core->getQueries()->saveComponent($component);
    }

    /**
     * Route for getting the student's program output for the diff-viewer
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/student_output")
     */
    public function ajaxGetStudentOutput($gradeable_id, $who_id = '', $version = '', $index = '') {
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // Get the graded gradeable
        $who_id = $this->core->getQueries()->getSubmitterIdFromAnonId($who_id);
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $who_id);
        if ($graded_gradeable === false) {
            return;
        }

        // get the requested version
        $version_instance = $this->tryGetVersion($graded_gradeable->getAutoGradedGradeable(), $version);
        if ($version_instance === false) {
            return;
        }

        // Get the requested testcase
        $testcase = $this->tryGetTestcase($version_instance, $index);
        if ($testcase === false) {
            return;
        }

        // Check access
        if (!$this->core->getAccess()->canI("autograding.load_checks", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable])) {
            // TODO: streamline permission error strings
            $this->core->getOutput()->renderJsonFail('You have insufficient permissions to access this command');
            return;
        }

        try {
            //display hidden testcases only if the user can view the entirety of this gradeable.
            $can_view_hidden = $this->core->getAccess()->canI("autograding.show_hidden_cases", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable]);
            $popup_css = "diff-viewer.css";
            $this->core->getOutput()->renderJsonSuccess(
                $this->core->getOutput()->renderTemplate(
                    'AutoGrading',
                    'loadAutoChecks',
                    $graded_gradeable,
                    $version_instance,
                    $testcase,
                    $popup_css,
                    $who_id,
                    $can_view_hidden
                )
            );
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for adding a mark to a component
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/marks/add", methods={"POST"})
     */
    public function ajaxAddNewMark($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $points = $_POST['points'] ?? '';
        $title = $_POST['title'] ?? null;
        $publish = ($_POST['publish'] ?? 'false') === 'true';

        // Validate required parameters
        if ($title === null) {
            $this->core->getOutput()->renderJsonFail('Missing title parameter');
            return;
        }
        if ($points === null) {
            $this->core->getOutput()->renderJsonFail('Missing points parameter');
            return;
        }
        if (!is_numeric($points)) {
            $this->core->getOutput()->renderJsonFail('Invalid points parameter');
            return;
        }

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.add_new_mark", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to add mark');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $mark = $this->addNewMark($component, $title, $points, $publish);
            $this->core->getOutput()->renderJsonSuccess(['mark_id' => $mark->getId()]);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function addNewMark(Component $component, string $title, float $points, bool $publish) {
        $mark = $component->addMark($title, $points, $publish);
        $this->core->getQueries()->saveComponent($component);
        return $mark;
    }

    /**
     * Route for deleting a mark from a component
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/marks/delete", methods={"POST"})
     */
    public function ajaxDeleteMark($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $mark_id = $_POST['mark_id'] ?? '';

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // get the mark
        $mark = $this->tryGetMark($component, $mark_id);
        if ($mark === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.delete_mark", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to delete marks');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->deleteMark($mark);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function deleteMark(Mark $mark) {
        $mark->getComponent()->deleteMark($mark);
        $this->core->getQueries()->saveComponent($mark->getComponent());
    }

    /**
     * Route for saving the general comment for the gradeable
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/comments", methods={"POST"})
     */
    public function ajaxSaveOverallComment($gradeable_id) {
        $anon_id = $_POST['anon_id'] ?? '';
        $comment = $_POST['overall_comment'] ?? '';
        $grader = $this->core->getUser();

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        // Check access
        if (!$this->core->getAccess()->canI("grading.electronic.save_general_comment", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save component general comment');
            return;
        }

        // Get the Ta graded gradeable
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $this->saveOverallComment($ta_graded_gradeable, $comment, $grader);
            $this->core->getOutput()->renderJsonSuccess();
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    public function saveOverallComment(TaGradedGradeable $ta_graded_gradeable, string $comment, User $grader) {
        // Set the comment
        $ta_graded_gradeable->setOverallComment($comment, $grader->getId());

        // New info, so reset the user viewed date
        $ta_graded_gradeable->resetUserViewedDate();

        // Finally, save the graded gradeable
        $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);
        $submitter = $ta_graded_gradeable->getGradedGradeable()->getSubmitter();
        if ($submitter->isTeam()) {
            $this->core->getQueries()->clearTeamViewedTime($submitter->getId());
        }
    }

    /**
     * Route for getting a GradedComponent
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/graded_gradeable/graded_component", methods={"GET"})
     */
    public function ajaxGetGradedComponent($gradeable_id, $anon_id = '', $component_id = '') {
        $grader = $this->core->getUser();

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.view_component_grade", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable, "component" => $component])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to get component data');
            return;
        }

        // Get / create the TA grade
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        // Get / create the graded component
        $graded_component = $ta_graded_gradeable->getGradedComponent($component, $grader);

        $logger_params = [
            "course_semester" => $this->core->getConfig()->getSemester(),
            "course_name" => $this->core->getDisplayedCourseName(),
            "gradeable_id" => $gradeable_id,
            "grader_id" => $this->core->getUser()->getId(),
            "component_id" => $component_id,
            "action" => "OPEN_COMPONENT",
            "submitter_id" => $submitter_id
        ];
        Logger::logTAGrading($logger_params);


        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $response_data = null;
            if ($graded_component !== null) {
                $response_data = $graded_component->toArray();
            }
            $this->core->getOutput()->renderJsonSuccess($response_data);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    /**
     * Route for getting the overall comment for the currently logged in user.
     * @param string $gradeable_id
     * @param string $anon_id, the anonymous id of the student
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/comments", methods={"GET"})
     */
    public function ajaxGetOverallComment($gradeable_id, $anon_id = '') {
        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }
        // Get user id from the anon id
        $submitter_id = $this->tryGetSubmitterIdFromAnonId($anon_id);
        if ($submitter_id === false) {
            return;
        }
        $commenter = $this->core->getUser();

        // Get the graded gradeable
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.get_gradeable_comment", ["gradeable" => $gradeable, "graded_gradeable" => $graded_gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to save gradeable comment');
            return;
        }

        // Get / create the TA grade
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        // Once we've parsed the inputs and checked permissions, perform the operation
        $this->core->getOutput()->renderJsonSuccess($ta_graded_gradeable->getOverallComments($commenter));
    }

    /**
     * Route for getting all submitters that received a mark and stats about that mark
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/components/marks/stats", methods={"POST"})
     */
    public function ajaxGetMarkStats($gradeable_id) {
        // Required parameters
        $component_id = $_POST['component_id'] ?? '';
        $mark_id = $_POST['mark_id'] ?? '';

        $grader = $this->core->getUser();

        // Get the gradeable
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return;
        }

        // get the component
        $component = $this->tryGetComponent($gradeable, $component_id);
        if ($component === false) {
            return;
        }

        // get the mark
        $mark = $this->tryGetMark($component, $mark_id);
        if ($mark === false) {
            return;
        }

        // checks if user has permission
        if (!$this->core->getAccess()->canI("grading.electronic.get_marked_users", ["gradeable" => $gradeable])) {
            $this->core->getOutput()->renderJsonFail('Insufficient permissions to view marked users');
            return;
        }

        try {
            // Once we've parsed the inputs and checked permissions, perform the operation
            $results = $this->getMarkStats($mark, $grader);
            $this->core->getOutput()->renderJsonSuccess($results);
        }
        catch (\InvalidArgumentException $e) {
            $this->core->getOutput()->renderJsonFail($e->getMessage());
        }
        catch (\Exception $e) {
            $this->core->getOutput()->renderJsonError($e->getMessage());
        }
    }

    private function getMarkStats(Mark $mark, User $grader) {
        $gradeable = $mark->getComponent()->getGradeable();

        $anon = $this->amIBlindGrading($gradeable, $grader, false);

        $section_submitter_ids = $this->core->getQueries()->getSubmittersWhoGotMarkBySection($mark, $grader, $gradeable, $anon);
        $all_submitter_ids     = $this->core->getQueries()->getAllSubmittersWhoGotMark($mark, $anon);

        // Show all submitters if grader has permissions, otherwise just section submitters
        $submitter_ids = ($grader->accessFullGrading() ? $all_submitter_ids : $section_submitter_ids);

        $section_graded_component_count = 0;
        $section_total_component_count  = 0;
        $total_graded_component_count   = 0;
        $total_total_component_count    = 0;

        $this->getStats($gradeable, $grader, true, $total_graded_component_count, $total_total_component_count);
        $this->getStats($gradeable, $grader, false, $section_graded_component_count, $section_total_component_count);

        return [
            'section_submitter_count' => count($section_submitter_ids),
            'total_submitter_count'   => count($all_submitter_ids),
            'section_graded_component_count' => $section_graded_component_count,
            'total_graded_component_count'   => $total_graded_component_count,
            'section_total_component_count' => $section_total_component_count,
            'total_total_component_count'   => $total_total_component_count,
            'submitter_ids' => $submitter_ids
        ];
    }

    /**
     * Gets... stats
     * @param Gradeable $gradeable
     * @param User      $grader
     * @param bool      $full_sets
     */
    private function getStats(Gradeable $gradeable, User $grader, bool $full_stats, &$total_graded, &$total_total) {
        $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable->getId());
        $sections = [];
        if ($full_stats) {
            $sections = $this->core->getQueries()->getAllSectionsForGradeable($gradeable);
        }
        elseif ($gradeable->isGradeByRegistration()) {
            $sections = $grader->getGradingRegistrationSections();
        }
        else {
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(), $grader->getId());
        }

        $section_key = ($gradeable->isGradeByRegistration() ? 'registration_section' : 'rotating_section');

        $total_users       = [];
        $graded_components = [];
        $ta_graded_components = [];
        if (count($sections) > 0) {
            $total_users = ($gradeable->isTeamAssignment()) ?
                $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable->getId(), $sections, $section_key) :
                $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
            $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable->getId(), $sections, $section_key, $gradeable->isTeamAssignment());
        }

        foreach ($graded_components as $key => $value) {
            $total_graded += intval($value);
        }
        foreach ($total_users as $key => $value) {
            $total_total += $value * $num_components;
        }
    }

    /**
     * @param Gradeable $gradeable
     * @param string $who_id
     * @return array
     */
    protected function getItempoolMapForSubmitter($gradeable, $who_id) {
        $user_item_map = [];
        // read config file
        $gradeable_config = $gradeable->getAutogradingConfig();

        $notebook_config = $gradeable_config->getNotebookConfig();
        $hashes = $gradeable_config->getUserSpecificNotebook($who_id)->getHashes();
        $que_idx = 0;
        // loop through the notebook key, and find from_pool key in each object (or question)
        foreach ($notebook_config as $key => $item) {
            // store those question which are having count(from_pool array) > 1
            if (isset($item['type']) && $item['type'] === 'item') {
                $item_id = !empty($item['item_label']) ? $item["item_label"] : "item";
                $item_id = isset($user_item_map[$item_id]) ? $item_id . '_' . $key : $item_id;
                $selected_idx = $item["user_item_map"][$who_id] ?? null;
                if (is_null($selected_idx)) {
                    $selected_idx = $hashes[$que_idx] % count($item['from_pool']);
                    $que_idx++;
                }
                $user_item_map[$item_id] = $item['from_pool'][$selected_idx];
            }
        }
        return $user_item_map;
    }

    /**
     * @param Gradeable $gradeable
     * @param array $submitter_itempool_map
     * @return array
     */
    public function getSolutionTaNotesForGradeable($gradeable, $submitter_itempool_map) {
        $solutions = [];
        try {
            $result_rows = $this->core->getQueries()->getSolutionForAllComponentIds($gradeable->getId());

            foreach ($result_rows as $row) {
                foreach ($row as $values) {
                    // itempool_name === '' indicates that the component is not linked with the itempool
                    if (empty($values['itempool_name']) || $submitter_itempool_map[$values['itempool_name']] === $values['itempool_item']) {
                        $solutions[$values['component_id']] = $values;
                        break;
                    }
                }
            }
        }
        catch (\Exception $exception) {
            $error = $exception->getMessage();
            $this->core->getOutput()->renderResultMessage("Something went wrong while fetching solutions " . $error, false);
        }
        return $solutions;
    }

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/solution_ta_notes", methods={"POST"})
     * @return JsonResponse
     */
    public function updateSolutionTaNotes($gradeable_id) {
        $solution_text = $_POST['solution_text'] ?? '';
        $component_id = $_POST['component_id'];
        $gradeable = $this->tryGetGradeable($gradeable_id);
        $author_id = $this->core->getUser()->getId();
        $itempool_item = $_POST['itempool_item'] ?? '';
        $error = "";
        $solution_row = [];
        $componentItempoolInfo = $this->core->getQueries()->componentItempoolInfo($gradeable_id, $component_id);
        if (!$gradeable) {
            $error = "Invalid Gradeable ID given!";
        }
        elseif (empty($solution_text)) {
            $error = "Please provide some non-empty solution";
        }
        elseif ($componentItempoolInfo['is_linked'] && empty($itempool_item)) {
            //Itempool must be non-empty when component is linked with the itempool
            $error = 'This component expects only non-empty itempool-item!';
        }
        elseif (!$componentItempoolInfo['is_linked'] && !empty($itempool_item)) {
            // Itempool item passed when the component is not linked with itempool
            $error = 'This Component expects only non-empty itempool-item!' . json_encode($componentItempoolInfo) . $itempool_item;
        }
        else {
            try {
                $this->core->getQueries()->addSolutionForComponentId($gradeable_id, $component_id, $itempool_item, $solution_text, $author_id);
                $solution_row = $this->core->getQueries()->getSolutionForComponentItempoolItem($gradeable_id, $component_id, $itempool_item);
            }
            catch (\Exception $exception) {
                $error = $exception->getMessage();
            }
        }

        return empty($error) ? JsonResponse::getSuccessResponse([
            "author" => $author_id,
            "current_user_id" => $this->core->getUser()->getId(),
            "edited_at" => DateUtils::convertTimeStamp(
                $this->core->getUser(),
                $solution_row['edited_at'],
                $this->core->getConfig()->getDateTimeFormat()->getFormat('solution_ta_notes')
            ),
            "solution_text" => $solution_text,
            "component_id" => $component_id,
            "itempool_item" => $solution_row['itempool_item'],
        ]) : JsonResponse::getErrorResponse($error);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/feedback/set", methods={"POST"})
     */
    public function ajaxSetPeerFeedback($gradeable_id) {
        $grader_id = $_POST['grader_id'] ?? '';
        $user_id = $_POST['user_id'] ?? '';
        $feedback = $_POST['feedback'];
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            return null;
        }
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $user_id)->getGradeableId() == $gradeable_id;
        if ($graded_gradeable === false) {
            return null;
        }
        $gradeable->setPeerFeedback($this->core->getQueries()->getAnonId($grader_id), $user_id, $feedback);
        $this->core->getOutput()->renderJsonSuccess("Feedback successfully uploaded");
        return true;
    }

    /**
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/clear_peer_marks", methods={"POST"})
     * @AccessControl(role="FULL_ACCESS_GRADER")
     */
    public function ajaxClearPeerMarks($gradeable_id) {
        $submitter_id = $_POST['submitter_id'] ?? '';
        $peer_id = $_POST['peer_id'] ?? '';
        $gradeable = $this->tryGetGradeable($gradeable_id);
        if ($gradeable === false) {
            $this->core->getOutput()->renderJsonFail('Could not fetch gradeable');
        }
        $graded_gradeable = $this->tryGetGradedGradeable($gradeable, $submitter_id);
        if ($graded_gradeable === false) {
            $this->core->getOutput()->renderJsonFail('Could not fetch graded gradeable');
        }
        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();
        foreach ($ta_graded_gradeable->getGradedComponentContainers() as $container) {
            $component = $container->getComponent();
            $ta_graded_gradeable->deleteGradedComponent($component, $this->core->getQueries()->getUserById($peer_id));
        }
        $ta_graded_gradeable->removeOverallComment($peer_id);
        $this->core->getQueries()->deleteOverallComment($gradeable_id, $peer_id, $gradeable->isTeamAssignment());
        $ta_graded_gradeable->resetUserViewedDate();

        // Finally, save the graded gradeable
        $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);
        $submitter = $ta_graded_gradeable->getGradedGradeable()->getSubmitter();
        if ($submitter->isTeam()) {
            $this->core->getQueries()->clearTeamViewedTime($submitter->getId());
        }
        $this->core->getOutput()->renderJsonSuccess("Marks removed successfully!");
        return true;
    }
}
