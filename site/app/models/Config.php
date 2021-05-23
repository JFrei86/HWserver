<?php

namespace app\models;

use app\controllers\admin\WrapperController;
use app\exceptions\ConfigException;
use app\libraries\Core;
use app\libraries\FileUtils;

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
 * @method string getVcsUrl()
 * @method string getCgiUrl()
 * @method string getSubmittyPath()
 * @method string getCgiTmpPath()
 * @method string getCoursePath()
 * @method string getDatabaseDriver()
 * @method array getSubmittyDatabaseParams()
 * @method array getCourseDatabaseParams()
 * @method string getCourseName()
 * @method string getCourseHomeUrl()
 * @method integer getDefaultHwLateDays()
 * @method integer getDefaultStudentLateDays()
 * @method string getConfigPath()
 * @method string getAuthentication()
 * @method array getLdapOptions()
 * @method \DateTimeZone getTimezone()
 * @method setTimezone(\DateTimeZone $timezone)
 * @method string getUploadMessage()
 * @method array getHiddenDetails()
 * @method string getCourseJsonPath()
 * @method void setCourseLoaded(bool $loaded)
 * @method bool isCourseLoaded()
 * @method string getInstitutionName()
 * @method string getInstitutionHomepage()
 * @method string getCourseCodeRequirements()
 * @method string getUsernameChangeText()
 * @method bool isForumEnabled()
 * @method string getForumCreateThreadMessage()
 * @method bool isRegradeEnabled()
 * @method bool isEmailEnabled()
 * @method string getRegradeMessage()
 * @method string getVcsBaseUrl()
 * @method string getSysAdminEmail()
 * @method string getSysAdminUrl()
 * @method string getCourseEmail()
 * @method string getVcsUser()
 * @method string getVcsType()
 * @method string getPrivateRepository()
 * @method string getRoomSeatingGradeableId()
 * @method void setRoomSeatingGradeableId(string $gradeable_id)
 * @method bool isSeatingOnlyForInstructor()
 * @method array getCourseJson()
 * @method string getSecretSession()
 * @method string getAutoRainbowGrades()
 * @method string|null getVerifiedSubmittyAdminUser()
 * @method bool isQueueEnabled()
 * @method bool getQueueContactInfo()
 * @method bool isSeekMessageEnabled()
 * @method bool isPollsEnabled()
 * @method void setSemester(string $semester)
 * @method void setCourse(string $course)
 * @method void setCoursePath(string $course_path)
 * @method void setSubmittyPath(string $submitty_path)
 * @method void setDebug(bool $debug)
 * @method string getQueueMessage()
 * @method string getSeekMessageInstructions()
 * @method string getQueueAnnouncementMessage()
 * @method string getSubmittyInstallPath()
 * @method bool isDuckBannerEnabled()
 * @method string getPhpUser()
 */

class Config extends AbstractModel {

    /**
     * Variable to set the system to debug mode, which allows, among other things
     * easier access to user switching and to always output full exceptions. Never
     * turn on if running server in production environment.
     * @prop
     * @var bool
     */
    protected $debug = false;

    /** @prop @var string contains the semester to use, generally from the $_REQUEST['semester'] global */
    protected $semester;
    /** @prop @var string contains the course to use, generally from the $_REQUEST['course'] global */
    protected $course;

    /** @prop @var string path on the filesystem that points to the course data directory */
    protected $config_path;
    /** @prop @var string path to the json file that contains all the course specific settings */
    protected $course_json_path;

    /** @prop @var array */
    protected $course_json = [];

    /**
     * Indicates whether a course config has been successfully loaded.
     * @var bool
     * @prop
     */
    protected $course_loaded = false;

    /*** MASTER CONFIG ***/
    /** @prop @var string */
    protected $base_url;
    /** @prop @var string */
    protected $vcs_url;
    /** @prop @var string */
    protected $cgi_url;
    /** @prop @var string */
    protected $authentication;
    /** @prop-read */
    protected $ldap_options = [];
    /** @prop @var DateTimeZone */
    protected $timezone;
    /** @var string */
    protected $default_timezone = 'America/New_York';
    /** @prop @var string */
    protected $submitty_path;
    /** @prop @var string */
    protected $course_path;
    /** @prop @var string */
    protected $submitty_log_path;
    /** @prop @var bool */
    protected $log_exceptions;
    /** @prop @var string */
    protected $cgi_tmp_path;

    /** @prop @var string */
    protected $database_driver = "pgsql";

    /**
     * The name of the institution that deployed Submitty. Added to the breadcrumb bar if non-empty.
     * @var string
     * @prop
     */
    protected $institution_name = "";

    /**
     * The url of the institution's homepage. Linked to from the breadcrumb created with institution_name.
     * @var string
     * @prop
     */
    protected $institution_homepage = "";

