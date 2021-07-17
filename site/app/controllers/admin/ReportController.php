<?php

namespace app\controllers\admin;

use app\controllers\AbstractController;
use app\libraries\DateUtils;
use app\libraries\FileUtils;
use app\libraries\GradeableType;
use app\libraries\routers\AccessControl;
use app\libraries\response\MultiResponse;
use app\libraries\response\WebResponse;
use app\libraries\PollUtils;
use app\models\gradeable\AutoGradedGradeable;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\LateDayInfo;
use app\models\gradeable\LateDays;
use app\models\gradeable\Mark;
use app\models\gradeable\Submitter;
use app\models\User;
use app\models\PollModel;
use Symfony\Component\Routing\Annotation\Route;
use app\models\RainbowCustomization;
use app\exceptions\ValidationException;

/**
 * Class ReportController
 * @package app\controllers\admin
 * @AccessControl(role="INSTRUCTOR")
 */
class ReportController extends AbstractController {

    const MAX_AUTO_RG_WAIT_TIME = 45;       // Time in seconds a call to autoRainbowGradesStatus should
                                            // wait for the job to complete before timing out and returning failure

    private $all_overrides = [];

    /**
     * @Route("/courses/{_semester}/{_course}/reports")
     */
    public function showReportPage() {
        if (!$this->core->getUser()->accessAdmin()) {
            $this->core->getOutput()->showError("This account cannot access admin pages");
        }

        $grade_summaries_last_run = $this->getGradeSummariesLastRun();
        $this->core->getOutput()->enableMobileViewport();

        $this->core->getOutput()->renderOutput(['admin', 'Report'], 'showReportUpdates', $grade_summaries_last_run);
    }

