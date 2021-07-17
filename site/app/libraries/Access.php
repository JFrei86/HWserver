<?php

namespace app\libraries;

use app\models\gradeable\Gradeable;
use app\models\gradeable\Component;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\Submitter;
use app\models\GradingSection;
use app\models\User;
use InvalidArgumentException;

class Access {
    // Access control options

    /** Allow Instructors to do this */
    const ALLOW_INSTRUCTOR              = 1 << 0;
    /** Allow full access graders to do this */
    const ALLOW_FULL_ACCESS_GRADER      = 1 << 1;
    /** Allow limited access graders to do this */
    const ALLOW_LIMITED_ACCESS_GRADER   = 1 << 2;
    /** Allow students to do this */
    const ALLOW_STUDENT                 = 1 << 3;
    /** Allow logged out users to do this */
    const ALLOW_LOGGED_OUT              = 1 << 4;
    /**
     * Check that the current user is at or above the minimum grading group required for a gradeable
     * If the gradeable has peer grading, this will also accept for students
     */
    const CHECK_GRADEABLE_MIN_GROUP     = 1 << 5 | self::REQUIRE_ARG_GRADEABLE;
    /**
     * Check that a given user is in the current user's grading section for a gradeable
     * Only applies to limited access graders
     */
    const CHECK_GRADING_SECTION_GRADER  = 1 << 6 | self::REQUIRE_ARG_GRADEABLE;
    /**
     * Check that a given user is in the current user's peer grading assignment for a gradeable
     * Only applies to students
     */
    const CHECK_PEER_ASSIGNMENT_STUDENT = 1 << 7 | self::REQUIRE_ARG_GRADEABLE;
    /** Require that the given gradeable have an active version / submission */
    const CHECK_HAS_SUBMISSION          = 1 << 8 | self::REQUIRE_ARG_GRADEABLE;
    /** Check that a valid CSRF token was passed in the request */
    const CHECK_CSRF                    = 1 << 9;
    /** Allow access if the gradeable is our own, even if sections are checked */
    const ALLOW_SELF_GRADEABLE          = 1 << 10 | self::REQUIRE_ARG_GRADEABLE;
    /** Only allow access if the gradeable is our own */
    const ALLOW_ONLY_SELF_GRADEABLE     = 1 << 11 | self::REQUIRE_ARG_GRADEABLE;
    /**
     * Check if the given component allows peer grading
     * Only applies to students
     */
    const CHECK_COMPONENT_PEER_STUDENT  = 1 << 12 | self::REQUIRE_ARG_COMPONENT;
    /** Check if they can access the given file and directory */
    const CHECK_FILE_DIRECTORY          = 1 << 13 | self::REQUIRE_ARGS_DIR_PATH;
    /** Require that the given file exists */
    const CHECK_FILE_EXISTS             = 1 << 14 | self::REQUIRE_ARGS_DIR_PATH;
    /**
     * Check that students are allowed to view the given gradeable
     * Only applies to students
     */
    const CHECK_STUDENT_VIEW            = 1 << 15 | self::REQUIRE_ARG_GRADEABLE;
    /**
     * Check that students are allowed to submit the given gradeable
     * Only applies to students
     */
    const CHECK_STUDENT_SUBMIT = 1 << 16 | self::REQUIRE_ARG_GRADEABLE | self::REQUIRE_ARG_VERSION;

    /** Check that the course status is such that the user can view the course */
    const CHECK_COURSE_STATUS           = 1 << 18;

    /** If the current set of flags requires the "gradeable" (type Gradeable) argument */
    const REQUIRE_ARG_GRADEABLE         = 1 << 24;
    /** If the current set of flags requires the "component" (type GradeableComponent) argument */
    const REQUIRE_ARG_COMPONENT         = 1 << 25;
    /** If the current set of flags requires the "dir" (type string) and "path" (type string) arguments */
    const REQUIRE_ARGS_DIR_PATH         = 1 << 26;
    /** If the current set of flags requires the "gradeable_version" (type int) argument */
    const REQUIRE_ARG_VERSION           = 1 << 27;
    /** If the current set of flags requires the "semester" (type string) and "course" (type string) arguments */
    const REQUIRE_ARGS_SEMESTER_COURSE  = 1 << 28;
    /** Ensure on the forum the operation is done by the correct user. */
    const REQUIRE_FORUM_SAME_STUDENT    = 1 << 29;


