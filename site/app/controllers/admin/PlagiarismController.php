<?php
namespace app\controllers\admin;

use app\controllers\AbstractController;
use app\libraries\Core;
use app\libraries\Output;
use app\libraries\FileUtils;

class PlagiarismController extends AbstractController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'compare':
                $this->plagiarismCompare();
                break;
            case 'index':
                $this->plagiarismIndex();
                break;
            case 'configure_new_gradeable_for_plagiarism_form':
                $this->configureNewGradeableForPlagiarismForm();
                break;    
            case 'save_new_plagiarism_configuration':
                $this->saveNewPlagiarismConfiguration();
                break;
            case 'get_submission_concatinated':
            	$this->ajaxGetSubmissionConcatinated();
            	break;
            case 'get_matching_users':
            	$this->ajaxGetMatchingUsers();
            	break;     
            case 'get_matches_for_clicked_match':
                $this->ajaxGetMatchesForClickedMatch();
                break;
            case 're_run_plagiarism':
                $this->reRunPlagiarism();
                break; 
            case 'show_plagiarism_result':
                $this->showPlagiarismResult(); 
                break;        
            default:
                $this->core->getOutput()->addBreadcrumb('Lichen Plagiarism Detection');
                $this->plagiarismMainPage();
                break;
        }
    }

    public function plagiarismCompare() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $assignment = $_REQUEST['assignment'];
        $studenta = $_REQUEST['studenta'];
        $studentb = $_REQUEST['studentb'];
        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'plagiarismCompare', $semester, $course, $assignment, $studenta, $studentb);
    }

    public function plagiarismIndex() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $assignment = $_REQUEST['assignment'];
        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'plagiarismIndex', $semester, $course, $assignment);
    }

    public function plagiarismMainPage() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        
        if(!$this->core->getUser()->accessAdmin()) {
            die("Don't have permission to access page.");
        }

        $gradeables_with_plagiarism_result= $this->core->getQueries()->getAllGradeablesIdsAndTitles();
        foreach($gradeables_with_plagiarism_result as $i => $gradeable_id_title) {
            if(!file_exists("/var/local/submitty/courses/".$semester."/".$course."/lichen/ranking/".$gradeable_id_title['g_id'].".txt")) {
                unset($gradeables_with_plagiarism_result[$i]);
            }
        }
        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'plagiarismMainPage', $semester, $course, $gradeables_with_plagiarism_result);
        
    }

    public function showPlagiarismResult() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $gradeable_id= $_REQUEST['gradeable_id'];
        $gradeable_title= ($this->core->getQueries()->getGradeable($gradeable_id))->getName();
        $return_url= $this->core->buildUrl(array('component' => 'admin', 'semester' => $semester, 'course'=> $course,'page' => 'plagiarism'));
        // $lichen_saved_configs = array_diff(scandir("/var/local/submitty/courses/$semester/$course/lichen/config"), array('.', '..'));
        // foreach($lichen_saved_configs as $i=>$lichen_saved_config) {
        //     $config_gradeable_id = json_decode(file_get_contents("/var/local/submitty/courses/$semester/$course/lichen/config/".$lichen_saved_config), true)["gradeable"];
        //     unset($lichen_saved_configs[$i]);
        //     $lichen_saved_configs[$lichen_saved_config] = ($this->core->getQueries()->getGradeable($config_gradeable_id))->getName();
        // }
        if(!$this->core->getUser()->accessAdmin()) {
            die("Don't have permission to access page.");
        }

        $file_path= "/var/local/submitty/courses/".$semester."/".$course."/lichen/ranking/".$gradeable_id.".txt";
        if(!file_exists($file_path)) {
            $this->core->addErrorMessage("Ranking File do not exist. Rerun the plagiarism with same configuration or create new configuration");
            $this->core->redirect($return_url);
        }
        if(file_get_contents($file_path) == "") {
            $this->core->addSuccessMessage("There are no matches for the gradeable with current configuration");
            $this->core->redirect($return_url);   
        }
        $content =file_get_contents($file_path);
        $content = trim(str_replace(array("\r", "\n"), '', $content));
        $rankings = preg_split('/ +/', $content);
        $rankings = array_chunk($rankings,3);
        foreach($rankings as $i => $ranking) {
            array_push($rankings[$i], $this->core->getQueries()->getUserById($ranking[1])->getDisplayedFirstName());  
        }
        
        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'showPlagiarismResult', $semester, $course, $gradeable_id, $gradeable_title, $rankings);
        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'plagiarismPopUpToShowMatches'); 
        // $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'reRunPlagiarismForm', $semester, $course, $lichen_saved_configs);        
    }

    public function configureNewGradeableForPlagiarismForm() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $gradeable_ids = array_diff(scandir("/var/local/submitty/courses/$semester/$course/submissions/"), array('.', '..'));
        $gradeable_ids_titles= $this->core->getQueries()->getAllGradeablesIdsAndTitles();
        foreach($gradeable_ids_titles as $i => $gradeable_id_title) {
            if(!in_array($gradeable_id_title['g_id'], $gradeable_ids)) {
                unset($gradeable_ids_titles[$i]);
            }
        }

        $prior_term_gradeables = FileUtils::getGradeablesFromPriorTerm();

        $this->core->getOutput()->renderOutput(array('admin', 'Plagiarism'), 'configureNewGradeableForPlagiarismForm', $gradeable_ids_titles, $prior_term_gradeables);
    }

    public function saveNewPlagiarismConfiguration() {

        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $return_url = $this->core->buildUrl(array('component'=>'admin', 'page' => 'plagiarism', 'course' => $course, 'semester' => $semester, 'action' => 'configure_new_gradeable_for_plagiarism_form'));
        
        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $this->core->addErrorMessage("Invalid CSRF token");
            $this->core->redirect($return_url);
        }

        $prev_gradeable_number = $_POST['prior_term_gradeables_number'];
        $ignore_submission_number = $_POST['ignore_submission_number'];
        $gradeable_id = $_POST['gradeable_id'];
        $version_option = $_POST['version_option'];
        if ($version_option == "active_version") {
            $version_option = "active_version";
        }
        else {
            $version_option = "all_version";
        }

        $file_option = $_POST['file_option'];
        if ($file_option == "regrex_matching_files") {
            $file_option = "matching_regrex";
        }
        else {
            $file_option = "all";
        }
        if($file_option == "matching_regrex") {
            if( isset($_POST['regrex_to_select_files']) && $_POST['regrex_to_select_files'] !== '') {
                $regrex_for_selecting_files = $_POST['regrex_to_select_files'];
            }
            else {
                $this->core->addErrorMessage("No regrex provided for selecting files");
                $this->core->redirect($return_url);
            }    
        }

        $language= $_POST['language'];
        if( isset($_POST['threshold']) && $_POST['threshold'] !== '') {
            $threshold = $_POST['threshold'];
        }
        else {
            $this->core->addErrorMessage("No input provided for threshold");
            $this->core->redirect($return_url);
        } 
        if( isset($_POST['sequence_length']) && $_POST['sequence_length'] !== '') {
            $sequence_length = $_POST['sequence_length'];
        }
        else {
            $this->core->addErrorMessage("No input provided for sequence length");
            $this->core->redirect($return_url);
        } 

        $prev_term_gradeables = array();
        for( $i = 0; $i < $prev_gradeable_number; $i++ ) {
            if($_POST['prev_sem_'.$i]!= "" && $_POST['prev_course_'.$i]!= "" && $_POST['prev_gradeable_'.$i]!= "") {
                array_push($prev_term_gradeables, "/var/local/submitty/course/".$_POST['prev_sem_'.$i]."/".$_POST['prev_course_'.$i]."/submissions/".$_POST['prev_gradeable_'.$i]);
            }
        }

        $ignore_submissions = array();
        $ignore_submission_option = $_POST['ignore_submission_option'];
        if ($ignore_submission_option == "ignore") {
            for( $i = 0; $i < $ignore_submission_number; $i++ ) {
                if(isset($_POST['ignore_submission_'.$i]) && $_POST['ignore_submission_'.$i] !== '') {
                    array_push($ignore_submissions, $_POST['ignore_submission_'.$i]);
                }
            }    
        }
        
        $gradeable_path =  FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
        $provided_code_option = $_POST['provided_code_option'];
        if($provided_code_option == "code_provided") {
            $instructor_provided_code= true;
        }
        else {
            $instructor_provided_code= false;
        }

        if($instructor_provided_code == true) {
            if (empty($_FILES) || !isset($_FILES['provided_code_file'])) {
                $this->core->addErrorMessage("Upload failed: Instructor code not provided");
                $this->core->redirect($return_url);
            }
            if (!isset($_FILES['provided_code_file']['tmp_name']) || $_FILES['provided_code_file']['tmp_name'] == "") {
                $this->core->addErrorMessage("Upload failed: Instructor code not provided");
                $this->core->redirect($return_url);
            }

            else {
                $upload = $_FILES['provided_code_file'];
                $target_dir = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "lichen/provided_code", $gradeable_id);
                if (!is_dir($target_dir)) {
                    FileUtils::createDir($target_dir);    
                }
                $target_dir = FileUtils::joinPaths($target_dir, count(scandir($target_dir))-1);
                FileUtils::createDir($target_dir);
                $instructor_provided_code_path = $target_dir;

                if (FileUtils::getMimeType($upload["tmp_name"]) == "application/zip") {
                    $zip = new \ZipArchive();
                    $res = $zip->open($upload['tmp_name']);
                    if ($res === true) {
                        $zip->extractTo($target_dir);
                        $zip->close();
                    }
                    else {
                        FileUtils::recursiveRmdir($target_dir);
                        $error_message = ($res == 19) ? "Invalid or uninitialized Zip object" : $zip->getStatusString();
                        $this->core->addErrorMessage("Upload failed: {$error_message}");
                        $this->core->redirect($return_url);
                    }
                }
                else {
                    if (!@copy($upload['tmp_name'], FileUtils::joinPaths($target_dir, $upload['name']))) {
                        FileUtils::recursiveRmdir($target_dir);
                        $this->core->addErrorMessage("Upload failed: Could not copy file");
                        $this->core->redirect($return_url);
                    }
                }
            }
        }

        $config_dir = "/var/local/submitty/courses/".$semester."/".$course."/lichen/config/";
        $number_of_undone_jobs = count(array_diff(scandir($config_dir), array(".","..")));
        $json_file = "/var/local/submitty/courses/".$semester."/".$course."/lichen/config/".$gradeable_id."_".$number_of_undone_jobs.".json";
        $json_data = array("semester" =>    $semester,
                            "course" =>     $course,
                            "gradeable" =>  $gradeable_id,
                            "version" =>    $version_option,
                            "file_option" =>$file_option,
                            "language" =>   $language,
                            "threshold" =>  $threshold,
                            "sequence_length"=> $sequence_length,
                            "prev_term_gradeables" => $prev_term_gradeables,
                            "ignore_submissions" =>   $ignore_submissions,
                            "instructor_provided_code" =>   $instructor_provided_code,
                                        );

        if($file_option == "matching_regrex") {
            $json_data["regrex"] = $regrex_for_selecting_files;
        }
        if($instructor_provided_code == true) {
            $json_data["instructor_provided_code_path"] = $instructor_provided_code_path;   
        }

        if (file_put_contents($json_file, json_encode($json_data, JSON_PRETTY_PRINT)) === false) {
            $this->core->addErrorMessage("Failed to create configuration. Create the configuration again.");
            $this->core->redirect($return_url);
        }

        $ret = $this->enqueueRunLichenJob($gradeable_id);
        if($ret !== null) {
            $this->core->addErrorMessage("Failed to create configuration. Create the configuration again.");
            $this->core->redirect($return_url);   
        }

        $this->addSuccessMessage("Configuration created. Refresh after a while to view the plagiarism results.");
        $this->core->redirect($this->core->buildUrl(array('component'=>'admin', 'page' => 'plagiarism', 'course' => $course, 'semester' => $semester)));
    }

    private function enqueueRunLichenJob($gradeable_id) {
        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        
        $run_lichen_data = [
            "job" => "RunLichen",
            "semester" => $semester,
            "course" => $course,
            "gradeable" => $gradeable_id
        ];

        $run_lichen_job_file = "/var/local/submitty/daemon_job_queue/lichen__" . $semester . "__" . $course . "__" . $gradeable_id . ".json";
        
        if(file_exists($run_lichen_job_file) && !is_writable($run_lichen_job_file)) {
            return "Failed to create lichen job. Just rerun the plagiarism for the gradeable";
        }

        if(file_put_contents($run_lichen_job_file, json_encode($run_lichen_data, JSON_PRETTY_PRINT)) === false) {
            return "Failed to write lichen job file. Rerun the plagiarism for the gradeable";   
        }
        return null;
    }

    public function reRunPlagiarism() {
        $semester = $_REQUEST['semester'];
        $course = $_REQUEST['course'];
        $gradeable_id = $_REQUEST['gradeable_id'];
        $return_url = $this->core->buildUrl(array('component'=>'admin', 'page' => 'plagiarism', 'course' => $course, 'semester' => $semester));
        
        $ret = $this->enqueueRunLichenJob($gradeable_id);
        if($ret !== null) {
            $this->core->addErrorMessage($ret);
            $this->core->redirect($return_url);   
        }

        $this->addSuccessMessage("Refresh after a while to see re-run results.")
        $this->core->redirect($return_url);
    }

    public function ajaxGetSubmissionConcatinated() {
    	$gradeable_id = $_REQUEST['gradeable_id'];
    	$user_id_1 =$_REQUEST['user_id_1'];
    	$version_user_1 = $_REQUEST['version_user_1'];
    	
        $course_path = $this->core->getConfig()->getCoursePath();
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);

        $return="";
        $active_version_user_1 = (string)$this->core->getQueries()->getGradeable($gradeable_id, $user_id_1)->getActiveVersion();
        $file_path= $course_path."/lichen/ranking/".$gradeable_id.".txt";
        if(!file_exists($file_path)) {
			$return = array('error' => 'Ranking file not exists.');
        	$return = json_encode($return);
        	echo($return);
        	return;
        }
    	$content =file_get_contents($file_path);
    	$content = trim(str_replace(array("\r", "\n"), '', $content));
    	$rankings = preg_split('/ +/', $content);
		$rankings = array_chunk($rankings,3);
		foreach($rankings as $ranking) {
			if($ranking[1] == $user_id_1) {
				$max_matching_version = $ranking[2];
			}
		}
        if($version_user_1 == "max_matching") {
        	$version_user_1 = $max_matching_version;
        }
        $all_versions_user_1 = array_diff(scandir($course_path."/submissions/".$gradeable_id."/".$user_id_1), array(".", "..", "user_assignment_settings.json"));

        $file_name= $course_path."/lichen/concatenated/".$gradeable_id."/".$user_id_1."/".$version_user_1."/submission.concatenated";
        $data="";
    	if(($this->core->getUser()->accessAdmin()) && (file_exists($file_name))) {
    		if(isset($_REQUEST['user_id_2']) && !empty($_REQUEST['user_id_2']) && isset($_REQUEST['version_user_2']) && !empty($_REQUEST['version_user_2'])) {
    			$color_info = $this->getColorInfo($course_path, $gradeable_id, $user_id_1, $version_user_1, $_REQUEST['user_id_2'], $_REQUEST['version_user_2'] , '1');	
    		}
    		else {
    			$color_info = $this->getColorInfo($course_path, $gradeable_id, $user_id_1, $version_user_1, '', '', '1');
    		}
    		$data= array('display_code1'=> htmlentities($this->getDisplayForCode($file_name, $color_info)), 'code_version_user_1' => $version_user_1, 'max_matching_version' => $max_matching_version, 'active_version_user_1' => $active_version_user_1, 'all_versions_user_1' => $all_versions_user_1, 'ci'=> $color_info);
        }
        else {
        	$return = array('error' => 'User 1 submission.concatinated for specified version not found.');
        	$return = json_encode($return);
        	echo($return);
        	return;
        }
        if(isset($_REQUEST['user_id_2']) && !empty($_REQUEST['user_id_2']) && isset($_REQUEST['version_user_2']) && !empty($_REQUEST['version_user_2'])) {
        	$file_name= $course_path."/lichen/concatenated/".$gradeable_id."/".$_REQUEST['user_id_2']."/".$_REQUEST['version_user_2']."/submission.concatenated";

	    	if(($this->core->getUser()->accessAdmin()) && (file_exists($file_name))) {
	    		$color_info = $this->getColorInfo($course_path, $gradeable_id, $user_id_1, $version_user_1, $_REQUEST['user_id_2'], $_REQUEST['version_user_2'], '2');
	  			$data['display_code2'] = htmlentities($this->getDisplayForCode($file_name, $color_info));
	        }   
	        else {
	        	$return = array('error' => 'User 2 submission.concatinated for matching version not found.');
		    	$return = json_encode($return);
		    	echo($return);
		    	return;
	        }	
        }

       	$return= json_encode($data);
    	echo($return);	
    }

    public function getColorInfo($course_path, $gradeable_id, $user_id_1, $version_user_1, $user_id_2, $version_user_2, $codebox) {
    	$color_info = array();
    	
		$file_path= $course_path."/lichen/matches/".$gradeable_id."/".$user_id_1."/".$version_user_1."/matches.json";
        if (!file_exists($file_path)) {
        	return $color_info;
        }
        else {
        	$matches = json_decode(file_get_contents($file_path), true);
        	$file_path= $course_path."/lichen/tokenized/".$gradeable_id."/".$user_id_1."/".$version_user_1."/tokens.json";
        	$tokens_user_1 = json_decode(file_get_contents($file_path), true);
        	if($user_id_2 != "") {
        		$file_path= $course_path."/lichen/tokenized/".$gradeable_id."/".$user_id_2."/".$version_user_2."/tokens.json";
        		$tokens_user_2 = json_decode(file_get_contents($file_path), true);
        	}
	    	foreach($matches as $match) {
	    		$start_pos =$tokens_user_1[$match["start"]-1]["char"];
	    		$start_line= $tokens_user_1[$match["start"]-1]["line"];
	    		$end_pos =$tokens_user_1[$match["end"]-1]["char"];
	    		$end_line= $tokens_user_1[$match["end"]-1]["line"];
	    		$end_value =$tokens_user_1[$match["end"]-1]["value"];
	    		if($match["type"] == "match") {
	    			$orange_color = false;
	    			if($user_id_2 != "") {
		    			foreach($match['others'] as $i=>$other) {
	    					if($other["username"] == $user_id_2) {
	    						$orange_color =true;
                                $user_2_index_in_others=$i;
	    					}
	    				}	
	    			}
	    			if($codebox == "1" && $orange_color) {
                        $onclick_function = 'getMatchesForClickedMatch("'.$gradeable_id.'", event,'.$match["start"].','.$match["end"].',"code_box_1","orange",this);';
                        $name = '{"start":'.$match["start"].', "end":'.$match["end"].'}';
	    				if(array_key_exists($start_line, $color_info) && array_key_exists($start_pos, $color_info[$start_line])) {
			    			$color_info[$start_line][$start_pos] .= "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffa500;cursor: pointer;'>";		
			    		}
			    		else {	
			    			$color_info[$start_line][$start_pos] = "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffa500;cursor: pointer;'>";
			    		}
			    		if(array_key_exists($end_line, $color_info) && array_key_exists($end_pos+strlen(strval($end_value)), $color_info[$end_line])) {
			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>".$color_info[$end_line][$end_pos+strlen(strval($end_value))];
			    		}
			    		else {
			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>";
			    		}
	    			}
	    			else if($codebox == "1" && !$orange_color) {
                        $onclick_function = 'getMatchesForClickedMatch("'.$gradeable_id.'", event,'.$match["start"].','.$match["end"].',"code_box_1","yellow",this);';
                        $name = '{"start":'.$match["start"].', "end":'.$match["end"].'}';
	    				if(array_key_exists($start_line, $color_info) && array_key_exists($start_pos, $color_info[$start_line])) {
			    			$color_info[$start_line][$start_pos] .= "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffff00;cursor: pointer;'>";		
			    		}
			    		else {	
			    			$color_info[$start_line][$start_pos] = "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffff00;cursor: pointer;'>";
			    		}
			    		if(array_key_exists($end_line, $color_info) && array_key_exists($end_pos+strlen(strval($end_value)), $color_info[$end_line])) {
			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>".$color_info[$end_line][$end_pos+strlen(strval($end_value))];
			    		}
			    		else {
			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>";
			    		}
	    			}
	    			else if($codebox == "2" && $user_id_2 !="" && $orange_color) {
                        foreach($match['others'][$user_2_index_in_others]['matchingpositions'] as $user_2_matchingposition) {
    	    				$start_pos =$tokens_user_2[$user_2_matchingposition["start"]-1]["char"];
    			    		$start_line= $tokens_user_2[$user_2_matchingposition["start"]-1]["line"];
    			    		$end_pos =$tokens_user_2[$user_2_matchingposition["end"]-1]["char"];
    			    		$end_line= $tokens_user_2[$user_2_matchingposition["end"]-1]["line"];
    			    		$end_value =$tokens_user_2[$user_2_matchingposition["end"]-1]["value"];
                            $onclick_function = 'getMatchesForClickedMatch("'.$gradeable_id.'", event,'.$match["start"].','.$match["end"].',"code_box_2","orange", this);';
                            $name = '{"start":'.$user_2_matchingposition["start"].', "end":'.$user_2_matchingposition["end"].'}';
    	    				if(array_key_exists($start_line, $color_info) && array_key_exists($start_pos, $color_info[$start_line])) {
    			    			$color_info[$start_line][$start_pos] .= "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffa500;cursor: pointer;'>";		
    			    		}
    			    		else {	
    			    			$color_info[$start_line][$start_pos] = "<span name='{$name}' onclick='{$onclick_function}' style='background-color:#ffa500;cursor: pointer;'>";
    			    		}
    			    		if(array_key_exists($end_line, $color_info) && array_key_exists($end_pos+strlen(strval($end_value)), $color_info[$end_line])) {
    			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>".$color_info[$end_line][$end_pos+strlen(strval($end_value))];
    			    		}
    			    		else {
    			    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>";
    			    		}
                        }    
	    			}	
	    				
	    		}
	    		else if($match["type"] == "common" && $codebox == "1") {
	    			if(array_key_exists($start_line, $color_info) && array_key_exists($start_pos, $color_info[$start_line])) {		
		    			$color_info[$start_line][$start_pos] .= "<span style='background-color:#cccccc'>";
		    		}
		    		else {
		    			$color_info[$start_line][$start_pos] = "<span style='background-color:#cccccc'>";	
		    		}
		    		if(array_key_exists($end_line, $color_info) && array_key_exists($end_pos+strlen(strval($end_value)), $color_info[$end_line])) {
		    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>".$color_info[$end_line][$end_pos+strlen(strval($end_value))];
		    		}
		    		else {
		    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>";
		    		}
	    		}
	    		else if($match["type"] == "provided"  && $codebox == "1") {
	    			if(array_key_exists($start_line, $color_info) && array_key_exists($start_pos, $color_info[$start_line])) {
		    			$color_info[$start_line][$start_pos] .= "<span style='background-color:#b5e3b5'>";
		    		}
		    		else {
		    			$color_info[$start_line][$start_pos] = "<span style='background-color:#b5e3b5'>";	
		    		}
		    		if(array_key_exists($end_line, $color_info) && array_key_exists($end_pos+strlen(strval($end_value)), $color_info[$end_line])) {
		    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>".$color_info[$end_line][$end_pos+strlen(strval($end_value))];
		    		}
		    		else {
		    			$color_info[$end_line][$end_pos+strlen(strval($end_value))] = "</span>";
		    		}	
	    		}
	    	}
        } 	
    	foreach($color_info as $i=>$color_info_for_line) {
	    	krsort($color_info[$i]);
    	}
    	return $color_info;
    }

    public function getDisplayForCode($file_name , $color_info){
    	$lines= file($file_name); 
    	foreach($lines as $i=>$line) {
    		$lines[$i] = rtrim($line, "\n");
    	}
	    $html = "<div style='background:white;border:none;' class='diff-container'><div class='diff-code'>";
	    $last_line_unmatched_span="";
	    $present_line_unmatched_span="";
	    
	    for ($i = 0; $i < count($lines); $i++) {
	        $j = $i + 1;
	        $html .= "<div style='white-space: nowrap;'>";
	        $html .= "<span class='line_number'>". $j ."</span>";
	        $html .= "<span class='line_code'>";
	        
	        if(array_key_exists($i+1, $color_info)) {
		        if($color_info[$i+1][max(array_keys($color_info[$i+1]))] != "</span>") {
	    			$lines[$i] = substr_replace($lines[$i], "</span>", strlen($lines[$i]), 0);
	    			$present_line_unmatched_span = str_replace("</span>", "", $color_info[$i+1][max(array_keys($color_info[$i+1]))]);
	    		}
	    		else {
	    			$present_line_unmatched_span = ""; 
	    		}
		        foreach ($color_info[$i+1] as $c => $color_info_for_position) {
		    		$lines[$i] = substr_replace($lines[$i], $color_info_for_position, $c-1, 0);
		    	}

	    		if((strpos($color_info[$i+1][min(array_keys($color_info[$i+1]))],"</span>") == 0)) {
	    			$lines[$i] = substr_replace($lines[$i], $last_line_unmatched_span, 0, 0);	
	    		}
		    }
		    else if($last_line_unmatched_span != "") {
	    		$lines[$i] = substr_replace($lines[$i], "</span>", strlen($lines[$i]), 0);
	    		$lines[$i] = substr_replace($lines[$i], $last_line_unmatched_span, 0, 0);	
	    	}
	    	$last_line_unmatched_span = $present_line_unmatched_span;
	        $html .= $lines[$i];
	        $html .= "</span></div>";
	    }
	    $j++;
	    $html .= "</div></div>";
	    return $html;
	}

    public function ajaxGetMatchingUsers() {
    	$gradeable_id = $_REQUEST['gradeable_id'];
    	$user_id =$_REQUEST['user_id_1'];
    	$version = $_REQUEST['version_user_1'];
        $course_path = $this->core->getConfig()->getCoursePath();
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);

        $return = array();
        $error="";
        $file_path= $course_path."/lichen/ranking/".$gradeable_id.".txt";
        if(!file_exists($file_path)) {
			$return = array('error' => 'Ranking file not exists.');
        	$return = json_encode($return);
        	echo($return);
        	return;
        }
    	$content =file_get_contents($file_path);
    	$content = trim(str_replace(array("\r", "\n"), '', $content));
    	$rankings = preg_split('/ +/', $content);
		$rankings = array_chunk($rankings,3);
		foreach($rankings as $ranking) {
			if($ranking[1] == $user_id) {
				$max_matching_version = $ranking[2];
			}
		}
        if($version == "max_matching") {
        	$version = $max_matching_version;
        }
        $file_path= $course_path."/lichen/matches/".$gradeable_id."/".$user_id."/".$version."/matches.json";
        if (!file_exists($file_path)) {
        	echo("no_match_for_this_version");
        }
        else {
	        $content = json_decode(file_get_contents($file_path), true);
	    	foreach($content as $match) {
	    		if($match["type"] == "match") {
	    			foreach ($match["others"] as $match_info) {
	    				if(!in_array(array($match_info["username"],$match_info["version"]), $return )) {
	    					array_push($return, array($match_info["username"],$match_info["version"]));
	    				}
	    			}
	    		}
	    	}
	    	foreach($return as $i => $match_user) {
    			array_push($return[$i], $this->core->getQueries()->getUserById($match_user[0])->getDisplayedFirstName());  
    		}
	    	$return = json_encode($return);
	        echo($return);
	    }    
    }

    public function ajaxGetMatchesForClickedMatch() {
        $gradeable_id = $_REQUEST['gradeable_id'];
        $user_id_1 =$_REQUEST['user_id_1'];
        $version_user_1 = $_REQUEST['version_user_1'];
        $course_path = $this->core->getConfig()->getCoursePath();
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);

        $return = array();

        $file_path= $course_path."/lichen/matches/".$gradeable_id."/".$user_id_1."/".$version_user_1."/matches.json";
        if (!file_exists($file_path)) {
            echo(json_encode(array("error"=>"user 1 matches.json does not exists")));
        }
        else {
            $content = json_decode(file_get_contents($file_path), true);
            foreach($content as $match) {
                if($match["start"] == $_REQUEST['start'] && $match["end"] == $_REQUEST['end']) {
                    foreach ($match["others"] as $match_info) {
                        $matchingpositions= array();
                        foreach($match_info['matchingpositions'] as $matchingpos) {
                            array_push($matchingpositions, array("start"=> $matchingpos["start"] , "end"=>$matchingpos["end"]));
                        }
                        array_push($return, array($match_info["username"],$match_info["version"], $matchingpositions));
                    }
                }
            }
            $return = json_encode($return);
            echo($return);
        }    
    }

}
