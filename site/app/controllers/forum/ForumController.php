<?php

namespace app\controllers\forum;

use app\libraries\Core;
use app\models\Notification;
use app\models\Email;
use app\controllers\AbstractController;
use app\libraries\Utils;
use app\libraries\FileUtils;
use app\libraries\DateUtils;

/**
 * Class ForumHomeController
 *
 * Controller to deal with the submitty home page. Once the user has been authenticated, but before they have
 * selected which course they want to access, they are forwarded to the home page.
 */
class ForumController extends AbstractController {

	/**
     * ForumHomeController constructor.
     *
     * @param Core $core
     */
    public function __construct(Core $core) {
        parent::__construct($core);
    }

    public function run() {
        switch ($_REQUEST['page']) {
            case 'create_thread':
                $this->showCreateThread();
                break;
            case 'publish_thread':
                $this->publishThread();
                break;
            case 'make_announcement':
                $this->alterAnnouncement(1);
                break;
            case 'publish_post':
                $this->publishPost();
                break;
            case 'delete_post':
                $this->alterPost(0);
                break;
            case 'edit_post':
                $this->alterPost(1);
                break;
            case 'undelete_post':
                $this->alterPost(2);
                break;
            case 'search_threads':
                $this->search();
                break;
            case 'get_edit_post_content':
                $this->getEditPostContent();
                break;
            case 'remove_announcement':
                $this->alterAnnouncement(0);
                break;
            case 'get_threads':
                $this->getThreads();
                break;
            case 'get_history':
                $this->getHistory();
                break;
            case 'add_category':
                $this->addNewCategory();
                break;
            case 'delete_category':
                $this->deleteCategory();
                break;
            case 'edit_category':
                $this->editCategory();
                break;
            case 'reorder_categories':
                $this->reorderCategories();
                break;
            case 'show_stats':
                $this->showStats();
                break;
            case 'merge_thread':
                $this->mergeThread();
                break;
            case 'pin_thread':
                $this->pinThread(1);
                break;
            case 'unpin_thread':
                $this->pinThread(0);
                break;
            case 'change_thread_status_resolve':
                $this->changeThreadStatus(1);
                break;
            case 'view_thread':
            default:
                $this->showThreads();
                break;
        }
    } 

    private function showDeleted() {
        return ($this->core->getUser()->accessGrading() && isset($_COOKIE['show_deleted']) && $_COOKIE['show_deleted'] == "1");
    }

    private function showMergedThreads($currentCourse) {
        return  (isset($_COOKIE["{$currentCourse}_show_merged_thread"]) && $_COOKIE["{$currentCourse}_show_merged_thread"] == "1");
    }

