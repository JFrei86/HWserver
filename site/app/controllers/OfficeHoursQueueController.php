<?php

namespace app\controllers;

use app\libraries\Core;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\response\JsonResponse;
use app\libraries\response\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use app\models\OfficeHoursQueueModel;
use app\libraries\routers\AccessControl;
use app\libraries\socket\Client;
use app\libraries\Logger;
use WebSocket;

/**
 * Class OfficeHoursQueueController
 *
 */
class OfficeHoursQueueController extends AbstractController {

    public function __construct(Core $core) {
        parent::__construct($core);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue", methods={"GET"})
     * @return MultiResponse
     */
    public function showQueue($full_history = false) {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['home']))
            );
        }

        return MultiResponse::webOnlyResponse(
            new WebResponse(
                'OfficeHoursQueue',
                'showTheQueue',
                new OfficeHoursQueueModel($this->core, $full_history)
            )
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function openQueue() {
        if (empty($_POST['code'])) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($_POST['token'])) {
            $this->core->addErrorMessage("Missing secret code");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        //Replace whitespace with "_"
        $queue_code = trim($_POST['code']);
        $token = trim($_POST['token']);

        $re = '/^[\sa-zA-Z0-9_\-]+$/m';
        preg_match_all($re, $queue_code, $matches_code, PREG_SET_ORDER, 0);
        preg_match_all($re, $token, $matches_token, PREG_SET_ORDER, 0);
        if (count($matches_code) !== 1 || count($matches_token) !== 1) {
            $this->core->addErrorMessage('Queue name and secret code must only contain letters, numbers, spaces, "_", and "-"');
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }
        $regex_pattern = isset($_POST['regex']) ? $_POST['regex'] : '';
        if ($this->core->getQueries()->openQueue($queue_code, $token, $regex_pattern)) {
            $this->core->addSuccessMessage("New queue added");
            Logger::logQueueActivity($this->core->getConfig()->getSemester(), $this->core->getDisplayedCourseName(), $queue_code, "CREATED");
        }
        else {
            $this->core->addErrorMessage("Unable to add queue. Make sure you have a unique queue name");
        }

        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/add", methods={"POST"})
     * @return MultiResponse
     */
    public function addPerson($queue_code) {
        if (empty($_POST['name'])) {
            $this->core->addErrorMessage("Missing user's name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($_POST['token'])) {
            $this->core->addErrorMessage("Missing secret code");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $contact_info = null;
        if ($this->core->getConfig()->getQueueContactInfo()) {
            if (empty($_POST['contact_info'])) {
                $this->core->addErrorMessage("Missing contact info");
                return MultiResponse::RedirectOnlyResponse(
                    new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
                );
            }
            else {
                //make sure contact information matches instructors regex pattern
                $regex_pattern = $this->core->getQueries()->getQueueRegex($queue_code)[0]['regex_pattern'];
                if ($regex_pattern !== '') {
                    $contact_info = $_POST['contact_info'];
                    $invalid = false;
                    //if checking for any email address
                    if ($regex_pattern === 'email' || $regex_pattern === 'Email') {
                        if (!filter_var($contact_info, FILTER_VALIDATE_EMAIL)) {
                            $invalid = true;
                        }
                    }
                    elseif (preg_match($regex_pattern, $contact_info) == 0) {
                        $invalid = true;
                    }
                    if ($invalid) {
                        $this->core->addErrorMessage("Invalid contact info");
                        return MultiResponse::RedirectOnlyResponse(
                            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
                        );
                    }
                }
            }
        }

        $queue_code = trim($queue_code);
        $token = trim($_POST['token']);

        $validated_code = $this->core->getQueries()->isValidCode($queue_code, $token);
        if (!$validated_code) {
            $this->core->addErrorMessage("Invalid secret code");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if ($this->core->getQueries()->alreadyInAQueue()) {
            $this->core->addErrorMessage("You are already in the queue");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $this->core->getQueries()->addToQueue($validated_code, $this->core->getUser()->getId(), $_POST['name'], $contact_info);
        $this->sendSocketMessage(['type' => 'queue_update']);
        $this->core->addSuccessMessage("Added to queue");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/remove", methods={"POST"})
     * @return MultiResponse
     */
    public function removePerson($queue_code) {
        if (empty($_POST['user_id'])) {
            $this->core->addErrorMessage("Missing user ID");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (!$this->core->getUser()->accessGrading() && $this->core->getUser()->getId() !== $_POST['user_id']) {
            $this->core->addErrorMessage("Permission denied to remove that person");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $remove_type = 'removed';//Mentor or ta removed you
        if ($this->core->getUser()->getId() === $_POST['user_id']) {
            $remove_type = 'self';//You removed yourself
        }


        $this->core->getQueries()->removeUserFromQueue($_POST['user_id'], $remove_type, $queue_code);
        $this->sendSocketMessage(['type' => 'full_update']);
        $this->core->addSuccessMessage("Removed from queue");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/togglePause", methods={"POST"})
     * @return MultiResponse
     */
    public function setQueuePauseState() {
        if (empty($_POST['pause_state'])) {
            $this->core->addErrorMessage("Missing queue position pause state");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $this->core->getQueries()->setQueuePauseState($_POST['pause_state'] === 'true');
        $this->core->addSuccessMessage($_POST['pause_state'] === 'true' ? "Position in queue paused" : "Position in queue unpaused");
        $this->sendSocketMessage(['type' => 'queue_update']);
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/restore", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function restorePerson($queue_code) {
        if (empty($_POST['entry_id'])) {
            $this->core->addErrorMessage("Missing entry ID");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $this->core->getQueries()->restoreUserToQueue($_POST['entry_id']);
        $this->sendSocketMessage(['type' => 'queue_status_update']);
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/startHelp", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function startHelpPerson($queue_code) {
        if (empty($_POST['user_id'])) {
            $this->core->addErrorMessage("Missing user ID");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $this->core->getQueries()->startHelpUser($_POST['user_id'], $queue_code);
        $this->sendSocketMessage(['type' => 'queue_status_update']);
        $this->core->addSuccessMessage("Started helping student");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/finishHelp", methods={"POST"})
     * @return MultiResponse
     */
    public function finishHelpPerson($queue_code) {
        if (empty($_POST['user_id'])) {
            $this->core->addErrorMessage("Missing entry ID");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        if (!$this->core->getUser()->accessGrading() && $this->core->getUser()->getId() !== $_POST['user_id']) {
            $this->core->addErrorMessage("Permission denied to finish helping that person");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $remove_type = 'helped';
        if ($this->core->getUser()->getId() === $_POST['user_id']) {
            $remove_type = 'self_helped';//You helped yourself
        }


        $this->core->getQueries()->finishHelpUser($_POST['user_id'], $queue_code, $remove_type);
        $this->sendSocketMessage(['type' => 'full_update']);
        $this->core->addSuccessMessage("Finished helping student");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/empty", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function emptyQueue($queue_code) {
        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        Logger::logQueueActivity($this->core->getConfig()->getSemester(), $this->core->getDisplayedCourseName(), $queue_code, "EMPTIED");
        $this->core->getQueries()->emptyQueue($queue_code);
        $this->core->addSuccessMessage("Queue emptied");
        $this->sendSocketMessage(['type' => 'full_update']);
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/toggle", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function toggleQueue($queue_code) {
        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }
        if (!isset($_POST['queue_state'])) {//Must be set as isset because empty(0) will return false even though 0 is a value
            $this->core->addErrorMessage("Missing queue state");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }
        Logger::logQueueActivity($this->core->getConfig()->getSemester(), $this->core->getDisplayedCourseName(), $queue_code, $_POST['queue_state'] === "1" ? 'CLOSED' : 'OPENED');
        $this->core->getQueries()->toggleQueue($queue_code, $_POST['queue_state']);
        $this->core->addSuccessMessage(($_POST['queue_state'] === "1" ? 'Closed' : 'Opened') . ' queue: "' . $queue_code . '"');
        $this->sendSocketMessage(['type' => 'toggle_queue']);

        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/deleteQueue", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function deleteQueue($queue_code) {
        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $this->core->getQueries()->deleteQueue($queue_code);
        $this->core->addSuccessMessage("Queue deleted");
        $this->sendSocketMessage(['type' => 'full_update']);
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/change_token", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function changeToken($queue_code) {
        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }


        //Replace whitespace with "_"
        $token = trim($_POST['token']);
        $re = '/^[\sa-zA-Z0-9_\-]+$/m';
        preg_match_all($re, $token, $matches_token, PREG_SET_ORDER, 0);
        if (count($matches_token) !== 1) {
            $this->core->addErrorMessage('Queue secret code must only contain letters, numbers, spaces, "_", and "-"');
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $queue_code = trim($queue_code);
        $this->core->getQueries()->changeQueueToken($token, $queue_code);
        $this->core->addSuccessMessage("Queue Access Code Changed");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/{queue_code}/change_regex", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function changeRegex($queue_code) {
        if (empty($queue_code)) {
            $this->core->addErrorMessage("Missing queue name");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $regex_pattern = $_POST['regex'];

        $queue_code = trim($_POST['code']);
        $this->core->getQueries()->changeQueueRegex($regex_pattern, $queue_code);
        $this->core->addSuccessMessage("Queue Regex Pattern Changed");
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/current_queue", methods={"GET"})
     * @return MultiResponse
     */
    public function showCurrentQueue() {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['home']))
            );
        }

        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                'OfficeHoursQueue',
                'renderCurrentQueue',
                new OfficeHoursQueueModel($this->core)
            )
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/queue_history", methods={"GET"})
     * @return MultiResponse
     */
    public function showQueueHistory($full_history = false) {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['home']))
            );
        }

        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                'OfficeHoursQueue',
                'renderQueueHistory',
                new OfficeHoursQueueModel($this->core, $full_history)
            )
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/new_status", methods={"GET"})
     * @return MultiResponse
     */
    public function showNewStatus() {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['home']))
            );
        }

        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                'OfficeHoursQueue',
                'renderNewStatus',
                new OfficeHoursQueueModel($this->core)
            )
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/update_announcement", methods={"POST"})
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     * @return MultiResponse
     */
    public function updateAnnouncement() {
        if (!isset($_POST['queue_announcement_message'])) {
            $this->core->addErrorMessage("Missing announcement content");
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
            );
        }

        $config_json = $this->core->getConfig()->getCourseJson();
        $config_json['course_details']['queue_announcement_message'] = $_POST['queue_announcement_message'];
        if (!$this->core->getConfig()->saveCourseJson(['course_details' => $config_json['course_details']])) {
            return MultiResponse::JsonOnlyResponse(
                JsonResponse::getFailResponse('Could not save config file')
            );
        }
        $this->core->addSuccessMessage("Updated announcement");
        $this->sendSocketMessage(['type' => 'announcement_update']);
        return MultiResponse::RedirectOnlyResponse(
            new RedirectResponse($this->core->buildCourseUrl(['office_hours_queue']))
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/stats", methods={"GET"})
     */
    public function showQueueStats() {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            $this->core->addErrorMessage("Office hours queue disabled");
            return new RedirectResponse($this->core->buildCourseUrl(['home']));
        }

        $viewer = new OfficeHoursQueueModel($this->core);
        return new WebResponse(
            'OfficeHoursQueue',
            'showQueueStats',
            $viewer->getQueueDataOverall(),
            $viewer->getQueueDataToday(),
            $viewer->getQueueDataByWeekDayThisWeek(),
            $viewer->getQueueDataByWeekDay(),
            $viewer->getQueueDataByQueue(),
            $viewer->getQueueDataByWeekNumber()
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/new_announcement", methods={"GET"})
     */
    public function showNewAnnouncement() {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return MultiResponse::RedirectOnlyResponse(
                new RedirectResponse($this->core->buildCourseUrl(['home']))
            );
        }

        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return MultiResponse::webOnlyResponse(
            new WebResponse(
                'OfficeHoursQueue',
                'renderNewAnnouncement',
                new OfficeHoursQueueModel($this->core)
            )
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/office_hours_queue/student_stats", methods={"GET"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function showQueueStudentStats() {
        if (!$this->core->getConfig()->isQueueEnabled()) {
            return new RedirectResponse($this->core->buildCourseUrl(['home']));
        }

        $viewer = new OfficeHoursQueueModel($this->core);
        return new WebResponse(
            'OfficeHoursQueue',
            'showQueueStudentStats',
            $viewer->getQueueDataStudent()
        );
    }

    /**
     * this function opens a WebSocket client and sends a message with the corresponding update
     * @param array $msg_array
     */
    private function sendSocketMessage(array $msg_array): void {
        $msg_array['user_id'] = $this->core->getUser()->getId();
        $msg_array['page'] = $this->core->getConfig()->getSemester() . '-' . $this->core->getConfig()->getCourse() . "-office_hours_queue";
        try {
            $client = new Client($this->core);
            $client->send($msg_array);
        }
        catch (WebSocket\ConnectionException $e) {
            $this->core->addNoticeMessage("WebSocket Server is down, page won't load dynamically.");
        }
    }
}
