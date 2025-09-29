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

        // Load both personal and global records
        $personal = $DB->get_record('local_qh_saved_help', [
            'userid' => $USER->id,
            'questionid' => $params['questionid'],
            'variant' => $params['variant'],
            'is_global' => 0
        ]);

        $global = $DB->get_record('local_qh_saved_help', [
            'questionid' => $params['questionid'],
            'variant' => $params['variant'],
            'is_global' => 1
        ]);

        if (!$personal && !$global) {
            return ['exists' => false];
        }

        // If personal missing but global exists, create personal from global
        if (!$personal && $global) {
            $now = time();
            $personal = (object) [
                'userid' => $USER->id,
                'questionid' => $params['questionid'],
                'variant' => $params['variant'],
                'practice_question' => $global->practice_question,
                'optionsjson' => $global->optionsjson,
                'correct_answer' => $global->correct_answer,
                'explanation' => $global->explanation,
                'concept_explanation' => $global->concept_explanation,
                'is_global' => 0,
                'timecreated' => $now,
                'timemodified' => $global->timemodified ?? $now
            ];
            $personal->id = $DB->insert_record('local_qh_saved_help', $personal);
        }

        // If both exist and global is newer, sync personal
        if ($personal && $global && (int)$global->timemodified > (int)$personal->timemodified) {
            $personal->practice_question = $global->practice_question;
            $personal->optionsjson = $global->optionsjson;
            $personal->correct_answer = $global->correct_answer;
            $personal->explanation = $global->explanation;
            $personal->concept_explanation = $global->concept_explanation;
            $personal->timemodified = $global->timemodified;
            $DB->update_record('local_qh_saved_help', $personal);
        }

        return [
            'exists' => true,
            'practice_question' => (string)$personal->practice_question,
            'optionsjson' => (string)$personal->optionsjson,
            'correct_answer' => (string)$personal->correct_answer,
            'explanation' => (string)$personal->explanation,
            'concept_explanation' => (string)$personal->concept_explanation,
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


