<?php

namespace app\models\gradeable;


use app\libraries\Core;
use app\libraries\GradeableType;
use app\models\AbstractModel;
use app\models\User;

/**
 * Class LateDays
 * @package app\models\gradeable
 *
 * Late day calculation model per user
 *
 * @method LateDayInfo[] getLateDayInfo()
 * @method array getLateDaysUpdates()
 */
class LateDays extends AbstractModel {

    /** @var User|null The user to whom this data belongs */
    private $user = null;
    /** @property @var LateDayInfo[] The late day info of each gradeable, indexed by gradeable id */
    protected $late_day_info = [];
    /** @property @var array All entries for the user in the `late_days` table */
    protected $late_days_updates = [];

    const STATUS_NO_SUBMISSION = 0;
    const STATUS_GOOD = 1;
    const STATUS_LATE = 2;
    const STATUS_BAD = 3;

    public static function isValidStatus($status) {
        return in_array($status, [self::STATUS_GOOD, self::STATUS_LATE, self::STATUS_BAD]);
    }

    /**
     * LateDays constructor.
     * @param Core $core
     * @param User $user
     * @param GradedGradeable[] $graded_gradeables An array of only electronic GradedGradeables
     */
    public function __construct(Core $core, User $user, array $graded_gradeables) {
        parent::__construct($core);
        $this->user = $user;

        // TODO: filter out non-late-days gradeables here (i.e. no due date gradeables)

        // Sort by due date
        usort($graded_gradeables, function (GradedGradeable $gg1, GradedGradeable $gg2) {
            return $gg1->getGradeable()->getSubmissionDueDate()->getTimestamp() - $gg2->getGradeable()->getSubmissionDueDate()->getTimestamp();
        });

        // Get the late day updates that the instructor will enter
        $this->late_days_updates = $late_days_updates = $this->core->getQueries()->getLateDayUpdates($user->getId());

        // Construct late days info for each gradeable
        $cumulative_charged_late_days = 0;
        foreach ($graded_gradeables as $graded_gradeable) {
            $info = new LateDayInfo($core, $user, $graded_gradeable, $cumulative_charged_late_days,
                $this->getLateDaysRemainingByContext($graded_gradeable->getGradeable()->getSubmissionDueDate()));
            $cumulative_charged_late_days += $info->getLateDaysCharged();
            $this->late_day_info[$graded_gradeable->getGradeableId()] = $info;
        }
    }

    /**
     * Test if the current user is allowed to view late day info for this gradeable
     * @param Gradeable $gradeable
     * @return bool True if they are
     */
    private static function filterCanView(Core $core, Gradeable $gradeable) {
        // TODO: update this with new after merged
        $user = $core->getUser();

        //Remove incomplete gradeables for non-instructors
        if (!$user->accessAdmin() && $gradeable->getType() == GradeableType::ELECTRONIC_FILE &&
            !$gradeable->hasAutogradingConfig()) {
            return false;
        }

        // student users should only see electronic gradeables -- NOTE: for now, we might change this design later
        if ($gradeable->getType() !== GradeableType::ELECTRONIC_FILE && !$user->accessGrading()) {
            return false;
        }

        // if student view false, never show
        if (!$gradeable->isStudentView() && !$user->accessGrading()) {
            return false;
        }

        //If we're not instructor and this is not open to TAs
        $date = $core->getDateTimeNow();
        if ($gradeable->getTaViewStartDate() > $date && !$user->accessAdmin()) {
            return false;
        }
        if ($gradeable->getSubmissionOpenDate() > $date && !$user->accessGrading()) {
            return false;
        }

        return true;
    }

    /**
     * Create a new LateDay instance for a given user
     * @param Core $core
     * @param User $user
     * @return LateDays
     */
    public static function fromUser(Core $core, User $user) {
        $gradeables = [];
        $graded_gradeables = [];
        foreach ($core->getQueries()->getGradeableConfigs(null, ['submission_due_date', 'grade_released_date']) as $g) {
            // User the 'core' user since it is the one permission checks are done for
            if (!LateDays::filterCanView($core, $g)) {
                continue;
            }
            $gradeables[] = $g;
        }
        foreach ($core->getQueries()->getGradedGradeables($gradeables, $user->getId()) as $gg) {
            $graded_gradeables[] = $gg;
        }
        return new LateDays($core, $user, $graded_gradeables);
    }

    public function toArray() {
        $details = parent::toArray();

        $details['user_id'] = $this->user->getId();

        return $details;
    }