    /**
     * The text to be shown to a user when they attempt to change their username.
     * @var string
     * @prop
     */
    protected $username_change_text = "";

    /**
     * The text to be shown when an instructor enters a course code for a new course.
     * @var string
     * @prop
     */
    protected $course_code_requirements = "";

    /**
     * Email address of the System Administration
     * @var string
     * @prop
     */
    protected $sys_admin_email = "";

    /**
     * Webpage URL for a help page (not hosted by submitty).
     * @var string
     * @prop
     */
    protected $sys_admin_url = "";

    /** @prop @var string Text shown to all users for system announcement */
    protected $system_message = '';

    /** @prop @var array */
    protected $submitty_database_params = [];

    /** @prop @var array */
    protected $course_database_params = [];

    /** @prop @var array */
    protected $wrapper_files = [];

    /** @prop @var bool */
    protected $email_enabled;

    /** @prop @var string */
    protected $latest_tag;
    /** @prop @var string */
    protected $latest_commit;

    /** @prop @var string */
    protected $course_name;
    /** @prop @var string */
    protected $course_home_url;
    /** @prop @var int */
    protected $default_hw_late_days;
    /** @prop @var int */
    protected $default_student_late_days;
    /** @prop @var bool */
    protected $zero_rubric_grades;

    /** @prop @var string */
    protected $upload_message;
    /** @prop @var bool */
    protected $display_rainbow_grades_summary;
    /** @prop @var bool */
    protected $display_custom_message;
    /** @prop @var string*/
    protected $course_email;
    /** @prop @var string */
    protected $vcs_base_url;
    /** @prop @var string */
    protected $vcs_type;
    /** @prop @var string */
    protected $private_repository;
    /** @prop @var array */
    protected $hidden_details;
    /** @prop @var bool */
    protected $forum_enabled;
    /** @prop @var string */
    protected $forum_create_thread_message;
    /** @prop @var bool */
    protected $regrade_enabled;
    /** @prop @var string */
    protected $regrade_message;
    /** @prop @var bool */
    protected $seating_only_for_instructor;
    /** @prop @var string|null */
    protected $room_seating_gradeable_id;
    /** @prop @var bool */
    protected $auto_rainbow_grades;
    /** @prop @var string */
    protected $secret_session;
    /** @prop-read @var string|null */
    protected $verified_submitty_admin_user = null;
    /** @prop @var bool */
    protected $queue_enabled;
    /** @prop @var bool */
    protected $seek_message_enabled;
    /** @prop @var bool */
    protected $queue_contact_info;
    /** @prop @var string */
    protected $queue_message;
    /** @prop @var string */
    protected $seek_message_instructions;
    /** @prop @var string */
    protected $queue_announcement_message;
    /** @prop @var string */
    protected $submitty_install_path;
    /** @prop @var bool */
    protected $duck_banner_enabled;
    /** @prop @var bool */
    protected $polls_enabled;


    /** @prop-read @var array */
    protected $feature_flags = [];

    /** @prop @var DateTimeFormat */
    protected $date_time_format;

    /** @prop @var string */
    protected $php_user;

    /**
     * Config constructor.
     *
     * @param Core   $core
     */
    public function __construct(Core $core) {
        parent::__construct($core);
        $this->timezone = new \DateTimeZone($this->default_timezone);

        // For now this will be set to 'MDY', and configured as a property of the Config class
        // Eventually this should be moved to the User class and configured on a per-user basis
        $this->date_time_format = new DateTimeFormat($this->core, 'MDY');
    }

