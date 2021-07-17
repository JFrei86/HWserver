<?php

namespace app\models\gradeable;

use app\libraries\DateUtils;
use app\libraries\GradeableType;
use app\exceptions\ValidationException;
use app\exceptions\NotImplementedException;
use app\libraries\Utils;
use app\libraries\FileUtils;
use app\libraries\Core;
use app\models\AbstractModel;
use app\models\grading\AbstractGradeableInput;
use app\models\GradingSection;
use app\models\Team;
use app\models\User;
use app\controllers\admin\AdminGradeableController;

/**
 * All data describing the configuration of a gradeable
 *  Note: All per-student data is in the GradedGradeable class
 *
 *  Note: there is no guarantee of the values of properties not relevant to the gradeable type
 *
 *  Missing validation: student permissions (i.e. view/submit) - low priority
 *
 * @method string getId()
 * @method string getTitle()
 * @method string getInstructionsUrl()
 * @method void setInstructionsUrl($url)
 * @method int getType()
 * @method int getGraderAssignmentMethod()
 * @method void setGraderAssignmentMethod($method)
 * @method \DateTime getTaViewStartDate()
 * @method \DateTime getGradeStartDate()
 * @method \DateTime getGradeDueDate()
 * @method \DateTime getGradeReleasedDate()
 * @method int getMinGradingGroup()
 * @method \DateTime getGradeInquiryStartDate()
 * @method \DateTime getGradeInquiryDueDate()
 * @method string getSyllabusBucket()
 * @method void setSyllabusBucket($bucket)
 * @method string getTaInstructions()
 * @method void setTaInstructions($instructions)
 * @method string getAutogradingConfigPath()
 * @method bool isVcs()
 * @method void setVcs($use_vcs)
 * @method string getVcsSubdirectory()
 * @method void setVcsSubdirectory($subdirectory)
 * @method int getVcsHostType()
 * @method void setVcsHostType($host_type)
 * @method bool isTeamAssignment()
 * @method int getTeamSizeMax()
 * @method \DateTime getTeamLockDate()
 * @method bool isTaGrading()
 * @method bool isScannedExam()
 * @method void setScannedExam($scanned_exam)
 * @method bool isStudentView()
 * @method void setStudentView($can_student_view)
 * @method bool isStudentViewAfterGrades()
 * @method void setStudentViewAfterGrades($can_student_view_after_grades)
 * @method bool isStudentSubmit()
 * @method void setStudentSubmit($can_student_submit)
 * @method void setPeerGrading($use_peer_grading)
 * @method int getPeerGradeSet()
 * @method void setPeerGradeSet($grade_set)
 * @method \DateTime getSubmissionOpenDate()
 * @method \DateTime getSubmissionDueDate()
 * @method int getLateDays()
 * @method bool isLateSubmissionAllowed()
 * @method void setLateSubmissionAllowed($allow_late_submission)
 * @method float getPrecision()
 * @method Component[] getComponents()
 * @method void setAllowedMinutes($minutes)
 * @method string getDependsOn()
 * @method void setDependsOn($depends_on)
 * @method int getDependsOnPoints()
 * @method void setDependsOnPoints($depends_on_points)
 * @method bool isRegradeAllowed()
 * @method bool isGradeInquiryPerComponentAllowed()
 * @method void setGradeInquiryPerComponentAllowed($is_grade_inquiry_per_component)
 * @method bool isDiscussionBased()
 * @method void setDiscussionBased($discussion_based)
 * @method string  getDiscussionThreadId()
 * @method void setDiscussionThreadId($discussion_thread_id)
 * @method int getActiveRegradeRequestCount()
 * @method void setHasDueDate($has_due_date)
 * @method object[] getPeerGradingPairs()
 * @method string getHiddenFiles()
 * @method void setHiddenFiles($hidden_files)
 * @method void setStudentSubmit($can_student_submit)
 * @method void setLimitedAccessBlind($limited_access_blind)
 * @method int getLimitedAccessBlind()
 * @method void setPeerBlind($peer_blind)
 * @method int getPeerBlind()
 * @method void setInstructorBlind($peer_blind)
 * @method int getInstructorBlind()
 * @method bool getAllowCustomMarks()
 * @method void setAllowCustomMarks($allow_custom_marks)
 */
class Gradeable extends AbstractModel {
    /* Enum range for grader_assignment_method */
    const ROTATING_SECTION = 0;
    const REGISTRATION_SECTION = 1;
    const ALL_ACCESS = 2;

    /**
     * Enum range for blind and unblind grading:
     * 1 is unblind, 2 is single blind, 3 is double blind
    */

    const UNBLIND_GRADING = 1;
    const SINGLE_BLIND_GRADING = 2;
    const DOUBLE_BLIND_GRADING = 3;

    /* Properties for all types of gradeables */

    /** @prop @var string The course-wide unique gradeable id */
    protected $id = "";
    /** @prop @var string The gradeable's title */
    protected $title = "";
    /** @prop @var string The instructions url to give to students */
    protected $instructions_url = "";
    /** @prop @var int The type of gradeable */
    protected $type = GradeableType::ELECTRONIC_FILE;
    /** @prop @var int If the gradeable should be graded by all access (2) by registration section (1) or rotating sections (0) */
    protected $grader_assignment_method = Gradeable::REGISTRATION_SECTION;
    /** @prop @var int The minimum user group that can grade this gradeable (1=instructor) */
    protected $min_grading_group = 1;
    /** @prop @var string The syllabus classification of this gradeable */
    protected $syllabus_bucket = "homework";
    /** @prop @var Component[] An array of all of this gradeable's components */
    protected $components = [];
    /** @prop @var Component[] An array of all gradeable components loaded from the database */
    private $db_components = [];

    /** @prop @var bool If any submitters have active grade inquiries */
    protected $active_regrade_request_count = 0;

    /* (private) Lazy-loaded Properties */

    /** @prop @var bool If any manual grades have been entered for this gradeable */
    private $any_manual_grades = null;
    /** @prop @var bool If any submissions exist */
    private $any_submissions = null;
    /** @prop @var bool If any errors occurred in the build output */
    private $any_build_errors = null;
    /** @prop @var Team[] Any teams that have been formed */
    private $teams = null;
    /** @prop @var string[][] Which graders are assigned to which rotating sections (empty if $grade_by_registration is true)
     *                          Array (indexed by grader id) of arrays of rotating section numbers
     */
    private $rotating_grader_sections = null;
    private $rotating_grader_sections_modified = false;
    /** @prop @var AutogradingConfig The object that contains the autograding config data */
    private $autograding_config = null;
    /** @prop @var array Array of all split pdf uploads. Each key is a filename and then each element is an array
     * that contains filename, file path, and the file size.
     */
    private $split_pdf_files = null;

    /** @prop @var array */
    protected $peer_grading_pairs = [];

    /* Properties exclusive to numeric-text/checkpoint gradeables */

    /** @prop @var string The overall ta instructions for grading (numeric-text/checkpoint only) */
    protected $ta_instructions = "";

    /* Properties exclusive to electronic gradeables */

    /** @prop @var string The location of the autograding configuration file */
    protected $autograding_config_path = "";
    /** @prop @var bool If the gradeable is using vcs upload (true) or manual upload (false) */
    protected $vcs = false;
    /** @prop @var string The subdirectory within the VCS repository for this gradeable */
    protected $vcs_subdirectory = "";
    /** @prop @var int Where are we hosting VCS (-1 -> Not VCS gradeable, 0,1 -> Submitty, 2,3 -> public/private Github) */
    protected $vcs_host_type = -1;
    /** @prop @var bool If the gradeable is a team assignment */
    protected $team_assignment = false;
    /** @prop @var int The maximum team size (if the gradeable is a team assignment) */
    protected $team_size_max = 0;
    /** @prop @var bool If the gradeable is using any manual grading */
    protected $ta_grading = false;
    /** @prop @var bool If the gradeable is a 'scanned exam' */
    protected $scanned_exam = false;
    /** @prop @var bool If students can view submissions */
    protected $student_view = false;
    /** @prop @var bool If students can only view submissions after grades released date */
    protected $student_view_after_grades = false;
    /** @prop @var bool If students can make submissions */
    protected $student_submit = false;
    /** @prop @var int The number of peers each student will be graded by */
    protected $peer_grade_set = 0;
    /** @prop @var bool if graders will be allowed to use custom marks */
    protected $allow_custom_marks = true;
    /** @prop @var bool If submission after student's max deadline
     *      (due date + min(late days allowed, late days remaining)) is allowed
     */
    protected $late_submission_allowed = true;
    /** @prop @var float The point precision for manual grading */
    protected $precision = 0.0;
    /** @prop @var bool If this gradeable has a due date or not */
    protected $has_due_date = false;
    /** @prop @var int The amount of time given to a default student to complete assignment */
    protected $allowed_minutes = null;
    /** @prop @var array Contains all of the allowed time overrides */
    protected $allowed_minutes_overrides = [];
    /** @prop @var string The dependent gradeable that must be completed before this one */
    protected $depends_on = null;
    /** @prop @var int The amount of points a user must reach to unlock this gradeable */
    protected $depends_on_points = null;

