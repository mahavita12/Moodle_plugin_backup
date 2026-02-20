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

    if (is_string($quiz_ids)) {
        $decoded = json_decode($quiz_ids, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $quiz_ids = $decoded;
        }
    }

    if (!is_array($quiz_ids) || empty($quiz_ids)) {
        throw new moodle_exception('invalid_quiz_ids', 'local_essaysmaster');
    }

    if (!in_array($action, ['enable', 'disable'])) {
        throw new moodle_exception('invalid_action', 'local_essaysmaster');
    }

    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $enabled = ($action === 'enable');

    $ids = array_map('intval', $quiz_ids);
    $csv = implode(',', $ids);
    $result = $dashboard_manager->bulk_toggle_quizzes($csv, $enabled);

    if (!empty($result['success'])) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'] ?? ''
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['message'] ?? get_string('bulk_action_failed', 'local_essaysmaster')
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
