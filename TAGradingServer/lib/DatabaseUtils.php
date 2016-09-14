<?php

namespace lib;

/**
 * Class DatabaseUtils
 * @package lib
 */
class DatabaseUtils {

    /**
     * Get instance of DatabaseUtils
     *
     * Creates a DatabaeUtils if one doesn't exist
     * and then subsequently returns the same object in the future
     *
     * @return DatabaseUtils The instance of DatabaseUtils
     */
    public static function getInstance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new DatabaseUtils();
        }
        return $instance;
    }

    /**
     * Don't allow anyone outside of DatabaseUtils and subclasses
     * to initailze a singleton object
     */
    private function __construct() { }

    /**
     * Don't allow someone to clone a singleton object
     *
     * @codeCoverageIgnore
     */
    private function __clone() { }

    /**
     * Converts a Postgres style array to a PHP array
     *
     * Postgres returns a text that contains their array when querying
     * through the PDO interface, meaning it has to processed into a PHP
     * array post Database for it to be actually usable.
     *
     * ex: "{1, 2, 3, 4}" => array(1, 2, 3, 4)
     *
     * @param string $text        the text representation of the postgres array
     * @param bool   $parse_bools set to true to convert "true"/"false" to booleans instead of strings
     * @param int    $start       index to start looking through $text at
     * @param int    $end         index of $text where we exist current pgArrayToPhp call
     *
     * @return array PHP array representation
     */
    public static function fromPGToPHPArray($text, $parse_bools = false, $start=0, &$end=null) {
        $text = trim($text);

        if(empty($text) || $text[0] != "{") {
            return array();
        } else if(is_string($text)) {
            $return = array();
            $element = "";
            $in_string = false;
            $have_string = false;
            $in_array = false;
            $quot = "";
            for ($i = $start; $i < strlen($text); $i++) {
                $ch = $text[$i];
                if (!$in_array && !$in_string && $ch == "{") {
                    $in_array = true;
                }
                else if (!$in_string && $ch == "{") {
                    $return[] = DatabaseUtils::fromPGToPHPArray($text, $parse_bools, $i, $i);
                }
                else if (!$in_string && $ch == "}") {
                    self::parsePGValue($element, $have_string, $parse_bools, $return);
                    $end = $i;
                    return $return;
                }
                else if (($ch == '"' || $ch == "'") && !$in_string) {
                    $in_string = true;
                    $quot = $ch;
                }
                else if ($in_string && $ch == $quot && $text[$i-1] == "\\") {
                    $element = substr($element, 0, -1).$ch;
                }
                else if ($in_string && $ch == $quot && $text[$i-1] != "\\") {
                    $in_string = false;
                    $have_string = true;
                }
                else if (!$in_string && $ch == " ") {
                    continue;
                }
                else if (!$in_string && $ch == ",") {
                    self::parsePGValue($element, $have_string, $parse_bools, $return);
                    $have_string = false;
                    $element = "";
                }
                else {
                    $element .= $ch;
                }
            }
        }

        return array();
    }

    /**
     * Method that given an element figures out how to add it to the $return array whether it's a string, a numeric,
     * a null, a boolean, or an unquoted string
     *
     * @param string $element     element to analyze
     * @param bool   $have_string do we have a quoted element (using either ' or " characters around the string)
     * @param bool   $parse_bools set to true to convert "true"/"false" to booleans instead of strings
     * @param array  &$return     this is the array being built to contain the parsed PG array
     */
    private static function parsePGValue($element, $have_string, $parse_bools, &$return) {
        if ($have_string) {
            $return[] = $element;
        }
        else if (strlen($element) > 0) {
            if (is_numeric($element)) {
                $return[] = ($element + 0);
            }
            else {
                $lower = strtolower($element);
                if ($parse_bools && in_array($lower, array("true", "t", "false", "f"))) {
                    $return[] = ($lower === "true" || $lower === "t") ? true : false;
                }
                else if (strtolower($element) == "null") {
                    $return[] = null;
                }
                else {
                    $return[] = $element;
                }
            }
        }
    }

    /**
     * Converts a PHP array into a Postgres text array
     *
     * Gets a PHP array ready to be put into a postgres array field
     * as part of a database update/insert
     *
     * ex: Array(1, 2, 3, 4) => "{1, 2, 3, 4)"
     *
     * @param array $array PHP array
     *
     * @return string Postgres text representation of array
     */
    public static function fromPHPToPGArray($array) {
        if (!is_array($array)) {
            return '{}';
        }
        $elements = array();
        foreach ($array as $e) {
            if ($e === null) {
                $elements[] = "null";
            }
            else if (is_array($e)) {
                $elements[] = DatabaseUtils::fromPHPToPGArray($e);
            }
            else if (is_string($e)) {
                $elements[] .= '"'. str_replace('"', '\"', $e) .'"';
            }
            else if (is_bool($e)) {
                $elements[] .= ($e) ? "true" : "false";
            }
            else {
                $elements[] .= "{$e}";
            }
        }
        $text = "{".implode(", ", $elements)."}";
        return $text;
    }
}

?>