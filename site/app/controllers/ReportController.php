<?php

namespace app\controllers;

use app\libraries\Core;
use app\libraries\Output;
use app\models\HWReport;
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
                $this->showCSVReport();
                break;
            case 'summary':
                $this->generateGradeSummary();
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
    
    public function showCSVReport() {
        
    }
    
    public function generateGradeSummary() {
        
    }
    
    public function generateHWReports() {
        if(!isset($_REQUEST['csrf_token']) || $_REQUEST['csrf_token'] !== $this->core->getCsrfToken()) {
            $response = array('status' => 'error', 'message' => 'Invalid CSRF Token');
            $this->core->getOutput()->renderJson($response);
            return $response;
        }
        $hw_report = new HWReport($this->core);
        $hw_report->generateAllReports();
        $response = array('status' => 'success', 'message' => 'Successfully Updated HWReports');
        $this->core->getOutput()->renderJson($response);
        return $response;
    }
}
?>