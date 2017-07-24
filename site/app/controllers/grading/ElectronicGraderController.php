<?php

namespace app\controllers\grading;

use app\controllers\AbstractController;
use app\models\User;
use app\models\HWReport;
use \app\libraries\GradeableType;
use app\models\Gradeable;
use app\models\GradeableComponent;
use app\models\GradeableComponentMark;

class ElectronicGraderController extends AbstractController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'details':
                $this->showDetails();
                break;
            case 'grade':
                $this->showGrading();
                break;
            case 'submit':
                $this->submitGrade();
                break;
            case 'save_one_component':
                $this->saveSingleComponent();
                break;
            default:
                $this->showStatus();
                break;
        }
    }

    /**
     * Shows statistics for the grading status of a given electronic submission. This is shown to all full access
     * graders. Limited access graders will only see statistics for the sections they are assigned to.
     */
    public function showStatus() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);
        $this->core->getOutput()->addBreadcrumb("Status {$gradeable->getName()}");

        if ($this->core->getUser()->getGroup() > $gradeable->getMinimumGradingGroup()) {
            $_SESSION['messages']['error'][] = "You do not have permission to grade {$gradeable->getName()}";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        /*
         * we need number of students per section
         */
        $total = array();
        $graded_components = array();
        $graders = array();
        $sections = array();
        $total_users = 0;
        if ($gradeable->isGradeByRegistration()) {
            if(!$this->core->getUser()->accessFullGrading()){
                $sections = $this->core->getUser()->getGradingRegistrationSections();
            }
            if (count($sections) > 0 || $this->core->getUser()->accessFullGrading()) {
                $total_users = $this->core->getQueries()->getTotalUserCountByRegistrationSections($sections);
                $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable->getId());
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByRegistrationSections($gradeable->getId(), $sections);
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
            }
        }
        else {
            if(!$this->core->getUser()->accessFullGrading()){
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            }
            if (count($sections) > 0 || $this->core->getUser()->accessFullGrading()) {
                $total_users = $this->core->getQueries()->getTotalUserCountByRotatingSections($sections);
                $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable->getId());
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByRotatingSections($gradeable_id, $sections);
                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable->getId(), $sections);
            }
        }

        $sections = array();
        if (count($total_users) > 0) {
            foreach ($total_users as $key => $value) {
                $sections[$key] = array(
                    'total_components' => $value * $num_components,                        
                    'graded_components' => 0,
                    'graders' => array()
                );
                if (isset($graded_components[$key])) {
                    $sections[$key]['graded_components'] = intval($graded_components[$key]);
                }
                if (isset($graders[$key])) {
                    $sections[$key]['graders'] = $graders[$key];
                }
            }
        }

        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'statusPage', $gradeable, $sections);
    }

    /**
     * This loads a gradeable and
     */
    public function showDetails() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);
        $this->core->getOutput()->addBreadcrumb("Details {$gradeable->getName()}");

        if ($this->core->getUser()->getGroup() > $gradeable->getMinimumGradingGroup()) {
            $_SESSION['messages']['error'][] = "You do not have permission to grade {$gradeable->getName()}";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        if ($gradeable === null) {
            $this->core->getOutput()->renderOutput('Error', 'noGradeable', $gradeable_id);
            return;
        }
        $students = array();
        if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
            $sections = $this->core->getUser()->getGradingRegistrationSections();
            if (!isset($_GET['view']) || $_GET['view'] !== "all") {
                $students = $this->core->getQueries()->getUsersByRegistrationSections($sections);
            }
            $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
        }
        else {
            $section_key = "rotating_section";
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id,
                $this->core->getUser()->getId());
            if (!isset($_GET['view']) || $_GET['view'] !== "all") {
                $students = $this->core->getQueries()->getUsersByRotatingSections($sections);
            }
            $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable->getId(), $sections);
        }
        if ((isset($_GET['view']) && $_GET['view'] === "all") || ($this->core->getUser()->accessAdmin() && count($sections) === 0)) {
            $students = $this->core->getQueries()->getAllUsers($section_key);
        }

        $student_ids = array_map(function(User $student) { return $student->getId(); }, $students);

        $rows = $this->core->getQueries()->getGradeables($gradeable_id, $student_ids, $section_key);
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'detailsPage', $gradeable, $rows, $graders);
    }

    public function submitGrade() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] != $this->core->getCsrfToken()) {
            $_SESSION['messages']['error'][] = "Invalid CSRF Token";
            $this->core->redirect($this->core->buildUrl(array()));
        }

        $gradeable_id = $_POST['g_id'];
        $who_id = $_POST['u_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $who_id);

        if ($this->core->getUser()->getGroup() > $gradeable->getMinimumGradingGroup()) {
            $_SESSION['messages']['error'][] = "You do not have permission to grade {$gradeable->getName()}";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        if ($this->core->getUser()->getGroup() === 3) {
            if ($gradeable->isGradeByRegistration()) {
                $sections = $this->core->getUser()->getGradingRegistrationSections();
                $users_to_grade = $this->core->getQueries()->getUsersByRegistrationSections($sections);
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
                $users_to_grade = $this->core->getQueries()->getUsersByRotatingSections($sections);
            }
            $user_ids_to_grade = array_map(function(User $user) { return $user->getId(); }, $users_to_grade);
            if (!in_array($who_id, $user_ids_to_grade)) {
                $_SESSION['messages']['error'][] = "You do not have permission to grade {$who_id}";
                $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'gradeable_id' => $gradeable_id)));
            }
        }

        $now = new \DateTime('now', $this->core->getConfig()->getTimezone());
        $homeworkDate = $gradeable->getGradeStartDate();
        if ($now < $homeworkDate) {
            $_SESSION['messages']['error'][] = "Grading is not open yet for {$gradeable->getName()}";
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'gradeable_id' => $gradeable_id)));
        }

        $gradeable->setOverallComment($_POST['comment-general']);

        $comps = $gradeable->getComponents();
        foreach($comps as $key => $data) {
            if (isset($_POST['overwrite'])) $comps[$key]->setGrader($this->core->getUser());
            $comps[$key]->setScore(floatval($_POST["grade-{$comps[$key]->getOrder()}"]));
            $comps[$key]->setComment($_POST["comment-{$comps[$key]->getOrder()}"]);
            $comps[$key]->setGradedVersion($_POST["graded_version"]);
            $comps[$key]->setGradeTime($now);
        }
        $gradeable->setComponents($comps);

        $gradeable->saveData();

        $hwReport = new HWReport($this->core);
        $hwReport->generateSingleReport($who_id, $gradeable_id);

        $_SESSION['messages']['success'][] = "Successfully uploaded grade for {$who_id}";
        $individual = intval($_POST['individual']);
        if ($individual == 1) {
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'details','gradeable_id'=>$gradeable_id)));
        }
        else {
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'grade', 'gradeable_id'=>$gradeable_id, 'individual'=>'0')));
        }   
    }

    public function showGrading() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        if ($this->core->getUser()->getGroup() > $gradeable->getMinimumGradingGroup()) {
            $_SESSION['messages']['error'][] = "You do not have permission to grade {$gradeable->getName()}";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        $graded = 0;
        $total = 0;
        if ($gradeable->isGradeByRegistration()) {
            $section_key = "registration_section";
            $sections = $this->core->getUser()->getGradingRegistrationSections();
            if ($this->core->getUser()->accessAdmin() && $sections == null) {
                $sections = $this->core->getQueries()->getRegistrationSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_registration_id'];
                }
            }
            $users_to_grade = $this->core->getQueries()->getUsersByRegistrationSections($sections);
            $total = array_sum($this->core->getQueries()->getTotalUserCountByRegistrationSections($sections));
            $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByRegistrationSections($gradeable_id, $sections));
        }
        else {
            $section_key = "rotating_section";
            $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            if ($this->core->getUser()->accessAdmin() && $sections == null) {
                $sections = $this->core->getQueries()->getRotatingSections();
                for ($i = 0; $i < count($sections); $i++) {
                    $sections[$i] = $sections[$i]['sections_rotating_id'];
                }
            }
            $users_to_grade = $this->core->getQueries()->getUsersByRotatingSections($sections);
            $total = array_sum($this->core->getQueries()->getTotalUserCountByRotatingSections($sections));
            $graded = array_sum($this->core->getQueries()->getGradedComponentsCountByRotatingSections($gradeable_id, $sections));
        }

        if($total == 0) {
            $progress = 100;
        }
        else {
            $progress = round(($graded / $total) * 100, 1);
        }

        $user_ids_to_grade = array_map(function(User $user) { return $user->getId(); }, $users_to_grade);
        $gradeables_to_grade = $this->core->getQueries()->getGradeables($gradeable_id, $user_ids_to_grade, $section_key);

        $who_id = isset($_REQUEST['who_id']) ? $_REQUEST['who_id'] : "";
        if (($who_id !== "") && ($this->core->getUser()->getGroup() === 3) && !in_array($who_id, $user_ids_to_grade)) {
            $_SESSION['messages']['error'][] = "You do not have permission to grade {$who_id}";
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'gradeable_id' => $gradeable_id)));
        }

        $prev_id = "";
        $next_id = "";
        $break_next = false;
        foreach ($gradeables_to_grade as $g) {
            $id = $g->getUser()->getId();
            if ($break_next) {
                $next_id = $id;
                break;
            }
            if (($who_id === "" && !$g->beenTAgraded()) || $who_id === $id) {
                $who_id = $id;
                $break_next = true;
            }
            else {
                $prev_id = $id;
            }
        }
        if ($who_id === "") {
            $_SESSION['messages']['success'][] = "Finished grading for {$gradeable->getName()}";
            $this->core->redirect($this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'gradeable_id' => $gradeable_id)));
        }

        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $who_id);
        $gradeable->loadResultDetails();
        $individual = $_REQUEST['individual'];


        $this->core->getOutput()->addCSS($this->core->getConfig()->getBaseUrl()."/css/ta-grading.css");
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'hwGradingPage', $gradeable, $progress, $prev_id, $next_id, $individual);
    }

    public function saveSingleComponent() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $_POST['user_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        foreach ($gradeable->getComponents() as $component) {
            if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            }
            else {
                if($gradeable->getGdId() == null) {
                    $gradeable->saveData2();
                }
                $component->setGrader($this->core->getUser());
                $component->setGradedVersion($_POST['active_version']);
                $component->setGradeTime(new \DateTime('now', $this->core->getConfig()->getTimezone()));
                $component->setComment($_POST['custom_message']);
                $component->setScore($_POST['custom_points']);
                $component->saveData($gradeable->getGdId());

                $index = 0;
                $marks_count = count($marks);
                // save existing marks
                foreach ($component->getMarks() as $mark) {
                    $mark->setPoints($_POST['marks'][$index]['points']);
                    $mark->setNote($_POST['marks'][$index]['note']);
                    $mark->setOrder($_POST['marks'][$index]['order']);
                    $mark->save();
                    $_POST['marks'][$index]['selected'] == 'true' ? $mark->setHasMark(true) : $mark->setHasMark(false);
                    $mark->saveData($gradeable->getGdId(), $component->getId());
                    $index++;
                }
                // create new marks
                for ($i = $index; $i < $_POST['num_mark']; $i++) {
                    $mark = new GradeableComponentMark($this->core);
                    $mark->setGcId($component->getId());
                    $mark->setPoints($_POST['marks'][$i]['points']);
                    $mark->setNote($_POST['marks'][$i]['note']);
                    $mark->setOrder($_POST['marks'][$i]['order']);
                    $mark->save();
                    $mark->setHasMark($_POST['marks'][$i]['selected']);
                    $mark->saveData($gradeable->getGdId(), $component->getId());
                }
            }
        }
    }
}
