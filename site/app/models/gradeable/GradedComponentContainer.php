<?php

namespace app\models\gradeable;

use app\libraries\Core;
use app\libraries\Utils;
use app\models\AbstractModel;
use app\models\User;
use app\libraries\NumberUtils;

/**
 * Class GradedComponentContainer
 * @package app\models\gradeable
 *
 * A convenience layer for managing and calculating the aggregate
 *  properties for grades for a Component
 *
 * @method GradedComponent[] getGradedComponents()
 */
class GradedComponentContainer extends AbstractModel {

    /** @var Component The Component all grades are for */
    private $component = null;
    /** @var TaGradedGradeable The TaGradedGradeable all grades belong to */
    private $ta_graded_gradeable = null;

    /** @prop @var GradedComponent[] The graded components for this Component */
    protected $graded_components = [];

    /**
     * GradedComponentContainer constructor.
     * @param Core $core
     * @param TaGradedGradeable $ta_graded_gradeable
     * @param Component $component
     */
    public function __construct(Core $core, TaGradedGradeable $ta_graded_gradeable, Component $component) {
        parent::__construct($core);

        $this->component = $component;
        $this->ta_graded_gradeable = $ta_graded_gradeable;
    }

    public function toArray() {
        // This class only exists as a convenience layer, so pretend that we are the array.
        return parent::parseObject($this->graded_components);
    }

    /**
     * Gets the Component this container holds grades for
     * @return Component
     */
    public function getComponent() {
        return $this->component;
    }

    /**
     * Gets the TaGradedGradeable these grades belong to
     * @return TaGradedGradeable
     */
    public function getTaGradedGradeable() {
        return $this->ta_graded_gradeable;
    }

    /**
     * Used to retrieve existing graded components or generate new ones
     * This function has fairly complex behavior to achieve a large amount of convenience.
     * In general: If the component is peer or generate is true, don't pass a null grader
     *
     * Grader Null:
     *   Component not peer:
     *     Component has grades:            => return the one TA grade
     *     Generate false:                  => return null
     *     Generate true:                   => throw InvalidArgumentException
     *   Component peer:                    => throw InvalidArgumentException
     * Grader Not Null:
     *   Component not peer:
     *     Component has grades:            => return the one
     *     Generate false:                  => return null
     *     Generate true:                   => return new component with provided user as grader (TA)
     *   Component peer:
     *     Component has grades for grader  => return that graded component
     *     Generate false:                  => return null
     *     Generate true:                   => return new component with provided user as grader (peer)
     *
     * @param User|null $grader The grader for this component
     * @param bool $generate If a new graded component should be generated if none were found
     * @return GradedComponent|null The graded component instance or null if not found
     * @throws \InvalidArgumentException If $grader is null and ($component is peer or $generate is true)
     */
    public function getOrCreateGradedComponent(User $grader = null, $generate = false) {
        $grades_exist = $this->anyGradedComponents();
        if ($grader === null) {
            // If the grader is null and its a peer component, we can't do anything useful
            if ($this->component->isPeerComponent()) {
                throw new \InvalidArgumentException('Cannot get peer graded component with null grader');
            }

            // Grades exist, not a peer component, so grab the first grade
            if ($grades_exist) {
                return $this->graded_components[0];
            }

            // If no grader is provided we can't generate a graded component
            if ($generate) {
                throw new \InvalidArgumentException('Cannot generate graded component with null grader');
            }

            // No grades exist, not trying to generate, not peer, no grader, so can't do anything
            return null;
        }

        //
        // Grader not null
        //

        if ($this->component->isPeerComponent()) {
            // Try to find existing graded component for this component and user...
            foreach ($this->graded_components as $graded_component) {
                if ($graded_component->getGrader()->getId() === $grader->getId()) {
                    // ... Found one
                    return $graded_component;
                }
            }

            // None found, but generate one (append to array)
            if ($generate) {
                return $this->graded_components[] =
                    new GradedComponent($this->core, $this->ta_graded_gradeable, $this->component, $grader, []);
            }

            // None found. Don't generate one
            return null;
        }

        //
        // Not peer component
        //

        // Grades exist for component, so get the only one
        if ($grades_exist) {
            return $this->graded_components[0];
        }

        // Grades don't exist, but generate one (at zero index of array)
        if ($generate) {
            return $this->graded_components[0] =
                new GradedComponent($this->core, $this->ta_graded_gradeable, $this->component, $grader, []);
        }

        // Grades don't exist.  Don't generate one
        return null;
    }

