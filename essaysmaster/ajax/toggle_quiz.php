<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/essaysmaster:configquizzes', $context);

header('Content-Type: application/json');

try {
    $quiz_id = required_param('quiz_id', PARAM_INT);
    $enabled = required_param('enabled', PARAM_INT);
    
    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $success = $dashboard_manager->toggle_quiz_status($quiz_id, $enabled);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => $enabled ? 
                get_string('quiz_enabled_success', 'local_essaysmaster') :
                get_string('quiz_disabled_success', 'local_essaysmaster')
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => get_string('quiz_toggle_failed', 'local_essaysmaster')
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>