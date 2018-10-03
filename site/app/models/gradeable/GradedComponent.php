<?php

namespace app\models\gradeable;

use app\libraries\GradeableType;
use app\models\AbstractModel;
use app\libraries\Core;
use app\libraries\DateUtils;
use app\models\User;

/**
 * Class GradedComponent
 * @package app\models\gradeable
 *
 * @method int getComponentId()
 * @method float getScore()
 * @method string getComment()
 * @method void setComment($comment)
 * @method string getGraderId()
 * @method int getGradedVersion()
 * @method void setGradedVersion($graded_version)
 * @method \DateTime getGradeTime()
 * @method int[] getMarkIds()
 * @method bool isMarksModified()
 */
class GradedComponent extends AbstractModel {
    /** @var Component Reference to component */
    private $component = null;
    /** @var TaGradedGradeable Reference to TaGradedGradeable this component belongs to */
    private $ta_graded_gradeable = null;
    /** @property @var string Id of the component this grade is attached to */
    protected $component_id = 0;

    /** @var User The grader of this component */
    private $grader = null;

    /** @var Mark[] References to the marks this graded component received */
    private $marks = array();

    /** @property @var int[] The mark ids the submitter received for this component */
    protected $mark_ids = array();
    /** @property @var int[]|null The mark ids the submitter received for this component as reflected in the db */
    private $db_mark_ids = null;

    /** @property @var bool True if the marks array was modified after construction */
    protected $marks_modified = false;

    /** @property @var float The score for this component (or custom mark point value) */
    protected $score = 0.0;
    /** @property @var string The comment on this mark / custom mark description */
    protected $comment = "";
    /** @property @var string The Id of the grader who most recently updated the component's grade */
    protected $grader_id = "";
    /** @property @var int The submission version this grade is for */
    protected $graded_version = 0;
    /** @property @var \DateTime The time which this grade was most recently updated */
    protected $grade_time = null;

    /**
     * GradedComponent constructor.
     * @param Core $core
     * @param TaGradedGradeable $ta_graded_gradeable The grade this component belongs to
     * @param Component $component The component this grade is associated with
     * @param User $grader The user who graded this component
     * @param array $details any remaining properties
     * @throws \InvalidArgumentException if any of the details are invalid, or the component/grader are null
     */
    public function __construct(Core $core, TaGradedGradeable $ta_graded_gradeable, Component $component, User $grader, array $details) {
        parent::__construct($core);

        if($ta_graded_gradeable === null) {
            throw new \InvalidArgumentException('Cannot create GradedComponent with null TaGradedGradeable');
        }
        $this->ta_graded_gradeable = $ta_graded_gradeable;

        $this->setComponent($component);
        $this->setGrader($grader);

        $this->setComment($details['comment'] ?? '');
        $this->setGradedVersion($details['graded_version'] ?? 0);
        $this->setGradeTime($details['grade_time'] ?? $this->core->getDateTimeNow());

        // assign the default score if its not electronic (or rather not a custom mark)
        if ($component->getGradeable()->getType() === GradeableType::ELECTRONIC_FILE) {
            $score = $details['score'] ?? 0;
        } else {
            $score = $details['score'] ?? $component->getDefault();
        }
        $this->setScore($score);

        $this->modified = false;
    }

    public function toArray() {
        $details = parent::toArray();

        // Make sure to convert the date into a string
        $details['grade_time'] = DateUtils::dateTimeToString($this->grade_time);

        return $details;
    }

    /**
     * Gets the component associated with this component data
     * @return Component
     */
    public function getComponent() {
        return $this->component;
    }

    /**
     * Gets the TaGradedGradeable that owns this graded component
     * @return TaGradedGradeable
     */
    public function getTaGradedGradeable() {
        return $this->ta_graded_gradeable;
    }

    /**
     * Gets the user who graded this component
     * @return User
     */
    public function getGrader() {
        return $this->grader;
    }

    /**
     * Gets references to the marks that the submitter received
     * @return Mark[]
     */
    public function getMarks() {
        return $this->marks;
    }

    /**
     * Gets if this component grade is attached to a AutoGradedVersion
     * @return bool
     */
    public function hasGradedVersion() {
        return $this->graded_version !== -1;
    }

    /**
     * Gets if this graded component received a mark id
     * @param int $mark_id
     * @return bool
     */
    public function hasMarkId($mark_id) {
        return in_array($mark_id, $this->mark_ids);
    }

    /**
     * Gets if this graded component received a mark
     * @param Mark $mark
     * @return bool
     */
    public function hasMark(Mark $mark) {
        return $this->hasMarkId($mark->getId());
    }

