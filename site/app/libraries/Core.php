<?php

namespace app\libraries;

use app\authentication\AbstractAuthentication;
use app\exceptions\AuthenticationException;
use app\exceptions\DatabaseException;
use app\libraries\database\DatabaseQueriesPostgresql;
use app\libraries\database\AbstractDatabaseQueries;
use app\models\Config;
use app\models\User;

/**
 * Class Core
 *
 * This is the core of the application that contains references to the other main
 * libraries (such as Database, Session, etc.) that the application relies on.
 */
class Core {
    /**
     * @var \app\models\Config
     */
    private $config = null;

    /** @var Database */
    private $submitty_db = null;

    /** @var Database */
    private $course_db = null;

    /** @var AbstractAuthentication */
    private $authentication;

    /** @var SessionManager */
    private $session_manager;

    /** @var AbstractDatabaseQueries */
    private $database_queries;

    /** @var User */
    private $user = null;

    /** @var Output */
    private $output = null;

    /**
     * Core constructor.
     *
     * This sets up our core for usage, by starting up our Output class as well as any $_SESSION variables that we
     * need. This should be called first, then loadConfig() and then loadDatabases().
     */
    public function __construct() {
        $this->output = new Output($this);
        // initialize our alert queue if it doesn't exist
        if(!isset($_SESSION['messages'])) {
            $_SESSION['messages'] = array();
        }
    
        // initialize our alert types if one of them doesn't exist
        foreach (array('error', 'notice', 'success') as $key) {
            if(!isset($_SESSION['messages'][$key])) {
                $_SESSION['messages'][$key] = array();
            }
        }
    
        // we cast each of our controller markers to lower to normalize our controller switches
        // and prevent any unexpected page failures for users in entering a capitalized controller
        foreach (array('component', 'page', 'action') as $key) {
            $_REQUEST[$key] = (isset($_REQUEST[$key])) ? strtolower($_REQUEST[$key]) : "";
        }
    }

    /**
     * Load the config details for the application. This takes in a file from the ../../config/master.ini as well as
     * then a config.ini contained in {$SUBMITTY_DATA_DIR}/courses/{$SEMESTER}/{$COURSE}/config directory. These
     * files contain details about how the database, location of files, late days settings, etc.
     *
     * Config model will throw exceptions if we cannot find a given $semester or $course on the fileystem.
     *
     * @param $semester
     * @param $course
     * @throws \Exception
     */
    public function loadConfig($semester, $course, $master_ini_path) {
        $this->config = new Config($semester, $course, $master_ini_path);
        $auth_class = "\\app\\authentication\\".$this->config->getAuthentication();
        if (!is_subclass_of($auth_class, 'app\authentication\AbstractAuthentication')) {
            throw new \Exception("Invalid module specified for Authentication. All modules should implement the AbstractAuthentication interface.");
        }
        $this->authentication = new $auth_class($this);
        $this->session_manager = new SessionManager($this);
    }

    /**
     * Create a connection to the database using the details loaded from the config files. Additionally, we make
     * available queries that all parts of the application should go through. It should never be allowed to directly
     * go through the database as we risk ending up with the same queries repeated around the application which makes
     * changing and fixing bugs that much harder.
     *
     * @throws \Exception if we have not loaded the config yet
     */
    public function loadDatabases() {
        if ($this->config === null) {
            throw new \Exception("Need to load the config before we can connect to the database");
        }

        $this->submitty_db = new Database($this->config->getDatabaseHost(), $this->config->getDatabaseUser(),
            $this->config->getDatabasePassword(), "submitty_db", $this->config->getDatabaseType());

        $this->course_db = new Database($this->config->getDatabaseHost(), $this->config->getDatabaseUser(),
            $this->config->getDatabasePassword(), $this->config->getDatabaseName(), $this->config->getDatabaseType());
        $this->course_db->connect();

        switch ($this->config->getDatabaseType()) {
            case 'pgsql':
                $this->database_queries = new DatabaseQueriesPostgresql($this);
                break;
            default:
                throw new DatabaseException("Unrecognized database type");
        }
    }

    /**
     * Helper function which is given a list of arguments where the first is the name of the model and the rest are
     * the parameters to pass to the constructor of the model. We use this for controllers were we are not
     * doing dependency injection into the controller (like setting up GradeableList) so that we can then have
     * the ability to Mock the object (by mocking this function) for testing.
     *
     * @return object Loaded instance of the requested model.
     */
    public function loadModel() {
        if (func_num_args() == 0) {
            throw new \InvalidArgumentException("loadModel requires at least one argument (Model)");
        }
        $args = func_get_args();
        $model = $args[0];
        foreach (AutoLoader::getClasses() as $class => $path) {
            if (Utils::startsWith($class, "app\\models\\") && Utils::endsWith($class, $model)) {
                // TODO: Once we drop PHP 5.5, we can drop this reflection and just use vargs
                $reflect = new \ReflectionClass($class);
                return $reflect->newInstanceArgs(array_slice($args, 1));
            }
        }
        $error = "Could not find the model to load. Check for misspellings and that it was autoloaded";
        throw new \InvalidArgumentException($error);
    }

    /**
     * Deconstructor for the Core. Cleans up any messages from the server as well as disconnects
     * the database, running any open transactions that were left.
     */
    public function __destruct() {
        if ($this->course_db !== null) {
            $this->getSubmittyDB()->disconnect();
            $this->getCourseDB()->disconnect();
        }
    }

    public function addErrorMessage($message) {
        $_SESSION['messages']['error'][] = $message;
    }

    public function addNoticeMessage($message) {
        $_SESSION['messages']['notice'][] = $message;
    }

