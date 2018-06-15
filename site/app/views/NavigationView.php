<?php
namespace app\views;
use \app\libraries\GradeableType;
use app\models\Gradeable;
use app\libraries\FileUtils;


class NavigationView extends AbstractView {

    const gradeableSections = [
        [
            //What title is displayed to the user for each category
            "title" => "FUTURE &nbsp;&nbsp; <em>visible only to Instructors</em>",
            //What bootstrap button the student button will be. Information about bootstrap buttons can be found here:
            //https://www.w3schools.com/bootstrap/bootstrap_buttons.asp
            "button_type_submission" => "btn-default",
            //What bootstrap button the instructor/TA button will be
            "button_type_grading" => "btn-default",
            //The general text of the button under the category
            //It is general since the text could change depending if the user submitted something or not and other factors.
            "prefix" => "ALPHA SUBMIT"
        ],
        [
            "title" => "BETA &nbsp;&nbsp; <em>open for testing by TAs</em>",
            "button_type_submission" => "btn-default",
            "button_type_grading" => "btn-default",
            "prefix" => "BETA SUBMIT"
        ],
        [
            "title" => "OPEN",
            "button_type_submission" => "btn-primary" ,
            "button_type_grading" => "btn-default" ,
            "prefix" => "SUBMIT"
        ],
        [
            "title" => "PAST DUE",
            "button_type_submission" => "btn-danger",
            "button_type_grading" => "btn-default",
            "prefix" => "LATE SUBMIT"
        ],
        [
            "title" => "CLOSED &nbsp;&nbsp; <em>being graded by TA/Instructor</em>",
            "button_type_submission" => "btn-default",
            "button_type_grading" => "btn-primary",
            "prefix" => "VIEW SUBMISSION"
        ],
        [
            "title" => "GRADES AVAILABLE",
            "button_type_submission" => 'btn-success',
            "button_type_grading" => 'btn-danger',
            "prefix" => "VIEW GRADE"
        ]
    ];

    const sectionMap = [
        "FUTURE" => 0,
        "BETA" => 1,
        "OPEN" => 2,
        "CLOSED" => 3,
        "ITEMS BEING GRADED" => 4,
        "GRADED" => 5
    ];

    public function noAccessCourse() {
        return <<<HTML
<div class="content">
   You don't have access to {$this->core->getDisplayedCourseName()}. If you think this is mistake,
   please contact your instructor to gain access.
</div>
HTML;
    }
    public function showGradeables($sections_to_list) {
        $site_url = $this->core->getConfig()->getSiteUrl();
        $return = "";

        // ======================================================================================
        // DISPLAY CUSTOM BANNER (typically used for exam seating assignments)
        // note: placement of this information this may eventually be re-designed
        // ======================================================================================
        $message_file_path = $this->core->getConfig()->getCoursePath() . "/reports/summary_html/" . $this->core->getUser()->getId() . "_message.html";
        $message_file_contents = "";
        if (file_exists($message_file_path)) {
            $message_file_contents = file_get_contents($message_file_path);
        }
        $display_custom_message = $this->core->getConfig()->displayCustomMessage();
        if ($display_custom_message && $message_file_contents != "") {
            $return .= <<<HTML
<div class="content">
   {$message_file_contents}
</div>
HTML;
        }
        $return .= <<<HTML
<div class="content">
    <div class="nav-buttons">
HTML;
        // ======================================================================================
        // CREATE NEW GRADEABLE BUTTON -- only visible to instructors
        // ======================================================================================
        if ($this->core->getUser()->accessAdmin()) {
            $return .= <<<HTML
        <a class="btn btn-primary" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'view_gradeable_page'))}">New Gradeable</a>
        <a class="btn btn-primary" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'gradeable', 'action' => 'upload_config'))}">Upload Config & Review Build Output</a>

HTML;
        }
        // ======================================================================================
        // FORUM BUTTON
        // ====================================================================================== 

        if ($this->core->getConfig()->isForumEnabled()) {
            $return .= <<<HTML
            <a class="btn btn-primary" href="{$this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread'))}">Discussion Forum</a>
HTML;
        }
        // ======================================================================================
        // GRADES SUMMARY BUTTON
        // ======================================================================================
        $display_rainbow_grades_summary = $this->core->getConfig()->displayRainbowGradesSummary();
        if ($display_rainbow_grades_summary) {
            $return .= <<<HTML
        <a class="btn btn-primary" href="{$this->core->buildUrl(array('component' => 'student', 'page' => 'rainbow'))}">View Grades</a>
HTML;
        }
        $return .= <<<HTML
    </div>
