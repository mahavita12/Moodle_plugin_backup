<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

/**
 * Simplified Personal Quiz Generator Service
 * 
 * Architecture:
 * 1. Synchronous execution (no async tasks)
 * 2. Single source of truth: Blue flags (excluding Red flags)
 * 3. Auto-flag incorrect questions (fraction=0) on first generation
 * 4. Force-delete in-progress attempts before slot changes
 * 5. Differential sync: toadd = desired - current, toremove = current - desired
 */
class generator_service {

    /**
     * Main entry point: generate or sync a personal quiz from a source quiz.
     *
     * @param int $userid Student user ID
     * @param int $sourcequizid Source (public) quiz ID
     * @param int|null $attemptid Optional attempt ID for first generation
     * @return object Result with personalcourseid, quizid, cmid, toadd, toremove
     */
    public static function generate_from_source(int $userid, int $sourcequizid, ?int $attemptid = null): object {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // === STEP 1: SETUP ===
        // Only release session lock in CLI mode to prevent web redirect issues
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            try { \core\session\manager::write_close(); } catch (\Throwable $e) {}
        }
        if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }

        // Store original user for restoration
        $originaluser = $USER;

        // Switch to admin context for permissions
        $admins = explode(',', $CFG->siteadmins);
        $adminid = !empty($admins) ? (int)trim($admins[0]) : 2;
        $adminuser = $DB->get_record('user', ['id' => $adminid]);
        if ($adminuser) {
            \core\session\manager::set_user($adminuser);
        }

        try {
            $result = self::do_generate($userid, $sourcequizid, $attemptid);
        } finally {
            // Restore original user
            if ($originaluser && isset($originaluser->id)) {
                \core\session\manager::set_user($originaluser);
            }
        }

        return $result;
    }

    /**
     * Internal generation logic (runs under admin context)
     */
    private static function do_generate(int $userid, int $sourcequizid, ?int $attemptid): object {
        global $DB, $CFG;

        error_log("[local_personalcourse] do_generate: userid=$userid sourcequizid=$sourcequizid attemptid=" . ($attemptid ?? 'null'));

        $emptyresult = (object)[
            'personalcourseid' => 0,
            'mappingid' => 0,
            'quizid' => 0,
            'cmid' => 0,
            'toadd' => [],
            'toremove' => [],
        ];

        // === STEP 2: VALIDATE SOURCE QUIZ ===
        $sourcequiz = $DB->get_record('quiz', ['id' => $sourcequizid], 'id,course,name');
        if (!$sourcequiz) {
            error_log("[local_personalcourse] do_generate: source quiz not found");
            return $emptyresult;
        }

        // Skip if source quiz contains essay questions
        if (self::quiz_has_essay($sourcequizid)) {
            error_log("[local_personalcourse] do_generate: quiz has essay - returning empty");
            return $emptyresult;
        }
        error_log("[local_personalcourse] do_generate: no essays, proceeding");

        // === STEP 3: ENSURE PERSONAL COURSE ===
        $cg = new \local_personalcourse\course_generator();
        $pcctx = $cg->ensure_personal_course($userid);
        $personalcourseid = (int)$pcctx->pc->id;
        $pccourseid = (int)$pcctx->course->id;

        // Ensure student enrolment
        try {
            $enrol = new \local_personalcourse\enrollment_manager();
            $enrol->ensure_manual_instance_and_enrol_student($pccourseid, $userid);
        } catch (\Throwable $e) {}

        // === STEP 4: GET SOURCE QUIZ QUESTION IDS (excluding essays) ===
        $sourceqids = self::get_quiz_questionids($sourcequizid, true);
        if (empty($sourceqids)) {
            return $emptyresult;
        }

        // === STEP 5: CHECK IF PERSONAL QUIZ EXISTS ===
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => $personalcourseid,
            'sourcequizid' => $sourcequizid,
        ]);
        
        // **CLEANUP: Delete orphaned mapping if quiz no longer exists**
        // **CLEANUP: Delete orphaned mapping if quiz no longer exists**
        // We check for the Course Module (CM) because mdl_quiz record might persist (e.g. Recycle Bin)
        $cm_exists = false;
        if ($pq) {
            try {
                // Check if valid CM exists in the personal course
                // Use MUST_EXIST to ensure we throw if missing
                $cm = get_coursemodule_from_instance('quiz', $pq->quizid, $pccourseid, false, MUST_EXIST);
                
                // Extra check: is it in deletion process?
                if (!empty($cm->deletioninprogress)) {
                     throw new \moodle_exception('deletioninprogress');
                }
                $cm_exists = true;
            } catch (\Throwable $e) {
                // CM not found or deleted
                error_log("[local_personalcourse] do_generate: CM lookup failed for quiz {$pq->quizid}: " . $e->getMessage());
            }

            if (!$cm_exists) {
                error_log("[local_personalcourse] do_generate: Found orphaned mapping id={$pq->id} - CM missing/deleted, deleting mapping and resetting");
                $DB->delete_records('local_personalcourse_quizzes', ['id' => (int)$pq->id]);
                $pq = null; // Force null to trigger isFirstGeneration
            }
        }

        if (false) {
            try {
                // Check if valid CM exists in the personal course
                $cm = get_coursemodule_from_instance('quiz', $pq->quizid, $pccourseid, false, MUST_EXIST);
                $cm_exists = true;
            } catch (\Throwable $e) {
                // CM not found
            }

            if (!$cm_exists) {
                error_log("[local_personalcourse] do_generate: Found orphaned mapping id={$pq->id} - CM missing, deleting mapping");
                $DB->delete_records('local_personalcourse_quizzes', ['id' => (int)$pq->id]);
                $pq = null; 
            }
        }
        
        $isFirstGeneration = empty($pq);
        error_log("[local_personalcourse] do_generate: personalcourseid=$personalcourseid isFirstGeneration=" . ($isFirstGeneration ? 'true' : 'false'));

        // === STEP 6: THRESHOLD CHECK FOR FIRST GENERATION ===
        if ($isFirstGeneration) {
            error_log("[local_personalcourse] do_generate: First generation - checking threshold");
            if (!\local_personalcourse\threshold_policy::allow_initial_creation($userid, $sourcequizid)) {
                error_log("[local_personalcourse] do_generate: Threshold NOT met - returning empty");
                $emptyresult['personalcourseid'] = $personalcourseid;
                return $emptyresult;
            }
            error_log("[local_personalcourse] do_generate: Threshold passed");
        } else {
            error_log("[local_personalcourse] do_generate: Not first generation - skipping threshold");
        }

        // === STEP 7: AUTO-FLAG INCORRECT QUESTIONS (First Generation Only) ===
        // If this is the *first time* we are creating the quiz, auto-flag incorrect questions
        // This populates the initial set of Blue flags
        if ($isFirstGeneration && $attemptid) {
            self::auto_flag_incorrect_questions($userid, $sourcequizid, $attemptid, $sourceqids);
        }

        // === STEP 8: GET DESIRED SET (Blue flags, excluding Red flags) ===
        $desired = self::get_desired_questionids($userid, $sourceqids);

        // If no desired questions and first generation, nothing to do
        if (empty($desired) && $isFirstGeneration) {
            $emptyresult['personalcourseid'] = $personalcourseid;
            return $emptyresult;
        }

        // === STEP 9: CREATE PERSONAL QUIZ IF NOT EXISTS ===
        $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);

        if ($isFirstGeneration) {
            // Create the personal quiz
            $sm = new \local_personalcourse\section_manager();
            $prefix = \local_personalcourse\naming_policy::section_prefix($userid, (int)$sourcequiz->course, $sourcequizid);
            $sectionnumber = $sm->ensure_section_by_prefix($pccourseid, $prefix);
            $name = \local_personalcourse\naming_policy::personal_quiz_name($userid, $sourcequizid);

            $qb = new \local_personalcourse\quiz_builder();
            $res = $qb->create_quiz($pccourseid, $sectionnumber, $name, '', 'default', $prefix);

            // **CRITICAL: Verify quiz was actually created**
            if (empty($res->success) || empty($res->quizid) || !$DB->record_exists('quiz', ['id' => (int)$res->quizid])) {
                error_log("[local_personalcourse] do_generate: FAILED to create quiz - success=" . ($res->success ?? 'null') . " quizid=" . ($res->quizid ?? 'null') . " error=" . ($res->error ?? 'none'));
                return $emptyresult;
            }
            error_log("[local_personalcourse] do_generate: Quiz created successfully - quizid={$res->quizid} cmid={$res->cmid}");

            // Safety: Ensure deletioninprogress is 0 for the newly created PQ as well
            $DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => (int)$res->cmid]);

            // Create mapping record
            $pqrec = (object)[
                'personalcourseid' => $personalcourseid,
                'quizid' => (int)$res->quizid,
                'sourcequizid' => $sourcequizid,
                'sectionname' => $prefix,
                'quiztype' => 'non_essay',
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
            $pq = $pqrec;

            // Mark CM with provenance
            if (!empty($res->cmid)) {
                $marker = 'pcq:' . $userid . ':' . $sourcequizid;
                $DB->set_field('course_modules', 'idnumber', $marker, ['id' => (int)$res->cmid]);

                // Tag with Source Course Category (User Request)
                try {
                     $sourcecourse = $DB->get_record('course', ['id' => $sourcequiz->course]);
                     if ($sourcecourse && !empty($sourcecourse->category)) {
                          \core_tag_tag::set_item_tags('core', 'course_modules', (int)$res->cmid, \context_module::instance((int)$res->cmid), ['SourceCategory_' . $sourcecourse->category]);
                          error_log("[local_personalcourse] Tagged CM {$res->cmid} with SourceCategory_{$sourcecourse->category}");
                     }
                } catch (\Throwable $e) {
                     error_log("[local_personalcourse] Failed to set tags: " . $e->getMessage());
                }
            }
        }

        // Note: Orphaned quiz check is now done in STEP 5 above

        // === STEP 10: GET CURRENT PERSONAL QUIZ SLOTS ===
        $current = self::get_quiz_questionids((int)$pq->quizid, false);

        // === STEP 11: COMPUTE DIFFERENTIAL ===
        $toadd = array_values(array_diff($desired, $current));
        $toremove = array_values(array_diff($current, $desired));

        // === STEP 12: APPLY CHANGES ===
        if (!empty($toadd) || !empty($toremove)) {
            // Check if quiz will be empty (for logging)
            $finalcount = count($current) + count($toadd) - count($toremove);
            if ($finalcount <= 0) {
                 error_log("[local_personalcourse] do_generate: Quiz will be empty.");
            }

            // Always redirect to Personal Course view when attempts are deleted (per user request)
            $redirecturl = $CFG->wwwroot . '/course/view.php?id=' . $pccourseid;

            // **CRITICAL: Force-delete in-progress attempts BEFORE slot changes**
            // If the quiz becomes EMPTY, we must delete ALL attempts (even finished ones)
            // to prevent summary.php from crashing on broken references.
            // Otherwise, we only delete in-progress/overdue attempts.
            $delete_all_attempts = ($finalcount <= 0);
            self::delete_inprogress_attempts((int)$pq->quizid, $userid, $redirecturl, $delete_all_attempts);

            $qb = new \local_personalcourse\quiz_builder();

            // Remove questions
            foreach ($toremove as $qid) {
                $qb->remove_question((int)$pq->quizid, (int)$qid);
            }

            // Add questions
            if (!empty($toadd)) {
                $qb->add_questions((int)$pq->quizid, $toadd);
            }

            // Re-sequence slots to 1, 2, 3...
            self::resequence_slots((int)$pq->quizid);

            // Rebuild course cache
            try {
                rebuild_course_cache($pccourseid, true);
            } catch (\Throwable $e) {}
        }

        // === STEP 13: COMPUTE CMID AND RETURN ===
        $cmid = (int)$DB->get_field('course_modules', 'id', [
            'module' => $moduleidquiz,
            'instance' => (int)$pq->quizid,
            'course' => $pccourseid,
        ], IGNORE_MISSING);

        // **NEW LOGIC: If First Generation resulted in an EMPTY quiz, DELETE IT**
        // This prevents the "Success" message leading to an empty quiz.
        // We check actual slots in DB to be sure.
        $slot_count = $DB->count_records('quiz_slots', ['quizid' => $pq->quizid]);
        
        if ($isFirstGeneration && $slot_count === 0) {
            error_log("[local_personalcourse] do_generate: First generation resulted in EMPTY quiz (0 questions). Deleting module to avoid confusion.");
            
            if ($cmid) {
                // Delete the course module (handles activity deletion too)
                course_delete_module($cmid);
            } else {
                // Fallback if CM missing but quiz exists
                quiz_delete_instance($pq->quizid);
            }
            
            // Delete mapping
            $DB->delete_records('local_personalcourse_quizzes', ['id' => (int)$pq->id]);
            
            // Return empty result
            return $emptyresult;
        }

        return (object)[
            'personalcourseid' => $personalcourseid,
            'mappingid' => (int)$pq->id,
            'quizid' => (int)$pq->quizid,
            'cmid' => $cmid,
            'toadd' => $toadd,
            'toremove' => $toremove,
        ];
    }

    /**
     * Check if a quiz contains any essay questions
     */
    private static function quiz_has_essay(int $quizid): bool {
        global $DB;
        // Moodle 4.x: quiz_slots → question_references → question_versions → question
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
            error_log("[local_personalcourse] quiz_has_essay error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all question IDs from a quiz (normalized to question.id)
     * 
     * @param int $quizid Quiz ID
     * @param bool $excludeEssay Whether to exclude essay questions
     * @return array Array of question IDs in slot order
     */
    private static function get_quiz_questionids(int $quizid, bool $excludeEssay = false): array {
        global $DB;
        
        $excludeClause = $excludeEssay ? "AND q.qtype <> 'essay'" : "";
        
        // Moodle 4.x: quiz_slots → question_references → question_versions → question
        $qids = $DB->get_fieldset_sql(
            "SELECT qv.questionid
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id 
                    AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = ? {$excludeClause}
              ORDER BY qs.slot ASC",
            [$quizid]
        );

        return array_values(array_unique(array_map('intval', $qids)));
    }

    /**
     * Get desired question IDs based on flags
     * Desired = All flagged questions (both Blue and Red) that exist in source quiz
     */
    private static function get_desired_questionids(int $userid, array $sourceqids): array {
        global $DB;
        
        if (empty($sourceqids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($sourceqids, SQL_PARAMS_QM);
        array_unshift($params, $userid);

        // Get all flags (blue and red) for this user for questions in the source quiz
        $flaggedqids = $DB->get_fieldset_sql(
            "SELECT DISTINCT questionid FROM {local_questionflags}
              WHERE userid = ? AND questionid {$insql} AND flagcolor IN ('blue', 'red')",
            $params
        );

        // Maintain source quiz order
        $ordered = [];
        foreach ($sourceqids as $qid) {
            if (in_array((int)$qid, array_map('intval', $flaggedqids), true)) {
                $ordered[] = (int)$qid;
            }
        }

        return $ordered;
    }

    /**
     * Auto-flag incorrect questions as Blue (for first generation)
     * Only flags questions that are completely wrong (fraction = 0)
     */
    private static function auto_flag_incorrect_questions(int $userid, int $sourcequizid, int $attemptid, array $sourceqids): void {
        global $DB;

        if (empty($sourceqids)) {
            return;
        }

        // Get incorrect questions from the attempt
        $analyzer = new \local_personalcourse\attempt_analyzer();
        $incorrect = $analyzer->get_incorrect_questionids_from_attempt($attemptid);

        if (empty($incorrect)) {
            return;
        }

        // Filter to only source quiz questions
        $incorrect = array_intersect($incorrect, $sourceqids);

        if (empty($incorrect)) {
            return;
        }

        // Get existing flags to avoid duplicates
        list($insql, $params) = $DB->get_in_or_equal($incorrect, SQL_PARAMS_QM);
        array_unshift($params, $userid);
        $existingflags = $DB->get_fieldset_sql(
            "SELECT questionid FROM {local_questionflags} WHERE userid = ? AND questionid {$insql}",
            $params
        );

        // Insert Blue flags for incorrect questions not already flagged
        $toflag = array_diff($incorrect, $existingflags);
        $now = time();

        foreach ($toflag as $qid) {
            $record = (object)[
                'userid' => $userid,
                'questionid' => (int)$qid,
                'questionbankentryid' => 0,
                'flagcolor' => 'blue',
                'quizid' => $sourcequizid,
                'cmid' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            try {
                $DB->insert_record('local_questionflags', $record);
            } catch (\Throwable $e) {
                // Ignore duplicate key errors
            }
        }
    }

    /**
     * Delete all in-progress and overdue attempts for a user on a quiz
     * MUST be called before modifying quiz structure
     * Stores redirect URL in session for the user to be redirected to the quiz view page
     */
    private static function delete_inprogress_attempts(int $quizid, int $userid, ?string $redirecturl = null, bool $delete_all = false): void {
        global $DB, $CFG, $SESSION;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if ($delete_all) {
             // Delete EVERYTHING (safe/required for empty quizzes)
             $attempts = $DB->get_records_select(
                'quiz_attempts',
                "quiz = ? AND userid = ?",
                [$quizid, $userid]
             );
        } else {
             // Only delete in-progress/overdue to preserve user history locally
             $attempts = $DB->get_records_select(
                'quiz_attempts',
                "quiz = ? AND userid = ? AND state IN ('inprogress', 'overdue')",
                [$quizid, $userid]
             );
        }

        if (empty($attempts)) {
            return;
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', IGNORE_MISSING);
        if (!$quiz) {
            return;
        }

        try {
            $cm = get_coursemodule_from_instance('quiz', $quizid, $quiz->course, false, MUST_EXIST);
            $quiz->cmid = (int)$cm->id;
        } catch (\Throwable $e) {
            return;
        }

        // Store redirect URL in session BEFORE deleting attempts
        // This allows the hook to redirect the user when they try to access the deleted attempt
        if (isset($SESSION)) {
            $url = $redirecturl ?? ($CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id);
            $SESSION->local_personalcourse_redirect = (object)[
                'url' => $url,
                'quizid' => $quizid,
                'userid' => $userid,
                'time' => time(),
            ];
            error_log("[local_personalcourse] Stored redirect URL in session for quiz cmid={$cm->id} to $url");
        }

        foreach ($attempts as $attempt) {
            try {
                quiz_delete_attempt($attempt, $quiz);
                error_log("[local_personalcourse] Deleted in-progress attempt {$attempt->id} for quiz $quizid");
            } catch (\Throwable $e) {
                // Log but continue
                error_log("[local_personalcourse] Failed to delete attempt {$attempt->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Re-sequence quiz slots to 1, 2, 3... with no gaps
     */
    private static function resequence_slots(int $quizid): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot ASC', 'id, slot');
        
        $pos = 1;
        foreach ($slots as $slot) {
            if ((int)$slot->slot !== $pos) {
                $DB->set_field('quiz_slots', 'slot', $pos, ['id' => $slot->id]);
            }
            $pos++;
        }

        // Repaginate
        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if ($quiz) {
            $qpp = (int)($quiz->questionsperpage ?? 1);
            if ($qpp <= 0) { $qpp = 1; }
            quiz_repaginate_questions($quizid, $qpp);
            quiz_update_sumgrades($quiz);
        }
    }
}
