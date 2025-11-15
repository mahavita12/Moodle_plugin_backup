<?php
require_once(__DIR__ . '/../../config.php');

require_login();

// Include helpers/services for tabs 2 and 3.
require_once(__DIR__ . '/classes/preset_helper.php');
require_once(__DIR__ . '/classes/copy_service.php');
require_once(__DIR__ . '/classes/settings_service.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/quiz_uploader/index.php'));
$PAGE->set_title('Quiz Uploader');
$PAGE->set_heading('Quiz Uploader');
$PAGE->set_pagelayout('admin');

require_capability('local/quiz_uploader:uploadquiz', $context);

$tab = optional_param('tab', 'upload', PARAM_ALPHA);

if ($tab === 'upload') {
    redirect(new moodle_url('/local/quiz_uploader/upload.php'));
}

// Tabs 2 and 3 are restricted to managers/admins.
if ($tab !== 'upload') {
    require_capability('moodle/category:manage', $context);
}

$selectedcat = optional_param('cat', 0, PARAM_INT);
$sourcecourse = optional_param('sourcecourse', 0, PARAM_INT);
$sourcesection = optional_param('sourcesection', 0, PARAM_INT);
$targetcourse = optional_param('targetcourse', 0, PARAM_INT);
$targetsection = optional_param('targetsection', 0, PARAM_INT);
$page = max(1, optional_param('page', 1, PARAM_INT));
$action = optional_param('action', '', PARAM_ALPHA);

$perpage = 20;

$renderer = $PAGE->get_renderer('core');

echo $OUTPUT->header();

$tabs = [];
$tabs[] = new tabobject('upload', new moodle_url('/local/quiz_uploader/upload.php'), 'Upload XML');
$tabs[] = new tabobject('copy', new moodle_url('/local/quiz_uploader/index.php', ['tab' => 'copy']), 'Copy from other courses');
$tabs[] = new tabobject('settings', new moodle_url('/local/quiz_uploader/index.php', ['tab' => 'settings']), 'Bulk settings');
print_tabs([$tabs], $tab);

