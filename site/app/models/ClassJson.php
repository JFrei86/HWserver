<?php

namespace app\models;

use app\libraries\Core;
use app\libraries\FileUtils;

/**
 * Class ClassJson
 *
 * Model representing the class.json that exists for any given course. Additionally, it contains a model
 * for the current assignment that is being looked at by the client (either latest assignment or one
 * specified by the client). This model is then used to build the Submission page for students.
 */
class ClassJson {
    /**
     * @var Core
     */
    private $core;

    private $class;
    
    /**
     * @var array()
     */
    private $allowed_assignments = null;

    /**
     * @var Assignment
     */
    private $assignment = null;

    public function __construct(Core $core, $assignment = null) {
        $this->core = $core;
        $this->class = FileUtils::loadJsonFile($this->core->getConfig()->getCoursePath()."/config/class.json");
        $this->getAssignments();
        
        if ($assignment === null || !array_key_exists($assignment, $this->allowed_assignments)) {
            $array = array_slice($this->allowed_assignments, -1);
            $assignment = array_pop($array);
        }
        else {
            $assignment = $this->allowed_assignments[$assignment];
        }
        

        if ($assignment !== null) {
            $this->assignment = new Assignment($this->core, $assignment);
        }
    }

    public function getAllAssignments() {
        return $this->class['assignments'];
    }
    
    /**
     * Returns an array containing all assignment_ids that the logged in user is allowed to acccess, whether
     * the assignment has been released or the user is an admin (and then can see all assignments regardless
     * of whether they've been released or not.
     *
     * @return array
     */
    public function getAssignments() {
        if ($this->allowed_assignments === null) {
            $this->allowed_assignments = array();
            foreach ($this->getAllAssignments() as $assignment) {
                if ($this->core->getUser()->accessAdmin() || $assignment['released'] === true) {
                    $this->allowed_assignments[$assignment['assignment_id']] = $assignment;
                }
            }
        }
        return $this->allowed_assignments;
    }

    /**
     * @return Assignment
     */
    public function getCurrentAssignment() {
        return $this->assignment;
    }
    
    public function isValidAssignment() {
        
    }
}