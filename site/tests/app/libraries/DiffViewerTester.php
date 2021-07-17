<?php

namespace tests\app\libraries;

use app\libraries\DiffViewer;

class DiffViewerTester extends \PHPUnit\Framework\TestCase {

    /**
     * Get all of the various diff test cases we have, ensuring that we only take in a folder that has
     * necessary four files to have the test fixture be properly run
     *
     * @return array
     */
    public function diffDir() {
        $needed_files = ['input_actual.txt', 'input_expected.txt',
            'input_differences.json', 'output_actual.txt', 'output_expected.txt'];
        $dir = __TEST_DATA__ . '/diffs';
        $files = scandir($dir);
        $diffs = [];
        foreach ($files as $file) {
            if (is_dir($dir . "/" . $file) && strpos($file, '.') === false) {
                foreach ($needed_files as $needed_file) {
                    if (!file_exists($dir . "/" . $file . "/" . $needed_file)) {
                        continue 2;
                    }
                }
                $diffs[] = [$dir . "/" . $file];
            }
        }
        return $diffs;
    }

    /**
     * @param $diffDir
     *
     * @dataProvider diffDir
     */
    public function testDiffViewer($diffDir) {
        $diff = new DiffViewer("{$diffDir}/input_actual.txt", "{$diffDir}/input_expected.txt", "{$diffDir}/input_differences.json", "");
        $this->assertStringEqualsFile($diffDir . "/output_actual.txt", $diff->getDisplayActual());
        $this->assertStringEqualsFile($diffDir . "/output_expected.txt", $diff->getDisplayExpected());
        $this->assertTrue($diff->existsDifference());
    }

    public function testActualException() {
        $diff = new DiffViewer("file_that_doesnt_exist", "", "", "");
        $this->expectException(\Exception::class);
        $diff->buildViewer();
    }

    public function testExpectedException() {
        $diff = new DiffViewer(
            __TEST_DATA__ . "/diffs/diff_test_01/input_actual.txt",
            "file_that_doesnt_exist",
            "",
            ""
        );
        $this->expectException(\Exception::class);
        $diff->buildViewer();
    }

    public function testDifferencesException() {
        $diff = new DiffViewer(
            __TEST_DATA__ . "/diffs/diff_test_01/input_actual.txt",
            __TEST_DATA__ . "/diffs/diff_test_01/input_expected.txt",
            "file_that_doesnt_exist",
            ""
        );
        $this->expectException(\Exception::class);
        $diff->buildViewer();
    }

    public function testLongDiff() {
        $diff = new DiffViewer(
            __TEST_DATA__ . "/diffs/diff_test_06/output_actual.txt",
            __TEST_DATA__ . "/diffs/diff_test_06/output_expected.txt",
            __TEST_DATA__ . "/diffs/diff_test_06/output_differences.json",
            ""
        );
        $diff->buildViewer();
        $diff_result_actual = $diff->getDisplayActual();
        $this->assertStringStartsWith("<p style='color: black;'>This file has been truncated. Please download it to see the full file.</p>", $diff_result_actual);

        $diff_result_expected = $diff->getDisplayExpected();
        $this->assertStringStartsWith("<p style='color: black;'>This file has been truncated. Please contact instructor if you feel that you need the full file.</p>", $diff_result_expected);
    }
}