if ($tab === 'copy') {
    echo html_writer::tag('h3', 'Copy quiz from other courses');

    echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/local/quiz_uploader/index.php')]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'copy']);

    $catoptions = ['0' => '-- Select course category --'];
    $categories = $DB->get_records('course_categories', null, 'name ASC', 'id, name');
    foreach ($categories as $cat) {
        $catoptions[$cat->id] = format_string($cat->name);
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Category', 'id_cat');
    echo html_writer::select($catoptions, 'cat', $selectedcat, null, ['id' => 'id_cat', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    $courseoptions = ['0' => '-- Select source course --'];
    if ($selectedcat) {
        $srcourses = $DB->get_records('course', ['category' => $selectedcat], 'shortname ASC', 'id, shortname, fullname');
        foreach ($srcourses as $c) {
            $courseoptions[$c->id] = $c->shortname . ' — ' . format_string($c->fullname);
        }
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Source course', 'id_sourcecourse');
    echo html_writer::select($courseoptions, 'sourcecourse', $sourcecourse, null, ['id' => 'id_sourcecourse', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    $sectionoptions = ['0' => '-- Select source section --'];
    if ($sourcecourse) {
        $sections = $DB->get_records('course_sections', ['course' => $sourcecourse], 'section ASC', 'id, section, name');
        foreach ($sections as $s) {
            $display = ($s->name !== null && $s->name !== '') ? $s->name : 'Section ' . $s->section;
            $sectionoptions[$s->id] = $display;
        }
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Source section', 'id_sourcesection');
    echo html_writer::select($sectionoptions, 'sourcesection', $sourcesection, null, ['id' => 'id_sourcesection', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');

    $quizrows = [];
    $total = 0;
    if ($sourcecourse && $sourcesection) {
        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', IGNORE_MISSING);
        if ($module) {
            $params = ['course' => $sourcecourse, 'section' => $sourcesection, 'module' => $module->id];
            $countsql = "SELECT COUNT(*)
                           FROM {course_modules} cm
                           JOIN {quiz} q ON q.id = cm.instance
                          WHERE cm.course = :course AND cm.section = :section AND cm.module = :module";
            $total = $DB->count_records_sql($countsql, $params);

            $offset = ($page - 1) * $perpage;
            $listsql = "SELECT cm.id AS cmid, q.id AS quizid, q.name
                          FROM {course_modules} cm
                          JOIN {quiz} q ON q.id = cm.instance
                         WHERE cm.course = :course AND cm.section = :section AND cm.module = :module
                      ORDER BY q.name ASC";
            $all = $DB->get_records_sql($listsql, $params, $offset, $perpage);
            $quizrows = array_values($all);
        }
    }

    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'copy']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cat', 'value' => $selectedcat]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourcecourse', 'value' => $sourcecourse]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourcesection', 'value' => $sourcesection]);

    if (!empty($quizrows)) {
        echo html_writer::tag('h4', 'Select quizzes to copy');
        echo html_writer::start_tag('div', ['style' => 'max-height:300px;overflow:auto;border:1px solid #ddd;padding:8px;']);
        foreach ($quizrows as $r) {
            $id = 'q_' . $r->cmid;
            echo html_writer::start_div('');
            echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'cmids[]', 'value' => $r->cmid, 'id' => $id]);
            echo html_writer::label(format_string($r->name) . " (cmid: {$r->cmid})", $id);
            echo html_writer::end_div();
        }
        echo html_writer::end_tag('div');

        if ($total > $perpage) {
            echo $OUTPUT->paging_bar($total, $page - 1, $perpage, new moodle_url('/local/quiz_uploader/index.php', ['tab' => 'copy', 'cat' => $selectedcat, 'sourcecourse' => $sourcecourse, 'sourcesection' => $sourcesection]));
        }
    } else {
        if ($sourcecourse && $sourcesection) {
            echo $OUTPUT->notification('No quizzes found in the selected section.', 'info');
        }
    }

    $tcourses = ['0' => '-- Select target course --'];
    $allcourses = $DB->get_records_sql("SELECT id, shortname, fullname FROM {course} WHERE id > 1 ORDER BY shortname ASC");
    // Default target course to Central Question Bank if not set.
    if (empty($targetcourse)) {
        $central = $DB->get_record('course', ['fullname' => 'Central Question Bank'], 'id');
        if (!$central) { $central = $DB->get_record('course', ['shortname' => 'CQB'], 'id'); }
        if ($central) { $targetcourse = (int)$central->id; }
    }
    foreach ($allcourses as $tc) {
        $tcourses[$tc->id] = $tc->shortname . ' — ' . format_string($tc->fullname);
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Target course', 'id_targetcourse');
    echo html_writer::select($tcourses, 'targetcourse', $targetcourse, null, ['id' => 'id_targetcourse']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Target section', 'id_targetsection');
    echo html_writer::select(['' => 'Please select target course first...'], 'targetsection', $targetsection, null, ['id' => 'id_targetsection']);
    echo html_writer::end_div();

    // Preset selector (use Quiz Uploader defaults; user may switch to Test)
    $presetoptions = [
        'default' => 'Default (Quiz Uploader)',
        'test' => 'Test (Quiz Uploader)'
    ];
    echo html_writer::start_div('form-group');
    echo html_writer::label('Preset', 'id_preset_copy');
    echo html_writer::select($presetoptions, 'preset', 'default', null, ['id' => 'id_preset_copy']);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'dryrun_copy']);
    echo html_writer::tag('button', 'Dry run copy', ['type' => 'submit', 'class' => 'btn btn-primary']);

    echo html_writer::end_tag('form');

    $PAGE->requires->js_amd_inline(<<<'JS'
document.addEventListener('DOMContentLoaded', function() {
  var tcourse = document.getElementById('id_targetcourse');
  var tsection = document.getElementById('id_targetsection');
  function loadSections(courseid) {
    if (!courseid) {
      tsection.innerHTML = '<option value="">Please select target course first...</option>';
      return;
    }
    tsection.innerHTML = '<option>Loading...</option>';
    fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + courseid + '&sesskey=' + M.cfg.sesskey)
      .then(function(r){ return r.json(); })
      .then(function(data){
        var opts = '<option value="">-- Select a section --</option>';
        data.forEach(function(s){ opts += '<option value="' + s.id + '">' + s.name + '</option>'; });
        tsection.innerHTML = opts;
      })
      .catch(function(){ tsection.innerHTML = '<option>Error loading sections</option>'; });
  }
  if (tcourse) {
    tcourse.addEventListener('change', function(){ loadSections(this.value); });
    if (tcourse.value) { loadSections(tcourse.value); }
  }
});
JS
    );
}

if ($tab === 'settings') {
    echo html_writer::tag('h3', 'Bulk change quiz settings');

    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'settings']);

    $catoptions = ['0' => '-- Select course category --'];
    $categories = $DB->get_records('course_categories', null, 'name ASC', 'id, name');
    foreach ($categories as $cat) {
        $catoptions[$cat->id] = format_string($cat->name);
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Category', 'id_cat2');
    echo html_writer::select($catoptions, 'cat', $selectedcat, null, ['id' => 'id_cat2', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    $courseoptions = ['0' => '-- Select source course --'];
    if ($selectedcat) {
        $srcourses = $DB->get_records('course', ['category' => $selectedcat], 'shortname ASC', 'id, shortname, fullname');
        foreach ($srcourses as $c) {
            $courseoptions[$c->id] = $c->shortname . ' — ' . format_string($c->fullname);
        }
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Course', 'id_sourcecourse2');
    echo html_writer::select($courseoptions, 'sourcecourse', $sourcecourse, null, ['id' => 'id_sourcecourse2', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    $sectionoptions = ['0' => '-- Select section --'];
    if ($sourcecourse) {
        $sections = $DB->get_records('course_sections', ['course' => $sourcecourse], 'section ASC', 'id, section, name');
        foreach ($sections as $s) {
            $display = ($s->name !== null && $s->name !== '') ? $s->name : 'Section ' . $s->section;
            $sectionoptions[$s->id] = $display;
        }
    }
    echo html_writer::start_div('form-group');
    echo html_writer::label('Section', 'id_sourcesection2');
    echo html_writer::select($sectionoptions, 'sourcesection', $sourcesection, null, ['id' => 'id_sourcesection2', 'onchange' => 'this.form.submit()']);
    echo html_writer::end_div();

    $quizrows = [];
    $total = 0;
    if ($sourcecourse && $sourcesection) {
        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', IGNORE_MISSING);
        if ($module) {
            $params = ['course' => $sourcecourse, 'section' => $sourcesection, 'module' => $module->id];
            $countsql = "SELECT COUNT(*)
                           FROM {course_modules} cm
                           JOIN {quiz} q ON q.id = cm.instance
                          WHERE cm.course = :course AND cm.section = :section AND cm.module = :module";
            $total = $DB->count_records_sql($countsql, $params);

            $offset = ($page - 1) * $perpage;
            $listsql = "SELECT cm.id AS cmid, q.id AS quizid, q.name
                          FROM {course_modules} cm
                          JOIN {quiz} q ON q.id = cm.instance
                         WHERE cm.course = :course AND cm.section = :section AND cm.module = :module
                      ORDER BY q.name ASC";
            $all = $DB->get_records_sql($listsql, $params, $offset, $perpage);
            $quizrows = array_values($all);
        }
    }

    if (!empty($quizrows)) {
        echo html_writer::tag('h4', 'Select quizzes to update');
        echo html_writer::start_tag('div', ['style' => 'max-height:300px;overflow:auto;border:1px solid #ddd;padding:8px;']);
        foreach ($quizrows as $r) {
            $id = 'qq_' . $r->cmid;
            echo html_writer::start_div('');
            echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'cmids[]', 'value' => $r->cmid, 'id' => $id]);
            echo html_writer::label(format_string($r->name) . " (cmid: {$r->cmid})", $id);
            echo html_writer::end_div();
        }
        echo html_writer::end_tag('div');

        if ($total > $perpage) {
            echo $OUTPUT->paging_bar($total, $page - 1, $perpage, new moodle_url('/local/quiz_uploader/index.php', ['tab' => 'settings', 'cat' => $selectedcat, 'sourcecourse' => $sourcecourse, 'sourcesection' => $sourcesection]));
        }
    } else {
        if ($sourcecourse && $sourcesection) {
            echo $OUTPUT->notification('No quizzes found in the selected section.', 'info');
        }
    }

    echo html_writer::tag('h4', 'Settings');
    $presetoptions = [
        'default' => 'Default (Quiz Uploader)',
        'test' => 'Test (Quiz Uploader)',
        'nochange' => 'No change'
    ];
    echo html_writer::start_div('form-group');
    echo html_writer::label('Preset', 'id_preset');
    echo html_writer::select($presetoptions, 'preset', 'default', null, ['id' => 'id_preset']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Close the quiz (optional)', 'id_timeclose');
    echo html_writer::empty_tag('input', ['type' => 'datetime-local', 'name' => 'timeclose', 'id' => 'id_timeclose']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Activity classification', 'id_activityclass');
    echo html_writer::select(['New' => 'New', 'Review' => 'Review', 'Practice' => 'Practice'], 'activityclass', 'New', null, ['id' => 'id_activityclass']);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'dryrun_settings']);
    echo html_writer::tag('button', 'Dry run update', ['type' => 'submit', 'class' => 'btn btn-primary']);

    echo html_writer::end_tag('form');
}

if ($action === 'dryrun_copy' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $targetcourse = required_param('targetcourse', PARAM_INT);
    $targetsection = required_param('targetsection', PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);

    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to copy.', 'warning');
    } else if (empty($targetcourse) || empty($targetsection)) {
        echo $OUTPUT->notification('Please select a target course and section.', 'warning');
    } else {
        $count = count($cmids);
        // Compute collisions by quiz name in the target section.
        $collisions = [];
        foreach ($cmids as $cmid) {
            $src = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
            $srcquizname = $DB->get_field('quiz', 'name', ['id' => $src->instance], IGNORE_MISSING);
            if ($srcquizname) {
                $exists = \local_quiz_uploader\copy_service::find_target_quiz_by_name($targetcourse, $targetsection, $srcquizname);
                if ($exists) { $collisions[] = $srcquizname; }
            }
        }

        $msg = "Dry run: {$count} quiz(es) will be copied to the selected target section.";
        if (!empty($collisions)) {
            $msg .= ' Conflicts detected (will be overwritten if you proceed): ' . s(implode(', ', $collisions));
        } else {
            $msg .= ' No name conflicts detected.';
        }
        echo $OUTPUT->notification($msg, !empty($collisions) ? 'warning' : 'info');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'copy']);
        foreach ($cmids as $id) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmids[]', 'value' => $id]); }
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetcourse', 'value' => $targetcourse]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetsection', 'value' => $targetsection]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preset', 'value' => $preset]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm_copy']);
        echo html_writer::tag('button', 'Confirm and proceed', ['type' => 'submit', 'class' => 'btn btn-danger']);
        echo html_writer::end_tag('form');
    }
}

