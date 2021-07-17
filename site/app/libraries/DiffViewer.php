<?php

namespace app\libraries;

/**
 * Class DiffViewer
 *
 * Given an expected, actual, and differences file,
 * will generate the display for them (in either
 * HTML or plain-text)
 */
class DiffViewer {

    /** @var string */
    private $actual_file;
    /** @var string */
    private $expected_file;
    /** @var string */
    private $diff_file;
    /** @var string */
    private $image_difference;

    private $built = false;

    /**
     * @var bool
     */
    private $has_actual = false;
    private $actual_file_image = "";
    private $actual_file_name = "";

    /**
     * @var bool
     */
    private $display_actual = false;

    /**
     * @var array
     */
    private $actual = [];

    /**
     * @var bool
     */
    private $has_expected = false;
    private $expected_file_image = "";

    private $has_difference = false;
    private $difference_file_image = "";

    /**
     * @var bool
     */
    private $display_expected = false;

    /**
     * @var array
     */
    private $expected = [];

    /**
     * @var array
     */
    private $diff = [];

    /**
     * @var array
     */
    private $add = [];

    /**
     * @var array
     */
    private $link = [];

    /**
     * @var string
     */
    private $id = "id";
    /**
     * @var array
     */
    private $white_spaces = [];

    const SPECIAL_CHARS_ORIGINAL = 'original';
    const SPECIAL_CHARS_ESCAPE = 'escape';
    const SPECIAL_CHARS_UNICODE = 'unicode';

    //The first element of array is used to find the special char, the second is the visual representation, the third is
    // the escape code
    const SPECIAL_CHARS_LIST = [
        "space" => [" ", "&nbsp;", " "],
        "tabs" => ["\t", "↹", "\\t"],
        "carriage return" => ["\r", "↵<br>", "\\r<br>"],
        "null characters" => ["\0", "^@", "\\0"],
        "smart quote1" => ["\xC2\xAB", "\"", "\\xC2\\xAB"],
        "smart quote2" => ["\xE2\x80\x98", "\"", "\\xE2\\x80\\x98"],
        "smart quote3" => ["\xE2\x80\x99", "'", "\\xE2\\x80\\x99"],
        "em dash" => ["\xE2\x80\x94", "—", "\\xE2\\x80\\x94"],
        "en dash" => ["\xE2\x80\x93", "–", "\\xE2\\x80\\x93"]
    ];

    const EXPECTED = 'expected';
    const ACTUAL = 'actual';

    public static function isValidSpecialCharsOption($option) {
        return in_array($option, [
            self::SPECIAL_CHARS_ORIGINAL,
            self::SPECIAL_CHARS_UNICODE,
            self::SPECIAL_CHARS_ESCAPE
        ]);
    }

    public static function isValidType($type) {
        return in_array($type, [
            self::EXPECTED,
            self::ACTUAL
        ]);
    }

    /**
     * Reset the DiffViewer to its starting values.
     */
    public function reset() {
        $this->has_actual = false;
        $this->display_actual = false;
        $this->actual = [];
        $this->has_expected = false;
        $this->display_expected = false;
        $this->expected = [];
        $this->diff = [];
        $this->add = [];
        $this->link = [];
    }

    /**
     * Load the actual file, expected file, and diff json, using them to populate the necessary arrays for
     * display them later back to the user
     *
     * @param string $actual_file
     * @param string $expected_file
     * @param string $diff_file
     * @param string $image_difference
     * @param string $id_prepend
     *
     * @throws \Exception
     */
    public function __construct(
        string $actual_file,
        string $expected_file,
        string $diff_file,
        string $image_difference,
        string $id_prepend = "id"
    ) {
        $this->id = rtrim($id_prepend, "_") . "_";
        $this->actual_file = $actual_file;
        $this->expected_file = $expected_file;
        $this->diff_file = $diff_file;
        $this->image_difference = $image_difference;
    }

    public function destroyViewer() {
        $this->reset();
        $this->built = false;
    }

