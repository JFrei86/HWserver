<?php

namespace app\models;

use app\libraries\Core;

/**
 * Class SimpleStat

 * @method bool getComponent()
 * @method string getTitle()
 * @method float getMaxValue()
 * @method int getOrder()
 * @method float getAverageScore()
 * @method float getStandardDeviation()
 * @method int getCount()
 * @method int getActiveGradeInquiryCount()
 * @method bool getIsPeerComponent()
 * @method string[] getGraderInfo()

 */
class SimpleStat extends AbstractModel {
    /** @prop @var bool is this a component */
    protected $component = true;
    /** @prop @var string Title of gradeable or component */
    protected $title = "";
    /** @prop @var float Maximum value of gradeable or component*/
    protected $max_value = 0;
    /** @prop @var int Order for components to be shown in */
    protected $order = -1;
    /** @prop @var float Average grade */
    protected $average_score = 0;
    /** @prop @var float Standard deviation*/
    protected $standard_deviation = 0;
    /** @prop @var int number of people graded(completely graded)*/
    protected $count = 0;
    /** @prop @var number of active grade inquiries for given grading component*/
    protected $active_grade_inquiry_count = 0;
    /** @prop @var bool Does this component use peer grading*/
    protected $is_peer_component = null;
    /** @prop @var array Grader information for these stats*/
    protected $grader_info = null;

    public function __construct(Core $core, $details = []) {
        parent::__construct($core);
        if (isset($details['gc_id'])) {
            $this->component = true;
            $this->title = $details['gc_title'];
            $this->max_value = $details['gc_max_value'];
            $this->average_score = $details['avg_comp_score'];
            $this->standard_deviation = $details['std_dev'];
            $this->order = $details['gc_order'];
            $this->is_peer_component = $details['gc_is_peer'];
            $this->count = $details['count'];
            $this->active_grade_inquiry_count = $details['active_grade_inquiry_count'];
            $this->grader_info = $this->core->getQueries()->getAverageGraderScores($details['g_id'], $details['gc_id'], $details['section_key'], $details['team']);
        }
        else {
            $this->component = false;
            $this->max_value = $details['max'];
            $this->average_score = $details['avg_score'];
            $this->standard_deviation = $details['std_dev'];
            $this->count = $details['count'];
        }
    }
}
