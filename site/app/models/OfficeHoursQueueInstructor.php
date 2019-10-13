<?php

namespace app\models;

use app\libraries\Core;
use app\libraries\DateUtils;
use app\models\OfficeHoursQueueStudent;


class OfficeHoursQueueInstructor extends AbstractModel {

    private $entries = array();
    private $entries_helped = array();
    /**
     * Notifications constructor.
     *
     * @param Core  $core
     * @param array $details
     */
    public function __construct(Core $core, array $entries, array $entries_helped) {
        parent::__construct($core);
        $this->entries = $entries;
        $this->entries_helped = $entries_helped;
    }

    public function getEntries(){
      return $this->entries;
    }

    public function getEntriesHelped(){
      return $this->entries_helped;
    }
}
