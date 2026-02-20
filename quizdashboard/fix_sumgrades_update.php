<?php
/**
 * Fix sumgrades update for essays that have been graded but don't have sumgrades properly updated
 */

require_once('../../config.php');
require_login();
require_capability('local/quizdashboard:view', context_system::instance());

$PAGE->set_url('/local/quizdashboard/fix_sumgrades_update.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fix Sumgrades Update');
$PAGE->set_heading('Fix Sumgrades Update for Graded Essays');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

echo '<h2>Fix Sumgrades Update for Graded Essays</h2>';
echo '<p>This tool will update quiz_attempts.sumgrades for essays that have been graded but don\'t have proper sumgrades values.</p>';

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'fix') {
    echo '<h3>Processing...</h3>';
    
    // Get all graded essays that don't have proper sumgrades
    $sql = "SELECT g.attempt_id, g.feedback_html, qa.sumgrades, qa.quiz, qa.userid
            FROM {local_quizdashboard_gradings} g
            JOIN {quiz_attempts} qa ON qa.id = g.attempt_id
            WHERE qa.sumgrades IS NULL 
               OR qa.sumgrades = 0
            ORDER BY g.attempt_id";
    
    $graded_attempts = $DB->get_records_sql($sql);
    
    $updated_count = 0;
    $error_count = 0;
    
    echo '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
    
    foreach ($graded_attempts as $graded) {
        echo "<p><strong>Processing Attempt {$graded->attempt_id}:</strong> ";
        
        // Extract grade from feedback HTML
        if (preg_match('/<strong>Final Score:\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)<\/strong>/i', $graded->feedback_html, $matches)) {
            $score = (float) $matches[1];
            $max_score = (float) $matches[2];
            
            if ($max_score > 0) {
                // Update quiz_attempts.sumgrades with the extracted score
                $success = $DB->set_field('quiz_attempts', 'sumgrades', $score, ['id' => $graded->attempt_id]);
                
                if ($success) {
                    echo "<span style='color: green;'>✅ Updated sumgrades to {$score}/{$max_score}</span>";
                    $updated_count++;
                } else {
                    echo "<span style='color: red;'>❌ Failed to update database</span>";
                    $error_count++;
                }
            } else {
                echo "<span style='color: orange;'>⚠️ Max score is 0, skipping</span>";
            }
        } else {
            echo "<span style='color: red;'>❌ Could not extract grade from feedback</span>";
            $error_count++;
        }
        
        echo "</p>";
        
        // Flush output for real-time display
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    echo '</div>';
    
    echo "<div style='background: #e8f5e8; border: 1px solid #4caf50; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    echo "<h4>Summary:</h4>";
    echo "<p><strong>Total processed:</strong> " . count($graded_attempts) . "</p>";
    echo "<p><strong>Successfully updated:</strong> <span style='color: green;'>{$updated_count}</span></p>";
    echo "<p><strong>Errors:</strong> <span style='color: red;'>{$error_count}</span></p>";
    echo "</div>";
    
    if ($updated_count > 0) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
        echo "<p style='margin: 0;'><strong>Success!</strong> The dashboard should now properly display the grades for updated attempts.</p>";
        echo "</div>";
    }
    
} else {
    // Show current status
    $sql = "SELECT 
                COUNT(*) as total_graded,
                SUM(CASE WHEN qa.sumgrades IS NULL THEN 1 ELSE 0 END) as null_sumgrades,
                SUM(CASE WHEN qa.sumgrades = 0 THEN 1 ELSE 0 END) as zero_sumgrades
            FROM {local_quizdashboard_gradings} g
            JOIN {quiz_attempts} qa ON qa.id = g.attempt_id";
    
    $status = $DB->get_record_sql($sql);
    
    echo '<div style="background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 5px; margin: 20px 0;">';
    echo '<h4>Current Status:</h4>';
    echo '<ul>';
    echo '<li><strong>Total graded essays:</strong> ' . $status->total_graded . '</li>';
    echo '<li><strong>With null sumgrades:</strong> <span style="color: red;">' . $status->null_sumgrades . '</span></li>';
    echo '<li><strong>With zero sumgrades:</strong> <span style="color: orange;">' . $status->zero_sumgrades . '</span></li>';
    echo '</ul>';
    echo '</div>';
    
    if ($status->null_sumgrades > 0 || $status->zero_sumgrades > 0) {
        echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 5px; margin: 10px 0;">';
        echo '<p><strong>Issues found:</strong> Some graded essays don\'t have proper sumgrades values in the quiz_attempts table.</p>';
        echo '<p>This causes the dashboard to show "-" instead of the actual grades.</p>';
        echo '</div>';
        
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="fix">';
        echo '<input type="submit" value="Fix Sumgrades Update" class="btn btn-primary" onclick="return confirm(\'This will update the sumgrades field for all affected attempts. Continue?\');">';
        echo '</form>';
    } else {
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 5px;">';
        echo '<p style="margin: 0;"><strong>✅ All good!</strong> All graded essays have proper sumgrades values.</p>';
        echo '</div>';
    }
}

echo '<div style="margin: 20px 0;">';
echo '<a href="index.php" class="btn btn-secondary">← Back to Dashboard</a>';
echo '</div>';

echo $OUTPUT->footer();
?>