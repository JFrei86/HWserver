<?php

namespace app\controllers\course;

use app\controllers\AbstractController;
use app\entities\course\CourseMaterialSection;
use app\libraries\DateUtils;
use app\libraries\FileUtils;
use app\libraries\response\JsonResponse;
use app\libraries\response\RedirectResponse;
use app\libraries\response\WebResponse;
use app\libraries\Utils;
use app\entities\course\CourseMaterial;
use app\views\course\CourseMaterialsView;
use Symfony\Component\Routing\Annotation\Route;
use app\libraries\routers\AccessControl;

class CourseMaterialsController extends AbstractController {
    /**
     * @Route("/courses/{_semester}/{_course}/course_materials")
     */
    public function viewCourseMaterialsPage(): WebResponse {
        $course_materials = $this->core->getCourseEntityManager()
            ->getRepository(CourseMaterial::class)
            ->findAll();
        return new WebResponse(
            CourseMaterialsView::class,
            'listCourseMaterials',
            $this->core->getUser(),
            $course_materials
        );
    }

    /**
     * @Route("/courses/{_semester}/{_course}/course_materials/delete")
     */
    public function deleteCourseMaterial($path) {
        // security check
        $dir = "course_materials";
        $path = $this->core->getAccess()->resolveDirPath($dir, htmlspecialchars_decode(rawurldecode($path)));

        if (!$this->core->getAccess()->canI("path.write", ["path" => $path, "dir" => $dir])) {
            $message = "You do not have access to that page.";
            $this->core->addErrorMessage($message);
            return new RedirectResponse($this->core->buildCourseUrl(['course_materials']));
        }

        $all_files = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)->findAll();

        foreach ($all_files as $file) {
            if (Utils::startsWith($file->getPath(), $path)) {
                $this->core->getCourseEntityManager()->remove($file);
            }
        }
        $this->core->getCourseEntityManager()->flush();
        $success = false;
        if (is_dir($path)) {
            $success = FileUtils::recursiveRmdir($path);
        }
        else {
            $success = unlink($path);
        }

        if ($success) {
            $this->core->addSuccessMessage(basename($path) . " has been successfully removed.");
        }
        else {
            $this->core->addErrorMessage("Failed to remove " . basename($path));
        }

