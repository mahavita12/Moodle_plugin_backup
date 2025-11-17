<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

use context_module;
use context_course;

class observers {

    /**
     * Lightweight logger for deploy-time diagnostics.
     * Writes to PHP error_log with a consistent prefix so ops can grep it.
     */
    private static function log(string $message): void {
        // Intentionally quiet if error_log is unavailable.
        try { @error_log('[local_personalcourse] ' . $message); } catch (\Throwable $e) {}
    }

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
            // Public course path: allow when the actor is the same as target user OR is a site admin;
            // otherwise require a student role in the source course.
            $sameuser = true; // relateduserid is the actor at this point; we re-attribute later if needed.
            if (!$sameuser && !is_siteadmin($userid) && !self::user_has_student_role($userid, $courseid)) {
                self::log("skip_non_student_public user={$userid} course={$courseid}");
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

        // Unconditional early enqueue: always schedule a reconcile task before deeper lookups.
        // This prevents event drops during PQ fork/rebuild windows.
        try {
            $sourcequizid_early = 0;
            if ($pcowner) {
                // Personal course path: resolve source quiz id cheaply.
                $src = (int)($DB->get_field('local_personalcourse_quizzes', 'sourcequizid', [
                    'personalcourseid' => (int)$pcowner->id,
                    'quizid' => (int)$cm->instance,
                ], IGNORE_MISSING) ?: 0);
                if ($src > 0) {
                    $sourcequizid_early = $src;
                } else {
                    // Fallback by name to infer the source quiz id without relying on mapping readiness.
                    $qname = (string)$DB->get_field('quiz', 'name', ['id' => (int)$cm->instance], IGNORE_MISSING);
                    if ($qname !== '') {
                        $srcid = $DB->get_field_sql(
                            "SELECT q.id FROM {quiz} q WHERE q.name = ? AND q.course <> ? ORDER BY q.id DESC",
                            [$qname, (int)$courseid]
                        );
                        if (!empty($srcid)) {
                            $sourcequizid_early = (int)$srcid;
                        }
                    }
                }
            } else {
                // Public course path: the instance is the source quiz.
                $sourcequizid_early = (int)($quizid ?: (int)$cm->instance);
            }

            if ($sourcequizid_early > 0) {
                $classname = '\\local_personalcourse\\task\\reconcile_view_task';
                $cd1 = '"userid":' . (int)$targetuserid;
                $cd2 = '"sourcequizid":' . (int)$sourcequizid_early;
                $exists = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$classname, "%$cd1%", "%$cd2%"]);
                if (!$exists) {
                    $task = new \local_personalcourse\task\reconcile_view_task();
                    $task->set_component('local_personalcourse');
                    $task->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_early]);
                    // Small delay to avoid racing immediately after fork/switch.
                    $task->set_next_run_time(time() + 10);
                    \core\task\manager::queue_adhoc_task($task, true);
                    self::log("queued reconcile task (early " . ($added ? 'add' : 'remove') . ") user={$targetuserid} source={$sourcequizid_early}");
                } else {
                    self::log("reconcile already queued (early " . ($added ? 'add' : 'remove') . ") user={$targetuserid} source={$sourcequizid_early}");
                }

                // Also queue an early unlock to ensure in-progress attempts don't block reconcile.
                try {
                    $unlock = new \local_personalcourse\task\unlock_reconcile_task();
                    $unlock->set_component('local_personalcourse');
                    $unlock->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_early]);
                    $unlock->set_next_run_time(time());
                    \core\task\manager::queue_adhoc_task($unlock, true);
                    self::log("queued unlock task (early " . ($added ? 'add' : 'remove') . ") user={$targetuserid} source={$sourcequizid_early}");
                } catch (\Throwable $ue) { /* best-effort */ }
            }

            // Lightweight heartbeat for ops diagnostics.
            try {
                $heartbeat = json_encode([
                    'ts' => time(),
                    'event' => $added ? 'add' : 'remove',
                    'userid' => (int)$userid,
                    'targetuserid' => (int)$targetuserid,
                    'courseid' => (int)$courseid,
                    'cmid' => (int)$cmid,
                    'sourcequizid' => (int)$sourcequizid_early,
                    'origin' => $origin,
                ]);
                if ($heartbeat !== false) {
                    set_config('pcq_last_event', $heartbeat, 'local_personalcourse');
                }
            } catch (\Throwable $hb) { /* non-blocking */ }
        } catch (\Throwable $e) {
            // Best-effort early enqueue; continue with existing logic.
        }

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
                    // Repair: if event came from an archived copy (no mapping), resolve the active PQ by base name or archives.
                    if (!$pq) {
                        try {
                            $currname = (string)$DB->get_field('quiz', 'name', ['id' => (int)$cm->instance], IGNORE_MISSING);
                            if ($currname !== '') {
                                $basename = (string)preg_replace('/\\s*\\((Previous Attempt|Archived).*$/i', '', $currname);
                                // Find another PQ in this personal course whose name matches the base.
                                $pq2 = $DB->get_record_sql(
                                    "SELECT pq.id, pq.quizid, pq.sourcequizid
                                       FROM {local_personalcourse_quizzes} pq
                                       JOIN {quiz} q2 ON q2.id = pq.quizid
                                      WHERE pq.personalcourseid = ?
                                        AND pq.quizid <> ?
                                        AND (q2.name = ? OR q2.name LIKE ? OR q2.name LIKE ?)
                                   ORDER BY pq.id DESC",
                                    [(int)$pc->id, (int)$cm->instance, $basename, $basename . ' (Previous Attempt)%', $basename . ' (Archived)%']
                                );
                                if ($pq2) { $pq = $pq2; }
                            }
                        } catch (\Throwable $e) { /* best-effort */ }
                    }
                    if (!$pq) {
                        // Last resort: map via archives table from this archived quiz to its source, then to active PQ.
                        try {
                            $arch = $DB->get_record('local_personalcourse_archives', [
                                'personalcourseid' => (int)$pc->id,
                                'archivedquizid' => (int)$cm->instance,
                            ], 'sourcequizid', IGNORE_MISSING);
                            if ($arch && !empty($arch->sourcequizid)) {
                                $pq3 = $DB->get_record('local_personalcourse_quizzes', [
                                    'personalcourseid' => (int)$pc->id,
                                    'sourcequizid' => (int)$arch->sourcequizid,
                                ], 'id, quizid, sourcequizid');
                                if ($pq3) { $pq = $pq3; }
                            }
                        } catch (\Throwable $e) { /* best-effort */ }
                    }
                } else {
                    $pq = $DB->get_record('local_personalcourse_quizzes', [
                        'personalcourseid' => (int)$pc->id,
                        'sourcequizid' => (int)$quizid,
                    ], 'id, quizid, sourcequizid');
                }

                $qb = new \local_personalcourse\quiz_builder();

                // Guard: ensure we only mutate legitimate PQs. If mapping exists, verify cm idnumber marker.
                if ($pq && !empty($pq->quizid)) {
                    try {
                        $cmcur = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pc->courseid, false, MUST_EXIST);
                        $marker = (string)$DB->get_field('course_modules', 'idnumber', ['id' => (int)$cmcur->id]);
                        $srcid = !empty($pq->sourcequizid) ? (int)$pq->sourcequizid : (int)$quizid;
                        $ownerid = (int)$pc->userid;
                        $expected = 'pcq:' . $ownerid . ':' . $srcid;
                        if (trim($marker) === '') {
                            // Legacy PQ without marker: stamp it now so we don't skip valid operations.
                            try { $DB->set_field('course_modules', 'idnumber', $expected, ['id' => (int)$cmcur->id]); } catch (\Throwable $e) { /* non-blocking */ }
                        } else if (stripos($marker, $expected) !== 0) {
                            return; // Mismatched marker - likely not our PQ instance.
                        }
                    } catch (\Throwable $g) { return; }
                }

                // Defer whenever there is an active attempt on the target personal quiz
                // (regardless of origin). We will unlock + reconcile in an adhoc task.
                $shoulddefer = false;
                if (!empty($pq) && $DB->record_exists_select(
                        'quiz_attempts',
                        "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                        [(int)$pq->quizid, (int)$targetuserid]
                    )) {
                    $shoulddefer = true;
                }
                self::log(($added ? 'ADD' : 'REMOVE') . " flag evt origin={$origin} cmid={$cmid} src={$quizid} pq=" . (!empty($pq)?(int)$pq->quizid:0) . " owner={$targetuserid} defer=" . ($shoulddefer ? '1':'0'));

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
                    if ($shoulddefer) {
                        // Defer structural changes; do not mutate quiz during active attempt.
                        // Queue an immediate unlock + reconcile in the background.
                        try {
                            $sourcequizid_for_unlock = !empty($pq) && !empty($pq->sourcequizid) ? (int)$pq->sourcequizid : ((int)$quizid ?: 0);
                            if (!empty($sourcequizid_for_unlock)) {
                                $unlock = new \local_personalcourse\task\unlock_reconcile_task();
                                $unlock->set_component('local_personalcourse');
                                $unlock->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_for_unlock]);
                                $unlock->set_next_run_time(time());
                                \core\task\manager::queue_adhoc_task($unlock, true);
                                self::log("queued unlock task (add) user={$targetuserid} source={$sourcequizid_for_unlock}");

                                // Also queue a reconcile as a follow-up (deduped).
                                $rc = '\\local_personalcourse\\task\\reconcile_view_task';
                                $cd1 = '"userid":' . (int)$targetuserid;
                                $cd2 = '"sourcequizid":' . (int)$sourcequizid_for_unlock;
                                $existsrc = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$rc, "%$cd1%", "%$cd2%"]);
                                if (!$existsrc) {
                                    $task = new \local_personalcourse\task\reconcile_view_task();
                                    $task->set_component('local_personalcourse');
                                    $task->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_for_unlock]);
                                    \core\task\manager::queue_adhoc_task($task, true);
                                    self::log("queued reconcile task (add) user={$targetuserid} source={$sourcequizid_for_unlock}");
                                } else {
                                    self::log("reconcile already queued (add) user={$targetuserid} source={$sourcequizid_for_unlock}");
                                }
                            }
                        } catch (\Throwable $e) { self::log('unlock queue failed (add): ' . $e->getMessage()); }
                    } else if ($pq && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        // Ensure no in-progress/overdue attempts block immediate edits (apply for both public and personal origins).
                        $hasip = $DB->record_exists_select('quiz_attempts',
                            "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                            [(int)$pq->quizid, (int)$targetuserid]
                        );
                        if ($hasip) { /* guarded by defer above */ }
                        // Dedupe: if this question is in another PQ inside this personal course, move it here.
                        $existing = $DB->get_record('local_personalcourse_questions', [
                            'personalcourseid' => (int)$pc->id,
                            'questionid' => (int)$questionid,
                        ]);
                        if ($existing && (int)$existing->personalquizid !== (int)$pq->id) {
                            $oldpq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$existing->personalquizid], 'id, quizid');
                            if ($oldpq) {
                                $oldhasfinished = $DB->record_exists_select('quiz_attempts', "quiz = ? AND state = 'finished'", [(int)$oldpq->quizid]);
                                if (!$oldhasfinished) {
                                    self::delete_inprogress_attempts_for_user_at_quiz((int)$oldpq->quizid, (int)$targetuserid);
                                    $qb->remove_question((int)$oldpq->quizid, (int)$questionid);
                                    $DB->delete_records('local_personalcourse_questions', ['id' => (int)$existing->id]);
                                }
                                // If finished attempts exist on the old PQ, skip removal and keep its mapping to preserve reviews.
                            }
                        }
                        // Ensure mapping row exists first (mapping drives desired state).
                        $existingmap = $DB->get_record('local_personalcourse_questions', [
                            'personalcourseid' => (int)$pc->id,
                            'personalquizid' => (int)$pq->id,
                            'questionid' => (int)$questionid,
                        ]);
                        if ($existingmap) {
                            $existingmap->timemodified = time();
                            if (empty($existingmap->flagcolor)) { $existingmap->flagcolor = ($flagcolor ?: 'blue'); }
                            $DB->update_record('local_personalcourse_questions', $existingmap);
                        } else {
                            $DB->insert_record('local_personalcourse_questions', (object)[
                                'personalcourseid' => (int)$pc->id,
                                'personalquizid' => (int)$pq->id,
                                'questionid' => (int)$questionid,
                                'slotid' => null,
                                'flagcolor' => $flagcolor ?: 'blue',
                                'source' => ($origin === 'auto') ? 'auto' : 'manual_flag',
                                'originalposition' => null,
                                'currentposition' => null,
                                'timecreated' => time(),
                                'timemodified' => time(),
                            ]);
                        }
                        // Add to current PQ if not present in ACTUAL quiz slots (mapping is auxiliary).
                        $presentinslots = self::question_present_in_quiz_slots((int)$pq->quizid, (int)$questionid);
                        if (!$presentinslots) {
                            $qb->add_questions((int)$pq->quizid, [(int)$questionid]);
                            // Enforce visibility immediately, sort quizzes in section by name, and queue a modinfo rebuild.
                            try {
                                if (!empty($pq->sourcequizid)) {
                                    \local_personalcourse\generator_service::enforce_archive_visibility((int)$pc->courseid, (int)$pq->sourcequizid, (int)$pq->quizid);
                                }
                                try {
                                    $cmcur = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pc->courseid, false, MUST_EXIST);
                                    $sm = new \local_personalcourse\section_manager();
                                    $sm->sort_quizzes_in_section_by_name((int)$pc->courseid, (int)$cmcur->section);
                                } catch (\Throwable $se) {}
                                \local_personalcourse\modinfo_rebuilder::queue((int)$pc->courseid, 'flag_add');
                            } catch (\Throwable $e) { }
                        } else {
                            // Already present in slots – ensure a mapping row exists/up-to-date.
                            if (!$DB->record_exists('local_personalcourse_questions', [
                                'personalcourseid' => (int)$pc->id,
                                'personalquizid' => (int)$pq->id,
                                'questionid' => (int)$questionid,
                            ])) {
                                $DB->insert_record('local_personalcourse_questions', (object)[
                                    'personalcourseid' => (int)$pc->id,
                                    'personalquizid' => (int)$pq->id,
                                    'questionid' => (int)$questionid,
                                    'slotid' => null,
                                    'flagcolor' => $flagcolor ?: 'blue',
                                    'source' => ($origin === 'auto') ? 'auto' : 'manual_flag',
                                    'originalposition' => null,
                                    'currentposition' => null,
                                    'timecreated' => time(),
                                    'timemodified' => time(),
                                ]);
                            }
                        }
                        // Quick reconcile to backfill any other mapped questions that are missing in slots.
                        try { self::quick_reconcile_slots_for_pq((int)$pc->id, (int)$pq->id, (int)$pq->quizid, (int)$targetuserid); } catch (\Throwable $e) {}
                    }
                } else {
                    // Removal: remove this question from any personal quiz within the student's personal course.
                    $existing = $DB->get_record('local_personalcourse_questions', [
                        'personalcourseid' => (int)$pc->id,
                        'questionid' => (int)$questionid,
                    ]);
                    // If we re-attributed to the owner, also remove the owner's flag rows.
                    if ($targetuserid !== $userid) {
                        $DB->delete_records('local_questionflags', [
                            'userid' => (int)$targetuserid,
                            'questionid' => (int)$questionid,
                        ]);
                    }
                    if ($shoulddefer) {
                        // Defer structural changes; do not remove from quiz during active attempt.
                        // Queue an immediate unlock + reconcile to apply the removal safely.
                        try {
                            $sourcequizid_for_unlock = !empty($pq) && !empty($pq->sourcequizid) ? (int)$pq->sourcequizid : ((int)$quizid ?: 0);
                            if (!empty($sourcequizid_for_unlock)) {
                                $unlock = new \local_personalcourse\task\unlock_reconcile_task();
                                $unlock->set_component('local_personalcourse');
                                $unlock->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_for_unlock]);
                                $unlock->set_next_run_time(time());
                                \core\task\manager::queue_adhoc_task($unlock, true);
                                self::log("queued unlock task (remove) user={$targetuserid} source={$sourcequizid_for_unlock}");

                                // Queue reconcile as well (deduped).
                                $rc = '\\local_personalcourse\\task\\reconcile_view_task';
                                $cd1 = '"userid":' . (int)$targetuserid;
                                $cd2 = '"sourcequizid":' . (int)$sourcequizid_for_unlock;
                                $existsrc = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$rc, "%$cd1%", "%$cd2%"]);
                                if (!$existsrc) {
                                    $task = new \local_personalcourse\task\reconcile_view_task();
                                    $task->set_component('local_personalcourse');
                                    $task->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid_for_unlock]);
                                    \core\task\manager::queue_adhoc_task($task, true);
                                    self::log("queued reconcile task (remove) user={$targetuserid} source={$sourcequizid_for_unlock}");
                                } else {
                                    self::log("reconcile already queued (remove) user={$targetuserid} source={$sourcequizid_for_unlock}");
                                }
                            }
                        } catch (\Throwable $e) { self::log('unlock queue failed (remove): ' . $e->getMessage()); }
                    } else if ($existing) {
                        $targetpq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$existing->personalquizid], 'id, quizid');
                        if ($targetpq) {
                            $hasfinished = $DB->record_exists_select('quiz_attempts', "quiz = ? AND state = 'finished'", [(int)$targetpq->quizid]);
                            if (!$hasfinished) {
                                self::delete_inprogress_attempts_for_user_at_quiz((int)$targetpq->quizid, (int)$targetuserid);
                                $qb->remove_question((int)$targetpq->quizid, (int)$questionid);
                                $DB->delete_records('local_personalcourse_questions', ['id' => (int)$existing->id]);
                            }
                        }
                    } else if (!empty($pq) && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        $hasfinished = $DB->record_exists_select('quiz_attempts', "quiz = ? AND state = 'finished'", [(int)$pq->quizid]);
                        if (!$hasfinished) {
                            self::delete_inprogress_attempts_for_user_at_quiz((int)$pq->quizid, (int)$targetuserid);
                            $qb->remove_question((int)$pq->quizid, (int)$questionid);
                            $DB->delete_records('local_personalcourse_questions', [
                                'personalcourseid' => (int)$pc->id,
                                'personalquizid' => (int)$pq->id,
                                'questionid' => (int)$questionid,
                            ]);
                        }
                    }
                    // Quick reconcile to remove any stray slots left without mapping (safety).
                    try { self::quick_reconcile_slots_for_pq((int)$pc->id, (int)$pq->id, (int)$pq->quizid, (int)$targetuserid); } catch (\Throwable $e) {}
                    // Enforce visibility after removals, sort, and queue rebuild.
                    try {
                        if (!empty($pq) && !empty($pq->sourcequizid)) {
                            \local_personalcourse\generator_service::enforce_archive_visibility((int)$pc->courseid, (int)$pq->sourcequizid, (int)$pq->quizid);
                        }
                        try {
                            if (!empty($pq)) {
                                $cmcur = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pc->courseid, false, MUST_EXIST);
                                $sm = new \local_personalcourse\section_manager();
                                $sm->sort_quizzes_in_section_by_name((int)$pc->courseid, (int)$cmcur->section);
                            }
                        } catch (\Throwable $se) {}
                        \local_personalcourse\modinfo_rebuilder::queue((int)$pc->courseid, 'flag_remove');
                    } catch (\Throwable $e) { }
                }

                // Always reconcile: compute desired state immediately. Defer structural edits during active PQ attempts.
                try {
                    if (!empty($pq) && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                        $sourcequizid = !empty($pq->sourcequizid) ? (int)$pq->sourcequizid : ((int)$quizid ?: 0);
                        if (!empty($sourcequizid)) {
                            $deferflag = (bool)$shoulddefer;
                            if ($ispcourse && !$deferflag) {
                                // Safe to apply immediately: no active attempt; clear in-progress then reconcile.
                                self::delete_inprogress_attempts_for_user_at_quiz((int)$pq->quizid, (int)$targetuserid);
                            }
                            $svc = new \local_personalcourse\generator_service();
                            $svc->generate_from_source((int)$targetuserid, (int)$sourcequizid, null, 'flags_only', (bool)$deferflag);
                            // When deferring (e.g., in-progress PQ), enqueue an unlock+reconcile task and a sequence cleanup.
                            if ($deferflag) {
                                try {
                                    // Queue unlock + reconcile immediately (no dedupe; small and idempotent).
                                    $unlock = new \local_personalcourse\task\unlock_reconcile_task();
                                    $unlock->set_component('local_personalcourse');
                                    $unlock->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid]);
                                    $unlock->set_next_run_time(time());
                                    \core\task\manager::queue_adhoc_task($unlock, true);
                                    self::log("queued unlock task (deferflag) user={$targetuserid} source={$sourcequizid}");
                                    $classname = '\\local_personalcourse\\task\\reconcile_view_task';
                                    $cd1 = '"userid":' . (int)$targetuserid;
                                    $cd2 = '"sourcequizid":' . (int)$sourcequizid;
                                    $exists = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$classname, "%$cd1%", "%$cd2%"]);
                                    if (!$exists) {
                                        $task = new \local_personalcourse\task\reconcile_view_task();
                                        $task->set_component('local_personalcourse');
                                        $task->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid]);
                                        \core\task\manager::queue_adhoc_task($task, true);
                                        self::log("queued reconcile task (deferflag) user={$targetuserid} source={$sourcequizid}");
                                    }
                                    // Also queue a sequence cleanup for the personal course to heal any stale CMIDs.
                                    $classname2 = '\\local_personalcourse\\task\\sequence_cleanup_task';
                                    $cd = '"courseid":' . (int)$pc->courseid;
                                    $exists2 = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ?', [$classname2, "%$cd%"]);
                                    if (!$exists2) {
                                        $cleanup = new \local_personalcourse\task\sequence_cleanup_task();
                                        $cleanup->set_component('local_personalcourse');
                                        $cleanup->set_custom_data(['courseid' => (int)$pc->courseid]);
                                        \core\task\manager::queue_adhoc_task($cleanup, true);
                                    }
                                } catch (\Throwable $q) { self::log('queue deferflag failed: ' . $q->getMessage()); }
                            }
                        }
                    }
                } catch (\Throwable $reconerr) {
                    // Best-effort reconciliation; defer to adhoc if any error occurs.
                    self::log('reconcile block error: ' . $reconerr->getMessage());
                }
            }
        } catch (\Throwable $t) {
            // Best-effort only; final reconciliation will occur in adhoc task.
            self::log('handle_flag_change error: ' . $t->getMessage());
        }
    }

    public static function on_quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB, $CFG, $PAGE;
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

        // Handle both cases: if attempt is in personal course, map to source quiz and delegate to generator_service;
        // otherwise proceed with the existing task path (which delegates to generator_service).
        global $DB;
        $pcrow = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
        if ($pcrow && (int)$pcrow->courseid === (int)$courseid) {
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pcrow->id,
                'quizid' => (int)$cm->instance,
            ], 'id, sourcequizid');
            if ($pq && !empty($pq->sourcequizid)) {
                try {
                    $svc = new \local_personalcourse\generator_service();
                    // Flags-only reconciliation post-creation; drive solely by current global flag state.
                    $svc->generate_from_source((int)$userid, (int)$pq->sourcequizid, (int)$event->objectid, 'flags_only');
                } catch (\Throwable $e) { /* best-effort */ }
            }
            return;
        }

        // Compute grade/attempt for notification purposes.
        $attempt = $DB->get_record('quiz_attempts', ['id' => (int)$event->objectid], 'id,attempt,sumgrades,quiz,userid', IGNORE_MISSING);
        $quiz = $DB->get_record('quiz', ['id' => (int)$cm->instance], 'id,sumgrades', IGNORE_MISSING);
        $sumgrades = $attempt ? (float)($attempt->sumgrades ?? 0.0) : 0.0;
        $totalsum = $quiz ? (float)($quiz->sumgrades ?? 0.0) : 0.0;
        $grade = ($totalsum > 0.0) ? (($sumgrades / $totalsum) * 100.0) : 0.0;
        $n = $attempt ? (int)$attempt->attempt : 0;

        // Determine if a personal quiz already exists for this user and source quiz.
        $hasquiz = false;
        $pcinfo = $DB->get_record('local_personalcourse_courses', ['userid' => (int)$userid], 'id,courseid');
        if ($pcinfo) {
            $moduleidquiz_chk = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            $existingquiz = $DB->get_record_sql(
                'SELECT q.id, cm.deletioninprogress
                   FROM {quiz} q
                   JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                  WHERE q.course = ? AND q.name = (
                        SELECT name FROM {quiz} WHERE id = ?
                  )
               ORDER BY q.id DESC',
                [$moduleidquiz_chk, (int)$pcinfo->courseid, (int)$cm->instance]
            );
            $hasquiz = ($existingquiz && empty($existingquiz->deletioninprogress));
        }

        // Show notification according to state.
        if ($hasquiz) {
            \core\notification::info(get_string('notify_pq_exists_short', 'local_personalcourse'));
        } else {
            if ($n === 1 && !($grade > 80.0)) {
                \core\notification::warning(get_string('notify_pq_not_created_first_short', 'local_personalcourse'));
            } else if ($n >= 2 && $grade < 40.0) {
                \core\notification::warning(get_string('notify_pq_not_created_next_short', 'local_personalcourse'));
            }
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

        // Fast path: immediately append incorrect questions to the student's existing Personal Quiz (append-only).
        // Heavy work (creation/fork/visibility) remains in async task.
        try {
            if ($pcinfo) {
                $pqmap = $DB->get_record('local_personalcourse_quizzes', [
                    'personalcourseid' => (int)$pcinfo->id,
                    'sourcequizid' => (int)$cm->instance,
                ], 'id, quizid');
                if ($pqmap && !empty($pqmap->quizid)) {
                    // Skip if a personal-quiz attempt is in progress/overdue.
                    $hasinprogress = $DB->record_exists_select('quiz_attempts',
                        "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                        [(int)$pqmap->quizid, (int)$userid]
                    );
                    if (!$hasinprogress) {
                        $an = new \local_personalcourse\attempt_analyzer();
                        $incorrectqids = $an->get_incorrect_questionids_from_attempt((int)$event->objectid);
                        if (!empty($incorrectqids)) {
                            // Current qids already present in the personal quiz (Moodle 4.4 schema via references).
                            $currqids = [];
                            try {
                                $currqids = $DB->get_fieldset_sql(
                                    "SELECT qv.questionid
                                       FROM {quiz_slots} qs
                                       JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                       JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                                      WHERE qs.quizid = ?
                                   ORDER BY qs.slot",
                                    [(int)$pqmap->quizid]
                                );
                            } catch (\Throwable $e) { $currqids = []; }
                            $currqids = array_map('intval', $currqids ?: []);
                            // Also avoid duplicates present in any PQ for this personal course.
                            $presentany = $DB->get_fieldset_select('local_personalcourse_questions', 'questionid',
                                'personalcourseid = ?', [(int)$pcinfo->id]) ?: [];
                            $presentany = array_map('intval', $presentany);
                            $toadd = array_values(array_diff(array_map('intval', $incorrectqids), $currqids, $presentany));
                            if (!empty($toadd)) {
                                $qb = new \local_personalcourse\quiz_builder();
                                $qb->add_questions((int)$pqmap->quizid, $toadd);
                                $now = time();
                                foreach ($toadd as $qid) {
                                    if (!$DB->record_exists('local_personalcourse_questions', [
                                        'personalcourseid' => (int)$pcinfo->id,
                                        'questionid' => (int)$qid,
                                    ])) {
                                        $DB->insert_record('local_personalcourse_questions', (object)[
                                            'personalcourseid' => (int)$pcinfo->id,
                                            'personalquizid' => (int)$pqmap->id,
                                            'questionid' => (int)$qid,
                                            'slotid' => null,
                                            'flagcolor' => 'blue',
                                            'source' => 'auto_incorrect',
                                            'originalposition' => null,
                                            'currentposition' => null,
                                            'timecreated' => $now,
                                            'timemodified' => $now,
                                        ]);
                                    }
                                }
                                try {
                                    \local_personalcourse\modinfo_rebuilder::queue((int)$pcinfo->courseid, 'immediate_inject');
                                    // Sort quizzes within the same section by name for consistent ordering.
                                    try {
                                        $cmcur = get_coursemodule_from_instance('quiz', (int)$pqmap->quizid, (int)$pcinfo->courseid, false, MUST_EXIST);
                                        $sm = new \local_personalcourse\section_manager();
                                        $sm->sort_quizzes_in_section_by_name((int)$pcinfo->courseid, (int)$cmcur->section);
                                    } catch (\Throwable $se) { }
                                } catch (\Throwable $e) { }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* best-effort immediate append; fall back to adhoc */ }

        return;
    }

    /**
     * Check if a specific question is actually present in a quiz's slots (4.4+ schema).
     */
    private static function question_present_in_quiz_slots(int $quizid, int $questionid): bool {
        global $DB;
        if ($quizid <= 0 || $questionid <= 0) { return false; }
        $sql = "SELECT 1
                  FROM {quiz_slots} qs
                  JOIN {question_references} qr
                    ON qr.itemid = qs.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                  JOIN {question_versions} qv
                    ON qv.questionbankentryid = qr.questionbankentryid
                 WHERE qs.quizid = ? AND qv.questionid = ?";
        return $DB->record_exists_sql($sql, [$quizid, $questionid]);
    }

    /**
     * Backfill any mapped questions missing from slots for a given personal quiz.
     * Adds missing questions after unlocking, and queues a rebuild.
     */
    private static function quick_reconcile_slots_for_pq(int $personalcourseid, int $pqid, int $quizid, int $ownerid): void {
        global $DB;
        if ($personalcourseid <= 0 || $pqid <= 0 || $quizid <= 0 || $ownerid <= 0) { return; }
        $rows = $DB->get_records('local_personalcourse_questions', [
            'personalcourseid' => $personalcourseid,
            'personalquizid' => $pqid,
        ], '', 'id,questionid');
        if (empty($rows)) { return; }
        $missing = [];
        foreach ($rows as $r) {
            if (!self::question_present_in_quiz_slots($quizid, (int)$r->questionid)) {
                $missing[] = (int)$r->questionid;
            }
        }
        if (empty($missing)) { return; }
        // Unlock and add all missing in one pass.
        self::delete_inprogress_attempts_for_user_at_quiz((int)$quizid, (int)$ownerid);
        try {
            $qb = new \local_personalcourse\quiz_builder();
            $qb->add_questions((int)$quizid, $missing);
        } catch (\Throwable $e) { /* best-effort */ }
        try {
            \local_personalcourse\modinfo_rebuilder::queue((int)$DB->get_field('quiz', 'course', ['id' => (int)$quizid], IGNORE_MISSING), 'quick_reconcile');
        } catch (\Throwable $e) { }
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
        // Defer structural reconcile on review: enqueue adhoc task (deduped), do not mutate in this request.
        try {
            $ownerid = (int)$pc->userid;
            if (!empty($pq) && !empty($pq->sourcequizid)) {
                $deferview = (int)get_config('local_personalcourse', 'defer_view_enforcement');
                if (!$deferview) {
                    // Enforce course visibility inline only when not deferring.
                    try {
                        \local_personalcourse\generator_service::enforce_archive_visibility((int)$courseid, (int)$pq->sourcequizid, (int)$cm->instance);
                    } catch (\Throwable $evis) { }
                }
                // Detect inconsistent section sequences only when not deferring.
                $inconsistent = false;
                $badexists = false;
                if (!$deferview) {
                    $sections = $DB->get_records('course_sections', ['course' => (int)$courseid], 'section', 'id,sequence');
                    if (!empty($sections)) {
                        // Build valid CM id set: existing and not deletioninprogress.
                        $validcmids = [];
                        $rs = $DB->get_recordset_select('course_modules', 'course = ? AND (deletioninprogress = 0 OR deletioninprogress IS NULL)', [(int)$courseid], '', 'id');
                        foreach ($rs as $r) { $validcmids[(int)$r->id] = true; }
                        $rs->close();
                        foreach ($sections as $sec) {
                            $seq = trim((string)$sec->sequence);
                            if ($seq === '') { continue; }
                            $ids = array_filter(array_map('intval', explode(',', $seq)));
                            foreach ($ids as $id) { if (!isset($validcmids[(int)$id])) { $inconsistent = true; $badexists = true; break 2; } }
                        }
                    }
                }

                $classname = '\\local_personalcourse\\task\\reconcile_view_task';
                $cd1 = '"userid":' . (int)$ownerid;
                $cd2 = '"sourcequizid":' . (int)$pq->sourcequizid;
                $exists = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$classname, "%$cd1%", "%$cd2%"]);
                if (!$exists) {
                    $task = new \local_personalcourse\task\reconcile_view_task();
                    $task->set_custom_data([
                        'userid' => $ownerid,
                        'sourcequizid' => (int)$pq->sourcequizid,
                        'cmid' => (int)$cmid,
                    ]);
                    $task->set_component('local_personalcourse');
                    \core\task\manager::queue_adhoc_task($task, true);
                    \core\notification::info(get_string('task_reconcile_scheduled', 'local_personalcourse'));
                }

                // If inconsistent or we are deferring, queue a sequence cleanup task for the course (dedup by courseid).
                if ($inconsistent || $deferview) {
                    $classname2 = '\\local_personalcourse\\task\\sequence_cleanup_task';
                    $cd = '"courseid":' . (int)$courseid;
                    $exists2 = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ?', [$classname2, "%$cd%"]);
                    if (!$exists2) {
                        $cleanup = new \local_personalcourse\task\sequence_cleanup_task();
                        $cleanup->set_component('local_personalcourse');
                        $cleanup->set_custom_data(['courseid' => (int)$courseid]);
                        \core\task\manager::queue_adhoc_task($cleanup, true);
                    }
                    // Early return to avoid any risk during rendering.
                    return;
                }
            }
        } catch (\Throwable $e) { /* best-effort */ }
        return;
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

        // No structural changes on attempt start; defer to attempt_submitted and review-page flag change handling.
        return;
    }

    private static function user_has_student_role(int $userid, int $courseid): bool {
        // Allow site admins to trigger tasks for testing and admin flows.
        if (is_siteadmin($userid)) { return true; }
        $coursectx = \context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        foreach ($roles as $ra) {
            if (!empty($ra->shortname) && $ra->shortname === 'student') {
                return true;
            }
        }
        return false;
    }

    private static function delete_inprogress_attempts_for_user_at_quiz(int $quizid, int $userid): void {
        global $DB, $CFG;
        if ($quizid <= 0 || $userid <= 0) { return; }
        $attempts = $DB->get_records_select('quiz_attempts', "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')", [(int)$quizid, (int)$userid], 'id ASC');
        if (empty($attempts)) { return; }
        $quiz = $DB->get_record('quiz', ['id' => (int)$quizid], '*', IGNORE_MISSING);
        if (!$quiz) { return; }
        try { require_once($CFG->dirroot . '/mod/quiz/locallib.php'); } catch (\Throwable $e) { return; }
        try {
            $cm = get_coursemodule_from_instance('quiz', (int)$quizid, (int)$quiz->course, false, MUST_EXIST);
            if ($cm && !isset($quiz->cmid)) { $quiz->cmid = (int)$cm->id; }
        } catch (\Throwable $e) { }
        foreach ($attempts as $attempt) {
            try { quiz_delete_attempt($attempt, $quiz); } catch (\Throwable $e) { }
        }
    }
}