    /**
     * Generates grade summary files for every user
     *
     * @Route("/courses/{_semester}/{_course}/reports/summaries")
     * @Route("/api/courses/{_semester}/{_course}/reports/summaries", methods={"POST"})
     */
    public function generateGradeSummaries() {
        if (!$this->core->getUser()->accessAdmin()) {
            $this->core->getOutput()->showError("This account cannot access admin pages");
        }

        $base_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), 'reports', 'all_grades');

        // Check that the directory is writable, fail if not
        if (!is_writable($base_path)) {
            $this->core->addErrorMessage('Unable to write to the grade summaries directory');
            $this->core->redirect($this->core->buildCourseUrl(['reports']));
        }

        $poll_base_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), 'reports', 'polls');

        // Check that the directory is writable, fail if not
        if ($this->core->getConfig()->isPollsEnabled() && !is_writable($poll_base_path)) {
            $this->core->addErrorMessage('Unable to write to the poll summary directory');
            $this->core->redirect($this->core->buildCourseUrl(['reports']));
        }

        $g_sort_keys = [
            'type',
            'CASE WHEN submission_due_date IS NOT NULL THEN submission_due_date ELSE g.g_grade_released_date END',
            'g_id',
        ];
        $gg_sort_keys = [
            'user_id',
        ];

        // Generate the reports
        $this->generateReportInternal($g_sort_keys, $gg_sort_keys, function ($a, $b, $c, $d) use ($base_path) {
            $this->saveUserToFile($base_path, $a, $b, $c, $d);
            return null;
        });

        if ($this->core->getConfig()->isPollsEnabled()) {
            $this->generatePollSummaryInternal($poll_base_path);
        }

        $this->core->addSuccessMessage("Successfully Generated Grade Summaries");
        $this->core->redirect($this->core->buildCourseUrl(['reports']));
        return $this->core->getOutput()->renderJsonSuccess();
    }


    public function getGradeSummariesLastRun() {

        // Build path to the grade summaries folder
        $summaries_dir = $this->core->getConfig()->getCoursePath() . '/reports/all_grades';

        // Get contents of directory
        $files = scandir($summaries_dir);

        // Get file count
        // Subtract 2 to account for '.' and '..'
        $file_count = count($files) - 2;

        // If folder is empty return never
        if ($file_count == 0) {
            return 'Never';
        }
        else {
            // Else folder has contents return the time stamp off the first file
            // Get file modification time of first student json
            $time_stamp = filemtime($summaries_dir . '/' . $files[2]);

            // Format it
            $time_stamp = new \DateTime("@$time_stamp");
            $time_stamp->setTimezone($this->core->getConfig()->getTimezone());

            return DateUtils::convertTimeStamp($this->core->getUser(), $time_stamp->format('c'), $this->core->getConfig()->getDateTimeFormat()->getFormat('gradeable'));
        }
    }

    /**
     * Generates and offers download of CSV grade report
     *
     * @Route("/courses/{_semester}/{_course}/reports/csv")
     */
    public function generateCSVReport() {
        if (!$this->core->getUser()->accessAdmin()) {
            $this->core->getOutput()->showError("This account cannot access admin pages");
        }

        $g_sort_keys = [
            'syllabus_bucket',
            'g_id',
        ];
        $gg_sort_keys = [
            'registration_section',
            'user_id',
        ];

        // Generate the reports
        $rows = $this->generateReportInternal($g_sort_keys, $gg_sort_keys, function (User $a, array $b, LateDays $c) {
            return $this->generateCSVRow($a, $b, $c);
        });

        // Concatenate the CSV
        $csv = '';
        if (count($rows) > 0) {
            // Header row
            $csv = implode(',', array_keys(reset($rows))) . PHP_EOL;
            // Content rows
            foreach ($rows as $row) {
                $csv .= implode(',', $row) . PHP_EOL;
            }
        }

        //Send csv data to file download.  Filename: "{course}_csvreport_{date/time stamp}.csv"
        $this->core->getOutput()->renderFile($csv, $this->core->getConfig()->getCourse() . "_csvreport_" . date("ymdHis") . ".csv");
    }

    /**
     * Loads all team graded gradeables
     * @param Gradeable[] $team_gradeables
     * @return array array, indexed by gradeable id, of arrays, indexed by user id, of team graded gradeables
     */
    private function cacheTeamGradedGradeables(array $team_gradeables) {
        // Array of team graded gradeables, first indexed by gradeable id, then by user id (so multiple entries per team)
        $team_graded_gradeables = [];

        // Get the team gradeables first and, unfortunately, fully cache them
        foreach ($this->core->getQueries()->getGradedGradeables($team_gradeables) as $gg) {
            /** @var GradedGradeable $gg */
            foreach ($gg->getSubmitter()->getTeam()->getMemberUserIds() as $user_id) {
                $team_graded_gradeables[$gg->getGradeableId()][$user_id] = $gg;
            }
        }

        return $team_graded_gradeables;
    }

    /**
     * Fetches all gradeables from the database and splits them based on teamness
     * @param array|null $sort_keys the keys used to sort the gradeables
     * @return array [gradeables, user gradeables, team gradeables]
     */
    private function getSplitGradeables($sort_keys) {
        $user_gradeables = [];
        $team_gradeables = [];
        $all_gradeables = [];
        foreach ($this->core->getQueries()->getGradeableConfigs(null, $sort_keys) as $g) {
            $all_gradeables[] = $g;
            if ($g->isTeamAssignment()) {
                $team_gradeables[] = $g;
            }
            else {
                $user_gradeables[] = $g;
            }
        }
        return [$all_gradeables, $user_gradeables, $team_gradeables];
    }

    private function genDummyGradedGradeable(Gradeable $gradeable, Submitter $submitter) {
        $gg = new GradedGradeable($this->core, $gradeable, $submitter, []);
        $gg->setAutoGradedGradeable(new AutoGradedGradeable($this->core, $gg, []));
        return $gg;
    }

    /**
     * Merges user and team graded gradeables for a user
     * @param Gradeable[] $gradeables
     * @param User $user
     * @param GradedGradeable[] $user_graded_gradeables User graded gradeables indexed by gradeable id
     * @param array $team_graded_gradeables See cacheTeamGradedGradeables
     * @return GradedGradeable[] array of graded gradeables in the order of $gradeables, indexed by gradeable id.
     *  NOTE: If there are any team gradeables that user has no team for, they get a new, blank gradeable with the user as the submitter
     */
    private function mergeGradedGradeables(array $gradeables, User $user, array $user_graded_gradeables, array $team_graded_gradeables) {
        $ggs = [];
        foreach ($gradeables as $g) {
            /** @var Gradeable $g */
            if ($g->isTeamAssignment()) {
                // if the user doesn't have a team, MAKE THE USER A SUBMITTER
                $graded_gradeable = $team_graded_gradeables[$g->getId()][$user->getId()] ?? $this->genDummyGradedGradeable($g, new Submitter($this->core, $user));
            }
            else {
                $graded_gradeable = $user_graded_gradeables[$g->getId()];
            }

            $graded_gradeable->setOverriddenGrades($this->all_overrides[$user->getId()][$graded_gradeable->getGradeableId()] ?? null);
            $ggs[] = $graded_gradeable;
        }
        return $ggs;
    }

    /**
     * A general purpose function for generating reports for all users, grouped by user
     * @param string[]|null $gradeable_sort_keys
     * @param string[]|null $graded_gradeable_sort_keys
     * @param \Closure $per_user_callback callback that accepts (User, GradedGradeable[], LateDays)
     * @return array list of results of $per_user_callback, indexed by user id
     */
    private function generateReportInternal($gradeable_sort_keys, $graded_gradeable_sort_keys, $per_user_callback) {
        /** @var User $current_user */
        $current_user = null;
        $results = [];

        // get all gradeables and cache team graded gradeables
        [$all_gradeables, $user_gradeables, $team_gradeables] = $this->getSplitGradeables($gradeable_sort_keys);
        $team_graded_gradeables = $this->cacheTeamGradedGradeables($team_gradeables);

        //Gradeable iterator will append one gradeable score per loop pass.
        $user_graded_gradeables = [];

        $all_late_days = [];
        foreach ($this->core->getQueries()->getLateDayUpdates(null) as $row) {
            if (!isset($all_late_days[$row['user_id']])) {
                $all_late_days[$row['user_id']] = [];
            }
            $all_late_days[$row['user_id']][] = $row;
        }

        $all_polls = $this->core->getQueries()->getPolls();

        $this->all_overrides = $this->core->getQueries()->getAllOverriddenGrades();

        // Method to call the callback with the required parameters
        $call_callback = function ($all_gradeables, User $current_user, $user_graded_gradeables, $team_graded_gradeables, $per_user_callback) use ($all_late_days, $all_polls) {
            $ggs = $this->mergeGradedGradeables($all_gradeables, $current_user, $user_graded_gradeables, $team_graded_gradeables);
            $late_days = new LateDays($this->core, $current_user, $ggs, $all_late_days[$current_user->getId()] ?? []);
            return $per_user_callback($current_user, $ggs, $late_days, $all_polls);
        };
        foreach ($this->core->getQueries()->getGradedGradeables($user_gradeables, null, null, $graded_gradeable_sort_keys) as $gg) {
            /** @var GradedGradeable $gg */
            if ($current_user === null || $current_user->getId() !== $gg->getSubmitter()->getId()) {
                if ($current_user !== null) {
                    // Previous pass completed an entire row
                    $results[$current_user->getId()] = $call_callback($all_gradeables, $current_user, $user_graded_gradeables, $team_graded_gradeables, $per_user_callback);
                }

                //Prepare for the new user
                $current_user = $gg->getSubmitter()->getUser();
                $user_graded_gradeables = [];
            }
            $user_graded_gradeables[$gg->getGradeableId()] = $gg;
        }

        // If there are only team gradeables, check to see that all users were properly covered
        //  If there are users on no teams, then they won't get a result from the above loop...
        if (count($all_gradeables) > 0 && count($user_gradeables) === 0) {
            //... So we must find them and give them dummy results
            foreach ($this->core->getQueries()->getAllUsers() as $u) {
                if (!isset($results[$u->getId()])) {
                    // This user had no results, so generate results
                    $ggs = $this->mergeGradedGradeables($all_gradeables, $u, [], $team_graded_gradeables);
                    $late_days = new LateDays($this->core, $u, $ggs, $all_late_days[$u->getId()] ?? []);
                    $results[$u->getId()] = $per_user_callback($u, $ggs, $late_days, $all_polls);
                }
            }
        }

        // Remember to do the callback on the last user
        if ($current_user !== null) {
            $results[$current_user->getId()] = $call_callback($all_gradeables, $current_user, $user_graded_gradeables, $team_graded_gradeables, $per_user_callback);
        }
        return $results;
    }

    /**
     * Generates a CSV row for a user
     * @param User $user The user the grades are for
     * @param GradedGradeable[] The list of graded gradeables, indexed by gradeable id
     * @param LateDays $late_days The late day info for these graded gradeables
     * @return array
     */
    private function generateCSVRow(User $user, array $ggs, LateDays $late_days) {
        $row = [];

        $row['User ID'] = $user->getId();
        $row['First Name'] = $user->getDisplayedFirstName();
        $row['Last Name'] = $user->getDisplayedLastName();
        $row['Registration Section'] = $user->getRegistrationSection();

        foreach ($ggs as $gg) {
            /** @var GradedGradeable $gg */
            //Append one gradeable score to row.  Scores are indexed by gradeable's ID.
            $row[$gg->getGradeableId()] = $gg->getTotalScore();

            if (!$gg->hasOverriddenGrades()) {
                // Check if the score should be a zero
                if ($gg->getGradeable()->getType() === GradeableType::ELECTRONIC_FILE) {
                    if ($gg->getGradeable()->isTaGrading() && ($gg->getOrCreateTaGradedGradeable()->hasVersionConflict() || !$gg->isTaGradingComplete())) {
                        // Version conflict or incomplete grading, so zero score
                        $row[$gg->getGradeableId()] = 0;
                    }
                    elseif ($late_days->getLateDayInfoByGradeable($gg->getGradeable())->getStatus() === LateDayInfo::STATUS_BAD) {
                        // BAD submission, so zero score
                        $row[$gg->getGradeableId()] = 0;
                    }
                }
            }
        }
        return $row;
    }

    /**
     * Saves all user data to a file
     * @param string $base_path the base path to store the reports
     * @param User $user The user the report is for
     * @param GradedGradeable[] The list of graded gradeables, indexed by gradeable id
     * @param LateDays $late_days The late day info for these graded gradeables
     */
    private function saveUserToFile(string $base_path, User $user, array $ggs, LateDays $late_days, array $polls) {

        $user_data = [];
        $user_data['user_id'] = $user->getId();
        $user_data['legal_first_name'] = $user->getLegalFirstName();
        $user_data['preferred_first_name'] = $user->getPreferredFirstName();
        $user_data['legal_last_name'] = $user->getLegalLastName();
        $user_data['preferred_last_name'] = $user->getPreferredLastName();
        $user_data['registration_section'] = $user->getRegistrationSection();
        $user_data['rotating_section'] = $user->getRotatingSection();
        $user_data['default_allowed_late_days'] = $this->core->getConfig()->getDefaultStudentLateDays();
        $user_data['last_update'] = date("l, F j, Y");

        foreach ($ggs as $gg) {
            $bucket = ucwords($gg->getGradeable()->getSyllabusBucket());
            $user_data[$bucket][] = $this->generateGradeSummary($gg, $user, $late_days);
        }

        file_put_contents(FileUtils::joinPaths($base_path, $user->getId() . '_summary.json'), FileUtils::encodeJson($user_data));
    }

    /**
     * Generates a summary of all polls over a semester if polling is enabled
     * @param string $base_path the base path to store the report
     */
    private function generatePollSummaryInternal(string $base_path): void {
        $polls = $this->core->getQueries()->getPolls();
        $polls_data = [];
        foreach ($polls as $poll) {
            $polls_data[] = [
                "id" => $poll->getId(),
                "responses" => $poll->getUserResponses()
            ];
        }
        FileUtils::writeJsonFile(FileUtils::joinPaths($base_path, "poll_responses.json"), $polls_data);
        FileUtils::writeJsonFile(FileUtils::joinPaths($base_path, "poll_questions.json"), PollUtils::getPollExportData($polls));
    }

    /**
     * Generates a grade summary entry for a graded gradeable
     * @param GradedGradeable $gg
     * @param LateDays $ld
     * @return array
     */
    public function generateGradeSummary(GradedGradeable $gg, User $user, LateDays $ld) {
        $g = $gg->getGradeable();

        $entry = [
            'id' => $g->getId(),
            'name' => $g->getTitle(),
            'gradeable_type' => GradeableType::typeToString($g->getType()),
            'grade_released_date' => $g->hasReleaseDate() ? $g->getGradeReleasedDate()->format('Y-m-d H:i:s O') : $g->getSubmissionOpenDate()->format('Y-m-d H:i:s O'),
        ];

        // Add team members to output
        if ($g->isTeamAssignment()) {
            $entry['team_members'] = $gg->getSubmitter()->isTeam() ? $gg->getSubmitter()->getTeam()->getMemberUserIds()
                : $gg->getSubmitter()->getId(); // If the user isn't on a team, the only member is themselves
        }

        $entry['score'] = $gg->getTotalScore();

        $ldi = $ld->getLateDayInfoByGradeable($g);

        if ($gg->hasOverriddenGrades()) {
            $entry['status'] = 'Overridden';
            $entry['comment'] = $gg->getOverriddenComment();
        }
        else {
            // Add information special to electronic file submissions
            if ($g->getType() === GradeableType::ELECTRONIC_FILE) {
                if ($ldi !== null) {
                    // Zero score if BAD status
                    if ($ldi->getStatus() === LateDayInfo::STATUS_BAD) {
                        $entry['score'] = 0;
                    }

                    // The report needs this to be different from the 'pretty' version returned from $ldi->getStatusMessage()
                    $entry['status'] = $this->getLateStatusMessage($ldi);

                    // Only include late day info if the submission was late
                    $late_days_charged = $ldi->getLateDaysCharged();
                    if ($late_days_charged > 0) {
                        $entry['days_after_deadline'] = $ldi->getDaysLate();
                        $entry['extensions'] = $ldi->getLateDayException();
                        $entry['days_charged'] = $late_days_charged;
                    }
                }

                // Add score breakdown
                $ta_gg = $gg->getOrCreateTaGradedGradeable();
                // an array where keys are userids and values are overall comments
                $entry['overall_comments'] = $ta_gg->getOverallComments();

                // Only split up scores if electronic gradeables
                $entry['autograding_score'] = $gg->getAutoGradingScore();
                $entry['tagrading_score'] = $gg->getTaGradingScore();

                // If the grading isn't complete or there are conflicts in which version is graded,
                //  let the user know that
                if ($g->isTaGrading() && ($ta_gg->hasVersionConflict() || !$ta_gg->isComplete())) {
                    $entry['score'] = 0;
                    $entry['autograding_score'] = 0;
                    $entry['tagrading_score'] = 0;
                    if (!$gg->getSubmitter()->isTeam() && $gg->getGradeable()->isTeamAssignment()) {
                        // This is sort of a hack.  Submitters for team assignments should always be teams,
                        //  but to keep the rest of the report generation sane, they can be users if the
                        //  user is not on a team
                        $entry['note'] = 'User is not on a team';
                    }
                    elseif (!$ta_gg->isComplete()) {
                        $entry['note'] = 'This has not been graded yet.';
                    }
                    else {
                        $entry['note'] = 'Score is set to 0 because there are version conflicts.';
                    }
                }
            }

            // Component/marks
            $entry['components'] = [];
            foreach ($g->getComponents() as $component) {
                $gcc = $gg->getOrCreateTaGradedGradeable()->getGradedComponentContainer($component);

                // We need to convert to the old model single-grader format for rainbow grades
                $gc = null;
                foreach ($gcc->getGradedComponents() as $gc_) {
                    $gc = $gc_;
                    // Get the only graded component and short circuit
                    break;
                }
                //
                // For each $gc in $gcc
                //

                $inner = [
                    'title' => $component->getTitle()
                ];
                if ($component->isText()) {
                    $inner['comment'] = $gc !== null ? $gc->getComment() : '';
                }
                else {
                    $inner['score'] = $gc !== null ? $gc->getTotalScore() : 0.0;
                    $inner['default_score'] = $component->getDefault();
                    $inner['upper_clamp'] = $component->getUpperClamp();
                    $inner['lower_clamp'] = $component->getLowerClamp();
                }

                if ($g->getType() === GradeableType::ELECTRONIC_FILE) {
                    $marks = [];
                    if ($gc !== null) {
                        $marks = array_map(function (Mark $m) {
                            return ['points' => $m->getPoints(), 'note' => $m->getTitle()];
                        }, $gc->getMarks());

                        if ($gc->hasCustomMark()) {
                            $marks[] = ['points' => $gc->getScore(), 'note' => $gc->getComment()];
                        }
                    }

                    $inner['marks'] = $marks;
                }
                $entry['components'][] = $inner;

                // end for
            }
        }
        return $entry;
    }

    /**
     * Gets the status message for a from a LateDayInfo status message
     * @param LateDayInfo $ldi
     * @return string
     */
    private function getLateStatusMessage(LateDayInfo $ldi) {
        switch ($ldi->getStatus()) {
            case LateDayInfo::STATUS_GOOD:
                return 'Good';
            case LateDayInfo::STATUS_LATE:
                return 'Late';
            case LateDayInfo::STATUS_BAD:
                return 'Bad';
            case LateDayInfo::STATUS_NO_ACTIVE_VERSION:
                if ($ldi->getGradedGradeable()->getAutoGradedGradeable()->hasSubmission()) {
                    return 'Cancelled';
                }
                else {
                    return 'Unsubmitted';
                }
            default:
                return 'ERROR';
        }
    }

    /**
     * @Route("/courses/{_semester}/{_course}/reports/rainbow_grades_customization")
     */
    public function generateCustomization() {
        //Build a new model, pull in defaults for the course
        $customization = new RainbowCustomization($this->core);
        $customization->buildCustomization();

        if (isset($_POST["json_string"])) {
            //Handle user input (the form) being submitted
            try {
                $customization->processForm();

                // Finally, send the requester back the information
                $this->core->getOutput()->renderJsonSuccess("Successfully wrote customization.json file");
            }
            catch (ValidationException $e) {
                //Use this to handle any invalid/inconsistent input exceptions thrown during processForm()
                $this->core->getOutput()->renderJsonFail('See "data" for details', $e->getDetails());
            }
            catch (\Exception $e) {
                //Catches any other exceptions, should be "unexpected" issues
                $this->core->getOutput()->renderJsonError($e->getMessage());
            }
        }
        else {
            $this->core->getOutput()->addInternalJs('rainbow-customization.js');
            $this->core->getOutput()->addInternalCss('rainbow-customization.css');

            $this->core->getOutput()->addBreadcrumb('Rainbow Grades Customization');

            // Print the form
            $this->core->getOutput()->renderTwigOutput('admin/RainbowCustomization.twig', [
                "customization_data" => $customization->getCustomizationData(),
                "available_buckets" => $customization->getAvailableBuckets(),
                'bucket_counts' => $customization->getBucketCounts(),
                "used_buckets" => $customization->getUsedBuckets(),
                'display_benchmarks' => $customization->getDisplayBenchmarks(),
                'benchmark_percents' => (array) $customization->getBenchmarkPercent(),
                'benchmarks_with_input_fields' => ['lowest_a-', 'lowest_b-', 'lowest_c-', 'lowest_d'],
                'sections_and_labels' => (array) $customization->getSectionsAndLabels(),
                'bucket_percentages' => $customization->getBucketPercentages(),
                'messages' => $customization->getMessages(),
                'per_gradeable_curves' => $customization->getPerGradeableCurves(),
                'limited_functionality_mode' => !$this->core->getQueries()->checkIsInstructorInCourse(
                    $this->core->getConfig()->getVerifiedSubmittyAdminUser(),
                    $this->core->getConfig()->getCourse(),
                    $this->core->getConfig()->getSemester()
                ),
            ]);
        }
    }

    /**
     * @Route("/courses/{_semester}/{_course}/reports/rainbow_grades_status")
     */
    public function autoRainbowGradesStatus() {
        // Create path to the file we expect to find in the jobs queue
        $jobs_file = '/var/local/submitty/daemon_job_queue/auto_rainbow_' .
            $this->core->getConfig()->getSemester() .
            '_' .
            $this->core->getConfig()->getCourse() .
            '.json';

        // Create path to 'processing' file in jobs queue
        $processing_jobs_file = '/var/local/submitty/daemon_job_queue/PROCESSING_auto_rainbow_' .
            $this->core->getConfig()->getSemester() .
            '_' .
            $this->core->getConfig()->getCourse() .
            '.json';

        // Get the max time to wait before timing out
        $max_wait_time = self::MAX_AUTO_RG_WAIT_TIME;

        // Check the jobs queue every second to see if the job has finished yet
        while (file_exists($jobs_file) && $max_wait_time) {
            sleep(1);
            $max_wait_time--;
            clearstatcache();
        }

        // Jobs queue daemon actually changes the name of the job by prepending PROCESSING onto the filename
        // We must also wait for that file to be removed
        // Check the jobs queue every second to see if the job has finished yet
        while (file_exists($processing_jobs_file) && $max_wait_time) {
            sleep(1);
            $max_wait_time--;
            clearstatcache();
        }

        // Check the course auto_debug_output.txt to ensure no exceptions were thrown
        $debug_output_path = '/var/local/submitty/courses/' .
            $this->core->getConfig()->getSemester() . '/' .
            $this->core->getConfig()->getCourse() .
            '/rainbow_grades/auto_debug_output.txt';

        // Look over the output file to see if any part of the process failed
        try {
            $failure_detected = FileUtils::areWordsInFile($debug_output_path, ['Exception', 'Aborted', 'failed']);
        }
        catch (\Exception $e) {
            $failure_detected = true;
        }

        $debug_contents = file_get_contents($debug_output_path);

        // If we finished the previous loops before max_wait_time hit 0 then the file successfully left the jobs queue
        // implying that it finished
        if ($max_wait_time && $failure_detected == false) {
            $this->core->getOutput()->renderJsonSuccess($debug_contents);
        }
        else {
            // Else we timed out or something else went wrong
            $this->core->getOutput()->renderJsonFail($debug_contents);
        }
    }

    /**
     * Generate full rainbow grades view for instructors
     * @Route("/courses/{_semester}/{_course}/gradebook")
     * @AccessControl(role="INSTRUCTOR")
     */
    public function displayGradebook() {
        $grade_path = $this->core->getConfig()->getCoursePath() . "/rainbow_grades/output.html";

        $grade_file = null;
        if (file_exists($grade_path)) {
            $grade_file = file_get_contents($grade_path);
        }

        return MultiResponse::webOnlyResponse(
            new WebResponse(
                ['admin', 'Report'],
                'showFullGradebook',
                $grade_file
            )
        );
    }
}