    /* Dates for all types of gradeables */

    /** @prop @var \DateTime The so-called 'TA Beta-Testing' date.  This is when the gradeable appears for TA's */
    protected $ta_view_start_date = null;
    /** @prop @var \DateTime The date that graders may start grading */
    protected $grade_start_date = null;
    /** @prop @var \DateTime The date that graders must have grades in by */
    protected $grade_due_date = null;
    /** @prop @var \DateTime The date that grades will be released to students */
    protected $grade_released_date = null;

    /* Dates for electronic gradeables*/

    /** @prop @var \DateTime The deadline for joining teams (if the gradeable is a team assignment) */
    protected $team_lock_date = null;
    /** @prop @var \DateTime The date students can start making submissions */
    protected $submission_open_date = null;
    /** @prop @var \DateTime The date, before which all students must make a submissions (or be marked late) */
    protected $submission_due_date = null;
    /** @prop @var int The number of late days allowed */
    protected $late_days = 0;
    /** @prop @var \DateTime The Date students can start making grade inquiries */
    protected $grade_inquiry_start_date = null;
    /** @prop @var \DateTime The deadline for submitting a grade inquiry */
    protected $grade_inquiry_due_date = null;
    /** @prop @var bool are grade inquiries enabled for this assignment*/
    protected $regrade_allowed = true;
    /** @prop @var bool are grade inquiries for specific components enabled for this assignment*/
    protected $grade_inquiry_per_component_allowed = false;
    /** @prop @var bool does this assignment have a discussion component*/
    protected $discussion_based = false;
    /** @prop @var string thread id for corresponding to discussion forum thread*/
    protected $discussion_thread_id = '';
    /** @prop @var string are a list of hidden files and the lowest_access_group that can see those files */
    protected $hidden_files = "";
    /** @prop @var bool will limited access graders grade the gradeable blindly*/
    protected $limited_access_blind = 1;
    /** @prop @var bool will peer graders grade the gradeable blindly*/
    protected $peer_blind = 3;
    /** @prop @var bool will instructors have blind peer grading enabled*/
    protected $instructor_blind = 1;

    /**
     * Gradeable constructor.
     * @param Core $core
     * @param array $details
     * @throws \InvalidArgumentException if any of the details were not found or invalid
     * @throws ValidationException If any of the dates are incompatible or invalid
     */
    public function __construct(Core $core, array $details) {
        parent::__construct($core);

        $this->setIdInternal($details['id']);
        $this->setTitle($details['title']);
        $this->setInstructionsUrl($details['instructions_url']);
        $this->setTypeInternal($details['type']);
        $this->setGraderAssignmentMethod($details['grader_assignment_method']);
        $this->setMinGradingGroup($details['min_grading_group']);
        $this->setSyllabusBucket($details['syllabus_bucket']);
        $this->setTaInstructions($details['ta_instructions']);
        if (array_key_exists('peer_graders_list', $details)) {
            $this->setPeerGradersList($details['peer_graders_list']);
        }

        if (array_key_exists('peer_blind', $details)) {
            $this->setPeerBlind($details['peer_blind']);
        }

        if (array_key_exists('limited_access_blind', $details)) {
            $this->setLimitedAccessBlind($details['limited_access_blind']);
        }

        if ($this->getType() === GradeableType::ELECTRONIC_FILE) {
            $this->setAutogradingConfigPath($details['autograding_config_path']);
            $this->setVcs($details['vcs']);
            $this->setVcsSubdirectory($details['vcs_subdirectory']);
            $this->setVcsHostType($details['vcs_host_type']);
            $this->setTeamAssignmentInternal($details['team_assignment']);
            $this->setTeamSizeMax($details['team_size_max']);
            $this->setTaGradingInternal($details['ta_grading']);
            $this->setScannedExam($details['scanned_exam']);
            $this->setStudentView($details['student_view']);
            $this->setStudentViewAfterGrades($details['student_view_after_grades']);
            $this->setStudentSubmit($details['student_submit']);
            $this->setHasDueDate($details['has_due_date']);
            $this->setLateSubmissionAllowed($details['late_submission_allowed']);
            $this->setPrecision($details['precision']);
            $this->setRegradeAllowedInternal($details['regrade_allowed']);
            $this->setGradeInquiryPerComponentAllowed($details['grade_inquiry_per_component_allowed']);
            $this->setDiscussionBased((bool) $details['discussion_based']);
            $this->setDiscussionThreadId($details['discussion_thread_ids']);
            $this->setAllowCustomMarks($details['allow_custom_marks']);
            $this->setAllowedMinutes($details['allowed_minutes']);
            $this->setDependsOn($details['depends_on']);
            $this->setDependsOnPoints($details['depends_on_points']);
            if (array_key_exists('hidden_files', $details)) {
                $this->setHiddenFiles($details['hidden_files']);
            }
        }

        $this->setActiveRegradeRequestCount($details['active_regrade_request_count'] ?? 0);

        // Set dates last
        $this->setDates($details);
        $this->modified = false;
    }

    /**
     * Exports all of the components so they can be saved to a json
     * @return array
     */
    public function exportComponents() {
        $component_arrays = [];
        foreach ($this->components as $component) {
            /** @var Component $component */
            $component_arrays[] = $component->export();
        }
        return $component_arrays;
    }

    /**
     * All \DateTime properties for this class
     */
    const date_properties = [
        'ta_view_start_date',
        'submission_open_date',
        'submission_due_date',
        'grade_start_date',
        'grade_due_date',
        'grade_released_date',
        'team_lock_date',
        'grade_inquiry_start_date',
        'grade_inquiry_due_date'
    ];

    /**
     * Display names for the different date properties (for forming error messages)
     */
    const date_display_names = [
        'ta_view_start_date' => 'Beta Testing',
        'submission_open_date' => 'Submission Open',
        'submission_due_date' => 'Submission Due',
        'grade_start_date' => 'Grading Open',
        'grade_due_date' => 'Grading Due',
        'grade_released_date' => 'Grades Released',
        'team_lock_date' => 'Teams Locked',
        'late_days' => 'Late Days',
        'grade_inquiry_start_date' => 'Grade Inquiries Open',
        'grade_inquiry_due_date' => 'Grade Inquiries Due'
    ];

    /**
     * All \DateTime properties that should be validated
     */
    const date_validated_properties = [
        'ta_view_start_date',
        'submission_open_date',
        'submission_due_date',
        'grade_start_date',
        'grade_due_date',
        'grade_released_date',
        'grade_inquiry_start_date',
        'grade_inquiry_due_date'
    ];

    /**
     * All \DateTime properties for NUMERIC_TEXT and CHECKPOINT gradeables
     * Note: this is in validation order
     */
    const date_properties_simple = [
        'ta_view_start_date',
        'grade_start_date',
        'grade_due_date',
        'grade_released_date'
    ];

    /**
     * All \DateTime properties for ELECTRONIC gradeables with ta grading
     * Note: this is in validation order
     */
    const date_properties_elec_ta = [
        'ta_view_start_date',
        'submission_open_date',
        'grade_start_date',
        'grade_due_date',
        'grade_released_date'
    ];

    /**
     * All \DateTime properties for ELECTRONIC gradeables with no ta grading
     * Note: this is in validation order
     */
    const date_properties_elec_no_ta = [
        'ta_view_start_date',
        'submission_open_date',
        'grade_released_date'
    ];

    /**
     * All \DateTime properties for ELECTRONIC exam gradeables
     * Note: this is in validation order
     */
    const date_properties_elec_exam = [
        'ta_view_start_date',
        'grade_start_date',
        'grade_due_date',
        'grade_released_date'
    ];

    /**
     * All \DateTime properties relevant for all types
     * Note: This is also the set for no student upload AND no ta grading
     * Note: this is in validation order
     */
    const date_properties_bare = [
        'ta_view_start_date',
        'grade_released_date'
    ];

    public function toArray() {
        // Use the default behavior for the most part, but convert the dates
        $return = parent::toArray();

        foreach (self::date_properties as $date) {
            $return[$date] = $this->$date !== null ? DateUtils::dateTimeToString($this->$date) : null;
        }

        // Serialize important Lazy-loaded values
        $return['rotating_grader_sections'] = parent::parseObject($this->getRotatingGraderSections());
        $return['autograding_config'] = parent::parseObject($this->getAutogradingConfig());

        return $return;
    }

