<?php

namespace tests\app\controllers\admin;

use app\controllers\admin\ConfigurationController;
use app\libraries\Core;
use app\libraries\database\DatabaseQueries;
use app\libraries\FileUtils;
use app\libraries\SessionManager;
use app\libraries\Utils;
use app\models\Config;
use app\views\admin\ConfigurationView;
use tests\utils\NullOutput;

class ConfigurationControllerTester extends \PHPUnit\Framework\TestCase {
    private $test_dir;
    private $master_configs_dir;
    private $course_config;

    public function setUpConfig($seating_dirs = []): void {
        $this->test_dir = FileUtils::joinPaths(sys_get_temp_dir(), Utils::generateRandomString());
        FileUtils::createDir($this->test_dir);
        $this->master_configs_dir = FileUtils::joinpaths($this->test_dir, 'master');
        FileUtils::createDir($this->master_configs_dir);
        foreach (['autograding', 'access', 'site_errors', 'ta_grading'] as $path) {
            FileUtils::createDir(FileUtils::joinPaths($this->test_dir, $path));
        }
        $config_files = [
            'autograding_workers' => '{"primary":{"capabilities":["default"],"address":"localhost","username":"","num_autograding_workers":5,"enabled":true}}',
            'database' => '{"authentication_method":"PamAuthentication","database_host":"\/var\/run\/postgresql","database_user":"submitty_dbuser","database_password":"submitty_dbuser","debugging_enabled":true}',
            'email' => '{"email_enabled":true,"email_user":"","email_password":"","email_sender":"submitty@vagrant","email_reply_to":"do-not-reply@vagrant","email_server_hostname":"localhost","email_server_port":25}',
            'secrets_submitty_php' => '{"session":"cGRZSDnVxdDjQwGyiq4ECnJyiZ8IQXEL1guSsJ1XlSKSEqisqvdCPhCRcYDEjpjm"}',
            'submitty_admin' => '{"submitty_admin_username":"submitty-admin","submitty_admin_password":"submitty-admin","token":"token"}',
            'submitty' => '{"submitty_install_dir":' . json_encode($this->test_dir) . ',"submitty_repository":' . json_encode($this->test_dir) . ',"submitty_data_dir":' . json_encode($this->test_dir) . ',"autograding_log_path":' . json_encode($this->test_dir) . ',"site_log_path":' . json_encode($this->test_dir) . ',"submission_url":"http:\/\/192.168.56.111","vcs_url":"","cgi_url":"http:\/\/192.168.56.111\/cgi-bin","institution_name":"","username_change_text":"foo","institution_homepage":"","timezone":"America\/New_York","worker":false}',
            'submitty_users' => '{"num_grading_scheduler_workers":5,"num_untrusted":60,"first_untrusted_uid":900,"first_untrusted_gid":900,"daemon_uid":1003,"daemon_gid":1006,"daemon_user":"submitty_daemon","course_builders_group":"submitty_course_builders","php_uid":1001,"php_gid":1004,"php_user":"submitty_php","cgi_user":"submitty_cgi","daemonphp_group":"submitty_daemonphp","daemoncgi_group":"submitty_daemoncgi","verified_submitty_admin_user":"submitty-admin"}',
            'version' => '{"installed_commit":"7da8417edd6ff46f1d56e1a938b37c054a7dd071","short_installed_commit":"7da8417ed","most_recent_git_tag":"v19.09.04"}'
        ];
        foreach ($config_files as $file => $value) {
            file_put_contents(FileUtils::joinPaths($this->master_configs_dir, $file . '.json'), $value);
        }
        $this->course_config = FileUtils::joinPaths($this->test_dir, 'course.json');
        file_put_contents(
            $this->course_config,
            '{"database_details":{"dbname":"submitty_f19_sample"},"course_details":{"course_name":"Submitty Sample","course_home_url":"","default_hw_late_days":0,"default_student_late_days":0,"zero_rubric_grades":false,"upload_message":"Hit Submit","keep_previous_files":false,"display_rainbow_grades_summary":false,"display_custom_message":false,"course_email":"Please contact your TA or instructor to submit a grade inquiry.","vcs_base_url":"","vcs_type":"git","private_repository":"","forum_enabled":true,"regrade_enabled":false,"regrade_message":"Regrade Message","seating_only_for_instructor":false,"room_seating_gradeable_id":"","auto_rainbow_grades":false, "queue_enabled": false}}'
        );
        FileUtils::createDir(FileUtils::joinPaths($this->test_dir, 'courses', 'f19', 'sample', 'reports', 'seating'), true);
        foreach ($seating_dirs as $dir) {
            FileUtils::createDir(FileUtils::joinPaths($this->test_dir, 'courses', 'f19', 'sample', 'reports', 'seating', $dir));
        }
    }

    public function tearDown(): void {
        if (!empty($this->test_dir) && file_exists($this->test_dir)) {
            FileUtils::recursiveRmdir($this->test_dir);
        }
        $_POST = [];
    }

