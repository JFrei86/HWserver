<?php

namespace app\models;

use app\exceptions\ConfigException;
use app\libraries\Core;
use app\libraries\FileUtils;
use app\libraries\IniParser;
use app\libraries\Utils;

/**
 * Class Config
 *
 * This class handles and contains all of the variables necessary for running
 * the application. These variables are loaded from a combination of files and tables from
 * the database. We also allow for using this to write back to the variables within the database
 * (but not the variables in the files).
 *
 * @method string getSemester()
 * @method string getCourse()
 * @method string getBaseUrl()
 * @method string getTaBaseUrl()
 * @method string getCgiUrl()
 * @method string getSiteUrl()
 * @method string getSubmittyPath()
 * @method string getCoursePath()
 * @method string getDatabaseType()
 * @method string getDatabaseHost()
 * @method string getDatabaseUser()
 * @method string getDatabasePassword()
 * @method string getDatabaseName()
 * @method string getCourseName()
 * @method string getCourseHomeUrl()
 * @method integer getDefaultHwLateDays()
 * @method integer getDefaultStudentLateDays()
 * @method string getConfigPath()
 * @method string getAuthentication()
 * @method \DateTimeZone getTimezone()
 * @method string getUploadMessage()
 * @method array getHiddenDetails()
 * @method string getCourseIniPath()
 * @method bool isCourseLoaded()
 * @method bool getInstitutionName()
 * @method bool getInstitutionHomepage()
 * @method bool getPasswordChangeText()
 */

class Config extends AbstractModel {

    /**
     * Variable to set the system to debug mode, which allows, among other things
     * easier access to user switching and to always output full exceptions. Never
     * turn on if running server in production environment.
     * @property
     * @var bool
     */
    protected $debug = false;

    /** @property @var string contains the semester to use, generally from the $_REQUEST['semester'] global */
    protected $semester;
    /** @property @var string contains the course to use, generally from the $_REQUEST['course'] global */
    protected $course;

    /** @property @var string path on the filesystem that points to the course data directory */
    protected $config_path;
    /** @property @var string path to the ini file that contains all the course specific settings */
    protected $course_ini_path;
    /** @property @var array */
    protected $course_ini;

    /**
    * Indicates whether a course config has been successfully loaded.
    * @var bool
    * @property
    */
    protected $course_loaded = false;

    /*** MASTER CONFIG ***/
    /** @property @var string */
    protected $base_url;
    /** @property @var string */
    protected $ta_base_url;
    /** @property @var string */
    protected $cgi_url;
    /** @property @var string */
    protected $site_url;
    /** @property @var string */
    protected $authentication;
    /** @property @var string */
    protected $timezone = "America/New_York";
    /** @property @var string */
    protected $submitty_path;
    /** @property @var string */
    protected $course_path;
    /** @property @var string */
    protected $submitty_log_path;
    /** @property @var bool */
    protected $log_exceptions;

    /**
     * Database host for PDO. The user does not need to set this
     * explicitly in the config files, in which case we'll just default
     * to PostgreSQL.
     * @var string
     * @property
     */
    protected $database_type = "pgsql";

    /**
     * The name of the institution that deployed Submitty. Added to the breadcrumb bar if non-empty.
     * @var string
     * @property
     */
    protected $institution_name = "";

    /**
     * The url of the institution's homepage. Linked to from the breadcrumb created with institution_name.
     * @var string
     * @property
     */
    protected $institution_homepage = "";

    /**
     * The text to be shown to a user when they attempt to change their username.
     * @var string
     * @property
     */
    protected $password_change_text = "";



    /**
     * Database host for PDO
     * @var string
     * @property
     */
    protected $database_host;

    /**
     * Database user for PDO
     * @var string
     * @property
     */
    protected $database_user;

    /**
     * Database password for PDO
     * @var string
     * @property
     */
    protected $database_password;

    /*** COURSE SPECIFIC CONFIG ***/
    /**
     * Database name for PDO
     * @var string
     * @property
     */
    protected $database_name;

    /*** COURSE DATABASE CONFIG ***/
    /** @property @var string */
    protected $course_name;
    /** @property @var string */
    protected $course_home_url;
    /** @property @var int */
    protected $default_hw_late_days;
    /** @property @var int */
    protected $default_student_late_days;
    /** @property @var bool */
    protected $zero_rubric_grades;

    /** @property @var string */
    protected $upload_message;
    /** @property @var bool */
    protected $keep_previous_files;
    /** @property @var bool */
    protected $display_iris_grades_summary;
    /** @property @var bool */
    protected $display_custom_message;
    /** @property @var string*/
    protected $course_email;
    /** @property @var string */
    protected $vcs_base_url;
    /** @property @var string */
    protected $vcs_type;
    /** @property @var array */
    protected $hidden_details;

    /**
     * Config constructor.
     *
     * @param Core   $core
     */
    public function __construct(Core $core, $semester, $course) {
        parent::__construct($core);
        $this->semester = $semester;
        $this->course = $course;
    }

