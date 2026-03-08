<?php
require_once(__DIR__.'/../../config.php');

$attemptid = required_param('id', PARAM_INT);
$clean = optional_param('clean', 0, PARAM_INT);

require_login();

global $DB, $PAGE, $OUTPUT;

// Fetch the grading record
$grading = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attemptid]);

if (!$grading) {
    echo "No feedback found for this attempt.";
    die();
}

// Ensure the page layout is minimal if clean=1
if ($clean) {
    $PAGE->set_pagelayout('embedded');
} else {
    $PAGE->set_pagelayout('admin');
}

$PAGE->set_url(new moodle_url('/local/quizdashboard/feedback_summary.php', ['id' => $attemptid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Feedback Summary');

echo $OUTPUT->header();

$feedback_json = $grading->feedback_json ?? '{}';
$data = json_decode($feedback_json, true);

if (!$data) {
    echo "<div class='alert alert-warning'>Feedback data could not be parsed.</div>";
    echo $OUTPUT->footer();
    die();
}

$top_priorities = $data['top_priorities'] ?? [];
$overall_comments = $data['overall_comments'] ?? '';

// Display logic here
echo "<div class='container mt-4 mb-4' style='font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; max-width: 800px;'>";

echo "<h2 style='color:#003366; font-size:24px; border-bottom:2px solid #003366; padding-bottom:10px; margin-bottom:20px;'>Top 4 Priorities for Improvement</h2>";

if (empty($top_priorities)) {
    echo "<p>No specific priorities were extracted from the feedback.</p>";
} else {
    echo "<ol style='padding-left: 20px;'>";
    foreach ($top_priorities as $priority) {
        $name = htmlspecialchars($priority['name'] ?? 'Priority');
        $explanation = htmlspecialchars($priority['explanation'] ?? '');
        echo "<li style='margin-bottom: 25px;'>";
        echo "<strong style='font-size: 16px; color:#c62828;'>{$name}:</strong> <span style='font-size: 15px;'>{$explanation}</span>";
        
        if (!empty($priority['examples']) && is_array($priority['examples'])) {
            echo "<ul style='list-style-type: none; padding-left: 15px; margin-top: 10px; border-left: 3px solid #e0e0e0;'>";
            foreach ($priority['examples'] as $idx => $ex) {
                echo "<li style='margin-bottom: 10px; font-size: 14px;'>";
                if (isset($ex['original']) && isset($ex['improved'])) {
                    echo "<div style='color: #6c757d; margin-bottom:4px;'><strong>Original " . ($idx + 1) . ":</strong> " . htmlspecialchars($ex['original']) . "</div>";
                    echo "<div style='color: #0d6efd;'><strong>Improved " . ($idx + 1) . ":</strong> " . htmlspecialchars($ex['improved']) . "</div>";
                } elseif (isset($ex['text'])) {
                    echo "<div style='color: #0d6efd;'><strong>Example " . ($idx + 1) . ":</strong> " . htmlspecialchars($ex['text']) . "</div>";
                }
                echo "</li>";
            }
            echo "</ul>";
        }
        echo "</li>";
    }
    echo "</ol>";
}

echo "<h2 style='color:#003366; font-size:24px; border-bottom:2px solid #003366; padding-bottom:10px; margin-top:40px; margin-bottom:20px;'>Overall Comments (Writing Journey)</h2>";

if (empty($overall_comments)) {
    echo "<p>No overall comments were found.</p>";
} else {
    // Basic logic to decide if warning (simplified since we only have the text here)
    // If it contains warning words or is red in the original output it might be a warning.
    // For now, we'll just output it nicely.
    
    // Check if the comment hints at poor progress (for styling)
    $is_warning = preg_match('/(?:did not|minimal|little|no)\\s+(?:improve|progress|change)/i', $overall_comments) || 
                  preg_match('/(?:copy|copied)/i', $overall_comments);
                  
    if ($is_warning) {
        echo "<div style='background-color: #fbe9e7; padding: 20px; border-radius: 8px; border-left: 5px solid #d32f2f; margin-bottom: 20px;'>";
        echo "<p style='color: #c62828; font-size: 16px; margin: 0; line-height: 1.6; font-weight: 500;'>";
        echo "⚠️ " . nl2br(htmlspecialchars($overall_comments));
        echo "</p></div>";
    } else {
        echo "<div style='background-color: #e3f2fd; padding: 20px; border-radius: 8px; border-left: 5px solid #1976d2; margin-bottom: 20px;'>";
        echo "<p style='color: #1565c0; font-size: 16px; margin: 0; line-height: 1.6;'>";
        echo nl2br(htmlspecialchars($overall_comments));
        echo "</p></div>";
    }
}

echo "</div>";

if ($clean) {
    // Add simple close button for popups
    echo "<div style='text-align:center; margin-top:30px; margin-bottom:30px;'>";
    echo "<button onclick='window.close()' style='padding:8px 20px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer;'>Close Window</button>";
    echo "</div>";
}

echo $OUTPUT->footer();
