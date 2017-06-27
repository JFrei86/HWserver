<?php

namespace app\views\grading;

use app\models\Gradeable;
use app\models\User;
use app\views\AbstractView;

class ElectronicGraderView extends AbstractView {
    /**
     * @param Gradeable $gradeable
     * @param array     $sections
     * @return string
     */
    public function statusPage($gradeable, $sections) {
        $course = $this->core->getConfig()->getCourse();
        $semester = $this->core->getConfig()->getSemester();
        $graded = 0;
        $total = 0;
        foreach ($sections as $key => $section) {
            if ($key === "NULL") {
                continue;
            }
            $graded += $section['graded_students'];
            $total += $section['total_students'];
        }
        if ($total === 0){
            $percentage = -1;
        }
        else{
            $percentage = round(($graded / $total) * 100);
        }
        $return = <<<HTML
<div class="content">
    <h2>Status of {$gradeable->getName()}</h2>
HTML;
    if($percentage === -1){
        $view = 'all';
        $return .= <<<HTML
    <div class="sub">
        No Grading To Be Done! :)
HTML;

    }
    else{
        $view = null;
        $return .= <<<HTML
    <div class="sub">
        Current percentage of grading done: {$percentage}% ({$graded}/{$total})
        <br />
        <br />
        By Grading Sections:
        <div style="margin-left: 20px">
HTML;
        foreach ($sections as $key => $section) {
            $percentage = round(($section['graded_students'] / $section['total_students']) * 100);
            $return .= <<<HTML
            Section {$key}: {$percentage}% ({$section['graded_students']} / {$section['total_students']})<br />
HTML;
        }
        $return .= <<<HTML
        </div>
        <br />
        Graders:
        <div style="margin-left: 20px">
HTML;
            foreach ($sections as $key => $section) {
                if ($key === "NULL") {
                    continue;
                }
                if (count($section['graders']) > 0) {
                    $graders = implode(", ", array_map(function($grader) { return $grader->getId(); }, $section['graders']));
                }
                else {
                    $graders = "Nobody";
                }
                $return .= <<<HTML
            Section {$key}: {$graders}<br />
HTML;
            }
        }
        // {$this->core->getConfig()->getTABaseUrl()}account/account-summary.php?course={$course}&semester={$semester}&g_id={$gradeable->getId()}
        $return .= <<<HTML
        </div>
        <div style="margin-top: 20px">
HTML;
        if($percentage !== -1 || $this->core->getUser()->accessFullGrading()){
            $return .= <<<HTML
            <a class="btn btn-primary" 
                href="{$this->core->buildUrl(array('component'=>'grading', 'page'=>'electronic', 'action' => 'summary', 'gradeable_id' => $gradeable->getId(), 'view' => $view))}"">
                Grading Details
            </a>
HTML;
            if(count($this->core->getUser()->getGradingRegistrationSections()) !== 0){
                $return .= <<<HTML
            <a class="btn btn-primary"
                href="{$this->core->getConfig()->getTaBaseUrl()}account/index.php?course={$course}&semester={$semester}&g_id={$gradeable->getId()}">
                Grade Next Student
            </a>
        </div>
    </div>
</div>
HTML;
            }
        }
        return $return;
    }

    /**
     * @param Gradeable   $gradeable
     * @param Gradeable[] $rows
     * @param array       $graders
     * @return string
     */
    public function summaryPage($gradeable, $rows, $graders) {
        $return = <<<HTML
<div class="content">
    
HTML;
        // Default is viewing your sections
        // Limited grader does not have "View All" option
        // If nothing to grade, Instructor will see all sections
        if (!isset($_GET['view']) || $_GET['view'] !== 'all') {
            $text = 'View All';
            $view = 'all';
        }
        else{
            $text = 'View Your Sections';
            $view = null;
        }
        if($gradeable->isGradeByRegistration()){
            $grading_count = count($this->core->getUser()->getGradingRegistrationSections());
        }
        else{
            $grading_count = count($this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(),$this->core->getUser()->getId()));
        }

        if($this->core->getUser()->accessFullGrading() && (!$this->core->getUser()->accessAdmin() || $grading_count !== 0)){
            $return .= <<<HTML
    <div style="float: right; margin-bottom: 10px">
        <a class="btn btn-default"
            href="{$this->core->buildUrl(array('component' => 'grading', 'page' => 'electronic', 'action' => 'summary', 'gradeable_id' => $gradeable->getId(), 'view' => $view))}">
            $text
        </a>
    </div>
HTML;
        }
        $show_auto_grading_points = true;
        $return .= <<<HTML
    <h2>Grade Details for {$gradeable->getName()}</h2>
    <table class="table table-striped table-bordered persist-area">
        <thead class="persist-thead">
            <tr>
                <td width="3%"></td>
                <td width="5%">Section</td>
                <td width="20%">User ID</td>
                <td width="15%">First Name</td>
                <td width="15%">Last Name</td>
HTML;

        if($gradeable->getTotalAutograderNonExtraCreditPoints() !== 0) {
            $return .= <<<HTML
                <td width="9%">Autograding</td>
                <td width="8%">TA Grading</td>
                <td width="7%">Total</td>
                <td width="10%">Active Version</td>
                <td width="8%">Viewed Grade</td>
            </tr>
        </thead>
HTML;
        }
        else {
            $show_auto_grading_points = false;
            $return .= <<<HTML
                <td width="12%">TA Grading</td>
                <td width="12%">Total</td>
                <td width="10%">Active Version</td>
                <td width="8%">Viewed Grade</td>
            </tr>
        </thead>
HTML;
        }
        
            $return .= <<<HTML
