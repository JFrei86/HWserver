<?php

namespace tests\app\models\gradeable;

use app\libraries\Core;
use app\libraries\DateUtils;
use app\libraries\GradeableType;
use app\models\Config;
use app\models\gradeable\AutoGradedGradeable;
use app\models\gradeable\AutoGradedVersion;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradedGradeable;
use app\models\gradeable\LateDayInfo;
use app\models\gradeable\LateDays;
use app\models\gradeable\Submitter;
use app\models\User;
use tests\BaseUnitTest;

class LateDaysTester extends BaseUnitTest {

    private function mockGradedGradeable(string $gradeable_id, string $due_date, int $late_days, string $submission_date, int $late_day_exception) {
        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getSubmissionDueDate')->willReturn(new \DateTime($due_date));
        $gradeable->method('getLateDays')->willReturn($late_days);
        $gradeable->method('getId')->willReturn($gradeable_id);
        $gradeable->method('getType')->willReturn(GradeableType::ELECTRONIC_FILE);
        $gradeable->method('hasDueDate')->willReturn(true);

        $auto_graded_gradeable = $this->createMockModel(AutoGradedGradeable::class);
        if ($submission_date !== '') {
            $auto_graded_version = $this->createMockModel(AutoGradedVersion::class);
            $auto_graded_version->method('getSubmissionTime')->willReturn(new \DateTime($submission_date));
            $auto_graded_version->method('getDaysLate')->willReturn(DateUtils::calculateDayDiff($due_date, $submission_date));
            $auto_graded_gradeable->method('getActiveVersionInstance')->willReturn($auto_graded_version);
            $auto_graded_gradeable->method('hasActiveVersion')->willReturn(true);
        }
        else {
            $auto_graded_gradeable->method('getActiveVersionInstance')->willReturn(null);
            $auto_graded_gradeable->method('hasActiveVersion')->willReturn(false);
        }

        $submitter = $this->createMock(Submitter::class);
        $submitter->method('hasUser')->willReturn(true);

        $graded_gradeable = $this->createMockModel(GradedGradeable::class);
        $graded_gradeable->method('getGradeable')->willReturn($gradeable);
        $graded_gradeable->method('getGradeableId')->willReturn($gradeable_id);
        $graded_gradeable->method('getSubmitter')->willReturn($submitter);
        $graded_gradeable->method('getAutoGradedGradeable')->willReturn($auto_graded_gradeable);
        $graded_gradeable->method('getLateDayException')->willReturn($late_day_exception);

        return $graded_gradeable;
    }

    private function mockCore(int $default_late_days, array $updates) {
        $core = $this->createMockModel(Core::class);
        $core->method('getDateTimeNow')->willReturn(new \DateTime());

        $config = $this->createMockModel(Config::class);
        $config->method('getDefaultStudentLateDays')->willReturn($default_late_days);
        $core->method('getConfig')->willReturn($config);

        $queries = $this->createMock(\app\libraries\database\DatabaseQueries::class);
        $queries->method('getLateDayUpdates')->willReturn($updates);
        $core->method('getQueries')->willReturn($queries);
        return $core;
    }

    private function mockUser(string $user_id) {
        $user = $this->createMockModel(User::class);
        $user->method('getId')->willReturn($user_id);
        return $user;
    }

    private function mockGradedGradeables() {
        $due_date_m1d = '9-10-2010 11:59:59';
        $due_date = '10-10-2010 11:59:59';
        $due_date_p1d = '11-10-2010 11:59:59';
        $due_date_p2d = '12-10-2010 11:59:59';

        $on_time = $this->mockGradedGradeable('on_time', $due_date, 3, $due_date_m1d, 0);
        $on_time_exceptions = $this->mockGradedGradeable('on_time_exception', $due_date, 3, $due_date_p1d, 1);

        // Uses 1 late day
        $late = $this->mockGradedGradeable('late', $due_date, 3, $due_date_p1d, 0);

        // Uses 1 late day
        $late_exception = $this->mockGradedGradeable('late_exception', $due_date_m1d, 3, $due_date_p1d, 1);

        // Uses 0 late days
        $bad_for_gradeable = $this->mockGradedGradeable('bad_for_gradeable', $due_date, 0, $due_date_p1d, 0);

        // Uses 0 late days
        $bad_for_term = $this->mockGradedGradeable('bad_for_term', $due_date, 3, '10-10-2222 11:59:59', 0);

        // Add another on time / late gradeable at the end to make sure that bad status doesn't affect future gradeables

        $on_time1 = $this->mockGradedGradeable('on_time1', $due_date_p1d, 3, $due_date_m1d, 0);

        // Uses 1 late day
        $late1 = $this->mockGradedGradeable('late1', $due_date_p1d, 3, $due_date_p2d, 0);


        // Total late days used: 3

        return [$on_time, $on_time_exceptions, $late, $late_exception, $bad_for_gradeable, $bad_for_term, $on_time1, $late1];
    }

