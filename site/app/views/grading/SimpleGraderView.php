<?php

namespace app\views\grading;

use app\libraries\GradeableType;
use app\models\Gradeable;
use app\models\User;
use app\views\AbstractView;

class SimpleGraderView extends AbstractView {

    /**
     * @param Gradeable $gradeable
     * @param Gradeable[] $rows
     * @param array       $graders
     *
     * @return string
     */
    public function simpleDisplay($gradeable, $rows, $graders, $section_type) {

        $g_id = $gradeable->getId();
        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        $action = ($gradeable->getType() === 1) ? 'lab' : 'numeric';
        $return = <<<HTML
<div class="content">
    <div style="float: right; margin-bottom: 10px; margin-left: 20px">
HTML;

        // Default is viewing your sections sorted by id
        // Limited grader does not have "View All"
        // If nothing to grade, Instuctor will see all sections
        if (!isset($_GET['sort'])) {
            $sort = 'id';
        } else {
            $sort = $_GET['sort'];
        }
        if (!isset($_GET['view']) || $_GET['view'] !== 'all') {
            $view = 'all';
        } else {
            $view = null;
        }
        if ($gradeable->isGradeByRegistration()) {
            $grading_count = count($this->core->getUser()->getGradingRegistrationSections());
        } else {
            $grading_count = count($this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(), $this->core->getUser()->getId()));
        }

        $show_all_sections_button = $this->core->getUser()->accessFullGrading() && (!$this->core->getUser()->accessAdmin() || $grading_count !== 0);

        // Get all the names/ids from all the students
        $student_full = array();
        foreach ($rows as $gradeable_row) {
            $student_full[] = array('value' => $gradeable_row->getUser()->getId(),
                'label' => $gradeable_row->getUser()->getDisplayedFirstName() . ' ' . $gradeable_row->getUser()->getLastName() . ' <' . $gradeable_row->getUser()->getId() . '>');
        }
        $student_full = json_encode($student_full);

        $components_numeric = [];
        $components_text = [];

        $num_text = 0;
        $num_numeric = count($gradeable->getComponents());
        $comp_ids = array();
        if ($action != 'lab') {
            foreach ($gradeable->getComponents() as $component) {
                if ($component->getIsText()) {
                    $components_text[] = $component;
                } else {
                    $components_numeric[] = $component;
                    $comp_ids[] = $component->getId();
                }
            }
        }


        $count = 1;
        $row = 0;
        $last_section = false;
        $tbody_open = false;
        $colspan = 5 + count($gradeable->getComponents());
        $num_users = 0;
        $sections = array();

        if ($action == 'numeric') {
            $colspan++;
        }
        // Iterate through every row
        foreach ($rows as $gradeable_row) {
            if ($gradeable->isGradeByRegistration()) {
                $section = $gradeable_row->getUser()->getRegistrationSection();
            } else {
                $section = $gradeable_row->getUser()->getRotatingSection();
            }
            $display_section = ($section === null) ? "NULL" : $section;
            if ($section !== $last_section) {
                if ($section !== null) {
                    $sections[] = $section;
                }
                $last_section = $section;
                $count = 1;
                if ($tbody_open) {
                    $return .= <<<HTML
        </tbody>
HTML;
                }
                if (isset($graders[$display_section]) && count($graders[$display_section]) > 0) {
                    $section_graders = implode(", ", array_map(function (User $user) {
                        return $user->getId();
                    }, $graders[$display_section]));
                } else {
                    $section_graders = "Nobody";
                }
                $return .= <<<HTML
            <tr class="info persist-header">
                <td colspan="{$colspan}" style="text-align: center">
                Students Enrolled in Section {$display_section}
HTML;
                if ($action == 'lab') {
                    $return .= <<<HTML
                    <a target=_blank href="{$this->core->buildUrl(array(
                        'component' => 'grading',
                        'page' => 'simple',
                        'action' => 'print_lab',
                        'sort' => $sort,
                        'section' => $section,
                        'sectionType' => $section_type,
                        'g_id' => $g_id))}">
                      <i class="fa fa-print"></i>
                    </a>
HTML;
                }
                $component_ids = json_encode($comp_ids);
                $return .= <<<HTML
                </td>
            </tr>
            <tr class="info">
                <td colspan="{$colspan}" style="text-align: center">Graders: {$section_graders}</td>
            </tr>
        <tbody id="section-{$section}" data-numnumeric="{$num_numeric}" data-numtext="{$num_text}" data-compids = "{$component_ids}">
HTML;
            }
            $style = "";
            if ($gradeable_row->getUser()->accessGrading()) {
                $style = "style='background: #7bd0f7;'";
            }
            $return .= <<<HTML
            <tr data-gradeable="{$gradeable->getId()}" data-user="{$gradeable_row->getUser()->getId()}" data-row="{$row}" {$style}> 
                <td class="">{$count}</td>
                <td class="">{$gradeable_row->getUser()->getRegistrationSection()}</td>
                <td class="cell-all" style="text-align: left">{$gradeable_row->getUser()->getId()}</td>
                <td class="" style="text-align: left">{$gradeable_row->getUser()->getDisplayedFirstName()}</td>
                <td class="" style="text-align: left">{$gradeable_row->getUser()->getLastName()}</td>
