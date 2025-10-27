<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

use context_module;
use context_course;

class observers {

    public static function on_flag_added(\local_questionflags\event\flag_added $event): void {
        self::handle_flag_change($event, true);
    }

    public static function on_flag_removed(\local_questionflags\event\flag_removed $event): void {
        self::handle_flag_change($event, false);
    }

    private static function handle_flag_change(\core\event\base $event, bool $added): void {
        global $DB;

        $userid = $event->relateduserid ?? null;
        if (!$userid) {
            return;
        }

        $context = $event->get_context();
        if (!($context instanceof context_module)) {
            return;
        }

        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = $cm->course;

        // If this is a personal course, allow regardless of acting user's role (we will re-attribute to owner below).
        $pcownerrow = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,userid,courseid');
        if (!$pcownerrow) {
            // Otherwise require the actor to be a student or site admin.
            if (!self::user_has_student_role($userid, $courseid)) {
                return;
            }
        }

        // Queue adhoc task to perform sync out of request context.
        $questionid = (int)$event->other['questionid'];
        $flagcolor = (string)$event->other['flagcolor'];
        $quizid = isset($event->other['quizid']) ? (int)$event->other['quizid'] : null;
        $origin = isset($event->other['origin']) ? (string)$event->other['origin'] : 'manual';

        // If this CM belongs to a personal course, re-attribute the change to the personal course owner.
        $pcowner = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,courseid,userid');
        $targetuserid = $pcowner ? (int)$pcowner->userid : (int)$userid;

        $sync = new \local_personalcourse\sync_manager();
        $sync->queue_flag_change($targetuserid, $questionid, $flagcolor, $added, $cmid, $quizid, $origin);

        // Immediate best-effort reconciliation so counts reflect flag changes without waiting for cron.
        try {
            if (!$quizid) { $quizid = (int)$cm->instance; }
            // Ensure personal course mapping exists for target user; if not, we only handle removal from any existing PQ.
            $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $targetuserid], 'id,courseid');
            if ($pc) {
                // Determine if the flag event came from the student's personal course.
                $ispcourse = ((int)$pc->courseid === (int)$courseid);
                // Resolve the mapped PQ accordingly: by personal quiz id if in personal course; otherwise by source quiz id.
                if ($ispcourse) {
                    $pq = $DB->get_record('local_personalcourse_quizzes', [
                        'personalcourseid' => (int)$pc->id,
                        'quizid' => (int)$cm->instance,
                    ], 'id, quizid, sourcequizid');
                } else {
                    $pq = $DB->get_record('local_personalcourse_quizzes', [
                        'personalcourseid' => (int)$pc->id,
                        'sourcequizid' => (int)$quizid,
                    ], 'id, quizid, sourcequizid');
                }

                $qb = new \local_personalcourse\quiz_builder();

                if ($added) {
                    // If we re-attributed to the owner, ensure the owner's flag row exists.
                    if ($targetuserid !== $userid) {
                        $existsflag = $DB->record_exists('local_questionflags', [
                            'userid' => (int)$targetuserid,
                            'questionid' => (int)$questionid,
                        ]);
                        if (!$existsflag) {
                            $time = time();
                            $DB->insert_record('local_questionflags', (object)[
                                'userid' => (int)$targetuserid,
                                'questionid' => (int)$questionid,
                                'flagcolor' => $flagcolor ?: 'blue',
                                'cmid' => $cmid,
                                'quizid' => $quizid ?: (int)$cm->instance,
                                'timecreated' => $time,
                                'timemodified' => $time,
                            ]);
                        }
                    }
                    if ($pq && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        // Dedupe: if this question is in another PQ inside this personal course, move it here.
                        $existing = $DB->get_record('local_personalcourse_questions', [
                            'personalcourseid' => (int)$pc->id,
                            'questionid' => (int)$questionid,
                        ]);
                        if ($existing && (int)$existing->personalquizid !== (int)$pq->id) {
                            $oldpq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$existing->personalquizid], 'id, quizid');
                            if ($oldpq) { $qb->remove_question((int)$oldpq->quizid, (int)$questionid); }
                            $DB->delete_records('local_personalcourse_questions', ['id' => (int)$existing->id]);
                        }
                        // Add to current PQ if not present.
                        $present = $DB->record_exists('local_personalcourse_questions', [
                            'personalcourseid' => (int)$pc->id,
                            'personalquizid' => (int)$pq->id,
                            'questionid' => (int)$questionid,
                        ]);
                        if (!$present) {
                            $qb->add_questions((int)$pq->quizid, [(int)$questionid]);
                            $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$pq->quizid, 'questionid' => (int)$questionid]);
                            $DB->insert_record('local_personalcourse_questions', (object)[
                                'personalcourseid' => (int)$pc->id,
                                'personalquizid' => (int)$pq->id,
                                'questionid' => (int)$questionid,
                                'slotid' => $slotid ? (int)$slotid : null,
                                'flagcolor' => $flagcolor ?: 'blue',
                                'source' => ($origin === 'auto') ? 'auto' : 'manual_flag',
                                'originalposition' => null,
                                'currentposition' => null,
                                'timecreated' => time(),
                                'timemodified' => time(),
                            ]);
                        }
                    }
                } else {
                    // Removal: remove this question from any personal quiz within the student's personal course immediately.
                    $existing = $DB->get_record('local_personalcourse_questions', [
                        'personalcourseid' => (int)$pc->id,
                        'questionid' => (int)$questionid,
                    ], 'id, personalquizid');
                    // If we re-attributed to the owner, also remove the owner's flag rows.
                    if ($targetuserid !== $userid) {
                        $DB->delete_records('local_questionflags', [
                            'userid' => (int)$targetuserid,
                            'questionid' => (int)$questionid,
                        ]);
                    }
                    if ($existing) {
                        $targetpq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$existing->personalquizid], 'id, quizid');
                        if ($targetpq) { $qb->remove_question((int)$targetpq->quizid, (int)$questionid); }
                        $DB->delete_records('local_personalcourse_questions', ['id' => (int)$existing->id]);
                    } else if (!empty($pq) && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        // No mapping row found; remove directly from the mapped quiz.
                        $qb->remove_question((int)$pq->quizid, (int)$questionid);
                    } else {
                        // As a last resort (e.g. flag removal in personal course with missing mapping), remove from this cm's quiz instance.
                        $qb->remove_question((int)$cm->instance, (int)$questionid);
                    }
                }

                // Always reconcile: ensure the personal quiz equals (owner flags ∩ source quiz questions).
                try {
                    if (!empty($pq) && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        $sourcequizid = null;
                        if (!empty($pq->sourcequizid)) {
                            $sourcequizid = (int)$pq->sourcequizid;
                        } else if (!$ispcourse && !empty($quizid)) {
                            // Event came from source quiz context.
                            $sourcequizid = (int)$quizid;
                        }

                        if (!empty($sourcequizid)) {
                            // 1) Source quiz question ids.
                            $srcquizqids = [];
                            try {
                                $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                                                           FROM {quiz_slots} qs\n                                                                           JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                                                           JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                                                          WHERE qs.quizid = ?", [$sourcequizid]);
                            } catch (\Throwable $e) {
                                $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$sourcequizid]);
                            }

                            // 2) Owner's flagged qids.
                            $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ?", [$targetuserid]);
                            $desired = array_values(array_intersect(array_map('intval', $srcquizqids ?: []), array_map('intval', $flagqids ?: [])));

                            // 3) Current qids in personal quiz.
                            $currqids = [];
                            try {
                                $currqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                                                       FROM {quiz_slots} qs\n                                                                       JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                                                       JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                                                      WHERE qs.quizid = ?", [(int)$pq->quizid]);
                            } catch (\Throwable $e) {
                                $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->quizid]);
                            }
                            $currqids = array_map('intval', $currqids ?: []);

                            $toadd = array_values(array_diff($desired, $currqids));
                            $toremove = array_values(array_diff($currqids, $desired));

                            // Apply removals first, then adds.
                            if (!empty($toremove)) {
                                foreach ($toremove as $qid) {
                                    $qb->remove_question((int)$pq->quizid, (int)$qid);
                                    $DB->delete_records('local_personalcourse_questions', [
                                        'personalquizid' => (int)$pq->id,
                                        'questionid' => (int)$qid,
                                    ]);
                                }
                            }

                            if (!empty($toadd)) {
                                $qb->add_questions((int)$pq->quizid, array_map('intval', $toadd));
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
                                            'source' => 'manual_flag',
                                            'originalposition' => null,
                                            'currentposition' => null,
                                            'timecreated' => $now,
                                            'timemodified' => $now,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $reconerr) {
                    // Best-effort reconciliation; defer to adhoc if any error occurs.
                }
            }
        } catch (\Throwable $t) {
            // Best-effort only; final reconciliation will occur in adhoc task.
        }
    }

    public static function on_quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        $userid = $event->relateduserid ?? null;
        if (!$userid) {
            return;
        }

        $context = $event->get_context();
        if (!($context instanceof context_module)) {
            return;
        }

        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = $cm->course;

        // Student-only gating: ignore admins/managers/teachers attempts.
        if (!self::user_has_student_role($userid, $courseid)) {
            return;
        }

        // Do not process attempts on the student's personal course; we only react to source quiz attempts.
        global $DB;
        $pcrow = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
        if ($pcrow && (int)$pcrow->courseid === (int)$courseid) {
            return; // Attempt is on personal course quiz; skip.
        }

        // Queue an adhoc task to analyze attempt, persist auto-blue flags, and apply thresholds.
        $task = new \local_personalcourse\task\attempt_generation_task();
        $task->set_custom_data([
            'userid' => $userid,
            'quizid' => (int)$cm->instance,
            'attemptid' => (int)$event->objectid,
            'cmid' => $cmid,
        ]);
        $task->set_component('local_personalcourse');
        \core\task\manager::queue_adhoc_task($task, true);
    }

    public static function on_quiz_viewed(\mod_quiz\event\course_module_viewed $event): void {
        global $DB;
        $context = $event->get_context();
        if (!($context instanceof context_module)) { return; }
        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;

        $pc = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,userid,courseid');
        if (!$pc) { return; }

        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => (int)$pc->id,
            'quizid' => (int)$cm->instance,
        ], 'id, quizid, sourcequizid');
        if (!$pq || empty($pq->sourcequizid)) {
            $useridowner = (int)$pc->userid;
            $sourcequizid = 0;
            $flagquizids = $DB->get_fieldset_sql("SELECT DISTINCT quizid FROM {local_questionflags} WHERE userid = ? AND quizid IS NOT NULL", [$useridowner]);
            if (!empty($flagquizids) && count($flagquizids) === 1) {
                $first = reset($flagquizids);
                if ($first) { $sourcequizid = (int)$first; }
            }
            if (!$sourcequizid) {
                $qname = $DB->get_field('quiz', 'name', ['id' => (int)$cm->instance]);
                if ($qname) {
                    $srcid = $DB->get_field_sql("SELECT q.id FROM {quiz} q WHERE q.name = ? AND q.course <> ? ORDER BY q.id DESC", [$qname, (int)$cm->course]);
                    if ($srcid) { $sourcequizid = (int)$srcid; }
                }
            }
            if ($sourcequizid) {
                if (!$pq) {
                    $pqid = (int)$DB->insert_record('local_personalcourse_quizzes', (object)[
                        'personalcourseid' => (int)$pc->id,
                        'quizid' => (int)$cm->instance,
                        'sourcequizid' => (int)$sourcequizid,
                        'sectionname' => '',
                        'quiztype' => 'non_essay',
                        'timecreated' => time(),
                        'timemodified' => time(),
                    ]);
                } else {
                    $pqid = (int)$pq->id;
                    $DB->update_record('local_personalcourse_quizzes', (object)[
                        'id' => $pqid,
                        'sourcequizid' => (int)$sourcequizid,
                        'timemodified' => time(),
                    ]);
                }
                $pq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$pqid], 'id, quizid, sourcequizid');
            } else {
                return;
            }
        }

        $sourcequizid = (int)$pq->sourcequizid;
        $userid = (int)$pc->userid; // Reconcile for the owner.

        $srcquizqids = [];
        try {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                                   FROM {quiz_slots} qs\n                                                   JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                                   JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                                  WHERE qs.quizid = ?", [$sourcequizid]);
        } catch (\Throwable $e) {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$sourcequizid]);
        }
        if (empty($srcquizqids)) { return; }

        $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ?", [$userid]);
        $desired = array_values(array_intersect(array_map('intval', $srcquizqids ?: []), array_map('intval', $flagqids ?: [])));

        $currqids = [];
        try {
            $currqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                               FROM {quiz_slots} qs\n                                               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                              WHERE qs.quizid = ?", [(int)$pq->quizid]);
        } catch (\Throwable $e) {
            $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->quizid]);
        }
        $currqids = array_map('intval', $currqids ?: []);

        $toadd = array_values(array_diff($desired, $currqids));
        $toremove = array_values(array_diff($currqids, $desired));

        if (empty($toadd) && empty($toremove)) { return; }

        try {
            $qb = new \local_personalcourse\quiz_builder();
            if (!empty($toremove)) {
                foreach ($toremove as $qid) {
                    $qb->remove_question((int)$pq->quizid, (int)$qid);
                    $DB->delete_records('local_personalcourse_questions', [
                        'personalquizid' => (int)$pq->id,
                        'questionid' => (int)$qid,
                    ]);
                }
            }
            if (!empty($toadd)) {
                $qb->add_questions((int)$pq->quizid, array_map('intval', $toadd));
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
                            'source' => 'manual_flag',
                            'originalposition' => null,
                            'currentposition' => null,
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Best-effort.
        }
    }

    private static function user_has_student_role(int $userid, int $courseid): bool {
        // Allow site admins to trigger tasks for testing and admin flows.
        if (is_siteadmin($userid)) { return true; }
        $coursectx = context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        foreach ($roles as $ra) {
            if (!empty($ra->shortname) && $ra->shortname === 'student') {
                return true;
            }
        }
        return false;
    }

    public static function on_personal_quiz_attempt_started(\core\event\base $event): void {
        global $DB;
        $userid = $event->relateduserid ?? null;

        $context = $event->get_context();
        if (!($context instanceof context_module)) { return; }
        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;

        // Resolve the personal course owner: if relateduserid is missing or not the owner, use the owner by courseid.
        $pc = null;
        if ($userid) {
            $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
            if (!$pc || (int)$pc->courseid !== $courseid) {
                $pc = null;
            }
        }
        if (!$pc) {
            $pc = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,courseid,userid');
            if (!$pc) { return; }
            $userid = (int)$pc->userid; // Use owner.
        }

        // Find mapping by personal quiz id to get source quiz id.
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => (int)$pc->id,
            'quizid' => (int)$cm->instance,
        ], 'id, quizid, sourcequizid');
        if (!$pq || empty($pq->sourcequizid)) { return; }

        // Build desired set = flagged only for the source quiz.
        // 1) Source quiz question ids
        $srcquizqids = [];
        try {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                    FROM {quiz_slots} qs
                                                    JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                    JOIN {question} q ON q.id = qv.questionid
                                                   WHERE qs.quizid = ?", [(int)$pq->sourcequizid]);
        } catch (\Throwable $e) { $srcquizqids = []; }
        if (empty($srcquizqids)) {
            try {
                $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->sourcequizid]);
            } catch (\Throwable $e) { $srcquizqids = []; }
        }
        if (empty($srcquizqids)) { return; }

        // 2) Flagged by user intersected with source quiz.
        list($insqlq, $inparamsq) = $DB->get_in_or_equal(array_map('intval', $srcquizqids), SQL_PARAMS_QM);
        $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ? AND questionid {$insqlq}", array_merge([$userid], $inparamsq));
        $qids = array_map('intval', $flagqids);

        // 3) Current questions in personal quiz.
        $currqids = [];
        try {
            $currqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                 FROM {quiz_slots} qs
                                                 JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                 JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                 JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                 JOIN {question} q ON q.id = qv.questionid
                                                WHERE qs.quizid = ?", [(int)$pq->quizid]);
        } catch (\Throwable $e) { $currqids = []; }
        if (empty($currqids)) {
            try {
                $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->quizid]);
            } catch (\Throwable $e) { $currqids = []; }
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
            $qb->add_questions((int)$pq->quizid, $toadd);
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
                        'source' => 'manual_flag',
                        'originalposition' => null,
                        'currentposition' => null,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }
    }
}
