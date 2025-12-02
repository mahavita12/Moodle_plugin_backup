<?php
// Essays Master Feedback API Endpoint - FULLY FIXED VERSION
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
// ✅ REMOVED: text_analyzer.php - now using real AI helper instead

// Ensure this is an AJAX request
if (!defined('AJAX_SCRIPT')) {
    define('AJAX_SCRIPT', true);
}

// Get parameters - 6-ROUND SYSTEM SUPPORT
$attemptid = required_param('attemptid', PARAM_INT);
$round = optional_param('round', 1, PARAM_INT); // Made optional for get_state
$sesskey = required_param('sesskey', PARAM_RAW);
$nonce = optional_param('nonce', null, PARAM_ALPHANUMEXT); // 🔍 PROBE B: Client nonce
$action = optional_param('action', 'feedback', PARAM_ALPHANUMEXT); // NEW: Action parameter

// NEW: Get current text from frontend
$current_text = optional_param('current_text', '', PARAM_RAW);
$original_text = optional_param('original_text', '', PARAM_RAW);
$question_prompt = optional_param('question_prompt', '', PARAM_RAW);

// Debug logging for text flow
error_log("Essays Master: Action: $action");
error_log("Essays Master: Received round $round with current_text length: " . strlen($current_text));
error_log("Essays Master: Received original_text length: " . strlen($original_text));

// Verify session key
if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid session key']);
    exit;
}

// Require login
require_login();

header('Content-Type: application/json');

