<?php

namespace tests\unitTests\app\models;

use app\exceptions\ConfigException;
use app\libraries\FileUtils;
use app\libraries\IniParser;
use app\libraries\Utils;
use app\models\Config;

class ConfigTester extends \PHPUnit_Framework_TestCase {
    private $temp_dir = null;
    private $master = null;

    /**
     * This test ensures that the default value of the DEBUG flag within the config model is always false. This
     * means that if the value is not found within the ini file, we don't have to worry about accidently
     * exposing things to students.
     */
    public function testClassProperties() {
        $class = new \ReflectionClass('app\models\Config');
        $properties = $class->getDefaultProperties();
        $this->assertFalse($properties['debug']);
    }

    public function tearDown() {
        if (is_dir($this->temp_dir)) {
            FileUtils::recursiveRmdir($this->temp_dir);
        }
    }

    private function createConfigFile($extra = array()) {
        $this->temp_dir = FileUtils::joinPaths(sys_get_temp_dir(), Utils::generateRandomString());
        FileUtils::createDir($this->temp_dir);
        $course_path = FileUtils::joinPaths($this->temp_dir, "courses", "s17", "csci0000");
        $log_path = FileUtils::joinPaths($this->temp_dir, "logs");
        FileUtils::createDir($course_path, 0777, true);
        FileUtils::createDir(FileUtils::joinPaths($course_path, "config"));
        FileUtils::createDir($log_path);
        $this->master = FileUtils::joinPaths($this->temp_dir,  "master.ini");
        $course = FileUtils::joinPaths($course_path, "config", "config.ini");
        $config = array(
            'site_details' => array(
                'base_url' => "http://example.com",
                'cgi_url' => "http://example.com/cgi",
                'ta_base_url' => "http://example.com/ta",
                'submitty_path' => $this->temp_dir,
                'authentication' => "PamAuthentication",
                'timezone' => "America/Chicago",
            ),
            'logging_details' => array(
                'submitty_log_path' => $log_path,
                'log_exceptions' => true,
            ),
            'database_details' => array(
                'database_host' => 'db_host',
                'database_user' => 'db_user',
                'database_password' => 'db_pass'
            )
        );

        $config = array_replace_recursive($config, $extra);
        IniParser::writeFile($this->master, $config);

        $config = array(
            'hidden_details' => array(
                'database_name' => 'submitty_s17_csci0000'
            ),
            'course_details' => array(
                'course_name' => 'Test Course',
                'course_home_url' => '',
                'default_hw_late_days' => 2,
                'default_student_late_days' => 3,
                'zero_rubric_grades' => false,
                'upload_message' => "",
                'keep_previous_files' => false,
                'display_iris_grades_summary' => false,
                'display_custom_message' => false
            )
        );

        $config = array_replace_recursive($config, $extra);
        IniParser::writeFile($course, $config);
    }

