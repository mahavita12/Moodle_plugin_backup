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

class save_help extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'variant' => new external_value(PARAM_ALPHA, 'Variant: help or challenge'),
            'practice_question' => new external_value(PARAM_TEXT, 'Practice question text'),
            'optionsjson' => new external_value(PARAM_RAW, 'Options JSON (A-D)'),
            'correct_answer' => new external_value(PARAM_ALPHA, 'Correct option letter'),
            'explanation' => new external_value(PARAM_TEXT, 'Answer explanation'),
            'concept_explanation' => new external_value(PARAM_TEXT, 'Concept explanation')
        ]);
    }

    public static function execute($questionid, $cmid, $variant, $practice_question, $optionsjson, $correct_answer, $explanation, $concept_explanation) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), compact(
            'questionid','cmid','variant','practice_question','optionsjson','correct_answer','explanation','concept_explanation'
        ));

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_login();

        $now = time();

        $record = $DB->get_record('local_qh_saved_help', [
            'userid' => $USER->id,
            'questionid' => $params['questionid'],
            'variant' => $params['variant']
        ]);

        if ($record) {
            $record->practice_question = $params['practice_question'];
            $record->optionsjson = $params['optionsjson'];
            $record->correct_answer = $params['correct_answer'];
            $record->explanation = $params['explanation'];
            $record->concept_explanation = $params['concept_explanation'];
            $record->timemodified = $now;
            $DB->update_record('local_qh_saved_help', $record);
        } else {
            $record = (object) [
                'userid' => $USER->id,
                'questionid' => $params['questionid'],
                'variant' => $params['variant'],
                'practice_question' => $params['practice_question'],
                'optionsjson' => $params['optionsjson'],
                'correct_answer' => $params['correct_answer'],
                'explanation' => $params['explanation'],
                'concept_explanation' => $params['concept_explanation'],
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_qh_saved_help', $record);
        }

        return ['success' => true];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success')
        ]);
    }
}