HTML;
            $count = 1;
            $last_section = false;
            $tbody_open = false;
            foreach ($rows as $row) {
                if ($row->beenTAgraded()){
                    if ($row->getUserViewedDate() === null || $row->getUserViewedDate() === "") {
                        $viewed_grade = "&#10008;";
                        $grade_viewed = "";
                        $grade_viewed_color = "color: red; font-size: 1.5em;";
                    }
                    else {
                        $viewed_grade = "&#x2714;";
                        $grade_viewed = "Last Viewed: " . date("F j, Y, g:i a", strtotime($row->getUserViewedDate()));
                        $grade_viewed_color = "color: #5cb85c; font-size: 1.5em;";
                    }
                }
                else{
                    $viewed_grade = "";
                    $grade_viewed = "";
                    $grade_viewed_color = "";
                }
                $active_version = $row->getActiveVersion();
                $highest_version = $row->getHighestVersion();
                $total_possible = $row->getTotalAutograderNonExtraCreditPoints() + $row->getTotalTANonExtraCreditPoints();
                $graded = $row->getGradedAutograderPoints() + $row->getGradedTAPoints();
                if ($graded < 0) $graded = 0;
                if ($gradeable->isGradeByRegistration()) {
                    $section = $row->getUser()->getRegistrationSection();
                }
                else {
                    $section = $row->getUser()->getRotatingSection();
                }
                $display_section = ($section === null) ? "NULL" : $section;
                if ($section !== $last_section) {
                    $last_section = $section;
                    $count = 1;
                    if ($tbody_open) {
                        $return .= <<<HTML
        </tbody>
HTML;
                    }
                    if (isset($graders[$display_section]) && count($graders[$display_section]) > 0) {
                        $section_graders = implode(", ", array_map(function(User $user) { return $user->getId(); }, $graders[$display_section]));
                    }
                    else {
                        $section_graders = "Nobody";
                    }
                    $cols = ($show_auto_grading_points) ? 10 : 9;
                    $return .= <<<HTML
        <tr class="info persist-header">
            <td colspan="{$cols}" style="text-align: center">Students Enrolled in Section {$display_section}</td>
        </tr>
        <tr class="info">
            <td colspan="{$cols}" style="text-align: center">Graders: {$section_graders}</td>
        </tr>
        <tbody id="section-{$section}">
HTML;
                }
                $return .= <<<HTML
            <tr id="user-row-{$row->getUser()->getId()}">
                <td>{$count}</td>
                <td>{$display_section}</td>
                <td>{$row->getUser()->getId()}</td>
                <td>{$row->getUser()->getDisplayedFirstName()}</td>
                <td>{$row->getUser()->getLastName()}</td>
HTML;

                if($show_auto_grading_points) {
                    if ($highest_version != 0) {
                        $return .= <<<HTML
                <td>{$row->getGradedAutograderPoints()}&nbsp;/&nbsp;{$row->getTotalAutograderNonExtraCreditPoints()}</td>
HTML;
                    }
                    else {
                        $return .= <<<HTML
                <td></td>
HTML;
                    }
                }
                if ($highest_version != 0) {
                    $return .= <<<HTML
                <td>
HTML;
                    $box_background = "";
                    if ($row->getActiveDaysLate() > $row->getAllowedLateDays()) {
                        $box_background = "background:red;padding:3px;margin-top:3px;margin-bottom:3px;margin-left:5%;margin-right:5%;border-radius:4px;";
                    }
                    
                    if ($row->beenTAgraded()) {
                        $btn_class = "btn-default";
                        $contents = "{$row->getGradedTAPoints()}&nbsp;/&nbsp;{$row->getTotalTANonExtraCreditPoints()}";
                    }
                    else {
                        $btn_class = "btn-primary";
                        $contents = "Grade";
                    }
                    $return .= <<<HTML
                        <a class="btn {$btn_class}" href="{$this->core->getConfig()->getTaBaseUrl()}account/index.php?g_id={$gradeable->getId()}&amp;individual={$row->getUser()->getId()}&amp;course={$this->core->getConfig()->getCourse()}&amp;semester={$this->core->getConfig()->getSemester()}">
                            {$contents}
                        </a>
                </td>
                <td><div style="{$box_background}">{$graded}&nbsp;/&nbsp;{$total_possible}</div></td>
HTML;
                    if($active_version == $highest_version) {
                        $return .= <<<HTML
                <td>{$active_version}</td>
HTML;
                    }
                    else {
                        $return .= <<<HTML
                <td>{$active_version}&nbsp;/&nbsp;{$highest_version}</td>
HTML;
                    }
                }
                else {
                    $return .= <<<HTML
                <td>
                    <a class="btn btn-default" style="color:#a5a5a5;" href="{$this->core->getConfig()->getTaBaseUrl()}account/index.php?g_id={$gradeable->getId()}&amp;individual={$row->getUser()->getId()}&amp;course={$this->core->getConfig()->getCourse()}&amp;semester={$this->core->getConfig()->getSemester()}">Grade
                    </a>
                </td>
                <td></td>
                <td></td>
HTML;
                }
                
                $return .= <<<HTML
                <td title="{$grade_viewed}" style="{$grade_viewed_color}">{$viewed_grade}</td>
            </tr>
HTML;
                $count++;
            }
            $return .= <<<HTML
        </tbody>
HTML;
        $return .= <<<HTML
    </table>
</div>
HTML;
        return $return;
    }
}
