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
     * @param int $bytes
     *
     * @return string
     * @throws \Exception
     */
    public static function generateRandomString($bytes = 16) {
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

    /**
     * Wrapper around the PHP function setcookie that deals with figuring out if we should be setting this cookie
     * such that it should only be accessed via HTTPS (secure) as well as allow easily passing an array to set as
     * the cookie data.
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
     * Generates a unique identifier v4 based on the RFC 4122 - Section 4.4 document.
     * TODO: when we move to Composer inside of site, we should replace usage of this
     * function with ramsey/uuid package.
     *
     * If for whatever reason random_bytes fails, we fall back to uniqid which has a
     * worse guarantee of being actually unique, but it's more important to not
     * crash out here.
     *
     * @see http://tools.ietf.org/html/rfc4122#section-4.4
     *
     * @return string
     */
    public static function guidv4() {
        try {
            $data = random_bytes(16);

            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        catch (\Exception $exc) {
            return uniqid('', true);
        }

    }
}