    /**
     * Gets the user this late day info is for
     * @return User
     */
    public function getUser() {
        return $this->user;
    }

    /**
     * Gets the cumulative number of late days the user has used
     * @return int
     */
    public function getLateDaysUsed() {
        return $this->getLateDaysUsedByContext($this->core->getDateTimeNow());
    }

    /**
     * Gets the cumulative number of late days the user has used at a certain date
     * @param \DateTime $context
     * @return int
     */
    public function getLateDaysUsedByContext(\DateTime $context) {
        $total = 0;
        /** @var LateDayInfo $info */
        foreach ($this->late_day_info as $info) {
            if ($info->getGradedGradeable()->getGradeable()->getSubmissionDueDate() > $context) {
                break;
            }
            $total += $info->getDaysLate();
        }
        return $total;
    }

    public function getDefaultLateDays() {
        return $this->core->getConfig()->getDefaultStudentLateDays();
    }

    /**
     * Gets the current number of late days remaining
     * @return int
     */
    public function getLateDaysRemaining() {
        // Use 'now' because it is possible that there are changes that occur in the future
        return $this->getLateDaysRemainingByContext($this->core->getDateTimeNow());
    }

    /**
     * Gets the number of late days remaining as of a certain date
     * Note: This does not apply late day 'updates' retroactively
     * @param \DateTime $context The date to calculate remaining late days for
     * @return int
     */
    public function getLateDaysRemainingByContext(\DateTime $context) {
        // make an array of 'due date events', which is an array of both the gradeables
        //  and the late day updates in the `late_days` table
        $late_day_events = array_merge(
            array_map(function (LateDayInfo $info) {
                return [
                    'timestamp' => $info->getGradedGradeable()->getGradeable()->getSubmissionDueDate(),
                    'info' => $info
                ];
            }, $this->late_day_info),
            array_map(function ($update) {
                return [
                    'timestamp' => $update['since_timestamp'],
                    'update' => $update
                ];
            }, $this->late_days_updates));

        // Sort by 'timestamp'
        usort($late_day_events, function ($e1, $e2) {
            return $e1['timestamp']->getTimestamp() - $e2['timestamp']->getTimestamp();
        });

        // step through each event and keep a running count of the late days
        $prev_late_days_available = $this->core->getConfig()->getDefaultStudentLateDays();
        $late_days_remaining = $prev_late_days_available;
        foreach ($late_day_events as $event) {
            if ($event['timestamp'] > $context) {
                break;
            }
            if (isset($event['info'])) {
                // gradeable event, so subtract the number of late days charged from
                //  the running count
                /** @var LateDayInfo $info */
                $info = $event['info'];

                // Due to the way getLateDaysCharged works, this subtraction should never make the
                //  count go below zero (if it does, fix getLateDaysCharged)
                $late_days_remaining -= $info->getLateDaysCharged();
            } else if (isset($event['update'])) {
                // Late days update event, so add the difference between the new and old
                //  available count and add that to the late days remaining.
                //  Clamp to 0 to ensure that subtractions don't make us go below zero
                $new_late_days_available = $event['update']['allowed_late_days'];
                $diff = $new_late_days_available - $prev_late_days_available;
                $late_days_remaining = min(0, $late_days_remaining + $diff);
                $prev_late_days_available = $new_late_days_available;
            }
            if ($late_days_remaining < 0) {
                throw new \Error('Late days calculation failed due to logic error (LateDaysInfo::getLateDaysCharged)!');
            }
        }

        return $late_days_remaining;
    }

    /**
     * Gets if the user has any late days to use
     * @return bool
     */
    public function hasLateDaysRemaining() {
        return $this->getLateDaysRemaining() > 0;
    }

    /**
     * Gets the LateDaysInfo instance for a gradeable
     * @param Gradeable $gradeable
     * @return LateDayInfo
     */
    public function getLateDayInfoByGradeable(Gradeable $gradeable) {
        return $this->late_day_info[$gradeable->getId()];
    }

    /**
     * Gets the gradeables with a provided status
     * @param $status
     * @return array
     */
    public function getGradeablesByStatus($status) {
        if (!self::isValidStatus($status)) {
            throw new \InvalidArgumentException('Invalid gradeable status');
        }

        return array_keys(array_filter($this->late_day_info, function (LateDayInfo $ldi) use ($status) {
            return $ldi->getStatus() === $status;
        }));
    }
}