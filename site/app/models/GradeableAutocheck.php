<?php

namespace app\models;

use app\libraries\DiffViewer;
use app\libraries\Utils;

/**
 * Class GradeableAutocheck
 *
 * Contains information pertaining to the autocheck element that's contained within a
 * GradeableTestcase. There is 0+ autochecks per GradeableTestcase.
 *
 * @method DiffViewer getDiffViewer()
 * @method string getDescription()
 * @method String[] getMessages()
 */
class GradeableAutocheck extends AbstractModel {
    
    /** @var string */
    protected $index;
    
    /** @var DiffViewer DiffViewer instance to hold the student, instructor, and differences */
    protected $diff_viewer;
    
    /** @var string Description to show for displaying the diff */
    protected $description = "";
    
    /** @var String[] Message to show underneath the description for a diff */
    protected $messages = array();
    /** @var */
    protected $messages2 = array();
    
    /**
     * GradeableAutocheck constructor.
     *
     * @param $details
     * @param $course_path
     * @param $result_path
     * @param $idx
     */
    public function __construct($details, $course_path, $result_path, $idx) {
        parent::__construct();
        $this->index = $idx;
        
        if (isset($details['description'])) {
            $this->description = Utils::prepareHtmlString($details['description']);
        }
        
        if (isset($details['messages'])) {
            foreach ($details['messages'] as $message) {
                if (isset($message['message']) && isset($message['color']))
                    $this->messages2[] = array(
                        'message' => Utils::prepareHtmlString($message['message']),
                        'color' => Utils::prepareHtmlString($message['color']));
                else
                    $this->messages[] = Utils::prepareHtmlString($message);
            }
        }
        
        $actual_file = $expected_file = $difference_file = "";

        if(isset($details["actual_file"]) && file_exists($result_path . "/" . $details["actual_file"])) {
            $actual_file = $result_path . "/" . $details["actual_file"];
        }
    
        if(isset($details["expected_file"]) &&
            file_exists($course_path . "/" . $details["expected_file"])) {
            $expected_file = $course_path . "/" . $details["expected_file"];
        }
    
        if(isset($details["difference_file"]) && file_exists($result_path . "/" . $details["difference_file"])) {
            $difference_file = $result_path . "/" . $details["difference_file"];
        }
        
        $this->diff_viewer = new DiffViewer($actual_file, $expected_file, $difference_file, $this->index);
    }
}
