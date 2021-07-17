<?php

namespace tests\app\controllers\course;

use app\controllers\course\CourseMaterialsController;
use app\entities\course\CourseMaterialSection;
use app\libraries\FileUtils;
use app\libraries\Utils;
use app\entities\course\CourseMaterial;
use Doctrine\ORM\EntityRepository;
use phpmock\phpunit\PHPMock;
use tests\BaseUnitTest;
use ZipArchive;

class CourseMaterialsControllerTester extends BaseUnitTest {
    use PHPMock;

    private $core;
    private $config;
    private $upload_path;
    private $course_material;

    public function setUp(): void {
        $this->config = [];
        $this->config['course_path'] = FileUtils::joinPaths(sys_get_temp_dir(), Utils::generateRandomString());
        $this->config['use_mock_time'] = true;
        $_POST['csrf_token'] = "";
        $this->core = $this->createMockCore($this->config, [], [], ["path.write"]);
        $_POST['release_time'] = $this->core->getDateTimeNow()->format("Y-m-d H:i:sO");

        FileUtils::createDir($this->core->getConfig()->getCoursePath() . "/uploads/course_materials", true);
        $this->upload_path = $this->core->getConfig()->getCoursePath() . "/uploads/course_materials";
    }

    public function tearDown(): void {
        FileUtils::recursiveRmdir($this->config['course_path']);
        $_POST = [];
        $_FILES = [];
    }

    private function buildFakeFile($filename, $part = 1) {
        $_FILES["files{$part}"]['name'][] = $filename;
        $_FILES["files{$part}"]['type'][] = mime_content_type($this->upload_path . "/" . $filename);
        $_FILES["files{$part}"]['size'][] = filesize($this->upload_path . "/" . $filename);

        $tmpname = $this->upload_path . "/" . Utils::generateRandomString() . $filename;
        copy($this->upload_path . "/" . $filename, $tmpname);

        $_FILES["files{$part}"]['tmp_name'][] = $tmpname;
        $_FILES["files{$part}"]['error'][] = 0;
    }

    private function buildFakeZipFile($name, $part = 1, $num_files = 1, $depth = 1) {
        $zip = new ZipArchive();

        $filename_full = FileUtils::joinPaths($this->config['course_path'], $name);
        $files = [];
        if ($zip->open($filename_full, ZipArchive::CREATE) === true) {
            $lev = "";
            for ($i = 0; $i < $depth; $i++) {
                for ($j = 0; $j < $num_files; $j++) {
                    $fname = "test" . $j . ".txt";
                    $tmpfile = fopen($this->config['course_path'] . $lev . "/" . $fname, "w");
                    $zip->addFile($this->config['course_path'] . $lev . "/" . $fname);
                    $files[] = $this->config['course_path'] . $lev . "/" . $fname;
                }
                $lev .= "/lev" . $i . "";
                FileUtils::createDir($this->config['course_path'] . $lev);
            }

            $zip->close();
        }

        $_FILES["files{$part}"]['name'][] = $name;
        $_FILES["files{$part}"]['type'][] = mime_content_type($filename_full);
        $_FILES["files{$part}"]['size'][] = filesize($this->config['course_path'] . "/" .   $name);

        $tmpname = $this->config['course_path'] . "/" . Utils::generateRandomString() . $name;
        copy($this->config['course_path'] . "/" . $name, $tmpname);

        $_FILES["files{$part}"]['tmp_name'][] = $tmpname;
        $_FILES["files{$part}"]['error'][] = 0;

        return $files;
    }

    public function buildCourseMaterial(string $name): CourseMaterial {
        $details = [
            'path' => $this->upload_path . $name,
            'hidden_from_students' => false,
            'priority' => 0,
            'release_date' => $this->core->getDateTimeNow(),
            'type' => 0
        ];
        return new CourseMaterial(
            CourseMaterial::FILE,
            $this->upload_path . $name,
            $this->core->getDateTimeNow(),
            false,
            0
        );
    }

