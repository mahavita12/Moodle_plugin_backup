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
     * Check if questions already exist in category.
     *
     * @param int $categoryid Category ID
     * @param array $questionnames Array of question names to check
     * @return array Array of question names that already exist
     */
    public static function questions_exist($categoryid, $questionnames) {
        global $DB;

        if (empty($questionnames)) {
            return [];
        }

        // Use IN clause to check multiple names
        list($insql, $params) = $DB->get_in_or_equal($questionnames, SQL_PARAMS_NAMED);
        $params['categoryid'] = $categoryid;

        // Moodle 4.x uses question_bank_entries structure
        $sql = "SELECT DISTINCT q.name
                FROM {question} q
                JOIN {question_versions} qv ON qv.questionid = q.id
                JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                WHERE qbe.questioncategoryid = :categoryid
                AND q.name $insql";

        $existing = $DB->get_fieldset_sql($sql, $params);

        return $existing;
    }

    /**
     * Comprehensive duplicate check for quiz and questions.
     *
     * @param int $courseid Course ID
     * @param string $quizname Quiz name
     * @param int $categoryid Category ID
     * @param array $questionnames Array of question names
     * @return object Result object with duplicate information
     */
    public static function check_all($courseid, $quizname, $categoryid, $questionnames) {
        $result = new \stdClass();
        $result->has_duplicates = false;
        $result->quiz_exists = false;
        $result->quiz_name = null;
        $result->questions_exist = [];
        $result->question_count = 0;

        // Check quiz name
        if (self::quiz_exists($courseid, $quizname)) {
            $result->has_duplicates = true;
            $result->quiz_exists = true;
            $result->quiz_name = $quizname;
        }

        // Check questions
        $existing_questions = self::questions_exist($categoryid, $questionnames);
        if (!empty($existing_questions)) {
            $result->has_duplicates = true;
            $result->questions_exist = $existing_questions;
            $result->question_count = count($existing_questions);
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
