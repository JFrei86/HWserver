<?php


namespace app\libraries;


use app\models\Gradeable;
use app\models\User;

class Access {
    const USER_GROUP_INSTRUCTOR            = 1;
    const USER_GROUP_FULL_ACCESS_GRADER    = 2;
    const USER_GROUP_LIMITED_ACCESS_GRADER = 3;
    const USER_GROUP_STUDENT               = 4;

    // Access control options

    /** Allow Instructors to do this */
    const ALLOW_INSTRUCTOR              = 1 << 0;
    /** Allow full access graders to do this */
    const ALLOW_FULL_ACCESS_GRADER      = 1 << 1;
    /** Allow limited access graders to do this */
    const ALLOW_LIMITED_ACCESS_GRADER   = 1 << 2;
    /** Allow students to do this */
    const ALLOW_STUDENT                 = 1 << 3;
    const ALLOW_LOGGED_OUT              = 1 << 4;
    const CHECK_GRADEABLE_MIN_GROUP     = 1 << 5;
    const CHECK_GRADING_SECTION_GRADER  = 1 << 6;
    const CHECK_PEER_ASSIGNMENT_STUDENT = 1 << 7;
    const CHECK_HAS_SUBMISSION          = 1 << 8;
    const CHECK_CSRF                    = 1 << 9;

    //
    const ALLOW_MIN_STUDENT    = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER | self::ALLOW_LIMITED_ACCESS_GRADER | self::ALLOW_STUDENT;
    const ALLOW_MIN_GRADER     = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER | self::ALLOW_LIMITED_ACCESS_GRADER;
    const ALLOW_MIN_TA         = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER;
    const ALLOW_MIN_INSTRUCTOR = self::ALLOW_INSTRUCTOR;

    /**
     * @var Core
     */
    private $core;
    private $permissions = [];

    public function __construct(Core $core) {
        $this->core = $core;

        $this->permissions["grading.details"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP;
        $this->permissions["grading.grade"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.show_hidden_cases"] = self::ALLOW_MIN_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.save_one_component"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::CHECK_HAS_SUBMISSION;
        $this->permissions["grading.save_general_comment"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::CHECK_HAS_SUBMISSION;
        $this->permissions["grading.get_mark_data"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.get_gradeable_comment"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.add_one_new_mark"] = self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.delete_one_mark"] = self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.import_teams"] = self::ALLOW_MIN_INSTRUCTOR | self::CHECK_CSRF;
        $this->permissions["grading.export_teams"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.submit_team_form"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.verify_grader"] = self::ALLOW_MIN_TA;
        $this->permissions["grading.verify_all"] = self::ALLOW_MIN_TA;
    }

    /**
     * Check if the currently logged in user is allowed to do an action
     * @param string $action Name of the action (see Access::$permissions)
     * @param array $args Any extra arguments that are required to check permissions
     * @return bool True if they are allowed to do that action
     */
    public function canI(string $action, $args = []) {
        if (!array_key_exists($action, $this->permissions)) {
            return false;
        }
        $checks = $this->permissions[$action];

        //Some things may be available when there is no user
        $user = $this->core->getUser();
        if ($user === null) {
            return !!($checks & self::ALLOW_LOGGED_OUT);
        }
        //Check user group first
        $group = $user->getGroup();
        if ($group === self::USER_GROUP_STUDENT && !($checks & self::ALLOW_STUDENT)) {
            return false;
        } else if ($group === self::USER_GROUP_LIMITED_ACCESS_GRADER && !($checks & self::ALLOW_LIMITED_ACCESS_GRADER)) {
            return false;
        } else if ($group === self::USER_GROUP_FULL_ACCESS_GRADER && !($checks & self::ALLOW_FULL_ACCESS_GRADER)) {
            return false;
        } else if ($group === self::USER_GROUP_INSTRUCTOR && !($checks & self::ALLOW_INSTRUCTOR)) {
            return false;
        }

        if ($checks & self::CHECK_CSRF) {
            if ($this->core->checkCsrfToken($_POST['csrf_token'])) {
                return false;
            }
        }

        if ($checks & self::CHECK_GRADEABLE_MIN_GROUP) {
            /* @var Gradeable|null $gradeable */
            $gradeable = $args["gradeable"] ?? null;
            if ($group > $gradeable->getMinimumGradingGroup()) {
                return false;
            }
        }

        if ($checks & self::CHECK_HAS_SUBMISSION) {
            /* @var Gradeable|null $gradeable */
            $gradeable = $args["gradeable"] ?? null;
            if ($gradeable->getActiveVersion() <= 0) {
                return false;
            }
        }

        if ($group === self::USER_GROUP_LIMITED_ACCESS_GRADER && ($checks & self::CHECK_GRADING_SECTION_GRADER)) {
            /* @var Gradeable|null $gradeable */
            $gradeable = $args["gradeable"] ?? null;
            //Check their grading section
            if (!$this->checkGradingSection($gradeable)) {
                return false;
            }
        }
        if ($group === self::USER_GROUP_STUDENT && ($checks & self::CHECK_PEER_ASSIGNMENT_STUDENT)) {
            /* @var Gradeable|null $gradeable */
            $gradeable = $args["gradeable"] ?? null;
            //Check their peer assignment
            if (!$this->checkPeerAssignment($gradeable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a limited access grader has a user in their section
     * @param Gradeable $gradeable
     * @return bool If they are
     */
    public function checkGradingSection(Gradeable $gradeable) {
        $now = new \DateTime("now", $this->core->getConfig()->getTimezone());

        //If a user is a limited access grader, and the gradeable is being graded, and the
        // gradeable can be viewed by limited access graders.
        if ($gradeable->getGradeStartDate() <= $now) {
            //Check to see if the requested user is assigned to this grader.
            if ($gradeable->isGradeByRegistration()) {
                $sections = $this->core->getUser()->getGradingRegistrationSections();
                $students = $this->core->getQueries()->getUsersByRegistrationSections($sections);
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($gradeable->getId(),
                    $this->core->getUser()->getId());
                $students = $this->core->getQueries()->getUsersByRotatingSections($sections);
            }
            foreach($students as $student) {
                /* @var User $student */
                if($student->getId() === $gradeable->getUser()->getId()){
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a student is allowed to peer grade another
     * @param Gradeable $gradeable
     * @return bool
     */
    public function checkPeerAssignment(Gradeable $gradeable) {
        if(!$gradeable->getPeerGrading()) {
            return false;
        } else {
            $user_ids_to_grade = $this->core->getQueries()->getPeerAssignment($gradeable->getId(), $this->core->getUser()->getId());
            return in_array($gradeable->getUser()->getId(), $user_ids_to_grade);
        }
    }
}