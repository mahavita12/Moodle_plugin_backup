<?php
/**
 * AJAX endpoint to get gamification stats for the current user.
 * Uses Category 1 course as anchor to determine leader/runner-up among peers.
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/homework_manager.php');

require_login();
header('Content-Type: application/json');

global $USER, $DB;

$userid = (int)$USER->id;

// Step 1: Find user's Category 1 course (anchor course)
$anchor_sql = "SELECT c.id, c.shortname
               FROM {course} c
               JOIN {enrol} e ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
               WHERE ue.userid = :userid AND c.category = 1 AND c.visible = 1
               LIMIT 1";
$anchor_course = $DB->get_record_sql($anchor_sql, ['userid' => $userid]);

if (!$anchor_course) {
    echo json_encode(['success' => true, 'overall' => ['label' => 'Overall', 'level' => 1, 'points' => 0, 'icon' => '🌟', 'isLeader' => false, 'isRunnerup' => false]]);
    exit;
}

// Step 2: Get all users enrolled in the same anchor course
$peers_sql = "SELECT DISTINCT ue.userid
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
              WHERE e.courseid = :courseid";
$peers = $DB->get_fieldset_sql($peers_sql, ['courseid' => $anchor_course->id]);

if (empty($peers)) {
    $peers = [$userid];
}

// Step 3: Get each peer's TOTAL points across ALL categories (1, 3, 4)
$manager = new \local_homeworkdashboard\homework_manager();
$all_rows = $manager->get_leaderboard_data(0, [], true, $peers);

// Calculate total points per user
$user_totals = [];

foreach ($all_rows as $row) {
    $uid = (int)$row->userid;
    $catid = (int)($row->categoryid ?? 0);
    
    // Only include Categories 1, 3, 4
    if (!in_array($catid, [1, 3, 4])) {
        continue;
    }
    
    $points = (int)round(($row->points_all ?? 0) / 10);
    
    if (!isset($user_totals[$uid])) {
        $user_totals[$uid] = 0;
    }
    $user_totals[$uid] += $points;
}

// Step 4: Determine leader and runner-up
arsort($user_totals);
$sorted_users = array_keys($user_totals);
$leader_userid = $sorted_users[0] ?? null;
$runnerup_userid = $sorted_users[1] ?? null;

// Get current user's stats
$overall_points = $user_totals[$userid] ?? 0;
$overall_level = max(1, ceil($overall_points / 100));
$is_leader = ($userid === $leader_userid && $overall_points > 0);
$is_runnerup = ($userid === $runnerup_userid && $overall_points > 0);

// Build response
$response = [
    'success' => true,
    'overall' => [
        'label' => 'Overall',
        'level' => $overall_level,
        'points' => $overall_points,
        'icon' => '🌟',
        'isLeader' => $is_leader,
        'isRunnerup' => $is_runnerup
    ]
];

echo json_encode($response);
