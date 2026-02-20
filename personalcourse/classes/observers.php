<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

use context_module;
use context_course;

/**
 * Simplified Event Observers for Personal Course Plugin
 * 
 * Architecture:
 * 1. All operations are SYNCHRONOUS (no async tasks)
 * 2. on_quiz_attempt_submitted: Check threshold, auto-flag incorrect, generate PQ
 * 3. on_flag_added/removed: Sync PQ slots immediately
 * 4. Force-delete in-progress attempts handled by generator_service
 */
class observers {

    /**
     * Called when a flag is added
     */
    public static function on_flag_added(\local_questionflags\event\flag_added $event): void {
        self::handle_flag_change($event, true);
    }

    /**
     * Called when a flag is removed
     */
    public static function on_flag_removed(\local_questionflags\event\flag_removed $event): void {
        self::handle_flag_change($event, false);
    }

    /**
     * Handle flag add/remove events - sync personal quiz immediately
     */
    private static function handle_flag_change(\core\event\base $event, bool $added): void {
        global $DB;

        // Recursion Guard: Prevent infinite loops if sync triggers other events
        static $processing = false;
        if ($processing) {
            error_log("[local_personalcourse] FLAG_CHANGE: Recursive call detected. Skipping.");
            return;
        }
        $processing = true;

        try {
            error_log("[local_personalcourse] FLAG_CHANGE: added=" . ($added ? 'true' : 'false'));

            $userid = $event->relateduserid ?? null;
        if (!$userid) {
            error_log("[local_personalcourse] FLAG_CHANGE: No userid, returning");
            return;
        }

        $context = $event->get_context();
        if (!($context instanceof context_module)) {
            return;
        }

        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $courseid = (int)$cm->course;
        $quizid = (int)$cm->instance;

        // === SELF-SYNC LOGIC: Check if this is a PERSONAL QUIZ ===
        $pq_mapping = $DB->get_record('local_personalcourse_quizzes', ['quizid' => $quizid]);
        if ($pq_mapping) {
            error_log("[local_personalcourse] FLAG_CHANGE: Event triggered from PERSONAL quiz $quizid (Source: {$pq_mapping->sourcequizid})");

            // SAFETY CHECK: "Self-Destruction Protection" vs "Global Sync"
            // Rule:
            // 1. If user is IN the attempt (referer matches attempt.php?attempt=X) -> PROTECT (Abort Sync).
            // 2. If user is REVIEWING (referer matches review.php or other) -> SYNC (Delete active attempt).
            
            $unfinished = $DB->get_record_select('quiz_attempts', 
                "quiz = ? AND userid = ? AND state != 'finished'", 
                [$quizid, $userid],
                '*',
                IGNORE_MULTIPLE
            );

            if ($unfinished) {
                $referer = $_SERVER['HTTP_REFERER'] ?? '';
                // Check if the request came from the active attempt page
                $is_self_destruction = false;
                if (strpos($referer, '/mod/quiz/attempt.php') !== false) {
                    $query = parse_url($referer, PHP_URL_QUERY);
                    parse_str($query, $params);
                    if (isset($params['attempt']) && (int)$params['attempt'] === (int)$unfinished->id) {
                        $is_self_destruction = true;
                    }
                }

                if ($is_self_destruction) {
                    error_log("[local_personalcourse] FLAG_CHANGE: ABORTING SYNC - User is inside active attempt {$unfinished->id} (Self-Destruction Protection). Referer: $referer");
                    return;
                } else {
                    error_log("[local_personalcourse] FLAG_CHANGE: PROCEEDING WITH SYNC - User has unfinished attempt {$unfinished->id} but is NOT inside it (Global Sync). Referer: $referer");
                }
            }

            // PROCEED: Sync from Source
            error_log("[local_personalcourse] FLAG_CHANGE: Self-Sync triggered. Syncing from Source {$pq_mapping->sourcequizid}");
            // We use the SOURCE quiz ID to regenerate/sync the personal quiz
            \local_personalcourse\generator_service::generate_from_source($userid, $pq_mapping->sourcequizid);
            return;
        }

        // Determine if this is a personal course or public course
        $pcowner = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,userid,courseid');
        
        if ($pcowner) {
            // Flag changed in a PERSONAL course - DO NOT sync
            // Flag changes in personal quiz should NOT trigger question removal
            error_log("[local_personalcourse] FLAG_CHANGE: Ignoring - flag change from personal quiz (courseid=$courseid)");
            return;
        } else {
            // Flag changed in a PUBLIC course - this quiz IS the source
            $sourcequizid = $quizid;
            $targetuserid = (int)$userid;
            
            // Verify user is a student (or admin for testing)
            if (!is_siteadmin($targetuserid) && !self::user_has_student_role($targetuserid, $courseid)) {
                return;
            }
        }

        // Skip if source quiz has essay questions
        if (self::quiz_has_essay($sourcequizid)) {
            return;
        }

        // Check if personal quiz mapping exists
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $targetuserid], 'id');
        if (!$pc) {
            // No personal course yet - will be created on next attempt submission
            return;
        }

