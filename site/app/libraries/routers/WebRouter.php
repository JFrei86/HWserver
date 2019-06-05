<?php


namespace app\libraries\routers;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Loader\AnnotationDirectoryLoader;
use Doctrine\Common\Annotations\AnnotationReader;
use app\libraries\Utils;
use app\libraries\Core;


class WebRouter {
    /** @var Core  */
    protected $core;

    /** @var Request  */
    protected $request;

    /** @var bool */
    protected $logged_in;

    /** @var UrlMatcher  */
    protected $matcher;

    /** @var array */
    public $parameters;

    public function __construct(Request $request, Core $core, $logged_in) {
        $this->core = $core;
        $this->request = $request;
        $this->logged_in = $logged_in;

        $fileLocator = new FileLocator();
        /** @noinspection PhpUnhandledExceptionInspection */
        $annotationLoader = new AnnotatedRouteLoader(new AnnotationReader());
        $loader = new AnnotationDirectoryLoader($fileLocator, $annotationLoader);
        $collection = $loader->load(realpath(__DIR__ . "/../../controllers"));

        $this->matcher = new UrlMatcher($collection, new RequestContext());
        $this->parameters = $this->matcher->matchRequest($this->request);
        $this->loginCheck();
    }

    public function run() {
        $controllerName = $this->parameters['_controller'];
        $methodName = $this->parameters['_method'];
        $controller = new $controllerName($this->core);

        if (in_array('semester', $this->parameters) && in_array('course', $this->parameters)) {
            $semester = $this->parameters['semester'];
            $course = $this->parameters['course'];
            /** @noinspection PhpUnhandledExceptionInspection */
            $this->core->loadConfig($semester, $course);
        }

        foreach ($this->parameters as $key => $value) {
            if (Utils::startsWith($key, "_")) {
                unset($this->parameters[$key]);
            }
        }

        return call_user_func_array(array($controller, $methodName), $this->parameters);
    }

    private function loginCheck() {
        if (!$this->logged_in) {
            $this->request = Request::create(
                '/authentication/login',
                'GET',
                ['old' => $this->request]
            );
            $this->parameters = $this->matcher->matchRequest($this->request);
        }
        elseif ($this->core->getUser() === null) {
            $this->core->loadSubmittyUser();
            if (!Utils::endsWith($this->parameters['_controller'], 'AuthenticationController')) {
                $this->request = Request::create(
                    '/navigation/no_access',
                    'GET'
                );
                $this->parameters = $this->matcher->matchRequest($this->request);
            }
        }
        elseif ($this->core->getConfig()->isCourseLoaded()
            && !$this->core->getAccess()->canI("course.view", ["semester" => $this->core->getConfig()->getSemester(), "course" => $this->core->getConfig()->getCourse()])
            && !Utils::endsWith($this->parameters['_controller'], 'AuthenticationController')) {
            $this->request = Request::create(
                '/navigation/no_access',
                'GET'
            );
            $this->parameters = $this->matcher->matchRequest($this->request);
        }

        // TODO: log

        if(!$this->core->getConfig()->isCourseLoaded()) {
            if ($this->logged_in){
                if (isset($this->parameters['_method']) && $this->parameters['_method'] === 'logout'){
                    $this->request = Request::create(
                        '/authentication/logout',
                        'GET'
                    );
                    $this->parameters = $this->matcher->matchRequest($this->request);
                }
                else {
                    $this->request = Request::create(
                        '/home',
                        'GET'
                    );
                    $this->parameters = $this->matcher->matchRequest($this->request);
                }
            }
            else {
                $this->request = Request::create(
                    '/authentication/login',
                    'GET'
                );
                $this->parameters = $this->matcher->matchRequest($this->request);
            }
        }

        if (empty($this->parameters['_controller']) && $this->core->getUser() !== null) {
            if ($this->core->getConfig()->isCourseLoaded()) {
                $this->request = Request::create(
                    '/' . $this->core->getConfig()->getSemester() . '/' . $this->core->getConfig()->getCourse(),
                    'GET'
                );
                $this->parameters = $this->matcher->matchRequest($this->request);
            }
            else {
                $this->request = Request::create(
                    '/home',
                    'GET'
                );
                $this->parameters = $this->matcher->matchRequest($this->request);
            }
        }
    }
}