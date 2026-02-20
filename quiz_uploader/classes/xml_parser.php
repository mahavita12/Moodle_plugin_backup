<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

/**
 * XML parser for Moodle question XML files.
 */
class xml_parser {

    /**
     * Extract category path from XML.
     *
     * @param string $xmlcontent XML file content
     * @return string|null Category path (e.g., "$system$/top/System Category/English/GMSR12")
     */
    public static function extract_category_path($xmlcontent) {
        // Parse XML
        $xml = simplexml_load_string($xmlcontent);
        if ($xml === false) {
            return null;
        }

        // Find last category question (the deepest one)
        $category_path = null;
        foreach ($xml->question as $question) {
            if ((string)$question['type'] === 'category') {
                $category_path = (string)$question->category->text;
            }
        }

        return $category_path;
    }

    /**
     * Extract question names from XML for duplicate checking.
     *
     * @param string $xmlcontent XML file content
     * @return array Array of question names
     */
    public static function extract_question_names($xmlcontent) {
        $xml = simplexml_load_string($xmlcontent);
        if ($xml === false) {
            return [];
        }

        $names = [];
        foreach ($xml->question as $question) {
            $type = (string)$question['type'];

            // Skip category questions
            if ($type === 'category') {
                continue;
            }

            // Extract question name
            if (isset($question->name->text)) {
                $names[] = (string)$question->name->text;
            }
        }

        return $names;
    }

    /**
     * Validate XML format.
     *
     * @param string $xmlcontent XML file content
     * @return bool True if valid Moodle XML
     */
    public static function validate_xml($xmlcontent) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlcontent);

        if ($xml === false) {
            return false;
        }

        // Check if it's a quiz XML (has <quiz> root element)
        if ($xml->getName() !== 'quiz') {
            return false;
        }

        return true;
    }

    /**
     * Parse category path to extract context type and parts.
     *
     * @param string $categorypath Category path from XML
     * @return object Object with context, parts, and final category name
     */
    public static function parse_category_path($categorypath) {
        $result = new \stdClass();

        // Determine context type
        if (strpos($categorypath, '$system$') === 0) {
            $result->context = 'system';
            $path = str_replace('$system$/top/', '', $categorypath);
        } else if (strpos($categorypath, '$course$') === 0) {
            $result->context = 'course';
            $path = str_replace('$course$/top/', '', $categorypath);
        } else if (strpos($categorypath, '$module$') === 0) {
            $result->context = 'module';
            $path = str_replace('$module$/top/', '', $categorypath);
        } else {
            // Default to course context
            $result->context = 'course';
            $path = $categorypath;
        }

        // Split path into parts
        $result->parts = array_filter(explode('/', $path));
        $result->final_name = end($result->parts);

        return $result;
    }

    /**
     * Count questions in XML (excluding category questions).
     *
     * @param string $xmlcontent XML file content
     * @return int Number of questions
     */
    public static function count_questions($xmlcontent) {
        $xml = simplexml_load_string($xmlcontent);
        if ($xml === false) {
            return 0;
        }

        $count = 0;
        foreach ($xml->question as $question) {
            $type = (string)$question['type'];
            if ($type !== 'category') {
                $count++;
            }
        }

        return $count;
    }
}