HTML;
        // ======================================================================================
        // INDEX OF ALL GRADEABLES
        // ======================================================================================
        $return .= <<<HTML
    <table class="gradeable_list" style="width:100%;">
HTML;
        //What bootstrap button the student button will be. Information about bootstrap buttons can be found here:
        //https://www.w3schools.com/bootstrap/bootstrap_buttons.asp
        $title_to_button_type_submission = array(
            "FUTURE" => "btn-default",
            "BETA" => "btn-default",
            "OPEN" => "btn-primary",
            "CLOSED" => "btn-danger",
            "ITEMS BEING GRADED" => "btn-default",
            "GRADED" => 'btn-success'
        );

        $found_assignment = false;
        foreach ($sections_to_list as $list_section => $gradeable_list) {
            /** @var Gradeable[] $gradeable_list */

            $display_section = $list_section;
            $index = self::sectionMap[$display_section];

            // temporary: want to make future - only visible to
            //  instructor (not released for submission to graders)
            //  and future - grader preview
            //  (released to graders for submission)
            //if ($title == "FUTURE" && !$this->core->getUser()->accessAdmin()) {
            $found_assignment = true;
            $lower_title = str_replace(" ", "_", strtolower($display_section));
            $return .= <<<HTML
        <tr class="bar"><td colspan="10"></td></tr>
        <tr class="colspan nav-title-row" id="{$lower_title}"><td colspan="4">{$this::gradeableSections[$index]["title"]}</td></tr>
        <tbody id="{$lower_title}_tbody">
HTML;
            $btn_title_save = $title_to_button_type_submission[$display_section];
            foreach ($gradeable_list as $gradeable_id => $gradeable) {
                if (!$this->core->getUser()->accessGrading()) {

                    if ($gradeable->getActiveVersion() === 0 && $gradeable->getCurrentVersionNumber() != 0) {
                        $submission_status = array(
                            "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                            "AUTOGRADE" => ""
                        );
                    } else if ($gradeable->getActiveVersion() === 0 && $gradeable->getCurrentVersionNumber() === 0) {
                        $submission_status = array(
                            "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                            "AUTOGRADE" => ""
                        );
                    } else {
                        if ($gradeable->getTotalNonHiddenNonExtraCreditPoints() == array() && ($list_section != "GRADED" && $list_section != "ITEMS BEING GRADED")) {
                            $submission_status = array(
                                "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                                "AUTOGRADE" => ""
                            );
                        } else if ($gradeable->getTotalNonHiddenNonExtraCreditPoints() != array() && ($list_section != "GRADED" && $list_section != "ITEMS BEING GRADED")) {
                            $autograde_points_earned = $gradeable->getGradedNonHiddenPoints();
                            $autograde_points_total = $gradeable->getTotalNonHiddenNonExtraCreditPoints();
                            $submission_status = array(
                                "SUBMITTED" => "",
                                "AUTOGRADE" => "<em style='font-size: .8em;'></em><br>"
                            );
                        } else if ($gradeable->getTotalNonHiddenNonExtraCreditPoints() != array() && ($list_section == "GRADED" || $list_section == "ITEMS BEING GRADED")) {
                            $submission_status = array(
                                "SUBMITTED" => "",
                                "AUTOGRADE" => ""
                            );
                        } else {
                            $autograde_points_earned = $gradeable->getGradedNonHiddenPoints();
                            $autograde_points_total = $gradeable->getTotalNonHiddenNonExtraCreditPoints();
                            $submission_status = array(
                                "SUBMITTED" => "",
                                //    "AUTOGRADE" => "<em style='font-size: .8em;'>(" . $autograde_points_earned . "/" . $autograde_points_total . ")</em><br>"
                            );

                        }
                    }
                } else { //don't show submission_status to instructors
                    $submission_status = array(
                        "SUBMITTED" => "<br>",
                        "AUTOGRADE" => ""
                    );
                }
                $display_section = $list_section;
                $title_to_button_type_submission[$list_section] = $btn_title_save;
                if ($gradeable->getActiveVersion() < 1) {
                    if ($display_section == "GRADED" || $display_section == "ITEMS BEING GRADED") {
                        $display_section = "CLOSED";
                    }
                }
                if ($gradeable->useTAGrading() && $gradeable->beenTAgraded() && $gradeable->getUserViewedDate() !== null) {
                    $title_to_button_type_submission['GRADED'] = "btn-default";
                }
                /** @var Gradeable $gradeable */
                $date = new \DateTime("now", $this->core->getConfig()->getTimezone());
                $time = " @ H:i";
                $gradeable_grade_range = 'PREVIEW GRADING<br><span style="font-size:smaller;">(grading opens ' . $gradeable->getGradeStartDate()->format("m/d/Y{$time}") . ")</span>";
                if ($gradeable->getType() == GradeableType::ELECTRONIC_FILE) {
                    if ($gradeable->useTAGrading()) {
                        $gradeable_grade_range = 'PREVIEW GRADING<br><span style="font-size:smaller;">(grading opens ' . $gradeable->getGradeStartDate()->format("m/d/Y{$time}") . "</span>)";
                    } else {
                        $gradeable_grade_range = 'VIEW SUBMISSIONS<br><span style="font-size:smaller;">(<em>no manual grading</em></span>)';
                    }
                }
                $temp_regrade_text = "";
                if ($list_section == 'ITEMS BEING GRADED') {
                    $gradeable_grade_range = 'GRADE<br><span style="font-size:smaller;">(grades due ' . $gradeable->getGradeReleasedDate()->format("m/d/Y{$time}") . '</span>)';
                    $temp_regrade_text = 'REGRADE<br><span style="font-size:smaller;">(grades due ' . $gradeable->getGradeReleasedDate()->format("m/d/Y{$time}") . '</span>)';
                }
                if ($list_section == 'GRADED') {
                    if ($gradeable->getType() == GradeableType::ELECTRONIC_FILE) {
                        if ($gradeable->useTAGrading()) {
                            $gradeable_grade_range = 'GRADE';
                        } else {
                            $gradeable_grade_range = 'VIEW SUBMISSIONS';
                        }
                    } else {
                        $gradeable_grade_range = 'REGRADE';
                    }
                }
                if (trim($gradeable->getInstructionsURL()) != '') {
                    $gradeable_title = '<label>' . $gradeable->getName() . '</label><a class="external" href="' . $gradeable->getInstructionsURL() . '" target="_blank"><i style="margin-left: 10px;" class="fa fa-external-link"></i></a>';
                } else {
                    if ($gradeable->getType() == GradeableType::ELECTRONIC_FILE) {
                        # no_team_flag is true if there are no teams else false. Note deleting a gradeable is not allowed is no_team_flag is false. 
                        $no_teams_flag = true;
                        $all_teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable_id);
                        if (!empty($all_teams)) {
                            $no_teams_flag = false;
                        }
                        # no_submission_flag is true if there are no submissions for assignement else false. Note deleting a gradeable is not allowed is no_submission_flag is false.
                        $no_submission_flag = true;
                        $semester = $this->core->getConfig()->getSemester();
                        $course = $this->core->getConfig()->getCourse();
                        $submission_path = "/var/local/submitty/courses/" . $semester . "/" . $course . "/" . "submissions/" . $gradeable_id;
                        if (is_dir($submission_path)) {
                            $no_submission_flag = false;
                        }
                        if ($this->core->getUser()->accessAdmin() && $no_submission_flag && $no_teams_flag) {
                            $form_action = $this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'delete_gradeable', 'id' => $gradeable_id));
                            $gradeable_title = <<<HTML
                    <label>{$gradeable->getName()}</label>&nbsp;
                    <i class="fa fa-times" style="color:red; cursor:pointer;" aria-hidden="true" onclick='newDeleteGradeableForm("{$form_action}","{$gradeable->getName()}");'></i>
HTML;
                        } else {
                            $gradeable_title = '<label>' . $gradeable->getName() . '</label>';
                        }
                    } else if (($gradeable->getType() == GradeableType::NUMERIC_TEXT) || (($gradeable->getType() == GradeableType::CHECKPOINTS))) {
                        if ($this->core->getUser()->accessAdmin() && $this->core->getQueries()->getNumUsersGraded($gradeable_id) === 0) {
                            $form_action = $this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'delete_gradeable', 'id' => $gradeable_id));
                            $gradeable_title = <<<HTML
                    <label>{$gradeable->getName()}</label>&nbsp;
                    <i class="fa fa-times" style="color:red; cursor:pointer;" aria-hidden="true" onclick='newDeleteGradeableForm("{$form_action}","{$gradeable->getName()}");'></i>
