<?php

namespace app\models\gradeable;

use app\libraries\Core;
use app\libraries\DateUtils;
use app\libraries\Utils;
use app\models\AbstractModel;
use app\models\User;

/**
 * Class TaGradedGradeable
 * @package app\models\gradeable
 *
 * @method string getOverallComments()
 * @method void setOverallComment($comment)
 * @method int getId()
 * @method \DateTime|null getUserViewedDate()
 */
class TaGradedGradeable extends AbstractModel {
    /** @prop @var GradedGradeable A reference to the graded gradeable this Ta grade belongs to */
    private $graded_gradeable = null;
    /** @prop @var int The id of this gradeable data */
    protected $id = 0;
    /** @prop @var string[] indexed by user_id. Overall comment made by each grader. */
    protected $overall_comments = [];
    /** @prop @var \DateTime|null The date the user viewed their grade */
    protected $user_viewed_date = null;
    /** @prop @var GradedComponentContainer[] The GradedComponentContainers, indexed by component id */
    private $graded_component_containers = [];
    /** @prop @var GradedComponent[] The components that have been marked for deletion */
    private $deleted_graded_components = [];


    /**
     * TaGradedGradeable constructor.
     * @param Core $core
     * @param array $details A property-name-indexed array of values to construct with
     * @param GradedGradeable $graded_gradeable
     * @throws \InvalidArgumentException If any of the details are invalid or the graded gradeable is null
     */
    public function __construct(Core $core, GradedGradeable $graded_gradeable, array $details) {
        parent::__construct($core);

        if ($graded_gradeable === null) {
            throw new \InvalidArgumentException('Graded gradeable cannot be null');
        }
        $this->graded_gradeable = $graded_gradeable;

        $this->setIdFromDatabase($details['id'] ?? 0);
        $this->setUserViewedDate($details['user_viewed_date'] ?? null);

        if (array_key_exists("overall_comments", $details)) {
            $this->overall_comments = $details['overall_comments'];
        }

        // Default to all blank components
        foreach ($graded_gradeable->getGradeable()->getComponents() as $component) {
            $this->graded_component_containers[$component->getId()] = new GradedComponentContainer($core, $this, $component);
        }

        $this->modified = false;
    }

    /**
     * Gets the array representation of the submitter's TA grade.
     *  Note: if a specific grader is provided, the 'graded_components' will be an array of GradedComponent's
     *      indexed by component id, but if no grader is provided, 'graded_components' will be an array of GradedComponent[]'s
     *      indexed by component id (for all graders).
     * @param User|null $grader If provided, only the grades relevant to this grader will be fetched
     * @return array
     */
    public function toArray($grader = null) {
        $details = parent::toArray();

        // Make sure to convert the date into a string
        $details['user_viewed_date'] = $this->user_viewed_date !== null ? DateUtils::dateTimeToString($this->user_viewed_date) : null;

        /** @var GradedComponent[] $graded_components */
        $graded_components = [];

        // Get the graded components for the provided grader (or all for null)
        if ($grader !== null) {
            /** @var GradedComponentContainer $container */
            foreach ($this->graded_component_containers as $container) {
                $graded_component = $container->getGradedComponent($grader);
                if ($graded_component !== null) {
                    $graded_components[] = $graded_component;
                    $details['graded_components'][$container->getComponent()->getId()] = $graded_component->toArray();
                    $graders[$graded_component->getGrader()->getId()] = $graded_component->getGrader();
                }
            }
        }
        else {
            // Grab the total peer score for each component here rather than computing on the site.
            $details["peer_scores"] = [];
            /** @var GradedComponentContainer $container */
            foreach ($this->graded_component_containers as $container) {
                $details["peer_scores"][$container->getComponent()->getId()] = $container->getTotalScore();
                $details['graded_components'][$container->getComponent()->getId()] = $container->toArray();
                $graded_components = array_merge($graded_components, $container->getGradedComponents());
            }
        }

        $current_user_id = $this->core->getUser()->getId();
        $current_user_comment = array_key_exists($current_user_id, $this->overall_comments) ? $this->overall_comments[$current_user_id] : "";
        $details["ta_grading_overall_comments"] = [];
        $details["ta_grading_overall_comments"]["logged_in_user"]["user_id"] = $current_user_id;
        $details["ta_grading_overall_comments"]["logged_in_user"]["comment"] = $current_user_comment;
        $details["ta_grading_overall_comments"]["other_graders"] = [];

        // Students (peers) are not allowed to see other graders' comments.
        if ($this->core->getUser()->getGroup() < 4) {
            foreach ($this->overall_comments as $commenter => $comment) {
                if ($commenter === $current_user_id) {
                    continue;
                }
                $details["ta_grading_overall_comments"]["other_graders"][$commenter] = $comment;
            }
        }

        // Uncomment this block if we want to serialize these values all the time
//        $percent_graded = $this->getPercentGraded();
//        $percent_graded = is_nan($percent_graded) ? 0 : $percent_graded;
//        $details['percent_graded'] = $percent_graded;
//
//        $graded_percent = $this->getGradedPercent();
//        $graded_percent = is_nan($graded_percent) ? 0 : $graded_percent;
//        $details['graded_percent'] = $graded_percent;

        return $details;
    }

