<?php

namespace app\views;

use \app\libraries\GradeableType;
use app\models\Gradeable;

class NavigationView extends AbstractView {
    public function noAccessCourse() {
        return <<<HTML
<div class="content">
   You don't have access to {$this->core->getConfig()->getCourseName()}. If you think this is mistake,
   please contact your instructor to gain access.
</div>
HTML;
    }

    public function showGradeables($sections_to_list) {
        $ta_base_url = $this->core->getConfig()->getTaBaseUrl();
        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        $site_url = $this->core->getConfig()->getSiteUrl();
        $return = "";


        // ======================================================================================
        // DISPLAY CUSTOM BANNER (typically used for exam seating assignments)
        // note: placement of this information this may eventually be re-designed
        // ======================================================================================
        $message_file_path = $this->core->getConfig()->getCoursePath()."/reports/summary_html/".$this->core->getUser()->getId()."_message.html";
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

        <!--<button class="btn btn-primary" onclick="batchImportJSON('{$ta_base_url}/account/submit/admin-gradeable.php?course={$course}&semester={$semester}&action=import', '{$this->core->getCsrfToken()}');">Import From JSON</button> -->
HTML;
        }
        // ======================================================================================
        // GRADES SUMMARY BUTTON
        // ======================================================================================
        $display_iris_grades_summary = $this->core->getConfig()->displayIrisGradesSummary();
        if ($display_iris_grades_summary) {
        $return .= <<<HTML
        <a="btn btn-primary" href="{$this->core->buildUrl(array('component' => 'student', 'page' => 'rainbow'))}">View Grades</a>
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
		//What the title is suppose to display to the user as the title for each category
        $title_to_category_title = array(
            "FUTURE" => "FUTURE &nbsp;&nbsp; <em>visible only to Instructors</em>",
            "BETA" => "BETA &nbsp;&nbsp; <em>open for testing by TAs</em>",
            "OPEN" => "OPEN",
            "CLOSED" => "PAST DUE",
            "ITEMS BEING GRADED" => "CLOSED &nbsp;&nbsp; <em>being graded by TA/Instructor</em>",
            "GRADED" => "GRADES AVAILABLE"
        );
        //What bootstrap button the student button will be. Information about bootstrap buttons can be found here:
        //https://www.w3schools.com/bootstrap/bootstrap_buttons.asp
        $title_to_button_type_submission = array(
            "FUTURE" => "btn-default",
            "BETA" => "btn-default",
            "OPEN" => "btn-primary" ,
            "CLOSED" => "btn-danger",
            "ITEMS BEING GRADED" => "btn-default",
            "GRADED" => 'btn-success'
        );
        //What bootstrap button the instructor/TA button will be
        $title_to_button_type_grading = array(
            "FUTURE" => "btn-default",
            "BETA" => "btn-default",
            "OPEN" => "btn-default" ,
            "CLOSED" => "btn-default",
            "ITEMS BEING GRADED" => "btn-primary",
            "GRADED" => 'btn-danger');
        //The general text of the button under the category
        //It is general since the text could change depending if the user submitted something or not and other factors.
        $title_to_prefix = array(
            "FUTURE" => "ALPHA SUBMIT",
            "BETA" => "BETA SUBMIT",
            "OPEN" => "SUBMIT",
            "CLOSED" => "LATE SUBMIT",
            "ITEMS BEING GRADED" => "VIEW SUBMISSION",
            "GRADED" => "VIEW GRADE"
        );
        
        $found_assignment = false;

        foreach ($sections_to_list as $title => $gradeable_list) {
            /** @var Gradeable[] $gradeable_list */
            // temporary: want to make future - only visible to
            //  instructor (not released for submission to graders)
            //  and future - grader preview
            //  (released to graders for submission)
            //if ($title == "FUTURE" && !$this->core->getUser()->accessAdmin()) {

            if (($title === "FUTURE" || $title === "BETA") && !$this->core->getUser()->accessGrading()) {
                continue;
            }

            // count the # of electronic gradeables in this category
            $electronic_gradeable_count = 0;
            foreach ($gradeable_list as $gradeable => $g_data) {
                if ($g_data->getType() == GradeableType::ELECTRONIC_FILE) {
                    $electronic_gradeable_count++;
                    continue;
                }
            }

            // if there are no gradeables, or if its a student and no electronic upload gradeables, don't show this category
            if (count($gradeable_list) == 0 ||
                ($electronic_gradeable_count == 0 && !$this->core->getUser()->accessGrading())) {
                continue;
            } else {
                $found_assignment = true;
            }

            $lower_title = str_replace(" ", "_", strtolower($title));
            $return .= <<<HTML
        <tr class="bar"><td colspan="10"></td></tr>
        <tr class="colspan nav-title-row" id="{$lower_title}"><td colspan="4">{$title_to_category_title[$title]}</td></tr>
        <tbody id="{$lower_title}_tbody">
HTML;
            $title_save = $title;
            $btn_title_save = $title_to_button_type_submission[$title];
            foreach ($gradeable_list as $gradeable => $g_data) {
                if (!$this->core->getUser()->accessGrading()){
                    
                    if ($g_data->getActiveVersion() === 0 && $g_data->getCurrentVersionNumber() != 0){
                        $submission_status = array(
                            "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                            "AUTOGRADE" => ""
                        );
                    }
                    else if ($g_data->getActiveVersion() === 0 && $g_data->getCurrentVersionNumber() === 0){
                        $submission_status = array(
                            "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                            "AUTOGRADE" => ""
                        );
                    }
                    else{

                        if ($g_data->getTotalNonHiddenNonExtraCreditPoints() == array() && ($title_save != "GRADED" && $title_save != "ITEMS BEING GRADED")){
                            $submission_status = array(
                                "SUBMITTED" => "<em style='font-size: .8em;'></em><br>",
                                "AUTOGRADE" => ""
                            ); 
                        }
                        else if ($g_data->getTotalNonHiddenNonExtraCreditPoints() != array() && ($title_save != "GRADED" && $title_save != "ITEMS BEING GRADED")){
                            $autograde_points_earned = $g_data->getGradedNonHiddenPoints(); 
                            $autograde_points_total = $g_data->getTotalNonHiddenNonExtraCreditPoints();
                            $submission_status = array(
                                "SUBMITTED" => "",
                                "AUTOGRADE" => "<em style='font-size: .8em;'></em><br>"
                            );
                        }
                        else if ($g_data->getTotalNonHiddenNonExtraCreditPoints() != array() && ($title_save == "GRADED" || $title_save == "ITEMS BEING GRADED")){
                            $submission_status = array(
                                "SUBMITTED" => "",
                                "AUTOGRADE" => ""
                            );
                        }
                        else{
                            $autograde_points_earned = $g_data->getGradedNonHiddenPoints(); 
                            $autograde_points_total = $g_data->getTotalNonHiddenNonExtraCreditPoints();
                            $submission_status = array(
                                "SUBMITTED" => "",
                            //    "AUTOGRADE" => "<em style='font-size: .8em;'>(" . $autograde_points_earned . "/" . $autograde_points_total . ")</em><br>"
                            );
                            
                        }
                    }
                }
                else{ //don't show submission_status to instructors
                    $submission_status = array(
                        "SUBMITTED" => "<br>",
                        "AUTOGRADE" => ""
                    );
                }

                $title = $title_save;
                $title_to_button_type_submission[$title_save] = $btn_title_save;

                // student users should only see electronic gradeables -- NOTE: for now, we might change this design later
                if ($g_data->getType() != GradeableType::ELECTRONIC_FILE && !$this->core->getUser()->accessGrading()) {
                    continue;
                }
                if ($g_data->getActiveVersion() < 1){
                    if ($title == "GRADED" || $title == "ITEMS BEING GRADED"){
                        $title = "CLOSED";
                    }
                }

                if ($g_data->beenAutograded() && $g_data->beenTAgraded() && $g_data->getUserViewedDate() !== null){
                    $title_to_button_type_submission['GRADED'] = "btn-default";
                }

                /** @var Gradeable $g_data */
                $date = new \DateTime("now", $this->core->getConfig()->getTimezone());
                if($g_data->getTAViewDate()->format('Y-m-d H:i:s') > $date->format('Y-m-d H:i:s') && !$this->core->getUser()->accessAdmin()){
                    continue;
                }
                $time = " @ H:i";

                $gradeable_grade_range = 'VIEW FORM<br><span style="font-size:smaller;">(grading opens '.$g_data->getGradeStartDate()->format("m/d/Y{$time}").")</span>";
                if ($g_data->getType() == GradeableType::ELECTRONIC_FILE) {
                  $gradeable_grade_range = 'VIEW SUBMISSIONS<br><span style="font-size:smaller;">(grading opens '.$g_data->getGradeStartDate()->format("m/d/Y{$time}")."</span>)";
                }
                $temp_regrade_text = "";
                if ($title_save=='ITEMS BEING GRADED') {
                  $gradeable_grade_range = 'GRADE<br><span style="font-size:smaller;">(grades due '.$g_data->getGradeReleasedDate()->format("m/d/Y{$time}").'</span>)';
                  $temp_regrade_text = 'REGRADE<br><span style="font-size:smaller;">(grades due '.$g_data->getGradeReleasedDate()->format("m/d/Y{$time}").'</span>)';
                }
                if ($title_save=='GRADED') {
                  $gradeable_grade_range = 'GRADE';
                }

                if(trim($g_data->getInstructionsURL())!=''){
                    $gradeable_title = '<label>'.$g_data->getName().'</label><a class="external" href="'.$g_data->getInstructionsURL().'" target="_blank"><i style="margin-left: 10px;" class="fa fa-external-link"></i></a>';
                }
                else{
                    $gradeable_title = '<label>'.$g_data->getName().'</label>';
                }

                if ($g_data->getType() == GradeableType::ELECTRONIC_FILE){

                    $display_date = ($title == "FUTURE" || $title == "BETA") ? "<span style=\"font-size:smaller;\">(opens ".$g_data->getOpenDate()->format("m/d/Y{$time}")."</span>)" : "<span style=\"font-size:smaller;\">(due ".$g_data->getDueDate()->format("m/d/Y{$time}")."</span>)";
                    if ($title=="GRADED" || $title=="ITEMS BEING GRADED") { $display_date = ""; }
                    if ($g_data->getActiveVersion() >= 1 && $title == "OPEN") { //if the user submitted something on time
                        $button_text = "RESUBMIT {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    }
                    else if($g_data->getActiveVersion() >= 1 && $title_save == "CLOSED") { //if the user submitted something past time
                        $button_text = "LATE RESUBMIT {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    }
                    else if(($title_save == "GRADED" || $title_save == "ITEMS BEING GRADED") && $g_data->getActiveVersion() < 1) {
                    	//to change the text to overdue submission if nothing was submitted on time
                        $button_text = "OVERDUE SUBMISSION {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    } //when there is no TA grade and due date passed
                    else if($title_save == "GRADED" && $g_data->useTAGrading() && !$g_data->beenTAgraded()) { 
                        $button_text = "TA GRADE NOT AVAILABLE {$submission_status["SUBMITTED"]} 
                        	{$submission_status["AUTOGRADE"]} {$display_date}";
                        $title_to_button_type_submission['GRADED'] = "btn-default";
                    }
                    else {
                    	$button_text = "{$title_to_prefix[$title]} {$submission_status["SUBMITTED"]} {$submission_status["AUTOGRADE"]} {$display_date}";
                    }
                    if ($g_data->hasConfig()) {
                        //calculate the point percentage
                    	if ($g_data->getTotalNonHiddenNonExtraCreditPoints() == 0) {
                    		$points_percent = 0;
                    	}
                    	else {
                    		$points_percent = $g_data->getGradedNonHiddenPoints() / $g_data->getTotalNonHiddenNonExtraCreditPoints();
                    	}                    	
						$points_percent = $points_percent * 100;
						if ($points_percent > 100) { 
                            $points_percent = 100; 
                        }
						if (($g_data->beenAutograded() && $g_data->getTotalNonHiddenNonExtraCreditPoints() != 0 && $g_data->getActiveVersion() >= 1
							&& $title_save == "CLOSED" && $points_percent >= 50) || ($g_data->beenAutograded() && $g_data->getTotalNonHiddenNonExtraCreditPoints() == 0 && $g_data->getActiveVersion() >= 1)) {
						$gradeable_open_range = <<<HTML
                 <a class="btn btn-default btn-nav" href="{$site_url}&component=student&gradeable_id={$gradeable}">
                     {$button_text}
                 </a>
HTML;
						}
						else { 
							$gradeable_open_range = <<<HTML
                 <a class="btn {$title_to_button_type_submission[$title]} btn-nav" href="{$site_url}&component=student&gradeable_id={$gradeable}">
                     {$button_text}
                 </a>
HTML;
						}

                        
                        
						//If the button is autograded and has been submitted once, give a progress bar.
						if ($g_data->beenAutograded() && $g_data->getTotalNonHiddenNonExtraCreditPoints() != 0 && $g_data->getActiveVersion() >= 1
							&& ($title_save == "CLOSED" || $title_save == "OPEN"))
						{							
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
							}
							else {
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
								if ($g_data->getGradedNonHiddenPoints() == 0) {
									$gradeable_open_range .= <<<HTML
									<div class="meter2">
	  								<span style="width: 2%"></span>
									</div>					 
HTML;
								} 
								else {
									$gradeable_open_range .= <<<HTML
									<div class="meter2">
	  								<span style="width: {$points_percent}%"></span>
								</div>					 
HTML;
								}
							}
						}
                        //This code is taken from the ElectronicGraderController, it used to calculate the TA percentage.
                        if ($g_data->useTAGrading()) {
                            $gradeable_core = $this->core->getQueries()->getGradeable($gradeable);
                            $total = array();
                            $graded = array();
                            $graders = array();
                            $sections = array();
                            if ($gradeable_core->isGradeByRegistration()) {
                                if(!$this->core->getUser()->accessFullGrading()){
                                    $sections = $this->core->getUser()->getGradingRegistrationSections();
                                }
                                if (count($sections) > 0 || $this->core->getUser()->accessFullGrading()) {
                                    $total = $this->core->getQueries()->getTotalUserCountByRegistrationSections($sections);
                                    $graded = $this->core->getQueries()->getGradedUserCountByRegistrationSections($gradeable_core->getId(), $sections);
                                    $graders = $this->core->getQueries()->getGradersForRegistrationSections($sections);
                                }
                            }
                            else {
                                if(!$this->core->getUser()->accessFullGrading()){
                                    $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable, $this->core->getUser()->getId());
                                }
                                if (count($sections) > 0 || $this->core->getUser()->accessFullGrading()) {
                                    $total = $this->core->getQueries()->getTotalUserCountByRotatingSections($sections);
                                    $graded = $this->core->getQueries()->getGradedUserCountByRotatingSections($gradeable, $sections);
                                    $graders = $this->core->getQueries()->getGradersForRotatingSections($gradeable_core->getId(), $sections);
                                }
                            }

                            $sections = array();
                            if (count($total) > 0) {
                                foreach ($total as $key => $value) {
                                    $sections[$key] = array(
                                        'total_students' => $value,
                                        'graded_students' => 0,
                                        'graders' => array()
                                    );
                                    if (isset($graded[$key])) {
                                        $sections[$key]['graded_students'] = intval($graded[$key]);
                                    }
                                    if (isset($graders[$key])) {
                                        $sections[$key]['graders'] = $graders[$key];
                                    }
                                }
                            }
                            $students_graded = 0;
                            $students_total = 0;
                            foreach ($sections as $key => $section) {
                                if ($key === "NULL") {
                                    continue;
                                }
                                $students_graded += $section['graded_students'];
                                $students_total += $section['total_students']; 
                            }
                            $TA_percent = 0;
                            if ($students_total == 0) { $TA_percent = 0; }
                            else {
                                $TA_percent = $students_graded / $students_total;
                                $TA_percent = $TA_percent * 100;
                            }
                            //if $TA_percent is 100, change the text to REGRADE
                            if ($TA_percent == 100 && $title_save=='ITEMS BEING GRADED') {
                                $gradeable_grade_range = <<<HTML
                                <a class="btn btn-default btn-nav" \\
                                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable))}">
                                {$temp_regrade_text}</a>
HTML;
                            } else if ($TA_percent == 100 && $title_save=='GRADED') {
                                $gradeable_grade_range = <<<HTML
                                <a class="btn btn-default btn-nav" \\
                                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable))}">
                                REGRADE</a>
HTML;
                            } else {
                                $gradeable_grade_range = <<<HTML
                                <a class="btn {$title_to_button_type_grading[$title_save]} btn-nav" \\
                                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'gradeable_id' => $gradeable))}">
                                {$gradeable_grade_range}</a>
