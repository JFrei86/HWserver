<?php

namespace app\controllers\grading;

use app\controllers\AbstractController;
use app\libraries\FileUtils;
use app\libraries\response\RedirectResponse;
use app\models\DisplayImage;
use app\models\User;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\Utils;
use app\libraries\routers\AccessControl;

class ImagesController extends AbstractController {

    /**
     * @Route("/courses/{_semester}/{_course}/student_photos")
     * @AccessControl(role="LIMITED_ACCESS_GRADER")
     */
    public function viewImagesPage() {
        $user_group = $this->core->getUser()->getGroup();
        $grader_sections = $this->core->getUser()->getGradingRegistrationSections();

        $instructor_permission = ($user_group === User::GROUP_INSTRUCTOR);
        $students = $this->core->getQueries()->getAllUsers();
        $this->core->getOutput()->renderOutput(['grading', 'Images'], 'listStudentImages', $students, $grader_sections, $instructor_permission);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/student_photos/upload")
     */
    public function ajaxUploadImagesFiles() {
        if (!$this->core->getUser()->accessAdmin()) {
            return $this->core->getOutput()->renderResultMessage("You have no permission to access this page", false);
        }

        if (!isset($_POST['csrf_token']) || !$this->core->checkCsrfToken($_POST['csrf_token'])) {
            return $this->core->getOutput()->renderResultMessage("Invalid CSRF token.", false, false);
        }

        if (empty($_FILES["files1"])) {
            return $this->core->getOutput()->renderResultMessage("No files to be submitted.", false);
        }

        $status = FileUtils::validateUploadedFiles($_FILES["files1"]);
        //check if we couldn't validate the uploaded files
        if (array_key_exists("failed", $status)) {
            return $this->core->getOutput()->renderResultMessage("Failed to validate uploads " . $status["failed"], false);
        }

        foreach ($status as $stat) {
            if ($stat['success'] === false) {
                return $this->core->getOutput()->renderResultMessage("Error " . $stat['error'], false);
            }
        }

        $uploaded_files = [];
        if (isset($_FILES["files1"])) {
            $uploaded_files[1] = $_FILES["files1"];
        }

        $count_item = count($uploaded_files[1]['name']);

        $file_size = 0;
        if (isset($uploaded_files[1])) {
            $uploaded_files[1]["is_zip"] = [];
            for ($j = 0; $j < $count_item; $j++) {
                if (mime_content_type($uploaded_files[1]["tmp_name"][$j]) == "application/zip") {
                    if (FileUtils::checkFileInZipName($uploaded_files[1]["tmp_name"][$j]) === false) {
                        return $this->core->getOutput()->renderResultMessage("Error: You may not use quotes, backslashes or angle brackets in your filename for files inside " . $uploaded_files[1]["name"][$j] . ".", false);
                    }
                    $uploaded_files[1]["is_zip"][$j] = true;
                    $file_size += FileUtils::getZipSize($uploaded_files[1]["tmp_name"][$j]);
                }
                else {
                    if (FileUtils::isValidFileName($uploaded_files[1]["name"][$j]) === false) {
                        return $this->core->getOutput()->renderResultMessage("Error: You may not use quotes, backslashes or angle brackets in your file name " . $uploaded_files[1]["name"][$j] . ".", false);
                    }
                    elseif (!FileUtils::isValidImage($uploaded_files[1]["tmp_name"][$j])) {
                        return $this->core->getOutput()->renderResultMessage("Error: " . $uploaded_files[1]['name'][$j] . " is not a valid image file.", false);
                    }
                    $uploaded_files[1]["is_zip"][$j] = false;
                    $file_size += $uploaded_files[1]["size"][$j];
                }
            }
        }

        $max_size = Utils::returnBytes(ini_get('upload_max_filesize'));
        if ($file_size > $max_size) {
            return $this->core->getOutput()->renderResultMessage("File(s) uploaded too large.  Maximum size is " . ($max_size / 1024) . " kb. Uploaded file(s) was " . ($file_size / 1024) . " kb.", false);
        }

        // creating uploads/student_images directory

        $upload_img_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "student_images");
        if (!FileUtils::createDir($upload_img_path)) {
            return $this->core->getOutput()->renderResultMessage("Failed to make image path.", false);
        }

        if (isset($uploaded_files[1])) {
            $users = $this->core->getQueries()->getListOfCourseUsers();
            // For each item that was uploaded
            for ($j = 0; $j < $count_item; $j++) {
                // Item was a zip file
                if ($uploaded_files[1]["is_zip"][$j] === true) {
                    $zip = new \ZipArchive();
                    $res = $zip->open($uploaded_files[1]["tmp_name"][$j]);
                    if ($res === true) {
                        //make tmp folder to store class section images
                        $upload_img_path_tmp = FileUtils::joinPaths($upload_img_path, "tmp");
                        $zip->extractTo($upload_img_path_tmp);

                        $files = FileUtils::getAllFilesTrimSearchPath($upload_img_path_tmp, 0);

                        foreach ($files as $file) {
                            $meta = pathinfo($file);
                            $user_id = $meta['filename'];
                            $extension = $meta['extension'];

                            // If user is a member of this course then go ahead and save
                            if (in_array($user_id, $users)) {
                                DisplayImage::saveUserImage($this->core, $user_id, $extension, $file, 'system_images');
                            }
                        }

                        //delete tmp folder
                        FileUtils::recursiveRmdir($upload_img_path_tmp);
                        $zip->close();
                    }
                    else {
                        // If the zip is an invalid zip (say we remove the last character from the zip file
                        // then trying to get the status code will throw an exception and not give us a string
                        // so we have that string hardcoded, otherwise we can just get the status string as
                        // normal.
                        $error_message = ($res == 19) ? "Invalid or uninitialized Zip object" : $zip->getStatusString();
                        return $this->core->getOutput()->renderResultMessage("Could not properly unpack zip file. Error message: " . $error_message . ".", false);
                    }
                }
                else {
                    // Item was an individual image
                    if (is_uploaded_file($uploaded_files[1]["tmp_name"][$j])) {
                        $tmp_path = $uploaded_files[1]["tmp_name"][$j];

                        $meta = explode('.', $uploaded_files[1]['name'][$j]);
                        $user_id = $meta[0];
                        $extension = $meta[1];

                        // If user is a member of this course then go ahead and save
                        if (in_array($user_id, $users)) {
                            DisplayImage::saveUserImage($this->core, $user_id, $extension, $tmp_path, 'system_images');
                        }
                    }
                    else {
                        return $this->core->getOutput()->renderResultMessage("The tmp file '{$uploaded_files[1]['name'][$j]}' was not properly uploaded.", false);
                    }
                }
                // Is this really an error we should fail on?
                if (!@unlink($uploaded_files[1]["tmp_name"][$j])) {
                    return $this->core->getOutput()->renderResultMessage("Failed to delete the uploaded file {$uploaded_files[1]["name"][$j]} from temporary storage.", false);
                }
            }
        }

        $total_count = intval($_POST['file_count']);
        $uploaded_count = count($uploaded_files[1]['tmp_name']);
        $remaining_count = $uploaded_count - $total_count;
        $php_count = ini_get('max_file_uploads');
        if ($total_count < $uploaded_count) {
            $message = "Successfully uploaded {$uploaded_count} images. Could not upload remaining {$remaining_count} files.";
            $message .= " The max number of files you can upload at once is set to {$php_count}.";
        }
        else {
            $message = 'Successfully uploaded!';
        }
        return $this->core->getOutput()->renderResultMessage($message, true);
    }

    /**
     * @Route("/courses/{_semester}/{_course}/flag_user_image", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function flagUserImage() {
        $result = $this->core->getQueries()->updateUserDisplayImageState($_POST['user_id'], 'flagged');

        if ($result) {
            $this->core->addSuccessMessage($_POST['user_id'] . '\'s image was successfully flagged.');
        }
        else {
            $this->core->addErrorMessage('Some error occurred flagging ' . $_POST['user_id'] . '\'s image.');
        }

        return new RedirectResponse($this->core->buildCourseUrl(['student_photos']));
    }
}
