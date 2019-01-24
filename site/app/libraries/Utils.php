<?php

namespace app\libraries;

/**
 * Class Utils
 */
class Utils {
    /**
     * Recursively removes a string from any value in an array.
     *
     * @param string $needle
     * @param array  $haystack
     *
     * @return array
     */
    public static function stripStringFromArray($needle, $haystack) {
        if (!is_array($haystack) || !is_string($needle)) {
            return null;
        }
        foreach($haystack as $key => $value) {
            if (is_array($value)) {
                $haystack[$key] = Utils::stripStringFromArray($needle, $value);
            }
            else {
                $haystack[$key] = str_replace($needle, "", $value);
            }
        }
        return $haystack;
    }

    /**
     * Defines a new default str_pad that's useful for things like parts of a datetime
     *
     * @param        $string
     * @param int    $pad_width  [optional]
     * @param string $pad_string [optional]
     * @param int    $pad_type   [optional]
     *
     * @return string
     */
    public static function pad($string, $pad_width = 2, $pad_string = '0', $pad_type = STR_PAD_LEFT) {
        return str_pad($string, $pad_width, $pad_string, $pad_type);
    }

    /**
     * Removes the trailing comma at the end of any JSON block. This means that if you had:
     * [ "element": { "a", "b", }, ]
     * this function would return:
     * [ "element": { "a", "b" } ]
     *
     * We do this as we have the potential of trailing commas in the JSON files that are generated by
     * the submission server
     *
     * @param string $json
     *
     * @return string
     */
    public static function removeTrailingCommas($json) {
        $json = preg_replace('/,\s*([\]}])/m', '$1', $json);
        return $json;
    }