    /**
     * @throws \Exception
     */
    public function buildViewer() {
        if ($this->built) {
            return;
        }

        //TODO: Implement a better way to deal with large files
        //.25MB (TEMP VALUE)
        $size_limit = 262144;

        $actual_file = $this->actual_file;
        $expected_file = $this->expected_file;
        $diff_file = $this->diff_file;
        $can_diff = true;
        $image_difference = $this->image_difference;
        if (!file_exists($actual_file) && $actual_file != "") {
            throw new \Exception("'{$actual_file}' could not be found.");
        }
        elseif ($actual_file != "") {
            // TODO: fix this hacky way to deal with images
            if (Utils::isImage($actual_file)) {
                $this->actual_file_image = $actual_file;
            }
            else {
                if (filesize($actual_file) < $size_limit) {
                    $this->actual_file_name = $actual_file;
                    $tmp_actual = file_get_contents($actual_file);
                    $this->has_actual = trim($tmp_actual) !== "";
                    $this->actual = explode("\n", $tmp_actual);
                    $this->display_actual = true;
                }
                else {
                    $this->actual_file_name = $actual_file;
                    $can_diff = false;
                    //load in the first sizelimit characters of the file (TEMP VALUE)
                    $tmp_actual = file_get_contents($actual_file, null, null, 0, $size_limit);
                    $this->has_actual = trim($tmp_actual) !== "";
                    $this->actual = explode("\n", $tmp_actual);
                    $this->display_actual = true;
                }
            }
        }

        if (!file_exists($expected_file) && $expected_file != "") {
            throw new \Exception("'{$expected_file}' could not be found.");
        }
        elseif ($expected_file != "") {
            if (Utils::isImage($expected_file)) {
                $this->expected_file_image = $expected_file;
            }
            else {
                if (filesize($expected_file) < $size_limit) {
                    $tmp_expected = file_get_contents($expected_file);
                    $this->has_expected = trim($tmp_expected) !== "";
                    $this->expected = explode("\n", $tmp_expected);
                    $this->display_expected = true;
                }
                else {
                    $can_diff = false;
                    //load in the first sizelimit characters of the file (TEMP VALUE)
                    $tmp_expected = file_get_contents($expected_file, null, null, 0, $size_limit);
                    $this->has_expected = trim($tmp_expected) !== "";
                    $this->expected = explode("\n", $tmp_expected);
                    $this->display_expected = true;
                }
            }
        }

        if (!file_exists($image_difference) && $image_difference != "") {
            throw new \Exception("'{$expected_file}' could not be found.");
        }
        elseif ($image_difference != "") {
            if (Utils::isImage($image_difference)) {
                $this->difference_file_image = $image_difference;
            }
        }

        if (!file_exists($diff_file) && $diff_file != "") {
            throw new \Exception("'{$diff_file}' could not be found.");
        }
        elseif ($diff_file != "") {
            $diff = FileUtils::readJsonFile($diff_file);
        }

        $this->diff = [self::EXPECTED => [], self::ACTUAL => []];
        $this->add = [self::EXPECTED => [], self::ACTUAL => []];

        if (isset($diff['differences']) && $can_diff) {
            $diffs = $diff['differences'];
            /*
             * Types of things we need to worry about:
             * lines are highlighted
             * lines are highlighted with character sequence
             * need to insert lines into other diff while some lines are highlighted
             */
            foreach ($diffs as $diff) {
                $act_ins = 0;
                $exp_ins = 0;
                $act_start = $diff[self::ACTUAL]['start'];
                $act_final = $act_start;
                if (isset($diff[self::ACTUAL]['line'])) {
                    $act_ins = count($diff[self::ACTUAL]['line']);
                    foreach ($diff[self::ACTUAL]['line'] as $line) {
                        $line_num = $line['line_number'];
                        if (isset($line['char_number'])) {
                            $this->diff[self::ACTUAL][$line_num] = $this->compressRange($line['char_number']);
                        }
                        else {
                            $this->diff[self::ACTUAL][$line_num] = [];
                        }
                        $act_final = $line_num;
                    }
                }

                $exp_start = $diff[self::EXPECTED]['start'];
                $exp_final = $exp_start;
                if (isset($diff[self::EXPECTED]['line'])) {
                    $exp_ins = count($diff[self::EXPECTED]['line']);
                    foreach ($diff[self::EXPECTED]['line'] as $line) {
                        $line_num = $line['line_number'];
                        if (isset($line['char_number'])) {
                            $this->diff[self::EXPECTED][$line_num] = $this->compressRange($line['char_number']);
                        }
                        else {
                            $this->diff[self::EXPECTED][$line_num] = [];
                        }
                        $exp_final = $line_num;
                    }
                }

                $this->link[self::ACTUAL][($act_start)] = (isset($this->link[self::ACTUAL])) ? count($this->link[self::ACTUAL]) : 0;
                $this->link[self::EXPECTED][($exp_start)] = (isset($this->link[self::EXPECTED])) ? count($this->link[self::EXPECTED]) : 0;

                // Do we need to insert blank lines into actual?
                if ($act_ins < $exp_ins) {
                    $this->add[self::ACTUAL][($act_final)] = $exp_ins - $act_ins;
                } // Or into expected?
                elseif ($act_ins > $exp_ins) {
                    $this->add[self::EXPECTED][($exp_final)] = $act_ins - $exp_ins;
                }
            }
        }

        for ($i = 0; $i < count($this->actual); $i++) {
            if (
                isset($this->diff[self::ACTUAL][$i])
                && strlen($this->actual[$i] !== mb_strlen($this->actual[$i]))
            ) {
                $this->diff[self::ACTUAL][$i] = $this->correctMbDiff(
                    $this->actual[$i],
                    $this->diff[self::ACTUAL][$i]
                );
            }
        }

        for ($i = 0; $i < count($this->expected); $i++) {
            if (
                isset($this->diff[self::EXPECTED][$i])
                && strlen($this->expected[$i] !== mb_strlen($this->expected[$i]))
            ) {
                $this->diff[self::EXPECTED][$i] = $this->correctMbDiff(
                    $this->expected[$i],
                    $this->diff[self::EXPECTED][$i]
                );
            }
        }

        $this->built = true;
    }

