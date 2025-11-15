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

                // Defer ONLY when the flag change originates from the attempt page of the personal quiz
                // and there is an in-progress/overdue attempt. For all other origins (including review and
                // public course), apply immediately and delete the in-progress attempt to regenerate.
                $shoulddefer = false;
                if ($ispcourse && !empty($pq) && $origin === 'attempt' && $DB->record_exists_select(
                        'quiz_attempts',
                        "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')",
                        [(int)$pq->quizid, (int)$targetuserid]
                    )) {
                    $shoulddefer = true;
                }

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
                    } else if (!$ispcourse && $pq && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
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
                            // When deferring (e.g., review-origin or in-progress PQ), enqueue an adhoc reconcile to run out-of-request and a sequence cleanup.
                            if ($deferflag) {
                                try {
                                    $classname = '\\local_personalcourse\\task\\reconcile_view_task';
                                    $cd1 = '"userid":' . (int)$targetuserid;
                                    $cd2 = '"sourcequizid":' . (int)$sourcequizid;
                                    $exists = $DB->record_exists_select('task_adhoc', 'classname = ? AND customdata LIKE ? AND customdata LIKE ?', [$classname, "%$cd1%", "%$cd2%"]);
                                    if (!$exists) {
                                        $task = new \local_personalcourse\task\reconcile_view_task();
                                        $task->set_component('local_personalcourse');
                                        $task->set_custom_data(['userid' => (int)$targetuserid, 'sourcequizid' => (int)$sourcequizid]);
                                        \core\task\manager::queue_adhoc_task($task, true);
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
                                } catch (\Throwable $q) { /* best-effort */ }
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
        // Execute synchronously as well so that first-time creation or existing mapping reconciliation happens immediately.
        $task2 = new \local_personalcourse\task\attempt_generation_task();
        $task2->set_custom_data([
            'userid' => (int)$userid,
            'quizid' => (int)$cm->instance,
            'attemptid' => (int)$event->objectid,
            'cmid' => (int)$cmid,
        ]);
        $task2->set_component('local_personalcourse');
        try {
            // Execute synchronously as well; if creation happens now, show success notification.
            $before = $hasquiz;
            $task2->execute();
            // Re-check creation state after execution.
            $hasquiz_after = false;
            if ($pcinfo) {
                $moduleidquiz_chk = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
                $existingquiz2 = $DB->get_record_sql(
                    'SELECT q.id, cm.deletioninprogress
                       FROM {quiz} q
                       JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                      WHERE q.course = ? AND q.name = (
                            SELECT name FROM {quiz} WHERE id = ?
                      )
                   ORDER BY q.id DESC',
                    [$moduleidquiz_chk, (int)$pcinfo->courseid, (int)$cm->instance]
                );
                $hasquiz_after = ($existingquiz2 && empty($existingquiz2->deletioninprogress));
            }
            if (!$before && $hasquiz_after) {
                \core\notification::success(get_string('notify_pq_created_short', 'local_personalcourse'));
            }
        } catch (\Throwable $e) {}
        return;
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
