<?php
namespace app\views\admin;

use app\views\AbstractView;

class PlagiarismView extends AbstractView {
    public function plagiarismCompare($studenta, $studentb) {
        $return = "";
        $return .= <<<HTML
<div class="content">
HTML;
        $return .= file_get_contents("/var/local/submitty/courses/f17/development/plagiarism/report/var/local/submitty/courses/f17/development/submissions/cpp_cats/compare/" . $studenta . "_" . $studentb . ".html");
        $return .= <<<HTML
</div>
HTML;
        return $return;
    }

    public function plagiarismIndex($semester, $course, $assignment) {
        $return = "";
        $return .= <<<HTML
<div class="content">
HTML;
        $return .= file_get_contents("/var/local/submitty/courses/$semester/$course/plagiarism/report/var/local/submitty/courses/$semester/$course/submissions/$assignment/index.html");
        $return .= <<<HTML
</div>
HTML;
        return $return;
    }

    public function plagiarismIndex($semester, $course, $assignments) {
        $return = "";
        $return .= <<<HTML
<div class="content"><ul>
HTML;
        foreach ($assignments as $assignment) {
            $return .= "<li><a href=\"{$this->core->buildUrl(array('component' => 'admin', 'page' => 'plagiarism', 'action' => 'index', 'assignment' => $assignment))}\">$assignment</a></li>
        }
        $return .= <<<HTML
</ul></div>
HTML;
        return $return;
    }
}
