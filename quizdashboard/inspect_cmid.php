<?php
// Quick inspector for a given course module id (cmid).
// Shows whether the CM exists, which module it belongs to, whether the instance exists,
// the owning course, and whether it appears in any course_sections sequence.

require_once(__DIR__ . '/../../config.php');

global $DB, $OUTPUT, $PAGE;

$cmid = required_param('id', PARAM_INT);

require_login();
// Restrict to site admins for safety.
if (!is_siteadmin()) {
    throw new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', 'inspect_cmid');
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/quizdashboard/inspect_cmid.php', ['id' => $cmid]));
$PAGE->set_title('Inspect CMID');
$PAGE->set_heading('Inspect Course Module ID');

echo $OUTPUT->header();
echo $OUTPUT->heading('Inspect CMID: ' . (int)$cmid, 2);

$cm = $DB->get_record('course_modules', ['id' => $cmid]);
if (!$cm) {
    echo $OUTPUT->notification('No record found in mdl_course_modules for id='.$cmid, 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$mod = $DB->get_record('modules', ['id' => $cm->module], '*', IGNORE_MISSING);
$modname = $mod ? $mod->name : '(unknown)';

echo html_writer::start_div();
echo html_writer::tag('p', 'course_modules.id: '.$cm->id);
echo html_writer::tag('p', 'course_modules.course: '.$cm->course);
echo html_writer::tag('p', 'course_modules.module id: '.$cm->module.' (name: '.$modname.')');
echo html_writer::tag('p', 'course_modules.instance: '.$cm->instance);
echo html_writer::end_div();

// Check instance table existence.
$instexists = false;
if ($mod && !empty($cm->instance)) {
    $instexists = $DB->record_exists($mod->name, ['id' => $cm->instance]);
}

if ($mod) {
    $msg = $instexists ? 'OK: Instance exists in mdl_'.$mod->name : 'Missing: No row in mdl_'.$mod->name.' for id='.$cm->instance;
    echo $OUTPUT->notification($msg, $instexists ? 'notifysuccess' : 'notifyproblem');
}

// Check if CMID appears in any section sequence for this course.
$sections = $DB->get_records('course_sections', ['course' => $cm->course], 'section ASC', 'id,section,sequence');
$foundinseq = false;
$where = [];
foreach ($sections as $s) {
    if (!empty($s->sequence)) {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $s->sequence))));
        if (in_array($cmid, $ids, true)) {
            $foundinseq = true;
            $where[] = (int)$s->section;
        }
    }
}

if ($foundinseq) {
    echo $OUTPUT->notification('CMID appears in course_sections.sequence for section(s): '.implode(', ', $where), 'notifysuccess');
} else {
    echo $OUTPUT->notification('CMID is NOT referenced in any course_sections.sequence for this course.', 'notifyproblem');
}

// Helper links
$autofixurl = new moodle_url('/local/quizdashboard/force_delete_course.php', ['id' => $cm->course, 'autofix' => 1]);
$courseurl = new moodle_url('/course/view.php', ['id' => $cm->course]);
echo html_writer::tag('p', html_writer::link($courseurl, 'Open course')); 
echo html_writer::tag('p', html_writer::link($autofixurl, 'Run Autofix for this course (recreate missing CMs, attach orphaned CMs, rebuild cache)'));

echo $OUTPUT->footer();
