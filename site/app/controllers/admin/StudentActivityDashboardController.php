<?php

namespace app\controllers\admin;

use app\controllers\AbstractController;
use app\libraries\routers\AccessControl;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\response\WebResponse;
use app\libraries\FileUtils;

/**
 * Class StudentActivityDashboardController
 * @package app\controllers\admin
 */

class StudentActivityDashboardController extends AbstractController {
  /**
   * @Route("/courses/{_semester}/{_course}/activity", methods={"GET"})
   * @AccessControl(role="INSTRUCTOR")
   */
    public function getStudents() {
        $data_dump = $this->core->getQueries()->getAttendanceInfo();
        return new WebResponse([
            'admin',
            'StudentActivityDashboard'
        ], 'createTable', $data_dump);
    }

   /**
    * @Route("/courses/{_semester}/{_course}/activity/download", methods={"GET"})
    * @AccessControl(role="INSTRUCTOR")
    */
    public function downloadData() {
        $data_dump = $this->core->getQueries()->getAttendanceInfo();
        $file_url = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            'tmp'
        );

        if (!FileUtils::createDir($file_url)) {
            return;
        }

        $file_url = FileUtils::joinPaths(
            $file_url,
            'Student_Activity.csv'
        );

        $fp = fopen($file_url, 'w');
        fputcsv($fp, ["Registration Section", "User ID", "First Name", "Last Name", "Gradeable Access Date", "Gradeable Submission Date",
            "Forum View Date", "Number of Poll Responses", "Office Hours Queue Date"]);
        foreach ($data_dump as $rows) {
            fputcsv($fp, $rows);
        }

        return new WebResponse([
            'admin',
            'StudentActivityDashboard'
        ], 'downloadFile', $file_url);
    }
}
