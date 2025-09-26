#!/usr/bin/env php
<?php
/**
 * Command-line AI Integration Test for Essays Master
 * 
 * Usage: php test_ai_cli.php [attempt_id]
 * 
 * This script tests the AI integration by simulating the 6-round process
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Check if running from command line
if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
    die("This script must be run from command line\n");
}

$attempt_id = isset($argv[1]) ? (int)$argv[1] : 0;

echo "Essays Master AI Integration Testing\n";
echo "====================================\n\n";

if ($attempt_id > 0) {
    echo "Testing specific attempt ID: $attempt_id\n\n";
    test_specific_attempt($attempt_id);
} else {
    echo "Running general system tests\n\n";
    run_general_tests();
}

function run_general_tests() {
    echo "1. Configuration Check\n";
    echo "----------------------\n";
    test_configuration();
    
    echo "\n2. Database Status\n";
    echo "------------------\n";
    test_database_status();
    
    echo "\n3. AI Connection Test\n";
    echo "---------------------\n";
    test_ai_connection();
    
    echo "\n4. Recent Activity\n";
    echo "------------------\n";
    show_recent_activity();
}

function test_specific_attempt($attempt_id) {
    global $DB;
    
    echo "Testing Attempt ID: $attempt_id\n\n";
    
    // Get session info
    $session = $DB->get_record('local_essaysmaster_sessions', ['attempt_id' => $attempt_id]);
    if (!$session) {
        echo "❌ No session found for attempt $attempt_id\n";
        return;
    }
    
    echo "✅ Session found (ID: {$session->id})\n";
    echo "   User: {$session->user_id}\n";
    echo "   Current Level: {$session->current_level}\n";
    echo "   Completed Rounds: {$session->feedback_rounds_completed}\n";
    echo "   Status: {$session->status}\n\n";
    
    // Check feedback for each round
    echo "Round-by-Round Analysis:\n";
    echo "------------------------\n";
    
    for ($round = 1; $round <= 6; $round++) {
        $feedback = $DB->get_record('local_essaysmaster_feedback', [
            'version_id' => $attempt_id,
            'level_type' => "round_$round"
        ]);
        
        $round_type = in_array($round, [1, 3, 5]) ? 'Feedback' : 'Validation';
        
        if ($feedback) {
            $feedback_length = strlen($feedback->feedback_html);
            $score = $feedback->completion_score;
            $response_time = $feedback->api_response_time;
            $generated = date('Y-m-d H:i:s', $feedback->feedback_generated_time);
            
            echo "✅ Round $round ($round_type): ";
            echo "$feedback_length chars, Score: $score, Time: {$response_time}s, Generated: $generated\n";
            
            // Check for potential issues
            if ($feedback_length < 50) {
                echo "   ⚠️  Short feedback (possible error)\n";
            }
            if ($response_time > 10) {
                echo "   ⚠️  Slow response time\n";
            }
            if ($score < 20) {
                echo "   ⚠️  Very low score\n";
            }
        } else {
            echo "❌ Round $round ($round_type): No feedback found\n";
        }
    }
    
    // Overall assessment
    $feedback_count = $DB->count_records('local_essaysmaster_feedback', ['version_id' => $attempt_id]);
    echo "\nOverall Assessment:\n";
    echo "-------------------\n";
    echo "Total feedback records: $feedback_count/6\n";
    
    if ($feedback_count == 6) {
        echo "✅ All rounds completed - AI integration working correctly\n";
    } elseif ($feedback_count >= 3) {
        echo "⚠️  Partial completion - Some rounds missing\n";
    } else {
        echo "❌ Incomplete - Most rounds missing\n";
    }
}

function test_configuration() {
    global $CFG;
    
    // Check debug logging
    $debug_enabled = isset($CFG->debug) && $CFG->debug == (E_ALL | E_STRICT);
    echo ($debug_enabled ? "✅" : "⚠️ ") . " Debug logging: " . ($debug_enabled ? "Enabled" : "Disabled") . "\n";
    
    // Check API key
    $api_key = get_config('local_quizdashboard', 'openai_api_key');
    if (!empty($api_key)) {
        $masked_key = substr($api_key, 0, 7) . '...' . substr($api_key, -4);
        echo "✅ OpenAI API Key: Configured ($masked_key)\n";
    } else {
        echo "❌ OpenAI API Key: Not configured\n";
    }
    
    // Check Quiz Dashboard
    $quizdashboard_available = file_exists($CFG->dirroot . '/local/quizdashboard/classes/essay_grader.php');
    echo ($quizdashboard_available ? "✅" : "⚠️ ") . " Quiz Dashboard: " . ($quizdashboard_available ? "Available" : "Not available") . "\n";
    
    // Check file permissions
    $log_dir = $CFG->dataroot . '/log';
    $writable = is_writable($log_dir);
    echo ($writable ? "✅" : "❌") . " Log directory: " . ($writable ? "Writable" : "Not writable") . "\n";
}

function test_database_status() {
    global $DB;
    
    // Count records
    $sessions = $DB->count_records('local_essaysmaster_sessions');
    $feedback = $DB->count_records('local_essaysmaster_feedback');
    
    echo "Total sessions: $sessions\n";
    echo "Total feedback records: $feedback\n";
    
    if ($sessions > 0 && $feedback > 0) {
        $avg_feedback_per_session = round($feedback / $sessions, 2);
        echo "Average feedback per session: $avg_feedback_per_session\n";
        
        if ($avg_feedback_per_session >= 5.5) {
            echo "✅ Good feedback coverage\n";
        } elseif ($avg_feedback_per_session >= 3) {
            echo "⚠️  Moderate feedback coverage\n";
        } else {
            echo "❌ Low feedback coverage\n";
        }
    }
    
    // Check for recent activity
    $recent_feedback = $DB->get_record_sql("
        SELECT MAX(feedback_generated_time) as latest 
        FROM {local_essaysmaster_feedback}
    ");
    
    if ($recent_feedback && $recent_feedback->latest) {
        $hours_ago = (time() - $recent_feedback->latest) / 3600;
        echo "Latest feedback: " . round($hours_ago, 1) . " hours ago\n";
        
        if ($hours_ago < 24) {
            echo "✅ Recent activity detected\n";
        } else {
            echo "⚠️  No recent activity\n";
        }
    } else {
        echo "❌ No feedback records found\n";
    }
}

function test_ai_connection() {
    try {
        require_once(__DIR__ . '/classes/ai_helper.php');
        $ai_helper = new \local_essaysmaster\ai_helper();
        
        echo "Testing AI connection...\n";
        
        $test_text = "This is a test essay with some errors and basic vocabulary to test the AI system.";
        
        // Test feedback generation
        $start_time = microtime(true);
        $result = $ai_helper->generate_feedback(1, $test_text);
        $response_time = microtime(true) - $start_time;
        
        if ($result['success']) {
            echo "✅ AI feedback test successful\n";
            echo "   Response time: " . round($response_time, 2) . " seconds\n";
            echo "   Response length: " . strlen($result['feedback']) . " characters\n";
        } else {
            echo "❌ AI feedback test failed: " . $result['message'] . "\n";
        }
        
        // Test validation
        $start_time = microtime(true);
        $validation = $ai_helper->generate_validation(2, $test_text, $test_text . " Improved version.", "Sample question");
        $response_time = microtime(true) - $start_time;
        
        if ($validation['success']) {
            echo "✅ AI validation test successful\n";
            echo "   Response time: " . round($response_time, 2) . " seconds\n";
            echo "   Score: " . $validation['score'] . "\n";
        } else {
            echo "❌ AI validation test failed: " . $validation['message'] . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ AI connection test failed: " . $e->getMessage() . "\n";
    }
}

function show_recent_activity() {
    global $DB;
    
    $recent_sessions = $DB->get_records_sql("
        SELECT s.*, u.username
        FROM {local_essaysmaster_sessions} s
        JOIN {user} u ON u.id = s.user_id
        WHERE s.timecreated > ?
        ORDER BY s.timecreated DESC
        LIMIT 10
    ", [time() - (7 * 24 * 3600)]); // Last 7 days
    
    if ($recent_sessions) {
        echo "Recent sessions (last 7 days):\n";
        foreach ($recent_sessions as $session) {
            $created = date('M j H:i', $session->timecreated);
            echo "  Attempt {$session->attempt_id} ({$session->username}): Level {$session->current_level}, {$session->feedback_rounds_completed} rounds, {$session->status} - $created\n";
        }
    } else {
        echo "No recent sessions found\n";
    }
    
    // Check for common issues
    $issues = $DB->get_records_sql("
        SELECT 'Empty feedback' as issue, COUNT(*) as count
        FROM {local_essaysmaster_feedback}
        WHERE feedback_html IS NULL OR LENGTH(feedback_html) < 50
        
        UNION ALL
        
        SELECT 'Slow responses' as issue, COUNT(*) as count
        FROM {local_essaysmaster_feedback}
        WHERE api_response_time > 10
        
        UNION ALL
        
        SELECT 'Low scores' as issue, COUNT(*) as count
        FROM {local_essaysmaster_feedback}
        WHERE completion_score < 20
    ");
    
    if ($issues) {
        echo "\nPotential issues:\n";
        foreach ($issues as $issue) {
            if ($issue->count > 0) {
                echo "  ⚠️  {$issue->issue}: {$issue->count} occurrences\n";
            }
        }
    }
}

echo "\nTest completed.\n";
?>
