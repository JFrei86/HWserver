<?php

namespace app\controllers;

use app\libraries\Core;
use app\libraries\Output;
use app\libraries\Utils;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class HomePageController
 *
 * Controller to deal with the submitty home page. Once the user has been authenticated, but before they have
 * selected which course they want to access, they are forwarded to the home page.
 */
class HomePageController extends AbstractController {
    /**
     * HomePageController constructor.
     *
     * @param Core $core
     */
    public function __construct(Core $core) {
        parent::__construct($core);
    }

    public function run() {
        switch ($_REQUEST['page']) {
            case 'change_username':
                $this->changeUserName();
                break;
            case 'change_password':
                $this->changePassword();
                break;
            case 'home_page':
            default:
                $this->showHomepage();
                break;
        }
    }

    /**
     * @Route("/home/change_password")
     */
    public function changePassword(){
        $user = $this->core->getUser();
        if(!empty($_POST['new_password']) && !empty($_POST['confirm_new_password'])
            && $_POST['new_password'] == $_POST['confirm_new_password']) {
            $user->setPassword($_POST['new_password']);
            $this->core->getQueries()->updateUser($user);
            $this->core->addSuccessMessage("Updated password");
        }
        else {
            $this->core->addErrorMessage("Must put same password in both boxes.");
        }
        $this->core->redirect($this->core->buildNewUrl(['home']));
    }

	/**
	 * Update/change first and last preferred name in $user model.
     *
     * @Route("/home/change_username")
	 */
    public function changeUserName(){
        $user = $this->core->getUser();
        if(isset($_POST['user_firstname_change']) && isset($_POST['user_lastname_change'])) {
            $newFirstName = trim($_POST['user_firstname_change']);
            $newLastName = trim($_POST['user_lastname_change']);
            // validateUserData() checks both for length (not to exceed 30) and for valid characters.
            if ($user->validateUserData('user_preferred_firstname', $newFirstName) === true && $user->validateUserData('user_preferred_lastname', $newLastName) === true) {
				$user->setPreferredFirstName($newFirstName);
				$user->setPreferredLastName($newLastName);
				//User updated flag tells auto feed to not clobber some of the user's data.
				$user->setUserUpdated(true);
				$this->core->getQueries()->updateUser($user);
            }
            else {
                $this->core->addErrorMessage("Preferred names must not exceed 30 chars.  Letters, spaces, hyphens, apostrophes, periods, parentheses, and backquotes permitted.");
            }
        }
        $this->core->redirect($this->core->buildNewUrl(['home']));
    }

    /**
     * Display the HomePageView to the student.
     *
     * @Route("/home")
     * @Route("/home/home_page")
     */
    public function showHomepage() {
        $user = $this->core->getUser();
        $unarchived_courses = $this->core->getQueries()->getUnarchivedCoursesById($user->getId());
        $archived_courses = $this->core->getQueries()->getArchivedCoursesById($user->getId());

        //Filter out any courses a student has dropped so they do not appear on the homepage.
        //Do not filter courses for non-students.

        $unarchived_courses = array_filter($unarchived_courses, function($course) use($user) {
            return $this->core->getQueries()->checkStudentActiveInCourse($user->getId(), $course->getTitle(), $course->getSemester());
        });
        $archived_courses = array_filter($archived_courses, function($course) use($user) {
            return $this->core->getQueries()->checkStudentActiveInCourse($user->getId(), $course->getTitle(), $course->getSemester());
        });

        $changeNameText = $this->core->getConfig()->getUsernameChangeText();
        $this->core->getOutput()->renderOutput('HomePage', 'showHomePage', $user, $unarchived_courses, $archived_courses, $changeNameText);
    }
}