HTML;
                        } else {
                            $gradeable_title = '<label>' . $gradeable->getName() . '</label>';
                        }

                    }
                }
                if ($gradeable->getType() == GradeableType::ELECTRONIC_FILE) {
                    $display_date = ($display_section == "FUTURE" || $display_section == "BETA") ? "<span style=\"font-size:smaller;\">(opens " . $gradeable->getOpenDate()->format("m/d/Y{$time}") . "</span>)" : "<span style=\"font-size:smaller;\">(due " . $gradeable->getDueDate()->format("m/d/Y{$time}") . "</span>)";
                    if ($display_section == "GRADED" || $display_section == "ITEMS BEING GRADED") {
                        $display_date = "";
                    }
                    if ($gradeable->getActiveVersion() >= 1 && $display_section == "OPEN") { //if the user submitted something on time
                        $button_text = "RESUBMIT {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    } else if ($gradeable->getActiveVersion() >= 1 && $list_section == "CLOSED") { //if the user submitted something past time
                        $button_text = "LATE RESUBMIT {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    } else if (($list_section == "GRADED" || $list_section == "ITEMS BEING GRADED") && $gradeable->getActiveVersion() < 1) {
                        //to change the text to overdue submission if nothing was submitted on time
                        $button_text = "OVERDUE SUBMISSION {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    } //when there is no TA grade and due date passed
                    else if ($list_section == "GRADED" && $gradeable->useTAGrading() && !$gradeable->beenTAgraded()) {
                        $button_text = "TA GRADE NOT AVAILABLE {$submission_status["SUBMITTED"]} 
                        	{$submission_status["AUTOGRADE"]} {$display_date}";
                        $title_to_button_type_submission['GRADED'] = "btn-default";
                    } else if ($list_section == "GRADED" && !$gradeable->useTAGrading()) {
                        $button_text = "{$this::gradeableSections[$index]["prefix"]} {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                        $title_to_button_type_submission['GRADED'] = "btn-default";
                    } // electronic gradeable with no ta grading should never be green
                    else {
                        $button_text = "{$this::gradeableSections[$index]["prefix"]} {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    }
                    if ($gradeable->hasConfig()) {
                        //calculate the point percentage
                        if ($gradeable->getTotalNonHiddenNonExtraCreditPoints() == 0) {
                            $points_percent = 0;
                        } else {
                            $points_percent = $gradeable->getGradedNonHiddenPoints() / $gradeable->getTotalNonHiddenNonExtraCreditPoints();
                        }
                        $points_percent = $points_percent * 100;
                        if ($points_percent > 100) {
                            $points_percent = 100;
                        }
                        if (($gradeable->isTeamAssignment() && $gradeable->getTeam() === null) && (!$this->core->getUser()->accessAdmin())) {
                            $gradeable_open_range = <<<HTML
                <a class="btn {$title_to_button_type_submission[$display_section]} btn-nav" disabled>
                     MUST BE ON A TEAM TO SUBMIT<br>{$display_date}
                </a>
HTML;
                        } else if ($gradeable->beenAutograded() && $gradeable->getTotalNonHiddenNonExtraCreditPoints() != 0 && $gradeable->getActiveVersion() >= 1
                            && $list_section == "CLOSED" && $points_percent >= 50) {
                            $gradeable_open_range = <<<HTML
                 <a class="btn btn-default btn-nav" href="{$site_url}&component=student&gradeable_id={$gradeable_id}">
                     {$button_text}
                 </a>
HTML;
                        } else {
                            $gradeable_open_range = <<<HTML
                 <a class="btn {$title_to_button_type_submission[$display_section]} btn-nav" href="{$site_url}&component=student&gradeable_id={$gradeable_id}">
                     {$button_text}
                 </a>
HTML;
                        }


                        //If the button is autograded and has been submitted once, give a progress bar.
                        if ($gradeable->beenAutograded() && $gradeable->getTotalNonHiddenNonExtraCreditPoints() != 0 && $gradeable->getActiveVersion() >= 1
                            && ($list_section == "CLOSED" || $list_section == "OPEN")) {
                            //from https://css-tricks.com/css3-progress-bars/
                            if ($points_percent >= 50) {
                                $gradeable_open_range .= <<<HTML
								<style type="text/css">	
									.meter1 { 
										height: 10px; 
										position: relative;
										background: rgb(224,224,224);
										padding: 0px;
									}
									.meter1 > span {
							  			display: block;
							  			height: 100%;
							  			background-color: rgb(92,184,92);
							  			position: relative;
									}
								</style>	
								<div class="meter1">
  									<span style="width: {$points_percent}%"></span>
								</div>				 
HTML;
                            } else {
                                $gradeable_open_range .= <<<HTML
								<style type="text/css">	
								.meter2 { 
									height: 10px; 
									position: relative;
									background: rgb(224,224,224);
									padding: 0px;
								}
								.meter2 > span {
								  	display: block;
								  	height: 100%;
								  	background-color: rgb(92,184,92);
								  	position: relative;
								}
								</style>	
HTML;
                                //Give them an imaginary progress point
                                if ($gradeable->getGradedNonHiddenPoints() == 0) {
                                    $gradeable_open_range .= <<<HTML
									<div class="meter2">
	  								<span style="width: 2%"></span>
									</div>					 
HTML;
                                } else {
                                    $gradeable_open_range .= <<<HTML
									<div class="meter2">
	  								<span style="width: {$points_percent}%"></span>
								</div>					 
HTML;
                                }
                            }
                        }
                        //This code is taken from the ElectronicGraderController, it used to calculate the TA percentage.
                        $gradeable_core = $this->core->getQueries()->getGradeable($gradeable_id);
                        $total_users = array();
                        $no_team_users = array();
                        $graded_components = array();
                        $graders = array();
                        if ($gradeable_core->isGradeByRegistration()) {
                            if (!$this->core->getUser()->accessFullGrading()) {
                                $sections = $this->core->getUser()->getGradingRegistrationSections();
                            } else {
                                $sections = $this->core->getQueries()->getRegistrationSections();
                                foreach ($sections as $i => $section) {
                                    $sections[$i] = $section['sections_registration_id'];
                                }
                            }
                            $section_key = 'registration_section';
                            if (count($sections) > 0) {
                                $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
                            }
                        } else {
                            if (!$this->core->getUser()->accessFullGrading()) {
                                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable_core->getId(), $this->core->getUser()->getId());
                            } else {
                                $sections = $this->core->getQueries()->getRotatingSections();
                                foreach ($sections as $i => $section) {
                                    $sections[$i] = $section['sections_rotating_id'];
                                }
                            }
                            $section_key = 'rotating_section';
                            if (count($sections) > 0) {
                                $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable_core->getId(), $sections);
                            }
                        }
                        if (count($sections) > 0) {
                            if ($gradeable_core->isTeamAssignment()) {
                                $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($gradeable_core->getId(), $sections, $section_key);
                                $no_team_users = $this->core->getQueries()->getUsersWithoutTeamByGradingSections($gradeable_core->getId(), $sections, $section_key);
                                $graded_components = $this->core->getQueries()->getGradedComponentsCountByTeamGradingSections($gradeable_core->getId(), $sections, $section_key);
                            } else {
                                $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
                                $no_team_users = array();
                                $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($gradeable_core->getId(), $sections, $section_key, $gradeable_core->isTeamAssignment());
                            }
                        }

                        $num_components = $this->core->getQueries()->getTotalComponentCount($gradeable_core->getId());
                        $num_submitted = $this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($gradeable_core->getId(), $sections, $section_key);
                        $sections = array();
                        if (count($total_users) > 0) {
                            foreach ($num_submitted as $key => $value) {
                                $sections[$key] = array(
                                    'total_components' => $value * $num_components,
                                    'graded_components' => 0,
                                    'graders' => array()
                                );
                                if ($gradeable_core->isTeamAssignment()) {
                                    $sections[$key]['no_team'] = $no_team_users[$key];
                                }
                                if (isset($graded_components[$key])) {
                                    // Clamp to total components if unsubmitted assigment is graded for whatever reason
                                    $sections[$key]['graded_components'] = min(intval($graded_components[$key]), $sections[$key]['total_components']);
                                }
                                if (isset($graders[$key])) {
                                    $sections[$key]['graders'] = $graders[$key];
                                }
                            }
                        }
                        $components_graded = 0;
                        $components_total = 0;
                        foreach ($sections as $key => $section) {
                            if ($key === "NULL") {
                                continue;
                            }
                            $components_graded += $section['graded_components'];
                            $components_total += $section['total_components'];
                        }
                        $TA_percent = 0;
                        if ($components_total == 0) {
                            $TA_percent = 0;
                        } else {
                            $TA_percent = $components_graded / $components_total;
                            $TA_percent = $TA_percent * 100;
                        }
                        //if $TA_percent is 100, change the text to REGRADE
                        if ($TA_percent == 100 && $list_section == 'ITEMS BEING GRADED') {
                            $gradeable_grade_range = <<<HTML
                            <a class="btn btn-default btn-nav" \\
                            href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id))}">
                            {$temp_regrade_text}</a>
