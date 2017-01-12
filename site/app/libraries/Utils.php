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
        if (!is_array($haystack)) {
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
     * @param int $bytes
     *
     * @return string
     */
    public static function generateRandomString($bytes = 16) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
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
     * This converts a Boolean to a Sting representation. We use this as by default the String representations are that
     * TRUE is "1" and FALSE is "" (empty string) which we generally do not want (especially if concatating booleans
     * to a string or using it within PDO).
     *
     * @param $value
     * @return string
     */
    public static function convertBooleanToString($value) {
        return ($value === true) ? "true" : "false";
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
}
