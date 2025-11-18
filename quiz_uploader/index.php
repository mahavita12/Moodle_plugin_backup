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
    echo html_writer::label('Close the quiz', 'id_timeclose');
    echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'timeclose_enable', 'id' => 'id_timeclose_enable', 'value' => 1]);
    // hidden field that will be submitted as YYYY-MM-DD HH:MM
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timeclose', 'id' => 'id_timeclose']);

    // Build date/time selects: year, month, day, hour, minute
    $nowts = time();
    $initY = (int)date('Y', $nowts);
    $initM = date('m', $nowts);
    $initD = date('d', $nowts);
    $initH = date('H', $nowts);
    $initI = date('i', $nowts);

    $yearoptions = [];
    for ($y = $initY - 1; $y <= $initY + 3; $y++) { $yearoptions[(string)$y] = (string)$y; }
    echo html_writer::select($yearoptions, 'timeclose_year', (string)$initY, null, ['id' => 'id_timeclose_year', 'style' => 'margin-left:8px;']);

    $monthoptions = [];
    for ($m = 1; $m <= 12; $m++) { $k = str_pad((string)$m, 2, '0', STR_PAD_LEFT); $monthoptions[$k] = date('F', mktime(0,0,0,$m,1)); }
    echo html_writer::select($monthoptions, 'timeclose_month', (string)$initM, null, ['id' => 'id_timeclose_month', 'style' => 'margin-left:6px;']);

    $dayoptions = [];
    for ($d = 1; $d <= 31; $d++) { $k = str_pad((string)$d, 2, '0', STR_PAD_LEFT); $dayoptions[$k] = $k; }
    echo html_writer::select($dayoptions, 'timeclose_day', (string)$initD, null, ['id' => 'id_timeclose_day', 'style' => 'margin-left:6px;']);

    $houroptions = [];
    for ($h = 0; $h <= 23; $h++) { $k = str_pad((string)$h, 2, '0', STR_PAD_LEFT); $houroptions[$k] = $k; }
    echo html_writer::select($houroptions, 'timeclose_hour', (string)$initH, null, ['id' => 'id_timeclose_hour', 'style' => 'margin-left:12px;']);

    $minoptions = [];
    for ($i = 0; $i <= 59; $i++) { $k = str_pad((string)$i, 2, '0', STR_PAD_LEFT); $minoptions[$k] = $k; }
    echo html_writer::select($minoptions, 'timeclose_min', (string)$initI, null, ['id' => 'id_timeclose_min', 'style' => 'margin-left:6px;']);

    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Time limit (minutes) — applies when Preset = Test', 'id_timelimit');
    echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'timelimit_enable', 'id' => 'id_timelimit_enable', 'value' => 1]);
    echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'timelimit', 'id' => 'id_timelimit', 'min' => 0, 'step' => 1, 'value' => 45]);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Activity classification', 'id_activityclass');
    echo html_writer::select(['None' => 'None', 'New' => 'New', 'Revision' => 'Revision'], 'activityclass', 'None', null, ['id' => 'id_activityclass']);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'dryrun_settings']);
    echo html_writer::tag('button', 'Dry run update', ['type' => 'submit', 'class' => 'btn btn-primary']);

    echo html_writer::end_tag('form');

    $PAGE->requires->js_amd_inline(<<<'JS'
(function(){
  function init(){
    var e1 = document.getElementById('id_timeclose_enable');
    var hiddenTime = document.getElementById('id_timeclose');
    var ySel = document.getElementById('id_timeclose_year');
    var mSel = document.getElementById('id_timeclose_month');
    var dSel = document.getElementById('id_timeclose_day');
    var hSel = document.getElementById('id_timeclose_hour');
    var iSel = document.getElementById('id_timeclose_min');
    var e2 = document.getElementById('id_timelimit_enable');
    var t2 = document.getElementById('id_timelimit');
    function setEnabled(input, enabled){
      if (!input) return;
      input.disabled = !enabled;
      if (enabled) {
        try { input.removeAttribute('disabled'); } catch(_) {}
      } else {
        try { input.setAttribute('disabled','disabled'); } catch(_) {}
      }
    }
    function pad2(n){ n = parseInt(n, 10); return (n < 10 ? '0' : '') + n; }
    function daysInMonth(yy, mm){
      var y = parseInt(yy, 10); var m = parseInt(mm, 10); if (!y || !m) return 31;
      return new Date(y, m, 0).getDate(); // mm is 1..12
    }
    function composeClose(){
      if (!hiddenTime) return;
      var yy = ySel ? ySel.value : '';
      var mm = mSel ? mSel.value : '';
      var dd = dSel ? dSel.value : '';
      var hh = hSel ? hSel.value : '';
      var ii = iSel ? iSel.value : '';
      // Normalize day within month
      var maxd = daysInMonth(yy, mm);
      var ddi = parseInt(dd || '1', 10);
      if (ddi > maxd) { ddi = maxd; if (dSel) dSel.value = pad2(ddi); }
      hiddenTime.value = yy + '-' + mm + '-' + pad2(ddi) + ' ' + hh + ':' + ii;
    }
    function sync(){
      // Keep time limit always editable; server-side respects the Enable checkbox
      setEnabled(t2, true);
      composeClose();
    }
    ['change','click'].forEach(function(ev){
      if (e2) e2.addEventListener(ev, sync);
      if (ySel) ySel.addEventListener(ev, sync);
      if (mSel) mSel.addEventListener(ev, sync);
      if (dSel) dSel.addEventListener(ev, sync);
      if (hSel) hSel.addEventListener(ev, sync);
      if (iSel) iSel.addEventListener(ev, sync);
    });
    // Ensure hidden field is composed before submit
    var form = ySel ? ySel.closest('form') : null;
    if (form) {
      form.addEventListener('submit', function(){ composeClose(); });
    }
    sync();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
JS
    );
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
    $timeclose_enable = optional_param('timeclose_enable', 0, PARAM_BOOL);
    $timelimit = optional_param('timelimit', 0, PARAM_INT);
    $timelimit_enable = optional_param('timelimit_enable', 0, PARAM_BOOL);

    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to update.', 'warning');
    } else {
        $count = count($cmids);
        $summary = 'Preset: ' . $preset . ' — Activity classification: ' . s($activityclass);
        $summary .= $timeclose_enable ? ' — Close time: enabled' : ' — Close time: not changed';
        if ($timelimit_enable) { $summary .= ' — Time limit: ' . (int)$timelimit . ' min (Test preset)'; }
        echo $OUTPUT->notification("Dry run: {$count} quiz(es) will be updated. " . $summary, 'info');
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'settings']);
        foreach ($cmids as $id) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmids[]', 'value' => $id]); }
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preset', 'value' => $preset]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timeclose', 'value' => $timeclose]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'activityclass', 'value' => $activityclass]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timeclose_enable', 'value' => (int)$timeclose_enable]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timelimit', 'value' => (int)$timelimit]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timelimit_enable', 'value' => (int)$timelimit_enable]);
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
    $timeclose_enable = optional_param('timeclose_enable', 0, PARAM_BOOL);
    $timelimit = optional_param('timelimit', 0, PARAM_INT);
    $timelimit_enable = optional_param('timelimit_enable', 0, PARAM_BOOL);

    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to update.', 'warning');
    } else {
        $results = \local_quiz_uploader\settings_service::apply_bulk_settings($cmids, $preset, $timeclose, $activityclass, (int)$timeclose_enable, (int)$timelimit, (int)$timelimit_enable);
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
