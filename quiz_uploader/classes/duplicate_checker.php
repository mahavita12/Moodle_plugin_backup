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
 * Check for duplicate quizzes and questions.
 */
class duplicate_checker {

    /**
     * Check if quiz name already exists in course.
     *
     * @param int $courseid Course ID
     * @param string $quizname Quiz name
     * @return bool True if quiz exists
     */
    public static function quiz_exists($courseid, $quizname) {
        global $DB;

        return $DB->record_exists('quiz', [
            'course' => $courseid,
            'name' => $quizname
        ]);
    }

    /**
     * Check if ANY category with the exact name already exists AND has questions.
     *
     * @param string $categoryname Category name (Layer 5 / topic name)
     * @param int $contextid Context ID (typically system context)
     * @return bool True if ANY category with this name exists AND contains questions
     */
    public static function category_exists($categoryname, $contextid) {
        global $DB;

        // Find ALL categories with this exact name in the given context
        $categories = $DB->get_records('question_categories', [
            'name' => $categoryname,
            'contextid' => $contextid
        ]);

        if (empty($categories)) {
            // No categories with this name exist
            return false;
        }

        // Check if ANY of these categories have questions
        foreach ($categories as $category) {
            $sql = "SELECT COUNT(q.id)
                    FROM {question} q
                    JOIN {question_versions} qv ON qv.questionid = q.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                    WHERE qbe.questioncategoryid = ?";

            $questioncount = $DB->count_records_sql($sql, [$category->id]);

            // If this category has questions, return true (duplicate found)
            if ($questioncount > 0) {
                return true;
            }
        }

        // None of the categories with this name have questions
        return false;
    }

    /**
     * Comprehensive duplicate check for category (topic name) only.
     * Quiz name is NOT checked - allows duplicate quiz names.
     *
     * @param int $courseid Course ID (not used, kept for compatibility)
     * @param string $quizname Quiz name (not used, kept for compatibility)
     * @param string $categoryname Category name (Layer 5 / topic name)
     * @param int $contextid Context ID
     * @return object Result object with duplicate information
     */
    public static function check_all($courseid, $quizname, $categoryname, $contextid) {
        $result = new \stdClass();
        $result->has_duplicates = false;
        $result->quiz_exists = false;
        $result->quiz_name = null;
        $result->category_exists = false;
        $result->category_name = null;

        // ONLY check if category (topic name) exists with questions
        // Quiz name is NOT checked - duplicate quiz names are allowed
        if (self::category_exists($categoryname, $contextid)) {
            $result->has_duplicates = true;
            $result->category_exists = true;
            $result->category_name = $categoryname;
        }

        return $result;
    }

    /**
     * Get all quiz names in a course.
     *
     * @param int $courseid Course ID
     * @return array Array of quiz names
     */
    public static function get_course_quizzes($courseid) {
        global $DB;

        return $DB->get_fieldset_select('quiz', 'name', 'course = ?', [$courseid]);
    }

    /**
     * Get all question names in a category.
     *
     * @param int $categoryid Category ID
     * @return array Array of question names
     */
    public static function get_category_questions($categoryid) {
        global $DB;

        // Moodle 4.x: Join through question_bank_entries
        $sql = "SELECT q.name
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                WHERE qbe.questioncategoryid = ?";

        return $DB->get_fieldset_sql($sql, [$categoryid]);
    }
}
