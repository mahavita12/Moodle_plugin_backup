<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

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
            'completion' => [
                'enable' => true,
                'minattempts' => 2,
            ],
        ];
    }

    public static function apply_to_quiz(int $quizid, string $preset = 'default', ?int $timeclose = null, ?int $timelimitminutes = null, string $mode = 'full', bool $applyTimelimit = true): bool {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', \MUST_EXIST);
        $cfg = self::get_preset($preset, $timelimitminutes ?? 45);

        if ($mode !== 'timeonly') {
            $quiz->preferredbehaviour = $cfg['preferredbehaviour'];
            $quiz->shuffleanswers = 0;
            if ($applyTimelimit) {
                if ($timelimitminutes !== null) {
                    $quiz->timelimit = max(0, (int)$timelimitminutes) * 60;
                } else if ($preset === 'test') {
                    $quiz->timelimit = $cfg['timelimit'];
                }
            }
            foreach ($cfg['reviewbits'] as $field => $bits) {
                $quiz->$field = $bits;
            }
        }
        if ($timeclose !== null) {
            $quiz->timeclose = $timeclose;
        }

        $DB->update_record('quiz', $quiz);

        $cm = \get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, \MUST_EXIST);
        if ($mode !== 'timeonly' && !empty($cfg['completion']['enable'])) {
            $DB->set_field('course_modules', 'completion', 2, ['id' => $cm->id]);
            if ($DB->get_manager()->field_exists('quiz', 'completionminattempts')) {
                $DB->set_field('quiz', 'completionminattempts', (int)$cfg['completion']['minattempts'], ['id' => $quiz->id]);
            }
            if ($DB->get_manager()->field_exists('quiz', 'completionminattemptsenabled')) {
                $DB->set_field('quiz', 'completionminattemptsenabled', 1, ['id' => $quiz->id]);
            }
        }

        \set_coursemodule_visible($cm->id, 1);
        \rebuild_course_cache($quiz->course, true);
        return true;
    }
}