HTML;
                            }                           
                            //Give the TAs a progress bar too                        
                            if (($title_save == "GRADED" || $title_save == "ITEMS BEING GRADED") && $students_total != 0) {
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
                        }
                        else {
                            $gradeable_grade_range = "";
                        }
                    }
                    else {
                        $gradeable_open_range = <<<HTML
                 <button class="btn {$title_to_button_type_submission[$title]}" style="width:100%;" disabled>
                     Need to run BUILD_{$this->core->getConfig()->getCourse()}.sh
                 </button>
HTML;
                        $gradeable_grade_range = <<<HTML
                <button class="btn {$title_to_button_type_grading[$title]}" style="width:100%;" disabled>
                    Need to run BUILD_{$this->core->getConfig()->getCourse()}.sh
                </button>
HTML;
                    }
                }
                else{
                    $gradeable_open_range = '';
                    //<!--onclick="location.href='{$ta_base_url}/account/account-checkpoints-gradeable.php?course={$course}&semester={$semester}&g_id={$gradeable}'">-->
                    if($g_data->getType() == GradeableType::CHECKPOINTS){
                       $gradeable_grade_range = <<<HTML
                <a class="btn {$title_to_button_type_grading[$title]} btn-nav" \\
                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'simple', 'action' => 'lab', 'g_id' => $gradeable))}">
                {$gradeable_grade_range}</a>
