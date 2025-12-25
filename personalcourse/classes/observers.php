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
                    \core\notification::success(get_string('personalquiz_updated_prominent', 'local_personalcourse', $url->out()));
                } else if ($result->mappingid > 0) {
                    $cm = get_coursemodule_from_instance('quiz', $result->quizid);
                    $url = new \moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
                    \core\notification::success(get_string('personalquiz_created_prominent', 'local_personalcourse', $url->out()));
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
    public static function on_quiz_viewed(\mod_quiz\event\course_module_viewed $event): void {
        // No-op: structural sync now handled entirely by flag events and attempt submission
    }

    /**
     * Called when a personal quiz attempt starts - no-op in simplified version
     */
    public static function on_personal_quiz_attempt_started(\core\event\base $event): void {
        // No-op: no pre-attempt mutations needed in synchronous model
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