HTML;
                        } else if ($TA_percent == 100 && $list_section == 'GRADED') {
                            $gradeable_grade_range = <<<HTML
                            <a class="btn btn-default btn-nav" \\
                            href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id))}">
                            REGRADE</a>
HTML;
                        } else {
                            $button_type = self::gradeableSections[self::sectionMap[$list_section]]["button_type_grading"];
                            if (!$gradeable->useTAGrading()) {
                                $button_type = 'btn-default';
                            }
                            $gradeable_grade_range = <<<HTML
                            <a class="btn {$button_type} btn-nav" \\
                            href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable_id))}">
                            {$gradeable_grade_range}</a>
HTML;
                        }
                        //Give the TAs a progress bar too                        
                        if (($list_section == "GRADED" || $list_section == "ITEMS BEING GRADED") && $components_total != 0 && $gradeable->useTAGrading()) {
                            $gradeable_grade_range .= <<<HTML
                            <style type="text/css"> 
                                .meter3 { 
                                    height: 10px; 
                                    position: relative;
                                    background: rgb(224,224,224);
                                    padding: 0px;
                                }
                                .meter3 > span {
                                    display: block;
                                    height: 100%;
                                    background-color: rgb(92,184,92);
                                    position: relative;
                                }
                            </style>    
                            <div class="meter3">
                                <span style="width: {$TA_percent}%"></span>
                            </div>               
HTML;
                        }
                    } else {
                        $gradeable_open_range = <<<HTML
                 <button class="btn {$title_to_button_type_submission[$display_section]}" style="width:100%;" disabled>
                     Need to run BUILD_{$this->core->getConfig()->getCourse()}.sh
                 </button>
HTML;
                        $gradeable_grade_range = <<<HTML
                <button class="btn {$this::gradeableSections[$this::sectionMap[$display_section]]["button_type_grading"]}" style="width:100%;" disabled>
                    Need to run BUILD_{$this->core->getConfig()->getCourse()}.sh
                </button>
HTML;
                    }
                } else {
                    $gradeable_open_range = '';
                    if ($gradeable->getType() == GradeableType::CHECKPOINTS) {
                        $gradeable_grade_range = <<<HTML
                <a class="btn {$this::gradeableSections[$this::sectionMap[$display_section]]["button_type_grading"]} btn-nav" \\
                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'simple', 'action' => 'lab', 'g_id' => $gradeable_id))}">
                {$gradeable_grade_range}</a>
