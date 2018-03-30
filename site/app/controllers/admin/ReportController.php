<?php

namespace app\controllers\admin;

use app\controllers\AbstractController;
use app\libraries\Core;
use app\libraries\FileUtils;
use app\libraries\GradeableType;
use app\libraries\Output;
use app\models\Gradeable;
use app\models\HWReport;
use app\models\GradeSummary;
use app\models\LateDaysCalculation;

/*
use app\report\HWReportView;
use app\report\CSVReportView;
use app\report\GradeSummaryView;
*/
class ReportController extends AbstractController {
    public function run() {
        switch($_REQUEST['action']) {
            case 'reportpage':
                $this->showReportPage();
                break;
            case 'csv':
                $this->generateCSVReport();
                break;
            case 'summary':
                $this->generateGradeSummaries();
                break;
            case 'hwreport':
                $this->generateHWReports();
                break;
            default:
                $this->core->getOutput()->showError("Invalid action request for controller ".get_class($this));
                break;
        }
    }
    
    public function showReportPage() {
        $this->core->getOutput()->renderOutput(array('admin', 'Report'), 'showReportUpdates');
    }
    
    public function generateCSVReport() {
        $students = $this->core->getQueries()->getAllUsers();
        $student_ids = array_map(function($stu) {return $stu->getId();}, $students);
        $gradeables = $this->core->getQueries()->getGradeables(null, $student_ids);
        $results = array();
        $results['header_model'] = array('First' => 'First Name', 'Last'=> 'Last Name', 'reg_section' => 'Registration Section');
        $ldu = new LateDaysCalculation($this->core);
        foreach($gradeables as $gradeable) {
            $student_id = $gradeable->getUser()->getId();
            if(!isset($results[$student_id])) {
                $results[$student_id] = array('First'=>$gradeable->getUser()->getDisplayedFirstName(), 'Last' => $gradeable->getUser()->getLastName(), 'reg_section' => $gradeable->getUser()->getRegistrationSection());
            }
            $g_id = $gradeable->getId();
            $is_electronic_gradeable = ($gradeable->getType() == GradeableType::ELECTRONIC_FILE);
            $use_ta_grading = !$is_electronic_gradeable || $gradeable->useTAGrading();

            if(!isset($results['header_model'][$g_id])) {
              $max = 0;
              if ($is_electronic_gradeable) {
                $max = $max + $gradeable->getTotalAutograderNonExtraCreditPoints();
              }
              if ($use_ta_grading) {
                $max = $max + $gradeable->getTotalTANonExtraCreditPoints();
              }
              $results['header_model'][$g_id] = $g_id.": ".$max;
            }

            $total_score = 0;
            if ($is_electronic_gradeable) {
              $total_score = $total_score + $gradeable->getGradedAutograderPoints();
            }
            if ($use_ta_grading) {
              $total_score = $total_score + $gradeable->getGradedTAPoints();
            }
            
            $late_days = $ldu->getGradeable($gradeable->getUser()->getId(), $gradeable->getId());
            // if this assignment exceeds the allowed late day policy or
            // if the student has switched versions after the ta graded,
            // then they should receive an automatic zero for this gradeable
            if( $is_electronic_gradeable &&
                ( (array_key_exists('status',$late_days) && substr($late_days['status'], 0, 3) == 'Bad') ||
                  ($use_ta_grading && !$gradeable->validateVersions()))) {
              $total_score = 0;
            }

            $results[$student_id][$g_id] = $total_score;
        }
        
        $nl = "\n";
        $csv_output = "";
        $filename = $this->core->getConfig()->getCourse()."CSVReport.csv";
        foreach($results as $id => $student) {
            $student_line = array();
            if($id === 'header_model') {
                $student_line[] = "UserId";
            }
            else {
                $student_line[] = $id;
            }
            $student_line[] = $student['First'];
            $student_line[] = $student['Last'];
            $student_line[] = $student['reg_section'];
            foreach($results['header_model'] as $grade_id => $grade) {
                if($grade_id == 'First' || $grade_id == 'Last' || $grade_id == 'reg_section') {
                    continue;
                }
                $student_line[] = $student[$grade_id];
            }
            $csv_output .= implode(",",$student_line).$nl;
        }
        $this->core->getOutput()->renderFile($csv_output, $filename);
        return $csv_output;
    }
    
