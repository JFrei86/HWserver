<?php

namespace app\controllers\grading;

use app\controllers\AbstractController;
use app\models\User;
use app\models\HWReport;
use \app\libraries\GradeableType;
use app\models\Gradeable;
use app\models\GradeableComponent;
use app\models\GradeableComponentMark;
use app\libraries\FileUtils;

class ElectronicGraderController extends AbstractController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'details':
                $this->showDetails();
                break;
            case 'submit_team_form':
                $this->adminTeamSubmit();
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
            case 'save_gradeable_comment':
                $this->saveGradeableComment();
                break;
            case 'get_mark_data':
                $this->getMarkDetails();
                break;
            case 'get_gradeable_comment':
                $this->getGradeableComment();
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
        $total_users = array();
        $no_team_users = array();
        $graded_components = array();
        $graders = array();
        if ($gradeable->isGradeByRegistration()) {
            if(!$this->core->getUser()->accessFullGrading()){
                $sections = $this->core->getUser()->getGradingRegistrationSections();
            }
            else {
                $sections = $this->core->getQueries()->getRegistrationSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_registration_id'];
                }
            }

            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
                if ($gradeable->isTeamAssignment()) {
                    $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'registration_section');
                    $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_id, $sections, 'registration_section');
                    $graded_components = $this->core->getQueries()->getGradedComponentsCountByTeamGradingSections($gradeable_id, $sections, 'registration_section');
                }
                else {
                    $total_users = $this->core->getQueries()->getTotalUserCountByRegistrationSections($sections);
                    $no_team_users = array();
                    $graded_components = $this->core->getQueries()->getGradedComponentsCountByRegistrationSections($gradeable_id, $sections);
                }
            }
        }
        else {
            if(!$this->core->getUser()->accessFullGrading()){
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_id, $this->core->getUser()->getId());
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_rotating_id'];
                }
            }

            if (count($sections) > 0) {
                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable->getId(), $sections);
                if ($gradeable->isTeamAssignment()) {
                    $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_id, $sections, 'rotating_section');
                    $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_id, $sections, 'rotating_section');
                    $graded_components = $this->core->getQueries()->getGradedComponentsCountByTeamGradingSections($gradeable_id, $sections, 'rotating_section');
                }
                else {
                    $total_users = $this->core->getQueries()->getTotalUserCountByRotatingSections($sections);
                    $no_team_users = array();
                    $graded_components = $this->core->getQueries()->getGradedComponentsCountByRotatingSections($gradeable_id, $sections);
                }
            }
        }

        $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable_id);
        $sections = array();
        if (count($total_users) > 0) {
            foreach ($total_users as $key => $value) {
                $sections[$key] = array(
                    'total_components' => $value * $num_components,                        
                    'graded_components' => 0,
                    'graders' => array()
                );
                if ($gradeable->isTeamAssignment()) {
                    $sections[$key]['no_team'] = $no_team_users[$key];
                }
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

        $empty_teams = array();
        if ($gradeable->isTeamAssignment()) {
            // Only give getGradeables one User ID per team
            $all_teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
            foreach($all_teams as $team) {
                $student_ids = array_diff($student_ids, $team->getMembers());
                $team_section = $gradeable->isGradeByRegistration() ? $team->getRegistrationSection() : $team->getRotatingSection();
                if ($team->getSize() > 0 && (in_array($team_section, $sections) || 
                                            (isset($_GET['view']) && $_GET['view'] === "all") || 
                                            (count($sections) === 0 && $this->core->getUser()->accessAdmin()))) {
                    $student_ids[] = $team->getMembers()[0];
                }
                if ($team->getSize() === 0 && $this->core->getUser()->accessAdmin()) {
                    $empty_teams[] = $team;
                }
            }
        }

        $rows = $this->core->getQueries()->getGradeables($gradeable_id, $student_ids, $section_key);
        if ($gradeable->isTeamAssignment()) {
            // Rearrange gradeables arrray into form (sec 1 teams, sec 1 individuals, sec 2 teams, sec 2 individuals, etc...)
            $sections = array();
            $individual_rows = array();
            $team_rows = array();
            foreach($rows as $row) {
                if ($gradeable->isGradeByRegistration()) {
                    $section = $row->getTeam() === null ? strval($row->getUser()->getRegistrationSection()) : strval($row->getTeam()->getRegistrationSection());
                }
                else {
                    $section = $row->getTeam() === null ? strval($row->getUser()->getRotatingSection()) : strval($row->getTeam()->getRotatingSection());
                }

                if ($section != null && !in_array($section, $sections)) {
                    $sections[] = $section;
                }

                if ($row->getTeam() === null) {
                    if (!isset($individual_rows[$section])) {
                        $individual_rows[$section] = array();
                    }
                    $individual_rows[$section][] = $row;
                }
                else {
                    if (!isset($team_rows[$section])) {
                        $team_rows[$section] = array();
                    }
                    $team_rows[$section][] = $row;
                }
            }

            asort($sections);
            $rows = array();
            foreach($sections as $section) {
                if (isset($team_rows[$section])) {
                    $rows = array_merge($rows, $team_rows[$section]);
                }
                if (isset($individual_rows[$section])) {
                    $rows = array_merge($rows, $individual_rows[$section]);
                }
            }
            // Put null section at end of array
            if (isset($team_rows[""])) {
                $rows = array_merge($rows, $team_rows[""]);
            }
            if (isset($individual_rows[""])) {
                $rows = array_merge($rows, $individual_rows[""]);
            }
        }
        $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'detailsPage', $gradeable, $rows, $graders, $empty_teams);

        if ($gradeable->isTeamAssignment() && $this->core->getUser()->accessAdmin()) {
            if ($gradeable->isGradeByRegistration()) {
                $all_sections = $this->core->getQueries()->getRegistrationSections();
                $key = 'sections_registration_id';
            }
            else {
                $all_sections = $this->core->getQueries()->getRotatingSections();
                $key = 'sections_rotating_id';
            }
            foreach ($all_sections as $i => $section) {
                $all_sections[$i] = $section[$key];
            }
            $this->core->getOutput()->renderOutput(array('grading', 'ElectronicGrader'), 'adminTeamForm', $gradeable, $all_sections);
        }
    }

    public function adminTeamSubmit() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] != $this->core->getCsrfToken()) {
            $_SESSION['messages']['error'][] = "Invalid CSRF Token";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        if (!$this->core->getUser()->accessAdmin()) {
            $_SESSION['messages']['error'][] = "Only admins can edit teams";
            $this->core->redirect($this->core->getConfig()->getSiteUrl());
        }

        $gradeable_id = $_REQUEST['gradeable_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id);

        $return_url = $this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action'=>'details','gradeable_id'=>$gradeable_id));
        if (isset($_POST['view'])) $return_url .= "&view={$_POST['view']}";

        if (!$gradeable->isTeamAssignment()) {
            $this->core->addErrorMessage("{$gradeable->getName()} is not a team assignment");
            $this->core->redirect($return_url);
        }

        $num_users = intval($_POST['num_users']);
        $user_ids = array();
        for ($i = 0; $i < $num_users; $i++) {
            $id = trim(htmlentities($_POST["user_id_{$i}"]));
            if (($id !== "") && !in_array($id, $user_ids)) {
                if ($this->core->getQueries()->getUserById($id) === null) {
                    $_SESSION['messages']['error'][] = "ERROR: {$id} is not a valid User ID";
                    $this->core->redirect($return_url);
                }
                $user_ids[] = $id;
            }
        }
        $new_team = $_POST['new_team'] === 'true' ? true : false;

        if ($new_team) {
            $team_leader_id = null;
            foreach($user_ids as $id) {
                if ($this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $id) !== null) {
                    $_SESSION['messages']['error'][] = "ERROR: {$id} is already on a team";
                    $this->core->redirect($return_url);
                }
                if ($id === $_POST['new_team_user_id']) {
                    $team_leader_id = $id;
                    if ($gradeable->isGradeByRegistration()) {
                        $registration_section = $_POST['section'] === "NULL" ? null : intval($_POST['section']);
                        $rotating_section = $this->core->getQueries()->getUserById($id)->getRotatingSection();
                    }
                    else {
                        $registration_section = $this->core->getQueries()->getUserById($id)->getRegistrationSection();
                        $rotating_section = $_POST['section'] === "NULL" ? null : intval($_POST['section']);
                    }
                }
            }
            if ($team_leader_id === null) {
                $_SESSION['messages']['error'][] = "ERROR: {$_POST['new_team_user_id']} must be on the team";
                $this->core->redirect($return_url);
            }

            $team_id = $this->core->getQueries()->createTeam($gradeable_id, $team_leader_id, $registration_section, $rotating_section);
            foreach($user_ids as $id) {
                $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $id);
                if ($id !== $team_leader_id) $this->core->getQueries()->acceptTeamInvitation($team_id, $id);
            }
            $_SESSION['messages']['success'][] = "Created New Team {$team_id}";

            $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
            if (!FileUtils::createDir($gradeable_path)) {
                $this->core->addErrorMEssage("Failed to make folder for this assignment");
                $this->core->redirect($return_url);
            }

            $user_path = FileUtils::joinPaths($gradeable_path, $team_id);
            if (!FileUtils::createDir($user_path)) {
                $this->core->addErrorMEssage("Failed to make folder for this assignment for the team");
                $this->core->redirect($return_url);
            }

            $current_time = (new \DateTime('now', $this->core->getConfig()->getTimezone()))->format("Y-m-d H:i:sO")." ".$this->core->getConfig()->getTimezone()->getName();
            $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");
            $json = array("team_history" => array(array("action" => "admin_create", "time" => $current_time,
                                                        "admin_user" => $this->core->getUser()->getId(), "first_user" => $team_leader_id)));
            foreach($user_ids as $id) {
                if ($id !== $team_leader_id) {
                    $json["team_history"][] = array("action" => "admin_add_user", "time" => $current_time,
                                                    "admin_user" => $this->core->getUser()->getId(), "added_user" => $id);
                }
            }
        }
        else {
            $team_id = $_POST['edit_team_team_id'];
            $team = $this->core->getQueries()->getTeamById($team_id);
            if ($team === null) {
                $_SESSION['messages']['error'][] = "ERROR: {$team_id} is not a valid Team ID";
                $this->core->redirect($return_url);
            }

            $team_members = $team->getMembers();
            $add_user_ids = array();
            foreach($user_ids as $id) {
                if (!in_array($id, $team_members)) {
                    if ($this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $id) !== null) {
                        $_SESSION['messages']['error'][] = "ERROR: {$id} is already on a team";
                        $this->core->redirect($return_url);
                    }
                    $add_user_ids[] = $id;
                }
            }
            $remove_user_ids = array();
            foreach($team_members as $id) {
                if (!in_array($id, $user_ids)) {
                    $remove_user_ids[] = $id;
                }
            }

            $section = $_POST['section'] === "NULL" ? null : intval($_POST['section']);
            if ($gradeable->isGradeByRegistration()) {
                $this->core->getQueries()->updateTeamRegistrationSection($team_id, $section);
            }
            else {
                $this->core->getQueries()->updateTeamRotatingSection($team_id, $section);
            }
            foreach($add_user_ids as $id) {
                $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $id);
                $this->core->getQueries()->acceptTeamInvitation($team_id, $id);
            }
            foreach($remove_user_ids as $id) {
                $this->core->getQueries()->leaveTeam($team_id, $id);
            }
            $_SESSION['messages']['success'][] = "Updated Team {$team_id}";

            $current_time = (new \DateTime('now', $this->core->getConfig()->getTimezone()))->format("Y-m-d H:i:sO")." ".$this->core->getConfig()->getTimezone()->getName();
            $settings_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id, $team_id, "user_assignment_settings.json");
            $json = FileUtils::readJsonFile($settings_file);
            if ($json === false) {
                $this->core->addErrorMEssage("Failed to open settings file");
                $this->core->redirect($return_url);
            }
            foreach($add_user_ids as $id) {
                $json["team_history"][] = array("action" => "admin_add_user", "time" => $current_time,
                                                    "admin_user" => $this->core->getUser()->getId(), "added_user" => $id);
            }
            foreach($remove_user_ids as $id) {
                $json["team_history"][] = array("action" => "admin_remove_user", "time" => $current_time,
                                                    "admin_user" => $this->core->getUser()->getId(), "removed_user" => $id);
            }
        }
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            $this->core->addErrorMEssage("Failed to write to team history to settings file");
        }
        $this->core->redirect($return_url);
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
        $debug = "";
        $mark_modified = false;

        //makes sure only the users a grader is assigned to can be graded
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
            if (!in_array($user_id, $user_ids_to_grade)) {
                $_SESSION['messages']['error'][] = "You do not have permission to grade {$user_id}";
                return;
            }
        }

        foreach ($gradeable->getComponents() as $component) {
            if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            }
            else {
                //checks if a component has changed, i.e. a mark has been selected or unselected since last time
                //also checks if all the marks are false
                $index = 0;
                $temp_mark_selected = false;
                $all_false = true;
                foreach ($component->getMarks() as $mark) {
                    $temp_mark_selected = ($_POST['marks'][$index]['selected'] == 'true') ? true : false;
                    if($all_false === true && $temp_mark_selected === true) {
                        $all_false = false;
                    }
                    if($temp_mark_selected !== $mark->getHasMark()) {
                        $mark_modified = true;
                    }
                    $index++;
                }
                for ($i = $index; $i < $_POST['num_mark']; $i++) {
                    if ($_POST['marks'][$index]['selected'] == 'true') {
                        $all_false = false;
                        $mark_modified = true;
                        break;
                    }
                }

                if($all_false === true) {
                    if($_POST['custom_message'] != "" || floatval($_POST['custom_points']) != 0) {
                        $all_false = false;
                    }
                }
                
                if($mark_modified === false) {
                    if ($component->getComment() != $_POST['custom_message']) {
                        $mark_modified = true;
                    }
                    if ($component->getScore() != $_POST['custom_points']) {
                        $mark_modified = true;
                    }
                }

                //if no gradeable id exists adds one to the gradeable data
                if($gradeable->getGdId() == null) {
                    $gradeable->saveData2();
                }

                if($all_false === true) {
                    $component->deleteData($gradeable->getGdId());
                } else {
                    if($mark_modified === true) { //only change the component information is the mark was modified
                        if ($component->getGrader() === null || $_POST['overwrite'] === "true") {
                            $component->setGrader($this->core->getUser());
                        }     
                        
                        $component->setGradedVersion($_POST['active_version']);
                        $component->setGradeTime(new \DateTime('now', $this->core->getConfig()->getTimezone()));
                        $component->setComment($_POST['custom_message']);
                        $component->setScore($_POST['custom_points']);
                        $debug = $component->saveData($gradeable->getGdId());
                    }
                }
                
                $index = 0;
                // save existing marks
                foreach ($component->getMarks() as $mark) {
                    $mark->setPoints($_POST['marks'][$index]['points']);
                    $mark->setNote($_POST['marks'][$index]['note']);
                    $mark->setOrder($_POST['marks'][$index]['order']);
                    $mark->save();
                    $_POST['marks'][$index]['selected'] == 'true' ? $mark->setHasMark(true) : $mark->setHasMark(false);
                    if($all_false === false) {
                        $mark->saveData($gradeable->getGdId(), $component->getId());
                    }
                    $index++;
                }
                // create new marks
                for ($i = $index; $i < $_POST['num_mark']; $i++) {
                    $mark = new GradeableComponentMark($this->core);
                    $mark->setGcId($component->getId());
                    $mark->setPoints($_POST['marks'][$i]['points']);
                    $mark->setNote($_POST['marks'][$i]['note']);
                    $mark->setOrder($_POST['marks'][$i]['order']);
                    $mark_id = $mark->save();
                    $mark->setId($mark_id);
                    $_POST['marks'][$index]['selected'] == 'true' ? $mark->setHasMark(true) : $mark->setHasMark(false);
                    if($all_false === false) {
                        $mark->saveData($gradeable->getGdId(), $component->getId());
                    }
                }
                $mark_modified = ($mark_modified === true) ? "true" : "false";
            }
        }

        //generates the HW Report each time a mark is saved
        $hwReport = new HWReport($this->core);
        $hwReport->generateSingleReport($user_id, $gradeable_id);

        $response = array('status' => 'success', 'modified' => $mark_modified, 'all_false' => $all_false);
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    public function saveGradeableComment() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $_POST['user_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        $gradeable->setOverallComment($_POST['gradeable_comment']);
        $gradeable->saveData2();
        $hwReport = new HWReport($this->core);
        $hwReport->generateSingleReport($user_id, $gradeable_id);
    }

    public function getMarkDetails() {
        //gets all the details from the database of a mark to readd it to the view
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $_POST['user_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        foreach ($gradeable->getComponents() as $component) {
            if ($component->getId() != $_POST['gradeable_component_id']) {
                continue;
            }
            else {
                $return_data = array();
                foreach ($component->getMarks() as $mark) {
                    $temp_array = array();
                    $temp_array['score'] = $mark->getPoints();
                    $temp_array['note'] = $mark->getNote();
                    $temp_array['has_mark'] = $mark->getHasMark();
                    $return_data[] = $temp_array;
                }
                $temp_array = array();
                $temp_array['custom_score'] = $component->getScore();
                $temp_array['custom_note'] = $component->getComment();
                $return_data[] = $temp_array;
            }
        }

        $response = array('status' => 'success', 'data' => $return_data);
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

    public function getGradeableComment() {
        $gradeable_id = $_POST['gradeable_id'];
        $user_id = $_POST['user_id'];
        $gradeable = $this->core->getQueries()->getGradeable($gradeable_id, $user_id);
        $response = array('status' => 'success', 'data' => $gradeable->getOverallComment());
        $this->core->getOutput()->renderJson($response);
        return $response;
    }

}