        return new RedirectResponse($this->core->buildCourseUrl(['course_materials']));
    }

    /**
     * @Route("/courses/{_semester}/{_course}/course_materials/download_zip")
     */
    public function downloadCourseMaterialZip($dir_name, $path) {
        $root_path = realpath(htmlspecialchars_decode(rawurldecode($path)));

        // check if the user has access to course materials
        if (!$this->core->getAccess()->canI("path.read", ["dir" => 'course_materials', "path" => $root_path])) {
            $this->core->getOutput()->showError("You do not have access to this folder");
            return false;
        }

        $temp_dir = "/tmp";
        // makes a random zip file name on the server
        $temp_name = uniqid($this->core->getUser()->getId(), true);
        $zip_name = $temp_dir . "/" . $temp_name . ".zip";
        // replacing any whitespace with underscore char.
        $zip_file_name = preg_replace('/\s+/', '_', $dir_name) . ".zip";
        // Always delete the zip file after script execution
        register_shutdown_function('unlink', $zip_name);

        $zip = new \ZipArchive();
        $zip->open($zip_name, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $isFolderEmptyForMe = true;
        // iterate over the files inside the requested directory
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root_path),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $course_material = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
                    ->findOneBy(['path' => $file_path]);
                if ($course_material !== null) {
                    if (!$this->core->getUser()->accessGrading()) {
                        // only add the file if the section of student is allowed and course material is released!
                        if ($course_material->isSectionAllowed($this->core->getUser()) && $course_material->getReleaseDate() < $this->core->getDateTimeNow()) {
                            $relativePath = substr($file_path, strlen($root_path) + 1);
                            $isFolderEmptyForMe = false;
                            $zip->addFile($file_path, $relativePath);
                        }
                    }
                    else {
                        // For graders and instructors, download the course-material unconditionally!
                        $relativePath = substr($file_path, strlen($root_path) + 1);
                        $isFolderEmptyForMe = false;
                        $zip->addFile($file_path, $relativePath);
                    }
                }
            }
        }

        // If the Course Material Folder Does not contain anything for current user display an error message.
        if ($isFolderEmptyForMe) {
            $this->core->getOutput()->showError("You do not have access to this folder");
            return false;
        }
        $zip->close();
        header("Content-type: application/zip");
        header("Content-Disposition: attachment; filename=$zip_file_name");
        header("Content-length: " . filesize($zip_name));
        header("Pragma: no-cache");
        header("Expires: 0");
        readfile("$zip_name");
    }

    /**
     * @Route("/courses/{_semester}/{_course}/course_materials/modify_timestamp")
     * @AccessControl(role="INSTRUCTOR")
     */
    public function modifyCourseMaterialsFileTimeStamp($filenames, $newdatatime): JsonResponse {
        $data = $_POST['fn'];

        if (!isset($newdatatime)) {
            $this->core->redirect($this->core->buildCourseUrl(['course_materials']));
        }

        $new_data_time = htmlspecialchars($newdatatime);
        $new_data_time = DateUtils::parseDateTime($new_data_time, $this->core->getDateTimeNow()->getTimezone());

        //only one will not iterate correctly
        if (is_string($data)) {
            $data = [$data];
        }

        $has_error = false;
        $success = false;

        foreach ($data as $filename) {
            if (!isset($filename)) {
                $this->core->redirect($this->core->buildCourseUrl(['course_materials']));
            }

            $file_name = htmlspecialchars($filename);
            $course_material = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
                ->findOneBy(['path' => $file_name]);
            if ($course_material !== null) {
                $course_material->setReleaseDate($new_data_time);
            }
            else {
                $has_error = true;
            }
        }

        $this->core->getCourseEntityManager()->flush();

        if ($has_error) {
            return JsonResponse::getErrorResponse("Failed to find one of the course materials.");
        }
        return JsonResponse::getSuccessResponse("Time successfully set.");
    }

    private function recursiveEditFolder(array $course_materials, CourseMaterial $main_course_material) {
        foreach ($course_materials as $course_material) {
            if (
                Utils::startsWith($course_material->getPath(), $main_course_material->getPath())
                && $course_material->getPath() != $main_course_material->getPath()
            ) {
                if ($course_material->isDir()) {
                    $this->recursiveEditFolder($course_materials, $course_material);
                }
                else {
                    $_POST['requested_path'] = $course_material->getPath();
                    $this->ajaxEditCourseMaterialsFiles(false);
                }
            }
        }
    }

    /**
     * @Route("/courses/{_semester}/{_course}/course_materials/edit", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function ajaxEditCourseMaterialsFiles(bool $flush = true): JsonResponse {
        $requested_path = $_POST['requested_path'] ?? '';
        if ($requested_path === '') {
            return JsonResponse::getErrorResponse("Requested path cannot be empty");
        }
        $course_material = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
            ->findOneBy(['path' => $requested_path]);
        if ($course_material == null) {
            return JsonResponse::getErrorResponse("Course material not found");
        }

        if ($course_material->isDir()) {
            if (isset($_POST['sort_priority'])) {
                $course_material->setPriority($_POST['sort_priority']);
                unset($_POST['sort_priority']);
            }
            if (
                (isset($_POST['sections_lock'])
                || isset($_POST['hide_from_students'])
                || isset($_POST['release_time']))
                && isset($_POST['folder_update'])
                && $_POST['folder_update'] == 'true'
            ) {
                $course_materials = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
                    ->findAll();
                $this->recursiveEditFolder($course_materials, $course_material);
            }
            $this->core->getCourseEntityManager()->flush();
            return JsonResponse::getSuccessResponse("Success");
        }

        //handle sections here

        if (isset($_POST['sections_lock']) && $_POST['sections_lock'] == "true") {
            if ($_POST['sections'] === "") {
                $sections = null;
            }
            else {
                $sections = explode(",", $_POST['sections']);
            }
            if ($sections != null) {
                $keep_ids = [];

                foreach ($sections as $section) {
                    $keep_ids[] = $section;
                    $found = false;
                    foreach ($course_material->getSections() as $course_section) {
                        if ($section === $course_section->getSectionId()) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $course_material_section = new CourseMaterialSection($section, $course_material);
                        $course_material->addSection($course_material_section);
                    }
                }

                foreach ($course_material->getSections() as $section) {
                    if (!in_array($section->getSectionId(), $keep_ids)) {
                        $course_material->removeSection($section);
                    }
                }
            }
        }
        elseif ($_POST['sections_lock'] == "false") {
            $course_material->getSections()->clear();
        }
        if (isset($_POST['hide_from_students'])) {
            $course_material->setHiddenFromStudents($_POST['hide_from_students'] == 'on');
        }
        if (isset($_POST['sort_priority'])) {
            $course_material->setPriority($_POST['sort_priority']);
        }

        if (isset($_POST['release_time']) && $_POST['release_time'] != '') {
            $date_time = DateUtils::parseDateTime($_POST['release_time'], $this->core->getDateTimeNow()->getTimezone());
            $course_material->setReleaseDate($date_time);
        }

        if ($flush) {
            $this->core->getCourseEntityManager()->flush();
        }

        return JsonResponse::getSuccessResponse("Successfully uploaded!");
    }

    /**
     * @Route("/courses/{_semester}/{_course}/course_materials/upload", methods={"POST"})
     * @AccessControl(role="INSTRUCTOR")
     */
    public function ajaxUploadCourseMaterialsFiles(): JsonResponse {
        $details = [];

        $expand_zip = "";
        if (isset($_POST['expand_zip'])) {
            $expand_zip = $_POST['expand_zip'];
        }

        $requested_path = "";
        if (isset($_POST['requested_path'])) {
            $requested_path = $_POST['requested_path'];
        }
        $details['path'][0] = $requested_path;

        if (isset($_POST['release_time'])) {
            $details['release_date'] = $_POST['release_time'];
        }

        $sections_lock = false;
        if (isset($_POST['sections_lock'])) {
            $sections_lock = $_POST['sections_lock'] == "true";
        }
        $details['section_lock'] = $sections_lock;

        if (isset($_POST['sections']) && $sections_lock) {
            $sections = $_POST['sections'];
            $sections_exploded = @explode(",", $sections);
            $details['sections'] = $sections_exploded;
        }
        else {
            $details['sections'] = null;
        }

        if (isset($_POST['hide_from_students'])) {
            $details['hidden_from_students'] = $_POST['hide_from_students'] == "on";
        }

        if (isset($_POST['sort_priority'])) {
            $details['priority'] = $_POST['sort_priority'];
        }

        $n = strpos($requested_path, '..');
        if ($n !== false) {
            return JsonResponse::getErrorResponse("Invalid filepath.");
        }

        $url_title = null;
        if (isset($_POST['url_title'])) {
            $url_title = $_POST['url_title'];
        }

        $url_url = null;
        if (isset($_POST['url_url'])) {
            if (!filter_var($_POST['url_url'], FILTER_VALIDATE_URL)) {
                return JsonResponse::getErrorResponse("Invalid url");
            }
            $url_url = $_POST['url_url'];
        }

        $external_link_file_name = "";
        if (isset($url_title) && isset($url_url)) {
            $external_link_file_name = "external-link-" . $this->core->getDateTimeNow()->format('Y-m-d-H-i-s') . ".json";

            $external_link_json = json_encode(
                [
                    "name" => $url_title,
                    "url" => $url_url,
                ]
            );

            $temp_external_link_file = tmpfile();
            fwrite($temp_external_link_file, $external_link_json);

            $_FILES["files1"]["name"][] = $external_link_file_name;
            $_FILES["files1"]["type"][] = "text/json";
            $_FILES["files1"]["tmp_name"][] = stream_get_meta_data($temp_external_link_file)['uri'];
            $_FILES["files1"]["error"][] = 0;
            $_FILES["files1"]["size"][] = 10;//Size does not really matter because it is just a basic json file that holds a url
        }

        $uploaded_files = [];
        if (isset($_FILES["files1"])) {
            $uploaded_files[1] = $_FILES["files1"];
        }

        if (empty($uploaded_files)) {
            return JsonResponse::getErrorResponse("No files were submitted.");
        }

        $status = FileUtils::validateUploadedFiles($_FILES["files1"]);
        if (array_key_exists("failed", $status)) {
            return JsonResponse::getErrorResponse("Failed to validate uploads " . $status['failed']);
        }

        $file_size = 0;
        foreach ($status as $stat) {
            $file_size += $stat['size'];
            if ($stat['success'] === false) {
                return JsonResponse::getErrorResponse($stat['error']);
            }
        }

        $max_size = Utils::returnBytes(ini_get('upload_max_filesize'));
        if ($file_size > $max_size) {
            return JsonResponse::getErrorResponse("File(s) uploaded too large. Maximum size is " . ($max_size / 1024) . " kb. Uploaded file(s) was " . ($file_size / 1024) . " kb.");
        }

        // creating uploads/course_materials directory
        $upload_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "course_materials");
        if (!FileUtils::createDir($upload_path)) {
            return JsonResponse::getErrorResponse("Failed to make image path.");
        }
        $dirs_to_make = null;
        // create nested path
        if (!empty($requested_path)) {
            $upload_nested_path = FileUtils::joinPaths($upload_path, $requested_path);
            if (!FileUtils::createDir($upload_nested_path, true)) {
                return JsonResponse::getErrorResponse("Failed to make image path.");
            }
            $dirs_to_make = [];
            $dirs = explode('/', $requested_path);
            $j = count($dirs);
            foreach ($dirs as $dir) {
                for ($i = 0; $i < $j; $i++) {
                    if (!isset($dirs_to_make[$i])) {
                        $dirs_to_make[$i] = $upload_path . '/' . $dir;
                    }
                    else {
                        $dirs_to_make[$i] .= '/' . $dir;
                    }
                }
                $j--;
            }
            $upload_path = $upload_nested_path;
        }

        $count_item = count($status);
        if (isset($uploaded_files[1])) {
            $index = 0;
            for ($j = 0; $j < $count_item; $j++) {
                $is_external_link_file = $uploaded_files[1]["name"][$j] == $external_link_file_name;
                if (is_uploaded_file($uploaded_files[1]["tmp_name"][$j]) || $is_external_link_file) {
                    $dst = FileUtils::joinPaths($upload_path, $uploaded_files[1]["name"][$j]);

                    $cm = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
                        ->findOneBy(['path' => $dst]);
                    if ($cm != null) {
                        return JsonResponse::getErrorResponse("A file already exists with path " .
                            $dst . ". Please delete the current file if you would like to use this path.");
                    }

                    if (strlen($dst) > 255) {
                        return JsonResponse::getErrorResponse("Path cannot have a string length of more than 255 chars.");
                    }

                    $is_zip_file = false;

                    if (mime_content_type($uploaded_files[1]["tmp_name"][$j]) == "application/zip") {
                        if (FileUtils::checkFileInZipName($uploaded_files[1]["tmp_name"][$j]) === false) {
                            return JsonResponse::getErrorResponse("You may not use quotes, backslashes, or angle brackets in your filename for files inside " . $uploaded_files[1]['name'][$j] . ".");
                        }
                        $is_zip_file = true;
                    }
                    //cannot check if there are duplicates inside zip file, will overwrite
                    //it is convenient for bulk uploads
                    if ($expand_zip == 'on' && $is_zip_file === true) {
                        //get the file names inside the zip to write to the JSON file

                        $zip = new \ZipArchive();
                        $res = $zip->open($uploaded_files[1]["tmp_name"][$j]);

                        if (!$res) {
                            return JsonResponse::getErrorResponse("Failed to open zip archive");
                        }

                        $entries = [];
                        $disallowed_folders = [".svn", ".git", ".idea", "__macosx"];
                        $disallowed_files = ['.ds_store'];
                        for ($i = 0; $i < $zip->numFiles; $i++) {
                            $entries[] = $zip->getNameIndex($i);
                        }
                        $entries = array_filter($entries, function ($entry) use ($disallowed_folders, $disallowed_files) {
                            $name = strtolower($entry);
                            foreach ($disallowed_folders as $folder) {
                                if (Utils::startsWith($folder, $name)) {
                                    return false;
                                }
                            }
                            if (substr($name, -1) !== '/') {
                                foreach ($disallowed_files as $file) {
                                    if (basename($name) === $file) {
                                        return false;
                                    }
                                }
                            }
                            return true;
                        });
                        $zfiles = array_filter($entries, function ($entry) {
                            return substr($entry, -1) !== '/';
                        });

                        foreach ($zfiles as $zfile) {
                            $path = FileUtils::joinPaths($upload_path, $zfile);
                            $cm = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)
                                ->findOneBy(['path' => $path]);
                            if ($cm != null) {
                                return JsonResponse::getErrorResponse("A file already exists with path " .
                                $path . ". Please delete the current file if you would like to use this path.");
                            }
                        }

                        $zip->extractTo($upload_path, $entries);

                        foreach ($zfiles as $zfile) {
                            $path = FileUtils::joinPaths($upload_path, $zfile);
                            $details['type'][$index] = $is_external_link_file ? CourseMaterial::LINK : CourseMaterial::FILE;
                            $details['path'][$index] = $path;
                            if ($dirs_to_make == null) {
                                $dirs_to_make = [];
                            }
                            $dirs = explode('/', $zfile);
                            array_pop($dirs);
                            $j = count($dirs);
                            $count = count($dirs_to_make);
                            foreach ($dirs as $dir) {
                                for ($i = $count; $i < $j + $count; $i++) {
                                    if (!isset($dirs_to_make[$i])) {
                                        $dirs_to_make[$i] = $upload_path . '/' . $dir;
                                    }
                                    else {
                                        $dirs_to_make[$i] .= '/' . $dir;
                                    }
                                }
                                $j--;
                            }
                            $index++;
                        }
                    }
                    else {
                        if (!@copy($uploaded_files[1]["tmp_name"][$j], $dst)) {
                            return JsonResponse::getErrorResponse("Failed to copy uploaded file {$uploaded_files[1]['name'][$j]} to current location.");
                        }
                        else {
                            $details['type'][$index] = $is_external_link_file ? CourseMaterial::LINK : CourseMaterial::FILE;
                            $details['path'][$index] = $dst;
                            $index++;
                        }
                    }
                }
                else {
                    return JsonResponse::getErrorResponse("The tmp file '{$uploaded_files[1]['name'][$j]}' was not properly uploaded.");
                }
                // Is this really an error we should fail on?
                if (!@unlink($uploaded_files[1]["tmp_name"][$j])) {
                    return JsonResponse::getErrorResponse("Failed to delete the uploaded file {$uploaded_files[1]['name'][$j]} from temporary storage.");
                }
            }
        }
        if ($dirs_to_make != null) {
            $i = -1;
            $new_paths = [];
            foreach ($dirs_to_make as $dir) {
                $cm = $this->core->getCourseEntityManager()->getRepository(CourseMaterial::class)->findOneBy(
                    ['path' => $dir]
                );
                if ($cm == null && !in_array($dir, $new_paths)) {
                    $details['type'][$i] = CourseMaterial::DIR;
                    $details['path'][$i] = $dir;
                    $i--;
                    $new_paths[] = $dir;
                }
            }
        }

        foreach ($details['type'] as $key => $value) {
            $course_material = new CourseMaterial(
                $value,
                $details['path'][$key],
                DateUtils::parseDateTime($details['release_date'], $this->core->getDateTimeNow()->getTimezone()),
                $details['hidden_from_students'],
                $details['priority']
            );
            $this->core->getCourseEntityManager()->persist($course_material);
            if ($details['section_lock']) {
                foreach ($details['sections'] as $section) {
                    $course_material_section = new CourseMaterialSection($section, $course_material);
                    $course_material->addSection($course_material_section);
                }
            }
        }
        $this->core->getCourseEntityManager()->flush();
        return JsonResponse::getSuccessResponse("Successfully uploaded!");
    }
}