    public function testConfig() {
        $this->createConfigFile();
        $config = new Config("s17", "csci0000", $this->master);

        $this->assertFalse($config->isDebug());
        $this->assertEquals("s17", $config->getSemester());
        $this->assertEquals("csci0000", $config->getCourse());
        $this->assertEquals("http://example.com/", $config->getBaseUrl());
        $this->assertEquals("http://example.com/ta/", $config->getTaBaseUrl());
        $this->assertEquals("http://example.com/cgi/", $config->getCgiUrl());
        $this->assertEquals("http://example.com/index.php?semester=s17&course=csci0000", $config->getSiteUrl());
        $this->assertEquals($this->temp_dir, $config->getSubmittyPath());
        $this->assertEquals($this->temp_dir."/courses/s17/csci0000", $config->getCoursePath());
        $this->assertEquals($this->temp_dir."/logs", $config->getLogPath());
        $this->assertTrue($config->shouldLogExceptions());
        $this->assertEquals("pgsql", $config->getDatabaseType());
        $this->assertEquals("db_host", $config->getDatabaseHost());
        $this->assertEquals("submitty_s17_csci0000", $config->getDatabaseName());
        $this->assertEquals("db_user", $config->getDatabaseUser());
        $this->assertEquals("db_pass", $config->getDatabasePassword());
        $this->assertEquals("Test Course", $config->getCourseName());
        $this->assertEquals("", $config->getCourseHomeUrl());
        $this->assertEquals(2, $config->getDefaultHwLateDays());
        $this->assertEquals(3, $config->getDefaultStudentLateDays());
        $this->assertFalse($config->shouldZeroRubricGrades());
        $this->assertEquals($this->temp_dir, $config->getConfigPath());
        $this->assertEquals("PamAuthentication", $config->getAuthentication());
        $this->assertEquals("America/Chicago", $config->getTimezone()->getName());
        $this->assertEquals("", $config->getUploadMessage());
        $this->assertFalse($config->displayCustomMessage());
        $this->assertFalse($config->keepPreviousFiles());
        $this->assertFalse($config->displayIrisGradesSummary());
        $this->assertEquals(FileUtils::joinPaths($this->temp_dir, "courses", "s17", "csci0000", "config", "config.ini"),
            $config->getCourseIniPath());

        $expected = array(
            'debug' => false,
            'semester' => 's17',
            'course' => 'csci0000',
            'base_url' => 'http://example.com/',
            'ta_base_url' => 'http://example.com/ta/',
            'cgi_url' => 'http://example.com/cgi/',
            'site_url' => 'http://example.com/index.php?semester=s17&course=csci0000',
            'submitty_path' => $this->temp_dir,
            'course_path' => $this->temp_dir.'/courses/s17/csci0000',
            'submitty_log_path' => $this->temp_dir.'/logs',
            'log_exceptions' => true,
            'database_type' => 'pgsql',
            'database_host' => 'db_host',
            'database_name' => 'submitty_s17_csci0000',
            'database_user' => 'db_user',
            'database_password' => 'db_pass',
            'course_name' => 'Test Course',
            'config_path' => $this->temp_dir,
            'course_ini' => $this->temp_dir.'/courses/s17/csci0000/config/config.ini',
            'authentication' => 'PamAuthentication',
            'timezone' => 'DateTimeZone',
            'course_home_url' => '',
            'default_hw_late_days' => 2,
            'default_student_late_days' => 3,
            'zero_rubric_grades' => false,
            'upload_message' => '',
            'keep_previous_files' => false,
            'display_iris_grades_summary' => false,
            'display_custom_message' => false,
            'hidden_details' => array(
                'database_name' => 'submitty_s17_csci0000'
            )
        );
        $actual = $config->toArray();

        ksort($expected);
        ksort($actual);
        $this->assertEquals($expected, $actual);
    }

    public function testHiddenCourseUrl() {
        $extra = array('hidden_details' => array('course_url' => 'http://example.com/course'));
        $this->createConfigFile($extra);
        $config = new Config("s17", "csci0000", $this->master);
        $this->assertEquals("http://example.com/course/", $config->getBaseUrl());
        $this->assertEquals("http://example.com/course", $config->getHiddenDetails()['course_url']);
    }

    public function testHiddenTABaseUrl() {
        $extra = array('hidden_details' => array('ta_base_url' => 'http://example.com/hwgrading'));
        $this->createConfigFile($extra);
        $config = new Config("s17", "csci0000", $this->master);
        $this->assertEquals("http://example.com/hwgrading/", $config->getTaBaseUrl());
        $this->assertEquals("http://example.com/hwgrading", $config->getHiddenDetails()['ta_base_url']);
    }

    public function testDefaultTimezone() {
        $extra = array('site_details' => array('timezone' => null));
        $this->createConfigFile($extra);
        $config = new Config("s17", "csci0000", $this->master);
        $this->assertEquals("America/New_York", $config->getTimezone()->getName());
    }

    public function testDebugTrue() {
        $extra = array('site_details' => array('debug' => true));
        $this->createConfigFile($extra);
        $config = new Config("s17", "csci0000", $this->master);
        $this->assertTrue($config->isDebug());
    }

