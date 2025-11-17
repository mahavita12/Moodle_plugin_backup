<?php
namespace local_questionflags\task;

defined('MOODLE_INTERNAL') || die();

class reconcile_flags_task extends \core\task\scheduled_task {
    public function get_name() {
        return 'Question flags reconciliation';
    }

    public function execute() {
        global $DB;

        $batch = 2000;
        $offset = 0;
        do {
            $rows = $DB->get_records_sql(
                "SELECT qf.id, qf.userid, qf.questionid, qf.flagcolor, qf.timemodified, qv.questionbankentryid AS qbe
                   FROM {local_questionflags} qf
                   JOIN {question_versions} qv ON qv.questionid = qf.questionid
                  ORDER BY qf.userid ASC, qv.questionbankentryid ASC, qf.timemodified ASC",
                null, $offset, $batch
            );
            if (!$rows) { break; }

            $groups = [];
            foreach ($rows as $r) {
                $key = $r->userid . ':' . $r->qbe;
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'userid' => (int)$r->userid,
                        'qbe' => (int)$r->qbe,
                        'latesttime' => (int)$r->timemodified,
                        'latestcolor' => (string)$r->flagcolor,
                        'qids' => [],
                    ];
                }
                if ((int)$r->timemodified >= (int)$groups[$key]['latesttime']) {
                    $groups[$key]['latesttime'] = (int)$r->timemodified;
                    $groups[$key]['latestcolor'] = (string)$r->flagcolor;
                }
                $groups[$key]['qids'][(int)$r->questionid] = (int)$r->id; // map questionid -> row id
            }

            foreach ($groups as $g) {
                $siblings = $DB->get_fieldset_select('question_versions', 'questionid', 'questionbankentryid = ?', [(int)$g['qbe']]);
                if (empty($siblings)) { continue; }
                $latestcolor = $g['latestcolor'];
                $latesttime = $g['latesttime'];
                foreach ($siblings as $sid) {
                    $sid = (int)$sid;
                    if (isset($g['qids'][$sid])) {
                        $rowid = (int)$g['qids'][$sid];
                        $DB->set_field('local_questionflags', 'flagcolor', $latestcolor, ['id' => $rowid]);
                        $DB->set_field('local_questionflags', 'timemodified', $latesttime, ['id' => $rowid]);
                    } else {
                        $DB->insert_record('local_questionflags', (object)[
                            'userid' => (int)$g['userid'],
                            'questionid' => $sid,
                            'flagcolor' => $latestcolor,
                            'timecreated' => $latesttime,
                            'timemodified' => $latesttime,
                            'quizid' => null,
                            'cmid' => null,
                        ]);
                    }
                }
            }

            $offset += $batch;
        } while (true);
    }
}
