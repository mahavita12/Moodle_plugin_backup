<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

class preset_helper {
    public static function preset_names(): array {
        return [
            'default' => 'Default (Interactive with multiple tries)',
            'test' => 'Test (Deferred Feedback)'
        ];
    }

    public static function get_preset(string $preset = 'default', int $timelimitminutes = 45): array {
        $timebits = [
            'during' => 0x10000,
            'immediately' => 0x01000,
            'open' => 0x00100,
            'closed' => 0x00010,
        ];
        // Only include review fields that exist as bitfields on the quiz table.
        $reviewfields = ['attempt','correctness','marks','specificfeedback','generalfeedback','rightanswer','overallfeedback'];

        $rv = [];
        if ($preset === 'test') {
            $behaviour = 'deferredfeedback';
            $timelimit = $timelimitminutes * 60;
            $map = [
                'during' => ['correctness','marks','specificfeedback'],
                'immediately' => $reviewfields,
                'open' => $reviewfields,
                'closed' => $reviewfields,
            ];
        } else {
            $behaviour = 'interactive';
            $timelimit = 0;
            $map = [
                'during' => ['correctness','marks','specificfeedback'],
                'immediately' => $reviewfields,
                'open' => $reviewfields,
                'closed' => $reviewfields,
            ];
        }

        foreach ($reviewfields as $field) {
            $bits = 0;
            foreach ($map as $time => $fields) {
                if (in_array($field, $fields, true)) {
                    $bits |= $timebits[$time];
                }
            }
            $rv['review' . $field] = $bits;
        }

        return [
            'preferredbehaviour' => $behaviour,
            'timelimit' => $timelimit,
            'reviewbits' => $rv,
        ];
    }

    public static function apply_to_quiz(int $quizid, string $preset = 'default', ?int $timeclose = null, ?int $timelimitminutes = null, string $mode = 'full', bool $applyTimelimit = true): bool {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', \MUST_EXIST);
        $cfg = self::get_preset($preset, $timelimitminutes ?? 45);

        if ($mode !== 'timeonly') {
            $quiz->preferredbehaviour = $cfg['preferredbehaviour'];
            $quiz->shuffleanswers = 0;
            foreach ($cfg['reviewbits'] as $field => $bits) {
                $quiz->$field = $bits;
            }
        }

        // Handle timelimit: 0 always clears; positive value only applied for 'test' preset.
        if ($applyTimelimit) {
            if ($timelimitminutes !== null) {
                $m = max(0, (int)$timelimitminutes);
                if ($m === 0) {
                    $quiz->timelimit = 0;
                } else if ($preset === 'test') {
                    $quiz->timelimit = $m * 60;
                }
            } else if ($mode !== 'timeonly' && $preset === 'test') {
                $quiz->timelimit = $cfg['timelimit'];
            }
        }
        if ($timeclose !== null) {
            $quiz->timeclose = $timeclose;
        }

        $DB->update_record('quiz', $quiz);
        quiz_update_events($quiz);
        quiz_update_open_attempts(['quizid' => $quiz->id]);

        $cm = \get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, \MUST_EXIST);
        // Clear completion settings when applying a preset (not in time-only mode).
        if ($mode !== 'timeonly') {
            // Course module-level completion flags.
            $DB->set_field('course_modules', 'completion', 0, ['id' => $cm->id]);
            if ($DB->get_manager()->field_exists('course_modules', 'completionview')) {
                $DB->set_field('course_modules', 'completionview', 0, ['id' => $cm->id]);
            }
            if ($DB->get_manager()->field_exists('course_modules', 'completionexpected')) {
                $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $cm->id]);
            }
            if ($DB->get_manager()->field_exists('course_modules', 'completiongradeitemnumber')) {
                $DB->set_field('course_modules', 'completiongradeitemnumber', null, ['id' => $cm->id]);
            }

            // Quiz table-specific completion flags (if present on this Moodle version).
            if ($DB->get_manager()->field_exists('quiz', 'completionminattemptsenabled')) {
                $DB->set_field('quiz', 'completionminattemptsenabled', 0, ['id' => $quiz->id]);
            }
            if ($DB->get_manager()->field_exists('quiz', 'completionminattempts')) {
                $DB->set_field('quiz', 'completionminattempts', 0, ['id' => $quiz->id]);
            }
            if ($DB->get_manager()->field_exists('quiz', 'completionattemptsexhausted')) {
                $DB->set_field('quiz', 'completionattemptsexhausted', 0, ['id' => $quiz->id]);
            }
            if ($DB->get_manager()->field_exists('quiz', 'completionpass')) {
                $DB->set_field('quiz', 'completionpass', 0, ['id' => $quiz->id]);
            }
        }

        \set_coursemodule_visible($cm->id, 1);
        \rebuild_course_cache($quiz->course, true);
        return true;
    }
}