    public function generateGradeSummaries() {
        $curr_allowed_term =

        $current_user = null;
        $user = [];
        $order_by = [
            'g.g_gradeable_type',
            'CASE WHEN eg.eg_submission_due_date IS NOT NULL THEN eg.eg_submission_due_date ELSE g.g_grade_released_date END'
        ];
        foreach ($this->core->getQueries()->getGradeablesIterator(null, true, 'registration_section', 'u.user_id', null, $order_by) as $gradeable) {
            /** @var \app\models\Gradeable $gradeable */
            if ($current_user !== $gradeable->getUser()->getId()) {
                if ($current_user !== null) {
                    file_put_contents(FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "reports", $current_user.'_summary.json'), $user);
                }
                $current_user = $gradeable->getUser()->getId();
                $user['user_id'] = $gradeable->getUser()->getId();
                $user['legal_first_name'] = $gradeable->getUser()->getFirstName();
                $user['preferred_first_name'] = $gradeable->getUser()->getPreferredFirstName();
                $user['last_name'] = $gradeable->getUser()->getLastName();
                $user['registration_section'] = $gradeable->getUser()->getRegistrationSection();
                $user['default_allowed_late_days'] = $this->core->getConfig()->getDefaultHwLateDays();
                $user['last_update'] = date("l, F j, Y");
            }
            $bucket = ucwords($gradeable->getBucket());
            if (!isset($user[$bucket])) {
                $user[$bucket] = [];
            }

            $autograding_score = $gradeable->getGradedAutoGraderPoints();
            $ta_grading_score = $gradeable->getGradedTAPoints();

            $entry = [
                'id' => $gradeable->getId(),
                'name' => $gradeable->getName(),
                'grade_released_date' => $gradeable->getGradeReleasedDate()->format('Y-m-d H:i:s O'),
            ];

            if ($gradeable->validateVersions() || !$gradeable->useTAGrading()) {
               $entry['score'] = max(0,floatval($autograding_score) + floatval($ta_grading_score));
            }
            else {
                $entry['score'] = 0;
                if ($gradeable->validateVersions(-1)) {
                    $entry['note'] = 'This has not been graded yet.';
                }
                elseif ($gradeable->getActiveVersion() !== 0) {
                    $entry['note'] = 'Score is set to 0 because there are version conflicts.';
                }
            }

            switch ($gradeable->getType()) {
                case GradeableType::ELECTRONIC_FILE:
                    //$this->addLateDays($gradeable, $entry);
                    $this->addText($gradeable, $entry);
                    break;
                case GradeableType::NUMERIC_TEXT:
                    $this->addText($gradeable, $entry);
                    $this->addProblemScores($gradeable, $entry);
                    break;
                case GradeableType::CHECKPOINTS:
                    $this->addProblemScores($gradeable, $entry);
                    break;
            }
            $user[$bucket][] = $entry;
        }
        $this->core->addSuccessMessage("Successfully Generated Grade Summaries");
        $this->core->getOutput()->renderOutput(array('admin', 'Report'), 'showReportUpdates');
    }

    private function addLateDays(Gradeable $gradeable, &$entry) {
        $late_days = $gradeable->getLateDays();

        if(substr($late_days['status'], 0, 3) == 'Bad') {
            $this_g["score"] = 0;
        }
        $this_g['status'] = $late_days['status'];

        if (array_key_exists('late_days_charged', $late_days) && $late_days['late_days_used'] > 0) {

            // TODO:  DEPRECATE THIS FIELD
            $this_g['days_late'] = $late_days['late_days_charged'];

            // REPLACED BY:
            $this_g['days_after_deadline'] = $late_days['late_days_used'];
            $this_g['extensions'] = $late_days['extensions'];
            $this_g['days_charged'] = $late_days['late_days_charged'];

        }
        else {
            $this_g['days_late'] = 0;
        }
    }

    private function addProblemScores(Gradeable $gradeable, &$entry) {
        $component_scores = [];
        foreach($gradeable->getComponents() as $component) {
            $component_scores[] = [$component->getTitle() => $component->getScore()];
        }
        $entry["component_scores"] = $component_scores;
    }

    private function addText(Gradeable $gradeable, &$entry) {
        $text_items = [];
        foreach($gradeable->getComponents() as $component) {
            $text_items[] = [$component->getTitle() => $component->getComment()];
        }

        if(count($text_items) > 0){
            $entry["text"] = $text_items;
        }
    }
    
    public function generateHWReports() {
        $hw_report = new HWReport($this->core);
        $hw_report->generateAllReports();
        $this->core->addSuccessMessage("Successfully Generated HWReports");
        $this->core->getOutput()->renderOutput(array('admin', 'Report'), 'showReportUpdates');
    }
}