    /**
     * Gets the component object with the provided component id
     * @param int $component_id
     * @return Component|null The Component with the provided id, or null if not found
     */
    public function getComponent($component_id) {
        foreach ($this->getComponents() as $component) {
            if ($component->getId() === $component_id) {
                return $component;
            }
        }
        throw new \InvalidArgumentException('Component id did not exist in gradeable');
    }

    /**
     * Gets an array of components set to be deleted
     * @return Component[]
     */
    public function getDeletedComponents() {
        return array_udiff($this->db_components, $this->components, Utils::getCompareByReference());
    }

    /**
     * Loads the autograding config file at $this->autograding_config into an array, or null if error/not found
     * @return AutogradingConfig|null
     */
    private function loadAutogradingConfig() {
        $course_path = $this->core->getConfig()->getCoursePath();

        try {
            $details = FileUtils::readJsonFile(FileUtils::joinPaths(
                $course_path,
                'config',
                'build',
                "build_{$this->id}.json"
            ));

            // If the file could not be found, the result will be false, so don't
            //  create the config if the file can't be found
            if ($details !== false) {
                return new AutogradingConfig($this->core, $details);
            }
            return null;
        }
        catch (\Exception $e) {
            // Don't throw an error, just don't make any data
            return null;
        }
    }

    /**
     * Parses array of the date properties to set to force them into a valid format
     * @param array $dates An array containing a combination of \DateTime and string objects indexed by date property name
     * @return \DateTime[] A full array of \DateTime objects (one element for each gradeable date property or null if not provided / bad format)
     *                      with a 'late_days' integer element
     */
    private function parseDates(array $dates) {
        $parsedDates = [];
        foreach (self::date_properties as $date) {
            if (isset($dates[$date]) && $dates[$date] !== null) {
                try {
                    $user = $this->core->getUser();
                    $time_zone = is_null($user) ? $this->core->getConfig()->getTimezone() : $user->getUsableTimeZone();
                    $parsedDates[$date] = DateUtils::parseDateTime($dates[$date], $time_zone);
                }
                catch (\Exception $e) {
                    $parsedDates[$date] = null;
                }
            }
            else {
                $parsedDates[$date] = null;
            }
        }

        // Assume that if no late days provided that there should be zero of them;
        $parsedDates['late_days'] = intval($dates['late_days'] ?? 0);
        return $parsedDates;
    }
    public function setRandomPeerGradersList(&$input) {
        $bad_rows = [];
        foreach ($input as $grader => $grading_list) {
            if ($this->core->getQueries()->getUserById($grading_list[0]) == null) {
                array_push($bad_rows, ($grading_list[0]));
            }
        }
        if (count($input) == 0) {
            $this->core->addErrorMessage("Changes Failed, Not Enough Submissions");
            return;
        }
        if (!empty($bad_rows)) {
            $msg = "The given user id is not valid: ";
            array_walk(
                $bad_rows,
                function ($val) use (&$msg) {
                    $msg .= " {$val}";
                }
            );
            $this->core->addErrorMessage($msg);
        }
        else {
            $this->core->getQueries()->clearPeerGradingAssignment($this->getId());
            $g_id = $this->getId();
            $query_string = "";
            if (count($input[0][1]) < 1) {
                return;
            }
            foreach ($input as $grading_list) {
                $grader = $grading_list[0];
                for ($j = 0; $j < count($grading_list[1]); $j++) {
                    $peer = $grading_list[1][$j];
                    $query_string .= " ('$grader', '$peer','$g_id'),";
                }
            }
            $query_string = chop($query_string, ',');
            $query_string .= ";";
                $this->core->getQueries()->insertBulkPeerGradingAssignment($query_string);
                $this->modified = true;
                $this->peer_grading_pairs = $this->core->getQueries()->getPeerGradingAssignment($this->getId());
        }
    }
    public function setPeerGradersList($input) {
        $bad_rows = [];
        foreach ($input as $row_num => $vals) {
            if ($this->core->getQueries()->getUserById($vals["student"]) === null) {
                array_push($bad_rows, ($vals["student"]));
            }
            if ($this->core->getQueries()->getUserById($vals["grader"]) === null) {
                array_push($bad_rows, ($vals["grader"]));
            }
        }
        if (!empty($bad_rows)) {
            $msg = "The given user id is not valid: ";
            array_walk($bad_rows, function ($val) use (&$msg) {
                $msg .= " {$val}";
            });
            $this->core->addErrorMessage($msg);
        }
        else {
            $query_string = "";
            $this->core->getQueries()->clearPeerGradingAssignment($this->getId());
            $g_id = $this->getId();
            foreach ($input as $row_num => $vals) {
                $grader = $vals["grader"];
                $peer = $vals["student"];
                $query_string .= " ('$grader', '$peer','$g_id'),";
            }
            $query_string = chop($query_string, ',');
            $query_string .= ";";
            $this->core->getQueries()->insertBulkPeerGradingAssignment($query_string);
            $this->modified = true;
            $this->peer_grading_pairs = $this->core->getQueries()->getPeerGradingAssignment($this->getId());
        }
    }

    public function setPeerFeedback($grader_id, $student_id, $feedback) {
        $bad_input = [];
        if ($this->core->getQueries()->getUserById($grader_id) === null) {
            array_push($bad_input, ($grader_id));
        }
        if ($this->core->getQueries()->getUserById($student_id) === null) {
            array_push($bad_input, ($student_id));
        }
        if (!empty($bad_input)) {
            $msg = "The given user id is not valid: ";
            array_walk($bad_input, function ($val) use (&$msg) {
                $msg .= " {$val}";
            });
            $this->core->addErrorMessage($msg);
        }
        else {
            $this->core->getQueries()->insertPeerGradingFeedback($grader_id, $student_id, $this->getId(), $feedback);
        }
    }

    public function getPeerFeedback($grader_id, $anon_id) {
        $user_id = $this->core->getQueries()->getSubmitterIdFromAnonId($anon_id);
        $feedback = $this->core->getQueries()->getPeerFeedbackInstance($this->getId(), $grader_id, $user_id);
        if ($feedback == 'thanks') {
            return 'Thank you!';
        }
        elseif ($feedback == 'helpful') {
            return 'This feedback was helpful to me!';
        }
        elseif ($feedback == 'detailed') {
            return 'This feedback was detailed, specific, and/or technical';
        }
        elseif ($feedback == 'inappropriate') {
            return 'This feedback was inaccurate and/or inappropriate';
        }
        return 'No response';
    }

    /**
     * Validates that a given set dates are strictly increasing
     * @param string[] $date_properties
     * @param \DateTime[] $date_values array of \DateTime objects indexed by $date_properties
     * @return string[] Array of error messages indexed by $date_properties
     */
    private static function validateDateSet(array $date_properties, array $date_values) {
        // A message to set if the date is null, which happens when: the provided date is null,
        //  or the parsing failed.  In either case, this is an appropriate message
        $invalid_format_message = 'Invalid date-time value!';

        // If the dates are null, then their format is invalid
        $errors = [];
        foreach ($date_properties as $property) {
            $date = $date_values[$property] = $date_values[$property] ?? null;
            if ($date === null) {
                $errors[$property] = $invalid_format_message;
            }
        }

        // Now, check if they are in increasing order
        $prev_property = null;
        foreach ($date_properties as $property) {
            if ($prev_property !== null) {
                if ($date_values[$prev_property] !== null && $date_values[$property] !== null) {
                    if ($date_values[$prev_property] > $date_values[$property]) {
                        $errors[$prev_property] = self::date_display_names[$prev_property] . ' Date must come before '
                            . self::date_display_names[$property] . ' Date';
                    }
                }
            }
            $prev_property = $property;
        }

        return $errors;
    }

    /**
     * Gets the dates that require validation for the gradeable's current configuration.
     * @return string[] array of date property names that need validation
     */
    private function getDateValidationSet() {
        if ($this->type === GradeableType::ELECTRONIC_FILE) {
            if (!$this->isStudentSubmit()) {
                if ($this->isTaGrading()) {
                    $result = self::date_properties_elec_exam;
                }
                else {
                    $result = self::date_properties_bare;
                }
            }
            elseif ($this->isTaGrading()) {
                $result = self::date_properties_elec_ta;
            }
            else {
                $result = self::date_properties_elec_no_ta;
            }

            // Only add in submission due date if student submission is enabled
            if ($this->isStudentSubmit() && $this->hasDueDate()) {
                // Make sure we insert the due date into the correct location (after the open date)
                array_splice($result, array_search('submission_open_date', $result) + 1, 0, 'submission_due_date');
            }

            // Only add in grade inquiry dates if its allowed & enabled
            if ($this->isTaGrading() && $this->core->getConfig()->isRegradeEnabled() && $this->isRegradeAllowed()) {
                $result[] = 'grade_inquiry_start_date';
                $result[] = 'grade_inquiry_due_date';
            }
        }
        else {
            $result = self::date_properties_simple;
        }
        return $result;
    }

