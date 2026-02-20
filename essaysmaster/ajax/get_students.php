<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/essaysmaster:viewdashboard', $context);

header('Content-Type: application/json');

try {
    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $students = $dashboard_manager->get_student_progress();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'students' => $students
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>