    /**
     * Generates a pseudo-random string that should be cryptographically secure for use
     * as tokens and other things where uniqueness is of absolute importance. The generated
     * string is twice as long as the given number of bytes as the parameter.
     *
     * @noinspection PhpDocMissingThrowsInspection
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function generateRandomString($bytes = 16) {
        /** @noinspection PhpUnhandledExceptionInspection */
        return bin2hex(random_bytes($bytes));
    }
    
    /**
     * Given a string, convert all newline characters to "<br />" while also performing htmlentities on all elements
     * that are not for the new lines
     *
     * @param string $string
     *
     * @return string
     */
    public static function prepareHtmlString($string) {
        $string = str_replace("<br>", "<br />", nl2br($string));
        $string = explode("<br />", $string);
        return implode("<br />", array_map("htmlentities", $string));
    }

    /**
     * Gets the last element of an array. As PHP arrays are technically ordered maps, this will return the last
     * element that was inserted into that map regardless of how the keys might be ordered. This is useful especially
     * for associative arrays that do not have numeric keys or the keys are out of order and we can't just use indices
     * as in other languages.
     *
     * @param $array
     * @return mixed|null
     */
    public static function getLastArrayElement($array) {
        $temp = array_slice($array, -1);
        return (count($temp) > 0) ? array_pop($temp) : null;
    }


    /**
     * Checks if string $haystack begins with the string $needle, returning TRUE if it does or FALSE otherwise.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function startsWith($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }

    /**
     * Checks if string $haystack ends with the string $needle, returning TRUE if it does or FALSE otherwise.
     *
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    public static function endsWith($haystack, $needle) {
        return substr($haystack, (-1*strlen($needle)), strlen($needle)) === $needle;
    }

    public static function getDisplayNameForum($anonymous, $real_name) {
        if($anonymous) {
            return "Anonymous";
        }
        return $real_name['first_name'] . substr($real_name['last_name'], 0, 2) . '.';
    }


    /**
     * Wrapper around the PHP function setcookie that deals with figuring out if we should be setting this cookie
     * such that it should only be accessed via HTTPS (secure) as well as allow easily passing an array to set as
     * the cookie data. This will also set the value in the $_COOKIE superglobal so that it's available without a
     * page reload.
     *
     * @param string        $name name of the cookie
     * @param string|array  $data data of the cookie, if array, will json_encode it
     * @param int           $expire when should the cookie expire
     *
     * @return bool true if successfully able to set the cookie, else false
     */
    public static function setCookie($name, $data, $expire=0) {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $_COOKIE[$name] = $data;
        return setcookie($name, $data, $expire, "/", "", $secure);
    }

    /**
     * Given a filename, determine if it is an image.
     * TOOD: Make this a stronger check than just on the appended file extension to the naem
     *
     * @param string $filename
     *
     * @return bool true if filename references an image else false
     */
    public static function isImage($filename) {
        return (substr($filename,strlen($filename)-4,4) == ".png") ||
            (substr($filename,strlen($filename)-4,4) == ".jpg") ||
            (substr($filename,strlen($filename)-4,4) == ".jpeg");
    }

    public static function checkUploadedImageFile($id){
        if(isset($_FILES[$id])){
            foreach($_FILES[$id]['tmp_name'] as $file_name){
                if(file_exists($file_name)){
                    $mime_type = FileUtils::getMimeType($file_name); 
                    if(getimagesize($file_name) === false  || substr($mime_type, 0, strrpos($mime_type, "/")) !== "image") {
                        return false;
                    }
                }
            } return true;
        } return false;
    }

    /**
     * Compares two potentially null values using greater-than comparison.
     * @param mixed $gtL Left operand for greater-than comparison
     * @param mixed $gtR Righ operand for greater-than comparison
     * @return bool True if $dtL > $dtR and neither are null, otherwise false
     */
    public static function compareNullableGt($gtL, $gtR) {
        return $gtL !== null && $gtR !== null && $gtL > $gtR;
    }

    /**
     * Calculates a percent value (0 to 1) for the given dividend and divisor.
     *  This method will suppress divide-by-zero warnings and just return NAN
     * @param int $dividend The number being divided
     * @param int $divisor The number to divide with
     * @param bool $clamp If the result should be at most 1 (more than 100% impossible)
     * @return float
     */
    public static function safeCalcPercent($dividend, $divisor, $clamp = false) {
        if (intval($divisor) === 0) {
            return NAN;
        }

        // Convert dividend to float so we don't truncate the quotient
        $result = floatval($dividend) / $divisor;

        if ($result > 1.0 && $clamp === true) {
            return 1.0;
        } else if ($result < 0.0 && $clamp === true) {
            return 0.0;
        }
        return $result;
    }

    /**
     * Gets a function to compare two objects by reference for functions like 'array_udiff'
     *  Credit to method: https://stackoverflow.com/a/27830923/2972004
     * As noted in the comments (and observed), simply comparing two references using `===` will not work
     * @return \Closure
     */
    public static function getCompareByReference() {
        return function ($a, $b) {
            return strcmp(spl_object_hash($a), spl_object_hash($b));
        };
    }

    /*
     * Given an array of students, returns a json object of formated student names in the form:
     * First_Name Last_Name <student_id>
     * Students in the null section are at the bottom of the list in the form:
     * (In null section) First_Name Last_Name <student_id>
     * Optional param to show previous submission count
     * students_version is an array of user and their highest submitted version
     */

    public static function getAutoFillData($students, $students_version = null){
        $students_full = array();
        $null_section = array();
        $i = 0;
        foreach ($students as $student) {
            if($student->getRegistrationSection() != null){
                $student_entry = array('value' => $student->getId(),
                'label' => $student->getDisplayedFirstName() . ' ' . $student->getDisplayedLastName() . ' <' . $student->getId() . '>');
                if ($students_version != null && $students_version[$i][1] !== 0) {
                    $student_entry['label'] .= ' (' . $students_version[$i][1] . ' Prev Submission)';
                }
                $students_full[] = $student_entry;
            }else{
                $null_entry = array('value' => $student->getId(),
                'label' => '[NULL section] ' . $student->getDisplayedFirstName() . ' ' . $student->getDisplayedLastName() . ' <' . $student->getId() . '>'); 

                $in_null_section = false;
                foreach ($null_section as $null_student) {
                    if($null_student['value'] === $student->getId()) $in_null_section = true;
                }
                if(!$in_null_section) $null_section[] = $null_entry;
            }
            $i++;
        }
        $students_full = array_unique(array_merge($students_full, $null_section), SORT_REGULAR);
        return json_encode($students_full);
    }
}
