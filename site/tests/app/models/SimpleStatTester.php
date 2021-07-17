<?php

namespace tests\app\models;

use app\models\SimpleStat;
use tests\BaseUnitTest;

class SimpleStatTester extends BaseUnitTest {
    private $core;

    public function setUp(): void {
        $this->core = $this->createMockCore();
    }

    public function testSimpleStatCreation() {
        $details = [
            'g_id' => 'test_gradeable',
            'gc_id' => '2',
            'gc_title' => 'gradeable test',
            'gc_max_value' => 50.0,
            'avg_comp_score' => 38.7,
            'std_dev' => 5.1,
            'gc_order' => -1,
            'gc_is_peer' => false,
            'count' => 27,
            'active_grade_inquiry_count' => 2,
            'section_key' => 'testkey',
            'team' => false
        ];
        $average_grader_scores = [
            'grader' => [
                'avg' => 40.1,
                'count' => 12,
                'std_dev' => 2.5
            ]
        ];

        $this->core->getQueries()
            ->expects($this->once())
            ->method('getAverageGraderScores')
            ->with($details['g_id'], $details['gc_id'], $details['section_key'], $details['team'])
            ->willReturn($average_grader_scores);

        $simple_stat = new SimpleStat($this->core, $details);
        $this->assertEquals(true, $simple_stat->getComponent());
        $this->assertEquals($details['gc_title'], $simple_stat->getTitle());
        $this->assertEquals($details['gc_max_value'], $simple_stat->getMaxValue());
        $this->assertEquals($details['avg_comp_score'], $simple_stat->getAverageScore());
        $this->assertEquals($details['std_dev'], $simple_stat->getStandardDeviation());
        $this->assertEquals($details['gc_order'], $simple_stat->getOrder());
        $this->assertEquals($details['gc_is_peer'], $simple_stat->getIsPeerComponent());
        $this->assertEquals($details['count'], $simple_stat->getCount());
        $this->assertEquals($details['active_grade_inquiry_count'], $simple_stat->getActiveGradeInquiryCount());
        $this->assertEquals($average_grader_scores, $simple_stat->getGraderInfo());
    }
}
