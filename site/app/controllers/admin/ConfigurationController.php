<?php

namespace app\controllers\admin;

use app\controllers\IController;
use app\libraries\Core;
use app\libraries\IniParser;
use app\libraries\Output;

class ConfigurationController implements IController {
    private $core;

    public function __construct(Core $core) {
        $this->core = $core;
    }

    public function run() {
        switch ($_REQUEST['action']) {
            case 'view':
                $this->viewConfiguration();
                break;
            case 'update':
                $this->updateConfiguration();
                break;
            default:
                $this->core->getOutput()->showError("Invalid page request for controller");
                break;
        }
    }

    public function viewConfiguration() {
        $fields = array(
            'course_name'               => $this->core->getConfig()->getCourseName(),
            'default_hw_late_days'      => $this->core->getConfig()->getDefaultHwLateDays(),
            'default_student_late_days' => $this->core->getConfig()->getDefaultStudentLateDays(),
            'zero_rubric_grades'        => $this->core->getConfig()->shouldZeroRubricGrades(),
            'upload_message'            => $this->core->getConfig()->getUploadMessage(),
            'ta_grades'                 => $this->core->getConfig()->showTaGrades()
        );

        foreach (array('course_name', 'upload_message') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = htmlentities($_SESSION['request'][$key]);
            }
        }

        foreach (array('default_hw_late_days', 'default_student_late_days') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = intval($_SESSION['request'][$key]);
            }
        }

        foreach (array('zero_rubric_grades', 'ta_grades') as $key) {
            if (isset($_SESSION['request'][$key])) {
                $fields[$key] = ($_SESSION['request'][$key] == true) ? true : false;
            }
        }

        if (isset($_SESSION['request'])) {
            unset($_SESSION['request']);
        }

        $this->core->getOutput()->renderOutput(array('admin', 'Configuration'), 'viewConfig', $fields);
    }

    public function updateConfiguration() {
        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $_SESSION['messages']['error'][] = "Invalid CSRF token. Try again.";
            $_SESSION['request'] = $_POST;
            $this->core->redirect($this->core->buildUrl(array('component' => 'admin',
                                                              'page' => 'configuration',
                                                              'action' => 'view')));
        }

        if (!isset($_POST['course_name']) || $_POST['course_name'] == "") {
            $_SESSION['messages']['error'][] = "Course name can not be blank";
            $_SESSION['request'] = $_POST;
            $this->core->redirect($this->core->buildUrl(array('component' => 'admin',
                                                              'page' => 'configuration',
                                                              'action' => 'view')));
        }

        foreach (array('default_hw_late_days', 'default_student_late_days') as $key) {
            $_POST[$key] = (isset($_POST[$key])) ? intval($_POST[$key]) : 0;
        }

        foreach (array('zero_rubric_grades', 'ta_grades') as $key) {
            $_POST[$key] = (isset($_POST[$key]) && $_POST[$key] == "true") ? true : false;
        }

        $save_array = array(
            'hidden_details' => array(
                'database_name' => $this->core->getConfig()->getDatabaseName()
            ),
            'course_details' => array(
                'course_name'               => $_POST['course_name'],
                'default_hw_late_days'      => $_POST['default_hw_late_days'],
                'default_student_late_days' => $_POST['default_student_late_days'],
                'zero_rubric_grades'        => $_POST['zero_rubric_grades'],
                'upload_message'            => nl2br($_POST['upload_message']),
                'ta_grades'                 => $_POST['ta_grades']
            )
        );
        
        if ($this->core->getConfig()->getCourseUrl() !== null) {
            $save_array['hidden_details']['course_url'] = $this->core->getConfig()->getCourseUrl();
        }
        
        IniParser::writeFile($this->core->getConfig()->getCourseIniPath(), $save_array);
        $_SESSION['messages']['success'][] = "Site configuration updated";
        $this->core->redirect($this->core->buildUrl(array('component' => 'admin',
                                                          'page' => 'configuration',
                                                          'action' => 'view')));
    }
}