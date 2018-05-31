<?php

namespace app\views\admin;

use app\models\GradeableComponent;
use app\views\AbstractView;
use app\models\AdminGradeable;

class AdminGradeableView extends AbstractView {
    /**
     * Shows creation part
     */
	public function show_add_gradeable($type_of_action, AdminGradeable $admin_gradeable, $nav_tab = 0) {

        $action           = "new"; //decides how the page's data is displayed
        $button_string    = "Add";
        $submit_text      = "Submit";
        $label_message    = "";
        $gradeables_array = array();
        
        //makes an array of gradeable ids for javascript
        foreach ($admin_gradeable->getTemplateList() as $g_id_title) {
            array_push($gradeables_array, $g_id_title['g_id']);
        }

        // For each grader with sections assigned to them, add their
        //  sections to the array generated above
        foreach($admin_gradeable->getGradersAllSection() as $grader) {
            //parses the sections from string "{1, 2, 3, 4}" to a php array [1,2,3,4]
            $sections = $grader['sections'];
            $sections = ltrim($sections, '{');
            $sections = rtrim($sections, '}');
            $sections = explode(',', $sections);

            $graders[$grader['user_group']][$grader['user_id']]['sections'] = $sections;
        }

        $marks = array();

        // //if the user is editing a gradeable instead of adding
        if ($type_of_action === "edit") {
            $action        = "edit";
            $button_string = "Save changes to";
            $submit_text   = "Save Changes";
            $label_message = ($admin_gradeable->getHasGrades()) ? "<span style='color: red;'>(Grading has started! Edit Questions At Own Peril!)</span>" : "";

            // Generate marks array if we're editing an electronic gradeable with TA grading
            if($admin_gradeable->getGGradeableType() == 0 and $admin_gradeable->getEgUseTaGrading()) {
                $old_components = $admin_gradeable->getOldComponents();
                for($x = 0; $x < sizeof($old_components); $x++) {
                    $component_id = $old_components[$x]->getId();
                    $my_marks = $this->core->getQueries()->getGradeableComponentsMarks($component_id);
                    $marks[$component_id] = array();
                    foreach($my_marks as $i => $mark) {
                        $marks[$component_id][$i] = array(
                            'publish'   => $mark->getPublish(),
                            'order'     => $mark->getOrder(),
                            'id'        => $mark->getId(),
                            'points'    => $mark->getPoints(),
                            'note'      => $mark->getNote());
                    }
                }
            }
        }

        // $marks_json = json_encode($marks);
        // $old_components_json = $admin_gradeable->getOldComponentsJson();


        return $this->core->getOutput()->renderTwigTemplate('admin/admin_gradeable/AdminGradeableBase.twig', [
            "submit_url"      => $this->core->buildUrl(array('component' => 'admin', 'page' => 'admin_gradeable', 'action' => 'upload_' . $action . '_gradeable')),
            "js_gradeables_array"=> json_encode($gradeables_array),
            "admin_gradeable" => $admin_gradeable,
            "label_message"   => $label_message,
            "action"          => $action,
            "template"        => $type_of_action == "add_template",
            "submit_text"     => $submit_text,
            "nav_tab"         => $nav_tab,

            // Be sure to NOT pass old components if we are inheriting from a template
            "old_components"  => $type_of_action == "add_template" ? array(new GradeableComponent($this->core, array())) : $admin_gradeable->getOldComponents(),
            "marks"           => $marks,

            // Graders Page Specific
            "all_graders"    => $admin_gradeable->getGradersFromUsertypes()
        ]);
    }
    
    public function show_edit_gradeable(AdminGradeable $admin_gradeable) {
        return "";
    }
}
