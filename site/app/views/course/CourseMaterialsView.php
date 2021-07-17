<?php

namespace app\views\course;

use app\entities\course\CourseMaterial;
use app\libraries\FileUtils;
use app\libraries\Utils;
use app\models\User;
use app\views\AbstractView;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CourseMaterialsView extends AbstractView {
    /**
     * @param User $user
     * @param CourseMaterial[] $course_materials_db
     */
    public function listCourseMaterials(User $user, array $course_materials_db) {
        $this->core->getOutput()->addInternalCss(FileUtils::joinPaths('fileinput.css'));
        $this->core->getOutput()->addVendorJs(FileUtils::joinPaths('flatpickr', 'flatpickr.min.js'));
        $this->core->getOutput()->addVendorCss(FileUtils::joinPaths('flatpickr', 'flatpickr.min.css'));
        $this->core->getOutput()->addVendorJs(FileUtils::joinPaths('flatpickr', 'plugins', 'shortcutButtons', 'shortcut-buttons-flatpickr.min.js'));
        $this->core->getOutput()->addVendorCss(FileUtils::joinPaths('flatpickr', 'plugins', 'shortcutButtons', 'themes', 'light.min.css'));
        $this->core->getOutput()->addBreadcrumb("Course Materials");
        $this->core->getOutput()->enableMobileViewport();
        $user_group = $user->getGroup();
        $user_section = $user->getRegistrationSection();

        $file_release_dates = [];
        $in_dir = [];
        $file_sections = [];
        $hide_from_students = [];
        $external_link = [];
        $priorities = [];
        //Get the expected course materials path and files
        $upload_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads");
        $expected_path = FileUtils::joinPaths($this->core->getConfig()->getCoursePath(), "uploads", "course_materials");
        $this->core->getOutput()->addInternalJs("drag-and-drop.js");

        $course_materials = [];
        foreach ($course_materials_db as $course_material) {
            $course_materials[$course_material->getPath()] = $course_material;
        }

        foreach ($course_materials as $path => $material) {
            $filepath = substr($material->getPath(), strlen($expected_path) + 1);
            $dirs = explode('/', $filepath);
            array_pop($dirs);
            $cur_path = "";
            if (!isset($priorities[$material->getPath()])) {
                $priorities[$material->getPath()] = 0.0;
            }
            $priorities[$material->getPath()] += $material->getPriority();
            foreach ($dirs as $dir) {
                $cur_path .= '/' . $dir;
                $path = FileUtils::joinPaths($expected_path, $cur_path);
                $priorities[$material->getPath()] += $course_materials[$path]->getPriority();
            }
        }

        $sort_priority = function (CourseMaterial $a, CourseMaterial $b) use ($priorities) {
            if ($priorities[$b->getPath()] == $priorities[$a->getPath()]) {
                $apath = explode('/', $a->getPath());
                $aname = array_pop($apath);
                $bpath = explode('/', $b->getPath());
                $bname = array_pop($bpath);
                if (strtolower($aname) < strtolower($bname)) {
                    return -1;
                }
                else {
                    return 1;
                }
            }
            elseif ($priorities[$a->getPath()] < $priorities[$b->getPath()]) {
                return -1;
            }
            else {
                return 1;
            }
        };
        uasort($course_materials, $sort_priority);

        $restored = [];
        foreach ($priorities as $key => $value) {
            $restored[$key] = $course_materials[$key]->getPriority();
        }
        $priorities = $restored;

        $start_dir_name = "course_materials";
        $files[$start_dir_name] = [];
        $now_date_time = $this->core->getDateTimeNow();

        $course_materials_array = [];
        foreach ($course_materials as $course_material) {
            if ($course_material->isDir()) {
                $filepath = substr($course_material->getPath(), strlen($expected_path) + 1);
                $path = explode('/', $filepath);
                $working_dir = &$files[$start_dir_name];
                $filename = array_pop($path);
                foreach ($path as $dir) {
                    $working_dir = &$working_dir[$dir];
                }
                if (!isset($working_dir[$filename])) {
                    $working_dir[$filename] = [];
                }
                continue;
            }

            array_push($in_dir, $course_material->getPath());

            $path = explode('/', $course_material->getPath());
            $filename = array_pop($path);
            $course_materials_array[] = $filename;
            if ($course_material->getSections()->count() > 0) {
                foreach ($course_material->getSections() as $section) {
                    $file_sections[$course_material->getPath()][] = $section->getSectionId();
                }
            }
            $release_date = $course_material->getReleaseDate();
            $hide_from_students[$course_material->getPath()] = $course_material->isHiddenFromStudents();

            if ($course_material->isLink()) {
                $contents = json_decode(file_get_contents($course_material->getPath()));
                $external_link[$course_material->getPath()] = [$contents->url, $contents->name];
            }

            if ($release_date > $now_date_time) {
                if ($user_group === 4) {
                    continue;
                }
            }
            $file_release_dates[$course_material->getPath()] = $release_date
                ->setTimezone($this->core->getConfig()->getTimezone())
                ->format($this->core->getConfig()->getDateTimeFormat()->getFormat('date_time_picker'));
            $filepath = substr($course_material->getPath(), strlen($expected_path) + 1);
            $path = explode('/', $filepath);
            $working_dir = &$files[$start_dir_name];
            $filename = array_pop($path);
            foreach ($path as $dir) {
                $working_dir = &$working_dir[$dir];
            }
            $working_dir[$filename] = $course_material->getPath();
        }

        if ($user_group !== 1 && count($course_materials_array) == 0) {
            $this->core->addErrorMessage("You have no permission to access this page");
            return new RedirectResponse($this->core->buildCourseUrl());
        }

        $max_size = Utils::returnBytes(ini_get('upload_max_filesize'));
        $max_size_string = Utils::formatBytes("MB", $max_size) . " (" . Utils::formatBytes("KB", $max_size) . ")";
        $reg_sections = $this->core->getQueries()->getRegistrationSections();

        return $this->core->getOutput()->renderTwigTemplate("course/CourseMaterials.twig", [
            "courseMaterialsArray" => $course_materials_array,
            'date_format' => 'Y-m-d H:i:s',
            "folderPath" => $expected_path,
            "uploadFolderPath" => $upload_path,
            "submissions" => $files,
            "priorities" => $priorities,
            "fileReleaseDates" => $file_release_dates,
            "userGroup" => $user_group,
            "inDir" => $in_dir,
            "csrf_token" => $this->core->getCsrfToken(),
            "delete_url" => $this->core->buildCourseUrl(["course_materials", "delete"]),
            "delete_folder_url" => $this->core->buildCourseUrl(["course_materials", "delete_folder"]),
            "max_size_string" => $max_size_string,
            "display_file_url" => $this->core->buildCourseUrl(['display_file']),
            "user_section" => $user_section,
            "reg_sections" => $reg_sections,
            "file_sections" => $file_sections,
            "hide_from_students" => $hide_from_students,
            "external_link" => $external_link,
            "materials_exist" => count($course_materials) != 0
        ]);
    }
}
