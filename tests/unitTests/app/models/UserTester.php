<?php

namespace tests\unitTests\app\models;

use app\models\User;

class UserTester extends \PHPUnit_Framework_TestCase {
    public function testUserNoPreferred() {
        $details = array(
            'user_id' => "test",
            'user_password' => "test",
            'user_firstname' => "User",
            'user_preferred_firstname' => null,
            'user_lastname' => "Tester",
            'user_email' => "test@example.com",
            'user_group' => 1,
            'registration_section' => 1,
            'rotating_section' => null,
            'manual_registration' => false,
            'grading_registration_sections' => "{1,2}"
        );
        $user = new User($details);
        $this->assertEquals($details['user_id'], $user->getId());
        $this->assertEquals($details['user_firstname'], $user->getFirstName());
        $this->assertEquals($details['user_preferred_firstname'], $user->getPreferredFirstName());
        $this->assertEquals($details['user_firstname'], $user->getDisplayedFirstName());
        $this->assertEquals($details['user_lastname'], $user->getLastName());
        $this->assertEquals($details['user_email'], $user->getEmail());
        $this->assertEquals($details['user_group'], $user->getGroup());
        $this->assertEquals($details['registration_section'], $user->getRegistrationSection());
        $this->assertEquals($details['rotating_section'], $user->getRotatingSection());
        $this->assertEquals($details['manual_registration'], $user->isManualRegistration());
        $this->assertEquals(array(1,2), $user->getGradingRegistrationSections());
        $this->assertTrue($user->accessAdmin());
        $this->assertTrue($user->accessFullGrading());
        $this->assertTrue($user->accessGrading());
        $this->assertTrue($user->isLoaded());
        $this->assertFalse($user->isDeveloper());
    }

    public function testUserPreferred() {
        $details = array(
            'user_id' => "test",
            'user_firstname' => "User",
            'user_preferred_firstname' => "Paul",
            'user_lastname' => "Tester",
            'user_email' => "test@example.com",
            'user_group' => 1,
            'registration_section' => 1,
            'rotating_section' => null,
            'manual_registration' => false,
            'grading_registration_sections' => "{1,2}"
        );
        $user = new User($details);
        $this->assertEquals($details['user_id'], $user->getId());
        $this->assertEquals($details['user_firstname'], $user->getFirstName());
        $this->assertEquals($details['user_preferred_firstname'], $user->getPreferredFirstName());
        $this->assertEquals($details['user_preferred_firstname'], $user->getDisplayedFirstName());
        $this->assertEquals($details['user_lastname'], $user->getLastName());
    }

    public function testPassword() {
        $details = array(
            'user_id' => "test",
            'user_password' => "test",
            'user_firstname' => "User",
            'user_preferred_firstname' => null,
            'user_lastname' => "Tester",
            'user_email' => "test@example.com",
            'user_group' => 1,
            'registration_section' => 1,
            'rotating_section' => null,
            'manual_registration' => false,
            'grading_registration_sections' => "{1,2}"
        );
        $user = new User($details);
        $this->assertTrue(password_verify("test", $user->getPassword()));
        $user->setPassword("test");
        $hashed_password = password_hash("test", PASSWORD_DEFAULT);
        password_verify("test", $hashed_password);
        $user->setPassword($hashed_password);
        password_verify("test", $hashed_password);
    }
}