<?php
namespace local_questionflags\external;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

class flag_question extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'Question ID'),
            'flagcolor' => new external_value(PARAM_ALPHA, 'Flag color (blue or red)'),
            'isflagged' => new external_value(PARAM_BOOL, 'Is question flagged'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    public static function execute($questionid, $flagcolor, $isflagged, $cmid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'flagcolor' => $flagcolor,
            'isflagged' => $isflagged,
            'cmid' => $cmid,
        ]);

        // Validate context
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);

        // Check capability
        require_capability('local/questionflags:flag', $context);

        // Validate flag color
        if (!in_array($params['flagcolor'], ['blue', 'red'])) {
            throw new invalid_parameter_exception('Invalid flag color');
        }

        $time = time();

        // Resolve quiz from cmid (for attribution)
        $cm = get_coursemodule_from_id('quiz', $params['cmid']);
        $quizid = $cm ? (int)$cm->instance : null;

        $qbeid = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => (int)$params['questionid']], IGNORE_MISSING);
        $siblings = [];
        if (!empty($qbeid)) {
            $siblings = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int)$qbeid]);
        }

        if ($params['isflagged']) {
            if (!empty($siblings)) {
                list($in, $inparams) = $DB->get_in_or_equal($siblings, SQL_PARAMS_QM);
                $DB->delete_records_select('local_questionflags', 'userid = ? AND questionid ' . $in, array_merge([(int)$USER->id], $inparams));
            } else {
                $DB->delete_records('local_questionflags', [
                    'userid' => $USER->id,
                    'questionid' => $params['questionid']
                ]);
            }

            $record = new \stdClass();
            $record->userid = $USER->id;
            $record->questionid = $params['questionid'];
            $record->flagcolor = $params['flagcolor'];
            $record->cmid = $params['cmid'];
            $record->quizid = $quizid;
            $record->timecreated = $time;
            $record->timemodified = $time;

            $insertid = $DB->insert_record('local_questionflags', $record);

            if (!empty($siblings)) {
                foreach ($siblings as $sid) {
                    $sid = (int)$sid;
                    if ($sid === (int)$params['questionid']) { continue; }
                    if (!$DB->record_exists('local_questionflags', ['userid' => (int)$USER->id, 'questionid' => $sid])) {
                        $r2 = new \stdClass();
                        $r2->userid = (int)$USER->id;
                        $r2->questionid = $sid;
                        $r2->flagcolor = $params['flagcolor'];
                        $r2->cmid = $params['cmid'];
                        $r2->quizid = $quizid;
                        $r2->timecreated = $time;
                        $r2->timemodified = $time;
                        try { $DB->insert_record('local_questionflags', $r2, false); } catch (\Throwable $e) {}
                    }
                }
            }

            $event = \local_questionflags\event\flag_added::create([
                'context' => $context,
                'objectid' => $insertid,
                'relateduserid' => $USER->id,
                'other' => [
                    'questionid' => $params['questionid'],
                    'flagcolor' => $params['flagcolor'],
                    'cmid' => $params['cmid'],
                    'quizid' => $quizid,
                ],
            ]);
            $event->trigger();
        } else {
            if (!empty($siblings)) {
                list($in, $inparams) = $DB->get_in_or_equal($siblings, SQL_PARAMS_QM);
                $DB->delete_records_select('local_questionflags', 'userid = ? AND questionid ' . $in, array_merge([(int)$USER->id], $inparams));
            } else {
                $DB->delete_records('local_questionflags', [
                    'userid' => $USER->id,
                    'questionid' => $params['questionid']
                ]);
            }

            $event = \local_questionflags\event\flag_removed::create([
                'context' => $context,
                'objectid' => 0,
                'relateduserid' => $USER->id,
                'other' => [
                    'questionid' => $params['questionid'],
                    'flagcolor' => $params['flagcolor'],
                    'cmid' => $params['cmid'],
                    'quizid' => $quizid,
                ],
            ]);
            $event->trigger();
        }

        return [
            'success' => true,
            'message' => 'Flag updated successfully'
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Response message'),
        ]);
    }
}