    /**
     * Gets the GradedComponent for a given grader
     * @param User|null $grader The grader for this component
     * @return GradedComponent|null
     */
    public function getGradedComponent(User $grader = null) {
        return $this->getOrCreateGradedComponent($grader, false);
    }

    /**
     * Gets if any grades exist for this component
     * @return bool
     */
    public function anyGradedComponents() {
        return count($this->graded_components) > 0;
    }

    /**
     * Sets the graded components for this container
     * @param GradedComponent[] $graded_components
     */
    public function setGradedComponents(array $graded_components) {
        foreach ($graded_components as $graded_component) {
            if (!($graded_component instanceof GradedComponent)) {
                throw new \InvalidArgumentException('Array must only contain graded components');
            }
            if ($graded_component->getComponentId() !== $this->component->getId()) {
                throw new \InvalidArgumentException('Graded components must have same component id');
            }
        }
        $this->graded_components = array_values($graded_components);
    }

    /**
     * Removes the graded component for a particular grader
     * @param User $user
     * @return GradedComponent|null The graded component removed or null of none removed
     */
    public function removeGradedComponent(User $user) {
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $i => $graded_component) {
            if ($graded_component->getGrader()->getId() === $user->getId()) {
                unset($this->graded_components[$i]);
                return $graded_component;
            }
        }
        return null;
    }

    /* Methods to abstract peer grading from point/mark calculation */

    /**
     * Gets the score the submitter received for this component, rounded
     *  to the precision of the gradeable
     * @return float
     */
    public function getTotalScore(User $grader = null): float {
        $points_earned = 0.0;
        $number_of_graders = 0;
        // TODO: how should peer grades be calculated: now its an average
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $graded_component) {
            // If there is a grader, we are only computing their total score rather than the total score for all peers.
            if ($grader !== null && $graded_component->getGrader()->getId() !== $grader->getId()) {
                continue;
            }
            $points_earned += $graded_component->getTotalScore();
            $number_of_graders += 1;
        }

        // Note: this is called 'safeCalcPercent', but it does not clamp the output to 1.0
        // Note: clamp count(...) to be at least 1 so safeCalcPercent doesn't return NaN
        $points_earned = Utils::safeCalcPercent($points_earned, max(1, $number_of_graders));
        return NumberUtils::roundPointValue($points_earned, $this->ta_graded_gradeable->getGradedGradeable()->getGradeable()->getPrecision());
    }

    /**
     * Gets the score the submitter received for this peer component, rounded
     *  to the precision of the gradeable
     * @return float
     */
    public function getTotalPeerScore(User $grader = null): float {
        $points_earned = 0.0;
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $graded_component) {
            // If there is a grader, we are only computing their total score rather than the total score for all graders
            if ($grader !== null && $graded_component->getGrader()->getId() !== $grader->getId()) {
                continue;
            }
            if ($graded_component->getGrader()->getGroup() < User::GROUP_STUDENT) {
                if ($graded_component->isPeer()) {
                    $points_earned += $graded_component->getTotalScore();
                }
            }
        }

        return $points_earned;
    }

    /**
     * Gets the ta score the submitter received for this component, rounded
     *  to the precision of the gradeable
     * @return float
     */
    public function getTotalTaScore(User $grader = null): float {
        $points_earned = 0.0;
        $number_of_graders = 0;
        // TODO: how should peer grades be calculated: now its an average
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $graded_component) {
            // If there is a grader, we are only computing their total score rather than the total score for all peers.
            if ($graded_component->getGrader()->getGroup() < User::GROUP_STUDENT) {
                if ($graded_component->isPeer()) {
                }
                else {
                    $points_earned += $graded_component->getTotalScore();
                }
            }
            else {
                $points_earned = 0;
                $number_of_graders = 0;
            }
        }

        return $points_earned;
    }

    /**
     * Gets whether this component is considered fully graded
     * In the peer case, components are considered fully graded if they
     *  meet the peer grade set or one of the graders is non-peer
     * @return bool
     */
    public function isComplete() {
        if ($this->component->isPeerComponent()) {
            if (count($this->graded_components) > 0) {
                return true;
            }
            else {
                return false;
            }
        }

        $required_graders = $this->component->getGradingSet();
        $graders = count($this->graded_components);
        if ($graders === $required_graders) {
            return true;
        }
        else {
            /** @var GradedComponent $graded_component */
            foreach ($this->graded_components as $graded_component) {
                // TODO: should this be full access?
                if ($graded_component->getGrader()->accessGrading()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Gets the percent graded this component is (only relevant for peer grading)
     * @return float percentage (0 to 1) not clamped to 100%
     */
    public function getPercentGraded() {
        $required_graders = $this->component->getGradingSet();
        $graders = count($this->graded_components);
        return Utils::safeCalcPercent($graders, $required_graders, false);
    }

    /**
     * Gets the number of graders who assigned the provided mark to the submitter
     *  Note: This will always be 1 or 0 for non-peer-graded assignments
     * @param Mark $mark
     * @return int
     */
    public function getMarkMultiplicity(Mark $mark, User $grader = null) {
        $count = 0;
        foreach ($this->graded_components as $graded_component) {
            if ($graded_component->hasMark($mark)) {
                if ($grader === null) {
                    $count++;
                }
                else {
                    if ($graded_component->getGrader()->getId() === $grader->getId()) {
                        return 1;
                    }
                }
            }
        }
        return $count;
    }

    /**
     * Gets if the submitter received the mark at least once
     * @param Mark $mark
     * @return bool
     */
    public function hasMark(Mark $mark, User $grader = null) {
        return $this->getMarkMultiplicity($mark, $grader) > 0;
    }

    /**
     * Gets the submission version these component grades are for,
     *  or false if they are inconsistent / ungraded
     * @return int|bool
     */
    public function getGradedVersion() {
        $version = false;
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $graded_component) {
            $v = $graded_component->getGradedVersion();
            if ($version === false) {
                $version = $v;
                continue;
            }
            if ($v !== $version) {
                return false;
            }
        }
        return $version;
    }

    /**
     * Gets all of the graders for this component
     * @return User[] indexed by user id
     */
    public function getGraders() {
        $graders = [];
        /** @var GradedComponent $graded_component */
        foreach ($this->graded_components as $graded_component) {
            $grader = $graded_component->getGrader();
            $graders[$grader->getId()] = $grader;
        }
        return $graders;
    }

    /**
     * Gets all user-visible graders for this component
     * If a verifier exists for a limited access grader, gets that instead
     * @return User[] indexed by user id
     */
    public function getVisibleGraders() {
        $visible_graders = [];
        foreach ($this->graded_components as $graded_component) {
            $grader = $graded_component->getGrader();
            $verifier_id = $graded_component->getVerifierId();
            if ($grader->accessFullGrading()) {
                $visible_graders[$grader->getId()] = $grader;
            }
            elseif ($verifier_id != '') {
                $visible_graders[$verifier_id] = $graded_component->getVerifier();
            }
        }
        return $visible_graders;
    }

    /**
     * Gets the score for this component (custom mark for electronic gradeables)
     * Note: check that `isComplete` is true before calling this
     * @return float
     */
    public function getScore() {
        return $this->component->isPeerComponent() ? 0.0 : $this->getGradedComponent()->getScore();
    }

    /**
     * Gets the comment for this component (custom mark comment for electronic gradeables)
     * Note: check that `isComplete` is true before calling this
     * @return string
     */
    public function getComment() {
        return $this->component->isPeerComponent() ? '' : $this->getGradedComponent()->getComment();
    }
}
