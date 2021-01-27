<?php

namespace app\models\gradeable;

use app\exceptions\AuthorizationException;
use app\libraries\Core;
use app\models\AbstractModel;
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
 * @method array|null getRegradeRequests()
 * @method Submitter getSubmitter()
 * @method array getLateDayExceptions()
 */
class GradedGradeable extends AbstractModel {
    /** @var Gradeable Reference to gradeable */
    private $gradeable = null;
    /** @prop @var string Id of the gradeable this grade is attached to */
    protected $gradeable_id = "";

    /** @prop @var Submitter The submitter who received this graded gradeable */
    protected $submitter = null;
    /** @prop @var TaGradedGradeable|null The TA Grading info or null if it doesn't exist  */
    protected $ta_graded_gradeable = null;
    /** @prop @var AutoGradedGradeable The Autograding info */
    protected $auto_graded_gradeable = null;
    /** @prop @var array The grade inquiries for this submitter/gradeable  */
    protected $regrade_requests = [];

    /** @prop @var array The late day exceptions indexed by user id */
    protected $late_day_exceptions = [];

    /** @prop @var int Late Day Info STatus */
    protected $late_day_status = null;

    /** @prop @var bool|null|SimpleGradeOverriddenUser Does this graded gradeable have overridden grades */
    protected $overridden_grades = false;

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
        $this->late_day_status = $details['late_day_status'] ?? null;
    }

    /**
     * Gets if the submitter submitted on time
     * @return bool
     */
    public function isOnTimeSubmission() {
        // if there is no submission, ignore
        if (!$this->hasSubmission()) {
            return true;
        }

        // If there is no info cached, treat it as a normal gradeable
        if (!$this->hasLateDayInfoCached()) {
            LateDays::cacheLateDayInfoForUser($this->core, $this->submitter->getId());
            return true;
        }

        return $this->late_day_status == LateDayInfo::STATUS_GOOD || $this->late_day_status == LateDayInfo::STATUS_LATE;
    }

    /**
     * Gets if the submitter submitted on time
     * @return bool
     */
    public function hasLateDayInfoCached() {
        return $this->late_day_status != null;
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
     * Gets whether a peer grader has graded all of the peer components for this submitter/gradeable
     * Later this will take in a userId and determine if that user graded all components
     * @return bool
     */
    public function isPeerGradingComplete() {
        foreach ($this->ta_graded_gradeable->getGradedComponentContainers() as $container) {
            if (!$container->isComplete() && $container->getComponent() != null && $container->getComponent()->isPeer()) {
                return false;
            }
        }
        return true;
    }


    /**
     * Sets the grade inquiry for this graded gradeable
     * @param array $regrade_requests
     */
    public function setRegradeRequests(array $regrade_requests) {
        $this->regrade_requests = $regrade_requests;
    }

    /**
     * Gets if the submitter has a grade inquiry
     * @return bool
     */
    public function hasRegradeRequest() {
        return $this->regrade_requests !== null && count($this->regrade_requests) > 0;
    }

    /**
     * Gets if the submitter has an active grade inquiry
     * @return bool
     */
    public function hasActiveRegradeRequest() {
        return $this->hasRegradeRequest() &&
            array_reduce($this->regrade_requests, function ($carry, RegradeRequest $grade_inquiry) {
                if ($this->gradeable->isGradeInquiryPerComponentAllowed()) {
                    $carry = $grade_inquiry->getStatus() == RegradeRequest::STATUS_ACTIVE || $carry;
                }
                else {
                    $carry = $grade_inquiry->getStatus() == RegradeRequest::STATUS_ACTIVE && is_null($grade_inquiry->getGcId()) || $carry;
                }

                return $carry;
            });
    }

    /**
     * Gets the grade inquiry assigned to the gradeable's component supplied
     * @param $gc_id int Gradeable Component id
     */
    public function getGradeInquiryByGcId($gc_id) {
        foreach ($this->regrade_requests as $grade_inquiry) {
            if ($grade_inquiry->getGcId() == $gc_id) {
                return $grade_inquiry;
            }
        }
        return null;
    }

    /**
     * get the number of grade inquiries that are pending
     * @return int
     */
    public function getActiveGradeInquiryCount() {
        if (!$this->gradeable->isGradeInquiryPerComponentAllowed()) {
            return array_reduce($this->regrade_requests, function ($carry, RegradeRequest $grade_inquiry) {
                return $carry + (is_null($grade_inquiry->getGcId()) && $grade_inquiry->getStatus() == RegradeRequest::STATUS_ACTIVE ? 1 : 0);
            });
        }
        return array_reduce($this->regrade_requests, function ($carry, RegradeRequest $grade_inquiry) {
            return $carry + ($grade_inquiry->getStatus() == RegradeRequest::STATUS_ACTIVE ? 1 : 0);
        });
    }

    /**
     * get number of grade inquiries pending and resolved for this gradeable
     * @return int
     */
    public function getGradeInquiryCount() {
        return count($this->regrade_requests);
    }

    /**
     * Gets the late day exception count for a user
     * @param User|null $user The user to get exception info for (can be null if not team assignment)
     * @return int The number of late days the user has for this gradeable
     */
    public function getLateDayException($user = null) {
        if ($user === null) {
            if ($this->gradeable->isTeamAssignment()) {
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
        if ($this->hasOverriddenGrades()) {
            return floatval(max(0.0, $this->overridden_grades->getMarks()));
        }
        else {
            return floatval(max(0.0, $this->getTaGradingScore() + $this->getAutoGradingScore()));
        }
    }

    public function getOverriddenComment() {
        $overridden_comment = "";
        if ($this->hasOverriddenGrades()) {
            $overridden_comment = $this->overridden_grades->getComment();
        }
        return $overridden_comment;
    }


    public function hasSubmission() {
        return $this->gradeable->hasSubmission($this->submitter);
    }

    public function hasOverriddenGrades() {
        if ($this->overridden_grades === false) {
            $this->overridden_grades = $this->core->getQueries()->getAUserWithOverriddenGrades($this->gradeable_id, $this->submitter->getId());
        }
        return $this->overridden_grades !== null;
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