    /**
     * Asserts that the provided set of dates are valid for this gradeable's configuration
     * @param \DateTime[] $dates
     * @throws ValidationException With all messages for each invalid property
     */
    private function assertDates(array $dates) {
        // Get the date set we validate against
        $date_set = $this->getDateValidationSet();

        // Get the validation errors
        $errors = self::validateDateSet($date_set, $dates);

        // Put any special exceptions to the normal validation rules here...

        if (count($errors) > 0) {
            throw new ValidationException('Date validation failed', $errors);
        }
    }

    /**
     * Takes a complete set of dates relevant to this gradeable and, depending on the gradeable's settings,
     *  coerces all dates to satisfy the database date constraints.  The behavior of this function is undefined
     *  if called before `assertDates`
     * @param \DateTime[] $dates Array of dates, indexed by property name
     * @return \DateTime[] Array of dates, indexed by property name
     */
    private function coerceDates(array $dates) {
        // Takes an array of date properties (in order) and date values (indexed by property)
        //  and returns the modified date values to comply with the provided order, using
        //  a compare function, which returns true when first parameter should be coerced
        //  into the second parameter.
        $coerce_dates = function (array $date_properties, array $black_list, array $date_values, $compare) {
            // coerce them to be in increasing order (and fill in nulls)
            foreach ($date_properties as $i => $property) {
                // Don't coerce the first date
                if ($i === 0) {
                    continue;
                }

                // Don't coerce a date on the black list
                if (in_array($property, $black_list)) {
                    continue;
                }

                // Get a value for the date to compare against
                $prev_date = $date_values[$date_properties[$i - 1]];

                // This may be null / not set
                $date = $date_values[$property] ?? null;

                // Coerce the date if it is out of bounds
                if ($date === null || $compare($date, $prev_date)) {
                    $date_values[$property] = $prev_date;
                }
            }
            return $date_values;
        };

        // Blacklist the dates checked by validation
        $black_list = $this->getDateValidationSet();

        // First coerce in the forward direction, then in the reverse direction
        return $coerce_dates(
            array_reverse(self::date_validated_properties),
            $black_list,
            $coerce_dates(
                self::date_validated_properties,
                $black_list,
                $dates,
                function (\DateTime $val, \DateTime $cmp) {
                    return $val < $cmp;
                }
            ),
            function (\DateTime $val, \DateTime $cmp) {
                return $val > $cmp;
            }
        );
    }

    /**
     * Sets the all of the dates of this gradeable
     * @param array $dates An array of dates/date strings indexed by property name
     * @throws ValidationException With all messages for each invalid property
     */
    public function setDates(array $dates) {
        // Wrangle the input so we have a fully populated array of \DateTime's (or nulls)
        $dates = $this->parseDates($dates);

        // Asserts that this date information is valid
        $this->assertDates($dates);

        // Coerce any dates that have database constraints, but
        //  aren't relevant to the current gradeable configuration
        $dates = $this->coerceDates($dates);

        // Manually set each property (instead of iterating over self::date_properties) so the user
        //  can't set dates irrelevant to the gradeable settings

        $this->ta_view_start_date = $dates['ta_view_start_date'];
        $this->grade_start_date = $dates['grade_start_date'];
        $this->grade_due_date = $dates['grade_due_date'];
        $this->grade_released_date = $dates['grade_released_date'];

        if ($this->type === GradeableType::ELECTRONIC_FILE) {
            // Set team lock date even if not team assignment because it is NOT NULL in the db
            $this->team_lock_date = $dates['team_lock_date'];
            $this->submission_open_date = $dates['submission_open_date'];
            $this->submission_due_date = $dates['submission_due_date'];
            $this->late_days = $dates['late_days'];
            $this->grade_inquiry_start_date = $dates['grade_inquiry_start_date'];
            $this->grade_inquiry_due_date = $dates['grade_inquiry_due_date'];
        }
        $this->modified = true;
    }

    /**
     * Gets all of the gradeable's date values indexed by property name (including late_days)
     * @return mixed[]
     */
    public function getDates() {
        $dates = [];
        foreach (self::date_properties as $property) {
            $dates[$property] = $this->$property;
        }
        $dates['late_days'] = $this->late_days;
        return $dates;
    }

    public function getStringThreadIds() {
        return $this->isDiscussionBased() && is_array(json_decode($this->getDiscussionThreadId()))
            ? implode(',', json_decode($this->getDiscussionThreadId())) : '';
    }

    /**
     * Gets all of the gradeable's date values as strings indexed by property name (including late_days)
     * @param bool $add_utc_offset True to add the UTC offset to the output strings
     * @return string[]
     */
    public function getDateStrings(bool $add_utc_offset = true) {
        $date_strings = [];
        $now = $this->core->getDateTimeNow();
        foreach (self::date_properties as $property) {
            $date_strings[$property] = DateUtils::dateTimeToString($this->$property ?? $now, $add_utc_offset);
        }
        $date_strings['late_days'] = strval($this->late_days);
        return $date_strings;
    }

    /**
     * Gets if this gradeable has a due date or not for electronic gradeables
     * @return bool
     */
    public function hasDueDate() {
        return $this->has_due_date;
    }

    /**
     * Gets the rotating section grader assignment
     * @return array An array (indexed by user id) of arrays of section ids
     */
    public function getRotatingGraderSections() {
        if ($this->rotating_grader_sections === null) {
            $this->setRotatingGraderSections($this->core->getQueries()->getRotatingSectionsByGrader($this->id));
            $this->rotating_grader_sections_modified = false;
        }
        return $this->rotating_grader_sections;
    }

    /**
     * Gets the autograding configuration object
     * @return AutogradingConfig|null returns null if loading from the disk fails
     */
    public function getAutogradingConfig() {
        if ($this->autograding_config === null) {
            $this->autograding_config = $this->loadAutogradingConfig();
        }
        return $this->autograding_config;
    }

    /**
     * Gets whether this gradeable has an autograding config
     * @return bool True if it has an autograding config
     */
    public function hasAutogradingConfig() {
        return $this->getAutogradingConfig() !== null;
    }

    /** @internal */
    public function setTaViewStartDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setGradeStartDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setGradeDueDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setGradeReleasedDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setTeamLockDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setSubmissionOpenDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    /** @internal */
    public function setSubmissionDueDate($date) {
        throw new NotImplementedException('Individual date setters are disabled, use "setDates" instead');
    }

    public function setAutogradingConfig(AutogradingConfig $autograding_config): void {
        $this->autograding_config = $autograding_config;
    }

    /**
     * Sets the number of active grade inquiries
     * @param int $count
     * @internal
     */
    public function setActiveRegradeRequestCount(int $count) {
        $this->active_regrade_request_count = $count;
    }