    /**
     * Gets if the graded component has any common marks assigned to it
     * @return bool
     */
    public function anyMarks() {
        return count($this->marks) !== 0;
    }

    /**
     * Gets if the graded component has a custom mark
     * @return bool
     */
    public function hasCustomMark() {
        return $this->getComment() !== '' && $this->getScore() != 0.0;
    }

    /**
     * Gets the total number of points earned for this component
     *  (including mark points)
     * @return float
     */
    public function getTotalScore() {
        if (!$this->anyMarks() && !$this->hasCustomMark()) {
            return 0.0; // Return no points if the user has no marks and no custom mark
        }
        // Be sure to add the default so count-down gradeables don't become negative
        $score = $this->component->getDefault();
        foreach($this->marks as $mark) {
            $score += $mark->getPoints();
        }
        $score += $this->getScore();
        $score = min(max($score, $this->component->getLowerClamp()), $this->component->getUpperClamp());
        return $this->getTaGradedGradeable()->getGradedGradeable()->getGradeable()->roundPointValue($score);
    }

    /**
     * Gets if this GradedComponent is new (not in db yet)
     * @return bool
     */
    public function isNew() {
        return $this->db_mark_ids === null;
    }

    /* Overridden setters with validation */

    /**
     * Sets the component reference for this data
     * @param Component $component
     */
    private function setComponent(Component $component) {
        if ($component === null) {
            throw new \InvalidArgumentException('Component cannot be null');
        }
        $this->component = $component;
        $this->component_id = $component->getId();
    }

    /**
     * Sets the marks the submitter received for this component
     * @param int[] $mark_ids
     */
    public function setMarkIds(array $mark_ids) {
        // This may seem redundant, but by fetching the marks from the component and calling setMarks, we
        //  effectively filter out any of the invalid values in $mark_ids
        $marks = [];
        $actual_ids = [];
        foreach ($this->component->getMarks() as $mark) {
            if (in_array($mark->getId(), $mark_ids)) {
                $marks[] = $mark;
                $actual_ids[] = $mark->getId();
            }
        }
        $this->marks = $marks;
        $this->mark_ids = $actual_ids;
        $this->marks_modified = true;
    }

    /**
     * Called from the db methods to load the mark ids the submitter received
     * @param int[] $db_mark_ids
     * @internal
     */
    public function setMarkIdsFromDb(array $db_mark_ids) {
        $this->setMarkIds($db_mark_ids);
        $this->db_mark_ids = $db_mark_ids;
        $this->marks_modified = false;
    }

    /**
     * Gets the mark ids loaded from the database
     * @return int[]|null
     * @internal
     */
    public function getDbMarkIds() {
        return $this->db_mark_ids;
    }

    /**
     * Sets the last time this component data was changed
     * @param \DateTime|string $grade_time Either a \DateTime object, or a date-time string
     * @throws \InvalidArgumentException if $grade_time is a string and failed to parse into a \DateTime object
     */
    public function setGradeTime($grade_time) {
        if ($grade_time === null) {
            $this->grade_time = null;
        } else {
            try {
                $this->grade_time = DateUtils::parseDateTime($grade_time, $this->core->getConfig()->getTimezone());
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid date string format');
            }
        }
        $this->modified = true;
    }

    /**
     * Sets the user who most recently changed the component data
     * @param User $grader
     */
    public function setGrader(User $grader) {
        $this->grader = $grader;
        $this->grader_id = $this->grader !== null ? $grader->getId() : '';
        $this->modified = true;
    }

    /**
     * Sets the score the submitter received for this component--clamped or custom mark--not clamped
     * @param float $score
     */
    public function setScore(float $score) {
        if ($this->component->getGradeable()->getType() === GradeableType::ELECTRONIC_FILE) {
            $this->score = $score;
        } else {
            // clamp the score (no error if not in bounds)
            //  min(max(a,b),c) will clamp the value 'b' in the range [a,c]
            $this->score = min(max($this->component->getLowerClamp(), $score), $this->component->getUpperClamp());
        }
        $this->score = $this->getComponent()->getGradeable()->roundPointValue($this->score);
        $this->modified = true;
    }

    /* Intentionally Unimplemented accessor methods */

    /** @internal */
    public function setComponentId() {
        throw new \BadFunctionCallException('Cannot set component data\'s component Id');
    }

    /** @internal */
    public function setGraderId() {
        throw new \BadFunctionCallException('Cannot set grader Id');
    }

    /** @internal */
    public function setDbMarkIds() {
        throw new \BadFunctionCallException('Cannot set db mark ids');
    }
}
