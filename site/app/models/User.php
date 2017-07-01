<?php

namespace app\models;

use app\libraries\Core;
use app\libraries\DatabaseUtils;

/**
 * Class User
 *
 * @method string getId()
 * @method void setId(string $id) Get the id of the loaded user
 * @method string getPassword()
 * @method string getFirstName() Get the first name of the loaded user
 * @method string getPreferredFirstName() Get the preferred name of the loaded user
 * @method string getDisplayedFirstName() Returns the preferred name if one exists and is not null or blank,
 *                                        otherwise return the first name field for the user.
 * @method string getLastName() Get the last name of the loaded user
 * @method void setLastName(string $last_name)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method int getGroup()
 * @method void setGroup(integer $group)
 * @method int getRegistrationSection()
 * @method int getRotatingSection()
 * @method void setManualRegistration(bool $flag)
 * @method bool isManualRegistration()
 * @method array getGradingRegistrationSections()
 */
class User extends AbstractModel {
    
    /** @property @var bool Is this user actually loaded (else you cannot access the other member variables) */
    protected $loaded = false;
    
    /** @property @var string The id of this user which should be a unique identifier (ex: RCS ID at RPI) */
    protected $id;
    /**
     * @property
     * @var string The password for the student used for database authentication. This should be hashed and salted.
     * @link http://php.net/manual/en/function.password-hash.php
     */
    protected $password = null;
    /** @property @var string The first name of the user */
    protected $first_name;
    /** @property @var string The first name of the user */
    protected $preferred_first_name = "";
    /** @property @var  string The name to be displayed by the system (either preferred name or first name) */
    protected $displayed_first_name;
    /** @property @var string The last name of the user */
    protected $last_name;
    /** @property @var string The email of the user */
    protected $email;
    /** @property @var int The group of the user, used for access controls (ex: student, instructor, etc.) */
    protected $group;
    
    /** @property @var int What is the registration section that the user was assigned to for the course */
    protected $registration_section = null;
    /** @property @var int What is the assigned rotating section for the user */
    protected $rotating_section = null;
    
    /**
     * @property
     * @var bool Was the user imported via a normal class list or was added manually. This is useful for students
     *           that are doing independent studies in the course, so not actually registered and so wouldn't want
     *           to be shifted to a null registration section or rotating section like a dropped student
     */
    protected $manual_registration = false;

    /** @property @var array */
    protected $grading_registration_sections = array();

    /**
     * User constructor.
     *
     * @param Core  $core
     * @param array $details
     */
    public function __construct(Core $core, $details) {
        parent::__construct($core);
        if (count($details) == 0) {
            return;
        }

        $this->loaded = true;
        $this->setId($details['user_id']);
        if (isset($details['user_password'])) {
            $this->setPassword($details['user_password']);
        }
        $this->setFirstName($details['user_firstname']);
        if (isset($details['user_preferred_firstname'])) {
            $this->setPreferredFirstName($details['user_preferred_firstname']);
        }

        $this->last_name = $details['user_lastname'];
        $this->email = $details['user_email'];
        $this->group = isset($details['user_group']) ? intval($details['user_group']) : 4;
        if ($this->group > 4 || $this->group < 0) {
            $this->group = 4;
        }

        $this->registration_section = isset($details['registration_section']) ? intval($details['registration_section']) : null;
        $this->rotating_section = isset($details['rotating_section']) ? intval($details['rotating_section']) : null;
        $this->manual_registration = isset($details['manual_registration']) && $details['manual_registration'] === true;
        if (isset($details['grading_registration_sections'])) {
            $this->setGradingRegistrationSections(DatabaseUtils::fromPGToPHPArray($details['grading_registration_sections']));
        }
    }
    
    /**
     * Gets whether the user was actually loaded from the DB with the given user id
     * @return bool
     */
    public function isLoaded() {
        return $this->loaded;
    }
    
    /**
     * Gets whether the user is allowed to access the grading interface
     * @return bool
     */
    public function accessGrading() {
        return $this->group < 4;
    }
    
    /**
     * Gets whether the user is allowed to access the full grading interface
     * @return bool
     */
    public function accessFullGrading() {
        return $this->group < 3;
    }
    
    /**
     * Gets whether the user is allowed to access the administrative interface
     * @return bool
     */
    public function accessAdmin() {
        return $this->group <= 1;
    }
    
    /**
     * Gets whether the user is considered a developer (and thus should have access to debug information)
     * @return int
     */
    public function isDeveloper() {
        return $this->group === 0;
    }

    public function setPassword($password) {
        $info = password_get_info($password);
        if ($info['algo'] === 0) {
            $this->password = password_hash($password, PASSWORD_DEFAULT);
        }
        else {
            $this->password = $password;
        }
    }

    public function setFirstName($name) {
        $this->first_name = $name;
        $this->setDisplayedFirstName();
    }

    public function setPreferredFirstName($name) {
        $this->preferred_first_name = $name;
        $this->setDisplayedFirstName();
    }

    private function setDisplayedFirstName() {
        if ($this->preferred_first_name !== "" && $this->preferred_first_name !== null) {
            $this->displayed_first_name = $this->preferred_first_name;
        }
        else {
            $this->displayed_first_name = $this->first_name;
        }
    }

    public function setRegistrationSection($section) {
        $this->registration_section = ($section !== null) ? intval($section) : null;
    }

    public function setRotatingSection($section) {
        $this->rotating_section = ($section !== null) ? intval($section) : null;
    }

    public function setGradingRegistrationSections($sections) {
        if ($this->getGroup() < 4) {
            $this->grading_registration_sections = $sections;
        }
    }
}