HTML;
                    } elseif ($gradeable->getType() == GradeableType::NUMERIC_TEXT) {
                        $gradeable_grade_range = <<<HTML
                <a class="btn {$this::gradeableSections[$this::sectionMap[$display_section]]["button_type_grading"]} btn-nav" \\
                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'simple', 'action' => 'numeric', 'g_id' => $gradeable_id))}">
                {$gradeable_grade_range}</a>
HTML;
                    }
                }
                // Team management button, only visible on team assignments
                $gradeable_team_range = '';
                if (($gradeable->isTeamAssignment())) {
                    if ($gradeable->getTeam() === null) {
                        if ($date->format('Y-m-d H:i:s') < $gradeable->getTeamLockDate()->format('Y-m-d H:i:s')) {
                            $button_type = 'btn-primary';
                            $display_date = "<br><span style=\"font-size:smaller;\">(teams lock {$gradeable->getTeamLockDate()->format("m/d/Y{$time}")})</span>";
                        } else {
                            $button_type = 'btn-danger';
                            $display_date = '';
                        }
                        $button_text = 'CREATE TEAM';
                        $teams = $this->core->getQueries()->getTeamsByGradeableId($gradeable->getId());
                        foreach ($teams as $t) {
                            if ($t->sentInvite($this->core->getUser()->getId())) {
                                $button_text = 'CREATE/JOIN TEAM';
                                break;
                            }
                        }
                    } else {
                        if ($date->format('Y-m-d H:i:s') < $gradeable->getTeamLockDate()->format('Y-m-d H:i:s')) {
                            $button_type = 'btn-primary';
                            $display_date = "<br><span style=\"font-size:smaller;\">(teams lock {$gradeable->getTeamLockDate()->format("m/d/Y{$time}")})</span>";
                            $button_text = 'MANAGE TEAM';
                        } else {
                            $button_type = 'btn-default';
                            $display_date = '';
                            $button_text = 'VIEW TEAM';
                        }
                    }
                    $gradeable_team_range = <<<HTML
                <a class="btn {$button_type}" style="width:100%;"
                href="{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable_id, 'page' => 'team'))}">
                {$button_text}{$display_date}
HTML;
                }
                if ($this->core->getUser()->accessAdmin()) {
                    $admin_button = <<<HTML
                <a class="btn btn-default" style="width:100%;" \\
                href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'edit_gradeable_page', 'id' => $gradeable_id))}">
                    Edit
                </a>