    public function loadMasterIni($master_ini_path) {
        if (!file_exists($master_ini_path)) {
            throw new ConfigException("Could not find master ini file: ". $master_ini_path, true);
        }
        $this->config_path = dirname($master_ini_path);
        // Load config details from the master config file
        $master = IniParser::readFile($master_ini_path);

        $this->setConfigValues($master, 'logging_details', array('submitty_log_path', 'log_exceptions'));
        $this->setConfigValues($master, 'site_details', array('base_url', 'cgi_url', 'ta_base_url', 'submitty_path', 'authentication'));
        $this->setConfigValues($master, 'database_details', array('database_host', 'database_user', 'database_password'));
        if (isset($master['site_details']['debug'])) {
           $this->debug = $master['site_details']['debug'] === true;
        }

        if (isset($master['site_details']['timezone'])) {
            $this->timezone = $master['site_details']['timezone'];
            if (!in_array($this->timezone, \DateTimeZone::listIdentifiers())) {
                throw new ConfigException("Invalid Timezone identifier: {$this->timezone}");
            }
        }

        if (isset($master['site_details']['institution_name'])) {
            $this->institution_name = $master['site_details']['institution_name'];
        }

        if (isset($master['site_details']['institution_url'])) {
            $this->institution_homepage = $master['site_details']['institution_url'];
        }

        if (isset($master['site_details']['username_change_text'])) {
            $this->username_change_text = $master['site_details']['username_change_text'];
        }

        $this->timezone = new \DateTimeZone($this->timezone);

        if (isset($master['database_details']['database_type'])) {
            $this->database_type = $master['database_details']['database_type'];
        }
        $this->base_url = rtrim($this->base_url, "/")."/";
        $this->cgi_url = rtrim($this->cgi_url, "/")."/";
        $this->ta_base_url = rtrim($this->ta_base_url, "/")."/";

        // Check that the paths from the config file are valid
        foreach(array('submitty_path', 'submitty_log_path') as $path) {
            if (!is_dir($this->$path)) {
                throw new ConfigException("Invalid path for setting {$path}: {$this->$path}");
            }
            $this->$path = rtrim($this->$path, "/");
        }

        foreach(array('site_errors', 'access') as $path) {
            if (!is_dir(FileUtils::joinPaths($this->submitty_log_path, $path))) {
                throw new ConfigException("Missing log folder: {$path}");
            }
        }
        $this->site_url = $this->base_url."index.php?";

        if (!empty($this->semester) && !empty($this->course)) {
            $this->course_path = FileUtils::joinPaths($this->submitty_path, "courses", $this->semester, $this->course);
        }
    }

    public function loadCourseIni($course_ini) {
        if (!file_exists($course_ini)) {
            throw new ConfigException("Could not find course config file: ".$course_ini, true);
        }
        $this->course_ini_path = $course_ini;
        $this->course_ini = IniParser::readFile($this->course_ini_path);

        $this->setConfigValues($this->course_ini, 'hidden_details', array('database_name'));
        $array = array('course_name', 'course_home_url', 'default_hw_late_days', 'default_student_late_days',
            'zero_rubric_grades', 'upload_message', 'keep_previous_files', 'display_iris_grades_summary',
            'display_custom_message', 'course_email', 'vcs_base_url', 'vcs_type');
        $this->setConfigValues($this->course_ini, 'course_details', $array);

        $this->hidden_details = $this->course_ini['hidden_details'];
        if (isset($this->course_ini['hidden_details']['course_url'])) {
            $this->base_url = rtrim($this->course_ini['hidden_details']['course_url'], "/")."/";
        }

        if (isset($this->course_ini['hidden_details']['ta_base_url'])) {
            $this->ta_base_url = rtrim($this->course_ini['hidden_details']['ta_base_url'], "/")."/";
        }
        
        $this->upload_message = Utils::prepareHtmlString($this->upload_message);
        
        foreach (array('default_hw_late_days', 'default_student_late_days') as $key) {
            $this->$key = intval($this->$key);
        }

        $array = array('zero_rubric_grades', 'keep_previous_files', 'display_iris_grades_summary',
            'display_custom_message');
        foreach ($array as $key) {
            $this->$key = ($this->$key == true) ? true : false;
        }
    
        $this->site_url = $this->base_url."index.php?semester=".$this->semester."&course=".$this->course;
        $this->course_loaded = true;
   }

    private function setConfigValues($config, $section, $keys) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            throw new ConfigException("Missing config section {$section} in ini file");
        }

        foreach ($keys as $key) {
            if (!isset($config[$section][$key])) {
                throw new ConfigException("Missing config setting {$section}.{$key} in configuration ini file");
            }
            $this->$key = $config[$section][$key];
        }
    }

    public function getHomepageUrl()
    {
        return $this->base_url."index.php?";
    }

    /**
     * @return boolean
     */
    public function isDebug() {
        return $this->debug;
    }

    /**
     * @return bool
     */
    public function shouldLogExceptions() {
        return $this->log_exceptions;
    }

    /**
     * @return bool
     */
    public function shouldZeroRubricGrades() {
        return $this->zero_rubric_grades;
    }

    /**
     * @return bool
     */
    public function displayCustomMessage() {
        return $this->display_custom_message;
    }

    /**
     * @return bool
     */
    public function keepPreviousFiles() {
        return $this->keep_previous_files;
    }

    /**
     * @return bool
     */
    public function displayIrisGradesSummary() {
        return $this->display_iris_grades_summary;
    }

    public function getLogPath() {
        return $this->submitty_log_path;
    }

    public function saveCourseIni($save) {
        IniParser::writeFile($this->course_ini_path, array_merge($this->course_ini, $save));
    }
}