HTML;
                    }
                    // onclick="location.href='{$ta_base_url}/account/account-numerictext-gradeable.php?course={$course}&semester={$semester}&g_id={$gradeable}'">
                    elseif($g_data->getType() == GradeableType::NUMERIC_TEXT){
                        $gradeable_grade_range = <<<HTML
                <a class="btn {$title_to_button_type_grading[$title]} btn-nav" \\
                href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'simple', 'action' => 'numeric', 'g_id' => $gradeable))}">
                {$gradeable_grade_range}</a>
HTML;
                    }
                }

                // Team management button, only visible on team assignments
                $gradeable_team_range = '';
                $admin_team_list = '';
                if (($g_data->isTeamAssignment()) && (($title == "OPEN") || ($title == "BETA"))) {
                    $gradeable_team_range = <<<HTML
                <a class="btn {$title_to_button_type_submission[$title]}" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'student', 'gradeable_id' => $gradeable, 'page' => 'team'))}"> MANAGE TEAM
                </a>
HTML;
                }
                    // View teams button, only visible to instructors on team assignments
                if (($this->core->getUser()->accessAdmin()) && ($g_data->isTeamAssignment())) {
                    $admin_team_list .= <<<HTML
                <a class="btn btn-default" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'team_list', 'gradeable_id' => $gradeable))}"> View Teams
                </a>
