<?php
define('CLI_SCRIPT', true);
require('/home/master/applications/srfshmcmyg/public_html/config.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/homework_manager.php');
require_once($CFG->dirroot . '/local/homeworkdashboard/classes/gemini_helper.php');

$userid = 12; // Lucas
$timeclose = 1764144000; // 25 Nov 2025

echo "SIMULATE: Generating Report for User $userid\n";

$manager = new \local_homeworkdashboard\homework_manager();
$rows = $manager->get_snapshot_homework_rows(
    0, [], 0, [], [$userid], '', '', '', '', null, 'timeclose', 'DESC', false, [$timeclose], false
);

// Filter for New Activities
$new_activities = [];
foreach ($rows as $r) {
    if (isset($r->classification) && strtolower($r->classification) === 'new') {
        $status = 'No attempt';
        if (isset($r->status)) {
            if ($r->status === 'completed') $status = 'Completed';
            elseif ($r->status === 'lowgrade') $status = 'Low grade';
        }
        
        $new_activities[] = [
            'name' => $r->quizname,
            'status' => $status,
            'attempts' => $r->attempts, // This might be just a count or array depending on implementation
            'maxscore' => $r->maxscore,
            'score' => $r->score
        ];
    }
}

echo "Found " . count($new_activities) . " new activities.\n";

$gemini = new \local_homeworkdashboard\gemini_helper();
// We only care about the logging side effect
$gemini->generate_commentary('Lucas', $new_activities, [], 'en');
