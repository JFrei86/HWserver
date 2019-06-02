<?php

namespace app\models\gradeable;

use app\exceptions\AuthorizationException;
use app\libraries\Core;
use \app\models\AbstractModel;
use app\models\User;
use app\libraries\FileUtils;
use app\exceptions\FileNotFoundException;
use app\exceptions\IOException;

/**
 * Class GradedGradeable
 * @package app\models\gradeable
 *
 * @method string getGradeableId()
 * @method AutoGradedGradeable getAutoGradedGradeable()
 * @method TaGradedGradeable|null getTaGradedGradeable()
 * @method RegradeRequest|null getRegradeRequest()
 * @method Submitter getSubmitter()
 * @method array getLateDayExceptions()
 */
class GradedGradeable extends AbstractModel {
    /** @var Gradeable Reference to gradeable */
    private $gradeable = null;
    /** @property @var string Id of the gradeable this grade is attached to */
    protected $gradeable_id = "";

    /** @property @var Submitter The submitter who received this graded gradeable */
    protected $submitter = null;
    /** @property @var TaGradedGradeable|null The TA Grading info or null if it doesn't exist  */
    protected $ta_graded_gradeable = null;
    /** @property @var AutoGradedGradeable The Autograding info */
    protected $auto_graded_gradeable = null;
    /** @property @var RegradeRequest|null The grade inquiry for this submitter/gradeable  */
    protected $regrade_request = null;

    /** @property @var array The late day exceptions indexed by user id */
    protected $late_day_exceptions = [];


    /**
     * GradedGradeable constructor.
     * @param Core $core
     * @param Gradeable $gradeable The gradeable associated with this grade
     * @param Submitter $submitter The user or team who submitted for this graded gradeable
     * @param array $details Other construction details (indexed by property name)
     * @throws \InvalidArgumentException If the provided gradeable or submitter are null
     */
    public function __construct(Core $core, Gradeable $gradeable, Submitter $submitter, array $details) {
        parent::__construct($core);

        // Check the gradeable instance
        if ($gradeable === null) {
            throw new \InvalidArgumentException('Gradeable cannot be null');
        }
        $this->gradeable = $gradeable;
        $this->gradeable_id = $gradeable->getId();

        // Check the Submitter instance
        if ($submitter === null) {
            throw new \InvalidArgumentException('Submitter cannot be null');
        }
        $this->submitter = $submitter;

        $this->late_day_exceptions = $details['late_day_exceptions'] ?? [];
    }

    /**
     * Gets the gradeable this grade data is associated with
     * @return Gradeable the gradeable this grade data is associated with
     */
    public function getGradeable() {
        return $this->gradeable;
    }

    /**
     * Sets the TA grading data for this graded gradeable
     * @param TaGradedGradeable $ta_graded_gradeable
     */
    public function setTaGradedGradeable(TaGradedGradeable $ta_graded_gradeable) {
        $this->ta_graded_gradeable = $ta_graded_gradeable;
    }

    /**
     * Gets the TaGradedGradeable for this graded gradeable, or generates a blank
     *  one if none exists
     * @return TaGradedGradeable|null
     */
    public function getOrCreateTaGradedGradeable() {
        if ($this->ta_graded_gradeable === null) {
            $this->ta_graded_gradeable = new TaGradedGradeable($this->core, $this, []);
        }
        return $this->ta_graded_gradeable;
    }

    /**
     * Sets the Autograding data for this graded gradeable
     * @param AutoGradedGradeable $auto_graded_gradeable
     */
    public function setAutoGradedGradeable(AutoGradedGradeable $auto_graded_gradeable) {
        $this->auto_graded_gradeable = $auto_graded_gradeable;
    }

    /**
     * Gets whether any TA grading information exists for this submitter/gradeable
     * @return bool
     */
    public function hasTaGradingInfo() {
        return $this->ta_graded_gradeable !== null && $this->ta_graded_gradeable->anyGrades();
    }

    /**
     * Gets whether the TA grading has been completed for this submitter/gradeable
     * @return bool
     */
    public function isTaGradingComplete() {
        return $this->hasTaGradingInfo() && $this->ta_graded_gradeable->isComplete();
    }

    /**
     * Sets the grade inquiry for this graded gradeable
     * @param RegradeRequest $regrade_request
     */
    public function setRegradeRequest(RegradeRequest $regrade_request) {
        $this->regrade_request = $regrade_request;
    }

    /**
     * Gets if the submitter has a grade inquiry
     * @return bool
     */
    public function hasRegradeRequest() {
        return $this->regrade_request !== null;
    }

    /**
     * Gets if the submitter has an active grade inquiry
     * @return bool
     */
    public function hasActiveRegradeRequest() {
        return $this->hasRegradeRequest() && $this->regrade_request->getStatus();
    }

    /**
     * Gets the late day exception count for a user
     * @param User|null $user The user to get exception info for (can be null if not team assignment)
     * @return int The number of late days the user has for this gradeable
     */
    public function getLateDayException($user = null) {
        if($user === null) {
            if($this->gradeable->isTeamAssignment()) {
                throw new \InvalidArgumentException('Must provide user if team assignment');
            }
            return $this->late_day_exceptions[$this->submitter->getId()] ?? 0;
        }
        return $this->late_day_exceptions[$user->getId()] ?? 0;
    }