if ($action === 'dryrun_settings' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);
    $timeclose = optional_param('timeclose', '', PARAM_RAW);
    $activityclass = optional_param('activityclass', 'New', PARAM_TEXT);

    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to update.', 'warning');
    } else {
        $count = count($cmids);
        $summary = 'Preset: ' . $preset . ' — Activity classification: ' . s($activityclass);
        if (!empty($timeclose)) { $summary .= ' — Close time provided'; } else { $summary .= ' — Close time not set'; }
        echo $OUTPUT->notification("Dry run: {$count} quiz(es) will be updated. " . $summary, 'info');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'settings']);
        foreach ($cmids as $id) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmids[]', 'value' => $id]); }
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preset', 'value' => $preset]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timeclose', 'value' => $timeclose]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'activityclass', 'value' => $activityclass]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirm_settings']);
        echo html_writer::tag('button', 'Confirm and proceed', ['type' => 'submit', 'class' => 'btn btn-danger']);
        echo html_writer::end_tag('form');
    }
}

if ($action === 'confirm_copy' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $targetcourse = required_param('targetcourse', PARAM_INT);
    $targetsection = required_param('targetsection', PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);

    if (empty($cmids) || empty($targetcourse) || empty($targetsection)) {
        echo $OUTPUT->notification('Missing parameters to perform copy.', 'error');
    } else {
        // Threshold: sync when <=10, else queue (not implemented yet).
        if (count($cmids) > 10) {
            echo $OUTPUT->notification('More than 10 items selected. Queuing to background will be added next.', 'warning');
        }
        $results = \local_quiz_uploader\copy_service::copy_quizzes($cmids, $targetcourse, $targetsection, $preset, null, 'New');
        $ok = array_reduce($results, function($c,$r){ return $c && !empty($r->success); }, true);
        if ($ok) {
            echo $OUTPUT->notification('Copy completed successfully.', 'success');
        } else {
            echo $OUTPUT->notification('Copy completed with some errors. Review the report below.', 'warning');
        }
        echo html_writer::tag('pre', print_r($results, true));
    }
}

if ($action === 'confirm_settings' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);
    $timeclose = optional_param('timeclose', '', PARAM_RAW);
    $activityclass = optional_param('activityclass', 'New', PARAM_TEXT);

    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to update.', 'warning');
    } else {
        $results = \local_quiz_uploader\settings_service::apply_bulk_settings($cmids, $preset, $timeclose, $activityclass);
        $ok = array_reduce($results, function($c,$r){ return $c && !empty($r->success); }, true);
        if ($ok) {
            echo $OUTPUT->notification('Settings updated successfully.', 'success');
        } else {
            echo $OUTPUT->notification('Settings updated with some errors. Review the report below.', 'warning');
        }
        echo html_writer::tag('pre', print_r($results, true));
    }
}

echo $OUTPUT->footer();
