<?php

namespace tests;

use app\libraries\Core;
use app\libraries\database\DatabaseQueries;
use app\libraries\Output;
use app\libraries\Utils;
use app\libraries\Access;
use app\models\Config;
use app\models\User;
use Doctrine\ORM\EntityManager;
use ReflectionException;

class BaseUnitTest extends \PHPUnit\Framework\TestCase {
    protected static $mock_builders = [];

    /**
     * This array stores whether or not a mocked method was called.
     * This is useful in places where all you care about is that
     * the method was called. An example would be the case of a
     * database query being called from a controller and you
     * don't really care what was passed to the view. Only that the
     * database gets called.
     *
     * @var bool[]
     */
    protected $mocked_methods = [];

    /** @noinspection PhpDocSignatureInspection */
    /**
     * Creates a mocked the Core object predefining things with known values so that we don't have to do this
     * repeatidly for a variety of tests. This
     *
     * @param array $config_values
     * @param array $user_config
     * @param array $queries
     * @param array $access
     *
     * @return Core
     */
    protected function createMockCore($config_values = [], $user_config = [], $queries = [], $access = []) {
        /** @var Core&\PHPUnit\Framework\MockObject\MockObject $core */
        $core = $this->createMock(Core::class);

        /** @var Config&\PHPUnit\Framework\MockObject\MockObject $config */
        $config = $this->createMockModel(Config::class);
        if (isset($config_values['semester'])) {
            $config->method('getSemester')->willReturn($config_values['semester']);
        }

        if (isset($config_values['course'])) {
            $config->method('getCourse')->willReturn($config_values['course']);
        }

        if (isset($config_values['semester']) && isset($config_values['course'])) {
            $config->method('isCourseLoaded')->willReturn(true);
        }

        if (isset($config_values['tmp_path'])) {
            $config->method('getSubmittyPath')->willReturn($config_values['tmp_path']);
        }

        if (isset($config_values['course_path'])) {
            $config->method('getCoursePath')->willReturn($config_values['course_path']);
        }

        $config->method('getTimezone')->willReturn(new \DateTimeZone("America/New_York"));

        if (isset($config_values['use_mock_time']) && $config_values['use_mock_time'] === true) {
            $core->method('getDateTimeNow')->willReturn(new \DateTime('2001-01-01', $config->getTimezone()));
        }
        else {
            $core->method('getDateTimeNow')->willReturnCallback(function () use ($config) {
                return new \DateTime('now', $config->getTimezone());
            });
        }

        $core->method('getConfig')->willReturn($config);

        if (isset($config_values['logged_in'])) {
            $core->method('isWebLoggedIn')->willReturn($config_values['logged_in']);
            $core->method('isApiLoggedIn')->willReturn($config_values['logged_in']);
            $core->method('removeCurrentSession')->willReturn($config_values['logged_in']);
        }

        if (isset($config_values['csrf_token'])) {
            $core->method('checkCsrfToken')->willReturn($config_values['csrf_token'] === true);
        }
        else {
            $core->method('checkCsrfToken')->willReturn(true);
        }

        $mock_access = $this->createMock(Access::class);
        $mock_access->expects($this->any())->method('canI')->willReturnCallback(
            function ($permission) use ($access) {
                if (in_array($permission, $access)) {
                    return true;
                }
                return false;
            }
        );
        $core->method('getAccess')->willReturn($mock_access);

        $mock_queries = $this->createMock(DatabaseQueries::class);
        foreach ($queries as $method => $value) {
            $this->mocked_methods[$method] = false;
            $mock_queries->method($method)->will($this->returnCallback(function () use ($method, $value) {
                $this->mocked_methods[$method] = true;
                return $value;
            }));
        }
        $core->method('getQueries')->willReturn($mock_queries);

        $course_entity_manger = $this->createMock(EntityManager::class);
        $core->method('getCourseEntityManager')->willReturn($course_entity_manger);

        $submitty_entity_manager = $this->createMock(EntityManager::class);
        $core->method('getSubmittyEntityManager')->willReturn($submitty_entity_manager);

        if (!isset($user_config['no_user'])) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $user = $this->createMockModel(User::class);
            $user->method('getId')->willReturn("testUser");
            if (isset($user_config['access_grading'])) {
                $user->method('accessGrading')->willReturn($user_config['access_grading'] == true);
            }
            else {
                $user->method('accessGrading')->willReturn(false);
            }
            if (isset($user_config['access_full_grading'])) {
                $user->method('accessFullGrading')->willReturn($user_config['access_full_grading'] == true);
            }
            else {
                $user->method('accessFullGrading')->willReturn(false);
            }
            if (isset($user_config['access_admin'])) {
                $user->method('accessAdmin')->willReturn($user_config['access_admin'] == true);
            }
            else {
                $user->method('accessAdmin')->willReturn(false);
            }

            if (isset($user_config['access_faculty'])) {
                $user->method('accessFaculty')->willReturn($user_config['access_faculty'] == true);
            }
            else {
                $user->method('accessFaculty')->willReturn(false);
            }

            $core->method('getUser')->willReturn($user);
        }