    private function makeTestLateDays() {
        return new LateDays($this->mockCore(5, []), $this->mockUser('testuser'), $this->mockGradedGradeables());
    }

    private function mockGradeable(string $gradeable_id) {
        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getId')->willReturn($gradeable_id);
        return $gradeable;
    }

    public function testNoUpdates() {
        $late_days = $this->makeTestLateDays();
        $this->assertEquals(2, $late_days->getLateDaysRemaining());
        $this->assertEquals(3, $late_days->getLateDaysUsed());

        // Since the lateDayInfoTest tests most of the behavior, we must test that the cumulative construction works
        // The constructor exhaustively implicitly tests the 'getLateDaysRemainingByContext'
        $this->assertEquals(LateDayInfo::STATUS_LATE, $late_days->getLateDayInfoByGradeable($this->mockGradeable('late1'))->getStatus());
    }

    public function testWithUpdates() {
        $updates = [
            [
                'since_timestamp' => new \DateTime('11-10-2010 11:59:58'),
                'allowed_late_days' => 3
            ]
        ];
        $late_days = new LateDays($this->mockCore(2, $updates), $this->mockUser('testuser'), $this->mockGradedGradeables());

        // Since we got an extra late day as of due date, we should not be late status
        $this->assertEquals(LateDayInfo::STATUS_LATE, $late_days->getLateDayInfoByGradeable($this->mockGradeable('late1'))->getStatus(), 'Late day updates not applied correctly');
    }

    public function testFilterCanView() {
        // Since this entire function is a logical expression, the testing is only
        //   done for regression testing for the critical (unprivileged) user
        $core = $this->createMockModel(Core::class);

        $user = $this->createMockModel(User::class);
        $user->method('accessAdmin')->willReturn(false);
        $user->method('accessGrading')->willReturn(false);
        $core->method('getUser')->willReturn($user);

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::CHECKPOINTS);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(false);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(false);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(false);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $gradeable->method('hasAutoGradingConfig')->willReturn(false);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $gradeable->method('hasAutoGradingConfig')->willReturn(true);
        $gradeable->method('isStudentView')->willReturn(false);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $gradeable->method('hasAutoGradingConfig')->willReturn(true);
        $gradeable->method('isStudentView')->willReturn(true);
        $gradeable->method('isTaViewOpen')->willReturn(false);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $gradeable->method('hasAutoGradingConfig')->willReturn(true);
        $gradeable->method('isStudentView')->willReturn(true);
        $gradeable->method('isTaViewOpen')->willReturn(true);
        $gradeable->method('isSubmissionOpen')->willReturn(false);
        $this->assertFalse(LateDays::filterCanView($core, $gradeable));

        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getType')->willReturn(\app\libraries\GradeableType::ELECTRONIC_FILE);
        $gradeable->method('isStudentSubmit')->willReturn(true);
        $gradeable->method('hasDueDate')->willReturn(true);
        $gradeable->method('isLateSubmissionAllowed')->willReturn(true);
        $gradeable->method('hasAutoGradingConfig')->willReturn(true);
        $gradeable->method('isStudentView')->willReturn(true);
        $gradeable->method('isTaViewOpen')->willReturn(true);
        $gradeable->method('isSubmissionOpen')->willReturn(true);
        $this->assertTrue(LateDays::filterCanView($core, $gradeable));
    }

    public function testGetGradeablesByStatus() {
        $late_days = $this->makeTestLateDays();
        $this->assertEquals(['on_time', 'on_time_exception', 'on_time1'], $late_days->getGradeableIdsByStatus(\app\models\gradeable\LateDayInfo::STATUS_GOOD));
        $this->assertEquals(['late_exception', 'late', 'late1'], $late_days->getGradeableIdsByStatus(\app\models\gradeable\LateDayInfo::STATUS_LATE));
        $this->assertEquals(['bad_for_gradeable', 'bad_for_term'], $late_days->getGradeableIdsByStatus(\app\models\gradeable\LateDayInfo::STATUS_BAD));
    }
}