try {
    // Get attempt details
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);

    // HANDLE STATE RETRIEVAL
    if ($action === 'get_state') {
        $session = $DB->get_record('local_essaysmaster_sessions', [
            'attempt_id' => $attemptid,
            'user_id' => $USER->id
        ]);

        if ($session) {
            echo json_encode([
                'success' => true,
                'current_level' => (int)$session->current_level,
                'feedback_rounds_completed' => (int)$session->feedback_rounds_completed,
                'status' => $session->status,
                'final_submission_allowed' => (int)$session->final_submission_allowed
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'current_level' => 0, // No session yet
                'feedback_rounds_completed' => 0,
                'status' => 'new',
                'final_submission_allowed' => 0
            ]);
        }
        exit;
    }

    // Check if user owns this attempt or has permission
    if ($attempt->userid != $USER->id && !has_capability('mod/quiz:viewreports', context_system::instance())) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    // Resolve student display name (firstname only) for prompt name policy
    $student_name = '';
    try {
        $student = $DB->get_record('user', ['id' => (int)$attempt->userid], 'id,firstname,lastname', IGNORE_MISSING);
        if ($student && !empty($student->firstname)) {
            $student_name = format_string($student->firstname, true);
        }
    } catch (Exception $e) { $student_name = ''; }

    // IDEMPOTENT: Get or create session with duplicate prevention
    $session = $DB->get_record('local_essaysmaster_sessions', [
        'attempt_id' => $attemptid, 
        'user_id' => $USER->id
    ]);

    if (!$session) {
        // ATOMIC SESSION CREATION: Try to create, handle duplicates gracefully
        $session = new stdClass();
        $session->attempt_id = $attemptid;
        $session->question_attempt_id = 0;
        $session->user_id = $USER->id;
        $session->current_level = 1;
        $session->max_level = 6; // UPDATED: Support 6-round system
        $session->threshold_percentage = 50.0;

        $session->status = 'active';
        $session->session_start_time = time();
        $session->session_end_time = 0;
        $session->final_submission_allowed = 0;
        $session->feedback_rounds_completed = 0;
        $session->timecreated = time();
        $session->timemodified = time();

        try {
            $session->id = $DB->insert_record('local_essaysmaster_sessions', $session);
        } catch (Exception $e) {
            // HANDLE DUPLICATE: If insert fails, try to get existing session
            $existing = $DB->get_record('local_essaysmaster_sessions', [
                'attempt_id' => $attemptid, 
                'user_id' => $USER->id
            ]);
            
            if ($existing) {
                $session = $existing;
                error_log("Essays Master: Using existing session {$session->id} for attempt $attemptid");
            } else {
                // Fallback session object
                $session = (object) [
                    'id' => 0,
                    'attempt_id' => $attemptid,
                    'user_id' => $USER->id,
                    'current_level' => $round,
                    'feedback_rounds_completed' => 0
                ];
            }
        }
    }

    // ALLOW RE-ATTEMPTS: Log nonce but don't block re-attempts
    if ($nonce) {
        error_log("Essays Master: Processing request with nonce: $nonce for attempt $attemptid, round $round - allowing re-attempt");
    }

    // ALLOW RE-ATTEMPTS: Check if feedback record exists, but allow overwriting
    $existing_feedback = $DB->get_record('local_essaysmaster_feedback', [
        'version_id' => $attemptid, // Using attemptid as version_id for now
        'level_type' => "round_$round"
    ]);

    if ($existing_feedback) {
        error_log("Essays Master: Found existing feedback for round $round, attempt $attemptid - will overwrite to allow re-attempt");
        // Don't return existing feedback - allow re-processing to overwrite
    }

    // ALLOW ROUND RE-ATTEMPTS: Allow any round to be attempted/re-attempted
    // Students can now retry any round without strict progression requirements
    error_log("Essays Master: Allowing round $round attempt/re-attempt for attempt $attemptid");

    // Get quiz and question details
    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);

    // Get essay questions from this attempt
    $sql = "SELECT qa.*, q.questiontext, q.name as questionname
            FROM {question_attempts} qa
            JOIN {question} q ON q.id = qa.questionid
            WHERE qa.questionusageid = ? AND q.qtype = 'essay'";

    $essay_questions = $DB->get_records_sql($sql, [$attempt->uniqueid]);

    if (empty($essay_questions)) {
        echo json_encode(['success' => false, 'error' => 'No essay questions found in this attempt']);
        exit;
    }

    // LOCK: Prevent parallel feedback generation for same attempt/round
    $factory = \core\lock\lock_config::get_lock_factory('local_essaysmaster');
    $lock_key = "feedback_{$attemptid}_r{$round}";
    $lock = $factory->get_lock($lock_key, 20); // 20 second timeout

    if (!$lock) {
        echo json_encode(['success' => false, 'error' => 'Could not acquire processing lock. Please try again.']);
        exit;
    }

    try {
        // ALLOW RE-ATTEMPTS: Skip round completion check to allow overwriting
        $session_recheck = $DB->get_record('local_essaysmaster_sessions', ['id' => $session->id]);
        if ($session_recheck) {
            error_log("Essays Master: Session recheck - allowing round $round re-attempt for attempt $attemptid");
        }

        // ALLOW OVERWRITING: Check for existing feedback but don't block re-attempts
        $recheck_feedback = $DB->get_record('local_essaysmaster_feedback', [
            'version_id' => $attemptid,
            'level_type' => "round_$round"
        ]);

        if ($recheck_feedback) {
            error_log("Essays Master: Found existing feedback for round $round - will overwrite to allow re-attempt");
        }

        // NEW: Use current text from frontend if available, fallback to database
        $essay_text = '';
        $real_question_text = 'Sample question prompt'; // Default fallback
        
        if (!empty($current_text)) {
            // Use the current text sent from frontend
            $essay_text = $current_text;
            error_log("Essays Master: Using current text from frontend (length: " . strlen($essay_text) . ")");
        } else {
            // Fallback: Get from database
            foreach ($essay_questions as $qa) {
                $sql = "SELECT qasd.value as answer
                        FROM {question_attempt_steps} qas
                        JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                        WHERE qas.questionattemptid = ?
                        AND qasd.name = 'answer'
                        AND qasd.value IS NOT NULL
                        ORDER BY qas.timecreated DESC
                        LIMIT 1";

                $answer_record = $DB->get_record_sql($sql, [$qa->id]);
                if ($answer_record && $answer_record->answer) {
                    $essay_text = $answer_record->answer;
                    break;
                }
            }
            error_log("Essays Master: Using database text fallback (length: " . strlen($essay_text) . ")");
        }
        
        // Extract real question text for rounds 5 and 6
        if (in_array($round, [5, 6]) && !empty($essay_questions)) {
            foreach ($essay_questions as $qa) {
                if (!empty($qa->questiontext)) {
                    // Clean the question text from HTML and decode entities
                    $real_question_text = trim(strip_tags(html_entity_decode($qa->questiontext, ENT_QUOTES, 'UTF-8')));
                    error_log("Essays Master: Extracted real question text for round $round (length: " . strlen($real_question_text) . ")");
                    break;
                }
            }
        }
        
        // Use real question text if available, otherwise use the parameter sent from frontend
        $question_prompt = ($real_question_text !== 'Sample question prompt') ? $real_question_text : $question_prompt;

        // REAL AI INTEGRATION: Use AI helper instead of fake analysis
        require_once(__DIR__ . '/classes/ai_helper.php');
        $ai_helper = new \local_essaysmaster\ai_helper();
        
        $is_validation_round = in_array($round, [2, 4, 6]);
        $is_feedback_round = in_array($round, [1, 3, 5]);
        
        if ($is_validation_round) {
            // VALIDATION ROUNDS (2, 4, 6) - Real AI validation
            error_log("Essays Master: Calling AI validation for round $round");
            
            // CONTEXT AWARENESS: Retrieve previous feedback round to avoid contradictions
            $previous_feedback_text = '';
            if ($round == 4) {
                // Get Round 3 feedback for context
                $round3_feedback = $DB->get_record('local_essaysmaster_feedback', [
                    'version_id' => $attemptid,
                    'level_type' => 'round_3'
                ]);
                if ($round3_feedback && !empty($round3_feedback->feedback_html)) {
                    $previous_feedback_text = $round3_feedback->feedback_html;
                    error_log("Essays Master: Retrieved Round 3 feedback for Round 4 context (length: " . strlen($previous_feedback_text) . ")");
                }
            } elseif ($round == 6) {
                // Get Round 5 feedback for context
                $round5_feedback = $DB->get_record('local_essaysmaster_feedback', [
                    'version_id' => $attemptid,
                    'level_type' => 'round_5'
                ]);
                if ($round5_feedback && !empty($round5_feedback->feedback_html)) {
                    $previous_feedback_text = $round5_feedback->feedback_html;
                    error_log("Essays Master: Retrieved Round 5 feedback for Round 6 context (length: " . strlen($previous_feedback_text) . ")");
                }
            }
            
            if (!empty($original_text) && !empty($essay_text)) {
                try {
                    $ai_result = $ai_helper->generate_validation($round, $original_text, $essay_text, $question_prompt, $previous_feedback_text, $student_name);

                    // Save version snapshot (rounds 1-5) right after sending to AI
                    if ($round >= 1 && $round <= 5 && !empty($session) && !empty($session->id)) {
                        try {
                            $max_version = $DB->get_field_sql(
                                "SELECT MAX(version_number) FROM {local_essaysmaster_versions} WHERE session_id = ?",
                                [$session->id]
                            );
                            $next_version = ($max_version !== null) ? ((int)$max_version + 1) : 1;

                            $version = new stdClass();
                            $version->session_id = $session->id;
                            $version->version_number = $next_version;
                            $version->level_number = $round;
                            $version->original_text = $original_text; // validation includes original vs revised
                            $version->revised_text = $essay_text;
                            $version->word_count = str_word_count($essay_text);
                            $version->character_count = strlen($essay_text);
                            $version->submission_time = time();
                            $version->is_initial = ($next_version === 1) ? 1 : 0;
                            $version->timecreated = time();

                            $DB->insert_record('local_essaysmaster_versions', $version);
                            error_log("Essays Master: Saved version snapshot v{$next_version} for session {$session->id}, round {$round}");
                        } catch (Exception $e) {
                            error_log("Essays Master: Failed to save version snapshot - " . $e->getMessage());
                        }
                    }
                    
                    // CRITICAL FIX: Check actual score, not just API success
                    $validation_passed = ($ai_result['score'] >= 50);
                    if ($validation_passed) {
                        $feedback_text = "GrowMinds Academy Validation Round $round - PASSED \n\n";
                        $feedback_text .= "Score: {$ai_result['score']}/100\n";
                        $feedback_text .= "Analysis: {$ai_result['analysis']}\n\n";
                        $feedback_text .= "Feedback: " . $ai_result['feedback'];
                    } else {
                        $feedback_text = "GrowMinds Academy Validation Round $round - FAILED \n\n";
                        $feedback_text .= "Score: {$ai_result['score']}/100\n";
                        $feedback_text .= "Analysis: {$ai_result['analysis']}\n\n";
                        $feedback_text .= "Feedback: " . $ai_result['feedback'];
                    }
                } catch (Exception $e) {
                    error_log("Essays Master: AI validation failed: " . $e->getMessage());
                    $feedback_text = "GrowMinds Academy validation temporarily unavailable. Score: 50/100 (default pass)\n\n";
                    $feedback_text .= "Please continue with your revisions.";
                }
            } else {
                $feedback_text = "**ERROR:** Missing text for GrowMinds Academy validation. Cannot compare changes.\n";
            }
            
            $analysis = [
                'highlighted_text' => $essay_text,
                'suggestions' => []
            ];
            
        } else if ($is_feedback_round) {
            // FEEDBACK ROUNDS (1, 3, 5) - Real AI feedback
            error_log("Essays Master: Calling AI feedback for round $round");
            
            if (!empty($essay_text)) {
                try {
                    $ai_result = $ai_helper->generate_feedback($round, $essay_text, $question_prompt, $student_name);

                    // Save version snapshot (rounds 1-5) right after sending to AI
                    if ($round >= 1 && $round <= 5 && !empty($session) && !empty($session->id)) {
                        try {
                            $max_version = $DB->get_field_sql(
                                "SELECT MAX(version_number) FROM {local_essaysmaster_versions} WHERE session_id = ?",
                                [$session->id]
                            );
                            $next_version = ($max_version !== null) ? ((int)$max_version + 1) : 1;

                            $version = new stdClass();
                            $version->session_id = $session->id;
                            $version->version_number = $next_version;
                            $version->level_number = $round;
                            // For feedback rounds, store current essay as original_text (revised_text left null)
                            $version->original_text = $essay_text;
                            $version->revised_text = null;
                            $version->word_count = str_word_count($essay_text);
                            $version->character_count = strlen($essay_text);
                            $version->submission_time = time();
                            $version->is_initial = ($next_version === 1) ? 1 : 0;
                            $version->timecreated = time();

                            $DB->insert_record('local_essaysmaster_versions', $version);
                            error_log("Essays Master: Saved version snapshot v{$next_version} for session {$session->id}, round {$round}");
                        } catch (Exception $e) {
                            error_log("Essays Master: Failed to save version snapshot - " . $e->getMessage());
                        }
                    }
                    
                    // For feedback rounds, just check if API call succeeded
                    if ($ai_result['success']) {
                        $feedback_text = $ai_result['feedback'];
                    } else {
                        $feedback_text = "GrowMinds Academy feedback temporarily unavailable: " . $ai_result['message'] . "\n\n";
                        $feedback_text .= "Please continue with your revisions.";
                    }
                } catch (Exception $e) {
                    error_log("Essays Master: AI feedback failed: " . $e->getMessage());
                    $feedback_text = "GrowMinds Academy feedback temporarily unavailable. Please continue with your revisions.";
                }
                
                // Simple analysis for highlighting (no complex suggestions)
                $analysis = [
                    'highlighted_text' => $essay_text,
                    'word_count' => str_word_count($essay_text),
                    'sentence_count' => substr_count($essay_text, '.') + substr_count($essay_text, '!') + substr_count($essay_text, '?'),
                    'suggestions' => []
                ];
            } else {
                $feedback_text = "**ERROR:** No essay text found for GrowMinds Academy analysis.\n";
                $analysis = ['highlighted_text' => '', 'suggestions' => []];
            }
        }

        // Prepare highlights array for word-boundary highlighting
        $highlights_array = [];
        if (!empty($analysis['suggestions'])) {
            foreach ($analysis['suggestions'] as $suggestion) {
                // Extract word from suggestion text or use text field directly
                $word = $suggestion['word'] ?? $suggestion['text'] ?? '';
                
                if (!empty($word) && $word !== $analysis['original_text']) {
                    $highlights_array[] = [
                        'word' => $word,
                        'type' => $suggestion['type'] ?? 'general', 
                        'message' => $suggestion['message'] ?? ''
                    ];
                }
            }
        }

        // ✅ STORE/UPDATE FEEDBACK RECORD: Overwrite existing records to allow re-attempts
        try {
            // Check if feedback record already exists
            $existing_record = $DB->get_record('local_essaysmaster_feedback', [
                'version_id' => $attemptid,
                'level_type' => "round_$round"
            ]);
            
            if ($existing_record) {
                // UPDATE existing record
                $existing_record->attempt_id = $attemptid; // FIXED: Ensure required field is set
                $existing_record->round_number = $round; // FIXED: Update round number
                $existing_record->feedback_html = $nonce ? $feedback_text . "\n<!-- nonce:$nonce -->" : $feedback_text;
                $existing_record->highlighted_areas = json_encode($highlights_array);
                // Use actual AI score instead of hardcoded value
                $existing_record->completion_score = isset($ai_result['score']) ? floatval($ai_result['score']) : 50.0;
                $existing_record->feedback_generated_time = time();
                $existing_record->api_response_time = 0.5; // Default response time
                // Removed timemodified - not in table structure
                
                $DB->update_record('local_essaysmaster_feedback', $existing_record);
                error_log("Essays Master: Updated existing feedback record for attempt $attemptid, round $round (re-attempt)");
            } else {
                // INSERT new record
                $feedback_record = new stdClass();
                $feedback_record->version_id = $attemptid; // Using attemptid as version_id for now
                $feedback_record->attempt_id = $attemptid; // FIXED: Missing required field
                $feedback_record->question_attempt_id = 0; // Default value
                $feedback_record->round_number = $round; // FIXED: Set round number properly
                $feedback_record->level_type = "round_$round";
                
                // 🔍 PROBE B: Include nonce in feedback for tracking
                if ($nonce) {
                    $feedback_record->feedback_html = $feedback_text . "\n<!-- nonce:$nonce -->";
                    error_log("Essays Master: Storing feedback with nonce: $nonce");
                } else {
                    $feedback_record->feedback_html = $feedback_text;
                }
                
                $feedback_record->highlighted_areas = json_encode($highlights_array);
                // Use actual AI score instead of hardcoded value
                $feedback_record->completion_score = isset($ai_result['score']) ? floatval($ai_result['score']) : 50.0;
                $feedback_record->feedback_generated_time = time();
                $feedback_record->api_response_time = 0.5; // Default response time
                $feedback_record->timecreated = time();
                // Removed timemodified for insert - not in table structure
                
                $DB->insert_record('local_essaysmaster_feedback', $feedback_record);
                error_log("Essays Master: Stored new feedback record for attempt $attemptid, round $round");
            }
        } catch (Exception $e) {
            error_log("Essays Master: Could not store/update feedback record: " . $e->getMessage());
            // Continue anyway - don't fail the request
        }

        // ✅ UPDATE SESSION: Allow re-attempts and track current round
        if ($session->id > 0) {
            // ERROR HANDLING: Do not update session if feedback contains error
            if (strpos($feedback_text, 'ERROR:') === 0) {
                error_log("Essays Master: Skipping session update due to feedback error: " . substr($feedback_text, 0, 50));
            } else {
                try {
                    // Always update session to reflect current round, allow re-attempts
                    $update_sql = "UPDATE {local_essaysmaster_sessions} 
                                SET current_level = ?, 
                                feedback_rounds_completed = GREATEST(feedback_rounds_completed, ?),
                                session_end_time = ?,
                                timemodified = ?,
                                final_submission_allowed = CASE WHEN ? >= 6 THEN 1 ELSE final_submission_allowed END,
                                status = CASE WHEN ? >= 6 THEN 'completed' ELSE 'active' END
                            WHERE id = ?";
                
                $update_params = [
                    $round,                    // current_level
                    $round,                    // feedback_rounds_completed (use GREATEST)
                    time(),                    // session_end_time  
                    time(),                    // timemodified
                    $round,                    // for final_submission_allowed check
                    $round,                    // for status check
                    $session->id               // WHERE id (removed the feedback_rounds_completed < round condition)
                ];
                
                $affected_rows = $DB->execute($update_sql, $update_params);
                
                if ($affected_rows > 0) {
                    error_log("Essays Master: Updated session {$session->id} for attempt $attemptid - round $round completed/re-attempted");
                    
                    // Update local session object for response
                    $session->current_level = $round;
                    $session->feedback_rounds_completed = max($session->feedback_rounds_completed, $round);
                    if ($round >= 6) { // UPDATED: 6-round completion
                        $session->final_submission_allowed = 1;
                        $session->status = 'completed';
                    }
                } else {
                    error_log("Essays Master: Could not update session {$session->id} for round $round");
                }
                
            } catch (Exception $e) {
                error_log("Essays Master: Could not update session: " . $e->getMessage());
            }
        }
        }

        // Log the feedback activity
        error_log("Essays Master: Provided feedback round $round for attempt $attemptid to user {$USER->id}");

        // PROGRESS TRACKING INTEGRATION
        if (strpos($feedback_text, 'ERROR:') !== 0) {
            try {
                require_once($CFG->dirroot . '/local/essaysmaster/classes/progress_tracker.php');
                $tracker = new \local_essaysmaster\progress_tracker($session);
                // Map round to level for progress tracking
                $tracker->initialize_level_progress($round);
                
                // Mark requirements as complete based on feedback
                // This is a simplified integration - ideally we parse feedback for specific improvements
                // For now, we assume if they got feedback, they made progress
                $tracker->update_progress($round, ['feedback_received' => true], $original_text, $current_text);
                error_log("Essays Master: Updated progress for session {$session->id} round $round");
            } catch (Exception $e) {
                error_log("Essays Master: Progress tracking error: " . $e->getMessage());
            }
        }

        // Debug logging
        error_log("Essays Master: Created " . count($highlights_array) . " highlights for highlighting");

        // ✅ ENHANCED: Return completion status
        echo json_encode([
            'success' => true,
            'feedback' => $feedback_text,
            'highlighted_text' => $analysis['highlighted_text'] ?? '',
            'highlights' => $highlights_array,
            'round' => $round,
            'max_rounds' => 6, // UPDATED: 6-round system
            'session_id' => $session->id,
            'completed_rounds' => $session->feedback_rounds_completed ?? $round,
            'is_final_round' => $round >= 6, // UPDATED: Final round is 6
            'final_submission_allowed' => ($round >= 6) ? 1 : 0
        ]);

    } finally {
        if (isset($lock)) {
            $lock->release();
        }
    }

} catch (Exception $e) {
    $error_details = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'attemptid' => $attemptid ?? 'undefined',
        'round' => $round ?? 'undefined',
        'userid' => isset($USER) ? $USER->id : 'undefined',
        'trace' => $e->getTraceAsString()
    ];
    error_log("Essays Master feedback error: " . json_encode($error_details));
    echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
}