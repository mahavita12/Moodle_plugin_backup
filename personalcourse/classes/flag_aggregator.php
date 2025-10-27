<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class flag_aggregator {
    /**
     * Get manual (and persisted) flags for a user within a specific source quiz.
     * Returns array with keys 'blue' and 'red' => list of questionids.
     */
    public function get_flags_for_quiz(int $userid, int $sourcequizid): array {
        global $DB;
        $result = ['blue' => [], 'red' => []];

        // Prefer rows with quizid attribution; fall back to join through slots otherwise.
        $sql = "SELECT qf.questionid, qf.flagcolor
                  FROM {local_questionflags} qf
                 WHERE qf.userid = ? AND qf.quizid = ?";
        $params = [$userid, $sourcequizid];
        $records = $DB->get_records_sql($sql, $params);

        if (!$records) {
            // Legacy fallback (rare): infer quiz by joining slots.
            $sql = "SELECT qf.questionid, qf.flagcolor
                      FROM {local_questionflags} qf
                      JOIN {quiz_slots} qs ON qs.questionid = qf.questionid
                      JOIN {quiz} q ON q.id = qs.quizid
                     WHERE qf.userid = ? AND q.id = ?";
            $records = $DB->get_records_sql($sql, $params);
        }

        foreach ($records as $rec) {
            if ($rec->flagcolor === 'red') {
                $result['red'][] = (int)$rec->questionid;
            } else if ($rec->flagcolor === 'blue') {
                $result['blue'][] = (int)$rec->questionid;
            }
        }
        return $result;
    }
}
