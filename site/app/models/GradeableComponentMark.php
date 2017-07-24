<?php

namespace app\models;
use app\libraries\Core;

/**
 * Class GradeableComponentMark
 *
 */

class GradeableComponentMark extends AbstractModel {
	/** @property @var int Unique identifier for the mark */
    protected $id = null;
    /** @property @var int Unique identifier for the component associated with this mark */
    protected $gc_id = null;
    /** @property @var float Maximum value that the component can have */
    protected $max_value = 0;
    /** @property @var int Order for marks to be shown in */
    protected $order = 1;
    /** @property @var float Points for this mark */
    protected $points = 0;
    /** @property @var string Comment for this mark */
    protected $note = "";
    /** @property @var bool This person earned this mark*/
    protected $has_mark = false;

    /** @property @var bool If the mark is diffirent from the one in the database*/
    protected $has_mark_modifed = false;

    public function __construct(Core $core, $details=array()) {
        parent::__construct($core);
        if (!isset($details['gcm_id'])) {
            return;
        }
        $this->id = $details['gcm_id'];
        $this->gc_id = $details['gc_id'];
        $this->points = $details['gcm_points'];
        $this->order = $details['gcm_order'];
        $this->note = $details['gcm_note'];
        if (isset($details['gcm_has_mark']) && $details['gcm_has_mark'] !== null) {
            $has_mark = true;
        }
    }

    public function save() {
        if($this->id === null) {
            $this->core->getQueries()->createGradeableComponentMark($this);
        } else {
            $this->core->getQueries()->updateGradeableComponentMark($this);
        }
    }

    public function saveData($gd_id, $gc_id) {
        if ($this->has_mark_modifed) {
        	if ($this->has_mark) {
                $this->core->getQueries()->insertGradeableComponentMarkData($gd_id, $gc_id, $this);
        	} else {
        		$this->core->getQueries()->deleteGradeableComponentMarkData($gd_id, $gc_id, $this);
        	}
        }
    }

    public function setHasMark($new_has_mark) {
        $this->has_mark_modifed = ($new_has_mark === $this->has_mark) ? false : true;
        $this->has_mark = filter_var($new_has_mark, FILTER_VALIDATE_BOOLEAN);
    }
}