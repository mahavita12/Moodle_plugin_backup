<?php
// Essays Master Check Intercept - FIXED VERSION
require_once("../../config.php");
require_once($CFG->dirroot . "/local/essaysmaster/lib.php");

// Handle both parameter names
$attemptid = optional_param("attemptid", 0, PARAM_INT);
if (!$attemptid) {
    $attemptid = optional_param("attempt", 0, PARAM_INT);
}

$action = optional_param("action", "check_intercept", PARAM_ALPHA);

if (!$attemptid) {
    header("Content-Type: application/json");
    echo json_encode(["error" => "Missing attemptid parameter"]);
    exit;
}

// Don't require login for AJAX calls - handle authentication differently
if (!isloggedin() || isguestuser()) {
    header("Content-Type: application/json");
    echo json_encode(["error" => "Not authenticated"]);
    exit;
}

header("Content-Type: application/json");

try {
    // ✅ ENHANCED: Handle checking completed feedback rounds with better validation
    if ($action === 'check_rounds') {
        // Get the most recent session for this attempt and user
        $session = $DB->get_record('local_essaysmaster_sessions', [
            'attempt_id' => $attemptid, 
            'user_id' => $USER->id
        ]);
        
        $completed_rounds = 0;
        $final_submission_allowed = false;
        
        // ✅ ACCURATE COUNT: Check actual feedback records first, then session
        $feedback_count = $DB->count_records('local_essaysmaster_feedback', [
            'version_id' => $attemptid  // Using attemptid as version_id
        ]);
        
        if ($session) {
            // Use the higher of actual feedback count or session count
            $session_rounds = $session->feedback_rounds_completed ?? $session->current_level ?? 0;
            $completed_rounds = max($feedback_count, $session_rounds);
            
            // ✅ ENHANCED: Check multiple completion indicators
            if ($session->final_submission_allowed || 
                $session->status === 'completed' || 
                $completed_rounds >= 3) {
                
                $completed_rounds = 3; // Force completion state
                $final_submission_allowed = true;
                
                // ✅ READ-ONLY: Don't modify database here, just report current state
                error_log("Essays Master: Session detected as completed for attempt $attemptid");
            }
        } else {
            // No session exists, but check if feedback records exist
            $completed_rounds = $feedback_count;
            
            // Also check for any completed sessions for this attempt
            $any_completed = $DB->get_record_sql(
                "SELECT * FROM {local_essaysmaster_sessions} 
                 WHERE attempt_id = ? AND user_id = ? AND final_submission_allowed = 1 
                 ORDER BY timemodified DESC LIMIT 1",
                [$attemptid, $USER->id]
            );
            
            if ($any_completed) {
                $completed_rounds = max($completed_rounds, 3);
                $final_submission_allowed = true;
                error_log("Essays Master: Found existing completed session for attempt $attemptid");
            }
        }
        
        error_log("Essays Master: State check for attempt $attemptid - rounds: $completed_rounds, final: " . ($final_submission_allowed ? 'yes' : 'no'));
        
        echo json_encode([
            "completed_rounds" => $completed_rounds,
            "session_exists" => $session ? true : false,
            "final_submission_allowed" => $final_submission_allowed,
            "session_id" => $session ? $session->id : null,
            "status" => $session ? $session->status : 'not_started'
        ]);
        exit;
    }
    
    // SIMPLIFIED: JavaScript handles all interception - PHP only provides state info
    echo json_encode([
        "should_intercept" => false,
        "message" => "JavaScript interception active",
        "sessions_complete" => true,
        "final_submission_allowed" => true,
        "note" => "All interception handled by quiz_interceptor.js"
    ]);
} catch (Exception $e) {
    error_log("Essays Master check_intercept error: " . $e->getMessage() . " - Trace: " . $e->getTraceAsString());
    echo json_encode([
        "error" => $e->getMessage(),
        "completed_rounds" => 0, // Safe fallback
        "sessions_complete" => false,
        "final_submission_allowed" => false
    ]);
}
?>