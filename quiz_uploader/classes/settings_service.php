<?php
namespace local_quiz_uploader;

defined('MOODLE_INTERNAL') || die();

class settings_service {
    public static function apply_bulk_settings(array $cmids, string $preset, string $timeclosestr, string $activityclass): array {
        global $DB;
        $results = [];
        $timeclose = self::parse_timeclose($timeclosestr);
        foreach ($cmids as $cmid) {
            $res = (object)['cmid' => $cmid, 'success' => false, 'message' => ''];
            try {
                $cm = \get_coursemodule_from_id('quiz', $cmid, 0, false, \MUST_EXIST);
                $quizid = (int)$DB->get_field('quiz', 'id', ['id' => $cm->instance], \IGNORE_MISSING);
                if (!$quizid) {
                    throw new \moodle_exception('invalidquiz', 'error');
                }
                // Apply preset defaults (behaviour, review, timelimit, completion). Leave timeclose as provided only.
                preset_helper::apply_to_quiz($quizid, $preset === 'nochange' ? 'default' : $preset, $timeclose, null);

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
        // Expect HTML datetime-local format: YYYY-MM-DDTHH:MM
        $ts = strtotime($val);
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
