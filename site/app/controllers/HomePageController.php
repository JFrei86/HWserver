<?php

namespace app\controllers;

use app\libraries\Core;
use app\libraries\Output;
use app\libraries\Utils;

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
                $this->showHomepage();
                break;
            case 'change_password':
                $this->changePassword();
                $this->showHomepage();
                break;
            case 'home_page':
            default:
                $this->showHomepage();
                break;
        }
    }

    public function changePassword(){

        if(isset($_POST['new_password']) && isset($_POST['confirm_new_password']))
        {
            //your logic here.
        }
    }

    public function changeUserName(){
        $user = $this->core->getUser();
        if(isset($_POST['user_name_change']))
        {
            $newName = $_POST['user_name_change'];
            if (ctype_alpha(str_replace(' ', '', $newName)) === true) {
                if(strlen($newName) <= 30)
                {
                    $user->setPreferredFirstName($newName);
                    $this->core->getQueries()->updateSubmittyUser($user);
                }
                else
                {
                    $this->core->addErrorMessage("Invalid Username. Please use 30 characters or fewer.");
                }
            }
            else
            {
                $this->core->addErrorMessage("Invalid Username. Please use only letters and spaces.");
            }
        }
    }

    /**
     * Display the HomePageView to the student.
     */
    public function showHomepage() {
        $user = $this->core->getUser();
        $courses = $this->core->getQueries()->getStudentCoursesById($user->getId());
        $this->core->getOutput()->renderOutput('HomePage', 'showHomePage', $user, $courses);
    }
}
