<?php

namespace app\models;

use app\libraries\database\IDatabaseQueries;

/**
 * Class User
 */
class User extends Model {
    /**
     * @var array
     */
    private $details = array();

    /**
     * User constructor.
     *
     * @param string           $user_id
     * @param IDatabaseQueries $database
     */
    public function __construct($user_id, $database) {
        $details = $database->getUserById($user_id);
        if (count($details) == 0) {
            return false;
        }

        $this->details = $details;

        return true;
    }

    public function getDetail($detail) {
        return $this->details[$detail];
    }

    public function accessGrading() {
        return $this->details['user_group'] > 1;
    }

    public function accessAdmin() {
        return $this->details['user_group'] >= 4;
    }

    public function isDeveloper() {
        return $this->details['user_group'] == 5;
    }

    public function getUserId() {
        return $this->details['user_id'];
    }
}