    public function testDatabaseType() {
        $extra = array('database_details' => array('database_type' => 'mysql'));
        $this->createConfigFile($extra);
        $config = new Config("s17", "csci0000", $this->master);
        $this->assertEquals("mysql", $config->getDatabaseType());
    }

    public function getRequiredSections() {
        return array(
            array('site_details'),
            array('logging_details'),
            array('database_details'),
            array('hidden_details'),
            array('course_details')
        );
    }

    /**
     * @dataProvider getRequiredSections
     *
     * @param string $section
     */
    public function testMissingSections($section) {
        try {
            $extra = array($section => null);
            $this->createConfigFile($extra);
            new Config("s17", "csci0000", $this->master);
            $this->fail("Should have thrown ConfigException");
        }
        catch (ConfigException $exception) {
            $this->assertEquals("Missing config section {$section} in ini file", $exception->getMessage());
        }
    }

    public function getRequiredSettings() {
        $settings = array(
            'site_details' => array(
                'base_url', 'cgi_url', 'ta_base_url', 'submitty_path', 'authentication'
            ),
            'logging_details' => array(
                'submitty_log_path', 'log_exceptions'
            ),
            'database_details' => array(
                'database_host', 'database_user', 'database_password'
            ),
            'hidden_details' => array(
                'database_name'
            ),
            'course_details' => array(
                'course_name', 'course_home_url', 'default_hw_late_days', 'default_student_late_days',
                'zero_rubric_grades', 'upload_message', 'keep_previous_files', 'display_iris_grades_summary',
                'display_custom_message'
            )
        );
        $return = array();
        foreach ($settings as $key => $value) {
            foreach ($value as $vv) {
                $return[] = array($key, $vv);
            }
        }
        return $return;
    }

    /**
     * @dataProvider getRequiredSettings
     *
     * @param string $section
     * @param string $setting
     */
    public function testMissingSectionSetting($section, $setting) {
        try {
            $extra = array($section => array($setting => null));
            $this->createConfigFile($extra);
            new Config("s17", "csci0000", $this->master);
            $this->fail("Should have thrown ConfigException for {$section}.{$setting}");
        }
        catch (ConfigException $exception) {
            $this->assertEquals("Missing config setting {$section}.{$setting} in configuration ini file",
                $exception->getMessage());
        }

    }

    /**
     * @expectedException \app\exceptions\ConfigException
     * @expectedExceptionMessage Invalid Timezone identifier: invalid
     */
    public function testInvalidTimezone() {
        $extra = array('site_details' => array('timezone' => "invalid"));
        $this->createConfigFile($extra);
        new Config("s17", "csci0000", $this->master);
    }

    /**
     * @expectedException \app\exceptions\ConfigException
     * @expectedExceptionMessage Invalid semester: invalid
     */
    public function testInvalidSemester() {
        $this->createConfigFile();
        new Config("invalid", "csci0000", $this->master);
    }

    /**
     * @expectedException \app\exceptions\ConfigException
     * @expectedExceptionMessage Invalid course: invalid
     */
    public function testInvalidCourse() {
        $this->createConfigFile();
        new Config("s17", "invalid", $this->master);
    }

    /**
     * @expectedException \app\exceptions\ConfigException
     * @expectedExceptionMessage Invalid path for setting submitty_path: /invalid
     */
    public function testInvalidSubmittyPath() {
        $extra = array('site_details' => array('submitty_path' => '/invalid'));
        $this->createConfigFile($extra);
        new Config("s17", "csci0000", $this->master);
    }

    /**
     * @expectedException \app\exceptions\ConfigException
     * @expectedExceptionMessage Invalid path for setting submitty_log_path: /invalid
     */
    public function testInvalidLogPath() {
        $extra = array('logging_details' => array('submitty_log_path' => '/invalid'));
        $this->createConfigFile($extra);
        new Config("s17", "csci0000", $this->master);
    }
}
