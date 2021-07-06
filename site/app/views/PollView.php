<?php

namespace app\views;

use app\entities\poll\Poll;
use app\entities\poll\Response;
use app\exceptions\OutputException;
use app\libraries\Core;
use app\libraries\Output;
use app\libraries\FileUtils;
use app\libraries\Utils;
use app\libraries\PollUtils;

class PollView extends AbstractView {
    public function __construct(Core $core, Output $output) {
        parent::__construct($core, $output);

        $this->core->getOutput()->addBreadcrumb("Polls", $this->core->buildCourseUrl(['polls']));
        $this->core->getOutput()->addInternalCss('polls.css');
        $this->core->getOutput()->enableMobileViewport();
    }

    /**
     *
     * @param Poll[] $todays_polls
     * @param Poll[] $older_polls
     * @param Poll[] $future_polls
     */
    public function showPollsInstructor(array $todays_polls, array $older_polls, array $future_polls, array $dropdown_states) {
        $this->core->getOutput()->addInternalJs('polls-dropdown.js');
        return $this->core->getOutput()->renderTwigTemplate("polls/AllPollsPageInstructor.twig", [
            'csrf_token' => $this->core->getCsrfToken(),
            'base_url' => $this->core->buildCourseUrl() . '/polls',
            'todays_polls' => $todays_polls,
            'older_polls' => $older_polls,
            'future_polls' => $future_polls,
            'dropdown_states' => $dropdown_states,
            'semester' => $this->core->getConfig()->getSemester(),
            'course' => $this->core->getConfig()->getCourse()
          ]);
    }

    /**
     * @param Poll[] $todays_polls
     * @param Poll[] $older_polls
     */
    public function showPollsStudent(array $todays_polls, array $older_polls) {
        $this->core->getOutput()->addInternalJs('polls-dropdown.js');
        return $this->core->getOutput()->renderTwigTemplate("polls/AllPollsPageStudent.twig", [
            'csrf_token' => $this->core->getCsrfToken(),
            'base_url' => $this->core->buildCourseUrl() . '/polls',
            'todays_polls' => $todays_polls,
            'older_polls' => $older_polls,
            'user_id' => $this->core->getUser()->getId(),
          ]);
    }

    /**
     * @param Response[] $responses
     */
    public function showPollStudent(Poll $poll, array $responses) {
        $this->core->getOutput()->addBreadcrumb("View Poll");
        $image_path = $poll->getImagePath();
        $file_data = null;
        if ($image_path !== null) {
            $file_data = base64_encode(file_get_contents($image_path));
            $file_data = 'data: ' . mime_content_type($image_path) . ';charset=utf-8;base64,' . $file_data;
        }
        $poll_type = PollUtils::isSingleResponse($poll->getQuestionType()) ? "single-response" : "multiple-response";

        $response_option_ids = [];
        foreach ($responses as $response) {
            $response_option_ids[] = $response->getOption()->getId();
        }

        return $this->core->getOutput()->renderTwigTemplate("polls/PollPageStudent.twig", [
            'csrf_token' => $this->core->getCsrfToken(),
            'base_url' => $this->core->buildCourseUrl() . '/polls',
            'poll' => $poll,
            'poll_type' => $poll_type,
            'responses' => $response_option_ids,
            'file_data' => $file_data
          ]);
    }

    public function pollForm(?Poll $poll = null) {
        $this->core->getOutput()->addBreadcrumb($poll !== null ? "Edit Poll" : 'New Poll');
        $this->core->getOutput()->addInternalJs('polls.js');
        $this->core->getOutput()->addVendorJs(FileUtils::joinPaths('flatpickr', 'flatpickr.min.js'));
        $this->core->getOutput()->addVendorCss(FileUtils::joinPaths('flatpickr', 'flatpickr.min.css'));
        $this->core->getOutput()->addVendorJs(FileUtils::joinPaths('flatpickr', 'plugins', 'shortcutButtons', 'shortcut-buttons-flatpickr.min.js'));
        $this->core->getOutput()->addVendorCss(FileUtils::joinPaths('flatpickr', 'plugins', 'shortcutButtons', 'themes', 'light.min.css'));
        $poll_type = $poll !== null && PollUtils::isSingleResponse($poll->getQuestionType()) ? "single-response" : "multiple-response";
        return $this->core->getOutput()->renderTwigTemplate("polls/PollForm.twig", [
            'csrf_token' => $this->core->getCsrfToken(),
            'base_url' => $this->core->buildCourseUrl() . '/polls',
            'poll' => $poll,
            'max_size' => Utils::returnBytes(ini_get('upload_max_filesize')),
            'poll_type' => $poll_type
          ]);
    }

    public function viewResults(Poll $poll) {
        $this->core->getOutput()->addBreadcrumb("View Results");
        $this->core->getOutput()->addVendorJs(FileUtils::joinPaths('plotly', 'plotly.js'));
        return $this->core->getOutput()->renderTwigTemplate("polls/ViewPollResults.twig", [
            'csrf_token' => $this->core->getCsrfToken(),
            'base_url' => $this->core->buildCourseUrl() . '/polls',
            'poll' => $poll,
          ]);
    }
}
