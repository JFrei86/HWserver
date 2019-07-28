<?php

namespace app\controllers;

use app\controllers\admin\AdminGradeableController;
use app\controllers\admin\GradeOverrideController;

class AdminController extends AbstractController {
    public function run() {
        if (!$this->core->getUser()->accessAdmin()) {
            $this->core->getOutput()->showError("This account cannot access admin pages");
        }

        //$this->core->getOutput()->addBreadcrumb('Admin');
        $controller = null;
        switch ($_REQUEST['page']) {
            case 'admin_gradeable':
                $controller = new AdminGradeableController($this->core);
                break;
            default:
                $this->core->getOutput()->showError("Invalid page request for controller ".get_class($this));
                break;
        }
        $controller->run();
    }
}