    private function returnUserContentToPage($error, $isThread, $thread_id){
            //Notify User
            $this->core->addErrorMessage($error);
            if($isThread){
                $url = $this->core->buildUrl(array('component' => 'forum', 'page' => 'create_thread'));
            } else {
                $url = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread_id));
            }
            return array(-1, $url);
    }

	private function changeThreadStatus($status) {
		$thread_id = $_POST['thread_id'];
		$result = array();
		if($this->core->getQueries()->getAuthorOfThread($thread_id) === $this->core->getUser()->getId() || $this->core->getUser()->accessGrading()) {
			if($this->core->getQueries()->updateResolveState($thread_id, $status)) {
				$result['success'] = 'Thread resolve state has been changed.';
			} else {
				$result['error'] = 'The thread resolve state could not be updated. Please try again.';
			}
		} else {
            $result["error"] = "You do not have permissions to do that.";
		}
        $this->core->getOutput()->renderJson($result);
		return $this->core->getOutput()->getOutput();
	}

    private function checkGoodAttachment($isThread, $thread_id, $file_post){
        if((!isset($_FILES[$file_post])) || $_FILES[$file_post]['error'][0] === UPLOAD_ERR_NO_FILE){
            return array(0);
        }
        if(count($_FILES[$file_post]['tmp_name']) > 5) {
            return $this->returnUserContentToPage("Max file upload size is 5. Please try again.", $isThread, $thread_id);
        }
        $imageCheck = Utils::checkUploadedImageFile($file_post) ? 1 : 0;
        if($imageCheck == 0 && !empty($_FILES[$file_post]['tmp_name'])){
            return $this->returnUserContentToPage("Invalid file type. Please upload only image files. (PNG, JPG, GIF, BMP...)", $isThread, $thread_id);
        }
        return array($imageCheck);
    }

    private function isValidCategories($inputCategoriesIds = -1, $inputCategoriesName = -1){
        $rows = $this->core->getQueries()->getCategories();
        if(is_array($inputCategoriesIds)) {
            if(count($inputCategoriesIds) < 1) {
                return false;
            }
            foreach ($inputCategoriesIds as $category_id) {
                $match_found = false;
                foreach($rows as $index => $values){
                    if($values["category_id"] === $category_id) {
                        $match_found = true;
                        break;
                    }
                }
                if(!$match_found) {
                    return false;
                }
            }
        }
        if(is_array($inputCategoriesName)) {
            if(count($inputCategoriesName) < 1) {
                return false;
            }
            foreach ($inputCategoriesName as $category_name) {
                $match_found = false;
                foreach($rows as $index => $values){
                    if($values["category_desc"] === $category_name) {
                        $match_found = true;
                        break;
                    }
                }
                if(!$match_found) {
                    return false;
                }
            }
        }
        return true;
    }

    private function isCategoryDeletionGood($category_id){
        // Check if not the last category which exists
        $rows = $this->core->getQueries()->getCategories();
        foreach($rows as $index => $values){
            if(((int)$values["category_id"]) !== $category_id) {
                return true;
            }
        }
        return false;
    }

    public function addNewCategory(){
        $result = array();
        if($this->core->getAccess()->canI("forum.modify_category")){
            if(!empty($_REQUEST["newCategory"])) {
                $category = trim($_REQUEST["newCategory"]);
                if($this->isValidCategories(-1, array($category))) {
                    $result["error"] = "That category already exists.";
                } else {
                    if(strlen($category)>50){
                        $result["error"] = "Category name is more than 50 characters.";
                    }
                    else {
                        $newCategoryId = $this->core->getQueries()->addNewCategory($category);
                        $result["new_id"] = $newCategoryId["category_id"];
                    }
                }
            } else {
                $result["error"] = "No category data submitted. Please try again.";
            }
        } else {
            $result["error"] = "You do not have permissions to do that.";
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    public function deleteCategory(){
        $result = array();
        if($this->core->getAccess()->canI("forum.modify_category")) {
            if(!empty($_REQUEST["deleteCategory"])) {
                $category = (int)$_REQUEST["deleteCategory"];
                if(!$this->isValidCategories(array($category))) {
                    $result["error"] = "That category doesn't exists.";
                } else if(!$this->isCategoryDeletionGood($category)) {
                    $result["error"] = "Last category can't be deleted.";
                } else {
                    if($this->core->getQueries()->deleteCategory($category)) {
                        $result["success"] = "OK";
                    } else {
                        $result["error"] = "Category is in use.";
                    }
                }
            } else {
                $result["error"] = "No category data submitted. Please try again.";
            }
        } else {
            $result["error"] = "You do not have permissions to do that.";
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    public function editCategory(){
        $result = array();

        if($this->core->getAccess()->canI("forum.modify_category")){
            $category_id = $_REQUEST["category_id"];
            $category_desc = null;
            $category_color = null;
            $should_update = true;

            if(!empty($_REQUEST["category_desc"])) {
                $category_desc = trim($_REQUEST["category_desc"]);
                if($this->isValidCategories(-1, array($category_desc))) {
                    $result["error"] = "That category already exists.";
                    $should_update = false;
                }
                else if(strlen($category_desc) > 50){
                    $result["error"] = "Category name is more than 50 characters.";
                    $should_update = false;
                }
            }
            if(!empty($_REQUEST["category_color"])) {
                $category_color = $_REQUEST["category_color"];
                if(!in_array(strtoupper($category_color), $this->getAllowedCategoryColor())) {
                    $result["error"] = "Given category color is not allowed.";
                    $should_update = false;
                }
            }

            if($should_update) {
                $this->core->getQueries()->editCategory($category_id, $category_desc, $category_color);
                $result["success"] = "OK";
            } else if(!isset($result["error"])) {
                $result["error"] = "No category data updated. Please try again.";
            }
        } else {
            $result["error"] = "You do not have permissions to do that.";
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    public function reorderCategories(){
        $result = array();
        if($this->core->getAccess()->canI("forum.modify_category")){
            $rows = $this->core->getQueries()->getCategories();

            $current_order = array();
            foreach ($rows as $row) {
                $current_order[] = (int)$row['category_id'];
            }
            $new_order = array();
            foreach ($_POST['categorylistitem'] as $item) {
                $new_order[] = (int)$item;
            }

            if(count(array_diff(array_merge($current_order, $new_order), array_intersect($current_order, $new_order))) === 0) {
                $this->core->getQueries()->reorderCategories($new_order);
                $results["success"] = "ok";
            } else {
                $result["error"] = "Different Categories IDs given";
            }
        } else {
            $result["error"] = "You do not have permissions to do that.";
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    //CODE WILL BE CONSOLIDATED IN FUTURE

    public function publishThread(){

        if(!$this->core->getAccess()->canI("forum.publish")) {
            $this->core->getOutput()->renderJson(['error' => "Invalid CSRF token"]);
            return $this->core->getOutput()->getOutput();
        }

        $result = array();
        $title = trim($_POST["title"]);
        $thread_post_content = str_replace("\r", "", $_POST["thread_post_content"]);
        $anon = (isset($_POST["Anon"]) && $_POST["Anon"] == "Anon") ? 1 : 0;
        if( !empty($_POST['lock_thread_date'])  and $this->core->getUser()->accessAdmin() ){
            $lock_thread_date = $_POST['lock_thread_date'];
        } else {
            $lock_thread_date = null;
        }
        $thread_status = $_POST["thread_status"];
        $announcement = (isset($_POST["Announcement"]) && $_POST["Announcement"] == "Announcement" && $this->core->getUser()->accessFullGrading()) ? 1 : 0 ;
        $email_announcement = (isset($_POST["EmailAnnouncement"]) && $_POST["EmailAnnouncement"] == "EmailAnnouncement" && $this->core->getUser()->accessFullGrading()) ? 1 : 0 ;

        $categories_ids  = array();
        foreach ($_POST["cat"] as $category_id) {
            $categories_ids[] = (int)$category_id;
        }
        if(empty($title) || empty($thread_post_content)){
            $this->core->addErrorMessage("One of the fields was empty or bad. Please re-submit your thread.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'create_thread'));
        } else if(!$this->isValidCategories($categories_ids)){
            $this->core->addErrorMessage("You must select valid categories. Please re-submit your thread.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'create_thread'));
        } else {
            $hasGoodAttachment = $this->checkGoodAttachment(true, -1, 'file_input');
            if($hasGoodAttachment[0] == -1){
                $result['next_page'] = $hasGoodAttachment[1];
            } else {
                // Good Attachment
                $result = $this->core->getQueries()->createThread($this->core->getUser()->getId(), $title, $thread_post_content, $anon, $announcement, $thread_status, $hasGoodAttachment[0], $categories_ids, $lock_thread_date);


                $id = $result["thread_id"];
                $post_id = $result["post_id"];

                if($hasGoodAttachment[0] == 1) {

                    $thread_dir = FileUtils::joinPaths(FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "forum_attachments"), $id);
                    FileUtils::createDir($thread_dir);

                    $post_dir = FileUtils::joinPaths($thread_dir, $post_id);
                    FileUtils::createDir($post_dir);

                    for($i = 0; $i < count($_FILES["file_input"]["name"]); $i++){
                        $target_file = $post_dir . "/" . basename($_FILES["file_input"]["name"][$i]);
                        move_uploaded_file($_FILES["file_input"]["tmp_name"][$i], $target_file);
                    }

                }

                $notification = new Notification($this->core, array('component' => 'forum', 'type' => $announcement ? 'new_announcement' : 'new_thread', 'thread_id' => $id, 'thread_title' => $title));
                $this->core->getQueries()->pushNotification($notification);

                if($email_announcement) {
                    $this->sendEmailAnnouncement($title, $thread_post_content);
                }
                $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $id));
            }
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    private function search(){
        $results = $this->core->getQueries()->searchThreads($_POST['search_content']);
        $this->core->getOutput()->renderOutput('forum\ForumThread', 'searchResult', $results);
    }

    public function publishPost(){

        if(!$this->core->getAccess()->canI("forum.publish")) {
            $this->core->getOutput()->renderJson(['error' => "Invalid CSRF token"]);
            return $this->core->getOutput()->getOutput();
        }

        $result = array();
        $parent_id = (!empty($_POST["parent_id"])) ? htmlentities($_POST["parent_id"], ENT_QUOTES | ENT_HTML5, 'UTF-8') : -1;
        $post_content_tag = 'thread_post_content';
        $file_post = 'file_input';
        $post_content = str_replace("\r", "", $_POST[$post_content_tag]);
        $thread_id = htmlentities($_POST["thread_id"], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $display_option = (!empty($_POST["display_option"])) ? htmlentities($_POST["display_option"], ENT_QUOTES | ENT_HTML5, 'UTF-8') : "tree";
        $anon = (isset($_POST["Anon"]) && $_POST["Anon"] == "Anon") ? 1 : 0;
        if(empty($post_content) || empty($thread_id)){
            $this->core->addErrorMessage("There was an error submitting your post. Please re-submit your post.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread'));
        } else if(!$this->core->getQueries()->existsThread($thread_id)) {
            $this->core->addErrorMessage("There was an error submitting your post. Thread doesn't exist.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread'));
        } else if(!$this->core->getQueries()->existsPost($thread_id, $parent_id)) {
            $this->core->addErrorMessage("There was an error submitting your post. Parent post doesn't exist in given thread.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread'));
        } else if($this->core->getQueries()->isThreadLocked($thread_id) and !$this->core->getUser()->accessAdmin() ) {
            $this->core->addErrorMessage("Thread is locked.");
            $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', "thread_id" => $thread_id));
        } else {
            $hasGoodAttachment = $this->checkGoodAttachment(false, $thread_id, $file_post);
            if($hasGoodAttachment[0] == -1){
                $result['next_page'] = $hasGoodAttachment[1];
            } else {
                $post_id = $this->core->getQueries()->createPost($this->core->getUser()->getId(), $post_content, $thread_id, $anon, 0, false, $hasGoodAttachment[0], $parent_id);
                $thread_dir = FileUtils::joinPaths(FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "forum_attachments"), $thread_id);

                if(!is_dir($thread_dir)) {
                    FileUtils::createDir($thread_dir);
                }

                if($hasGoodAttachment[0] == 1) {
                    $post_dir = FileUtils::joinPaths($thread_dir, $post_id);
                    FileUtils::createDir($post_dir);
                    for($i = 0; $i < count($_FILES[$file_post]["name"]); $i++){
                        $target_file = $post_dir . "/" . basename($_FILES[$file_post]["name"][$i]);
                        move_uploaded_file($_FILES[$file_post]["tmp_name"][$i], $target_file);
                    }
                }
                // Notification to parent post author
                $post = $this->core->getQueries()->getPost($parent_id);
                $post_author = $post['author_user_id'];
                $notification_anonymous = ($anon == 1) ? true : false;
                $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'reply', 'thread_id' => $thread_id, 'post_id' => $parent_id, 'post_content' => $post['content'], 'reply_to' => $post_author, 'child_id' => $post_id, 'anonymous' => $notification_anonymous));
                $this->core->getQueries()->pushNotification($notification);
                $result['next_page'] = $this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'option' => $display_option, 'thread_id' => $thread_id));
            }
        }
        $this->core->getOutput()->renderJson($result);
        return $this->core->getOutput()->getOutput();
    }

    public function alterAnnouncement($type){
        if($this->core->getAccess()->canI("forum.modify_announcement")){
            $thread_id = $_POST["thread_id"];
            $this->core->getQueries()->setAnnouncement($thread_id, $type);
            if($type) {
                $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'updated_announcement', 'thread_id' => $thread_id, 'thread_title' => $this->core->getQueries()->getThreadTitle($thread_id)['title']));
                $this->core->getQueries()->pushNotification($notification);
            }
        } else {
            $this->core->addErrorMessage("You do not have permissions to do that.");
        }
    }

    public function pinThread($type){
        $thread_id = $_POST["thread_id"];
        $current_user = $this->core->getUser()->getId();
        $this->core->getQueries()->addPinnedThread($current_user, $thread_id, $type);
        $response = array('user' => $current_user, 'thread' => $thread_id, 'type' => $type);
        $this->core->getOutput()->renderJson($response);
        return $this->core->getOutput()->getOutput();
    }

    /**
     * Alter content/delete/undelete post of a thread
     *
     * If applied on the first post of a thread, same action will be reflected on the corresponding thread
     *
     * @param integer(0/1/2) $modifyType - 0 => delete, 1 => edit content, 2 => undelete
     */
    public function alterPost($modifyType){
        $post_id = $_POST["post_id"] ?? $_POST["edit_post_id"];
        $post = $this->core->getQueries()->getPost($post_id);
        if(!$this->core->getAccess()->canI("forum.modify_post", ['post_author' => $post['author_user_id']])) {
                $this->core->getOutput()->renderJson(['error' => 'You do not have permissions to do that.']);
                return;
        }
        if(!empty($_POST['edit_thread_id']) && $this->core->getQueries()->isThreadLocked($_POST['edit_thread_id']) and !$this->core->getUser()->accessAdmin() ){
            $this->core->addErrorMessage("Thread is locked.");
            $this->core->redirect($this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $_POST['edit_thread_id'])));
        } else if($this->core->getQueries()->isThreadLocked($_POST['thread_id']) and !$this->core->getUser()->accessAdmin() ){
            $this->core->getOutput()->renderJson(['error' => 'Thread is locked']);
            return;
        }
        else if($modifyType == 0) { //delete post or thread
            $thread_id = $_POST["thread_id"];
            if($this->core->getQueries()->setDeletePostStatus($post_id, $thread_id, 1)){
                $type = "thread";
            } else {
                $type = "post";
            }
            $post_author = $post['author_user_id'];
            $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'deleted', 'thread_id' => $thread_id, 'post_content' => $post['content'], 'reply_to' => $post_author));
            $this->core->getQueries()->pushNotification($notification);
            $this->core->getQueries()->removeNotificationsPost($post_id);
            $this->core->getOutput()->renderJson($response = array('type' => $type));
            return $this->core->getOutput()->getOutput();
        } else if($modifyType == 2) { //undelete post or thread
            $thread_id = $_POST["thread_id"];
            $result = $this->core->getQueries()->setDeletePostStatus($post_id, $thread_id, 0);
            if(is_null($result)) {
                $error = "Parent post must be undeleted first.";
                $this->core->getOutput()->renderJson($response = array('error' => $error));
            } else {
                /// We want to reload same thread again, in both case (thread/post undelete)
                $type = "post";
                $post_author = $post['author_user_id'];
                $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'undeleted', 'thread_id' => $thread_id, 'post_id' => $post_id, 'post_content' => $post['content'], 'reply_to' => $post_author));
                $this->core->getQueries()->pushNotification($notification);
                $this->core->getOutput()->renderJson($response = array('type' => $type));
            }
            return $this->core->getOutput()->getOutput();
        } else if($modifyType == 1) { //edit post or thread
            $thread_id = $_POST["edit_thread_id"];
            $status_edit_thread = $this->editThread();
            $status_edit_post   = $this->editPost();
            $any_changes = false;
            $isError = false;
            $messageString = '';
             // Author of first post and thread must be same
            if(is_null($status_edit_thread) && is_null($status_edit_post)) {
                $this->core->addErrorMessage("No data submitted. Please try again.");
            } else if(is_null($status_edit_thread) || is_null($status_edit_post)) {
                $type = is_null($status_edit_thread)?"Post":"Thread";
                if($status_edit_thread || $status_edit_post) {
                    //$type is true
                    $messageString = "{$type} updated successfully.";
                    $any_changes = true;
                } else {
                    $isError = true;
                    $messageString = "{$type} updation failed. Please try again.";
                }
            } else {
                if($status_edit_thread && $status_edit_post) {
                    $messageString = "Thread and post updated successfully.";
                    $any_changes = true;
                } else {
                    $type = ($status_edit_thread)?"Thread":"Post";
                    $type_opposite = (!$status_edit_thread)?"Thread":"Post";
                    $isError = true;
                    if($status_edit_thread || $status_edit_post) {
                        //$type is true
                        $messageString = "{$type} updated successfully. {$type_opposite} updation failed. Please try again.";
                        $any_changes = true;
                    } else {
                        $messageString = "Thread and Post updation failed. Please try again.";
                    }
                }
            }
            if($any_changes) {
                $post_author = $post['author_user_id'];
                $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'edited', 'thread_id' => $thread_id, 'post_id' => $post_id, 'post_content' => $post['content'], 'reply_to' => $post_author));
                $this->core->getQueries()->pushNotification($notification);
            }
            if($isError) {
                $this->core->getOutput()->renderJson(['error' => $messageString]);
                return;
            }
            $this->core->redirect($this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread_id)));
        }
    }

    private function editThread(){
        // Ensure authentication before call
        if(!empty($_POST["title"])) {
            $thread_id = $_POST["edit_thread_id"];
            if( !empty($_POST['lock_thread_date']) and $this->core->getUser()->accessAdmin()){
                $lock_thread_date = $_POST['lock_thread_date'];
            }
            else{
                $lock_thread_date = null;
            }
            $thread_title = $_POST["title"];
            $status = $_POST["thread_status"];
            $categories_ids  = array();
            if(!empty($_POST["cat"])) {
                foreach ($_POST["cat"] as $category_id) {
                    $categories_ids[] = (int)$category_id;
                }
            }
            if(!$this->isValidCategories($categories_ids)) {
                return false;
            }
            return $this->core->getQueries()->editThread($thread_id, $thread_title, $categories_ids, $status, $lock_thread_date);
        }
        return null;
    }

    private function editPost(){
        // Ensure authentication before call
        $new_post_content = $_POST["thread_post_content"];
        if(!empty($new_post_content)) {
            $post_id = $_POST["edit_post_id"];
            $original_post = $this->core->getQueries()->getPost($post_id);
            if(!empty($original_post)) {
                $original_creator = $original_post['author_user_id'];
            }
            $anon = (!empty($_POST["Anon"]) && $_POST["Anon"] == "Anon") ? 1 : 0;
            $current_user = $this->core->getUser()->getId();
            if(!$this->modifyAnonymous($original_creator)) {
                $anon = $original_post["anonymous"] ? 1 : 0;
            }
            return $this->core->getQueries()->editPost($original_creator, $current_user, $post_id, $new_post_content, $anon);
        }
        return null;
    }

    private function getSortedThreads($categories_ids, $max_thread, $show_deleted, $show_merged_thread, $thread_status, $unread_threads, &$blockNumber, $thread_id = -1){
        $current_user = $this->core->getUser()->getId();
        if(!$this->isValidCategories($categories_ids)) {
            // No filter for category
            $categories_ids = array();
        }

        $thread_block = $this->core->getQueries()->loadThreadBlock($categories_ids, $thread_status, $unread_threads, $show_deleted, $show_merged_thread, $current_user, $blockNumber, $thread_id);

        $ordered_threads = $thread_block['threads'];
        $blockNumber = $thread_block['block_number'];

        foreach ($ordered_threads as &$thread) {
            $list = array();
            foreach(explode("|", $thread['categories_ids']) as $id ) {
                $list[] = (int)$id;
            }
            $thread['categories_ids'] = $list;
            $thread['categories_desc'] = explode("|", $thread['categories_desc']);
            $thread['categories_color'] = explode("|", $thread['categories_color']);
        }
        return $ordered_threads;
    }

    public function getThreads(){
        $pageNumber = !empty($_GET["page_number"]) && is_numeric($_GET["page_number"]) ? (int)$_GET["page_number"] : 1;
        $show_deleted = $this->showDeleted();
        $currentCourse = $this->core->getConfig()->getCourse();
        $show_merged_thread = $this->showMergedThreads($currentCourse);
        $categories_ids = array_key_exists('thread_categories', $_POST) && !empty($_POST["thread_categories"]) ? explode("|", $_POST['thread_categories']) : array();
        $thread_status = array_key_exists('thread_status', $_POST) && ($_POST["thread_status"] === "0" || !empty($_POST["thread_status"])) ? explode("|", $_POST['thread_status']) : array();
        $unread_threads = ($_POST["unread_select"] === 'true');
        if(empty($categories_ids) && !empty($_COOKIE[$currentCourse . '_forum_categories'])){
            $categories_ids = explode("|", $_COOKIE[$currentCourse . '_forum_categories']);
        }
        if(empty($thread_status) && !empty($_COOKIE['forum_thread_status'])){
            $thread_status = explode("|", $_COOKIE['forum_thread_status']);
        }
        foreach ($categories_ids as &$id) {
            $id = (int)$id;
        }
        foreach ($thread_status as &$status) {
            $status = (int)$status;
        }
        $max_thread = 0;
        $threads = $this->getSortedThreads($categories_ids, $max_thread, $show_deleted, $show_merged_thread, $thread_status, $unread_threads, $pageNumber, -1);
        $currentCategoriesIds = (!empty($_POST['currentCategoriesId'])) ? explode("|", $_POST["currentCategoriesId"]) : array();
        $currentThreadId = array_key_exists('currentThreadId', $_POST) && !empty($_POST["currentThreadId"]) && is_numeric($_POST["currentThreadId"]) ? (int)$_POST["currentThreadId"] : -1;
        $this->core->getOutput()->renderOutput('forum\ForumThread', 'showAlteredDisplayList', $threads, true, $currentThreadId, $currentCategoriesIds);
        $this->core->getOutput()->useHeader(false);
        $this->core->getOutput()->useFooter(false);
        return $this->core->getOutput()->renderJson(array(
                "html" => $this->core->getOutput()->getOutput(),
                "count" => count($threads),
                "page_number" => $pageNumber,
            ));
    }

    public function showThreads(){
        $user = $this->core->getUser()->getId();
        $currentCourse = $this->core->getConfig()->getCourse();
        $category_id = in_array('thread_category', $_POST) ? $_POST['thread_category'] : -1;
        $category_id = array($category_id);
        $thread_status = array();
        $new_posts = array();
        $unread_threads = false;
        if(!empty($_COOKIE[$currentCourse . '_forum_categories']) &&  $category_id[0] == -1 ) {
            $category_id = explode('|', $_COOKIE[$currentCourse . '_forum_categories']);
        }
        if(!empty($_COOKIE['forum_thread_status'])){
            $thread_status = explode("|", $_COOKIE['forum_thread_status']);
        }
        if(!empty($_COOKIE['unread_select_value'])){
            $unread_threads = ($_COOKIE['unread_select_value'] === 'true');
        }
        foreach ($category_id as &$id) {
            $id = (int)$id;
        }
        foreach ($thread_status as &$status) {
            $status = (int)$status;
        }

        $max_thread = 0;
        $show_deleted = $this->showDeleted();
        $show_merged_thread = $this->showMergedThreads($currentCourse);
        $current_user = $this->core->getUser()->getId();

        $posts = null;
        $option = 'tree';
        if(!empty($_REQUEST['option'])) {
           $option = $_REQUEST['option'];
        } else if(!empty($_COOKIE['forum_display_option'])) {
           $option = $_COOKIE['forum_display_option'];
        }
        $option = ($this->core->getUser()->accessGrading() || $option != 'alpha') ? $option : 'tree';
        if(!empty($_REQUEST["thread_id"])){
            $thread_id = (int)$_REQUEST["thread_id"];
            $this->core->getQueries()->markNotificationAsSeen($user, -2, (string)$thread_id);
            $unread_p = $this->core->getQueries()->getUnviewedPosts($thread_id, $current_user);
            foreach ($unread_p as $up) {
                $new_posts[] = $up["id"];
            }
            $thread = $this->core->getQueries()->getThread($thread_id);
            if(!empty($thread)) {
                $thread = $thread[0];
                if($thread['merged_thread_id'] != -1){
                    // Redirect merged thread to parent
                    $this->core->addSuccessMessage("Requested thread is merged into current thread.");
                    $this->core->redirect($this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread['merged_thread_id'])));
                    return;
                }
                if($option == "alpha"){
                    $posts = $this->core->getQueries()->getPostsForThread($current_user, $thread_id, $show_deleted, 'alpha');
                } else if($option == "reverse-time") {
                    $posts = $this->core->getQueries()->getPostsForThread($current_user, $thread_id, $show_deleted, 'reverse-time');
                }else {
                    $posts = $this->core->getQueries()->getPostsForThread($current_user, $thread_id, $show_deleted, 'tree');
                }
                if(empty($posts)){
                    $this->core->addErrorMessage("No posts found for selected thread.");
                }
            }

        }
        if(empty($_REQUEST["thread_id"]) || empty($posts)) {
            $new_posts = $this->core->getQueries()->getUnviewedPosts(-1, $current_user);
            $posts = $this->core->getQueries()->getPostsForThread($current_user, -1, $show_deleted);
        }
        $thread_id = -1;
        if(!empty($posts)){
            $thread_id = $posts[0]["thread_id"];
        }
        $pageNumber = 0;
        $threads = $this->getSortedThreads($category_id, $max_thread, $show_deleted, $show_merged_thread, $thread_status, $unread_threads, $pageNumber, $thread_id);

        $this->core->getOutput()->renderOutput('forum\ForumThread', 'showForumThreads', $user, $posts, $new_posts, $threads, $show_deleted, $show_merged_thread, $option, $max_thread, $pageNumber);
    }

    private function getAllowedCategoryColor() {
        $colors = array();
        $colors["MAROON"]   = "#800000";
        $colors["OLIVE"]    = "#808000";
        $colors["GREEN"]    = "#008000";
        $colors["TEAL"]     = "#008080";
        $colors["NAVY"]     = "#000080";
        $colors["PURPLE"]   = "#800080";
        $colors["GRAY"]     = "#808080";
        $colors["BLACK"]    = "#000000";
        return $colors;
    }

    public function showCreateThread(){
         $this->core->getOutput()->renderOutput('forum\ForumThread', 'createThread', $this->getAllowedCategoryColor());
    }

    public function getHistory(){
        $post_id = $_POST["post_id"];
        $output = array();
        if($this->core->getUser()->accessGrading()){
            $_post = array();
            $older_posts = $this->core->getQueries()->getPostHistory($post_id);
            $current_post = $this->core->getQueries()->getPost($post_id);
            $oc = $current_post["author_user_id"];
            $anon = $current_post["anonymous"];
            foreach ($older_posts as $post) {
                $_post['user'] = !$this->modifyAnonymous($oc) && $oc == $post["edit_author"] && $anon ? '' : $post["edit_author"];
                $_post['content'] = $this->core->getOutput()->renderTemplate('forum\ForumThread', 'filter_post_content',  $post["content"]);
                $_post['post_time'] = DateUtils::parseDateTime($post['edit_timestamp'], $this->core->getConfig()->getTimezone())->format("n/j g:i A");
                $output[] = $_post;
            }
            if(count($output) == 0) {
                // Current post
                $_post['user'] = !$this->modifyAnonymous($oc) && $anon ? '' : $oc;
                $_post['content'] = $this->core->getOutput()->renderTemplate('forum\ForumThread', 'filter_post_content',  $current_post["content"]);
                $_post['post_time'] = DateUtils::parseDateTime($current_post['timestamp'], $this->core->getConfig()->getTimezone())->format("n/j g:i A");
                $output[] = $_post;
            }
            // Fetch additional information
            foreach ($output as &$_post) {
                $emptyUser = empty($_post['user']);
                $_post['user_info'] = $emptyUser ? array('first_name' => 'Anonymous', 'last_name' => '', 'email' => '') : $this->core->getQueries()->getDisplayUserInfoFromUserId($_post['user']);
                $_post['is_staff_post'] = $emptyUser ? false : $this->core->getQueries()->isStaffPost($_post['user']);
            }
        } else {
            $output['error'] = "You do not have permissions to do that.";
        }
        $this->core->getOutput()->renderJson($output);
        return $this->core->getOutput()->getOutput();
    }

    public function modifyAnonymous($author) {
        return $this->core->getUser()->accessFullGrading() || $this->core->getUser()->getId() === $author;
    }

    public function getEditPostContent(){
        $post_id = $_POST["post_id"];
        if(!empty($post_id)) {
            $result = $this->core->getQueries()->getPost($post_id);
            if($this->core->getAccess()->canI("forum.modify_post", ['post_author' => $result['author_user_id']])) {
                $output = array();
                $output['post'] = $result["content"];
                $output['post_time'] = $result['timestamp'];
                $output['anon'] = $result['anonymous'];
                $output['change_anon'] = $this->modifyAnonymous($result["author_user_id"]);
                $output['user'] = $output['anon'] ? 'Anonymous' : $result["author_user_id"];
                if(isset($_POST["thread_id"])) {
                    $this->getThreadContent($_POST["thread_id"], $output);
                }
                $this->core->getOutput()->renderJson($output);
            } else {
                $this->core->getOutput()->renderJson(array('error' => "You do not have permissions to do that."));
            }
        }
        return $this->core->getOutput()->getOutput();
    }

    private function getThreadContent($thread_id, &$output){
        $result = $this->core->getQueries()->getThread($thread_id)[0];
        $output['lock_thread_date'] = $result['lock_thread_date'];
        $output['title'] = $result["title"];
        $output['categories_ids'] = $this->core->getQueries()->getCategoriesIdForThread($thread_id);
        $output['thread_status'] = $result["status"];
    }

    public function showStats(){
        $posts = $this->core->getQueries()->getPosts();
        $num_posts = count($posts);
        $users = array();
        for($i=0;$i<$num_posts;$i++){
            $user = $posts[$i]["author_user_id"];
            $content = $posts[$i]["content"];
            if(!isset($users[$user])){
                $users[$user] = array();
                $u = $this->core->getQueries()->getSubmittyUser($user);
                $users[$user]["first_name"] = htmlspecialchars($u -> getDisplayedFirstName());
                $users[$user]["last_name"] = htmlspecialchars($u -> getDisplayedLastName());
                $users[$user]["posts"]=array();
                $users[$user]["id"]=array();
                $users[$user]["timestamps"]=array();
                $users[$user]["total_threads"]=0;
                $users[$user]["num_deleted_posts"] = count($this->core->getQueries()->getDeletedPostsByUser($user));
            }
            if($posts[$i]["parent_id"]==-1){
                $users[$user]["total_threads"]++;
            }
            $users[$user]["posts"][] = $content;
            $users[$user]["id"][] = $posts[$i]["id"];
            $users[$user]["timestamps"][] = DateUtils::parseDateTime($posts[$i]["timestamp"], $this->core->getConfig()->getTimezone())->format("n/j g:i A");
            $users[$user]["thread_id"][] = $posts[$i]["thread_id"];
            $users[$user]["thread_title"][] = $this->core->getQueries()->getThreadTitle($posts[$i]["thread_id"]);


        }
        ksort($users);
        $this->core->getOutput()->renderOutput('forum\ForumThread', 'statPage', $users);
    }

    public function mergeThread(){
        $parent_thread_id = $_POST["merge_thread_parent"];
        $child_thread_id = $_POST["merge_thread_child"];
        preg_match('/\((.*?)\)/', $parent_thread_id, $result);
        $parent_thread_id = $result[1];
        $thread_id = $child_thread_id;
        if($this->core->getAccess()->canI("forum.merge_thread")){
            if(is_numeric($parent_thread_id) && is_numeric($child_thread_id)) {
                $message = "";
                $child_root_post = -1;
                if($this->core->getQueries()->mergeThread($parent_thread_id, $child_thread_id, $message, $child_root_post)) {
                    $child_thread_dir = FileUtils::joinPaths(FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "forum_attachments"), $child_thread_id);
                    if(is_dir($child_thread_dir)) {
                        $parent_thread_dir = FileUtils::joinPaths(FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "forum_attachments"), $parent_thread_id);
                        if(!is_dir($parent_thread_dir)) {
                            FileUtils::createDir($parent_thread_dir);
                        }
                        $child_posts_dirs = FileUtils::getAllDirs($child_thread_dir);
                        foreach ($child_posts_dirs as $post_id) {
                            $child_post_dir = FileUtils::joinPaths($child_thread_dir, $post_id);
                            $parent_post_dir = FileUtils::joinPaths($parent_thread_dir, $post_id);
                            rename($child_post_dir, $parent_post_dir);
                        }
                    }
                    // Notify thread author
                    $child_thread = $this->core->getQueries()->getThread($child_thread_id)[0];
                    $child_thread_author = $child_thread['created_by'];
                    $child_thread_title = $child_thread['title'];
                    $parent_thread_title =$this->core->getQueries()->getThreadTitle($parent_thread_id)['title'];
                    $notification = new Notification($this->core, array('component' => 'forum', 'type' => 'merge_thread', 'child_thread_id' => $child_thread_id, 'parent_thread_id' => $parent_thread_id, 'child_thread_title' => $child_thread_title, 'parent_thread_title' => $parent_thread_title, 'child_thread_author' => $child_thread_author, 'child_root_post' => $child_root_post));
                    $this->core->getQueries()->pushNotification($notification);
                    $this->core->addSuccessMessage("Threads merged!");
                    $thread_id = $parent_thread_id;
                } else {
                    $this->core->addErrorMessage("Merging Failed! ".$message);
                }
            }
        } else {
            $this->core->addErrorMessage("You do not have permissions to do that.");
        }
        $this->core->redirect($this->core->buildUrl(array('component' => 'forum', 'page' => 'view_thread', 'thread_id' => $thread_id)));
    }

    private function sendEmailAnnouncement($thread_title, $thread_content) {
      $class_list = $this->core->getQueries()->getClassEmailList();
      $formatted_body = "An Instructor/TA made an announcement in the Submitty discussion forum:\n\n".$thread_content;

      foreach($class_list as $student_email) {
          $email_data = array(
              "subject" => $thread_title,
              "body" => $formatted_body,
              "recipient" => $student_email["user_email"]
          );

          $announcement_email = new Email($this->core, $email_data);
          $this->core->getQueries()->createEmail($announcement_email);
      }

    }

}
