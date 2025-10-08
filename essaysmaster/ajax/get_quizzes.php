<?php
require_once('../../../config.php');
require_once($CFG->dirroot . '/local/essaysmaster/classes/dashboard_manager.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/essaysmaster:configquizzes', $context);

header('Content-Type: application/json');

try {
    $dashboard_manager = new \local_essaysmaster\dashboard_manager();
    $configs = $dashboard_manager->get_quiz_configurations(0);

    $quizzes = [];
    foreach ($configs as $cfg) {
        $quizzes[] = [
            'id' => $cfg->quiz_id ?? 0,
            'name' => $cfg->quiz_name ?? 'Unknown Quiz',
            'course_name' => $cfg->course_name ?? 'Unknown Course',
            'attempts_count' => 0,
            'avg_improvement' => 0,
            'is_enabled' => !empty($cfg->is_enabled)
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'quizzes' => $quizzes
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
