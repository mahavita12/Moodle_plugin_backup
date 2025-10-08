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
    $configs = $dashboard_manager->get_quiz_configurations(0);

    $total = is_array($configs) ? count($configs) : 0;
    $enabled = 0;
    if ($total > 0) {
        foreach ($configs as $cfg) {
            if (!empty($cfg->is_enabled)) {
                $enabled++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total_quizzes' => $total,
            'enabled_quizzes' => $enabled
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
