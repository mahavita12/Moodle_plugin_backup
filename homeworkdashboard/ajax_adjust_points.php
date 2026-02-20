<?php
/**
 * AJAX endpoint for point adjustments (bonus/penalty).
 * Actions: add, delete, list
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();
require_sesskey();

// Only admins can manage adjustments
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = required_param('action', PARAM_ALPHA);
$manager = new \local_homeworkdashboard\homework_manager();

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'add':
            $userid   = required_param('userid', PARAM_INT);
            $courseid = required_param('courseid', PARAM_INT);
            $points   = required_param('points', PARAM_FLOAT);
            $reason   = required_param('reason', PARAM_TEXT);

            if (empty($reason)) {
                throw new moodle_exception('Reason is required.');
            }
            if ($points == 0) {
                throw new moodle_exception('Points cannot be zero.');
            }

            $datestr = optional_param('dateapplied', '', PARAM_TEXT);
            $timeapplied = !empty($datestr) ? strtotime($datestr . ' 12:00:00') : time();

            $id = $manager->add_points_adjustment($userid, $courseid, $points, $reason, $timeapplied);
            $adjustments = $manager->get_user_adjustments($userid);
            $net = array_sum(array_column($adjustments, 'points'));

            echo json_encode([
                'success' => true,
                'id' => $id,
                'adjustments' => $adjustments,
                'net_total' => $net
            ]);
            break;

        case 'edit':
            $id     = required_param('adjustid', PARAM_INT);
            $userid = required_param('userid', PARAM_INT);
            $points = required_param('points', PARAM_FLOAT);
            $reason = required_param('reason', PARAM_TEXT);

            if ($points == 0) {
                throw new moodle_exception('Points cannot be zero.');
            }

            $datestr = optional_param('dateapplied', '', PARAM_TEXT);
            $timeapplied = !empty($datestr) ? strtotime($datestr . ' 12:00:00') : 0;
            $manager->update_adjustment($id, $points, $reason, $timeapplied);
            $adjustments = $manager->get_user_adjustments($userid);
            $net = array_sum(array_column($adjustments, 'points'));

            echo json_encode([
                'success' => true,
                'adjustments' => $adjustments,
                'net_total' => $net
            ]);
            break;

        case 'delete':
            $id = required_param('adjustid', PARAM_INT);
            $userid = required_param('userid', PARAM_INT);
            $manager->delete_adjustment($id);
            $adjustments = $manager->get_user_adjustments($userid);
            $net = array_sum(array_column($adjustments, 'points'));

            echo json_encode([
                'success' => true,
                'adjustments' => $adjustments,
                'net_total' => $net
            ]);
            break;

        case 'list':
            $userid = required_param('userid', PARAM_INT);
            $adjustments = $manager->get_user_adjustments($userid);
            $net = array_sum(array_column($adjustments, 'points'));

            echo json_encode([
                'success' => true,
                'adjustments' => $adjustments,
                'net_total' => $net
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