    /**
     * Sets the gradeable Id.  Must match the regular expression:  ^[a-zA-Z0-9_-]*$
     * @param string $id The gradeable id to set
     */
    private function setIdInternal($id) {
        preg_match('/^[a-zA-Z0-9_-]*$/', $id, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches) === 0) {
            throw new \InvalidArgumentException('Gradeable id must be alpha-numeric/hyphen/underscore only');
        }
        $this->id = $id;
    }

    /** @internal */
    public function setId($id) {
        throw new \BadFunctionCallException('Cannot change Id of gradeable');
    }

    /**
     * Sets the gradeable Title
     * @param string $title Must not be blank.
     */
    public function setTitle($title) {
        if ($title === '') {
            throw new \InvalidArgumentException('Gradeable title must not be blank');
        }
        $this->title = strval($title);
        $this->modified = true;
    }

    /**
     * Sets the gradeable type
     * @param int $type Must be a valid GradeableType
     */
    private function setTypeInternal($type) {
        // Call this to make an exception if the type is invalid
        GradeableType::typeToString($type);
        $this->type = $type;
    }

    /** @internal */
    public function setType($type) {
        throw new \BadFunctionCallException('Cannot change gradeable type');
    }

    /**
     * gets bool representing if gradeable is set to grade by registration
     * @return boolean
     */
    public function isGradeByRegistration() {
        if ($this->getGraderAssignmentMethod() == Gradeable::REGISTRATION_SECTION) {
            return true;
        }
        return false;
    }

    /**
     * Sets the minimum user level that can grade an assignment.
     * @param int $group Must be at least 1 and no more than 4
     */
    public function setMinGradingGroup(int $group) {
        // Disallow the 0 group (this may catch some potential bugs with instructors not being able to edit gradeables)
        if ($group > 0 && $group <= 4) {
            $this->min_grading_group = $group;
        }
        else {
            throw new \InvalidArgumentException('Grading group must be an integer larger than 0');
        }
        $this->modified = true;
    }

    /**
     * Sets the maximum team size
     * @param int $max_team_size Must be at least 0
     */
    public function setTeamSizeMax(int $max_team_size) {
        if ($max_team_size >= 0) {
            $this->team_size_max = intval($max_team_size);
        }
        else {
            throw new \InvalidArgumentException('Max team size must be a non-negative integer!');
        }
        $this->modified = true;
    }

    /**
     * Sets the precision for grading
     * @param float $precision
     */
    public function setPrecision(float $precision) {
        $this->precision = $precision;
        $this->modified = true;
    }

    /**
     * Sets the peer grading set
     * @param int $peer_grading_set Must be at least 0
     */
    public function setPeerGradingSet(int $peer_grading_set) {
        if ($peer_grading_set >= 0) {
            $this->peer_grade_set = intval($peer_grading_set);
        }
        else {
            throw new \InvalidArgumentException('Peer grade set must be a non-negative integer!');
        }
        $this->modified = true;
    }

    /**
     * Sets the array of components
     * @param Component[] $components Must be an array of only Component
     */
    public function setComponents(array $components) {
        $components = array_values($components);
        foreach ($components as $component) {
            if (!($component instanceof Component)) {
                throw new \InvalidArgumentException('Object in components array wasn\'t a component');
            }
        }

        // Get the implied deleted components from this operation and ensure we aren't deleting any
        //  components that have grades already
        $deleted_components = array_udiff($this->components, $components, Utils::getCompareByReference());
        if (
            in_array(
                true,
                array_map(
                    function (Component $component) {
                        return $component->anyGrades();
                    },
                    $deleted_components
                )
            )
        ) {
            throw new \InvalidArgumentException('Call to setComponents implied deletion of component with grades');
        }

        $this->components = $components;

        // sort by order
        usort($this->components, function (Component $a, Component $b) {
            return $a->getOrder() - $b->getOrder();
        });
    }

    /**
     * Adds a new component to this gradeable with the provided properties
     * @param string $title
     * @param string $ta_comment
     * @param string $student_comment
     * @param float $lower_clamp
     * @param float $default
     * @param float $max_value
     * @param float $upper_clamp
     * @param bool $text
     * @param bool $peer
     * @param int $pdf_page set to Component::PDF_PAGE_NONE if not a pdf assignment
     * @return Component the created component
     */
    public function addComponent(
        string $title,
        string $ta_comment,
        string $student_comment,
        float $lower_clamp,
        float $default,
        float $max_value,
        float $upper_clamp,
        bool $text,
        bool $peer,
        int $pdf_page
    ) {
        $component = new Component($this->core, $this, [
            'title' => $title,
            'ta_comment' => $ta_comment,
            'student_comment' => $student_comment,
            'lower_clamp' => $lower_clamp,
            'default' => $default,
            'max_value' => $max_value,
            'upper_clamp' => $upper_clamp,
            'text' => $text,
            'peer' => $peer,
            'page' => $pdf_page,
            'id' => 0,
            'order' => count($this->components)
        ]);
        $this->components[] = $component;

        return $component;
    }

    /**
     * Adds a new component to this gradeable with the provided properties in array form
     * @param array $details
     * @return Component the created component
     */
    public function importComponent(array $details) {
        $details['id'] = 0;
        $details['order'] = count($this->components);
        $component = Component::import($this->core, $this, $details);
        $this->components[] = $component;
        return $component;
    }

    /**
     * Base method for deleting components.  This isn't exposed as public so
     *  its make very clear that a delete component operation is being forceful.
     * @param Component $component
     * @param bool $force true to delete the component if it has grades
     * @throws \InvalidArgumentException If this gradeable doesn't own the provided component or
     *          $force is false and the component has grades
     */
    private function deleteComponentInner(Component $component, bool $force = false) {
        // Don't delete if the component has grades (and we aren't forcing)
        if ($component->anyGrades() && !$force) {
            throw new \InvalidArgumentException('Attempt to delete a component with grades!');
        }

        // Calculate our components array without the provided component
        $new_components = array_udiff($this->components, [$component], Utils::getCompareByReference());

        // If it wasn't removed from our components, it was either already deleted, or never belonged to us
        if (count($new_components) === count($this->components)) {
            throw new \InvalidArgumentException('Attempt to delete component that did not belong to this gradeable');
        }

        // Finally, set our array to the new one
        $this->components = $new_components;
    }

    /**
     * Deletes a component from this gradeable
     * @param Component $component
     * @throws \InvalidArgumentException If this gradeable doesn't own the provided component or if the component has grades
     */
    public function deleteComponent(Component $component) {
        $this->deleteComponentInner($component, false);
    }

    /**
     * Deletes a component from this gradeable without checking if grades exist for it yet.
     * DANGER: THIS CAN BE A VERY DESTRUCTIVE ACTION -- USE ONLY WHEN EXPLICITLY REQUESTED
     * @param Component $component
     * @throws \InvalidArgumentException If this gradeable doesn't own the provided component
     */
    public function forceDeleteComponent(Component $component) {
        $this->deleteComponentInner($component, true);
    }

    /**
     * Sets the array of the components, only called from the database
     * @param Component[] $components
     * @internal
     */
    public function setComponentsFromDatabase(array $components) {
        $this->setComponents($components);
        $this->db_components = $this->components;
    }

    /**
     * Sets the path to the autograding config
     * @param string $path Must not be blank
     */
    public function setAutogradingConfigPath($path) {
        if ($path === '') {
            throw new \InvalidArgumentException('Autograding configuration file path cannot be blank');
        }
        $this->autograding_config_path = strval($path);
        $this->modified = true;
    }

    /**
     * Sets whether the gradeable is a team gradeable
     * @param bool $use_teams
     */
    private function setTeamAssignmentInternal($use_teams) {
        $this->team_assignment = $use_teams === true;
    }

    /** @internal */
    public function setTeamAssignment($use_teams) {
        throw new \BadFunctionCallException('Cannot change teamness of gradeable');
    }

    /**
     * Sets the rotating grader sections for this gradeable
     * @param array $rotating_grader_sections An array (indexed by grader id) of arrays of section numbers
     */
    public function setRotatingGraderSections($rotating_grader_sections) {
        // Number of total rotating sections
        $num_sections = $this->core->getQueries()->getNumberRotatingSections();

        $parsed_graders_sections = [];
        foreach ($rotating_grader_sections as $user => $grader_sections) {
            if ($grader_sections !== null) {
                if (!is_array($grader_sections)) {
                    throw new \InvalidArgumentException('Rotating grader section for grader was not array');
                }
                // Parse each section array into strings
                $parsed_sections = [];
                foreach ($grader_sections as $section) {
                    if ((is_int($section) || ctype_digit($section)) && intval($section) > 0 && intval($section) <= $num_sections) {
                        $parsed_sections[] = intval($section);
                    }
                    else {
                        throw new \InvalidArgumentException('Grading section must be a positive integer no more than the number of rotating sections!');
                    }
                }
                $parsed_graders_sections[$user] = $parsed_sections;
            }
        }
        $this->rotating_grader_sections = $parsed_graders_sections;
        $this->rotating_grader_sections_modified = true;
    }

    /**
     * Sets whether regrades are allowed for this gradeable
     * @param bool $regrade_allowed
     * @throws ValidationException If date validation fails in this new grade inquiry configuration
     */
    public function setRegradeAllowed(bool $regrade_allowed) {
        $old = $this->regrade_allowed;
        $this->regrade_allowed = $regrade_allowed;

        try {
            // Asserts that this date information is valid after changing this property
            $this->setDates($this->getDates());
        }
        catch (ValidationException $e) {
            // Reset to the old value if validation fails
            $this->regrade_allowed = $old;

            // This line brings me great pain
            throw $e;
        }
        // make sure grade_inquiry_per_component_allowed is false when regrade allowed is false
        if (!$regrade_allowed) {
            $this->grade_inquiry_per_component_allowed = false;
        }
    }

    /**
     * @param bool $regrade_allowed
     * @internal
     */
    private function setRegradeAllowedInternal(bool $regrade_allowed) {
        $this->regrade_allowed = $regrade_allowed;
    }

    /**
     * Sets whether this gradeable will use ta grading
     * @param bool $ta_grading
     * @throws ValidationException If date validation fails in this new TA grading configuration
     */
    public function setTaGrading(bool $ta_grading) {
        $old = $this->ta_grading;
        $this->ta_grading = $ta_grading;

        try {
            // Asserts that this date information is valid after changing this property
            $this->setDates($this->getDates());
        }
        catch (ValidationException $e) {
            // Reset to the old value if validation fails
            $this->ta_grading = $old;

            // This line brings me great pain
            throw $e;
        }
    }

    /**
     * @param bool $ta_grading
     * @internal
     */
    private function setTaGradingInternal(bool $ta_grading) {
        $this->ta_grading = $ta_grading;
    }

    /**
     * Gets all of the teams formed for this gradeable
     * @return Team[]
     */
    public function getTeams() {
        if ($this->teams === null) {
            $this->teams = $this->core->getQueries()->getTeamsByGradeableId($this->getId());
        }
        return $this->teams;
    }

    /**
     * Gets if this gradeable has any active grade inquiries
     * @return bool
     */
    public function anyActiveRegradeRequests() {
        return $this->active_regrade_request_count > 0 && $this->core->getUser()->getGroup() < User::GROUP_STUDENT;
    }

    /**
     * Gets if this gradeable has any manual grades (any GradedGradeables exist)
     * @return bool True if any manual grades exist
     */
    public function anyManualGrades() {
        if ($this->any_manual_grades === null) {
            $this->any_manual_grades = $this->core->getQueries()->getGradeableHasGrades($this->getId());
        }
        return $this->any_manual_grades;
    }

    /**
     * Gets if this gradeable has any submissions yet
     * @return bool
     */
    public function anySubmissions() {
        if ($this->any_submissions === null) {
            // Until we find a submission, assume there are none
            $this->any_submissions = false;
            if ($this->type === GradeableType::ELECTRONIC_FILE) {
                $submission_path = FileUtils::joinPaths(
                    $this->core->getConfig()->getSubmittyPath(),
                    'courses',
                    $this->core->getConfig()->getSemester(),
                    $this->core->getConfig()->getCourse(),
                    'submissions',
                    $this->getId()
                );
                if (is_dir($submission_path)) {
                    $this->any_submissions = true;
                }
            }
        }
        return $this->any_submissions;
    }

    /**
     * Gets if this gradeable had any build errors during the last build attempt
     * @return bool
     */
    public function anyBuildErrors() {
        if ($this->any_build_errors === null) {
            $build_file = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), 'build', $this->getId(), "log_cmake_output.txt");

            // Default to true so if the file isn't found it counts as a 'build error'
            $this->any_build_errors = true;
            if (file_exists($build_file)) {
                $this->any_build_errors = strpos(file_get_contents($build_file), "error") !== false;
            }
        }
        return $this->any_build_errors;
    }

    /**
     * Gets if this gradeable has any teams formed yet
     * @return bool
     */
    public function anyTeams() {
        return !empty($this->getTeams());
    }

    /**
     * Used to decide whether a gradeable can be deleted or not.
     * This means: No submissions, No manual grades entered, No teams formed, and No VCS repos created
     * @return bool True if the gradeable can be deleted
     */
    public function canDelete() {
        return !$this->anySubmissions() && !$this->anyManualGrades() && !$this->anyTeams() && !($this->isVcs() && !$this->isTeamAssignment());
    }

    /**
     * Gets whether the rotating grader sections were modified
     * @return bool
     */
    public function isRotatingGraderSectionsModified() {
        return $this->rotating_grader_sections_modified;
    }

    /**
     * Gets if this gradeable is pdf-upload
     * @return bool
     */
    public function isPdfUpload() {
        foreach ($this->components as $component) {
            if ($component->getPage() !== Component::PDF_PAGE_NONE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets if the students assign pages to components
     * @return bool
     */
    public function isStudentPdfUpload() {
        foreach ($this->components as $component) {
            if ($component->getPage() === Component::PDF_PAGE_STUDENT) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the number of numeric components if type is GradeableType::NUMERIC_TEXT
     * @return int
     */
    public function getNumNumeric() {
        if ($this->type !== GradeableType::NUMERIC_TEXT) {
            return 0;
        }
        $count = 0;
        foreach ($this->components as $component) {
            if (!$component->isText()) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Gets the number of text components if type is GradeableType::NUMERIC_TEXT
     * @return int
     */
    public function getNumText() {
        if ($this->type !== GradeableType::NUMERIC_TEXT) {
            return 0;
        }
        $count = 0;
        foreach ($this->components as $component) {
            if ($component->isText()) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * Gets the components that are not for peer grading
     * @return Component[]
     */
    public function getNonPeerComponents() {
        return array_filter($this->components, function (Component $component) {
            return !$component->isPeerComponent();
        });
    }

    /**
     * Gets the components that are for peer grading
     * @return Component[]
     */
    public function getPeerComponents() {
        return array_filter($this->components, function (Component $component) {
            return $component->isPeerComponent();
        });
    }

    /**
     * Gets the percent of grading complete for the provided user for this gradeable
     * @param User $grader
     * @return float The percentage (0 to 1) of grading completed or NAN if none required
     */
    public function getGradingProgress(User $grader) {
        //This code is taken from the ElectronicGraderController, it used to calculate the TA percentage.
        $total_users = [];
        $graded_components = [];
        if ($this->isGradeByRegistration()) {
            if (!$grader->accessFullGrading()) {
                $sections = $grader->getGradingRegistrationSections();
            }
            else {
                $sections = $this->core->getQueries()->getRegistrationSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_registration_id'];
                }
            }
            $section_key = 'registration_section';
        }
        else {
            if (!$grader->accessFullGrading()) {
                $sections = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($this->getId(), $grader->getId());
            }
            else {
                $sections = $this->core->getQueries()->getRotatingSections();
                foreach ($sections as $i => $section) {
                    $sections[$i] = $section['sections_rotating_id'];
                }
            }
            $section_key = 'rotating_section';
        }
        $num_submitted = [];
        if (count($sections) > 0) {
            if ($this->isTeamAssignment()) {
                $total_users = $this->core->getQueries()->getTotalTeamCountByGradingSections($this->getId(), $sections, $section_key);
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByTeamGradingSections($this->getId(), $sections, $section_key);
                $num_submitted = $this->core->getQueries()->getTotalSubmittedTeamCountByGradingSections($this->getId(), $sections, $section_key);
            }
            else {
                $total_users = $this->core->getQueries()->getTotalUserCountByGradingSections($sections, $section_key);
                $graded_components = $this->core->getQueries()->getGradedComponentsCountByGradingSections($this->getId(), $sections, $section_key, $this->isTeamAssignment());
                $num_submitted = $this->core->getQueries()->getTotalSubmittedUserCountByGradingSections($this->getId(), $sections, $section_key);
            }
        }

        $num_components = $this->core->getQueries()->getTotalComponentCount($this->getId());
        $sections = [];
        if (count($total_users) > 0) {
            foreach ($num_submitted as $key => $value) {
                $sections[$key] = [
                    'total_components' => $value * $num_components,
                    'graded_components' => 0,
                ];
                if (isset($graded_components[$key])) {
                    // Clamp to total components if unsubmitted assigment is graded for whatever reason
                    $sections[$key]['graded_components'] = min(intval($graded_components[$key]), $sections[$key]['total_components']);
                }
            }
        }
        $components_graded = 0;
        $components_total = 0;
        foreach ($sections as $key => $section) {
            if ($key === "NULL") {
                continue;
            }
            $components_graded += $section['graded_components'];
            $components_total += $section['total_components'];
        }
        if ($components_total === 0) {
            return NAN;
        }
        return $components_graded / $components_total;
    }

    /**
     * Gets the info about split pdf upload files
     * @return array An array (indexed by file name) of arrays each containing file info.
     *      See FileUtils::getAllFiles for more details.
     */
    public function getSplitPdfFiles() {
        if ($this->split_pdf_files === null) {
            $upload_path = FileUtils::joinPaths(
                $this->core->getConfig()->getCoursePath(),
                'uploads',
                'split_pdf',
                $this->id
            );
            $this->split_pdf_files = FileUtils::getAllFiles($upload_path);
        }
        return $this->split_pdf_files;
    }

    /**
     * Gets if the grades released date has passed yet
     * @return bool
     */
    public function isTaGradeReleased() {
        return $this->grade_released_date < $this->core->getDateTimeNow();
    }

    /**
     * Gets if tas can view the gradeable now
     * @return bool
     */
    public function isTaViewOpen() {
        return $this->ta_view_start_date < $this->core->getDateTimeNow();
    }

    /**
     * Gets if the submission open date has passed yet
     * @return bool
     */
    public function isSubmissionOpen() {
        return $this->submission_open_date < $this->core->getDateTimeNow();
    }

    /**
     * Gets if the submission due date has passed yet
     * @return bool
     */
    public function isSubmissionClosed() {
        return $this->submission_due_date < $this->core->getDateTimeNow();
    }

    /**
     * Gets if students can make submissions at this time
     * @return bool
     */
    public function canStudentSubmit() {
        return $this->isStudentSubmit() && $this->isSubmissionOpen() &&
            (!$this->isSubmissionClosed() || $this->isLateSubmissionAllowed());
    }

    /**
     * Gets the total possible non-extra-credit manual grading (ta + peer) points
     * @return float
     */
    public function getManualGradingPoints() {
        return $this->getTaPoints() + $this->getPeerPoints();
    }


    /**
     * Gets the total possible non-extra-credit ta/instructor (non-peer) points
     * @return float
     */
    public function getTaPoints() {
        $total = 0.0;
        foreach ($this->getComponents() as $component) {
            if (!$component->isPeerComponent()) {
                $total += $component->getMaxValue();
            }
        }
        return $total;
    }

    /**
     * Gets the total possible non-extra-credit peer grading points
     * @return float
     */
    public function getPeerPoints() {
        $total = 0.0;
        foreach ($this->getComponents() as $component) {
            if ($component->isPeerComponent()) {
                $total += $component->getMaxValue();
            }
        }
        return $total;
    }

    /**
     * Gets if a gradeable has peer component(s)
     * @return bool
     */
    public function hasPeerComponent() {
        foreach ($this->getComponents() as $component) {
            if ($component->isPeerComponent()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get a list of all grading sections assigned to a given user
     * @param User $user
     * @return GradingSection[]
     */
    public function getGradingSectionsForUser(User $user) {
        if ($this->hasPeerComponent() && $user->getGroup() === User::GROUP_STUDENT) {
            if ($this->isTeamAssignment()) {
                $users = $this->core->getQueries()->getUsersById($this->core->getQueries()->getPeerAssignment($this->getId(), $user->getId()));
                $teams = [];
                foreach ($users as $u) {
                    $teamToAdd = $this->core->getQueries()->getTeamByGradeableAndUser($this->getId(), $u->getId());
                    if ($this->core->getQueries()->getTeamByGradeableAndUser($this->getId(), $u->getId()) !== null) {
                        $teams[$teamToAdd->getId()] = $this->core->getQueries()->getTeamByGradeableAndUser($this->getId(), $u->getId());
                    }
                }
                $g_section = new GradingSection($this->core, false, -1, [$user], null, $teams);
                return [$g_section];
            }
            $users = $this->core->getQueries()->getUsersById($this->core->getQueries()->getPeerAssignment($this->getId(), $user->getId()));
            $g_section = new GradingSection($this->core, false, -1, [$user], $users, null);
            return [$g_section];
        }
        else {
            $users = [];
            $teams = [];

            if ($this->isGradeByRegistration()) {
                $section_names = $user->getGradingRegistrationSections();

                if ($this->isTeamAssignment()) {
                    foreach ($section_names as $section) {
                        $teams[$section] = [];
                    }
                    $all_teams = $this->core->getQueries()->getTeamsByGradeableAndRegistrationSections($this->getId(), $section_names);
                    foreach ($all_teams as $team) {
                        /** @var Team $team */
                        $teams[$team->getRegistrationSection()][] = $team;
                    }
                }
                else {
                    foreach ($section_names as $section) {
                        $users[$section] = [];
                    }
                    $all_users = $this->core->getQueries()->getUsersByRegistrationSections($section_names);
                    foreach ($all_users as $user) {
                        /** @var User $user */
                        $users[$user->getRegistrationSection()][] = $user;
                    }
                }
                $graders = $this->core->getQueries()->getGradersForRegistrationSections($section_names);
            }
            else {
                $section_names = $this->core->getQueries()->getRotatingSectionsForGradeableAndUser($this->getId(), $user->getId());

                if ($this->isTeamAssignment()) {
                    foreach ($section_names as $section) {
                        $teams[$section] = [];
                    }
                    $all_teams = $this->core->getQueries()->getTeamsByGradeableAndRotatingSections($this->getId(), $section_names);
                    foreach ($all_teams as $team) {
                        /** @var Team $team */
                        $teams[$team->getRotatingSection()][] = $team;
                    }
                }
                else {
                    foreach ($section_names as $section) {
                        $users[$section] = [];
                    }
                    $all_users = $this->core->getQueries()->getUsersByRotatingSections($section_names);
                    foreach ($all_users as $user) {
                        /** @var User $user */
                        $users[$user->getRotatingSection()][] = $user;
                    }
                }
                $graders = $this->core->getQueries()->getGradersForRotatingSections($this->getId(), $section_names);
            }

            $sections = [];
            foreach ($section_names as $section_name) {
                $sections[] = new GradingSection(
                    $this->core,
                    $this->isGradeByRegistration(),
                    $section_name,
                    $graders[$section_name] ?? [],
                    $users[$section_name] ?? null,
                    $teams[$section_name] ?? null
                );
            }

            return $sections;
        }
    }

    /**
     * Get a list of all grading sections
     * @return GradingSection[]
     */
    public function getAllGradingSections() {
        if ($this->hasPeerComponent()) {
            //Todo: What are all sections when you have peer grading?
        }

        $users = [];
        $teams = [];

        $get_method = $this->isGradeByRegistration() ? 'getRegistrationSection' : 'getRotatingSection';

        if ($this->isTeamAssignment()) {
            $all_teams = $this->core->getQueries()->getTeamsByGradeableId($this->getId());
            foreach ($all_teams as $team) {
                /** @var Team $team */
                $teams[$team->$get_method() ?? 'NULL'][] = $team;
            }
        }
        else {
            $all_users = $this->core->getQueries()->getAllUsers();
            foreach ($all_users as $user) {
                /** @var User $user */
                $users[$user->$get_method() ?? 'NULL'][] = $user;
            }
        }

        if ($this->isGradeByRegistration()) {
            $section_names = $this->core->getQueries()->getRegistrationSections();
            foreach ($section_names as $i => $section) {
                $section_names[$i] = $section['sections_registration_id'];
            }
            $graders = $this->core->getQueries()->getGradersForRegistrationSections($section_names);
        }
        else {
            $section_names = $this->core->getQueries()->getRotatingSections();
            foreach ($section_names as $i => $section) {
                $section_names[$i] = $section['sections_rotating_id'];
            }
            $graders = $this->core->getQueries()->getGradersForRotatingSections($this->getId(), $section_names);
        }
        $section_names[] = 'NULL';

        $sections = [];
        foreach ($section_names as $section_name) {
            $sections[] = new GradingSection($this->core, $this->isGradeByRegistration(), $section_name, $graders[$section_name] ?? [], $users[$section_name] ?? null, $teams[$section_name] ?? null);
        }

        return $sections;
    }

    /**
     * return true if students can currently submit regrades for this assignment, false otherwise
     * @return bool
     */
    public function isRegradeOpen() {
        if ($this->core->getConfig()->isRegradeEnabled() == true && $this->isTaGradeReleased() && $this->regrade_allowed && ($this->grade_inquiry_start_date < $this->core->getDateTimeNow() && $this->grade_inquiry_due_date > $this->core->getDateTimeNow())) {
            return true;
        }
        return false;
    }
    /**
     * return true if the grade-inquiry is about to start for the students, false otherwise
     * @return bool
     */
    public function isGradeInquiryYetToStart() {
        if ($this->core->getConfig()->isRegradeEnabled() == true && $this->isTaGradeReleased() && $this->regrade_allowed && $this->grade_inquiry_start_date > $this->core->getDateTimeNow()) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the grade-inquiry has ended or not
     * @return bool
     */
    public function isGradeInquiryEnded() {
        if ($this->core->getConfig()->isRegradeEnabled() == true && $this->isTaGradeReleased() && $this->regrade_allowed && $this->grade_inquiry_due_date < $this->core->getDateTimeNow()) {
            return true;
        }
        return false;
    }

    /**
     * Creates a new team with the provided members
     * @param User $leader The team leader (first user)
     * @param User[] $members The team members (not including leader).
     * @param string $registration_section Registration section to give team.  Leave blank to inherit from leader. 'NULL' for null section.
     * @param int $rotating_section Rotating section to give team.  Set to -1 to inherit from leader. 0 for null section.
     * @throws \Exception If creating directories for the team fails, or writing team history fails
     *  Note: The team in the database may have already been created if an exception is thrown
     */
    public function createTeam(User $leader, array $members, string $registration_section = '', int $rotating_section = -1, string $team_name = null) {
        $all_members = $members;
        $all_members[] = $leader;

        // Validate parameters
        $gradeable_id = $this->getId();
        foreach ($all_members as $member) {
            if (!($member instanceof User)) {
                throw new \InvalidArgumentException('User array contained non-user object');
            }
            if ($this->core->getQueries()->getTeamByGradeableAndUser($gradeable_id, $member->getId()) !== null) {
                throw new \InvalidArgumentException("{$member->getId()} is already on a team");
            }
        }

        // Inherit rotating/registration section from leader if not provided
        if ($registration_section === '') {
            $registration_section = $leader->getRegistrationSection();
        }
        elseif ($registration_section === 'NULL') {
            $registration_section = null;
        }
        if ($rotating_section < 0) {
            $rotating_section = $leader->getRotatingSection();
        }
        elseif ($rotating_section === 0) {
            $rotating_section = null;
        }

        // Create the team in the database
        $team_id = $this->core->getQueries()->createTeam($gradeable_id, $leader->getId(), $registration_section, $rotating_section, $team_name);

        // Force the other team members to accept the invitation from this newly created team
        $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $leader->getId());
        foreach ($members as $i => $member) {
            $this->core->getQueries()->declineAllTeamInvitations($gradeable_id, $member->getId());
            $this->core->getQueries()->acceptTeamInvitation($team_id, $member->getId());
        }

        // Create the submission directory if it doesn't exist
        $gradeable_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "submissions", $gradeable_id);
        if (!FileUtils::createDir($gradeable_path)) {
            throw new \Exception("Failed to make folder for this assignment");
        }

        // Create the team submission directory if it doesn't exist
        $user_path = FileUtils::joinPaths($gradeable_path, $team_id);
        if (!FileUtils::createDir($user_path)) {
            throw new \Exception("Failed to make folder for this assignment for the team");
        }

        $current_time = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO")
            . " " . $this->core->getConfig()->getTimezone()->getName();
        $settings_file = FileUtils::joinPaths($user_path, "user_assignment_settings.json");

        $json = ["team_history" => [["action" => "admin_create", "time" => $current_time,
            "admin_user" => $this->core->getUser()->getId(), "first_user" => $leader->getId()]]];
        foreach ($members as $member) {
            $json["team_history"][] = ["action" => "admin_add_user", "time" => $current_time,
                "admin_user" => $this->core->getUser()->getId(), "added_user" => $member->getId()];
        }
        if (!@file_put_contents($settings_file, FileUtils::encodeJson($json))) {
            throw new \Exception("Failed to write to team history to settings file");
        }

        if ($this->isVcs()) {
            $config = $this->core->getConfig();
            AdminGradeableController::enqueueGenerateRepos($config->getSemester(), $config->getCourse(), $gradeable_id);
        }
    }

    public function getRepositoryPath(User $user, Team $team = null) {
        if (strpos($this->getVcsSubdirectory(), '://') !== false || substr($this->getVcsSubdirectory(), 0, 1) === '/') {
            $vcs_path = $this->getVcsSubdirectory();
        }
        else {
            if (strpos($this->core->getConfig()->getVcsBaseUrl(), '://')) {
                $vcs_path = rtrim($this->core->getConfig()->getVcsBaseUrl(), '/') . '/' . $this->getVcsSubdirectory();
            }
            else {
                $vcs_path = FileUtils::joinPaths($this->core->getConfig()->getVcsBaseUrl(), $this->getVcsSubdirectory());
            }
        }
        $repo = $vcs_path;

        $repo = str_replace('{$vcs_type}', $this->core->getConfig()->getVcsType(), $repo);
        $repo = str_replace('{$gradeable_id}', $this->getId(), $repo);
        $repo = str_replace('{$user_id}', $user->getId(), $repo);
        if ($this->isTeamAssignment() && $team !== null) {
            $repo = str_replace('{$team_id}', $team->getId(), $repo);
        }
        return $repo;
    }

    /**
     * Gets if a user or team has a submission for this gradeable
     * @param Submitter $submitter
     * @return bool
     */
    public function hasSubmission(Submitter $submitter) {
        if ($submitter->isTeam() && !$this->isTeamAssignment()) {
            return false;
        }
        if (!$submitter->isTeam() && $this->isTeamAssignment()) {
            $team = $this->core->getQueries()->getTeamByGradeableAndUser($this->getId(), $submitter->getId());
            if ($team === null) {
                return false;
            }
            $submitter = new Submitter($this->core, $team);
        }
        return $this->core->getQueries()->getHasSubmission($this, $submitter);
    }

    /**
     * Gets if a user has a overridden grades for this gradeable
     * @param Submitter $submitter
     * @return bool
     */
    public function hasOverriddenGrades(Submitter $submitter) {
        $userWithOverriddenGrades = $this->core->getQueries()->getAUserWithOverriddenGrades($this->getId(), $submitter->getId());
        if ($userWithOverriddenGrades === null) {
            return false;
        }
        return true;
    }

    /**
     * Gets the number of days late this gradeable would be if submitted now
     * @return int
     */
    public function getWouldBeDaysLate() {
        return max(0, DateUtils::calculateDayDiff($this->getSubmissionDueDate(), null));
    }

    /**
     * Can a given user view this gradeable
     */
    public function canView(User $user): bool {
        //Remove incomplete gradeables for non-instructors
        if (
            !$user->accessAdmin()
            && $this->getType() == GradeableType::ELECTRONIC_FILE
            && !$this->hasAutogradingConfig()
        ) {
            return false;
        }

        // student users should only see electronic gradeables -- NOTE: for now, we might change this design later
        if ($this->getType() !== GradeableType::ELECTRONIC_FILE && !$user->accessGrading()) {
            return false;
        }

        // if student view false, never show
        if (!$this->isStudentView() && !$user->accessGrading()) {
            return false;
        }

        // if student view is true and they can only view after grades are released, filter appropriately
        if ($this->isStudentView() && $this->isStudentViewAfterGrades() && !$user->accessGrading()) {
            return $this->isTaGradeReleased();
        }

        //If we're not instructor and this is not open to TAs
        $date = $this->core->getDateTimeNow();
        if ($this->getTaViewStartDate() > $date && !$user->accessAdmin()) {
            return false;
        }

        return true;
    }

    /*
     * Gets a multidimensional array containing data for all possible default configuration paths
     *
     * @return array
     */
    public function getDefaultConfigPaths(): array {
        $install_dir = $this->core->getConfig()->getSubmittyInstallPath();
        return [
            ['PROVIDED: upload_only (1 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/upload_only/config')],
            ['PROVIDED: upload_only (10 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/upload_only_10mb/config')],
            ['PROVIDED: upload_only (20 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/upload_only_20mb/config')],
            ['PROVIDED: upload_only (50 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/upload_only_50mb/config')],
            ['PROVIDED: upload_only (100 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/upload_only_100mb/config')],
            ['PROVIDED: bulk scanned pdf exam (100 mb maximum total student file submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/pdf_exam/config')],
            ['PROVIDED: iclicker_upload (for collecting student iclicker IDs)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/iclicker_upload/config')],
            ['PROVIDED: left_right_exam_seating (for collecting student handedness for exam seating assignment)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/left_right_exam_seating/config')],
            ['PROVIDED: test_notes_upload (expects single file, 2 mb maximum, 2-page pdf student submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/test_notes_upload/config')],
            ['PROVIDED: test_notes_upload_3page (expects single file, 3 mb maximum, 3-page pdf student submission)',
                FileUtils::joinPaths($install_dir, 'more_autograding_examples/test_notes_upload_3page/config')]
        ];
    }

    /**
     * Determine if $this gradeable is using a configuration that was user uploaded or created by notebook builder.
     *
     * @return bool True if using an uploaded configuration, false otherwise.
     */
    public function isUsingUploadedConfig(): bool {
        $config_upload_path = FileUtils::joinPaths(
            $this->core->getConfig()->getCoursePath(),
            'config_upload'
        );

        return !(strpos($this->getAutogradingConfigPath(), $config_upload_path) === false);
    }

    /**
     * Determine if $this gradeable has an allowed time.
     *
     * @return bool True if has allowed, false otherwise.
     */
    public function hasAllowedTime(): bool {
        return $this->allowed_minutes !== null;
    }

    /**
     * Retrieve users allowed time for this exam
     *
     * @return int Number of minutes allowed
     */
    public function getUserAllowedTime(User $user): ?int {
        if ($this->allowed_minutes === null) {
            return null;
        }
        if (empty($this->allowed_minutes_overrides) || $this->allowed_minutes_overrides === null) {
            return $this->allowed_minutes;
        }
        if (isset($this->allowed_minutes_overrides[$user->getId()])) {
            return $this->allowed_minutes_overrides[$user->getId()];
        }
        else {
            return $this->allowed_minutes;
        }
    }

    /**
     * Setup the allowed time overrides array
     *
     * @return void
     */
    public function setAllowedMinutesOverrides(array $overrides): void {
        foreach ($overrides as $override) {
            $this->allowed_minutes_overrides[$override['user_id']] = $override['allowed_minutes'];
        }
    }

    /**
     * Determines if gradeable is locked for user
     *
     * @param string $user_id
     * @return bool
     */
    public function isLocked(string $user_id): bool {
        if ($this->depends_on !== null && $this->depends_on_points !== null) {
            $dependent_gradeable = $this->core->getQueries()->getGradeableConfig($this->depends_on);
            if ($dependent_gradeable != null) {
                $dependent_gradeable_graded = $this->core->getQueries()->getGradedGradeable($dependent_gradeable, $user_id);
                if ($dependent_gradeable_graded != null) {
                    if ($dependent_gradeable_graded->hasSubmission()) {
                        if ($dependent_gradeable_graded->getAutoGradingScore() >= $this->depends_on_points) {
                            return false;
                        }
                    }
                }
            }
        }
        else {
            return false;
        }
        return true;
    }
}