    public function testCourseMaterialsUpload() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $name = "foo.txt";
        file_put_contents($this->upload_path . "/" .  $name, 'a');
        $this->buildFakeFile($name);

        $course_material = $this->buildCourseMaterial("/$name");

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('persist')
            ->with($course_material);

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);
        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $_POST['requested_path'] = '';
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $controller = new CourseMaterialsController($this->core);

        $ret = $controller->ajaxUploadCourseMaterialsFiles();

        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);

        $filename_full = FileUtils::joinPaths($this->upload_path, $name);
        $files = FileUtils::getAllFiles($this->upload_path, [], true);
        $expected_files = [
            $name => [
                'name' => $name,
                'path' => $filename_full,
                'size' => filesize($filename_full),
                'relative_name' => $name
            ]
        ];
        $this->assertEquals($expected_files, $files);
    }

    public function testZipCourseUpload() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $names = [
            $this->core->getConfig()->getCoursePath() . '/test0.txt',
            $this->core->getConfig()->getCoursePath() . '/test1.txt',
            $this->core->getConfig()->getCoursePath() . '/lev0/test0.txt',
            $this->core->getConfig()->getCoursePath() . '/lev0/test1.txt'
        ];

        $course_materials = [];
        foreach ($names as $name) {
            $course_materials[] = $this->buildCourseMaterial($name);
        }

        $this->core->getCourseEntityManager()
            ->expects($this->exactly(8))
            ->method('persist')
            ->withConsecutive([$course_materials[0]], [$course_materials[1]], [$course_materials[2]], [$course_materials[3]]);

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->exactly(19))
            ->method('findOneBy')
            ->willReturn(null);
        $this->core->getCourseEntityManager()
            ->expects($this->exactly(19))
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $controller = new CourseMaterialsController($this->core);

        $_FILES = [];
        $_POST['expand_zip'] = 'on';
        //create a zip file of depth = 2 with 2 files in each level.
        $fake_files = $this->buildFakeZipFile('foo.zip', 1, 2, 2);
        $_POST['requested_path'] = '';
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $ret = $controller->ajaxUploadCourseMaterialsFiles();
        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);

        $files = FileUtils::getAllFiles($this->upload_path, [], true);
        $this->assertEquals(4, count($files));

        $f1 = Utils::getFirstArrayElement($files);
        $expected_files1 = [
            'name' => 'test0.txt',
            'path' => $this->upload_path . $this->config['course_path'] . '/lev0/test0.txt',
            'size' => 0,
            'relative_name' => ltrim($this->config['course_path'], '/') . '/lev0/test0.txt'
        ];

        $this->assertEquals($expected_files1, $f1);
    }

    /**
     * @runInSeparateProcess
     */
    public function testModifyCourseMaterials() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $_FILES = [];

        $name = "foo.txt";
        file_put_contents($this->upload_path . "/" . $name, 'a');
        $this->buildFakeFile($name);

        $course_material = $this->buildCourseMaterial("/$name");

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('persist')
            ->with($course_material);

        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['path' => $this->upload_path . "/" . $name])
            ->willReturnOnConsecutiveCalls(null, $course_material);
        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $controller = new CourseMaterialsController($this->core);

        $_POST['requested_path'] = '';
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $ret = $controller->ajaxUploadCourseMaterialsFiles();
        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);

        $_POST['fn'][] = FileUtils::joinPaths($this->upload_path, $name);
        $new_date = new \DateTime('2005-01-01');

        $course_material->setReleaseDate($new_date);

        $ret = $controller->modifyCourseMaterialsFileTimeStamp($_POST['fn'][0], $new_date->format('Y-m-d H:i:sO'));
        $exptected_ret = ['status' => 'success', 'data' => 'Time successfully set.'];
        $this->assertEquals($exptected_ret, $ret->json);
    }

    /**
     * @runInSeparateProcess
     */
    public function testUpdateCourseMaterial() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $_FILES = [];

        $name = "foo.txt";
        file_put_contents($this->upload_path . "/" . $name, 'a');
        $this->buildFakeFile($name);

        $course_material = $this->buildCourseMaterial("/$name");

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('persist')
            ->with($course_material);

        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->with(['path' => $this->upload_path . "/" . $name])
            ->willReturnOnConsecutiveCalls(null, $course_material);
        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $controller = new CourseMaterialsController($this->core);

        $_POST['requested_path'] = '';
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $ret = $controller->ajaxUploadCourseMaterialsFiles();
        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);

        $_POST = [];

        $_POST['sections'] = '1,2';
        $_POST['sections_lock'] = "true";
        $_POST['requested_path'] = FileUtils::joinPaths($this->upload_path, $name);

        $sections = ['1', '2'];
        foreach ($sections as $section) {
            $course_material_section = new CourseMaterialSection($section, $course_material);
            $course_material->addSection($course_material_section);
        }

        $ret = $controller->ajaxEditCourseMaterialsFiles();
        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteCourseMaterial() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $_FILES = [];

        //create a file
        $name = "foo.txt";
        file_put_contents($this->upload_path . "/" .  $name, 'a');
        $this->buildFakeFile($name);

        $course_material = $this->buildCourseMaterial("/$name");

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('persist')
            ->with($course_material);

        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['path' => $this->upload_path . "/" . $name])
            ->willReturnOnConsecutiveCalls(null, $course_material);
        $repository
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$course_material]);
        $this->core->getCourseEntityManager()
            ->expects($this->exactly(2))
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $controller = new CourseMaterialsController($this->core);

        $_POST['requested_path'] = '';
        $_POST['sections_lock'] = false;
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $controller->ajaxUploadCourseMaterialsFiles();

        $path = $this->upload_path . "/" . $name;

        $this->core->getAccess()->method('resolveDirPath')->willReturn($path);

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('remove')
            ->with($course_material);

        $controller->deleteCourseMaterial($path);

        $files = FileUtils::getAllFiles($this->upload_path);
        $this->assertEquals(0, count($files));
    }

    public function testRequestedPathUpload() {
        $this->getFunctionMock('app\controllers\course', 'is_uploaded_file')
            ->expects($this->any())
            ->willReturn(true);

        $name = "foo.txt";
        file_put_contents($this->upload_path . "/" .  $name, 'a');
        $this->buildFakeFile($name);

        $_POST['requested_path'] = 'foo/foo2';
        $_POST['sections_lock'] = false;
        $_POST['hide_from_students'] = false;
        $_POST['sort_priority'] = 0;

        $course_material = $this->buildCourseMaterial("/foo/foo2/$name");

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('persist')
            ->with($course_material);

        $this->core->getCourseEntityManager()
            ->expects($this->once())
            ->method('flush');

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects($this->exactly(3))
            ->method('findOneBy')
            ->withConsecutive([['path' => $this->upload_path . '/foo/foo2/foo.txt']], [['path' => $this->upload_path . "/foo/foo2"]], [['path' => $this->upload_path . "/foo"]])
            ->willReturnOnConsecutiveCalls(null, $course_material, $course_material);
        $this->core->getCourseEntityManager()
            ->expects($this->exactly(3))
            ->method('getRepository')
            ->with(CourseMaterial::class)
            ->willReturn($repository);

        $controller = new CourseMaterialsController($this->core);

        $ret = $controller->ajaxUploadCourseMaterialsFiles();

        $exptected_ret = ['status' => 'success', 'data' => 'Successfully uploaded!'];
        $this->assertEquals($exptected_ret, $ret->json);

        $filename_full = FileUtils::joinPaths($this->upload_path, 'foo/foo2', $name);
        $files = FileUtils::getAllFiles($this->upload_path, [], true);
        $newname = 'foo/foo2/' . $name;
        $expected_files = [
            $newname => [
                'name' => $name,
                'path' => $filename_full,
                'size' => filesize($filename_full),
                'relative_name' => $newname
            ]
        ];
        $this->assertEquals($expected_files[$newname], $files[$newname]);
    }
}
