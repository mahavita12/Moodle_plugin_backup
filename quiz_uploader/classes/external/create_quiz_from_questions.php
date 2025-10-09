<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quiz_uploader\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use local_quiz_uploader\duplicate_checker;
use local_quiz_uploader\quiz_creator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External function for creating quiz from existing questions.
 */
class create_quiz_from_questions extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section database ID'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question ID'),
                'Array of question IDs'
            ),
            'checkduplicates' => new external_value(PARAM_INT, 'Check for duplicate quiz name (1=yes, 0=no)', VALUE_DEFAULT, 1),
            'quizsettings' => new external_value(PARAM_RAW, 'Quiz settings as JSON', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Create quiz from existing questions.
     *
     * @param int $courseid Course ID
     * @param int $sectionid Section database ID
     * @param string $quizname Quiz name
     * @param array $questionids Array of question IDs
     * @param int $checkduplicates Check duplicates flag
     * @param string $quizsettings Quiz settings JSON
     * @return array Result array
     */
    public static function execute($courseid, $sectionid, $quizname, $questionids, $checkduplicates = 1, $quizsettings = '{}') {
        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'quizname' => $quizname,
            'questionids' => $questionids,
            'checkduplicates' => $checkduplicates,
            'quizsettings' => $quizsettings,
        ]);

        // Validate context
        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Check capabilities
        require_capability('mod/quiz:addinstance', $context);
        require_capability('mod/quiz:manage', $context);

        // Parse quiz settings
        $settings = json_decode($quizsettings);
        if ($settings === null) {
            $settings = new \stdClass();
        }

        // Check for duplicate quiz name
        if ($checkduplicates && duplicate_checker::quiz_exists($courseid, $quizname)) {
            return [
                'success' => false,
                'error' => 'duplicate_quiz',
                'message' => "Quiz '{$quizname}' already exists in this course",
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsadded' => 0,
            ];
        }

        // Create quiz
        $quizresult = quiz_creator::create_quiz($courseid, $sectionid, $quizname, '', $settings);
        if (!$quizresult->success) {
            return [
                'success' => false,
                'error' => 'quizcreatefailed',
                'message' => 'Quiz creation failed: ' . ($quizresult->error ?? ''),
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsadded' => 0,
            ];
        }

        // Add questions to quiz
        $addresult = quiz_creator::add_questions_to_quiz($quizresult->quizid, $questionids);

        return [
            'success' => true,
            'error' => '',
            'message' => "Quiz '{$quizname}' created successfully with {$addresult->count} questions",
            'quizid' => $quizresult->quizid,
            'cmid' => $quizresult->cmid,
            'quizurl' => quiz_creator::get_quiz_url($quizresult->cmid),
            'questionsadded' => $addresult->count,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation was successful'),
            'error' => new external_value(PARAM_TEXT, 'Error code if failed'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'quizid' => new external_value(PARAM_INT, 'Quiz ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'quizurl' => new external_value(PARAM_TEXT, 'Quiz URL'),
            'questionsadded' => new external_value(PARAM_INT, 'Number of questions added to quiz'),
        ]);
    }
}