    public function testViewConfiguration(): void {
        $this->setUpConfig();
        $core = new Core();
        $core->setOutput(new NullOutput($core));
        $config = new Config($core);
        $config->loadMasterConfigs($this->master_configs_dir);
        $config->loadCourseJson('f19', 'sample', $this->course_config);
        $core->setConfig($config);
        $queries = $this->createMock(DatabaseQueries::class);
        $queries
            ->expects($this->once())
            ->method('getAllGradeablesIdsAndTitles')
            ->with()
            ->willReturn([
                ['g_id' => 'test1'],
                ['g_id' => 'test2']
            ]);
        $queries
            ->expects($this->once())
            ->method('checkIsInstructorInCourse')
            ->with($this->equalTo('submitty-admin'), $this->equalTo('sample'), $this->equalTo('f19'))
            ->willReturn(true);
        $core->setQueries($queries);
        $controller = new ConfigurationController($core);
        $core->setSessionManager(new SessionManager($core));
        $response = $controller->viewConfiguration();
        $this->assertNull($response->redirect_response);
        $expected = [
            'course_name'                    => 'Submitty Sample',
            'course_home_url'                => '',
            'default_hw_late_days'           => 0,
            'default_student_late_days'      => 0,
            'zero_rubric_grades'             => false,
            'upload_message'                 => 'Hit Submit',
            'keep_previous_files'            => false,
            'display_rainbow_grades_summary' => false,
            'display_custom_message'         => false,
            'course_email'                   => 'Please contact your TA or instructor to submit a grade inquiry.',
            'vcs_base_url'                   => 'http://192.168.56.111/{$vcs_type}/f19/sample/',
            'vcs_type'                       => 'git',
            'forum_enabled'                  => true,
            'regrade_enabled'                => false,
            'regrade_message'                => 'Regrade Message',
            'private_repository'             => '',
            'room_seating_gradeable_id'      => '',
            'seating_only_for_instructor'    => false,
            'auto_rainbow_grades'            => false,
            'queue_enabled'                  => false
        ];

        $gradeable_seating_options = [
            [
                'g_id' => "",
                'g_title' => "--None--"
            ]
        ];
        $admin_user = [
            'user_id' => 'submitty-admin',
            'verified' => true,
            'in_course' => true
        ];

        $this->assertNotNull($response->json_response);
        $json_expected = [
            'status' => 'success',
            'data' => [
                'config' => $expected,
                'gradeable_seating_options' => $gradeable_seating_options,
                'email_enabled' => true,
                'submitty_admin_user' => $admin_user
            ]
        ];
        $this->assertEquals($json_expected, $response->json_response->json);
        $this->assertEquals(ConfigurationView::class, $response->web_response->view_class);
        $this->assertEquals('viewConfig', $response->web_response->view_function);
        $this->assertEquals([$expected, $gradeable_seating_options, true, $admin_user, true, false], $response->web_response->parameters);
    }

    public function testViewConfigurationWithSeatingChartsFirstItem(): void {
        $this->setUpConfig(['test1']);
        $core = new Core();
        $core->setOutput(new NullOutput($core));
        $config = new Config($core);
        $config->loadMasterConfigs($this->master_configs_dir);
        $config->loadCourseJson('f19', 'sample', $this->course_config);
        $core->setConfig($config);
        $queries = $this->createMock(DatabaseQueries::class);
        $queries
            ->expects($this->once())
            ->method('getAllGradeablesIdsAndTitles')
            ->with()
            ->willReturn([
                ['g_id' => 'test1', 'g_title' => 'Test 1'],
                ['g_id' => 'test2', 'g_title' => 'Test 2']
            ]);
        $queries
            ->expects($this->once())
            ->method('checkIsInstructorInCourse')
            ->with($this->equalTo('submitty-admin'), $this->equalTo('sample'), $this->equalTo('f19'))
            ->willReturn(true);
        $core->setQueries($queries);
        $controller = new ConfigurationController($core);
        $core->setSessionManager(new SessionManager($core));
        $response = $controller->viewConfiguration();
        $this->assertNull($response->redirect_response);
        $expected = [
            'course_name'                    => 'Submitty Sample',
            'course_home_url'                => '',
            'default_hw_late_days'           => 0,
            'default_student_late_days'      => 0,
            'zero_rubric_grades'             => false,
            'upload_message'                 => 'Hit Submit',
            'keep_previous_files'            => false,
            'display_rainbow_grades_summary' => false,
            'display_custom_message'         => false,
            'course_email'                   => 'Please contact your TA or instructor to submit a grade inquiry.',
            'vcs_base_url'                   => 'http://192.168.56.111/{$vcs_type}/f19/sample/',
            'vcs_type'                       => 'git',
            'forum_enabled'                  => true,
            'regrade_enabled'                => false,
            'regrade_message'                => 'Regrade Message',
            'private_repository'             => '',
            'room_seating_gradeable_id'      => '',
            'seating_only_for_instructor'    => false,
            'auto_rainbow_grades'            => false,
            'queue_enabled'                  => false
        ];

        $gradeable_seating_options = [
            [
                'g_id' => "",
                'g_title' => "--None--"
            ],
            [
                'g_id' => 'test1',
                'g_title' => 'Test 1'
            ]
        ];
        $admin_user = [
            'user_id' => 'submitty-admin',
            'verified' => true,
            'in_course' => true
        ];

        $this->assertNotNull($response->json_response);
        $json_expected = [
            'status' => 'success',
            'data' => [
                'config' => $expected,
                'gradeable_seating_options' => $gradeable_seating_options,
                'email_enabled' => true,
                'submitty_admin_user' => $admin_user
            ]
        ];

        $this->assertNotNull($response->json_response);
        $this->assertEquals($json_expected, $response->json_response->json);
        $this->assertEquals(ConfigurationView::class, $response->web_response->view_class);
        $this->assertEquals('viewConfig', $response->web_response->view_function);
        $this->assertEquals([$expected, $gradeable_seating_options, true, $admin_user, true, false], $response->web_response->parameters);
    }

