<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/essaysmaster:configquizzes', $context);

header('Content-Type: application/json');

try {
    $quiz_ids = required_param('quiz_ids', PARAM_RAW);
    $action = required_param('action', PARAM_ALPHA);
    
    // Parse quiz IDs (sent as array from JavaScript)
    if (is_string($quiz_ids)) {
        $quiz_ids = json_decode($quiz_ids);
    }
    
    if (!is_array($quiz_ids) || empty($quiz_ids)) {
        throw new moodle_exception('invalid_quiz_ids', 'local_essaysmaster');
    }
    
    // Validate action
    if (!in_array($action, ['enable', 'disable'])) {
        throw new moodle_exception('invalid_action', 'local_essaysmaster');
    }
    
    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $enabled = ($action === 'enable') ? 1 : 0;
    
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($quiz_ids as $quiz_id) {
        $quiz_id = (int)$quiz_id;
        if ($dashboard_manager->toggle_quiz_status($quiz_id, $enabled)) {
            $success_count++;
        } else {
            $failed_count++;
        }
    }
    
    if ($success_count > 0 && $failed_count === 0) {
        echo json_encode([
            'success' => true,
            'message' => get_string('bulk_action_success', 'local_essaysmaster', [
                'action' => $action,
                'count' => $success_count
            ])
        ]);
    } else if ($success_count > 0 && $failed_count > 0) {
        echo json_encode([
            'success' => true,
            'message' => get_string('bulk_action_partial', 'local_essaysmaster', [
                'action' => $action,
                'success' => $success_count,
                'failed' => $failed_count
            ])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => get_string('bulk_action_failed', 'local_essaysmaster')
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>