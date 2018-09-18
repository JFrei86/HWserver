<?php

namespace app\views\grading;

use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\Component;
use app\models\User;
use app\views\AbstractView;

class SimpleGraderView extends AbstractView {

    /**
     * @param Gradeable $gradeable
     * @param GradedGradeable[] $graded_gradeables A full set of graded gradeables
     * @param array $graders
     * @param string $section_type
     * @param bool $show_all_sections_button
     * @return string
     */
    public function simpleDisplay($gradeable, $graded_gradeables, $student_full, $graders, $section_type, $show_all_sections_button) {
        $action = ($gradeable->getType() === 1) ? 'lab' : 'numeric';

        // Default is viewing your sections sorted by id
        // Limited grader does not have "View All"
        // If nothing to grade, Instructor will see all sections
        $view_all = isset($_GET['view']) && $_GET['view'] === 'all';
        $components_numeric = [];
        $components_text = [];

        $comp_ids = array();
        foreach ($gradeable->getComponents() as $component) {
            if ($component->isText()) {
                $components_text[] = $component;
            } else {
                $components_numeric[] = $component;
                if ($action != 'lab') {
                    $comp_ids[] = $component->getId();
                }
            }
        }

        $sort = $_GET['sort'] ?? "id";

        $num_users = 0;
        $sections = array();

        // Iterate through every row
        /** @var GradedGradeable $graded_gradeable */
        foreach ($graded_gradeables as $graded_gradeable) {
            if ($gradeable->isGradeByRegistration()) {
                $section = $graded_gradeable->getSubmitter()->getUser()->getRegistrationSection();
            } else {
                $section = $graded_gradeable->getSubmitter()->getUser()->getRotatingSection();
            }

            $display_section = ($section === null) ? "NULL" : $section;

            if (!array_key_exists($section, $sections)) {
                $sections[$section] = [
                    "grader_names" => array_map(function (User $user) {
                        return $user->getId();
                    }, $graders[$display_section] ?? []),
                    "rows" => [],
                ];
            }
            $sections[$section]["rows"][] = $graded_gradeable;

            if ($graded_gradeable->getSubmitter()->getUser()->getRegistrationSection() != "") {
                $num_users++;
            }
        }
        $component_ids = json_encode($comp_ids);

        $this->core->getOutput()->addInternalJs('twig.min.js');
        $this->core->getOutput()->addInternalJs('ta-grading-keymap.js');
        $this->core->getOutput()->addInternalJs('simple-grading.js');

        $return = $this->core->getOutput()->renderTwigTemplate("grading/simple/Display.twig", [
            "gradeable" => $gradeable,
            "action" => $action,
            "section_type" => $section_type,
            "show_all_sections_button" => $show_all_sections_button,
            "view_all" => $view_all,
            "student_full" => $student_full,
            "components_numeric" => $components_numeric,
            "components_text" => $components_text,
            "sort" => $sort,
            "sections" => $sections,
            "component_ids" => $component_ids,
        ]);

        $return .= $this->core->getOutput()->renderTwigTemplate("grading/simple/StatisticsForm.twig", [
            "num_users" => $num_users,
            "components_numeric" => $components_numeric,
            "sections" => $sections
        ]);

        $return .= $this->core->getOutput()->renderTwigTemplate("grading/SettingsForm.twig");

        return $return;
    }

    /**
     * @param Gradeable $gradeable
     * @param string $section
     * @param User[] $students
     * @return string
     */
    public function displayPrintLab(Gradeable $gradeable, string $section, $students) {
        //Get the names of all of the checkpoints
        $checkpoints = array_map(function (Component $component) {
            return $component->getTitle();
        }, $gradeable->getComponents());
        return $this->core->getOutput()->renderTwigTemplate("grading/simple/PrintLab.twig", [
            "gradeable" => $gradeable,
            "section" => $section,
            "checkpoints" => $checkpoints,
            "students" => $students
        ]);
    }
}