    /**
     * Given a line that contains multibyte characters and diff for that line,
     * check if any of the diff ranges split a MB character, and if so, correct
     * the diff.
     */
    private function correctMbDiff(string $line, array $diffs) {
        $split_str = str_split($line);
        $mb_split_str = Utils::mb_str_split($line);
        for ($i = 0, $j = 0; $i < strlen($line); $i++, $j++) {
            if ($split_str[$i] === $mb_split_str[$j]) {
                continue;
            }
            $combined = $split_str[$i];
            $start = $i;
            $has_diff = false;
            // what entry in the diff array contains the range
            // that is for this MB character (if any)
            $diff_idx = null;
            foreach ($diffs as $idx => $diff) {
                if ($diff[0] <= $i && $i <= $diff[1]) {
                    $has_diff = true;
                    $diff_idx = $idx;
                    break;
                }
            }

            while ($combined !== $mb_split_str[$j]) {
                $i++;
                // if we have not yet found our index, we
                // recheck on each character as the range
                // may start on any character in the byte
                // sequence
                if (!$has_diff) {
                    foreach ($diffs as $idx => $diff) {
                        if ($diff[0] <= $i && $i <= $diff[1]) {
                            $has_diff = true;
                            $diff_idx = $idx;
                            break;
                        }
                    }
                }
                $combined .= $split_str[$i];
            }

            // given that we have a diff range for this
            // character, adjust the range such that it contains
            // the start and end of the multibyte character if it
            // does not already
            if ($has_diff) {
                $diff = $diffs[$diff_idx];
                if ($start < $diff[0]) {
                    $diff[0] = $start;
                }
                if ($i > $diff[1]) {
                    $diff[1] = $i;
                }
                $diffs[$diff_idx] = $diff;
            }
        }

        return $diffs;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function hasDisplayActual() {
        $this->buildViewer();
        return $this->display_actual;
    }

    /**
     * Boolean flag to indicate whether or not the actual file had any contents to display (or was
     * blank/empty lines). Assuming we do not have a difference file, we can use this flag to indicate
     * if we should actually print out the actual file or not, such as an error file (which ideally is
     * empty in most cases).
     *
     * @return bool
     * @throws \Exception
     */
    public function hasActualOutput() {
        $this->buildViewer();
        return $this->has_actual;
    }

    /**
     * Was there a given expected file and were we able to successfully read from it
     * @return bool
     * @throws \Exception
     */
    public function hasDisplayExpected() {
        $this->buildViewer();
        return $this->display_expected;
    }

    /**
     * Returns boolean indicating whether or not there is any input in the expected.
     * @return bool
     * @throws \Exception
     */
    public function hasExpectedOutput() {
        $this->buildViewer();
        return $this->has_expected;
    }

    /**
     * Return the output HTML for the actual display
     * @param string $option Option for displaying. Currently only supports show empty space
     *
     * @return string actual html
     * @throws \Exception
     */
    public function getDisplayActual($option = self::SPECIAL_CHARS_ORIGINAL) {
        $this->buildViewer();
        if ($this->display_actual) {
            return $this->getDisplay($this->actual, self::ACTUAL, $option);
        }
        else {
            return "";
        }
    }

    /**
     * @return string the file name for a non-image.
     * @throws \Exception
     */
    public function getActualFilename() {
        $this->buildViewer();
        return $this->actual_file_name;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getActualImageFilename() {
        $this->buildViewer();
        return $this->actual_file_image;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getExpectedImageFilename() {
        $this->buildViewer();
        return $this->expected_file_image;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getDifferenceFilename() {
        $this->buildViewer();
        return $this->difference_file_image;
    }

    /**
     * Return the HTML for the expected display
     * @param string $option Option for displaying. Currently only supports show empty space
     *
     * @return string expected html
     * @throws \Exception
     */
    public function getDisplayExpected($option = self::SPECIAL_CHARS_ORIGINAL) {
        $this->buildViewer();
        if ($this->display_expected) {
            return $this->getDisplay($this->expected, self::EXPECTED, $option);
        }
        else {
            return "";
        }
    }

    /**
     * Prints out the $lines parameter
     *
     * Prints out the actual codebox with diff view applied
     * using the $this->diff global based off which
     * type we're interested in
     *
     * @param array $lines array of strings (each line)
     * @param string $type which diff we use while printing
     *
     * @return string html to be displayed to user
     * @throws \Exception
     */
    private function getDisplay($lines, $type = self::EXPECTED, $option = self::SPECIAL_CHARS_ORIGINAL) {
        $this->buildViewer();
        $start = null;
        $html = "<div class='diff-container'><div class='diff-code'>\n";

        $num_blanks = 0;
        if (isset($this->add[$type]) && count($this->add[$type]) > 0) {
            if (array_key_exists(-1, $this->add[$type])) {
                $num_blanks = $this->add[$type][-1];
                $html .= "\t<div class='highlight' id='{$this->id}{$type}_{$this->link[$type][-1]}'>\n";
                for ($k = 0; $k < $num_blanks; $k++) {
                    $html .= "\t<div class='row bad'><div class='empty_line'>&nbsp;</div></div>\n";
                }
                $html .= "\t</div>\n";
            }
        }
        if (count($lines) + $num_blanks > 1000) {
            if ($type === self::EXPECTED) {
                $html = "<p style='color: black;'>This file has been truncated. Please contact instructor if you feel that you need the full file.</p>" . $html;
            }
            elseif ($type === self::ACTUAL) {
                $html = "<p style='color: black;'>This file has been truncated. Please download it to see the full file.</p>" . $html;
            }
        }
        /*
         * Run through every line, starting a highlight around any group of mismatched lines that exist (whether
         * there's a difference on that line or that the line doesn't exist.
         */
        $max_digits = strlen((string) count($lines));
        for ($i = 0; $i < count($lines); $i++) {
            if ($i === 1000 - $num_blanks) {
                break;
            }
            $j = $i + 1;
            if ($start === null && isset($this->diff[$type][$i])) {
                $start = $i;
                $html .= "\t<div class='highlight' id='{$this->id}{$type}_{$this->link[$type][$start]}'>\n";
            }
            if (isset($this->diff[$type][$i])) {
                $html .= "\t<div class='bad'>";
            }
            else {
                $html .= "\t<div>";
            }
            $html .= "<span class='line_number'>";
            $digits_at_line = strlen((string) $j);
            for ($counter = ($max_digits - $digits_at_line); $counter > 0; $counter--) {
                $html .= "&nbsp;";
            }
            $html .= "{$j}</span>";
            $html .= "<span class='line_code'>";
            if (isset($this->diff[$type][$i])) {
                // highlight the line
                $current = 0;
                // character highlighting
                foreach ($this->diff[$type][$i] as $diff) {
                    $html_orig = htmlentities(substr($lines[$i], $current, ($diff[0] - $current)));
                    $test = str_replace("\0", "null", $html_orig);
                    $html_orig_error = htmlentities(substr($lines[$i], $diff[0], ($diff[1] - $diff[0] + 1)));
                    $test2 = str_replace("\0", "null", $html_orig_error);
                    if ($option == self::SPECIAL_CHARS_ORIGINAL) {
                        $html .= $html_orig;
                        $html .= "<span class='highlight-char'>" . $html_orig_error . "</span>";
                    }
                    elseif ($option == self::SPECIAL_CHARS_UNICODE) {
                        $html_no_empty = $this->replaceEmptyChar($html_orig, false);
                        $html_no_empty_error = $this->replaceEmptyChar($html_orig_error, false);
                        $html .= $html_no_empty;
                        $html .= "<span class='highlight-char'>" . $html_no_empty_error . "</span>";
                    }
                    elseif ($option == self::SPECIAL_CHARS_ESCAPE) {
                        $html_no_empty = $this->replaceEmptyChar($html_orig, true);
                        $html_no_empty_error = $this->replaceEmptyChar($html_orig_error, true);
                        $html .= $html_no_empty;
                        $html .= "<span class='highlight-char'>" . $html_no_empty_error . "</span>";
                    }
                    $current = $diff[1] + 1;
                }
                $html .= "<span class='line_code_inner'>";
                $inner = htmlentities(substr($lines[$i], $current));
                if ($option === self::SPECIAL_CHARS_UNICODE) {
                    $inner = $this->replaceEmptyChar($inner, false);
                }
                elseif ($option === self::SPECIAL_CHARS_ESCAPE) {
                    $inner = $this->replaceEmptyChar($inner, true);
                }
                $html .= "{$inner}</span>";
            }
            else {
                if (isset($lines[$i])) {
                    if ($option == self::SPECIAL_CHARS_ORIGINAL) {
                        $html .= htmlentities($lines[$i]);
                    }
                    elseif ($option == self::SPECIAL_CHARS_UNICODE) {
                        $html .= $this->replaceEmptyChar(htmlentities($lines[$i]), false);
                    }
                    elseif ($option == self::SPECIAL_CHARS_ESCAPE) {
                        $html .= $this->replaceEmptyChar(htmlentities($lines[$i]), true);
                    }
                }
            }
            if ($option == self::SPECIAL_CHARS_UNICODE) {
                $html .= '<span class="whitespace">&#9166;</span>';
            }
            elseif ($option == self::SPECIAL_CHARS_ESCAPE) {
                $html .= '<span class="whitespace">\\n</span>';
            }
            $html .= "</span></div>\n";

            if (isset($this->add[$type][$i])) {
                if ($start === null) {
                    $html .= "\t<div class='highlight' id='{$this->id}{$type}_{$this->link[$type][$i]}'>\n";
                }
                for ($k = 0; $k < $this->add[$type][$i]; $k++) {
                    $html .= "\t<div class='bad'><td class='empty_line' colspan='2'>&nbsp;</td></div>\n";
                }
                if ($start === null) {
                    $html .= "\t</div>\n";
                }
            }

            if ($start !== null && !isset($this->diff[$type][($i + 1)])) {
                $start = null;
                $html .= "\t</div>\n";
            }
        }
        return $html . "</div></div>\n";
    }

    public function getWhiteSpaces() {
        $return = "";
        foreach ($this->white_spaces as $key => $value) {
            $return .= "$value" . " = " . "$key" . " ";
        }
        return $this->white_spaces;
    }

    /**
     * @param string $html the original HTML before any text transformation
     * @param bool $with_escape Show escape characters instead of character representations
     *
     * Add to this function (Or the one below it) in the future for any other special characters that needs to be replaced.
     *
     * @return string HTML after white spaces replaced with visuals
     */
    private function replaceEmptyChar(string $html, bool $with_escape): string {
        $idx = $with_escape ? 2 : 1;
        $return = $html;
        foreach (self::SPECIAL_CHARS_LIST as $name => $representations) {
            $this->replaceUTF($representations[0], $representations[$idx], $return, $name);
        }
        return $return;
    }

    /**
     * @param string $text String
     * @param string $what String
     * @param string $which String(Reference)
     * @param string $description (What is the description of this character)
     * @return string (The newly formed string)
     *
     * This function replaces string $text with string $what in string $which.
     */
    private function replaceUTF(
        string $text,
        string $what,
        string &$which,
        string $description
    ): string {
        $count = 0;
        $what = '<span class="whitespace">' . $what . '</span>';
        $which = str_replace($text, $what, $which, $count);
        if ($count > 0) {
            $this->white_spaces[$description] = strip_tags($what);
        }
        return $what;
    }

    /**
     * Compress an array of numbers into ranges
     *
     * Given some array of numbers, it sorts the array, then condenses
     * adjacent numbers into a range.
     *
     * Ex: Given [0,1,2,5,6,9,100] -> [[0,2],[5,6],[9,9],[100,100]]
     *
     * @param array $range original flat array
     *
     * @return array A condensed array with ranges
     */
    private function compressRange(array $range): array {
        sort($range);
        $range[] = -100;
        $last = -100;
        $return = [];
        $temp = [];
        foreach ($range as $number) {
            if ($number != $last + 1) {
                if (count($temp) > 0) {
                    $return[] = [$temp[0], end($temp)];
                    $temp = [];
                }
            }
            $temp[] = $number;
            $last = $number;
        }
        return $return;
    }

    /**
     * Returns true if there's an actual difference between actual and expected, else will
     * return false
     *
     * @return bool
     * @throws \Exception
     */
    public function existsDifference() {
        $this->buildViewer();
        $return = false;
        foreach ([self::EXPECTED, self::ACTUAL] as $key) {
            if (count($this->diff[$key]) > 0 || count($this->add[$key]) > 0) {
                $return = true;
            }
        }
        return $return;
    }
}
