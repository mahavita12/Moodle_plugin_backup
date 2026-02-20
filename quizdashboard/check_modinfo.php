<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$courseid = required_param('id', PARAM_INT);
$rebuild  = optional_param('rebuild', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/quizdashboard/check_modinfo.php', ['id' => $courseid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Check modinfo');
$PAGE->set_heading('Check modinfo alignment');

echo $OUTPUT->header();
echo $OUTPUT->heading('Course '.$courseid);

if ($rebuild) {
    rebuild_course_cache($courseid, true);
    echo $OUTPUT->notification('Rebuilt course cache for course '.$courseid, 'notifysuccess');
}

$modinfo = get_fast_modinfo($courseid);
$sections = $modinfo->get_section_info_all();
$missing = [];
foreach ($sections as $s) {
    $list = $modinfo->get_sections()[$s->section] ?? [];
    foreach ($list as $cmid) {
        $cm = $modinfo->cms[$cmid] ?? null;
        if (!$cm) { $missing[] = [ 'sectionnum' => $s->section, 'cmid' => $cmid ]; }
    }
}

if ($missing) {
    echo $OUTPUT->notification('Missing CM entries in modinfo (section→cms mismatch):', 'notifyproblem');
    $lines = array_map(function($r){ return 'section '.$r['sectionnum'].' cmid '.$r['cmid']; }, $missing);
    echo html_writer::tag('pre', s(implode("\n", $lines)));
    $reb = new moodle_url('/local/quizdashboard/check_modinfo.php', ['id' => $courseid, 'rebuild' => 1]);
    echo $OUTPUT->single_button($reb, 'Rebuild cache now');
} else {
    echo $OUTPUT->notification('All section sequences map to valid cm entries in modinfo.', 'notifysuccess');
}

echo $OUTPUT->footer();
