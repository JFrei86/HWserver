<?php

namespace app\controllers;

use app\libraries\response\RedirectResponse;
use app\models\Course;
use app\models\User;
use app\libraries\Core;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\response\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class HomePageController
 *
 * Controller to deal with the submitty home page. Once the user has been authenticated, but before they have
 * selected which course they want to access, they are forwarded to the home page.
 */
class HomePageController extends AbstractController {
    /**
     * HomePageController constructor.
     *
     * @param Core $core
     */
    public function __construct(Core $core) {
        parent::__construct($core);
    }

    /**
     * @Route("/api/courses", methods={"GET"})
     * @Route("/home/courses", methods={"GET"})
     *
     * @param string|null $user_id
     * @param bool|string $as_instructor
     * @return MultiResponse
     */
    public function getCourses($user_id = null, $as_instructor = false) {
        if ($as_instructor === 'true') {
            $as_instructor = true;
        }

        $user = $this->core->getUser();
        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $unarchived_courses = $this->core->getQueries()->getCourseForUserId($user_id);
        $archived_courses = $this->core->getQueries()->getCourseForUserId($user_id, true);

        if ($as_instructor) {
            foreach (['archived_courses', 'unarchived_courses'] as $var) {
                $$var = array_filter($$var, function (Course $course) use ($user_id) {
                    return $this->core->getQueries()->checkIsInstructorInCourse($user_id, $course->getTitle(), $course->getSemester());
                });
            }
        }

        $callback = function (Course $course) {
            return $course->getCourseInfo();
        };

        return MultiResponse::JsonOnlyResponse(
            JsonResponse::getSuccessResponse([
                "unarchived_courses" => array_map($callback, $unarchived_courses),
                "archived_courses" => array_map($callback, $archived_courses)
            ])
        );
    }

    /**
     * @Route("/home/groups")
     *
     * @param null $user_id
     * @return MultiResponse
     */
    public function getGroups($user_id = null): MultiResponse {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if (is_null($user_id) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            $user_id = $user->getId();
        }

        $groups = $this->core->getQueries()->getUserGroups($user_id);

        return new MultiResponse(
            JsonResponse::getSuccessResponse($groups)
        );
    }

    /**
     * Display the HomePageView to the student.
     *
     * @Route("/home")
     * @return MultiResponse
     */
    public function showHomepage() {
        $courses = $this->getCourses()->json_response->json;

        return new MultiResponse(
            null,
            new WebResponse(
                ['HomePage'],
                'showHomePage',
                $this->core->getUser(),
                $courses["data"]["unarchived_courses"],
                $courses["data"]["archived_courses"]
            )
        );
    }

    /**
     * @Route("/home/courses/new", methods={"POST"})
     * @Route("/api/courses", methods={"POST"})
     */
    public function createCourse() {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if (
            !isset($_POST['course_semester'])
            || !isset($_POST['course_title'])
            || !isset($_POST['head_instructor'])
            || !isset($_POST['group_name'])
        ) {
            $error = "Semester, course title, head instructor, or group name not set.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home']))
            );
        }

        $semester = $_POST['course_semester'];
        $course_title = strtolower($_POST['course_title']);
        $head_instructor = $_POST['head_instructor'];