HTML;
                }

                if ($this->core->getUser()->accessAdmin()) {
                    $admin_button = <<<HTML
                <a class="btn btn-default" style="width:100%;" \\
                href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'edit_gradeable_page', 'id' => $gradeable))}">
                    Edit
                </a>
HTML;
                }
                else {
                    $admin_button = "";
                }

                if ($title_save === "ITEMS BEING GRADED" && $this->core->getUser()->accessAdmin()) {
                    $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable, 'quick_link_action' => 'release_grades_now'))}">
                        RELEASE GRADES NOW
                        </a>
HTML;
                } else if ($title_save === "FUTURE" && $this->core->getUser()->accessAdmin()) {
                    $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable, 'quick_link_action' => 'open_ta_now'))}">
                        OPEN TO TAS NOW
                        </a>
HTML;
                }
                else if($title_save === "BETA" && $this->core->getUser()->accessAdmin()) {
                    if($g_data->getType() == GradeableType::ELECTRONIC_FILE) {
                        $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable, 'quick_link_action' => 'open_students_now'))}">
                        OPEN NOW
                        </a>
HTML;
                    } else {
                        $quick_links = <<<HTML
                        <a class="btn btn-primary" style="width:100%;" href="{$this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'quick_link', 'id' => $gradeable, 'quick_link_action' => 'open_grading_now'))}">
                        OPEN TO GRADING NOW
                        </a>
HTML;
                    }
                } else {
                    $quick_links = "";
                }

                if (!$this->core->getUser()->accessGrading()) {
                    $gradeable_grade_range = "";

                }

                $return .= <<<HTML
            <tr class="gradeable_row">
                <td>{$gradeable_title}</td>
                <td style="padding: 20px;">{$gradeable_team_range}</td>
                <td style="padding: 20px;">{$admin_team_list}</td>
                <td style="padding: 20px;">{$gradeable_open_range}</td>
HTML;
                if ($this->core->getUser()->accessGrading() && ($this->core->getUser()->getGroup() <= $g_data->getMinimumGradingGroup())) {
                    $return .= <<<HTML
                <td style="padding: 20px;">{$gradeable_grade_range}</td>
                <td style="padding: 20px;">{$admin_button}</td>
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
}