        $pqexists = $DB->record_exists('local_personalcourse_quizzes', [
            'personalcourseid' => (int)$pc->id,
            'sourcequizid' => $sourcequizid,
        ]);

        if (!$pqexists) {
            // Personal quiz doesn't exist yet - will be created on next attempt submission
            return;
        }

        error_log("[local_personalcourse] FLAG_CHANGE: Syncing user=$targetuserid source=$sourcequizid");

        // === SYNCHRONOUS SYNC ===
        // Call generator_service directly to sync the personal quiz
        // Note: Redirect is handled via session variable in lib.php's before_footer hook
        try {
            $result = \local_personalcourse\generator_service::generate_from_source($targetuserid, $sourcequizid);
            error_log("[local_personalcourse] FLAG_CHANGE: Result - toadd=" . count($result->toadd ?? []) . " toremove=" . count($result->toremove ?? []));
        } catch (\Throwable $e) {
            error_log("[local_personalcourse] Flag sync failed: " . $e->getMessage());
        }
    } catch (\Throwable $e) {
        error_log("[local_personalcourse] FATAL error in handle_flag_change: " . $e->getMessage());
    } finally {
        $processing = false;
    }
    }

    /**
     * Called when a quiz attempt is submitted
     */
    public static function on_quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        global $DB;

        error_log("[local_personalcourse] ATTEMPT_SUBMITTED event fired");

        $userid = $event->relateduserid ?? null;
        if (!$userid) {
            error_log("[local_personalcourse] ATTEMPT_SUBMITTED: No userid, returning");
            return;
        }

        $context = $event->get_context();
        if (!($context instanceof context_module)) {
            return;
        }

        $cmid = $context->instanceid;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $courseid = (int)$cm->course;
        $quizid = (int)$cm->instance;
        $attemptid = (int)$event->objectid;

        // Check if this is a personal course
        $pcowner = $DB->get_record('local_personalcourse_courses', ['courseid' => $courseid], 'id,userid');
        if ($pcowner) {
            // This is a PERSONAL quiz attempt submission
            // Sync the personal quiz based on current flags (deferred from flag changes during attempt)
            error_log("[local_personalcourse] ATTEMPT_SUBMITTED: Personal quiz attempt - syncing deferred flag changes");
            
            // Find the source quiz for this personal quiz
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pcowner->id,
                'quizid' => $quizid,
            ], 'id, sourcequizid');
            
            if ($pq && !empty($pq->sourcequizid)) {
                try {
                    $result = \local_personalcourse\generator_service::generate_from_source((int)$pcowner->userid, (int)$pq->sourcequizid);
                    error_log("[local_personalcourse] ATTEMPT_SUBMITTED: Personal quiz sync - toadd=" . count($result->toadd ?? []) . " toremove=" . count($result->toremove ?? []));
                } catch (\Throwable $e) {
                    error_log("[local_personalcourse] Personal quiz sync failed: " . $e->getMessage());
                }
            }
            return;
        }

        // Skip if quiz has essay questions
        if (self::quiz_has_essay($quizid)) {
            return;
        }

        // Verify user is a student (or admin for testing)
        if (!is_siteadmin($userid) && !self::user_has_student_role($userid, $courseid)) {
            return;
        }

        // === THRESHOLD CHECK (30%) ===
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        
        $percentage = 0.0;
        if ($quiz && $quiz->sumgrades > 0 && $attempt) {
            $percentage = ($attempt->sumgrades / $quiz->sumgrades) * 100;
        }

        // === ATTEMPT 1 CHECK ===
        // If this is the FIRST attempt, we do NOTHING.
        // No generation, no failure notification.
        if ($attempt->attempt == 1) {
            error_log("[local_personalcourse] ATTEMPT_SUBMITTED: Attempt 1 - suppressing generation and notifications (Policy).");
            return;
        }

        if ($percentage < \local_personalcourse\threshold_policy::THRESHOLD_PERCENTAGE) {
            // FAILED THRESHOLD (Attempt 2+)
            $msg = "You scored " . format_float($percentage, 0) . "%. You should score " . \local_personalcourse\threshold_policy::THRESHOLD_PERCENTAGE . "% or more to have the personal quiz.";
            // Use custom styling (White on Blue)
            \core\notification::add(
                '<span class="local-personalcourse-notification">' . 
                get_string('personalquiz_failed_prominent', 'local_personalcourse', $msg) . 
                '</span>',
                \core\notification::INFO
            );
            return;
        }

        // === ONE-TIME GENERATION CHECK ===
        // If personal quiz already exists, do NOT re-generate (updates are flag-driven only)
        // BUT: Check if the quiz *actually* exists (it might have been deleted)
        $existing_pq = $DB->get_record_sql(
            "SELECT pq.id, pq.quizid 
             FROM {local_personalcourse_quizzes} pq
             JOIN {local_personalcourse_courses} pc ON pq.personalcourseid = pc.id
             WHERE pc.userid = ? AND pq.sourcequizid = ?",
            [$userid, $quizid]
        );

        if ($existing_pq) {
            // Check if the linked quiz AND its course module still exist in Moodle
            // Note: Quiz record may persist in recycle bin even after UI deletion
            // We must verify the course_module exists and is not being deleted
            $cm_exists = $DB->record_exists_sql(
                "SELECT 1 FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE cm.instance = ? AND cm.deletioninprogress = 0",
                [$existing_pq->quizid]
            );
            
            if ($cm_exists) {
                error_log("[local_personalcourse] Personal quiz exists (Quiz ID {$existing_pq->quizid}). Skipping re-generation.");
                
                // OPTIONAL: Send a "Success" notification here too?
                // User asked for "Notification... similar to success message" but maybe they mean failing message?
                // If they scored > 80% and quiz exists, maybe just remind them "Your personal quiz is ready"?
                return;
            } else {
                // Orphaned record! The quiz was deleted or course module is missing/deleting.
                // Delete the stale mapping and allow re-generation.
                error_log("[local_personalcourse] Orphaned personal quiz record found (Quiz ID {$existing_pq->quizid} - CM missing or deleting). Cleaning up mapping.");
                $DB->delete_records('local_personalcourse_quizzes', ['id' => $existing_pq->id]);
            }
        }

        error_log("[local_personalcourse] ATTEMPT_SUBMITTED: Calling generator_service for user=$userid quiz=$quizid attempt=$attemptid");

        // === SYNCHRONOUS GENERATION ===
        // Call generator_service directly with the attempt ID
        // This will:
        // 1. Check threshold (>30%)
        // 2. Auto-flag incorrect questions (fraction=0)
        // 3. Create or sync personal quiz
        
        if (!method_exists('\\local_personalcourse\\generator_service', 'generate_from_source')) {
             error_log("[local_personalcourse] CRITICAL: generator_service::generate_from_source method not found. This indicates a cache mismatch. Please purge Moodle caches.");
             return;
        }

        try {
            $result = \local_personalcourse\generator_service::generate_from_source($userid, $quizid, $attemptid);
            error_log("[local_personalcourse] ATTEMPT_SUBMITTED: Result - quizid=" . ($result->quizid ?? 0) . " mappingid=" . ($result->mappingid ?? 0));
            
            // Show notification if quiz was created or updated
            if (!empty($result->quizid)) {
                if (!empty($result->toadd) || !empty($result->toremove)) {
                    $cm = get_coursemodule_from_instance('quiz', $result->quizid);
                    $url = new \moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
                    \core\notification::add(
                        '<span class="local-personalcourse-notification">' . 
                        get_string('personalquiz_updated_prominent', 'local_personalcourse', $url->out()) . 
                        '</span>',
                        \core\notification::INFO
                    );
                } else if ($result->mappingid > 0) {
                    $cm = get_coursemodule_from_instance('quiz', $result->quizid);
                    $url = new \moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
                    \core\notification::add(
                        '<span class="local-personalcourse-notification">' . 
                        get_string('personalquiz_created_prominent', 'local_personalcourse', $url->out()) . 
                        '</span>',
                        \core\notification::INFO
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log("[local_personalcourse] Generation failed: " . $e->getMessage());
        }
    }

    /**
     * Check if user has student role in course
     */
    private static function user_has_student_role(int $userid, int $courseid): bool {
        if (is_siteadmin($userid)) {
            return true;
        }
        $coursectx = \context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        foreach ($roles as $ra) {
            if (!empty($ra->shortname) && $ra->shortname === 'student') {
                return true;
            }
        }
        return false;
    }

    /**
     * Called when a quiz is viewed - no-op in simplified version
     */
    /**
     * Called when user views a quiz page.
     * For Personal Quizzes: Run sync to ensure structure matches current flags.
     * This handles edge cases like two-tab scenario where flags changed elsewhere.
     */
    public static function on_quiz_viewed(\mod_quiz\event\course_module_viewed $event): void {
        global $DB;
        
        try {
            $context = $event->get_context();
            if (!($context instanceof \context_module)) {
                return;
            }
            
            $cmid = $context->instanceid;
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
            
            $quizid = (int)$cm->instance;
            $userid = $event->userid;
            
            // Check if this is a Personal Quiz
            $pq_mapping = $DB->get_record('local_personalcourse_quizzes', ['quizid' => $quizid]);
            if (!$pq_mapping) {
                return; // Not a PQ, ignore
            }
            
            // Sync structure based on current flags
            // This ensures the quiz is up-to-date before user starts a new attempt
            error_log("[local_personalcourse] QUIZ_VIEWED: Syncing PQ $quizid for user $userid");
            
            \local_personalcourse\generator_service::generate_from_source($userid, $pq_mapping->sourcequizid);
            
        } catch (\Throwable $e) {
            error_log("[local_personalcourse] on_quiz_viewed error: " . $e->getMessage());
        }
    }

    /**
     * Called when a personal quiz attempt starts.
     * Safety check: Ensure structure matches flags (handles stale tab edge case).
     */
    public static function on_personal_quiz_attempt_started(\core\event\base $event): void {
        global $DB;
        
        try {
            $context = $event->get_context();
            if (!($context instanceof \context_module)) {
                return;
            }
            
            $cmid = $context->instanceid;
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return;
            }
            
            $quizid = (int)$cm->instance;
            $userid = $event->relateduserid ?? $event->userid;
            
            // Check if this is a Personal Quiz
            $pq_mapping = $DB->get_record('local_personalcourse_quizzes', ['quizid' => $quizid]);
            if (!$pq_mapping) {
                return; // Not a PQ, ignore
            }
            
            // Run sync to ensure structure is current
            // This catches edge case where user started attempt from stale view page
            error_log("[local_personalcourse] ATTEMPT_STARTED: Ensuring PQ $quizid sync for user $userid");
            
            \local_personalcourse\generator_service::generate_from_source($userid, $pq_mapping->sourcequizid);
            
        } catch (\Throwable $e) {
            error_log("[local_personalcourse] on_personal_quiz_attempt_started error: " . $e->getMessage());
        }
    }

    /**
     * Check if quiz contains essay questions
     */
    private static function quiz_has_essay(int $quizid): bool {
        global $DB;
        try {
            return $DB->record_exists_sql(
                "SELECT 1
                   FROM {quiz_slots} qs
                   JOIN {question_references} qr ON qr.itemid = qs.id 
                        AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                   JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                   JOIN {question} q ON q.id = qv.questionid
                  WHERE qs.quizid = ? AND q.qtype = 'essay'",
                [$quizid]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