HTML;
            if ($action == 'lab') {
                $col = 0;
                foreach ($gradeable_row->getComponents() as $component) {
                    $grader = ($component->getGrader() !== null) ? "data-grader='{$component->getGrader()->getId()}'" : '';
                    $time = ($component->getGradeTime() !== null) ? "data-grade-time='{$component->getGradeTime()->format('Y-m-d H:i:s')}'" : '';
                    if ($component->getIsText()) {
                        $return .= <<<HTML
                <td>{$component->getComment()}</td>
HTML;
                    } else {
                        if ($component->getScore() === 1.0) {
                            $background_color = "background-color: #149bdf";
                        } else if ($component->getScore() === 0.5) {
                            $background_color = "background-color: #88d0f4";
                        } else {
                            $background_color = "";
                        }


                        $return .= <<<HTML
                <td class="cell-grade" id="cell-{$row}-{$col}" data-id="{$component->getId()}" data-score="{$component->getScore()}" {$grader} {$time} style="{$background_color}"></td>
HTML;
                    }
                    $gradeable_row++;
                    $col++;
                }
            } else {
                $col = 0;
                $total = 0;
                if ($num_numeric !== 0) {
                    foreach ($gradeable_row->getComponents() as $component) {
                        $grader = ($component->getGrader() !== null) ? "data-grader='{$component->getGrader()->getId()}'" : '';
                        $time = ($component->getGradeTime() !== null) ? "data-grade-time='{$component->getGradeTime()->format('Y-m-d H:i:s')}'" : '';
                        if (!$component->getIsText()) {
                            $total += $component->getScore();
                            if ($component->getScore() == 0) {
                                $return .= <<<HTML
                <td class="option-small-input"><input class="option-small-box" style="text-align: center; color: #bbbbbb;" type="text" id="cell-{$row}-{$col}" value="{$component->getScore()}" data-id="{$component->getId()}" {$grader} {$time} data-num="true"/></td>
HTML;
                            } else {
                                $return .= <<<HTML
                <td class="option-small-input"><input class="option-small-box" style="text-align: center" type="text" id="cell-{$row}-{$col}" value="{$component->getScore()}" data-id="{$component->getId()}" {$grader} {$time} data-num="true"/></td>
HTML;
                            }
                            $gradeable_row++;
                            $col++;
                        }
                    }
                    $return .= <<<HTML
                <td class="option-small-output"><input class="option-small-box" style="text-align: center" type="text" border="none" id="total-{$row}" value=$total data-total="true" readonly></td>
HTML;

                }

                foreach ($gradeable_row->getComponents() as $component) {
                    if ($component->getIsText()) {
                        $return .= <<<HTML
                <td class="option-small-input"><input class="option-small-box" type="text" id="cell-{$row}-{$col}" value="{$component->getComment()}" data-id="{$component->getId()}"/></td>
HTML;
                        $gradeable_row++;
                        $col++;
                    }
                }
            }

            if ($gradeable_row->getUser()->getRegistrationSection() != "") {
                $num_users++;
            }

            $return .= <<<HTML
            </tr>
HTML;
            $row++;
            $count++;
        }


        $this->core->getOutput()->addInternalJs('twig.min.js');
        $this->core->getOutput()->addInternalJs('ta-grading-keymap.js');

        $return = $this->core->getOutput()->renderTwigTemplate("grading/simple/Display.twig", [
            "gradeable" => $gradeable,
            "action" => $action,
            "show_all_sections_button" => $show_all_sections_button,
            "view_all" => $view,
            "student_full" => $student_full,
            "components_numeric" => $components_numeric,
            "components_text" => $components_text,
            "rows" => $rows,
        ]);

        $return .= $this->core->getOutput()->renderTwigTemplate("grading/simple/StatisticsForm.twig", [
            "num_users" => $num_users,
            "components" => $gradeable->getComponents(),
            "sections" => $sections
        ]);

        $return .= $this->core->getOutput()->renderTwigTemplate("grading/SettingsForm.twig");

        return $return;
    }

    /**
     * @param Gradeable $gradeable
     * @param string $sort_by
     * @param string $section
     * @param User[] $students
     * @return string
     */
    public function displayPrintLab(Gradeable $gradeable, string $sort_by, string $section, $students) {
        //Get the names of all of the checkpoints
        $checkpoints = array();
        foreach ($gradeable->getComponents() as $row) {
            array_push($checkpoints, $row->getTitle());
        }
        return $this->core->getOutput()->renderTwigTemplate("grading/simple/PrintLab.twig", [
            "gradeable" => $gradeable,
            "section" => $section,
            "checkpoints" => $checkpoints,
            "students" => $students
        ]);
    }
}