    // Broader user group access cases since generally actions are "minimum this group"

    const ALLOW_MIN_STUDENT               = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER | self::ALLOW_LIMITED_ACCESS_GRADER | self::ALLOW_STUDENT;
    const ALLOW_MIN_LIMITED_ACCESS_GRADER = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER | self::ALLOW_LIMITED_ACCESS_GRADER;
    const ALLOW_MIN_FULL_ACCESS_GRADER    = self::ALLOW_INSTRUCTOR | self::ALLOW_FULL_ACCESS_GRADER;
    const ALLOW_MIN_INSTRUCTOR            = self::ALLOW_INSTRUCTOR;
    const DENY_ALL                        = -1;

    /**
     * @var Core
     */
    private $core;
    /**
     * @var int[] $permissions
     */
    private $permissions = [];
    /**
     * @var array[] $directories
     */
    private $directories = null;

    public function __construct(Core $core) {
        $this->core = $core;

        // TODO: these are new actions that should be audited
        $this->permissions["grading.electronic.view_component"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_COMPONENT_PEER_STUDENT | self::CHECK_PEER_ASSIGNMENT_STUDENT;


        $this->permissions["course.view"] = self::ALLOW_MIN_STUDENT | self::REQUIRE_ARGS_SEMESTER_COURSE | self::CHECK_COURSE_STATUS;

        $this->permissions["grading.electronic.status"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.electronic.status.full"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.status.warnings"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.peer_panel"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.electronic.details"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP;
        $this->permissions["grading.electronic.details.show_all"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.details.show_all_no_sections"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.details.show_empty_teams"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.grade"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.electronic.grade.if_no_sections_exist"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.save_mark"] = self::CHECK_CSRF | self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_GRADEABLE_MIN_GROUP;
        $this->permissions["grading.electronic.save_component"] = self::CHECK_CSRF | self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.add_component"] = self::CHECK_CSRF | self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.delete_component"] = self::CHECK_CSRF | self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.save_graded_component"] = self::CHECK_CSRF | self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::CHECK_HAS_SUBMISSION | self::CHECK_COMPONENT_PEER_STUDENT;
        $this->permissions["grading.electronic.save_general_comment"] = self::CHECK_CSRF | self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::CHECK_HAS_SUBMISSION;
        $this->permissions["grading.electronic.view_component_grade"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::CHECK_COMPONENT_PEER_STUDENT;
        $this->permissions["grading.electronic.get_gradeable_comment"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT;
        $this->permissions["grading.electronic.add_new_mark"] = self::CHECK_CSRF | self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.electronic.delete_mark"] = self::CHECK_CSRF | self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.electronic.get_marked_users"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP;
        $this->permissions["grading.electronic.get_marked_users.full_stats"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.show_edit_teams"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.import_teams"] = self::ALLOW_MIN_INSTRUCTOR | self::CHECK_CSRF;
        $this->permissions["grading.electronic.export_teams"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.submit_team_form"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.verify_grader"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.verify_all"] = self::CHECK_CSRF | self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.electronic.silent_edit"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["grading.electronic.export_components"] = self::ALLOW_MIN_INSTRUCTOR; // this doesn't need to be instructor, but they're the only ones who will do this
        $this->permissions["grading.electronic.grade_inquiry"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADING_SECTION_GRADER | self::ALLOW_SELF_GRADEABLE;

        $this->permissions["autograding.load_checks"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::ALLOW_SELF_GRADEABLE;
        $this->permissions["autograding.show_hidden_cases"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;


        $this->permissions["grading.simple"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER;
        $this->permissions["grading.simple.grade"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER;
        $this->permissions["grading.simple.show_all"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["grading.simple.upload_csv"] = self::ALLOW_MIN_FULL_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP;

        $this->permissions["gradeable.submit.everyone"] = self::ALLOW_MIN_FULL_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP;

        //General path read/write checks
        $this->permissions["path.read"] = self::ALLOW_MIN_STUDENT | self::CHECK_FILE_DIRECTORY | self::CHECK_FILE_EXISTS;
        $this->permissions["path.write"] = self::ALLOW_MIN_STUDENT | self::CHECK_CSRF | self::CHECK_FILE_DIRECTORY;

        //Per-directory access permissions

        //NOTE: These do not contain CHECK_FILE_DIRECTORY because they are more generic
        // "can I access anything in this directory at all" checks. If you want to check if someone has access
        // to a specific file then use path.read or path.write
        $this->permissions["path.read.uploads"] = self::ALLOW_MIN_INSTRUCTOR;
        $this->permissions["path.read.split_pdf"] = self::ALLOW_MIN_FULL_ACCESS_GRADER;
        $this->permissions["path.read.site"] = self::ALLOW_MIN_STUDENT;
        //TODO: Timed access control
        $this->permissions["path.read.course_materials"] = self::ALLOW_MIN_STUDENT;
        //TODO: Check deleted posts
        $this->permissions["path.read.forum_attachments"] = self::ALLOW_MIN_STUDENT;
        $this->permissions["path.read.results"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_HAS_SUBMISSION;
        $this->permissions["path.read.results_public"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::ALLOW_SELF_GRADEABLE | self::CHECK_HAS_SUBMISSION | self::CHECK_STUDENT_VIEW | self::CHECK_STUDENT_SUBMIT;
        $this->permissions["path.read.submissions"] = self::ALLOW_MIN_STUDENT | self::CHECK_GRADEABLE_MIN_GROUP | self::CHECK_GRADING_SECTION_GRADER | self::CHECK_PEER_ASSIGNMENT_STUDENT | self::ALLOW_SELF_GRADEABLE | self::CHECK_HAS_SUBMISSION | self::CHECK_STUDENT_VIEW | self::CHECK_STUDENT_SUBMIT;
        $this->permissions["path.read.rainbow_grades"] = self::ALLOW_INSTRUCTOR | self::CHECK_FILE_DIRECTORY | self::CHECK_FILE_EXISTS;

        $this->permissions["path.write.submissions"] = self::ALLOW_MIN_STUDENT | self::ALLOW_ONLY_SELF_GRADEABLE | self::CHECK_CSRF;
        $this->permissions["path.write.split_pdf"] = self::ALLOW_MIN_FULL_ACCESS_GRADER | self::CHECK_CSRF;
        $this->permissions["path.write.uploads"] = self::ALLOW_MIN_INSTRUCTOR | self::CHECK_CSRF;
        $this->permissions["path.write.site"] = self::ALLOW_MIN_INSTRUCTOR | self::CHECK_CSRF;
        $this->permissions["path.write.checkout"] = self::DENY_ALL | self::CHECK_CSRF;
        $this->permissions["path.write.results"] = self::DENY_ALL | self::CHECK_CSRF;
        $this->permissions["path.write.results_public"] = self::DENY_ALL | self::CHECK_CSRF;
        $this->permissions["path.write.course_materials"] = self::ALLOW_MIN_INSTRUCTOR  | self::CHECK_CSRF | self::CHECK_FILE_DIRECTORY;
        $this->permissions["path.write.rainbow_grades"] = self::ALLOW_INSTRUCTOR | self::CHECK_CSRF | self::CHECK_FILE_DIRECTORY;
        $this->permissions["path.write.forum_attachments"] = self::ALLOW_MIN_STUDENT | self::CHECK_CSRF;


        //Forum permissions
        $this->permissions["forum.view_modify_category"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER;//allows you to view the page to modify forum categorys
        $this->permissions["forum.modify_category"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_CSRF;//allows you to actually modify the categorys
        $this->permissions["forum.publish"] = self::ALLOW_MIN_STUDENT | self::CHECK_CSRF;
        $this->permissions["forum.modify_announcement"] = self::ALLOW_MIN_FULL_ACCESS_GRADER | self::CHECK_CSRF;
        $this->permissions["forum.modify_post"] = self::ALLOW_MIN_STUDENT | self::CHECK_CSRF | self::REQUIRE_FORUM_SAME_STUDENT;
        $this->permissions["forum.merge_thread"] = self::ALLOW_MIN_LIMITED_ACCESS_GRADER | self::CHECK_CSRF;

        $this->permissions["admin.wrapper"] = self::ALLOW_MIN_INSTRUCTOR;
    }

    /**
     * Load directory access paths
     * Needs to be later because the constructor is called before the config is loaded
     */
    private function loadDirectories() {
        $this->directories["annotations"] = [
            //Base directory on disk where files are located. If you try to access anything via this directory
            // the path needs to start with this base.
            "base" => $this->core->getConfig()->getCoursePath() . "/annotations",
            //After the base path, these are additional directories with specific naming conventions to sort
            // files. Possible values are "gradeable", "submitter", "version", "thread", and "post"
            // See canUserAccessFile to update that list
            "subparts" => ["gradeable", "submitter", "version"],
            //Extra permissions checking to be done when doing various actions. When canUserAccessFile is
            // called with a key from this array, canUser will be called with the value. Any parsed args from
            // the subparts array will be passed.
            "permissions" => [
                "path.read" => "path.read.submissions",
                "path.write" => "path.write.submissions",
            ]
        ];
        $this->directories["annotated_pdfs"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/annotated_pdfs",
            "subparts" => ["gradeable", "submitter", "version"],
            "permissions" => [
                "path.read" => "path.read.submissions",
                "path.write" => "path.write.submissions",
            ]
        ];
        $this->directories["checkout"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/checkout",
            "subparts" => ["gradeable", "submitter", "version"],
            "permissions" => [
                "path.read" => "path.read.submissions",
                "path.write" => "path.write.checkout",
            ]
        ];
        $this->directories["submissions"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/submissions",
            "subparts" => ["gradeable", "submitter", "version"],
            "permissions" => [
                "path.read" => "path.read.submissions",
                "path.write" => "path.write.submissions",
            ]
        ];
        $this->directories["results"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/results",
            "subparts" => ["gradeable", "submitter", "version"],
            "permissions" => [
                "path.read" => "path.read.results",
                "path.write" => "path.write.results",
            ]
        ];
        $this->directories["results_public"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/results_public",
            "subparts" => ["gradeable", "submitter", "version"],
            "permissions" => [
                "path.read" => "path.read.results_public",
                "path.write" => "path.write.results_public",
            ]
        ];
        $this->directories["config_upload"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/config_upload",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.uploads",
                "path.write" => "path.write.uploads",
            ]
        ];
        $this->directories["uploads"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/uploads",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.uploads",
                "path.write" => "path.write.uploads",
            ]
        ];
        $this->directories["split_pdf"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/uploads/split_pdf",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.split_pdf",
                "path.write" => "path.write.split_pdf",
            ]
        ];
        $this->directories["site"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/site",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.site",
                "path.write" => "path.write.site",
            ]
        ];
        $this->directories["course_materials"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/uploads/course_materials",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.course_materials",
                "path.write" => "path.write.course_materials",
            ]
        ];
        $this->directories["forum_attachments"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/forum_attachments",
            "subparts" => ["thread", "post"],
            "permissions" => [
                "path.read" => "path.read.forum_attachments",
                "path.write" => "path.write.forum_attachments",
            ]
        ];
        $this->directories["rainbow_grades"] = [
            "base" => $this->core->getConfig()->getCoursePath() . "/uploads/rainbow_grades",
            "subparts" => [],
            "permissions" => [
                "path.read" => "path.read.rainbow_grades",
                "path.write" => "path.write.rainbow_grades",
            ]
        ];
    }

    /**
     * Check if the currently logged in user is allowed to do an action, shortcut of
     * $access->canUser($core->getUser(), ...)
     * @param string $action Name of the action (see Access::$permissions)
     * @param array $args Any extra arguments that are required to check permissions
     * @return bool True if they are allowed to do that action
     */
    public function canI(string $action, $args = []) {
        $user = $args["user"] ?? $this->core->getUser();
        return $this->canUser($user, $action, $args);
    }

    /**
     * Check if a user is allowed to do an action
     * @param User|null $user User to check access for
     * @param string $action Name of the action (see Access::$permissions)
     * @param array $args Any extra arguments that are required to check permissions
     * @return bool True if they are allowed to do that action
     */
    public function canUser($user, string $action, $args) {
        if (!array_key_exists($action, $this->permissions)) {
            throw new InvalidArgumentException("Unknown action '$action'");
        }
        $checks = $this->permissions[$action];

        //Because sometimes we need to explicitly deny permissions to everyone
        if ($checks === self::DENY_ALL) {
            return false;
        }

        //Some things may be available when there is no user
        if ($user === null) {
            if (!($checks & self::ALLOW_LOGGED_OUT)) {
                return false;
            }
            $group = User::GROUP_NONE;
        }
        else {
            $group = $user->getGroup();
        }

        //Check user group first
        if ($group === User::GROUP_STUDENT && !self::checkBits($checks, self::ALLOW_STUDENT)) {
            return false;
        }
        elseif ($group === User::GROUP_LIMITED_ACCESS_GRADER && !self::checkBits($checks, self::ALLOW_LIMITED_ACCESS_GRADER)) {
            return false;
        }
        elseif ($group === User::GROUP_FULL_ACCESS_GRADER && !self::checkBits($checks, self::ALLOW_FULL_ACCESS_GRADER)) {
            return false;
        }
        elseif ($group === User::GROUP_INSTRUCTOR && !self::checkBits($checks, self::ALLOW_INSTRUCTOR)) {
            return false;
        }

        if (self::checkBits($checks, self::CHECK_CSRF)) {
            if (!$this->core->checkCsrfToken()) {
                return false;
            }
        }

        if (self::checkBits($checks, self::REQUIRE_FORUM_SAME_STUDENT)) {
            if ($group === User::GROUP_STUDENT && array_key_exists('post_author', $args) && $this->core->getUser()->getId() != $args['post_author']) {
                return false;
            }
        }

        /** @var GradedGradeable|null $graded_gradeable */
        $graded_gradeable = null;
        /** @var Gradeable|null $gradeable */
        $gradeable = null;
        if (self::checkBits($checks, self::REQUIRE_ARG_GRADEABLE)) {
            if (array_key_exists("graded_gradeable", $args)) {
                $graded_gradeable = $args["graded_gradeable"];
                $gradeable = $graded_gradeable->getGradeable();
            }
            else {
                $gradeable = $this->requireArg($args, "gradeable");
                if ($gradeable === null) {
                    return false;
                }
            }

            //Check if they pass the grading-related checks. There are overrides at the end so
            // we can't just immediately return false.
            $grading_checks = true;

            if ($grading_checks && self::checkBits($checks, self::CHECK_GRADEABLE_MIN_GROUP)) {
                //Make sure they meet the minimum requirements
                if (!$this->checkGroupPrivilege($group, $gradeable->getMinGradingGroup())) {
                    if (
                        //Full access graders are allowed to view submissions if there is no manual grading
                        !($group === User::GROUP_FULL_ACCESS_GRADER && !$gradeable->isTaGrading())
                        &&
                        //Students are allowed to see this if its a peer graded assignment
                        !((($group === User::GROUP_STUDENT && $gradeable->hasPeerComponent()) || $group === User::GROUP_LIMITED_ACCESS_GRADER) && $gradeable->getGradeStartDate() <= $this->core->getDateTimeNow())
                    ) {
                        //Otherwise, you're not allowed
                        $grading_checks = false;
                    }
                }
            }

            if ($grading_checks && self::checkBits($checks, self::CHECK_HAS_SUBMISSION)) {
                if ($graded_gradeable !== null && $graded_gradeable->getAutoGradedGradeable()->getActiveVersion() <= 0) {
                    $grading_checks = false;
                }
            }

            if ($grading_checks && self::checkBits($checks, self::CHECK_GRADING_SECTION_GRADER) && $group === User::GROUP_LIMITED_ACCESS_GRADER) {
                //Check their grading section
                if (array_key_exists("section", $args)) {
                    if (!$this->isSectionInGradingSections($gradeable, $args["section"], $user)) {
                        $grading_checks = false;
                    }
                }
                else {
                    //If graded gradeable is null then we're asking if we can grade anything in this gradeable, which we can.
                    // If a graded gradeable is passed then we need to make sure we can grade that specific graded gradeable.
                    if ($graded_gradeable !== null) {
                        if (!$this->isGradedGradeableInGradingSections($graded_gradeable, $user)) {
                            $grading_checks = false;
                        }
                    }
                }
            }

            if ($grading_checks && self::checkBits($checks, self::CHECK_PEER_ASSIGNMENT_STUDENT) && $group === User::GROUP_STUDENT) {
                //Check their peer assignment
                if (!$this->isGradeableInStudentPeerAssignment($gradeable, $user)) {
                    $grading_checks = false;
                }
            }

            //Sometimes they're allowed to view their own even if the other checks fail
            if (!$grading_checks && self::checkBits($checks, self::ALLOW_SELF_GRADEABLE) && $this->isGradedGradeableByUser($graded_gradeable, $user)) {
                $grading_checks = true;
            }
            //Sometimes they're only allowed to access their own gradeable, even if they are able to
            // grade another (eg students cannot edit others' files during peer grading)
            if ($grading_checks && self::checkBits($checks, self::ALLOW_ONLY_SELF_GRADEABLE) && !$this->isGradedGradeableByUser($graded_gradeable, $user)) {
                $grading_checks = false;
            }

            if (!$grading_checks) {
                //Not allowed to do this action to this gradeable
                return false;
            }

            //As these are not grading-related they can return false immediately
            if ($group === User::GROUP_STUDENT) {
                if (self::checkBits($checks, self::CHECK_STUDENT_VIEW)) {
                    if (!$gradeable->isStudentView()) {
                        return false;
                    }
                }
            }


            if (self::checkBits($checks, self::REQUIRE_ARG_VERSION)) {
                /* @var int $version */
                $version = $this->requireArg($args, "gradeable_version");
                $can_submit = $gradeable->isStudentSubmit();
                // Can only view active version for non-student-submit assignment
                if ($group === User::GROUP_STUDENT && !$can_submit) {
                    if ($graded_gradeable !== null && $version !== $graded_gradeable->getAutoGradedGradeable()->getActiveVersion()) {
                        return false;
                    }
                }
            }
        }

        if (self::checkBits($checks, self::REQUIRE_ARG_COMPONENT)) {
            /* @var Component|null $component */
            $component = $this->requireArg($args, "component");
            if ($component === null) {
                return false;
            }

            if (self::checkBits($checks, self::CHECK_COMPONENT_PEER_STUDENT) && $group === User::GROUP_STUDENT) {
                //Make sure a component allows students to access it via peer grading
                if (!$component->isPeerComponent()) {
                    return false;
                }
            }
        }

        //These are always done together
        if (self::checkBits($checks, self::REQUIRE_ARGS_DIR_PATH)) {
            $dir = $this->requireArg($args, "dir");
            $path = $this->requireArg($args, "path");

            if ($this->directories === null) {
                $this->loadDirectories();
            }
            //This is not a valid directory
            //checks for top level dirs like annotations, checkout, course_materials, submissions ... )
            if (!array_key_exists($dir, $this->directories)) {
                return false;
            }
            elseif (!file_exists($path)) {
                //checks for the existense of path which is asked for
                return false;
            }

            //Check if they can access the path!
            if (!$this->canUserAccessPath($action, $path, $dir, $user, $args)) {
                return false;
            }
        }

        if (self::checkBits($checks, self::REQUIRE_ARGS_SEMESTER_COURSE)) {
            $semester = $this->requireArg($args, "semester");
            $course = $this->requireArg($args, "course");
            if (self::checkBits($checks, self::CHECK_COURSE_STATUS)) {
                $course_status = $this->core->getQueries()->getCourseStatus($semester, $course);
                // only instructors should be able to access courses with status archived==2
                if ($course_status === 2 && $group !== User::GROUP_INSTRUCTOR) {
                    return false;
                }
                elseif ($group === User::GROUP_STUDENT && ($course_status !== 1 || $user->getRegistrationSection() === null)) {
                    // only students with a non-null registration section should be able to view courses (and only active==1 courses)
                    return false;
                }
                elseif ($course_status > 2) {
                    // no one can view courses with status greater than 2
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Require that a named key is in the list of args. Throws otherwise
     * @param array $args List of args
     * @param string $name Name of required arg
     * @return mixed Arg value
     */
    private function requireArg(array $args, string $name) {
        if (!array_key_exists($name, $args)) {
            throw new InvalidArgumentException("Missing argument '$name'");
        }
        return $args[$name];
    }

    /**
     * Check if a bit set matches a given bit mask
     * @param int $bits Bit set (list of flags)
     * @param int $test Bit mask (flags to check for)
     * @return bool If matches
     */
    private function checkBits(int $bits, int $test) {
        return ($bits & $test) === $test;
    }

    /**
     * Check if a Graded Gradeable's submitter is in a user's grading sections
     * @param GradedGradeable $graded_gradeable
     * @param User $user
     * @return bool If they are
     */
    public function isGradedGradeableInGradingSections($graded_gradeable, User $user) {
        $now = $this->core->getDateTimeNow();

        $gradeable = $graded_gradeable->getGradeable();

        //If a user is a limited access grader, and the gradeable is being graded, and the
        // gradeable can be viewed by limited access graders.
        //Check to see if the requested user is assigned to this grader.
        $sections = $gradeable->getGradingSectionsForUser($user);

        foreach ($sections as $section) {
            /** @var GradingSection $section */
            if ($gradeable->isTeamAssignment()) {
                if ($section->containsTeam($graded_gradeable->getSubmitter()->getTeam())) {
                    return true;
                }
            }
            else {
                if ($section->containsUser($graded_gradeable->getSubmitter()->getUser())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a section (by name) is one of the user's grading sections for a gradeable
     * @param Gradeable $gradeable
     * @param string $section Section name
     * @param User $user
     * @return bool
     */
    public function isSectionInGradingSections($gradeable, string $section, User $user) {
        $sections = $gradeable->getGradingSectionsForUser($user);
        foreach ($sections as $check_section) {
            /** @var GradingSection $check_section */
            if ($check_section->getName() === $section) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a Gradeable is in a user's peer grading assignment
     * @param Gradeable $gradeable Gradeable to be peer graded
     * @param User $user User doing the peer grading
     * @return bool
     */
    public function isGradeableInStudentPeerAssignment($gradeable, User $user) {
        if ($gradeable === null) {
            return false;
        }

        if (!$gradeable->hasPeerComponent()) {
            return false;
        }
        else {
            /*
            * When this check is run, the submitter of a gradeable is set to the grader, even on master.
            * This means the in_array will always be false. Hence the return true so that peer grading is even possible.
            */
            //$user_ids_to_grade = $this->core->getQueries()->getPeerAssignment($gradeable->getId(), $user->getId());
            //return in_array($graded_gradeable->getSubmitter()->getId(), $user_ids_to_grade);
            return true;
        }
    }

    /**
     * Check that a user group has privilege at least equal to minimum
     * @param int $check
     * @param int $minimum
     * @return bool
     */
    public function checkGroupPrivilege(int $check, int $minimum) {
        //Because access levels decrease as they get more powerful, this needs to be <=
        // If groups ever become non-sequential in the future, this needs to be replaced.
        return $check <= $minimum;
    }

    /**
     * Check if a User is one of the submitters of a Graded Gradeable
     * @param GradedGradeable $graded_gradeable Graded Gradeable object
     * @param User $user User to check
     * @return bool True if this is their Graded Gradeable or if they are on the team of this Graded Gradeable
     */
    public function isGradedGradeableByUser($graded_gradeable, User $user) {
        if ($graded_gradeable !== null) {
            if ($graded_gradeable->getSubmitter()->getTeam() !== null) {
                return $graded_gradeable->getSubmitter()->getTeam()->hasMember($user->getId());
            }
            return $graded_gradeable->getSubmitter()->getUser()->getId() === $user->getId();
        }
        return false;
    }

    /**
     * Check if a Submitter is one of the submitters of a Graded Gradeable
     * @param GradedGradeable $graded_gradeable Graded Gradeable object
     * @param Submitter $submitter Submitter to check
     * @return bool True if this is their Graded Gradeable or if they are on the team of this Graded Gradeable
     */
    public function isGradedGradeableBySubmitter($graded_gradeable, Submitter $submitter) {
        if ($graded_gradeable !== null) {
            if ($graded_gradeable->getSubmitter()->isTeam()) {
                if ($submitter->isTeam()) {
                    return $graded_gradeable->getSubmitter()->getId() === $submitter->getId();
                }
                else {
                    return $graded_gradeable->getSubmitter()->getTeam()->hasMember($submitter->getId());
                }
            }
            return $graded_gradeable->getSubmitter()->getId() === $submitter->getId();
        }
        return false;
    }

    /**
     * Check if a user has permissions to access a file/directory by a particular action
     * @param string $action Specific action, eg path.read
     * @param string $path File path, can be either absolute or relative
     * @param string $dir A directory name in $this->directories
     * @param User $user User to check access
     * @param array $args Additional arguments for specific checks
     * @return bool True if they are allowed to access this file
     */
    public function canUserAccessPath(string $action, string $path, string $dir, User $user, array $args = []) {
        if ($this->directories === null) {
            $this->loadDirectories();
        }
        if (!array_key_exists($dir, $this->directories)) {
            return false;
        }

        $checks = $this->permissions[$action];
        $info = $this->directories[$dir];

        //Get the real path
        $path = $this->resolveDirPath($dir, $path);
        $relative_path = substr($path, strlen($info["base"]) + 1);

        //If it doesn't exist we can't read it
        if (self::checkBits($checks, self::CHECK_FILE_EXISTS) && !file_exists($path)) {
            return false;
        }

        //Check if the relative path starts with the right directory
        $subpart_types = $info["subparts"];
        $subpart_values = explode("/", $relative_path);

        //Missing necessary directory path
        if (count($subpart_values) <= count($subpart_types)) {
            return false;
        }
        $end_path = implode("/", array_slice($subpart_values, count($subpart_types)));
        $subpart_values = array_slice($subpart_values, 0, count($subpart_types));
        $subpart_types[] = "path";
        $subpart_values[] = $end_path;

        //To array of [type, value]
        $subparts = array_combine($subpart_types, $subpart_values);

        //So we can extract parameters from the path
        foreach ($subpart_types as $type) {
            $value = $subparts[$type];
            switch ($type) {
                case "gradeable":
                    //If we already have a gradeable in the args, make sure this file actually belongs to it
                    if (array_key_exists("gradeable", $args)) {
                        $gradeable = $args["gradeable"];
                        if ($gradeable instanceof Gradeable) {
                            //Check if the gradeable matches
                            if ($gradeable->getId() !== $value) {
                                return false;
                            }
                        }
                    }
                    else {
                        $args["gradeable"] = $this->core->getQueries()->getGradeableConfig($value);
                    }
                    $hidden_files = $args["gradeable"]->getHiddenFiles();
                    foreach (explode(",", $hidden_files) as $file_regex) {
                        $file_regex = trim($file_regex);
                        if (fnmatch($file_regex, $subpart_values[count($subpart_values) - 1]) && $this->core->getUser()->getGroup() > 3) {
                            return false;
                        }
                    }
                    break;
                case "submitter":
                    $submitter = $this->core->getQueries()->getSubmitterById($value);
                    if ($submitter !== null) {
                        $args["submitter"] = $submitter;
                        if (array_key_exists("graded_gradeable", $subparts)) {
                            //If we already have a graded gradeable in the args, make sure this file
                            // actually belongs to it
                            $graded_gradeable = $args["graded_gradeable"];
                        }
                        elseif (array_key_exists("gradeable", $args)) {
                            $gradeable = $args["gradeable"];
                            $graded_gradeable = $this->core->getQueries()->getGradedGradeableForSubmitter($gradeable, $submitter);
                            $args["graded_gradeable"] = $graded_gradeable;
                        }
                        else {
                            return false;
                        }
                        if ($graded_gradeable === null || !($graded_gradeable instanceof GradedGradeable)) {
                            return false;
                        }

                        //Check that the given graded gradeable is the same as the
                        // one that this file is part of.
                        if (!$this->isGradedGradeableBySubmitter($graded_gradeable, $submitter)) {
                            return false;
                        }
                    }
                    break;
                case "version":
                    $args["gradeable_version"] = (int) $value;
                    break;
                case "thread":
                    $args["thread"] = (int) $value;
                    break;
                case "post":
                    $args["post"] = (int) $value;
                    break;
            }
        }

        //So now that we know the file is valid, we need to make sure they have the specific
        // permissions to access what the file is part of. Generally this is either a
        // gradeable check or a basic "is user group" check
        if (array_key_exists("permissions", $info) && array_key_exists($action, $info["permissions"])) {
            return $this->canUser($user, $info["permissions"][$action], $args);
        }

        //There isn't any extra permissions for this action, let em at it
        return true;
    }

    /**
     * Resolve relative (and absolute) file paths for a directory
     * @param string $dir Directory name
     * @param string $path
     * @return bool|string Absolute path of the file in that directory
     */
    public function resolveDirPath(string $dir, string $path) {
        if ($this->directories === null) {
            $this->loadDirectories();
        }
        if (!array_key_exists($dir, $this->directories)) {
            throw new InvalidArgumentException("Unknown directory {$dir}");
        }
        $info = $this->directories[$dir];

        //No directory traversal
        $orig_parts = explode(DIRECTORY_SEPARATOR, $path);
        $parts = [];
        foreach ($orig_parts as $part) {
            if ($part !== ".." && $part !== ".") {
                $parts[] = $part;
            }
        }
        $path = implode(DIRECTORY_SEPARATOR, $parts);

        //Make sure it starts with the dir base
        if (!Utils::startsWith($path, $info["base"])) {
            //This both prevents people from accessing files outside the base dir
            // and lets us have relative paths. Convenient!
            if ($path[0] === "/") {
                $path = substr($path, 1);
            }

            $path = $info["base"] . "/" . $path;
        }

        return $path;
    }
}