    public function addSuccessMessage($message) {
        $_SESSION['messages']['success'][] = $message;
    }

    /**
     * @return Config
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * @return Database
     */
    public function getSubmittyDB() {
        return $this->submitty_db;
    }

    /**
     * @return Database
     */
    public function getCourseDB() {
        return $this->course_db;
    }

    /**
     * @return AbstractDatabaseQueries
     */
    public function getQueries() {
        return $this->database_queries;
    }

    /**
     * @param string $user_id
     */
    public function loadUser($user_id) {
        // attempt to load rcs as both student and user
        $this->user = $this->database_queries->getUserById($user_id);
    }

    /**
     * Returns the user that the client is logged in as. Will return null if there is no user
     * to be logged in as.
     *
     * @return User
     */
    public function getUser() {
        return $this->user;
    }

    /**
     * Is a user loaded into the Core to be used for the client to be logged in as
     *
     * @return bool
     */
    public function userLoaded() {
        return $this->user !== null && $this->user->isLoaded();
    }

    /**
     * @return string
     */
    public function getCsrfToken() {
        return $this->session_manager->getCsrfToken();
    }

    /**
     * @return AbstractAuthentication
     */
    public function getAuthentication() {
        return $this->authentication;
    }

    /**
     * Given a session id (which should be coming from a cookie or request header), the database is queried to find
     * a session that matches the string, then returns the user that matches that row (if it exists). If no session
     * is found that matches the given id, return false, otherwise return true and load the user.
     *
     * @param $session_id
     *
     * @return bool
     */
    public function getSession($session_id) {
        $user_id = $this->session_manager->getSession($session_id);
        if ($user_id === false) {
            return false;
        }

        $this->loadUser($user_id);
        return true;
    }

    /**
     * Remove the currently loaded session within the session manager
     */
    public function removeCurrentSession() {
        $this->session_manager->removeCurrentSession();
    }

    /**
     * Authenticates the user against whatever method was choosen within the master.ini config file (and exists
     * within the app/authentication folder. The username and password for the user being authenticated are passed
     * in separately so that we do not worry about those being leaked via the stack trace that might get thrown
     * from this method. Returns True/False whether or not the authenication attempt succeeded/failed.
     *
     * @param bool $persistent_cookie should we store this for some amount of time (true) or till browser closure (false)
     * @return bool
     *
     * @throws AuthenticationException
     */
    public function authenticate($persistent_cookie = true) {
        $auth = false;
        $user_id = $this->authentication->getUserId();
        try {
            if ($this->authentication->authenticate()) {
                $auth = true;
                $session_id = $this->session_manager->newSession($user_id);
                $cookie_id = $this->getConfig()->getSemester()."_".$this->getConfig()->getCourse()."_session_id";
                // Set the cookie to last for 7 days
                $cookie_data = array('session_id' => $session_id);
                $cookie_data['expire_time'] = ($persistent_cookie === true) ? time() + (7 * 24 * 60 * 60) : 0;
                if (Utils::setCookie($cookie_id, $cookie_data, $cookie_data['expire_time']) === false) {
                    return false;
                }
            }
        }
        catch (\Exception $e) {
            // We wrap all non AuthenticationExceptions so that they get specially processed in the
            // ExceptionHandler to remove password details
            if ($e instanceof AuthenticationException) {
                throw $e;
            }
            throw new AuthenticationException($e->getMessage(), $e->getCode(), $e);
        }
        return $auth;
    }

    /**
     * Checks the inputted $csrf_token against the one that is loaded from the session table for the particular
     * signed in user.
     *
     * @param string $csrf_token
     *
     * @return bool
     */
    public function checkCsrfToken($csrf_token=null) {
        if ($csrf_token === null) {
            return isset($_POST['csrf_token']) && $this->getCsrfToken() === $_POST['csrf_token'];
        }
        else {
            return $this->getCsrfToken() === $csrf_token;
        }
    }

    /**
     * Given some number of URL parameters (parts), build a URL for the site using those parts
     *
     * @param array  $parts
     * @param string $hash
     *
     * @return string
     */
    public function buildUrl($parts=array(), $hash = null) {
        $url = $this->config->getSiteUrl().((count($parts) > 0) ? "&".http_build_query($parts) : "");
        if ($hash !== null) {
            $url .= "#".$hash;
        }
        return $url;
    }

    /**
     * @param     $url
     * @param int $status_code
     */
    public function redirect($url, $status_code = 302) {
        header('Location: ' . $url, true, $status_code);
        die();
    }

    /**
     * Returns all the different parts of the url used for choosing the appropriate controller
     * and method of that controller to run
     *
     * @return array
     */
    public function getControllerTypes() {
        return array('component', 'page', 'action');
    }

    /**
     * Returns a string that contains the course code as well as the course name only if the course name is not
     * blank, placing a colon between the two (if both are displayed)
     *
     * @return string
     */
    public function getFullCourseName() {
        $course_name = strtoupper($this->getConfig()->getCourse());
        if ($this->getConfig()->getCourseName() !== "") {
            $course_name .= ": ".htmlentities($this->getConfig()->getCourseName());
        }
        return $course_name;
    }
    
    /**
     * @return Output
     */
    public function getOutput() {
        return $this->output;
    }

    /**
     * We use this function to allow us to bypass certain "safe" PHP functions that we cannot
     * bypass via mocking or some other method (like is_uploaded_file). This method, which normally
     * ALWAYS returns FALSE we can mock to return TRUE for testing. It's probably not "best practices",
     * and the proper way is using "phpt" files, but
     *
     * @return bool
     */
    public function isTesting() {
        return false;
    }
}