    public function testViewConfigurationWithSeatingChartsNonFirstItem(): void {
        $this->setUpConfig(['test2', 'test3']);
        $core = new Core();
        $core->setOutput(new NullOutput($core));
        $config = new Config($core);
        $config->loadMasterConfigs($this->master_configs_dir);
        $config->loadCourseJson('f19', 'sample', $this->course_config);
        $core->setConfig($config);
        $queries = $this->createMock(DatabaseQueries::class);
        $queries
            ->expects($this->once())
            ->method('getAllGradeablesIdsAndTitles')
            ->with()
            ->willReturn([
                ['g_id' => 'test1', 'g_title' => 'Test 1'],
                ['g_id' => 'test2', 'g_title' => 'Test 2'],
                ['g_id' => 'test3', 'g_title' => 'Test 3']
            ]);
        $queries
            ->expects($this->once())
            ->method('checkIsInstructorInCourse')
            ->with($this->equalTo('submitty-admin'), $this->equalTo('sample'), $this->equalTo('f19'))
            ->willReturn(true);
        $core->setQueries($queries);
        $controller = new ConfigurationController($core);
        $core->setSessionManager(new SessionManager($core));
        $response = $controller->viewConfiguration();
        $this->assertNull($response->redirect_response);
        $expected = [
            'course_name'                    => 'Submitty Sample',
            'course_home_url'                => '',
            'default_hw_late_days'           => 0,
            'default_student_late_days'      => 0,
            'zero_rubric_grades'             => false,
            'upload_message'                 => 'Hit Submit',
            'keep_previous_files'            => false,
            'display_rainbow_grades_summary' => false,
            'display_custom_message'         => false,
            'course_email'                   => 'Please contact your TA or instructor to submit a grade inquiry.',
            'vcs_base_url'                   => 'http://192.168.56.111/{$vcs_type}/f19/sample/',
            'vcs_type'                       => 'git',
            'forum_enabled'                  => true,
            'regrade_enabled'                => false,
            'regrade_message'                => 'Regrade Message',
            'private_repository'             => '',
            'room_seating_gradeable_id'      => '',
            'seating_only_for_instructor'    => false,
            'auto_rainbow_grades'            => false,
            'queue_enabled'                  => false
        ];

        $gradeable_seating_options = [
            [
                'g_id' => "",
                'g_title' => "--None--"
            ],
            [
                'g_id' => 'test2',
                'g_title' => 'Test 2'
            ],
            [
                'g_id' => 'test3',
                'g_title' => 'Test 3'
            ]
        ];
        $admin_user = [
            'user_id' => 'submitty-admin',
            'verified' => true,
            'in_course' => true
        ];

        $this->assertNotNull($response->json_response);
        $json_expected = [
            'status' => 'success',
            'data' => [
                'config' => $expected,
                'gradeable_seating_options' => $gradeable_seating_options,
                'email_enabled' => true,
                'submitty_admin_user' => $admin_user
            ]
        ];

        $this->assertNotNull($response->json_response);
        $this->assertEquals($json_expected, $response->json_response->json);
        $this->assertEquals(ConfigurationView::class, $response->web_response->view_class);
        $this->assertEquals('viewConfig', $response->web_response->view_function);
        $this->assertEquals([$expected, $gradeable_seating_options, true, $admin_user, true, false], $response->web_response->parameters);
    }

    public function testUpdateConfigurationNoName() {
        $core = new Core();
        $controller = new ConfigurationController($core);
        $response = $controller->updateConfiguration();
        $this->assertNull($response->web_response);
        $this->assertNull($response->redirect_response);
        $expected = [
            'status' => 'fail',
            'message' => 'Name of config value not provided'
        ];
        $this->assertEquals($expected, $response->json_response->json);
    }

    public function testUpdateConfigurationNoEntry() {
        $core = new Core();
        $_POST['name'] = 'foo';
        $controller = new ConfigurationController($core);
        $response = $controller->updateConfiguration();
        $this->assertNull($response->web_response);
        $this->assertNull($response->redirect_response);
        $expected = [
            'status' => 'fail',
            'message' => 'Name of config entry not provided'
        ];
        $this->assertEquals($expected, $response->json_response->json);
    }
}