    public function loadMasterConfigs($config_path) {
        if (!is_dir($config_path)) {
            throw new ConfigException("Could not find config directory: " . $config_path, true);
        }
        $this->config_path = $config_path;
        // Load config details from the master config file
        $database_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'database.json'));

        if (!$database_json) {
            throw new ConfigException("Could not find database config: {$this->config_path}/database.json");
        }

        $this->submitty_database_params = [
            'dbname' => 'submitty',
            'host' => $database_json['database_host'],
            'port' => $database_json['database_port'],
            'username' => $database_json['database_user'],
            'password' => $database_json['database_password']
        ];

        if (isset($database_json['driver'])) {
            $this->database_driver = $database_json['driver'];
        }

        $this->debug = $database_json['debugging_enabled'] === true;

        $authentication_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'authentication.json'));
        if (!$authentication_json) {
            throw new ConfigException("Could not find authentication config: {$this->config_path}/authentication.json");
        }
        $this->authentication = $authentication_json['authentication_method'];
        $this->ldap_options = $authentication_json['ldap_options'];
        if ($this->authentication === 'LdapAuthentication') {
            foreach (['url', 'uid', 'bind_dn'] as $key) {
                if (!isset($this->ldap_options[$key])) {
                    throw new ConfigException("Missing config value for ldap options: {$key}");
                }
            }
        }

        $submitty_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'submitty.json'));
        if (!$submitty_json) {
            throw new ConfigException("Could not find submitty config: {$this->config_path}/submitty.json");
        }

        $this->log_exceptions = true;

        $this->base_url = $submitty_json['submission_url'];
        $this->duck_banner_enabled = $submitty_json['duck_special_effects'] === true;

        $this->sys_admin_email = $submitty_json['sys_admin_email'] ?? '';
        $this->sys_admin_url = $submitty_json['sys_admin_url'] ?? '';

        if (isset($submitty_json['timezone'])) {
            if (!in_array($submitty_json['timezone'], \DateTimeZone::listIdentifiers())) {
                throw new ConfigException("Invalid Timezone identifier: {$submitty_json['timezone']}");
            }
            $this->timezone = new \DateTimeZone($submitty_json['timezone']);
        }

        if (isset($submitty_json['institution_name'])) {
            $this->institution_name = $submitty_json['institution_name'];
        }

        if (isset($submitty_json['institution_homepage'])) {
            $this->institution_homepage = $submitty_json['institution_homepage'];
        }

        if (isset($submitty_json['username_change_text'])) {
            $this->username_change_text = $submitty_json['username_change_text'];
        }

        if (isset($submitty_json['course_code_requirements'])) {
            $this->course_code_requirements = $submitty_json['course_code_requirements'];
        }

        if (isset($submitty_json['system_message'])) {
            $this->system_message = strval($submitty_json['system_message']);
        }

        $this->base_url = rtrim($this->base_url, "/") . "/";

        if (!empty($submitty_json['cgi_url'])) {
            $this->cgi_url = rtrim($submitty_json['cgi_url'], "/") . "/";
        }
        else {
            $this->cgi_url = $this->base_url . "cgi-bin/";
        }

        if (empty($submitty_json['vcs_url'])) {
            $this->vcs_url = $this->base_url . '{$vcs_type}/';
        }
        else {
            $this->vcs_url = rtrim($submitty_json['vcs_url'], '/') . '/';
        }

        $this->submitty_path = $submitty_json['submitty_data_dir'];
        $this->submitty_log_path = $submitty_json['site_log_path'];
        $this->submitty_install_path = $submitty_json['submitty_install_dir'];

        $this->cgi_tmp_path = FileUtils::joinPaths($this->submitty_path, "tmp", "cgi");

        // Check that the paths from the config file are valid
        foreach (['submitty_path', 'submitty_log_path', 'submitty_install_path'] as $path) {
            if (!is_dir($this->$path)) {
                throw new ConfigException("Invalid path for setting {$path}: {$this->$path}");
            }
            $this->$path = rtrim($this->$path, "/");
        }

        foreach (['autograding', 'access', 'site_errors', 'ta_grading'] as $path) {
            if (!is_dir(FileUtils::joinPaths($this->submitty_log_path, $path))) {
                throw new ConfigException("Missing log folder: {$path}");
            }
        }

        $secrets_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'secrets_submitty_php.json'));
        if (!$secrets_json) {
            throw new ConfigException("Could not find secrets config: {$this->config_path}/secrets_submitty_php.json");
        }

        foreach (['session'] as $key) {
            $var = "secret_{$key}";
            $secrets_json[$key] = trim($secrets_json[$key]) ?? '';
            if (empty($secrets_json[$key])) {
                throw new ConfigException("Missing secret var: {$key}");
            }
            elseif (strlen($secrets_json[$key]) < 32) {
                // enforce a minimum 32 bytes for the secrets
                throw new ConfigException("Secret {$key} is too weak. It should be at least 32 bytes.");
            }
            $this->$var = $secrets_json[$key];
        }

        $email_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'email.json'));
        if (!$email_json) {
            throw new ConfigException("Could not find email config: {$this->config_path}/email.json");
        }
        $this->email_enabled = $email_json['email_enabled'];


        $version_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'version.json'));
        if (!$version_json) {
            throw new ConfigException("Could not find version file: {$this->config_path}/version.json");
        }
        if (
            !isset($version_json['most_recent_git_tag'])
            || !isset($version_json['short_installed_commit'])
        ) {
            throw new ConfigException("Error parsing version information: {$this->config_path}/version.json");
        }
        $this->latest_tag = $version_json['most_recent_git_tag'];
        $this->latest_commit = $version_json['short_installed_commit'];

        $users_json = FileUtils::readJsonFile(FileUtils::joinPaths($this->config_path, 'submitty_users.json'));
        if ($users_json !== false) {
            if (isset($users_json['verified_submitty_admin_user'])) {
                $this->verified_submitty_admin_user = $users_json['verified_submitty_admin_user'];
            }

            $this->php_user = $users_json['php_user'];
        }
    }

    public function loadCourseJson($semester, $course, $course_json_path) {
        $this->semester = $semester;
        $this->course = $course;
        $this->course_path = FileUtils::joinPaths($this->getSubmittyPath(), "courses", $semester, $course);

        if (!file_exists($course_json_path)) {
            throw new ConfigException("Could not find course config file: " . $course_json_path, true);
        }
        $this->course_json_path = $course_json_path;
        $this->course_json = json_decode(file_get_contents($course_json_path), true);
        if ($this->course_json === null) {
            throw new ConfigException("Error parsing the config file: " . json_last_error_msg());
        }

        if (!isset($this->course_json['database_details']) || !is_array($this->course_json['database_details'])) {
            throw new ConfigException("Missing config section 'database_details' in json file");
        }

        $this->course_database_params = array_merge($this->submitty_database_params, $this->course_json['database_details']);

        $array = [
            'course_name', 'course_home_url', 'default_hw_late_days', 'default_student_late_days',
            'zero_rubric_grades', 'upload_message', 'display_rainbow_grades_summary',
            'display_custom_message', 'room_seating_gradeable_id', 'course_email', 'vcs_base_url', 'vcs_type',
            'private_repository', 'forum_enabled', 'forum_create_thread_message', 'regrade_enabled', 'seating_only_for_instructor',
            'regrade_message', 'auto_rainbow_grades', 'queue_enabled', 'queue_contact_info', 'queue_message', 'polls_enabled', 'queue_announcement_message', 'seek_message_enabled', 'seek_message_instructions'
        ];
        $this->setConfigValues($this->course_json, 'course_details', $array);

        if (empty($this->vcs_base_url)) {
            $this->vcs_base_url = $this->vcs_url . $this->semester . '/' . $this->course;
        }

        $this->vcs_base_url = rtrim($this->vcs_base_url, "/") . "/";

        if (isset($this->course_json['hidden_details'])) {
            $this->hidden_details = $this->course_json['hidden_details'];
            if (isset($this->course_json['hidden_details']['course_url'])) {
                $this->base_url = rtrim($this->course_json['hidden_details']['course_url'], "/") . "/";
            }
        }

        foreach (['default_hw_late_days', 'default_student_late_days'] as $key) {
            $this->$key = intval($this->$key);
        }

        $array = [
            'zero_rubric_grades',
            'display_rainbow_grades_summary',
            'display_custom_message',
            'forum_enabled',
            'regrade_enabled',
            'seating_only_for_instructor',
            'queue_enabled',
            'queue_contact_info',
            'polls_enabled',
            'seek_message_enabled',
        ];
        foreach ($array as $key) {
            $this->$key = (bool) $this->$key;
        }

        if (!empty($this->course_json['feature_flags']) && is_array($this->course_json['feature_flags'])) {
            $this->feature_flags = $this->course_json['feature_flags'];
        }

        $wrapper_files_path = FileUtils::joinPaths($this->getCoursePath(), 'site');
        foreach (WrapperController::WRAPPER_FILES as $file) {
            $path = FileUtils::joinPaths($wrapper_files_path, $file);
            if (file_exists($path)) {
                $this->wrapper_files[$file] = $path;
            }
        }

        $this->course_loaded = true;
    }

    private function setConfigValues($config, $section, $keys) {
        if (!isset($config[$section]) || !is_array($config[$section])) {
            throw new ConfigException("Missing config section '{$section}' in json file");
        }

        foreach ($keys as $key) {
            if (!isset($config[$section][$key])) {
                throw new ConfigException("Missing config setting '{$section}.{$key}' in configuration json file");
            }
            $this->$key = $config[$section][$key];
        }
    }

    public function isSubmittyAdminUserVerified() {
        return !empty($this->verified_submitty_admin_user);
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
    public function displayRainbowGradesSummary() {
        return $this->display_rainbow_grades_summary;
    }

    /**
     * @return bool
     */
    public function displayRoomSeating() {
        return $this->room_seating_gradeable_id !== "";
    }


    public function getLogPath() {
        return $this->submitty_log_path;
    }

    public function saveCourseJson($save): bool {
        return FileUtils::writeJsonFile($this->course_json_path, array_merge($this->course_json, $save));
    }

    public function wrapperEnabled() {
        return $this->course_loaded
            && (count($this->wrapper_files) > 0);
    }

    public function getWrapperFiles() {
        //Return empty if not logged in because we can't access them
        return ($this->core->getUser() === null ? [] : $this->wrapper_files);
    }

    /**
     * Checks to see if a given feature flag is enabled or not. If the site
     * is running under debug, we assume all flags are enabled, else, the
     * flag must exist in the course config.json file and be set to true.
     */
    public function checkFeatureFlagEnabled(string $flag): bool {
        return $this->debug
            || (
                isset($this->feature_flags[$flag])
                && $this->feature_flags[$flag] === true
            );
    }
}
