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
 * Manages question bank categories.
 */
class category_manager {

    /**
     * Get or create category from XML path.
     *
     * @param string $categorypath Category path from XML (e.g., "$system$/top/Math/Grade 8")
     * @param int $courseid Course ID (for course context)
     * @return object Category object or null on failure
     */
    public static function get_or_create_from_path($categorypath, $courseid = null) {
        global $DB;

        // Parse the category path
        $parsed = xml_parser::parse_category_path($categorypath);

        // Get context ID
        $contextid = self::get_context_id($parsed->context, $courseid);
        if (!$contextid) {
            return null;
        }

        // Find or create the category hierarchy
        return self::ensure_category_hierarchy($parsed->parts, $contextid);
    }

    /**
     * Get context ID based on context type.
     *
     * @param string $contexttype 'system', 'course', or 'module'
     * @param int|null $courseid Course ID (required for course context)
     * @return int|null Context ID
     */
    private static function get_context_id($contexttype, $courseid = null) {
        if ($contexttype === 'system') {
            return \context_system::instance()->id;
        } else if ($contexttype === 'course' && $courseid) {
            return \context_course::instance($courseid)->id;
        }

        return null;
    }

    /**
     * Ensure category hierarchy exists, creating categories as needed.
     *
     * @param array $parts Category path parts (e.g., ['Math', 'Grade 8', 'Algebra'])
     * @param int $contextid Context ID
     * @return object|null Final category object
     */
    public static function ensure_category_hierarchy($parts, $contextid) {
        global $DB;

        // Start with the top category for this context
        $parentid = self::get_top_category($contextid);

        // Traverse/create each level
        foreach ($parts as $categoryname) {
            $category = $DB->get_record('question_categories', [
                'name' => $categoryname,
                'contextid' => $contextid,
                'parent' => $parentid
            ]);

            if (!$category) {
                // Create the category
                $category = self::create_category($categoryname, $contextid, $parentid);
                if (!$category) {
                    return null;
                }
            }

            // Move down the hierarchy
            $parentid = $category->id;
        }

        return $DB->get_record('question_categories', ['id' => $parentid]);
    }

    /**
     * Get the top category ID for a context.
     *
     * @param int $contextid Context ID
     * @return int Top category ID
     */
    private static function get_top_category($contextid) {
        global $DB;

        $top = $DB->get_record('question_categories', [
            'contextid' => $contextid,
            'parent' => 0
        ]);

        if (!$top) {
            // Create top category if it doesn't exist
            $top = new \stdClass();
            $top->name = 'top';
            $top->contextid = $contextid;
            $top->info = 'The top category for questions in this context.';
            $top->infoformat = FORMAT_HTML;
            $top->stamp = make_unique_id_code();
            $top->parent = 0;
            $top->sortorder = 0;
            $top->id = $DB->insert_record('question_categories', $top);
        }

        return $top->id;
    }

    /**
     * Create a new question category.
     *
     * @param string $name Category name
     * @param int $contextid Context ID
     * @param int $parentid Parent category ID
     * @return object|null Created category object
     */
    public static function create_category($name, $contextid, $parentid) {
        global $DB;

        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $contextid;
        $category->info = '';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $parentid;

        // Get the highest sortorder in this context
        $maxsort = $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {question_categories} WHERE contextid = ?',
            [$contextid]
        );
        $category->sortorder = $maxsort ? $maxsort + 1 : 999;

        try {
            $category->id = $DB->insert_record('question_categories', $category);
            return $category;
        } catch (\Exception $e) {
            debugging('Failed to create category: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get category by ID.
     *
     * @param int $categoryid Category ID
     * @return object|null Category object
     */
    public static function get_category($categoryid) {
        global $DB;
        return $DB->get_record('question_categories', ['id' => $categoryid]);
    }
}
