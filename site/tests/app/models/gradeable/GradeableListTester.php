<?php

namespace tests\app\models\gradeable;

use app\libraries\Core;
use app\libraries\GradeableType;
use app\libraries\database\DatabaseQueries;
use app\models\gradeable\Gradeable;
use app\models\gradeable\GradeableList;
use app\models\Config;
use app\models\User;
use tests\BaseUnitTest;

class GradeableListTester extends BaseUnitTest {
    public function testFullList() {
        $core = $this->getCore();
        $gradeables = [];
        $gradeables['01_future_homework_no_ta'] = $this->mockGradeable(
            $core,
            "01_future_homework_no_ta",
            GradeableType::ELECTRONIC_FILE,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['02_future_homework'] = $this->mockGradeable(
            $core,
            "02_future_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['03_open_homework'] = $this->mockGradeable(
            $core,
            "03_open_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['04_closed_homework'] = $this->mockGradeable(
            $core,
            "04_closed_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['05_grading_homework'] = $this->mockGradeable(
            $core,
            "05_grading_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '9999-01-01'
        );
        $gradeables['06_graded_homework'] = $this->mockGradeable(
            $core,
            "06_graded_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01'
        );

        $gradeables['11_future_numeric_no_ta'] = $this->mockGradeable(
            $core,
            "11_future_numeric_no_ta",
            GradeableType::NUMERIC_TEXT,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['12_future_numeric'] = $this->mockGradeable(
            $core,
            "12_future_numeric",
            GradeableType::NUMERIC_TEXT,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['13_grading_numeric'] = $this->mockGradeable(
            $core,
            "13_grading_numeric",
            GradeableType::NUMERIC_TEXT,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-01-01',
            '9999-01-01'
        );
        $gradeables['14_graded_numeric'] = $this->mockGradeable(
            $core,
            "14_graded_numeric",
            GradeableType::NUMERIC_TEXT,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-03-01',
            '1004-02-01'
        );

        $gradeables['07_future_checkpoint_no_ta'] = $this->mockGradeable(
            $core,
            "07_future_checkpoint_no_ta",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['08_future_checkpoint'] = $this->mockGradeable(
            $core,
            "08_future_checkpoint",
            GradeableType::CHECKPOINTS,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['09_grading_lab'] = $this->mockGradeable(
            $core,
            "09_grading_lab",
            GradeableType::CHECKPOINTS,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01'
        );
        $gradeables['10_graded_lab'] = $this->mockGradeable(
            $core,
            "10_graded_lab",
            GradeableType::CHECKPOINTS,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-03-01'
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);
        $this->assertCount(count($gradeables), $list->getGradeables());
        $this->assertEquals(count($gradeables), $list->getGradeableCount());
        $this->assertEquals(6, $list->getGradeableCount(GradeableType::ELECTRONIC_FILE));
        $this->assertEquals(4, $list->getGradeableCount(GradeableType::CHECKPOINTS));
        $this->assertEquals(4, $list->getGradeableCount(GradeableType::NUMERIC_TEXT));

        $expected = ['01_future_homework_no_ta', '07_future_checkpoint_no_ta', '11_future_numeric_no_ta'];
        $actual = $list->getFutureGradeables();
        $this->assertCount(count($expected), $actual);
        $this->assertEquals($expected, array_keys($actual));
        foreach ($expected as $key) {
            $this->assertEquals($gradeables[$key], $actual[$key]);
        }

        $expected = ['02_future_homework', '08_future_checkpoint', '12_future_numeric'];
        $actual = $list->getBetaGradeables();
        $this->assertCount(count($expected), $actual);
        $this->assertEquals($expected, array_keys($actual));
        foreach ($expected as $key) {
            $this->assertEquals($gradeables[$key], $actual[$key]);
        }

        $actual = $list->getOpenGradeables();
        $this->assertCount(1, $actual);
        $this->assertArrayHasKey('03_open_homework', $actual);
        $this->assertEquals($gradeables['03_open_homework'], $actual['03_open_homework']);

        $actual = $list->getClosedGradeables();
        $this->assertCount(1, $actual);
        $this->assertArrayHasKey('04_closed_homework', $actual);
        $this->assertEquals($gradeables['04_closed_homework'], $actual['04_closed_homework']);

        $expected = ['09_grading_lab', '05_grading_homework', '13_grading_numeric'];
        $actual = $list->getGradingGradeables();
        $this->assertCount(count($expected), $actual);
        $this->assertEquals($expected, array_keys($actual));
        foreach ($expected as $key) {
            $this->assertEquals($gradeables[$key], $actual[$key]);
        }

        $expected = ['10_graded_lab', '14_graded_numeric', '06_graded_homework'];
        $actual = $list->getGradedGradeables();
        $this->assertCount(count($expected), $actual);
        $this->assertEquals($expected, array_keys($actual));
        foreach ($expected as $key) {
            $this->assertEquals($gradeables[$key], $actual[$key]);
        }
    }

    public function testSubmittableHasDueAdmin() {
        $gradeables = [];
        $core = $this->getCore();
        $gradeables['01_future_homework_no_ta'] = $this->mockGradeable(
            $core,
            "01_future_homework_no_ta",
            GradeableType::ELECTRONIC_FILE,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['02_future_homework'] = $this->mockGradeable(
            $core,
            "02_future_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['03_open_homework'] = $this->mockGradeable(
            $core,
            "03_open_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['04_closed_homework'] = $this->mockGradeable(
            $core,
            "04_closed_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['05_grading_homework'] = $this->mockGradeable(
            $core,
            "05_grading_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '9999-01-01'
        );
        $gradeables['06_graded_homework'] = $this->mockGradeable(
            $core,
            "06_graded_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01'
        );
        $gradeables['07_future_checkpoint_no_ta'] = $this->mockGradeable(
            $core,
            "07_future_checkpoint_no_ta",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);
        $this->assertCount(6, $list->getSubmittableElectronicGradeables());
    }

    public function testSubmittableHasDueGrader() {
        $gradeables = [];
        $core = $this->getCore(false);
        $gradeables['01_future_homework_no_ta'] = $this->mockGradeable(
            $core,
            "01_future_homework_no_ta",
            GradeableType::ELECTRONIC_FILE,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['02_future_homework'] = $this->mockGradeable(
            $core,
            "02_future_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['03_open_homework'] = $this->mockGradeable(
            $core,
            "03_open_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['04_closed_homework'] = $this->mockGradeable(
            $core,
            "04_closed_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['05_grading_homework'] = $this->mockGradeable(
            $core,
            "05_grading_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '9999-01-01'
        );
        $gradeables['06_graded_homework'] = $this->mockGradeable(
            $core,
            "06_graded_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01'
        );
        $gradeables['07_future_checkpoint_no_ta'] = $this->mockGradeable(
            $core,
            "07_future_checkpoint_no_ta",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);
        $this->assertCount(5, $list->getSubmittableElectronicGradeables());
    }

    public function testSubmittableHasDueStudent() {
        $gradeables = [];
        $core = $this->getCore(false, false);
        $gradeables['01_future_homework_no_ta'] = $this->mockGradeable(
            $core,
            "01_future_homework_no_ta",
            GradeableType::ELECTRONIC_FILE,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['02_future_homework'] = $this->mockGradeable(
            $core,
            "02_future_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['03_open_homework'] = $this->mockGradeable(
            $core,
            "03_open_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['04_closed_homework'] = $this->mockGradeable(
            $core,
            "04_closed_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['05_grading_homework'] = $this->mockGradeable(
            $core,
            "05_grading_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '9999-01-01'
        );
        $gradeables['06_graded_homework'] = $this->mockGradeable(
            $core,
            "06_graded_homework",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01'
        );
        $gradeables['07_future_checkpoint_no_ta'] = $this->mockGradeable(
            $core,
            "07_future_checkpoint_no_ta",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);
        $this->assertCount(4, $list->getSubmittableElectronicGradeables());
    }

    public function testSubmittableNoDueGrader() {
        $gradeables = [];
        $core = $this->getCore(false);
        $gradeables['01_future_no_due'] = $this->mockGradeable(
            $core,
            "01_future_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01',
            true,
            true,
            false
        );
        $gradeables['02_grading_no_due'] = $this->mockGradeable(
            $core,
            "02_grading_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01',
            true,
            true,
            false
        );
        $gradeables['03_ta_submit_no_due'] = $this->mockGradeable(
            $core,
            "03_ta_submit_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01',
            true,
            false,
            false
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(false);

        $list = new GradeableList($core);
        $this->assertCount(3, $list->getSubmittableElectronicGradeables());

        $actual = $list->getFutureGradeables();
        $this->assertCount(0, $actual);

        $actual = $list->getBetaGradeables();
        $this->assertCount(0, $actual);

        $actual = $list->getOpenGradeables();
        $this->assertCount(1, $actual);
        $this->assertArrayHasKey('01_future_no_due', $actual);
        $this->assertEquals($gradeables['01_future_no_due'], $actual['01_future_no_due']);

        $actual = $list->getClosedGradeables();
        $this->assertCount(0, $actual);

        $expected = ['02_grading_no_due', '03_ta_submit_no_due'];
        $actual = $list->getGradingGradeables();
        $this->assertCount(count($expected), $actual);
        $this->assertEquals($expected, array_keys($actual));
        foreach ($expected as $key) {
            $this->assertEquals($gradeables[$key], $actual[$key]);
        }

        $actual = $list->getGradedGradeables();
        $this->assertCount(0, $actual);
    }

    public function testSubmittableNoDueStudent() {
        $core = $this->GetCore(false, false);

        $gradeables = [];
        $gradeables['01_no_submit_no_due'] = $this->mockGradeable(
            $core,
            "01_no_submit_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01',
            true,
            true,
            false
        );
        $gradeables['02_submitted_no_due'] = $this->mockGradeable(
            $core,
            "02_submitted_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01',
            true,
            true,
            false
        );
        $gradeables['03_ta_submit_no_due'] = $this->mockGradeable(
            $core,
            "03_ta_submit_no_due",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '9997-01-01',
            '1003-02-01',
            '9999-01-01',
            true,
            false,
            false
        );
        $gradeables['04_no_submit_grades_released'] = $this->mockGradeable(
            $core,
            "04_no_submit_grades_released",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01',
            true,
            true,
            false
        );
        $gradeables['05_submitted_grades_released'] = $this->mockGradeable(
            $core,
            "05_submitted_grades_released",
            GradeableType::ELECTRONIC_FILE,
            '1000-01-01',
            '1001-01-01',
            '1002-01-01',
            '1003-01-01',
            '1004-01-01',
            true,
            true,
            false
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->will($this->onConsecutiveCalls(false, true, false, false, true));

        $list = new GradeableList($core);
        $this->assertCount(5, $list->getSubmittableElectronicGradeables());

        $actual = $list->getFutureGradeables();
        $this->assertCount(0, $actual);

        $actual = $list->getBetaGradeables();
        $this->assertCount(0, $actual);

        $actual = $list->getOpenGradeables();
        $this->assertCount(2, $actual);
        $this->assertArrayHasKey('01_no_submit_no_due', $actual);
        $this->assertArrayHasKey('02_submitted_no_due', $actual);
        $this->assertEquals($gradeables['01_no_submit_no_due'], $actual['01_no_submit_no_due']);
        $this->assertEquals($gradeables['02_submitted_no_due'], $actual['02_submitted_no_due']);

        $actual = $list->getGradingGradeables();
        $this->assertCount(1, $actual);
        $this->assertArrayHasKey('03_ta_submit_no_due', $actual);
        $this->assertEquals($gradeables['03_ta_submit_no_due'], $actual['03_ta_submit_no_due']);

        $actual = $list->getClosedGradeables();
        $this->assertCount(0, $actual);

        $actual = $list->getGradedGradeables();
        $this->assertCount(2, $actual);
        $this->assertArrayHasKey('04_no_submit_grades_released', $actual);
        $this->assertArrayHasKey('05_submitted_grades_released', $actual);
        $this->assertEquals($gradeables['04_no_submit_grades_released'], $actual['04_no_submit_grades_released']);
        $this->assertEquals($gradeables['05_submitted_grades_released'], $actual['05_submitted_grades_released']);
    }

    public function testNoSubmittableGradeables() {
        $core = $this->getCore();
        $gradeables = [];
        $gradeables['07_future_checkpoint_no_ta'] = $this->mockGradeable(
            $core,
            "07_future_checkpoint_no_ta",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);
        $this->assertCount(0, $list->getSubmittableElectronicGradeables());
    }

    public function testGetGradeable() {
        $core = $this->getCore();

        $gradeables = [];
        $gradeables['01_electronic'] = $this->mockGradeable(
            $core,
            "01_electronic",
            GradeableType::ELECTRONIC_FILE,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );
        $gradeables['02_checkpoint'] = $this->mockGradeable(
            $core,
            "02_checkpoint",
            GradeableType::CHECKPOINTS,
            '9995-01-01',
            '9996-01-01',
            '9997-01-01',
            '9998-01-01',
            '9999-01-01'
        );

        $this->mockGetGradeables($core, $gradeables);
        $core->getQueries()->method('getHasSubmission')->willReturn(true);

        $list = new GradeableList($core);

        $gradeable = $list->getGradeable('01_electronic');
        $this->assertNotNull($gradeable);
        $this->assertEquals($gradeables['01_electronic'], $gradeable);

        $gradeable = $list->getGradeable('02_checkpoint');
        $this->assertNotNull($gradeable);
        $this->assertEquals($gradeables['02_checkpoint'], $gradeable);

        $gradeable = $list->getGradeable('01_electronic', GradeableType::ELECTRONIC_FILE);
        $this->assertNotNull($gradeable);
        $this->assertEquals($gradeables['01_electronic'], $gradeable);

        $gradeable = $list->getGradeable('01_electronic', GradeableType::CHECKPOINTS);
        $this->assertNull($gradeable);

        $gradeable = $list->getGradeable('02_checkpoint', GradeableType::CHECKPOINTS);
        $this->assertNotNull($gradeable);
        $this->assertEquals($gradeables['02_checkpoint'], $gradeable);

        $gradeable = $list->getGradeable('02_checkpoint', GradeableType::NUMERIC_TEXT);
        $this->assertNull($gradeable);
    }

    private function getCore($access_admin = true, $access_grading = true) {
        $core = new Core();
        $user = new User($core, [
            'user_id' => 'test',
            'user_firstname' => 'Test',
            'user_lastname' => 'Person',
            'user_email' => '',
            'user_email_secondary' => '',
            'user_email_secondary_notify' => false,
            'user_group' => $access_admin ? 1 : ($access_grading ? 2 : 4)
        ]);
        $core->setUser($user);
        $core->setConfig(new Config($core));
        return $core;
    }

    private function mockGetGradeables($core, $gradeables) {
        $mock_queries = $this->createMock(DatabaseQueries::class);
        $mock_queries->method('getGradeableConfigs')->willReturn($gradeables);
        $core->setQueries($mock_queries);
        $core->setConfig(new Config($core));
    }

    /**
     * @param Core $core
     * @param $id
     * @param $type
     * @param $ta_view_start_date
     * @param $submission_open_date
     * @param $submission_due_date
     * @param $grade_start_date
     * @param $grade_released_date
     * @param $ta_grading
     * @param $student_submit
     * @param $has_due_date
     * @param $has_submission, from perspective of the user
     *
     * @return Gradeable
     */
    private function mockGradeable(
        Core $core,
        $id,
        $type,
        $ta_view_start_date,
        $submission_open_date,
        $submission_due_date,
        $grade_start_date,
        $grade_released_date,
        $ta_grading = true,
        $student_submit = true,
        $has_due_date = true
    ) {
        $timezone = new \DateTimeZone('America/New_York');
        $details = [
            'id' => $id,
            'title' => $id,
            'instructions_url' => '',
            'ta_instructions' => '',
            'type' => $type,
            'grader_assignment_method' => 0,
            'min_grading_group' => 3,
            'syllabus_bucket' => 'homework',
            'autograding_config_path' => '/path/to/autograding',
            'vcs' => false,
            'vcs_subdirectory' => '',
            'vcs_host_type' => -1,
            'team_assignment' => false,
            'team_size_max' => 1,
            'ta_grading' => $ta_grading,
            'scanned_exam' => false,
            'student_view' => true,
            'student_view_after_grades' => false,
            'student_submit' => $student_submit,
            'has_due_date' => $has_due_date,
            'peer_grading' => false,
            'peer_grade_set' => false,
            'late_submission_allowed' => true,
            'precision' => 0.5,
            'regrade_allowed' => true,
            'grade_inquiry_per_component_allowed' => true,
            'discussion_based' => false,
            'discussion_thread_ids' => '',
            'ta_view_start_date' => new \DateTime($ta_view_start_date, $timezone),
            'grade_start_date' => new \DateTime($grade_start_date, $timezone),
            'grade_due_date' => new \DateTime($grade_start_date, $timezone),
            'grade_released_date' => new \DateTime($grade_released_date, $timezone),
            'team_lock_date' => new \DateTime($submission_due_date, $timezone),
            'submission_open_date' => new \DateTime($submission_open_date, $timezone),
            'submission_due_date' => new \DateTime($submission_due_date, $timezone),
            'late_days' => 2,
            'grade_inquiry_start_date' => new \DateTime($grade_released_date, $timezone),
            'grade_inquiry_due_date' => new \DateTime($grade_released_date, $timezone),
            'allowed_minutes' => null,
            'depends_on' => null,
            'depends_on_points' => null,
            'allow_custom_marks' => true
        ];

        return new Gradeable($core, $details);
    }
}