HTML;
                } else {
                    $admin_button = "";
                }
                if (($this->core->getUser()->accessAdmin()) && ($gradeable->getType() == GradeableType::ELECTRONIC_FILE)) {
                    $admin_rebuild_button = <<<HTML
                <a class="btn btn-default" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'rebuild_assignement', 'gradeable_id' => $gradeable_id))}">
                    Rebuild
                </a>
HTML;
                } else {
                    $admin_rebuild_button = "";
                }
                if ($list_section === "ITEMS BEING GRADED" && $this->core->getUser()->accessAdmin()) {
                    $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable_id, 'quick_link_action' => 'release_grades_now'))}">
                        RELEASE GRADES NOW
                        </a>
HTML;
                } else if ($list_section === "FUTURE" && $this->core->getUser()->accessAdmin()) {
                    $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable_id, 'quick_link_action' => 'open_ta_now'))}">
                        OPEN TO TAS NOW
                        </a>
HTML;
                } else if ($list_section === "BETA" && $this->core->getUser()->accessAdmin()) {
                    if ($gradeable->getType() == GradeableType::ELECTRONIC_FILE) {
                        $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable_id, 'quick_link_action' => 'open_students_now'))}">
                        OPEN NOW
                        </a>
HTML;
                    } else {
                        $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable_id, 'quick_link_action' => 'open_grading_now'))}">
                        OPEN TO GRADING NOW
                        </a>
