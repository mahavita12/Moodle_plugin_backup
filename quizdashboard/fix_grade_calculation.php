<?php
/**
 * Fix Grade Calculation - Simple Manual Fix - added
 * Manually calculates and updates quiz sumgrades for attempts with null sumgrades
 */

require_once(__DIR__ . '/../../config.php');
require_login();

// Set up context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/quizdashboard/fix_grade_calculation.php');
$PAGE->set_title('Fix Grade Calculation');
$PAGE->set_heading('Fix Grade Calculation');

// Check if user has permission
require_capability('local/quizdashboard:view', context_system::instance());

echo $OUTPUT->header();

$attempt_id = optional_param('attempt_id', 0, PARAM_INT);
$fix_all = optional_param('fix_all', 0, PARAM_BOOL);

echo html_writer::tag('h2', 'Fix Grade Calculation');

if ($attempt_id == 0 && !$fix_all) {
    echo html_writer::tag('p', 'Choose an option:');
    
    // Show attempts with null sumgrades
    echo html_writer::tag('h3', 'Attempts with NULL sumgrades:');
    
    $null_attempts = $DB->get_records_sql("
        SELECT qa.id, qa.quiz, qa.userid, qa.state, qa.sumgrades, qa.uniqueid, 
               u.firstname, u.lastname, q.name as quiz_name
        FROM {quiz_attempts} qa 
        JOIN {user} u ON u.id = qa.userid 
        JOIN {quiz} q ON q.id = qa.quiz 
        WHERE qa.sumgrades IS NULL AND qa.state = 'finished'
        ORDER BY qa.id DESC 
        LIMIT 20
    ");
    
    if (empty($null_attempts)) {
        echo html_writer::tag('p', 'No attempts found with NULL sumgrades.');
    } else {
        echo html_writer::start_tag('ul');
        foreach ($null_attempts as $attempt) {
            $link = new moodle_url('/local/quizdashboard/fix_grade_calculation.php', ['attempt_id' => $attempt->id]);
            echo html_writer::tag('li', 
                html_writer::link($link, "Attempt {$attempt->id} - {$attempt->quiz_name} by {$attempt->firstname} {$attempt->lastname}")
            );
        }
        echo html_writer::end_tag('ul');
        
        // Fix all button
        $fix_all_url = new moodle_url('/local/quizdashboard/fix_grade_calculation.php', ['fix_all' => 1]);
        echo html_writer::tag('div', 
            html_writer::link($fix_all_url, 'Fix All Attempts', 
                ['class' => 'btn btn-warning', 'style' => 'margin: 10px 0;']
            ),
            ['style' => 'margin: 15px 0;']
        );
    }
    
} elseif ($fix_all) {
    // Fix all attempts with null sumgrades
    echo html_writer::tag('h3', 'Fixing All Attempts with NULL sumgrades...');
    
    $null_attempts = $DB->get_records_sql("
        SELECT qa.id, qa.quiz, qa.userid, qa.state, qa.sumgrades, qa.uniqueid
        FROM {quiz_attempts} qa 
        WHERE qa.sumgrades IS NULL AND qa.state = 'finished'
        ORDER BY qa.id DESC 
        LIMIT 50
    ");
    
    $fixed_count = 0;
    $error_count = 0;
    
    foreach ($null_attempts as $attempt) {
        try {
            $result = fix_attempt_grade($attempt->id);
            if ($result['success']) {
                $fixed_count++;
                echo html_writer::tag('p', "<span style='color:green;'>✓ Fixed attempt {$attempt->id}: {$result['message']}</span>");
            } else {
                $error_count++;
                echo html_writer::tag('p', "<span style='color:red;'>✗ Failed attempt {$attempt->id}: {$result['message']}</span>");
            }
        } catch (Exception $e) {
            $error_count++;
            echo html_writer::tag('p', "<span style='color:red;'>✗ Error with attempt {$attempt->id}: " . $e->getMessage() . "</span>");
        }
    }
    
    echo html_writer::tag('h4', "Summary: Fixed {$fixed_count} attempts, {$error_count} errors");
    
} else {
    // Fix specific attempt
    echo html_writer::tag('h3', "Fixing Attempt ID: {$attempt_id}");
    
    try {
        $result = fix_attempt_grade($attempt_id);
        if ($result['success']) {
            echo html_writer::tag('p', "<span style='color:green; font-size:16px;'><strong>✓ SUCCESS: " . $result['message'] . "</strong></span>");
        } else {
            echo html_writer::tag('p', "<span style='color:red; font-size:16px;'><strong>✗ FAILED: " . $result['message'] . "</strong></span>");
        }
        
        if (isset($result['details'])) {
            echo html_writer::tag('h4', 'Details:');
            echo html_writer::tag('pre', $result['details']);
        }
        
    } catch (Exception $e) {
        echo html_writer::tag('p', "<span style='color:red;'>Error: " . $e->getMessage() . "</span>");
        echo html_writer::tag('pre', $e->getTraceAsString());
    }
}

/**
 * Fix grade calculation for a specific attempt
 */
function fix_attempt_grade($attempt_id) {
    global $DB, $CFG;
    
    require_once($CFG->dirroot . '/question/engine/lib.php');
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    
    // Get attempt
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attempt_id]);
    if (!$attempt) {
        return ['success' => false, 'message' => 'Attempt not found'];
    }
    
    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
    if (!$quiz) {
        return ['success' => false, 'message' => 'Quiz not found'];
    }
    
    $details = "Original sumgrades: " . ($attempt->sumgrades ?? 'NULL') . "\n";
    
    try {
        // Load question usage
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $total_mark = $quba->get_total_mark();
        $max_mark = $quba->get_max_mark();
        
        $details .= "Question Usage Total Mark: $total_mark\n";
        $details .= "Question Usage Max Mark: $max_mark\n";
        
        if ($total_mark === null) {
            return ['success' => false, 'message' => 'No grades found in question usage', 'details' => $details];
        }
        
        // Update the attempt record directly
        $update_success = $DB->set_field('quiz_attempts', 'sumgrades', $total_mark, ['id' => $attempt_id]);
        $details .= "Database update result: " . ($update_success ? 'SUCCESS' : 'FAILED') . "\n";
        
        if (!$update_success) {
            return ['success' => false, 'message' => 'Database update failed', 'details' => $details];
        }
        
        // Verify the update
        $updated_attempt = $DB->get_record('quiz_attempts', ['id' => $attempt_id]);
        $details .= "New sumgrades: " . ($updated_attempt->sumgrades ?? 'NULL') . "\n";
        
        // Update gradebook
        try {
            quiz_update_grades($quiz, $attempt->userid);
            $details .= "Gradebook update: SUCCESS\n";
        } catch (Exception $e) {
            $details .= "Gradebook update: FAILED - " . $e->getMessage() . "\n";
        }
        
        return [
            'success' => true, 
            'message' => "Updated sumgrades from NULL to $total_mark",
            'details' => $details
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false, 
            'message' => 'Exception: ' . $e->getMessage(),
            'details' => $details . "\nException: " . $e->getMessage()
        ];
    }
}

echo $OUTPUT->footer();
