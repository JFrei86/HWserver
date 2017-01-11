<?php

namespace app\controllers;

use app\controllers\grading\ElectronicGraderController;
use app\controllers\grading\SimpleGraderController;

class GradingController extends AbstractController{
    public function run() {
        if (!$this->core->getUser()->accessGrading()) {
            $this->core->getOutput()->showError("This account is not authorized to view grading section");
        }

        $controller = null;
        switch ($_REQUEST['page']) {
            case 'simple':
                $controller = new SimpleGraderController($this->core);
                break;
            case 'electronic':
                $controller = new ElectronicGraderController($this->core);
                break;
            default:
                $this->core->getOutput()->showError("Invalid page request for controller ".get_class($this));
                break;
        }
        $controller->run();
    }
}