        /** @var Output&\PHPUnit\Framework\MockObject\MockObject $output */
        $output = $this->getMockBuilder(Output::class)
            ->setConstructorArgs([$core])
            ->setMethods(['addBreadcrumb'])
            ->getMock();
        $output->method('addBreadcrumb')->willReturn(true);
        $output->disableRender();

        $core->method('getOutput')->willReturn($output);

        return $core;
    }

    /**
     * Utility function that helps us mock Models from our system. Because they rely on the magic __call() function,
     * we cannot directly mock these as we would any other object as then we won't have access to any methods that
     * require the __call() magic function. However, PHPUnit allows us to specify functions that are mockable (even
     * if they are not directly defined) via setMethods(), so we use reflection on our given class to get all methods
     * that are documented in the PHPDoc via the "@method" tag, and then also the defined functions. Kind of hacky,
     * and does slow testing down some, but it's easier than having to manually update a list of needed functions
     * when mocking these things.
     *
     * @noinspection PhpDocMissingThrowsInspection
     *
     * @param string $class
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    public function createMockModel(string $class) {
        if (!isset(static::$mock_builders[$class])) {
            $builder = $this->getMockBuilder($class)
                ->disableOriginalConstructor()
                ->disableOriginalClone()
                ->disableArgumentCloning()
                ->disallowMockingUnknownTypes();

            /** @noinspection PhpUnhandledExceptionInspection */
            $reflection = new \ReflectionClass($class);
            $methods = [];
            $matches = [];
            preg_match_all("/@method.* (.*)\(.*\)/", $reflection->getDocComment(), $matches);
            foreach ($matches[1] as $match) {
                if (strlen($match) > 0) {
                    $methods[] = $match;
                }
            }
            foreach ($reflection->getMethods() as $method) {
                if (!Utils::startsWith($method->getName(), "__")) {
                    $methods[] = $method->getName();
                }
            }
            $builder->setMethods(array_unique($methods));
            static::$mock_builders[$class] = $builder;
        }
        return static::$mock_builders[$class]->getMock();
    }

    /**
     * Call protected/private method of a class.
     * https://jtreminio.com/blog/unit-testing-tutorial-part-3-testing-protected-private-methods-coverage-reports-and-crap
     *
     * @param object &$object    Instantiated object that we will run method on.
     * @param string $methodName Method name to call
     * @param array  $parameters Array of parameters to pass into method.
     *
     * @throws ReflectionException
     * @return mixed Method return.
     */
    public function invokeMethod(&$object, $methodName, ...$parameters) {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Asserts whether a mocked method was called or not
     */
    public function assertMethodCalled(string $method): void {
        $this->assertTrue(array_key_exists($method, $this->mocked_methods) && $this->mocked_methods[$method]);
    }
}