        if ($user->getAccessLevel() === User::LEVEL_FACULTY && $head_instructor !== $user->getId()) {
            $error = "You can only create course for yourself.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home']))
            );
        }

        if (empty($this->core->getQueries()->getSubmittyUser($head_instructor))) {
            $error = "Head instructor doesn't exist.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getFailResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home']))
            );
        }

        $group_name = $_POST['group_name'];

        try {
            $group_check = $this->core->curlRequest(
                $this->core->getConfig()->getCgiUrl() . "group_check.cgi" . "?" . http_build_query(
                    [
                        'head_instructor' => $head_instructor,
                        'group_name' => $group_name
                    ]
                )
            );

            if (empty($group_check) || empty($group_name)) {
                $error = "Invalid group name.";
                $this->core->addErrorMessage($error);
                return new MultiResponse(
                    JsonResponse::getFailResponse($error),
                    null,
                    new RedirectResponse($this->core->buildUrl(['home']))
                );
            }

            if (json_decode($group_check, true)['status'] === 'fail') {
                $error = "The instructor is not in the correct Linux group.\n Please contact sysadmin for more information.";
                $this->core->addErrorMessage($error);
                return new MultiResponse(
                    JsonResponse::getFailResponse($error),
                    null,
                    new RedirectResponse($this->core->buildUrl(['home']))
                );
            }
        }
        catch (\Exception $e) {
            $error = "Server error.";
            $this->core->addErrorMessage($error);
            return new MultiResponse(
                JsonResponse::getErrorResponse($error),
                null,
                new RedirectResponse($this->core->buildUrl(['home']))
            );
        }

        $json = [
            "job" => 'CreateCourse',
            'semester' => $semester,
            'course' => $course_title,
            'head_instructor' => $head_instructor,
            'group_name' => $group_name
        ];

        $json = json_encode($json, JSON_PRETTY_PRINT);
        file_put_contents('/var/local/submitty/daemon_job_queue/create_' . $semester . '_' . $course_title . '.json', $json);

        $this->core->addSuccessMessage("Course creation request successfully sent.\n Please refresh the page later.");
        return new MultiResponse(
            JsonResponse::getSuccessResponse(null),
            null,
            new RedirectResponse($this->core->buildUrl(['home']))
        );
    }

    /**
     * @Route("/home/courses/new", methods={"GET"})
     */
    public function createCoursePage() {
        $user = $this->core->getUser();
        if (is_null($user) || !$user->accessFaculty()) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        if ($user->getAccessLevel() === User::LEVEL_SUPERUSER) {
            $faculty = $this->core->getQueries()->getAllFaculty();
        }

        return new MultiResponse(
            null,
            new WebResponse(
                ['HomePage'],
                'showCourseCreationPage',
                $faculty ?? null,
                $this->core->getUser()->getId(),
                $this->core->getQueries()->getAllTerms(),
                $this->core->getUser()->getAccessLevel() === User::LEVEL_SUPERUSER,
                $this->core->getCsrfToken()
            )
        );
    }

    /**
     * @Route("/term/new", methods={"POST"})
     * @return MultiResponse
     */
    public function addNewTerm() {
        $response = new MultiResponse();
        if (isset($_POST['term_id']) && isset($_POST['term_name']) && isset($_POST['start_date']) && isset($_POST['end_date'])) {
            $term_id = $_POST['term_id'];
            $term_name = $_POST['term_name'];
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];

            $terms = $this->core->getQueries()->getAllTerms();
            if (in_array($term_id, $terms)) {
                $this->core->addErrorMessage("Term id already exists.");
            }
            elseif ($end_date < $start_date) {
                $this->core->addErrorMessage("End date should be after Start date.");
            }
            else {
                $this->core->getQueries()->createNewTerm($term_id, $term_name, $start_date, $end_date);
                $this->core->addSuccessMessage("Term added successfully.");
            }
            $url = $this->core->buildUrl(['home', 'courses', 'new']);
            $response = $response->RedirectOnlyResponse(new RedirectResponse($url));
        }
        return $response;
    }

    /**
     * @Route("/update", methods={"GET"})
     * @return MultiResponse|WebResponse
     */
    public function systemUpdatePage() {
        $user = $this->core->getUser();
        if (is_null($user) || $user->getAccessLevel() !== User::LEVEL_SUPERUSER) {
            return new MultiResponse(
                JsonResponse::getFailResponse("You don't have access to this endpoint."),
                new WebResponse("Error", "errorPage", "You don't have access to this page.")
            );
        }

        $this->core->getOutput()->addInternalJs('system-update.js');
        $this->core->getOutput()->addInternalCss('system-update.css');
        return new WebResponse(
            'HomePage',
            'showSystemUpdatePage',
            $this->core->getCsrfToken()
        );
    }
}
