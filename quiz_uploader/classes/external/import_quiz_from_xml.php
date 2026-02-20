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
use context_course;
use context_system;
use local_quiz_uploader\xml_parser;
use local_quiz_uploader\category_manager;
use local_quiz_uploader\duplicate_checker;
use local_quiz_uploader\question_importer;
use local_quiz_uploader\quiz_creator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

/**
 * External function for importing quiz from XML.
 */
class import_quiz_from_xml extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section database ID'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item ID with XML file'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'checkduplicates' => new external_value(PARAM_INT, 'Check for duplicates (1=yes, 0=no)', VALUE_DEFAULT, 1),
            'quizsettings' => new external_value(PARAM_RAW, 'Quiz settings as JSON', VALUE_DEFAULT, '{}'),
            'categoryid' => new external_value(PARAM_INT, 'Question category ID (optional, extracted from XML if not provided)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Import quiz from XML file in draft area.
     *
     * @param int $courseid Course ID
     * @param int $sectionid Section database ID
     * @param int $draftitemid Draft item ID
     * @param string $quizname Quiz name
     * @param int $checkduplicates Check duplicates flag
     * @param string $quizsettings Quiz settings JSON
     * @param int $categoryid Question category ID (0 = extract from XML)
     * @return array Result array
     */
    public static function execute($courseid, $sectionid, $draftitemid, $quizname, $checkduplicates = 1, $quizsettings = '{}', $categoryid = 0) {
        global $USER;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionid' => $sectionid,
            'draftitemid' => $draftitemid,
            'quizname' => $quizname,
            'checkduplicates' => $checkduplicates,
            'quizsettings' => $quizsettings,
            'categoryid' => $categoryid,
        ]);

        // Validate context
        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Check capabilities
        require_capability('moodle/question:add', $context);
        require_capability('mod/quiz:addinstance', $context);

        // Parse quiz settings
        $settings = json_decode($quizsettings);
        if ($settings === null) {
            $settings = new \stdClass();
        }

        // Step 1: Get XML file from draft area
        // File is in current user's draft area
        $xmlcontent = question_importer::get_file_from_draft($draftitemid, $USER->id);
        if (!$xmlcontent) {
            return [
                'success' => false,
                'error' => 'nofile',
                'message' => get_string('error_nofile', 'local_quiz_uploader'),
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsimported' => 0,
                'questionids' => json_encode([]),
                'categoryid' => 0,
                'categoryname' => '',
                'duplicates' => json_encode([]),
            ];
        }

        // Step 2: Validate XML
        if (!xml_parser::validate_xml($xmlcontent)) {
            return [
                'success' => false,
                'error' => 'invalidxml',
                'message' => get_string('error_invalidxml', 'local_quiz_uploader'),
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsimported' => 0,
                'questionids' => json_encode([]),
                'categoryid' => 0,
                'categoryname' => '',
                'duplicates' => json_encode([]),
            ];
        }

        // Step 3: Get category (use provided categoryid or extract from XML)
        if (!empty($categoryid)) {
            // Use the category provided from the upload form (5-layer structure)
            global $DB;
            $category = $DB->get_record('question_categories', ['id' => $categoryid]);
            if (!$category) {
                return [
                    'success' => false,
                    'error' => 'nocategory',
                    'message' => 'Invalid category ID provided: ' . $categoryid,
                    'quizid' => 0,
                    'cmid' => 0,
                    'quizurl' => '',
                    'questionsimported' => 0,
                    'questionids' => json_encode([]),
                    'categoryid' => 0,
                    'categoryname' => '',
                    'duplicates' => json_encode([]),
                ];
            }
        } else {
            // Legacy: Extract category path from XML (old method)
            $categorypath = xml_parser::extract_category_path($xmlcontent);
            if (!$categorypath) {
                $categorypath = '$course$/top/Imported Questions';
            }

            // Step 4: Get or create category
            $category = category_manager::get_or_create_from_path($categorypath, $courseid);
            if (!$category) {
                return [
                    'success' => false,
                    'error' => 'nocategory',
                    'message' => get_string('error_nocategory', 'local_quiz_uploader'),
                    'quizid' => 0,
                    'cmid' => 0,
                    'quizurl' => '',
                    'questionsimported' => 0,
                    'questionids' => json_encode([]),
                    'categoryid' => 0,
                    'categoryname' => '',
                    'duplicates' => json_encode([]),
                ];
            }
        }

        // Step 5: Check for duplicates (if enabled)
        if ($checkduplicates) {
            $systemcontext = context_system::instance();
            $dupcheck = duplicate_checker::check_all($courseid, $quizname, $category->name, $systemcontext->id);

            if ($dupcheck->has_duplicates) {
                return [
                    'success' => false,
                    'error' => 'duplicate_detected',
                    'message' => "Duplicate found: Topic '{$dupcheck->category_name}' already exists with questions in the question bank.",
                    'quizid' => 0,
                    'cmid' => 0,
                    'quizurl' => '',
                    'questionsimported' => 0,
                    'questionids' => json_encode([]),
                    'categoryid' => $category->id,
                    'categoryname' => $category->name,
                    'duplicates' => json_encode([
                        'category_exists' => $dupcheck->category_exists,
                        'category_name' => $dupcheck->category_name,
                    ]),
                ];
            }
        }

        // Step 6: Import questions
        $importresult = question_importer::import_from_xml($xmlcontent, $category, $courseid);
        if (!$importresult->success) {
            return [
                'success' => false,
                'error' => 'importfailed',
                'message' => get_string('error_importfailed', 'local_quiz_uploader') . ': ' . ($importresult->error ?? ''),
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsimported' => 0,
                'questionids' => json_encode([]),
                'categoryid' => $category->id,
                'categoryname' => $category->name,
                'duplicates' => json_encode([]),
            ];
        }

        // Step 7: Create quiz
        $quizresult = quiz_creator::create_quiz($courseid, $sectionid, $quizname, '', $settings);
        if (!$quizresult->success) {
            return [
                'success' => false,
                'error' => 'quizcreatefailed',
                'message' => get_string('error_quizcreatefailed', 'local_quiz_uploader') . ': ' . ($quizresult->error ?? ''),
                'quizid' => 0,
                'cmid' => 0,
                'quizurl' => '',
                'questionsimported' => $importresult->count,
                'questionids' => json_encode($importresult->questionids),
                'categoryid' => $category->id,
                'categoryname' => $category->name,
                'duplicates' => json_encode([]),
            ];
        }

        // Step 8: Add questions to quiz
        $addresult = quiz_creator::add_questions_to_quiz($quizresult->quizid, $importresult->questionids);

        // Step 9: Return success
        return [
            'success' => true,
            'error' => '',
            'message' => "Quiz '{$quizname}' created successfully with {$addresult->count} questions",
            'quizid' => $quizresult->quizid,
            'cmid' => $quizresult->cmid,
            'quizurl' => quiz_creator::get_quiz_url($quizresult->cmid),
            'questionsimported' => $importresult->count,
            'questionids' => json_encode($importresult->questionids),
            'categoryid' => $category->id,
            'categoryname' => $category->name,
            'duplicates' => json_encode([]),
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
            'questionsimported' => new external_value(PARAM_INT, 'Number of questions imported'),
            'questionids' => new external_value(PARAM_RAW, 'JSON array of question IDs'),
            'categoryid' => new external_value(PARAM_INT, 'Category ID'),
            'categoryname' => new external_value(PARAM_TEXT, 'Category name'),
            'duplicates' => new external_value(PARAM_RAW, 'JSON object with duplicate information'),
        ]);
    }
}
