<?php
/**
 * AI Integration Testing Script for Essays Master
 * 
 * This script helps test whether essays are correctly being sent to AI for all 6 rounds
 * Run from: /local/essaysmaster/test_ai_integration.php
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Require admin login for security
require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', 'overview', PARAM_ALPHA);
$attemptid = optional_param('attemptid', 0, PARAM_INT);

echo '<html><head><title>Essays Master AI Integration Testing</title>';
echo '<style>
body { font-family: Arial; margin: 20px; }
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
.warning { color: orange; font-weight: bold; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
.test-section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
</style></head><body>';

echo '<h1>Essays Master AI Integration Testing</h1>';

// Navigation
echo '<div style="margin-bottom: 20px;">';
echo '<a href="?action=overview">Overview</a> | ';
echo '<a href="?action=test_config">Test Configuration</a> | ';
echo '<a href="?action=test_database">Database Status</a> | ';
echo '<a href="?action=test_ai">Test AI Connection</a> | ';
echo '<a href="?action=logs">Recent Logs</a>';
echo '</div>';

switch ($action) {
    case 'overview':
        show_overview();
        break;
    case 'test_config':
        test_configuration();
        break;
    case 'test_database':
        test_database_status();
        break;
    case 'test_ai':
        test_ai_connection();
        break;
    case 'logs':
        show_recent_logs();
        break;
    default:
        show_overview();
}

echo '</body></html>';

function show_overview() {
    global $DB;
    
    echo '<div class="test-section">';
    echo '<h2>System Overview</h2>';
    
    // Count sessions and feedback
    $sessions_count = $DB->count_records('local_essaysmaster_sessions');
    $feedback_count = $DB->count_records('local_essaysmaster_feedback');
    
    echo "<p><strong>Total Sessions:</strong> $sessions_count</p>";
    echo "<p><strong>Total Feedback Records:</strong> $feedback_count</p>";
    
    // Recent activity
    $recent_sessions = $DB->get_records_sql("
        SELECT s.*, u.firstname, u.lastname 
        FROM {local_essaysmaster_sessions} s
        JOIN {user} u ON u.id = s.user_id
        ORDER BY s.timecreated DESC 
        LIMIT 10
    ");
    
    if ($recent_sessions) {
        echo '<h3>Recent Sessions</h3>';
        echo '<table>';
        echo '<tr><th>ID</th><th>User</th><th>Attempt ID</th><th>Current Level</th><th>Completed Rounds</th><th>Status</th><th>Created</th></tr>';
        
        foreach ($recent_sessions as $session) {
            $created = date('Y-m-d H:i:s', $session->timecreated);
            echo "<tr>";
            echo "<td>{$session->id}</td>";
            echo "<td>{$session->firstname} {$session->lastname}</td>";
            echo "<td><a href='?action=test_database&attemptid={$session->attempt_id}'>{$session->attempt_id}</a></td>";
            echo "<td>{$session->current_level}</td>";
            echo "<td>{$session->feedback_rounds_completed}</td>";
            echo "<td>{$session->status}</td>";
            echo "<td>{$created}</td>";
            echo "</tr>";
        }
        echo '</table>';
    }
    
    echo '</div>';
}

function test_configuration() {
    global $CFG;
    
echo '<div class="test-section">';
echo '<h2>Configuration Testing</h2>';
    
    // Check if debug logging is enabled
    $debug_enabled = $CFG->debug == (E_ALL | E_STRICT);
    echo '<p><strong>Debug Logging:</strong> ' . ($debug_enabled ? 
        '<span class="success">Enabled</span>' : 
        '<span class="warning">Disabled - Enable for better testing</span>') . '</p>';
    
    // Essays Master provider and API keys
    $provider = get_config('local_essaysmaster', 'provider') ?: 'anthropic';
    $openai_key = get_config('local_essaysmaster', 'openai_apikey');
    $anthropic_key = get_config('local_essaysmaster', 'anthropic_apikey');
    $openai_model = get_config('local_essaysmaster', 'openai_model') ?: 'gpt-4o';
    $anthropic_model = get_config('local_essaysmaster', 'anthropic_model') ?: 'sonnet-4';

    echo '<p><strong>Provider:</strong> ' . htmlspecialchars($provider) . '</p>';
    echo '<p><strong>Anthropic Key:</strong> ' . ($anthropic_key ? '<span class="success">Configured</span>' : '<span class="warning">Not configured</span>') . '</p>';
    echo '<p><strong>Anthropic Model:</strong> ' . htmlspecialchars($anthropic_model) . '</p>';
    echo '<p><strong>OpenAI Key:</strong> ' . ($openai_key ? '<span class="success">Configured</span>' : '<span class="warning">Not configured</span>') . '</p>';
    echo '<p><strong>OpenAI Model:</strong> ' . htmlspecialchars($openai_model) . '</p>';
    
    // Check if Quiz Dashboard is available
    $quizdashboard_available = file_exists($CFG->dirroot . '/local/quizdashboard/classes/essay_grader.php');
    echo '<p><strong>Quiz Dashboard Integration:</strong> ' . ($quizdashboard_available ? 
        '<span class="success">Available</span>' : 
        '<span class="warning">Not available</span>') . '</p>';
    
    // Check file permissions
    $log_dir = $CFG->dataroot . '/log';
    $writable = is_writable($log_dir);
    echo '<p><strong>Log Directory Writable:</strong> ' . ($writable ? 
        '<span class="success">Yes</span>' : 
        '<span class="error">No</span>') . '</p>';
    
    echo '</div>';
}

function test_database_status() {
    global $DB;
    
    $attemptid = optional_param('attemptid', 0, PARAM_INT);
    
    echo '<div class="test-section">';
    echo '<h2>Database Status</h2>';
    
    if ($attemptid > 0) {
        echo "<h3>Details for Attempt ID: $attemptid</h3>";
        
        // Session details
        $session = $DB->get_record('local_essaysmaster_sessions', ['attempt_id' => $attemptid]);
        if ($session) {
            echo '<h4>Session Information</h4>';
            echo '<table>';
            echo '<tr><th>Field</th><th>Value</th></tr>';
            echo "<tr><td>Session ID</td><td>{$session->id}</td></tr>";
            echo "<tr><td>User ID</td><td>{$session->user_id}</td></tr>";
            echo "<tr><td>Current Level</td><td>{$session->current_level}</td></tr>";
            echo "<tr><td>Rounds Completed</td><td>{$session->feedback_rounds_completed}</td></tr>";
            echo "<tr><td>Status</td><td>{$session->status}</td></tr>";
            echo "<tr><td>Max Level</td><td>{$session->max_level}</td></tr>";
            echo "<tr><td>Final Submission Allowed</td><td>" . ($session->final_submission_allowed ? 'Yes' : 'No') . "</td></tr>";
            echo '</table>';
            
            // Feedback records
            $feedback_records = $DB->get_records('local_essaysmaster_feedback', 
                ['version_id' => $attemptid], 'level_type');
            
            echo '<h4>Feedback Records</h4>';
            if ($feedback_records) {
                echo '<table>';
                echo '<tr><th>Round</th><th>Feedback Length</th><th>Score</th><th>Generated Time</th><th>API Response Time</th></tr>';
                
                foreach ($feedback_records as $feedback) {
                    $round = str_replace('round_', '', $feedback->level_type);
                    $feedback_length = strlen($feedback->feedback_html);
                    $generated = date('Y-m-d H:i:s', $feedback->feedback_generated_time);
                    
                    echo "<tr>";
                    echo "<td>$round</td>";
                    echo "<td>$feedback_length chars</td>";
                    echo "<td>{$feedback->completion_score}</td>";
                    echo "<td>$generated</td>";
                    echo "<td>{$feedback->api_response_time}s</td>";
                    echo "</tr>";
                }
                echo '</table>';
                
                // Show round coverage
                $rounds_with_feedback = [];
                foreach ($feedback_records as $feedback) {
                    $rounds_with_feedback[] = str_replace('round_', '', $feedback->level_type);
                }
                
                echo '<h4>Round Coverage Analysis</h4>';
                for ($i = 1; $i <= 6; $i++) {
                    $has_feedback = in_array($i, $rounds_with_feedback);
                    $status = $has_feedback ? '<span class="success">✓</span>' : '<span class="error">✗</span>';
                    echo "<p>Round $i: $status</p>";
                }
            } else {
                echo '<p class="warning">No feedback records found</p>';
            }
        } else {
            echo '<p class="error">No session found for this attempt ID</p>';
        }
    }
    
    // Overall statistics
    echo '<h3>Overall Statistics</h3>';
    
    // Feedback by round
    $round_stats = $DB->get_records_sql("
        SELECT level_type, COUNT(*) as count
        FROM {local_essaysmaster_feedback}
        GROUP BY level_type
        ORDER BY level_type
    ");
    
    if ($round_stats) {
        echo '<table>';
        echo '<tr><th>Round</th><th>Feedback Count</th></tr>';
        foreach ($round_stats as $stat) {
            echo "<tr><td>{$stat->level_type}</td><td>{$stat->count}</td></tr>";
        }
        echo '</table>';
    }
    
    echo '</div>';
}

function test_ai_connection() {
    echo '<div class="test-section">';
    echo '<h2>AI Connection Testing</h2>';
    
    try {
        require_once(__DIR__ . '/classes/ai_helper.php');
        $ai_helper = new \local_essaysmaster\ai_helper();
        
        echo '<p>Testing AI connection with sample text...</p>';
        
        $test_text = "This is a test essay with some grammar errors and basic vocabulary. The student wrote this text to see if the AI system works correctly.";
        
        // Test feedback generation (round 1)
        echo '<h4>Testing Feedback Generation (Round 1)</h4>';
        echo '<p><em>Provider:</em> ' . htmlspecialchars(get_config('local_essaysmaster','provider') ?: 'anthropic') . ', ' .
             '<em>Model:</em> ' . htmlspecialchars(get_config('local_essaysmaster','anthropic_model') ?: 'claude-3-5-sonnet-latest') . '</p>';
        $feedback_result = $ai_helper->generate_feedback(1, $test_text, 'Sample question prompt');
        
        if ($feedback_result['success']) {
            echo '<p class="success">✓ Feedback generation successful</p>';
            echo '<p><strong>Response length:</strong> ' . strlen($feedback_result['feedback']) . ' characters</p>';
            echo '<details><summary>Show response</summary><pre>' . htmlspecialchars($feedback_result['feedback']) . '</pre></details>';
        } else {
            echo '<p class="error">✗ Feedback generation failed: ' . $feedback_result['message'] . '</p>';
        }
        
        // Test validation (round 2)
        echo '<h4>Testing Validation (Round 2)</h4>';
        $original_text = "This is a test essay with some errors.";
        $revised_text = "This is a test essay with fewer errors and better grammar.";
        
        $validation_result = $ai_helper->generate_validation(2, $original_text, $revised_text, 'Sample question');
        
        if ($validation_result['success']) {
            echo '<p class="success">✓ Validation successful</p>';
            echo '<p><strong>Score:</strong> ' . $validation_result['score'] . '</p>';
            echo '<p><strong>Analysis:</strong> ' . htmlspecialchars($validation_result['analysis']) . '</p>';
        } else {
            echo '<p class="error">✗ Validation failed: ' . $validation_result['message'] . '</p>';
        }
        
    } catch (Exception $e) {
        echo '<p class="error">✗ AI connection test failed: ' . $e->getMessage() . '</p>';
    }
    
    echo '</div>';
}

function show_recent_logs() {
    global $CFG;
    
    echo '<div class="test-section">';
    echo '<h2>Recent Essays Master Logs</h2>';
    
    // Try to read from error log
    $log_files = [
        $CFG->dataroot . '/log/apache_error.log',
        $CFG->dataroot . '/log/error.log',
        '/var/log/apache2/error.log',
        '/var/log/httpd/error_log'
    ];
    
    $found_logs = false;
    
    foreach ($log_files as $log_file) {
        if (file_exists($log_file) && is_readable($log_file)) {
            echo "<h4>Reading from: $log_file</h4>";
            
            // Get last 100 lines and filter for Essays Master
            $command = "tail -n 100 " . escapeshellarg($log_file) . " | grep -i 'essays master'";
            $output = shell_exec($command);
            
            if ($output) {
                $lines = explode("\n", trim($output));
                $lines = array_reverse(array_slice($lines, -20)); // Last 20 relevant lines
                
                echo '<pre style="max-height: 300px; overflow-y: auto;">';
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        // Highlight different types of messages
                        if (strpos($line, '🤖') !== false || strpos($line, 'AI Helper') !== false) {
                            echo '<span style="color: blue;">' . htmlspecialchars($line) . '</span>' . "\n";
                        } elseif (strpos($line, '✅') !== false || strpos($line, 'succeeded') !== false) {
                            echo '<span style="color: green;">' . htmlspecialchars($line) . '</span>' . "\n";
                        } elseif (strpos($line, '🚨') !== false || strpos($line, 'failed') !== false || strpos($line, 'error') !== false) {
                            echo '<span style="color: red;">' . htmlspecialchars($line) . '</span>' . "\n";
                        } else {
                            echo htmlspecialchars($line) . "\n";
                        }
                    }
                }
                echo '</pre>';
                $found_logs = true;
                break;
            }
        }
    }
    
    if (!$found_logs) {
        echo '<p class="warning">No accessible log files found. Check the following:</p>';
        echo '<ul>';
        echo '<li>Enable debug logging in config.php</li>';
        echo '<li>Ensure log directory is writable</li>';
        echo '<li>Check file permissions</li>';
        echo '</ul>';
        
        echo '<h4>Suggested config.php settings for testing:</h4>';
        echo '<pre>';
        echo '$CFG->debug = (E_ALL | E_STRICT);' . "\n";
        echo '$CFG->debugdisplay = 1;' . "\n";
        echo '$CFG->debugsmtp = true;';
        echo '</pre>';
    }
    
    echo '</div>';
}

?>