HTML;
                    }
                } else if ($list_section === "CLOSED" && $this->core->getUser()->accessAdmin()) {
                    $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable_id, 'quick_link_action' => 'open_grading_now'))}">
                        OPEN TO GRADING NOW
                        </a>
HTML;
                } else {
                    $quick_links = "";
                }
                if (!$this->core->getUser()->accessGrading() && !$gradeable->getPeerGrading()) {
                    $gradeable_grade_range = "";
                }
                $return .= <<<HTML
            <tr class="gradeable_row">
                <td>{$gradeable_title}</td>
                <td style="padding: 20px;">{$gradeable_team_range}</td>
                <td style="padding: 20px;">{$gradeable_open_range}</td>
HTML;
                if (($this->core->getUser()->accessGrading() && ($this->core->getUser()->getGroup() <= $gradeable->getMinimumGradingGroup())) || ($this->core->getUser()->getGroup() === 4 && $gradeable->getPeerGrading())) {
                    $return .= <<<HTML
                <td style="padding: 20px;">{$gradeable_grade_range}</td>
                <td style="padding: 20px;">{$admin_button}</td>
                <td style="padding: 20px;">{$admin_rebuild_button}</td>
                <td style="padding: 20px;">{$quick_links}</td>
HTML;
                }
                $return .= <<<HTML
            </tr>
HTML;
            }
            $return .= '</tbody><tr class="colspan"><td colspan="10" style="border-bottom:2px black solid;"></td></tr>';
        }
        if ($found_assignment == false) {
            $return .= <<<HTML
    <div class="container">
    <p>There are currently no assignments posted.  Please check back later.</p>
    </div></table></div>
HTML;
            return $return;
        }
        $return .= <<<HTML
                            </table>
                        </div>
HTML;
        return $return;
    }

    public function deleteGradeableForm() {
        return $this->core->getOutput()->renderTwigTemplate("navigation/DeleteGradeableForm.twig");
    }
}
