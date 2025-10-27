<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class attempt_generation_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $data = (object)$this->get_custom_data();
        $userid = (int)$data->userid;
        $quizid = (int)$data->quizid;
        $attemptid = (int)$data->attemptid;
        $cmid = (int)$data->cmid;

        // Resolve course and ensure student-only gating.
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        $coursectx = \context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        $isstudent = false;
        foreach ($roles as $ra) { if (!empty($ra->shortname) && $ra->shortname === 'student') { $isstudent = true; break; } }
        if (!$isstudent) { return; }

        // Load personal course context and any existing mapping.
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
        $pq = null;
        if ($pc) {
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pc->id,
                'sourcequizid' => $quizid,
            ], 'id, quizid');
        }

        // Compute grade percent and attempt number for threshold gating.
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id,attempt,sumgrades,quiz,userid', MUST_EXIST);
        if ((int)$attempt->userid !== $userid || (int)$attempt->quiz !== $quizid) { return; }
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades', MUST_EXIST);
        $sumgrades = (float)($attempt->sumgrades ?? 0.0);
        $totalsum = (float)($quiz->sumgrades ?? 0.0);
        $grade = ($totalsum > 0.0) ? (($sumgrades / $totalsum) * 100.0) : 0.0;
        $n = (int)$attempt->attempt;

        if (!$pq) {
            // Initial generation thresholds for first creation only.
            $allow = false;
            if ($n === 1 && $grade > 70.0) { $allow = true; }
            else if ($n === 2 && $grade >= 30.0) { $allow = true; }
            else if ($n >= 3 && $grade >= 30.0) { $allow = true; }
            if (!$allow) { return; }
        }

        // Persist auto-blue for any new incorrects on this attempt.
        $analyzer = new \local_personalcourse\attempt_analyzer();
        $incorrectqids = $analyzer->get_incorrect_questionids_from_attempt($attemptid);
        $time = time();
        $context = \context_module::instance($cmid);
        if (!empty($incorrectqids)) {
            foreach ($incorrectqids as $qid) {
                $existing = $DB->get_record('local_questionflags', ['userid' => $userid, 'questionid' => $qid], 'id');
                if ($existing) { continue; }
                $rec = (object)[
                    'userid' => $userid,
                    'questionid' => $qid,
                    'flagcolor' => 'blue',
                    'cmid' => $cmid,
                    'quizid' => $quizid,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ];
                $id = $DB->insert_record('local_questionflags', $rec);
                $event = \local_questionflags\event\flag_added::create([
                    'context' => $context,
                    'objectid' => $id,
                    'relateduserid' => $userid,
                    'other' => [
                        'questionid' => $qid,
                        'flagcolor' => 'blue',
                        'cmid' => $cmid,
                        'quizid' => $quizid,
                        'origin' => 'auto',
                    ],
                ]);
                $event->trigger();
            }
        }

        if ($pq) {
            // If the mapped quiz was deleted manually, recreate it and update the mapping.
            if (!$DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                $quizrow = $DB->get_record('quiz', ['id' => $quizid], 'id,course,name', MUST_EXIST);
                $sourcecourse = $DB->get_record('course', ['id' => $quizrow->course], 'id,shortname,fullname', MUST_EXIST);
                $prefix = (string)$sourcecourse->shortname;
                $sm = new \local_personalcourse\section_manager();
                $sectionnumber = $sm->ensure_section_by_prefix((int)$pc->courseid, $prefix);
                $qb = new \local_personalcourse\quiz_builder();
                $res = $qb->create_quiz((int)$pc->courseid, $sectionnumber, (string)$quizrow->name, '', 'default');
                if (!empty($res) && !empty($res->quizid)) {
                    $pq->quizid = (int)$res->quizid;
                    $DB->update_record('local_personalcourse_quizzes', (object)['id' => (int)$pq->id, 'quizid' => (int)$pq->quizid, 'timemodified' => time()]);
                } else {
                    return; // Cannot recreate; abort safely.
                }
            }
            // Reconcile existing personal quiz to match flagged ∪ incorrect from this attempt.
            // 1) Source quiz question ids (with fallback for legacy schemas).
            $srcquizqids = [];
            try {
                $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                        FROM {quiz_slots} qs
                                                        JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                        JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                        JOIN {question} q ON q.id = qv.questionid
                                                       WHERE qs.quizid = ?", [$quizid]);
            } catch (\Throwable $e) {
                $srcquizqids = [];
            }
            if (empty($srcquizqids)) {
                try {
                    $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT qs.questionid
                                                            FROM {quiz_slots} qs
                                                           WHERE qs.quizid = ? AND qs.questionid IS NOT NULL", [$quizid]);
                } catch (\Throwable $e) {
                    $srcquizqids = [];
                }
            }

            // 2) Flagged by user intersected with source quiz.
            $flagqids = [];
            if (!empty($srcquizqids)) {
                list($insqlq, $inparamsq) = $DB->get_in_or_equal(array_map('intval', $srcquizqids), SQL_PARAMS_QM);
                $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ? AND questionid {$insqlq}", array_merge([$userid], $inparamsq));
            }

            // 3) Desired set = flagged only (incorrects are persisted as auto-blue flags separately).
            $qids = array_map('intval', $flagqids);

            // 4) Current question ids in personal quiz.
            $currqids = [];
            try {
                $currqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                     FROM {quiz_slots} qs
                                                     JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                     JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                     JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                     JOIN {question} q ON q.id = qv.questionid
                                                    WHERE qs.quizid = ?", [(int)$pq->quizid]);
            } catch (\Throwable $e) {
                $currqids = [];
            }
            if (empty($currqids)) {
                try {
                    $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->quizid]);
                } catch (\Throwable $e) {
                    $currqids = [];
                }
            }
            $currqids = array_map('intval', $currqids);

            $toadd = array_values(array_diff($qids, $currqids));
            $toremove = array_values(array_diff($currqids, $qids));

            $qb = new \local_personalcourse\quiz_builder();
            if (!empty($toremove)) {
                foreach ($toremove as $qid) {
                    $qb->remove_question((int)$pq->quizid, (int)$qid);
                    $DB->delete_records('local_personalcourse_questions', ['personalquizid' => (int)$pq->id, 'questionid' => (int)$qid]);
                }
            }
            if (!empty($toadd)) {
                $addres = $qb->add_questions((int)$pq->quizid, $toadd);
                // Upsert mapping rows.
                $now = time();
                foreach ($toadd as $qid) {
                    $existsrow = $DB->get_record('local_personalcourse_questions', [
                        'personalcourseid' => (int)$pc->id,
                        'questionid' => (int)$qid,
                    ]);
                    if ($existsrow) {
                        $existsrow->personalquizid = (int)$pq->id;
                        if (empty($existsrow->flagcolor)) { $existsrow->flagcolor = 'blue'; }
                        $existsrow->timemodified = $now;
                        $DB->update_record('local_personalcourse_questions', $existsrow);
                    } else {
                        $DB->insert_record('local_personalcourse_questions', (object)[
                            'personalcourseid' => (int)$pc->id,
                            'personalquizid' => (int)$pq->id,
                            'questionid' => (int)$qid,
                            'slotid' => null,
                            'flagcolor' => 'blue',
                            'source' => 'auto',
                            'originalposition' => null,
                            'currentposition' => null,
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ]);
                    }
                }
            }
            return;
        }

        // If we get here, it's first-time generation (allowed). Persist incorrects and rely on flag events to finalize creation.
        if (empty($incorrectqids)) { return; }
        foreach ($incorrectqids as $qid) {
            // Already persisted above if not existing; re-check not necessary.
        }
    }
}
