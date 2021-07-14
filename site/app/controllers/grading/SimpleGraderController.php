<?php

namespace app\controllers\grading;

use app\libraries\response\RedirectResponse;
use app\models\gradeable\GradedGradeable;
use app\models\User;
use app\controllers\AbstractController;
use app\libraries\Utils;
use app\libraries\routers\AccessControl;
use app\libraries\response\MultiResponse;
use app\libraries\response\JsonResponse;
use app\libraries\response\WebResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class SimpleGraderController
 * @package app\controllers\grading
 * @AccessControl(permission="grading.simple")
 */
class SimpleGraderController extends AbstractController {
    /**
     * @param $gradeable_id
     * @param $section
     * @param $section_type
     * @param $sort
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/print", methods={"GET"})
     * @return MultiResponse
     */
    public function printLab($gradeable_id, $section = null, $section_type = null, $sort = "id") {
        //convert from id --> u.user_id etc for use by the database.
        if ($sort === "id") {
            $sort_by = "u.user_id";
        }
        elseif ($sort === "first") {
            $sort_by = "coalesce(NULLIF(u.user_preferred_firstname, ''), u.user_firstname)";
        }
        else {
            $sort_by = "coalesce(NULLIF(u.user_preferred_lastname, ''), u.user_lastname)";
        }

        //Figure out what section we are supposed to print
        if (is_null($section)) {
            $this->core->addErrorMessage("ERROR: Section not set; You did not select a section to print.");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl())
            );
        }

        $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);

        if (!$this->core->getAccess()->canI("grading.simple.grade", ["gradeable" => $gradeable, "section" => $section])) {
            $this->core->addErrorMessage("ERROR: You do not have access to grade this section.");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl())
            );
        }

        //Figure out if we are getting users by rotating or registration section.
        if (is_null($section_type)) {
            return MultiResponse::webOnlyResponse(
                new WebResponse('Error', 'noGradeable')
            );
        }

        //Grab the students in section, sectiontype.
        if ($section_type === "rotating_section") {
            $students = $this->core->getQueries()->getUsersByRotatingSections([$section], $sort_by);
        }
        elseif ($section_type === "registration_section") {
            $students = $this->core->getQueries()->getUsersByRegistrationSections([$section], $sort_by);
        }
        else {
            $this->core->addErrorMessage("ERROR: You did not select a valid section type to print.");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl())
            );
        }

        //Turn off header/footer so that we are using simple html.
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        //display the lab to be printed (in SimpleGraderView's displayPrintLab function)
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                ['grading', 'SimpleGrader'],
                'displayPrintLab',
                $gradeable,
                $section,
                $students
            )
        );
    }

    /**
     * @param $gradeable_id
     * @param $view
     * @param $sort
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading", methods={"GET"})
     * @return MultiResponse
     */
    public function gradePage($gradeable_id, $view = null, $sort = "section_subsection") {
        try {
            $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);
        }
        catch (\InvalidArgumentException $e) {
            return MultiResponse::webOnlyResponse(
                new WebResponse('Error', 'noGradeable')
            );
        }

        //If you can see the page, you can grade the page
        if (!$this->core->getAccess()->canI("grading.simple.grade", ["gradeable" => $gradeable])) {
            $this->core->addErrorMessage("You do not have permission to grade {$gradeable->getTitle()}");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl())
            );
        }

        // sort makes sorting remain when clicking print lab or view all
        if ($sort === "id") {
            $sort_key = "u.user_id";
        }
        elseif ($sort === "first") {
            $sort_key = "coalesce(NULLIF(u.user_preferred_firstname, ''), u.user_firstname)";
        }
        elseif ($sort === "last") {
            $sort_key = "coalesce(NULLIF(u.user_preferred_lastname, ''), u.user_lastname)";
        }
        else {
            $sort_key = "u.registration_subsection";
        }

        if ($gradeable->isGradeByRegistration()) {
            $grading_count = count($this->core->getUser()->getGradingRegistrationSections());
        }
        else {
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
        if ($show_all) {
            $sections = $gradeable->getAllGradingSections();
        }
        else {
            $sections = $gradeable->getGradingSectionsForUser($this->core->getUser());
        }

        $students = [];
        foreach ($sections as $section) {
            $students = array_merge($students, $section->getUsers());
        }
        $student_ids = array_map(function (User $user) {
            return $user->getId();
        }, $students);

        $student_full = Utils::getAutoFillData($students);

        if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
        }
        else {
            $section_key = "rotating_section";
        }

        $graders = [];
        foreach ($sections as $section) {
            $graders[$section->getName()] = $section->getGraders();
        }

        $rows = $this->core->getQueries()->getGradedGradeables([$gradeable], $student_ids, null, [$section_key, $sort_key, "u.user_id"]);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                ['grading', 'SimpleGrader'],
                'simpleDisplay',
                $gradeable,
                $rows,
                $student_full,
                $graders,
                $section_key,
                $show_all_sections_button,
                $sort
            )
        );
    }

    /**
     * @param $gradeable_id
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading", methods={"POST"})
     * @return MultiResponse
     */
    public function save($gradeable_id) {
        if (!isset($_POST['user_id'])) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Did not pass in user_id')
            );
        }
        $user_id = $_POST['user_id'];

        $grader = $this->core->getUser();
        $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);

        $user = $this->core->getQueries()->getUserById($user_id);
        if ($gradeable === null) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse("Invalid gradeable ID")
            );
        }
        elseif ($user === null) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse("Invalid user ID")
            );
        }
        elseif (!isset($_POST['scores']) || empty($_POST['scores'])) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse("Didn't submit any scores")
            );
        }

        $graded_gradeable = $this->core->getQueries()->getGradedGradeable($gradeable, $user_id, null);

        //Make sure they're allowed to do this
        if (!$this->core->getAccess()->canI("grading.simple.grade", ["graded_gradeable" => $graded_gradeable])) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse("You do not have permission to do this.")
            );
        }

        $ta_graded_gradeable = $graded_gradeable->getOrCreateTaGradedGradeable();

        // Return ids and scores of updated components in success response so frontend can validate
        $return_data = [];

        foreach ($gradeable->getComponents() as $component) {
            $data = $_POST['scores'][$component->getId()] ?? '';
            $original_data = $_POST['old_scores'][$component->getId()] ?? '';
            // This catches both the not-set and blank-data case
            if ($data !== '') {
                $component_grade = $ta_graded_gradeable->getOrCreateGradedComponent($component, $grader, true);
                $component_grade->setGrader($grader);

                if ($component->isText()) {
                    $component_grade->setComment($data);
                }
                else {
                    if (
                        $component->getUpperClamp() < $data
                        || !is_numeric($data)
                    ) {
                        return MultiResponse::JsonOnlyResponse(
                            JsonResponse::getFailResponse("Save error: score must be a number less than the upper clamp")
                        );
                    }
                    $db_data = $component_grade->getTotalScore();
                    if ($original_data != $db_data) {
                        return MultiResponse::JsonOnlyResponse(
                            JsonResponse::getFailResponse("Save error: displayed stale data (" . $original_data . ") does not match database (" . $db_data . ")")
                        );
                    }
                    $component_grade->setScore($data);
                }
                $component_grade->setGradeTime($this->core->getDateTimeNow());
                $return_data[$component->getId()] = $data;
            }
        }

        $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);

        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse($return_data)
        );
    }

    /**
     * @param $gradeable_id
     * @Route("/courses/{_semester}/{_course}/gradeable/{gradeable_id}/grading/csv", methods={"POST"})
     * @return MultiResponse
     */
    public function UploadCSV($gradeable_id) {
        $users = $_POST['users'];

        $gradeable = $this->core->getQueries()->getGradeableConfig($gradeable_id);
        $grader = $this->core->getUser();

        if (!$this->core->getAccess()->canI("grading.simple.upload_csv", ["gradeable" => $gradeable])) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse("You do not have permission to grade {$gradeable->getTitle()}")
            );
        }

        $num_numeric = $_POST['num_numeric'];

        $csv_array = preg_split("/\r\n|\n|\r/", $_POST['big_file']);
        $arr_length = count($csv_array);
        $return_data = [];

        $data_array = [];
        for ($i = 0; $i < $arr_length; $i++) {
            $temp_array = explode(',', $csv_array[$i]);
            $data_array[] = $temp_array;
        }

        /** @var GradedGradeable $graded_gradeable */
        foreach ($this->core->getQueries()->getGradedGradeables([$gradeable], $users, null) as $graded_gradeable) {
            for ($j = 0; $j < $arr_length; $j++) {
                $username = $graded_gradeable->getSubmitter()->getId();
                if ($username !== $data_array[$j][0]) {
                    continue;
                }

                $temp_array = [];
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
                        if ($component->isText()) {
                            $component_grade->setComment($data_array[$j][$index2]);
                            $component_grade->setGradeTime($this->core->getDateTimeNow());
                            $temp_array[$value_temp_str] = $data_array[$j][$index2];
                            $temp_array[$status_temp_str] = "OK";
                        }
                        else {
                            if ($component->getUpperClamp() < $data_array[$j][$index2]) {
                                $temp_array[$value_temp_str] = $data_array[$j][$index2];
                                $temp_array[$status_temp_str] = "ERROR";
                            }
                            else {
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
                    if ($index1 == $num_numeric) {
                        $index2++;
                    }
                }

                // Reset the overall comment because we're overwriting the grade anyway
                $this->core->getQueries()->saveTaGradedGradeable($ta_graded_gradeable);

                $return_data[] = $temp_array;
                $j = $arr_length; //stops the for loop early to not waste resources
            }
        }

        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse($return_data)
        );
    }
}
