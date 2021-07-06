<?php

namespace app\controllers;

use app\entities\poll\Option;
use app\entities\poll\Poll;
use app\entities\poll\Response;
use app\libraries\Core;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\response\JsonResponse;
use app\libraries\response\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\routers\AccessControl;
use app\libraries\FileUtils;
use app\libraries\Utils;
use app\libraries\PollUtils;
use app\views\PollView;

class PollController extends AbstractController {
    public function __construct(Core $core) {
        parent::__construct($core);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls", methods={"GET"})
     */
    public function showPollsPage(): WebResponse {
        /** @var \app\repositories\PollRepository */
        $repo = $this->core->getCourseEntityManager()->getRepository(Poll::class);

        if ($this->core->getUser()->accessAdmin()) {
            // Check if we have a saved cookie session with the dropdown states for each of the instructor polls sections
            $dropdown_states = ['today' => true, 'old' => false, 'future' => false];
            foreach ($dropdown_states as $key => $val) {
                $cookie_key = $key . "_polls_dropdown";
                if (array_key_exists($cookie_key, $_COOKIE)) {
                    $dropdown_states[$key] = $_COOKIE[$cookie_key] === 'true';
                }
            }

            return new WebResponse(
                PollView::class,
                'showPollsInstructor',
                // release_date = ? order by name
                $repo->findByToday(),
                $repo->findByOld(),
                $repo->findByFuture(),
                $dropdown_states,
            );
        }
        else {
            return new WebResponse(
                PollView::class,
                'showPollsStudent',
                $repo->findByToday(),
                $repo->findByOld(),
            );
        }
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/viewPoll/{poll_id}", methods={"GET"}, requirements={"poll_id": "\d*", })
     * @return RedirectResponse|WebResponse
     */
    public function showPoll($poll_id) {
        if (!isset($poll_id)) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        /** @var Poll|null */
        $poll = $this->core->getCourseEntityManager()->find(Poll::class, $poll_id);
        $responses = $this->core->getCourseEntityManager()->getRepository(Response::class)->findBy([
            'poll' => $poll,
            'student_id' => $this->core->getUser()->getId()
        ]);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        if ($this->core->getUser()->accessAdmin()) {
            // TODO: add a admin view for viewPoll?
            /*
            return new WebResponse(
                PollView::class,
                'showPollInstructor',
                $poll
            );
            */
        }
        return new WebResponse(
            PollView::class,
            'showPollStudent',
            $poll,
            $responses
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/newPoll", methods={"GET"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function showNewPollPage(): WebResponse {
        return new WebResponse(
            PollView::class,
            'pollForm'
        );
    }


    /**
     * @Route("/courses/{_semester}/{_course}/polls/newPoll", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function addNewPoll(): RedirectResponse {
        $em = $this->core->getCourseEntityManager();

        $fields = ['name', 'question', 'question_type', 'release_date'];
        foreach ($fields as $field) {
            if (empty($_POST[$field])) {
                $this->core->addErrorMessage("Poll must fill out all fields");
                return new RedirectResponse($this->core->buildCourseUrl(['polls']));
            }
        }
        $date = \DateTime::createFromFormat("Y-m-d", $_POST["release_date"]);
        if ($date === false) {
            $this->core->addErrorMessage("Invalid poll release date");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        if (!in_array($_POST["question_type"], PollUtils::getPollTypes())) {
            $this->core->addErrorMessage("Invalid poll question type");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $poll = new Poll($_POST['name'], $_POST['question'], $_POST['question_type'], $date);
        $em->persist($poll);

        // Need to run this after persist so that we can use getId() below
        if (isset($_FILES['image_file']) && $_FILES["image_file"]["name"] !== "") {
            // validate the uploaded file size
            $status = FileUtils::validateUploadedFiles($_FILES["image_file"]);
            if (!$status[0]["success"]) {
                $this->core->addErrorMessage("Failed to validate poll image: " . $status[0]["error"]);
            }
            else {
                $file = $_FILES["image_file"];
                // validate the uploaded file type is indeed an image
                if (!FileUtils::isValidImage($file["tmp_name"])) {
                    $this->core->addErrorMessage("Error: " . $file["name"] . " is not a valid image file. File was not successfully attached to poll '" . $_POST["name"] . "'.");
                }
                else {
                    $file_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "polls", "poll_image_" . $poll->getId() . "_" . $file["name"]);
                    move_uploaded_file($file["tmp_name"], $file_path);
                    $poll->setImagePath($file_path);
                }
            }
        }

        $answers = 0;

        foreach ($_POST['option'] as $option) {
            if (!isset($option['order']) || !isset($option['response'])) {
                $this->core->addErrorMessage("Error occured in adding poll");
                return new RedirectResponse($this->core->buildCourseUrl(['polls']));
            }
            $option = new Option((int)$option['order'], $option['response'], isset($option['is_correct']) && $option['is_correct'] === 'on');
            if ($option->isCorrect()) {
                $answers++;
            }
            $poll->addOption($option);
            $em->persist($option);
        }

        if ($answers === 0) {
            $this->core->addErrorMessage("Polls must have at least one correct response");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        elseif ($_POST["question_type"] === "single-response-single-correct" && $answers > 1) {
            $this->core->addErrorMessage("Polls of type 'single-response-single-correct' must have exactly one correct response");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        elseif (in_array($_POST['question_type'], ["single-response-survey", "multiple-response-survey"]) && $answers !== count($poll->getOptions())) {
            $this->core->addErrorMessage("All responses of polls of type 'survey' must be marked at correct responses");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $em->flush();

        $this->core->addSuccessMessage("Poll successfully added");
        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/editPoll/{poll_id}", methods={"GET"}, requirements={"poll_id": "\d*", })
     * @AccessControl(role="INSTRUCTOR")
     * @return RedirectResponse|WebResponse
     */
    public function editPoll($poll_id) {
        if (!isset($poll_id)) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $poll = $this->core->getCourseEntityManager()->find(Poll::class, $poll_id);

        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        return new WebResponse(
            PollView::class,
            'pollForm',
            $poll
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/editPoll/submitEdits", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function submitEdits(): RedirectResponse {
        $em = $this->core->getCourseEntityManager();

        $returnUrl = $this->core->buildCourseUrl(['polls']);
        $poll_id = (int)$_POST['poll_id'];
        if (empty($poll_id)) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($returnUrl);
        }

        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);

        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($returnUrl);
        }

        $fields = ['name', 'question', 'question_type', 'release_date'];
        foreach ($fields as $field) {
            if (empty($_POST[$field])) {
                $this->core->addErrorMessage("Poll must fill out all fields");
                return new RedirectResponse($this->core->buildCourseUrl(['polls']));
            }
        }


        $date = \DateTime::createFromFormat("Y-m-d", $_POST["release_date"]);
        if ($date === false) {
            $this->core->addErrorMessage("Invalid poll release date");
            return new RedirectResponse($returnUrl);
        }
        if (!in_array($_POST["question_type"], PollUtils::getPollTypes())) {
            $this->core->addErrorMessage("Invalid poll question type");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $poll->setName($_POST['name']);
        $poll->setQuestion($_POST['question']);
        $poll->setQuestionType($_POST['question_type']);
        $poll->setReleaseDate($date);

        if (isset($_FILES['image_file']) && $_FILES["image_file"]["name"] !== "") {
            $file = $_FILES["image_file"];
            // validate file size
            $status = FileUtils::validateUploadedFiles($file);
            if (!$status[0]["success"]) {
                $this->core->addErrorMessage("Failed to validate uploads " . $status[0]["error"]);
            }
            elseif (!FileUtils::isValidImage($file["tmp_name"])) {
                // validate file type
                $this->core->addErrorMessage("Error: " . $file["name"] . " is not a valid image file. Image was not successfully updated in poll '" . $_POST["name"] . "'.");
                // reject the new image, but keep the old one
            }
            else {
                $current_file_path = $poll->getImagePath();
                if ($current_file_path !== null) {
                    unlink($current_file_path);
                }
                $file_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "polls", "poll_image_" . $_POST["poll_id"] . "_" . $_FILES["image_file"]["name"]);
                move_uploaded_file($file["tmp_name"], $file_path);
                $poll->setImagePath($file_path);
            }
        }
        elseif (!isset($_POST['keep_image'])) {
            $poll->setImagePath(null);
        }

        $keep_ids = [];

        $answers = 0;

        foreach ($_POST['option'] as $option) {
            if (!isset($option['order']) || !isset($option['response'])) {
                $this->core->addErrorMessage("Error occured in adding poll");
                return new RedirectResponse($this->core->buildCourseUrl(['polls']));
            }
            $id = (int)$option['id'];
            if (!empty($id)) {
                $keep_ids[] = $id;
                $found = false;
                foreach ($poll->getOptions() as $poll_option) {
                    if ($poll_option->getId() === $id) {
                        $poll_option->setOrderId((int)$option['order']);
                        $poll_option->setResponse($option['response']);
                        $poll_option->setCorrect(isset($option['is_correct']) && $option['is_correct'] === 'on');
                        if ($poll_option->isCorrect()) {
                            $answers++;
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $option = new Option((int)$option['order'], $option['response'], isset($option['is_correct']) && $option['is_correct'] === 'on');
                    if ($option->isCorrect()) {
                        $answers++;
                    }
                    $poll->addOption($option);
                    $em->persist($option);
                }
            }
        }

        foreach ($poll->getOptions() as $poll_option) {
            if (!in_array($poll_option->getId(), $keep_ids)) {
                if ($poll_option->hasUserResponses()) {
                    $this->core->addErrorMessage("Error occured in editing poll: attempt to delete response option that has already been submitted as an answer");
                    return new RedirectResponse($returnUrl);
                }
                $poll->removeOption($option);
                $em->remove($option);
            }
        }

        if ($answers === 0) {
            $this->core->addErrorMessage("Polls must have at least one correct response");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        elseif ($_POST["question_type"] === "single-response-single-correct" && $answers > 1) {
            $this->core->addErrorMessage("Polls of type 'single-response-single-correct' must have exactly one correct response");
            new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        elseif ((($_POST["question_type"] === "single-response-survey") || ($_POST["question_type"] === "multiple-response-survey")) && $answers !== count($poll->getOptions())) {
            $this->core->addErrorMessage("All responses of polls of type 'survey' must be marked at correct responses");
            new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $em->flush();

        $this->core->addSuccessMessage("Poll successfully edited");
        return new RedirectResponse($returnUrl);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/setOpen", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function openPoll(): RedirectResponse {
        $poll_id = (int) $_POST['poll_id'] ?? 0;
        $em = $this->core->getCourseEntityManager();
        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $poll->setOpen();
        $em->flush();

        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/setEnded", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     * @return MultiResponse
     */
    public function endPoll(): RedirectResponse {
        $poll_id = (int) $_POST['poll_id'] ?? 0;
        $em = $this->core->getCourseEntityManager();
        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $poll->setEnded();
        $em->flush();

        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/setClosed", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function closePoll(): RedirectResponse {
        $poll_id = (int) $_POST['poll_id'] ?? 0;
        $em = $this->core->getCourseEntityManager();
        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $poll->setClosed();
        $em->flush();

        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/updateDropdownStates", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function updateDropdownStates(): JsonResponse {
        $expire_time = time() + (7 * 24 * 60 * 60); // 7 days from now
        Utils::setCookie($_POST["cookie_key"], $_POST["new_state"], $expire_time);
        return JsonResponse::getSuccessResponse($_COOKIE[$_POST["cookie_key"]]);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/submitResponse", methods={"POST"})
     */
    public function submitResponse(): RedirectResponse {
        $em = $this->core->getCourseEntityManager();

        $poll_id = (int)$_POST['poll_id'];
        if (empty($poll_id)) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        if (!$poll->isOpen()) {
            $this->core->addErrorMessage("Poll is closed");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        if (!array_key_exists("answers", $_POST) && PollUtils::isSingleResponse($poll->getQuestionType())) {
            // Answer must be given for single-response ("no response" counts as a reponse)
            $this->core->addErrorMessage("No answer given");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }

        $user_id = $this->core->getUser()->getId();

        /** @var Response[] */
        $responses = $em->getRepository(Response::class)->findBy([
            'poll' => $poll,
            'student_id' => $user_id
        ]);

        foreach ($responses as $response) {
            $em->remove($response);
        }
        if (
            (PollUtils::isSingleResponse($poll->getQuestionType()) && $_POST['answers'][0] !== '-1')
            || (!PollUtils::isSingleResponse($poll->getQuestionType()) && array_key_exists("answers", $_POST))
        ) {
            foreach ($_POST['answers'] as $option_id) {
                $response = new Response($user_id);
                $poll->addResponse($response, $option_id);
                $em->persist($response);
            }
        }

        $em->flush();

        $this->core->addSuccessMessage("Poll response recorded");
        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/deletePoll", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function deletePoll(): JsonResponse {
        $poll_id = (int) $_POST['poll_id'] ?? 0;
        $em = $this->core->getCourseEntityManager();
        /** @var Poll|null */
        $poll = $em->find(Poll::class, $poll_id);
        if ($poll === null) {
            return JsonResponse::getFailResponse('Invalid Poll ID');
        }
        if ($poll->getImagePath() !== null) {
            unlink($poll->getImagePath());
        }
        foreach ($poll->getResponses() as $response) {
            $em->remove($response);
        }
        foreach ($poll->getOptions() as $option) {
            $em->remove($option);
        }
        $em->remove($poll);
        $em->flush();

        return JsonResponse::getSuccessResponse();
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/viewResults/{poll_id}", methods={"GET"}, requirements={"poll_id": "\d*", })
     * @AccessControl(role="INSTRUCTOR")
     * @return RedirectResponse|WebResponse
     */
    public function viewResults($poll_id) {
        if (!isset($poll_id)) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $poll = $this->core->getCourseEntityManager()->find(Poll::class, $poll_id);
        if ($poll === null) {
            $this->core->addErrorMessage("Invalid Poll ID");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        return new WebResponse(
            PollView::class,
            'viewResults',
            $poll
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/hasAnswers", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function hasAnswers() {
        $option_id  = (int)$_POST['option_id'];
        if (empty($option_id)) {
            return JsonResponse::getFailResponse('Invalid option id');
        }
        /** @var Option|null */
        $option = $this->core->getCourseEntityManager()->find(Option::class, $option_id);
        if ($option === null) {
            return JsonResponse::getFailResponse('Invalid poll id');
        }
        return JsonResponse::getSuccessResponse($option->hasUserResponses());
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/export", methods={"GET"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function getPollExportData() {
        /** @var Poll[] */
        $polls = $this->core->getCourseEntityManager()->getRepository(Poll::class)->findAll();
        $file_name = date("Y-m-d") . "_" . $this->core->getConfig()->getSemester() . "_" . $this->core->getConfig()->getCourse() . "_" . "poll_questions" . ".json";
        $data = FileUtils::encodeJson(PollUtils::getPollExportData($polls));
        if ($data === false) {
            $this->core->addErrorMessage("Failed to export poll data. Please try again");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        header("Content-type: " . "application/json");
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        $this->core->getOutput()->renderString($data);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/polls/import", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function importPollsFromJSON(): RedirectResponse {
        $em = $this->core->getCourseEntityManager();
        $filename = $_FILES["polls_file"]["tmp_name"];
        $polls = FileUtils::readJsonFile($filename);
        if ($polls === false) {
            $this->core->addErrorMessage("Failed to read file. Make sure the file is the right format");
            return new RedirectResponse($this->core->buildCourseUrl(['polls']));
        }
        $num_imported = 0;
        $num_errors = 0;
        $question_type = null;
        foreach ($polls as $poll) {
            if (
                !array_key_exists("name", $poll)
                || !array_key_exists("question", $poll)
                || !array_key_exists("responses", $poll)
                || !array_key_exists("correct_responses", $poll)
                || !array_key_exists("release_date", $poll)
            ) {
                $num_errors = $num_errors + 1;
                continue;
            }
            /*  Polls that were exported before this feature was
                implemented don't have this data. At the time, there
                only existed questions of type single reponse. */
            $question_type = array_key_exists("question_type", $poll) ? $poll['question_type'] : 'single-response-multiple-correct';
            $poll = new Poll($poll['name'], $poll['question'], $question_type, \DateTime::createFromFormat("Y-m-d", $poll['release_date']));
            $em->persist($poll);
            $order = 0;
            foreach ($poll['responses'] as $id => $response) {
                $option = new Option($order, $response, in_array($id, $poll['correct_responses']));
                $poll->addOption($option);
                $em->persist($option);
                $order++;
            }
            $num_imported = $num_imported + 1;
        }

        $em->flush();

        if ($num_errors === 0) {
            $this->core->addSuccessMessage("Successfully imported " . $num_imported . " polls");
        }
        else {
            $this->core->addErrorMessage("Successfully imported " . $num_imported . " polls. Errors occurred in " . $num_errors . " polls");
        }
        return new RedirectResponse($this->core->buildCourseUrl(['polls']));
    }
}
