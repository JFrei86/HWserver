<?php

namespace app\controllers\grading;

use app\models\gradeable\GradedGradeable;
use app\models\User;
use app\controllers\GradingController;
use app\libraries\Utils;
use app\libraries\routers\AccessControl;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class SimpleGraderController
 * @package app\controllers\grading
 * @AccessControl(permission="grading.simple")
 */
class SimpleGraderController extends GradingController  {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'save_lab':
                $this->save();
                break;
            case 'save_numeric':
                $this->save();
                break;
            case 'upload_csv_numeric':
                $this->UploadCSV();
                break;
            default:
                break;
        }
    }

    /**
     * @param $gradeable_id
     * @param $section
     * @param $section_type
     * @param $sort_by
     * @Route("/{_semester}/{_course}/gradeable/{gradeable_id}/grading/print", methods={"GET"})
     */
    public function printLab($gradeable_id, $section = null, $section_type = null, $sort_by = "registration_section"){
        //convert from id --> u.user_id etc for use by the database.
        if ($sort_by === "id") {
            $sort_by = "u.user_id";
        }
        else if($sort_by === "first"){
            $sort_by = "coalesce(u.user_preferred_firstname, u.user_firstname)";
        }
        else if($sort_by === "last"){
            $sort_by = "coalesce(u.user_preferred_lastname, u.user_lastname)";
        }

        //Figure out what section we are supposed to print
        if (is_null($section)) {
            $this->core->addErrorMessage("ERROR: Section not set; You did not select a section to print.");
            $this->core->redirect($this->core->buildNewCourseUrl());
            return;
        };

        $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);

        if (!$this->core->getAccess()->canI("grading.simple.grade", ["gradeable" => $gradeable, "section" => $section])) {
            $this->core->addErrorMessage("ERROR: You do not have access to grade this section.");
            $this->core->redirect($this->core->buildNewCourseUrl());
            return;
        }

        //Figure out if we are getting users by rotating or registration section.
        if (is_null($section_type)) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable');
        }

        //Grab the students in section, sectiontype.
        if ($section_type === "rotating_section") {
            $students = $this->core->getQueries()->getUsersByRotatingSections(array($section), $sort_by);
        }
        elseif ($section_type === "registration_section") {
            $students = $this->core->getQueries()->getUsersByRegistrationSections(array($section), $sort_by);
        }
        else {
            $this->core->addErrorMessage("ERROR: You did not select a valid section type to print.");
            $this->core->redirect($this->core->buildNewCourseUrl());
            return;
        }

        //Turn off header/footer so that we are using simple html.
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        //display the lab to be printed (in SimpleGraderView's displayPrintLab function)
        $this->core->getOutput()->renderOutput(array('grading', 'SimpleGrader'), 'displayPrintLab', $gradeable, $section, $students);
    }

    /**
     * @param $gradeable_id
     * @param $view
     * @param $sort
     * @Route("/{_semester}/{_course}/gradeable/{gradeable_id}/grading", methods={"GET"})
     */
    public function grade($gradeable_id, $view = null, $sort = null) {
        try {
            $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);
        } catch(\InvalidArgumentException $e) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return;
        }

        //If you can see the page, you can grade the page
        if (!$this->core->getAccess()->canI("grading.simple.grade", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getTitle()}");
            $this->core->redirect($this->core->buildNewCourseUrl());
        }

        // sort makes sorting remain when clicking print lab or view all
        if($sort === "id"){
            $sort_key = "u.user_id";
        }
        else if($sort === "first"){
            $sort_key = "coalesce(u.user_preferred_firstname, u.user_firstname)";
        }
        else {
            $sort_key = "coalesce(u.user_preferred_lastname, u.user_lastname)";
        }

        if ($gradeable->isGradeByRegistration()) {
            $grading_count = count($this->core->getUser()->getGradingRegistrationSections());
        } else {
            $grading_count = count($this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(), $this->core->getUser()->getId()));
        }
        //Can you show all
        $can_show_all = $this->core->getAccess()->canI("grading.simple.show_all");
        //Are you currently showing all
        $show_all = ($view === 'all' || $grading_count === 0) && $can_show_all;
        //Should the button be shown
        $show_all_sections_button = $can_show_all;

        //Checks to see if the Grader has access to all users in the course,
        //Will only show the sections that they are graders for if not TA or Instructor
        if($show_all) {
            $sections = $gradeable->getAllGradingSections();
        } else {
            $sections = $gradeable->getGradingSectionsForUser($this->core->getUser());
        }

        $students = [];
        foreach ($sections as $section) {
            $students = array_merge($students, $section->getUsers());
        }
        $student_ids = array_map(function(User $user) {
            return $user->getId();
        }, $students);

        $student_full = Utils::getAutoFillData($students);

        if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
        } else {
            $section_key = "rotating_section";
        }

        $graders = [];
        foreach ($sections as $section) {
            $graders[$section->getName()] = $section->getGraders();
        }

        $rows = $this->core->getQueries()->getGradedGradeables([$gradeable], $student_ids, null, [$section_key, $sort_key]);
        $this->core->getOutput()->renderOutput(array('grading', 'SimpleGrader'), 'simpleDisplay', $gradeable, $rows, $student_full, $graders, $section_key, $show_all_sections_button, $sort);
    }

    public function save() {
        if (!isset($_REQUEST['g_id']) || !isset($_REQUEST['user_id'])) {
            return $this->core->getOutput()->renderJsonFail('Did not pass in g_id or user_id');
        }
        $g_id = $_REQUEST['g_id'];
        $user_id = $_REQUEST['user_id'];

        $grader = $this->core->getUser();
        $gradeable = $this->core->getQueries()->getGradeableConfig($g_id);

        $user = $this->core->getQueries()->getUserById($user_id);
        if (!$this->core->checkCsrfToken()) {
            return $this->core->getOutput()->renderJsonFail('Invalid CSRF token');
        } else if ($gradeable === null) {
            return $this->core->getOutput()->renderJsonFail('Invalid gradeable ID');
        } else if ($user === null) {
            return $this->core->getOutput()->renderJsonFail('Invalid user ID');
        } else if (!isset($_POST['scores']) || empty($_POST['scores'])) {
            return $this->core->getOutput()->renderJsonFail("Didn't submit any scores");
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $user_id, null);

        //Make sure they're allowed to do this
        if (!$this->core->getAccess()->canI("grading.simple.grade", ["graded_gradeable" => $graded_gradeable])) {
            return $this->core->getOutput()->renderJsonFail("You do not have permission to do this.");
        }

        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        foreach ($gradeable->getComponents() as $component) {
            $data = $_POST['scores'][$component->getId()] ?? '';
            $original_data = $_POST['old_scores'][$component->getId()] ?? '';
            // This catches both the not-set and blank-data case
            if ($data !== '') {
                $component_grade = $ta_graded_gradeable->getOrCreateGradedComponent($component, $grader, true);
                $component_grade->setGrader($grader);

                if ($component->isText()) {
                    $component_grade->setComment($data);
                } else {
                    if ($component->getUpperClamp() < $data ||
                        !is_numeric($data)) {
                        return $this->core->getOutput()->renderJsonFail("Save error: score must be a number less than the upper clamp");
                    }
                    $db_data = $component_grade->getTotalScore();
                    if ($original_data != $db_data) {
                        return $this->core->getOutput()->renderJsonFail("Save error: displayed stale data (" . $original_data . ") does not match database (" . $db_data . ")");
                    }
                    $component_grade->setScore($data);
                }
                $component_grade->setGradeTime($this->core->getDateTimeNow());
            }
        }

        $ta_graded_gradeable->setOverallComment('');
        $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);

        return $this->core->getOutput()->renderJsonSuccess();
    }

    public function UploadCSV() {

        $users = $_POST['users'];
        $g_id = $_POST['g_id'];

        $gradeable = $this->core->getQueries()->getGradeableConfig($g_id);
        $grader = $this->core->getUser();

        //FIXME: returning html error message in a json-returning route
        if (!$this->core->getAccess()->canI("grading.simple.upload_csv", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getTitle()}");
            $this->core->redirect($this->core->buildNewCourseUrl());
        }

        $num_numeric = $_POST['num_numeric'];

        // FIXME: remove these parameters in the javascript request
//        $num_text = $_POST['num_text'];
//        $component_ids = $_POST['component_ids'];
        $csv_array = preg_split("/\r\n|\n|\r/", $_POST['big_file']);
        $arr_length = count($csv_array);
        $return_data = array();

        $data_array = array();
        for ($i = 0; $i < $arr_length; $i++) {
            $temp_array = explode(',', $csv_array[$i]);
            $data_array[] = $temp_array;
        }

        /** @var GradedGradeable $graded_gradeable */
        foreach($this->core->getQueries()->getGradedGradeables([$gradeable], $users, null) as $graded_gradeable) {
            for ($j = 0; $j < $arr_length; $j++) {
                $username = $graded_gradeable->getSubmitter()->getId();
                if($username !== $data_array[$j][0]) {
                    continue;
                }

                $temp_array = array();
                $temp_array['username'] = $username;
                $index1 = 0;
                $index2 = 3; //3 is the starting index of the grades in the csv
                $value_str = "value_";
                $status_str = "status_";

                // Get the user grade for this gradeable
                $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

                //Makes an array with all the values and their status.
                foreach ($gradeable->getComponents() as $component) {
                    $component_grade = $ta_graded_gradeable->getOrCreateGradedComponent($component, $grader, true);
                    $component_grade->setGrader($grader);

                    $value_temp_str = $value_str . $index1;
                    $status_temp_str = $status_str . $index1;
                    if (isset($data_array[$j][$index2])) {
                        if ($component->isText()){
                            $component_grade->setComment($data_array[$j][$index2]);
                            $component_grade->setGradeTime($this->core->getDateTimeNow());
                            $temp_array[$value_temp_str] = $data_array[$j][$index2];
                            $temp_array[$status_temp_str] = "OK";
                        }
                        else{
                            if($component->getUpperClamp() < $data_array[$j][$index2]){
                                $temp_array[$value_temp_str] = $data_array[$j][$index2];
                                $temp_array[$status_temp_str] = "ERROR";
                            } else {
                                $component_grade->setScore($data_array[$j][$index2]);
                                $component_grade->setGradeTime($this->core->getDateTimeNow());
                                $temp_array[$value_temp_str] = $data_array[$j][$index2];
                                $temp_array[$status_temp_str] = "OK";
                            }

                        }
                    }
                    $index1++;
                    $index2++;

                    //skips the index of the total points in the csv file
                    if($index1 == $num_numeric) {
                        $index2++;
                    }
                }

                // Reset the overall comment because we're overwriting the grade anyway
                $ta_graded_gradeable->setOverallComment('');
                $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);

                $return_data[] = $temp_array;
                $j = $arr_length; //stops the for loop early to not waste resources
            }
        }

        return $this->core->getOutput()->renderJsonSuccess($return_data);
    }


}
