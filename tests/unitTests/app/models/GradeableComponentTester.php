<?php

namespace unitTests\app\models;

use app\libraries\Core;
use app\models\GradeableComponent;

class GradeableComponentTester extends \PHPUnit_Framework_TestCase {
    private $core;

    public function setUp() {
        $this->core = $this->createMock(Core::class);
    }
    
    public function testGradeableComponent() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => 100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => 10,
            'gcd_component_comment' => 'Comment about gradeable'
        );


        $component = new GradeableComponent($this->core, $details);
        $expected = array(
            'id' => 'test',
            'title' => 'Test Component',
            'ta_comment' => 'Comment to TA',
            'student_comment' => 'Comment to Student',
            'max_value' => 100,
            'is_text' => false,
            'is_extra_credit' => false,
            'order' => 1,
            'score' => 10.0,
            'comment' => 'Comment about gradeable',
            'has_grade' => true,
            'modified' => false
        );
        $actual = $component->toArray();
        ksort($expected);
        ksort($actual);
        $this->assertEquals($expected, $actual);
        $this->assertEquals($expected['id'], $component->getId());
        $this->assertEquals($expected['title'], $component->getTitle());
        $this->assertEquals($expected['ta_comment'], $component->getTaComment());
        $this->assertEquals($expected['student_comment'], $component->getStudentComment());
        $this->assertEquals($expected['max_value'], $component->getMaxValue());
        $this->assertFalse($component->getIsText());
        $this->assertFalse($component->getIsExtraCredit());
        $this->assertTrue($component->getHasGrade());
        $this->assertEquals($expected['order'], $component->getOrder());
        $this->assertEquals($expected['score'], $component->getScore());
        $this->assertEquals($expected['comment'], $component->getComment());

        $component->setScore(20);
        $this->assertEquals(20, $component->getScore());
    }

    public function testScoreGreaterThanPositiveMax() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => 100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => 1000,
            'gcd_component_comment' => 'Comment about gradeable'
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(100, $component->getScore());
        $this->assertEquals(100, $component->getMaxValue());
    }

    public function testScoreLessThanZeroPositiveMax() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => 100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => -100,
            'gcd_component_comment' => 'Comment about gradeable'
        );
        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(0, $component->getScore());
        $this->assertEquals(100, $component->getMaxValue());
    }

    public function testNullDataRow() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => 100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => null,
            'gcd_component_comment' => null
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(0, $component->getScore());
        $this->assertEquals("", $component->getComment());
    }

    public function testNegativeMaxScore() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => -100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => -50,
            'gcd_component_comment' => 'Comment about gradeable'
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(-100, $component->getMaxValue());
        $this->assertEquals(-50, $component->getScore());
    }

    public function testScoreLessThanNegativeMax() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => -100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => -150,
            'gcd_component_comment' => 'Comment about gradeable'
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(-100, $component->getMaxValue());
        $this->assertEquals(-100, $component->getScore());
    }

    public function testScoreMoreThanZeroNegativeMax() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => -100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => 100,
            'gcd_component_comment' => 'Comment about gradeable'
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(-100, $component->getMaxValue());
        $this->assertEquals(0, $component->getScore());
    }

    public function testGradedNullComment() {
        $details = array(
            'gc_id' => 'test',
            'gc_title' => 'Test Component',
            'gc_ta_comment' => 'Comment to TA',
            'gc_student_comment' => 'Comment to Student',
            'gc_max_value' => 100,
            'gc_is_text' => false,
            'gc_is_extra_credit' => false,
            'gc_order' => 1,
            'gcd_score' => 50,
            'gcd_component_comment' => null
        );


        $component = new GradeableComponent($this->core, $details);
        $this->assertEquals(100, $component->getMaxValue());
        $this->assertEquals(50, $component->getScore());
        $this->assertTrue($component->getHasGrade());
        $this->assertEquals("", $component->getComment());
    }

    public function testSetFunctions() {
        $component = new GradeableComponent($this->core);
        $expected = "f";
        $component->setComment($expected);
        $this->assertEquals($expected, $component->getComment());
    }
}