    /**
     * Gets the auto grading score for the active version, or 0 if none
     * @return int
     */
    public function getAutoGradingScore() {
        if ($this->getAutoGradedGradeable()->hasActiveVersion()) {
            return $this->getAutoGradedGradeable()->getActiveVersionInstance()->getTotalPoints();
        }
        return 0;
    }

    /**
     * Gets the ta grading score
     * Note: This does not check any consistency with submission version
     *  and graded version
     * @return float
     */
    public function getTaGradingScore() {
        if ($this->hasTaGradingInfo()) {
            return $this->getTaGradedGradeable()->getTotalScore();
        }
        return 0.0;
    }

    /**
     * Gets the total score for this student's active submission
     * Note: This does not check that the graded version matches
     *      the active version or any other consistency checking
     * @return float max(0.0, auto_score + ta_score)
     */
    public function getTotalScore() {
        return floatval(max(0.0, $this->getTaGradingScore() + $this->getAutoGradingScore()));
    }

    /**
     * Gets a new 'notebook' which contains information about most recent submissions
     *
     * @return array An updated 'notebook' which has the most recent submission data entered into the
     * 'recent_submission_string' key for each input item inside the notebook.  If there haven't been any submissions,
     * then 'recent_submission_string' is populated with 'starter_value_string' if one exists, otherwise it will be
     * blank.
     */
    public function getUpdatedNotebook() {

        // Get notebook
        $newNotebook = $this->getGradeable()->getAutogradingConfig()->getNotebook();

        foreach ($newNotebook as $notebookKey => $notebookVal) {

            // Handle if the notebook cell type is short_answer
            if(isset($notebookVal['type']) &&
               $notebookVal['type'] == "short_answer") {

                // If no previous submissions set string to default initial_value
                if($this->getAutoGradedGradeable()->getHighestVersion() == 0)
                {
                    $recentSubmission = $notebookVal['initial_value'];
                }
                // Else there has been a previous submission try to get it
                else
                {
                    try
                    {
                        // Try to get the most recent submission
                        $recentSubmission = $this->getRecentSubmissionContents($notebookVal['filename']);
                    }
                    catch (AuthorizationException $e)
                    {
                        // If the user lacked permission then just set to default instructor provided string
                        $recentSubmission = $notebookVal['initial_value'];
                    }
                }

                // Add field to the array
                $newNotebook[$notebookKey]['recent_submission'] = $recentSubmission;
            }

            // Handle if notebook cell type is multiple_choice
            else if(isset($notebookVal['type']) &&
                    $notebookVal['type'] == "multiple_choice")
            {

                // If no previous submissions do nothing
                if($this->getAutoGradedGradeable()->getHighestVersion() == 0)
                {
                    continue;
                }
                // Else there has been a previous submission try to get it
                else
                {
                    try
                    {
                        // Try to get the most recent submission
                        $recentSubmission = $this->getRecentSubmissionContents($notebookVal['filename']);

                        // Add field to the array
                        $newNotebook[$notebookKey]['recent_submission'] = $recentSubmission;
                    }
                    catch (AuthorizationException $e)
                    {
                        // If failed to get the most recent submission then skip
                        continue;
                    }
                }
            }
        }

        // Operate on notebook to add prev_submission field to inputs
        return $newNotebook;
    }

    /**
     * Get the data from the student's most recent submission
     *
     * @param $filename Name of the file to collect the data out of
     * @throws AuthorizationException if the user lacks permissions to read the submissions file
     * @throws FileNotFoundException if file with passed filename could not be found
     * @throws IOException if there was an error reading contents from the file
     * @return string if successful returns the contents of a students most recent submission
     */
    private function getRecentSubmissionContents($filename) {

        // Get items in path to student's submission folder
        $course_path = $this->core->getConfig()->getCoursePath();
        $gradable_dir = $this->getGradeableId();
        $student_id = $this->core->getUser()->getId();
        $version = $this->getAutoGradedGradeable()->getHighestVersion();

        // Join path items
        $complete_file_path = FileUtils::joinPaths(
            $course_path,
            'submissions',
            $gradable_dir,
            $student_id,
            $version,
            $filename);

        // Check if the user has permission to access this submission
        $isAuthorized = $this->core->getAccess()->canI('path.read', ["dir" => "submissions", "path" => $complete_file_path]);

        // If user lacks permission to get the submission contents throw Auth exception
        if(!$isAuthorized)
        {
            throw new AuthorizationException("The user lacks permissions to access this data.");
        }

        // If desired file does not exist in the most recent submission directory throw exception
        if(!file_exists($complete_file_path))
        {
            throw new FileNotFoundException("Unable to locate submission file.");
        }

        // Read file contents into string
        $file_contents = file_get_contents($complete_file_path);

        // If file_contents is False an error has occured
        if($file_contents === False)
        {
            throw new IOException("An error occurred retrieving submission contents.");
        }

        // Remove trailing newline
        $file_contents = rtrim($file_contents, "\n");

        return $file_contents;
    }

    /* Intentionally Unimplemented accessor methods */

    /** @internal */
    public function setGradeableId($id) {
        throw new \BadFunctionCallException('Cannot set id of gradeable associated with gradeable data');
    }

    /** @internal */
    public function setSubmitter(Submitter $submitter) {
        throw new \BadFunctionCallException('Cannot set gradeable submitter');
    }

    /** @internal  */
    public function setLateDayExceptions() {
        throw new \BadFunctionCallException('Cannot set late day exception info');
    }
}
