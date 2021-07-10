<?php

namespace tests\app\controllers\grading;

use app\controllers\grading\ElectronicGraderController;
use app\models\gradeable\Gradeable;
use app\models\GradingOrder;
use tests\BaseUnitTest;

class ElectronicGraderControllerTester extends BaseUnitTest {
    private $core;
    /**
     * Helper method to generate a gradeable.
     *
     * @param $upper_clamp
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockGradeable() {
        return $this->createMockModel(Gradeable::class);
    }
    private function createMockGradingOrder() {
        return $this->createMockModel(GradingOrder::class);
    }
    public function randomizedGradingProvider() {
        return [
            [['aphacker','pagaca','sauerd','student','bitdiddle'], 2]
        ];
    }
    /**
     * @dataProvider randomizedGradingProvider
     */
    public function testSetRandomizedGraders(array $students, $number_to_grade) {
        $_POST['gradeable_id'] = 'test';
        $gradeable = $this->createMockGradeable();
        $grading_order = $this->createMockGradingOrder();
        $core = $this->createMockCore();
        $controller = new ElectronicGraderController($core);
        $response = $controller->setRandomizedGraders(['aphacker','pagaca','sauerd','student'], 1);
        $this->assertEquals(gettype($response), 'array');
        for ($i = 0; $i < count($response); ++$i) {
            for ($j = 0; $i < count($response[$i][1]); ++$j) {
                $this->assertEquals(gettype($response[$i][1]), 'array');
            }
        }
    }
    public function allGradeAllGradingProvider() {
        return [
            [['aphacker','pagaca','sauerd','student','bitdiddle']]
        ];
    }
    /**
     * @dataProvider allGradeAllGradingProvider
     */
    public function testSetAllGradAllGrading($students) {
        $_POST['gradeable_id'] = 'test';
        $gradeable = $this->createMockGradeable();
        $grading_order = $this->createMockGradingOrder();
        $core = $this->createMockCore();
        $controller = new ElectronicGraderController($core);
        $response = $controller->setAllGradAllGrading(['aphacker','pagaca','sauerd','student']);
        $this->assertEquals(gettype($response), 'array');
        for ($i = 0; $i < count($response); ++$i) {
            for ($j = 0; $i < count($response[$i][1]); ++$j) {
                $this->assertEquals(gettype($response[$i][1]), 'array');
                $this->assertEquals(count($response[$i][1]), 3);
            }
        }
    }
}
