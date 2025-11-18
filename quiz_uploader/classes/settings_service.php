<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

class settings_service {
    public static function apply_bulk_settings(array $cmids, string $preset, string $timeclosestr, string $activityclass, int $timeclose_enable = 0, int $timelimit_minutes = 0, int $timelimit_enable = 0): array {
        global $DB;
        $results = [];
        $timeclose = $timeclose_enable ? self::parse_timeclose($timeclosestr) : null;
        $applyTimelimit = ($preset === 'test') && !empty($timelimit_enable);
        $timelimit_minutes = $applyTimelimit ? max(0, (int)$timelimit_minutes) : 0;
        foreach ($cmids as $cmid) {
            $res = (object)['cmid' => $cmid, 'success' => false, 'message' => ''];
            try {
                $cm = \get_coursemodule_from_id('quiz', $cmid, 0, false, \MUST_EXIST);
                $quizid = (int)$DB->get_field('quiz', 'id', ['id' => $cm->instance], \IGNORE_MISSING);
                if (!$quizid) {
                    throw new \moodle_exception('invalidquiz', 'error');
                }
                // Apply preset defaults (behaviour, review, timelimit, completion). Leave timeclose as provided only.
                $mode = ($preset === 'nochange') ? 'timeonly' : 'full';
                $effpreset = ($preset === 'nochange') ? 'default' : $preset;
                $timelimitArg = $applyTimelimit ? $timelimit_minutes : null;
                preset_helper::apply_to_quiz($quizid, $effpreset, $timeclose, $timelimitArg, $mode, $applyTimelimit);

                // Activity classification: best-effort placeholder until field details are provided.
                // If a custom field with shortname 'activityclassification' exists for mod_quiz, the code below attempts to store it.
                try { self::maybe_set_activity_classification($cm->id, $activityclass); } catch (\Throwable $e) { /* ignore */ }

                $res->success = true;
                $res->message = 'Updated';
            } catch (\Throwable $e) {
                $res->success = false;
                $res->message = $e->getMessage();
            }
            $results[] = $res;
        }
        return $results;
    }

    private static function parse_timeclose(?string $val): ?int {
        if (empty($val)) { return null; }
        // HTML datetime-local typically posts as YYYY-MM-DDTHH:MM (no timezone). Normalise to space for robust parsing.
        $norm = str_replace('T', ' ', trim((string)$val));
        // If seconds missing, ok; strtotime can handle. If fails, return null.
        $ts = strtotime($norm);
        return $ts ?: null;
    }

    private static function maybe_set_activity_classification(int $cmid, string $value): void {
        global $DB;
        // Attempt to find a customfield field for mod_quiz with shortname 'activityclassification'.
        if (!$DB->get_manager()->table_exists('customfield_field')) { return; }
        $field = $DB->get_record('customfield_field', ['shortname' => 'activityclassification'], 'id,component,area,configdata');
        if (!$field) { return; }
        // Store in customfield_data with instanceid as cmid when area likely maps to module-level data.
        if (!$DB->get_manager()->table_exists('customfield_data')) { return; }
        $data = $DB->get_record('customfield_data', ['fieldid' => $field->id, 'instanceid' => $cmid]);
        $rec = new \stdClass();
        if ($data) {
            $rec = $data;
            $rec->value = $value;
            $rec->valueformat = 0;
            $DB->update_record('customfield_data', $rec);
        } else {
            $rec->fieldid = $field->id;
            $rec->instanceid = $cmid;
            $rec->value = $value;
            $rec->valueformat = 0;
            $DB->insert_record('customfield_data', $rec);
        }
    }
}