    /**
     * Gets all component containers
     * @return GradedComponentContainer[]
     */
    public function getGradedComponentContainers() {
        return $this->graded_component_containers;
    }

    /**
     * Gets all component grades for a given component
     * @param Component $component The component to get grades for
     * @return GradedComponentContainer
     */
    public function getGradedComponentContainer(Component $component) {
        $container = $this->graded_component_containers[$component->getId()] ?? null;
        if ($container === null) {
            throw new \InvalidArgumentException('Invalid component');
        }
        return $container;
    }

    /**
     * Gets or creates a graded component based on the logic of GradedComponentContainer::getOrCreateGradedComponent
     *  for the provided component
     * @param Component $component
     * @param User|null $grader The grader to look for
     * @param bool $generate If a new graded component should be generated if none were found
     * @return GradedComponent|null The graded component instance or null if not found
     */
    public function getOrCreateGradedComponent(Component $component, $grader = null, $generate = false) {
        return $this->getGradedComponentContainer($component)->getOrCreateGradedComponent($grader, $generate);
    }

    /**
     * Gets the graded component with the provided component and grader
     * @param Component $component The component the grade is for
     * @param User|null $grader The grader for this component
     * @return GradedComponent|null
     * @throws \InvalidArgumentException If $grader is null and $component is peer
     */
    public function getGradedComponent(Component $component, $grader = null) {
        // The subset of the above function's features satisfy the
        //  expected behavior for a normal getter
        return $this->getOrCreateGradedComponent($component, $grader, false);
    }

    /**
     * Gets the AutoGradedVersion for this grade
     * @param bool $strict if true, all grades for this gradeable must have a consistent version
     *                      otherwise, returns the first valid version number found
     * @return AutoGradedVersion|null
     */
    public function getGradedVersionInstance($strict = true) {
        $versions = $this->graded_gradeable->getAutoGradedGradeable()->getAutoGradedVersions();
        $version_number = $this->getGradedVersion($strict);
        // Get the version instance associated with the graded version
        return $versions[$version_number] ?? null;
    }

