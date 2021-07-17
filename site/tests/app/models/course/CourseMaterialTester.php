<?php

namespace tests\app\models\course;

use app\entities\course\CourseMaterial;
use app\entities\course\CourseMaterialSection;
use tests\BaseUnitTest;

class CourseMaterialTester extends BaseUnitTest {
    public function testFileWithSections() {
        $details = [
            'type' => CourseMaterial::FILE,
            'path' => '/tmp/file.txt',
            'release_date' => new \DateTime('9998-01-01 05:00:00'),
            'hidden_from_students' => false,
            'priority' => 1.2
        ];
        $course_material = new CourseMaterial(
            $details['type'],
            $details['path'],
            $details['release_date'],
            $details['hidden_from_students'],
            $details['priority']
        );
        $sections = ['1', '2'];
        foreach ($sections as $section) {
            $course_material_section = new CourseMaterialSection($section, $course_material);
            $course_material->addSection($course_material_section);
        }
        $this->assertEquals($details['type'], $course_material->getType());
        $this->assertEquals($details['path'], $course_material->getPath());
        $this->assertEquals($details['release_date'], $course_material->getReleaseDate());
        $this->assertEquals($details['hidden_from_students'], $course_material->isHiddenFromStudents());
        $this->assertEquals($details['priority'], $course_material->getPriority());
        $index = 0;
        foreach ($course_material->getSections()->toArray() as $section) {
            $this->assertEquals($sections[$index], $section->getSectionId());
            $index++;
        }
    }

    public function testLinkNoSections() {
        $details = [
            'type' => CourseMaterial::LINK,
            'path' => '/tmp/file.txt',
            'release_date' => new \DateTime('9998-01-01 05:00:00'),
            'hidden_from_students' => true,
            'priority' => 2.4
        ];
        $course_material = new CourseMaterial(
            $details['type'],
            $details['path'],
            $details['release_date'],
            $details['hidden_from_students'],
            $details['priority']
        );
        $this->assertEquals($details['type'], $course_material->getType());
        $this->assertEquals($details['path'], $course_material->getPath());
        $this->assertEquals($details['release_date'], $course_material->getReleaseDate());
        $this->assertEquals($details['hidden_from_students'], $course_material->isHiddenFromStudents());
        $this->assertEquals($details['priority'], $course_material->getPriority());
    }
}
