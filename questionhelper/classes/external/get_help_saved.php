<?php
namespace local_questionhelper\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

class get_help_saved extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'variant' => new external_value(PARAM_ALPHA, 'Variant: help or challenge'),
        ]);
    }

    public static function execute($questionid, $cmid, $variant) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), compact('questionid','cmid','variant'));

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_login();

        $record = $DB->get_record('local_qh_saved_help', [
            'userid' => $USER->id,
            'questionid' => $params['questionid'],
            'variant' => $params['variant']
        ]);

        if (!$record) {
            return ['exists' => false];
        }

        return [
            'exists' => true,
            'practice_question' => (string)$record->practice_question,
            'optionsjson' => (string)$record->optionsjson,
            'correct_answer' => (string)$record->correct_answer,
            'explanation' => (string)$record->explanation,
            'concept_explanation' => (string)$record->concept_explanation,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'exists' => new external_value(PARAM_BOOL, 'Record exists'),
            'practice_question' => new external_value(PARAM_RAW, 'Practice question', VALUE_OPTIONAL),
            'optionsjson' => new external_value(PARAM_RAW, 'Options JSON', VALUE_OPTIONAL),
            'correct_answer' => new external_value(PARAM_RAW, 'Correct answer', VALUE_OPTIONAL),
            'explanation' => new external_value(PARAM_RAW, 'Explanation', VALUE_OPTIONAL),
            'concept_explanation' => new external_value(PARAM_RAW, 'Concept explanation', VALUE_OPTIONAL),
        ]);
    }
}