    /**
     * Gets the version number for the submission associated with this grade
     * @param bool $strict if true, all grades for this gradeable must have a consistent version
     *                      otherwise, return the first valid version number found
     * @return bool|int returns false if $strict is true and the versions aren't consistent
     */
    public function getGradedVersion($strict = true) {
        $version = false;
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $v = $container->getGradedVersion();
            if ($v !== false && $strict !== true) {
                return $v;
            }
            elseif ($v === false) {
                return false;
            }

            if ($version === false) {
                $version = $v;
            }
            elseif ($version !== $v) {
                return false;
            }
        }
        return $version;
    }

    /**
     * Gets if any the graded components' versions are not the active version
     * @return bool
     */
    public function hasVersionConflict() {
        $active_version = $this->getGradedGradeable()->getAutoGradedGradeable()->getActiveVersion();
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            foreach ($container->getGradedComponents() as $component) {
                if ($component->getGradedVersion() !== $active_version) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Gets the graded gradeable instance this Ta grade belongs to
     * @return GradedGradeable
     */
    public function getGradedGradeable() {
        return $this->graded_gradeable;
    }

    /**
     * Gets the manual grading points the student earned
     * @return float
     */
    public function getTotalScore($grader = null) {
        $points_earned = 0.0;
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $points_earned += $container->getTotalScore($grader);
        }
        return $points_earned;
    }

    /**
     * Gets the percent of points the student has earned of the
     *  components that have been graded
     * @param bool $clamp True to clamp the result to 1.0
     * @return float percentage (0 to 1), or NAN if no grading started
     */
    public function getTotalScorePercent($clamp = false, $grader = null) {
        return Utils::safeCalcPercent(
            $this->getTotalScore(),
            $this->getGradedGradeable()->getGradeable()->getManualGradingPoints(),
            $clamp
        );
    }

    /**
     * Gets how many of this submitter's components has been graded
     * @return int
     */
    public function getComponentsGraded() {
        $running_num = 0;
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $running_num += $container->getComponentsGraded();
        }
        return $running_num;
    }

    /**
     * Gets how much of this submitter's submission has been graded
     * @return float percentage (0 to 1) not clamped to 100%, or NAN if no component in gradeable
     */
    public function getPercentGraded() {
        $running_percent = 0.0;
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $running_percent += $container->getPercentGraded();
        }
        return Utils::safeCalcPercent($running_percent, count($this->graded_component_containers), false);
    }

    /**
     * Gets if this graded gradeable is completely graded
     * @return bool
     */
    public function isComplete() {
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            if (!$container->isComplete()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Gets if this graded gradeable has any grades
     * @return bool
     */
    public function anyGrades() {
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            if ($container->anyGradedComponents()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sets the id of this grade data (used from database methods)
     * @param int $id
     * @internal
     */
    public function setIdFromDatabase($id) {
        if ((is_int($id) || ctype_digit($id)) && intval($id) >= 0) {
            $this->id = intval($id);
        }
        else {
            throw new \InvalidArgumentException('Id must be a non-negative integer');
        }
        // Reset the modified flag since this gets called once saved to db or constructor
        $this->modified = false;
    }

    /**
     * Sets the array of graded component containers for this gradeable data
     *  Note: only call from db methods for loading
     * @param GradedComponentContainer[] $containers
     * @internal
     */
    public function setGradedComponentContainersFromDatabase(array $containers) {
        $containers_by_id = [];
        foreach ($containers as $container) {
            if (!($container instanceof GradedComponentContainer)) {
                throw new \InvalidArgumentException('Graded Component Container array contained invalid type');
            }

            // Index by component id
            $containers_by_id[$container->getComponent()->getId()] = $container;
        }
        $this->graded_component_containers = $containers_by_id;
    }

    /**
     * Clears the array of graded components en-route to deletion
     *  Note: only call from db methods for saving
     * @internal
     */
    public function clearDeletedGradedComponents() {
        $this->deleted_graded_components = [];
    }

    /**
     * Sets the overall comment for a grader. Access should be checked before calling this function.
     * @param string $comment. The comment to be saved.
     * @param string $grader_id. The grader that made the comment.
     */
    public function setOverallComment($comment, $grader_id) {
        $this->overall_comments[$grader_id] = $comment;
    }

    /**
     * Removes the overall comment for a grader. Access should be checked before calling this function.
     * @param string $grader_id. The grader that made the comment.
     */
    public function removeOverallComment($grader_id) {
        unset($this->overall_comments[$grader_id]);
    }

    /**
     * Retrieves a mapping of grader id to overall comment. If grader is passed in, returns only
     * the key, value pair for that grader.
     * @param User|null $grader The grader to retrieve a comment for. Optional.
     */
    public function getOverallComments(User $grader = null) {
        if ($grader === null) {
            return $this->overall_comments;
        }
        else {
            if (array_key_exists($grader->getId(), $this->overall_comments)) {
                return [$grader->getId() => $this->overall_comments[$grader->getId()]];
            }
            else {
                return [$grader->getId() => null];
            }
        }
    }


    /**
     * Deletes the GradedComponent(s) associated with the provided Component and grader
     * @param Component $component The component to delete the grade for
     * @param User|null $grader The grader to delete the grade for, or null to delete all grades
     */
    public function deleteGradedComponent(Component $component, User $grader = null) {
        $container = $this->getGradedComponentContainer($component);

        if ($grader === null || !$component->getGradeable()->isPeerGrading()) {
            // If the grader is null or we aren't peer grading, then delete all component grades for this component
            $this->deleted_graded_components = array_merge(
                $this->deleted_graded_components,
                $container->getGradedComponents()
            );

            // Clear the container for this component
            $container->setGradedComponents([]);
        }
        else {
            // Otherwise, only delete the component with the provided grader
            $deleted_component = $container->removeGradedComponent($grader);
            if ($deleted_component !== null) {
                $this->deleted_graded_components[] = $deleted_component;
            }
        }
    }

    /**
     * Gets the GradedComponents marked for deletion via deleteGradedComponent
     * @return GradedComponent[]
     */
    public function getDeletedGradedComponents() {
        return $this->deleted_graded_components;
    }

    /**
     * Sets the date that the user viewed their grade
     * @param string|\DateTime $user_viewed_date The date or date string of when the user viewed their grade
     * @throws \InvalidArgumentException if $grade_time is a string and failed to parse into a \DateTime object
     */
    public function setUserViewedDate($user_viewed_date) {
        if ($user_viewed_date === null) {
            $this->user_viewed_date = null;
        }
        else {
            try {
                $this->user_viewed_date = DateUtils::parseDateTime($user_viewed_date, $this->core->getConfig()->getTimezone());
            }
            catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid date string format');
            }
        }
        $this->modified = true;
    }

    /**
     * Resets the user_viewed_date to be as if the student never saw the grade
     */
    public function resetUserViewedDate() {
        $this->user_viewed_date = null;
        $this->modified = true;
    }

    /**
     * Gets all of the graders
     * @return User[] indexed by user id
     */
    public function getGraders() {
        $graders = [];
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $graders = array_merge($graders, $container->getGraders());
        }
        return $graders;
    }

    /**
     * Gets all user-visible graders for this component
     * @return User[] indexed by user id
     */
    public function getVisibleGraders() {
        $graders = [];
        /** @var GradedComponentContainer $container */
        foreach ($this->graded_component_containers as $container) {
            $graders = array_merge($graders, $container->getVisibleGraders());
        }
        return $graders;
    }

    /* Intentionally Unimplemented accessor methods */

    /** @internal */
    public function setId($id) {
        throw new \BadFunctionCallException('Cannot set id of gradeable data');
    }

    /** @internal */
    public function setGradedComponentContainers(array $graded_component_containers) {
        throw new \BadFunctionCallException('Cannot set graded component containers');
    }
}
