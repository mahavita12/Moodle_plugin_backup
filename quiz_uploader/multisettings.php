<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_once(__DIR__ . '/classes/preset_helper.php');
require_once(__DIR__ . '/classes/settings_service.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/quiz_uploader/multisettings.php'));
$PAGE->set_title('Quiz Uploader');
$PAGE->set_heading('Quiz Uploader');
$PAGE->set_pagelayout('admin');

require_capability('local/quiz_uploader:uploadquiz', $context);
require_capability('moodle/category:manage', $context);

$columns = 5;
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$maxsections = (int)get_config('local_quiz_uploader', 'maxsections');
if ($maxsections < 1) { $maxsections = 0; }

// Default category to 'Category 1' when not specified.
$defaultcatid = (int)$DB->get_field('course_categories', 'id', ['name' => 'Category 1'], IGNORE_MISSING);
if (!$defaultcatid) { $defaultcatid = 1; }

echo $OUTPUT->header();

$tabs = [];
$tabs[] = new tabobject('upload', new moodle_url('/local/quiz_uploader/upload.php'), 'Upload XML');
$tabs[] = new tabobject('copy', new moodle_url('/local/quiz_uploader/index.php', ['tab' => 'copy']), 'Copy from other courses');
$tabs[] = new tabobject('multisettings', new moodle_url('/local/quiz_uploader/multisettings.php'), 'Multi settings');
print_tabs([$tabs], 'multisettings');

echo html_writer::tag('h3', 'Bulk change quiz settings');

$categories = $DB->get_records('course_categories', null, 'name ASC', 'id, name');
$catoptions = ['0' => '-- Select course category --'];
foreach ($categories as $cat) { $catoptions[$cat->id] = format_string($cat->name); }

$coldata = [];
// Preserve selections across reloads.
$preselected = optional_param_array('cmids', [], PARAM_INT);
for ($i = 1; $i <= $columns; $i++) {
    $cat = optional_param('cat' . $i, 0, PARAM_INT);
    if (empty($cat)) { $cat = $defaultcatid; }
    $course = optional_param('sourcecourse' . $i, 0, PARAM_INT);
    $sections = optional_param_array('sourcesections' . $i, [], PARAM_INT);
    $coldata[$i] = (object)[
        'cat' => $cat,
        'course' => $course,
        'sections' => $sections,
        'courses' => [],
        'sectionoptions' => [],
        'quizrows' => [],
        'total' => 0,
    ];
}

for ($i = 1; $i <= $columns; $i++) {
    $cd = $coldata[$i];
    if ($cd->cat) {
        $srcourses = $DB->get_records('course', ['category' => $cd->cat], 'shortname ASC', 'id, shortname, fullname');
        foreach ($srcourses as $c) { $cd->courses[$c->id] = $c->shortname . ' — ' . format_string($c->fullname); }
    }
    if ($cd->course) {
        $sections = $DB->get_records('course_sections', ['course' => $cd->course], 'section ASC', 'id, section, name');
        foreach ($sections as $s) {
            $display = ($s->name !== null && $s->name !== '') ? $s->name : 'Section ' . $s->section;
            $cd->sectionoptions[$s->id] = $display;
        }
    }
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$gridstyle = 'display:grid;grid-template-columns:520px 520px repeat(3, minmax(320px,1fr));gap:12px;align-items:start;overflow-x:auto';
echo html_writer::start_tag('div', ['style' => $gridstyle]);
for ($i = 1; $i <= $columns; $i++) {
    $cd = $coldata[$i];
    $colstyle = 'border:1px solid #eee;padding:8px;border-radius:6px;box-sizing:border-box;' . (($i <= 2) ? 'min-width:520px;' : 'min-width:320px;');
    echo html_writer::start_div('col-' . $i, ['style' => $colstyle]);
    echo html_writer::tag('h4', 'Column ' . $i, ['style' => 'margin-top:0;font-size:15px']);

    echo html_writer::start_div('form-group');
    echo html_writer::label('Category', 'id_cat' . $i);
    echo html_writer::select($catoptions, 'cat' . $i, $cd->cat, null, ['id' => 'id_cat' . $i, 'onchange' => 'this.form.submit()', 'style' => 'width:100%;max-width:none']);
    echo html_writer::end_div();

    $courseoptions = ['0' => '-- Select course --'] + $cd->courses;
    echo html_writer::start_div('form-group');
    echo html_writer::label('Course', 'id_sourcecourse' . $i);
    echo html_writer::select($courseoptions, 'sourcecourse' . $i, $cd->course, null, ['id' => 'id_sourcecourse' . $i, 'onchange' => 'this.form.submit()', 'style' => 'width:100%;max-width:none']);
    echo html_writer::end_div();

    echo html_writer::start_div('form-group');
    echo html_writer::label('Sections', 'id_sourcesections' . $i);
    echo html_writer::select($cd->sectionoptions, 'sourcesections' . $i . '[]', $cd->sections, null, ['id' => 'id_sourcesections' . $i, 'multiple' => 'multiple', 'size' => 8, 'style' => 'width:100%;max-width:none']);
    echo html_writer::start_div('', ['style' => 'margin-top:8px;']);
    echo html_writer::tag('button', 'Load quizzes', ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div();
}
echo html_writer::end_tag('div');

$module = $DB->get_record('modules', ['name' => 'quiz'], '*', IGNORE_MISSING);
if ($module) {
    for ($i = 1; $i <= $columns; $i++) {
        $cd = $coldata[$i];
        if ($cd->course && !empty($cd->sections)) {
            if ($maxsections > 0 && count($cd->sections) > $maxsections) { $cd->sections = array_slice($cd->sections, 0, $maxsections); }
            list($insql, $inparams) = $DB->get_in_or_equal($cd->sections, SQL_PARAMS_NAMED, 'sec');
            $params = ['course' => $cd->course, 'module' => $module->id] + $inparams;
            $listsql = "SELECT cm.id AS cmid, q.id AS quizid, q.name, cs.id AS sectionid, cs.section AS sectionnum, cs.name AS sectionname
                          FROM {course_modules} cm
                          JOIN {quiz} q ON q.id = cm.instance
                          JOIN {course_sections} cs ON cs.id = cm.section
                         WHERE cm.course = :course AND cm.module = :module AND cm.section $insql
                      ORDER BY cs.section ASC, q.name ASC";
            $all = $DB->get_records_sql($listsql, $params, 0, 0);
            $cd->quizrows = array_values($all);
        }
        $coldata[$i] = $cd;
    }
}

// Quizzes list
echo html_writer::tag('h4', 'Select quizzes to update');
$gridstyle2 = 'display:grid;grid-template-columns:400px 400px repeat(3, minmax(300px,1fr));gap:12px;align-items:start;';
echo html_writer::start_tag('div', ['style' => $gridstyle2]);
for ($i = 1; $i <= $columns; $i++) {
    $cd = $coldata[$i];
    echo html_writer::start_div('cmids-container', ['style' => 'border:1px solid #ddd;padding:8px;border-radius:6px;max-height:320px;overflow:auto;']);
    if (!empty($cd->quizrows)) {
        echo html_writer::start_div('');
        echo html_writer::tag('button', 'Select all', ['type' => 'button', 'class' => 'btn btn-link select-all-cmids']);
        echo html_writer::tag('button', 'Clear all', ['type' => 'button', 'class' => 'btn btn-link clear-all-cmids', 'style' => 'margin-left:6px;']);
        echo html_writer::end_div();
        $cursec = -1;
        foreach ($cd->quizrows as $r) {
            $secname = ($r->sectionname !== null && $r->sectionname !== '') ? $r->sectionname : ('Section ' . $r->sectionnum);
            if ($cursec !== (int)$r->sectionid) {
                if ($cursec !== -1) { echo html_writer::empty_tag('hr'); }
                echo html_writer::start_div('section-header');
                echo html_writer::tag('h5', s($secname));
                echo html_writer::tag('button', 'Select section', ['type' => 'button', 'class' => 'btn btn-sm btn-link select-section', 'data-section' => $r->sectionid]);
                echo html_writer::tag('button', 'Clear section', ['type' => 'button', 'class' => 'btn btn-sm btn-link clear-section', 'data-section' => $r->sectionid, 'style' => 'margin-left:6px;']);
                echo html_writer::end_div();
                $cursec = (int)$r->sectionid;
            }
            $id = 'qq_' . $i . '_' . $r->cmid;
            echo html_writer::start_div('');
            $attrs = ['type' => 'checkbox', 'name' => 'cmids[]', 'value' => $r->cmid, 'id' => $id, 'data-section' => $r->sectionid];
            if (!empty($preselected) && in_array((int)$r->cmid, $preselected, true)) { $attrs['checked'] = 'checked'; }
            echo html_writer::empty_tag('input', $attrs);
            echo html_writer::label(format_string($r->name) . " (cmid: {$r->cmid})", $id);
            echo html_writer::end_div();
        }
    } else {
        echo html_writer::tag('div', 'No quizzes loaded.', ['style' => 'color:#666']);
    }
    echo html_writer::end_div();
}
echo html_writer::end_tag('div');

// Settings area
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
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'timeclose', 'id' => 'id_timeclose']);
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
for ($i2 = 0; $i2 <= 59; $i2++) { $k = str_pad((string)$i2, 2, '0', STR_PAD_LEFT); $minoptions[$k] = $k; }
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
    function setEnabled(input, enabled){ if (!input) return; input.disabled = !enabled; if (enabled) { try { input.removeAttribute('disabled'); } catch(_) {} } else { try { input.setAttribute('disabled','disabled'); } catch(_) {} } }
    function pad2(n){ n = parseInt(n, 10); return (n < 10 ? '0' : '') + n; }
    function daysInMonth(yy, mm){ var y = parseInt(yy, 10); var m = parseInt(mm, 10); if (!y || !m) return 31; return new Date(y, m, 0).getDate(); }
    function composeClose(){ if (!hiddenTime) return; var yy = ySel ? ySel.value : ''; var mm = mSel ? mSel.value : ''; var dd = dSel ? dSel.value : ''; var hh = hSel ? hSel.value : ''; var ii = iSel ? iSel.value : ''; var maxd = daysInMonth(yy, mm); var ddi = parseInt(dd || '1', 10); if (ddi > maxd) { ddi = maxd; if (dSel) dSel.value = pad2(ddi); } hiddenTime.value = yy + '-' + mm + '-' + pad2(ddi) + ' ' + hh + ':' + ii; }
    function sync(){ setEnabled(t2, true); composeClose(); }
    ['change','click'].forEach(function(ev){ if (e2) e2.addEventListener(ev, sync); if (ySel) ySel.addEventListener(ev, sync); if (mSel) mSel.addEventListener(ev, sync); if (dSel) dSel.addEventListener(ev, sync); if (hSel) hSel.addEventListener(ev, sync); if (iSel) iSel.addEventListener(ev, sync); });
    var form = ySel ? ySel.closest('form') : null; if (form) { form.addEventListener('submit', function(){ composeClose(); }); }
    sync();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
JS
);
$PAGE->requires->js_amd_inline(<<<'JS'
document.addEventListener('click', function(ev){
  var t = ev.target;
  if (!t) return;
  if (t.classList.contains('select-all-cmids')){
    ev.preventDefault();
    var cont = t.closest('.cmids-container') || document;
    var boxes = cont.querySelectorAll('input[type="checkbox"][name="cmids[]"]');
    boxes.forEach(function(b){ b.checked = true; });
  } else if (t.classList.contains('clear-all-cmids')){
    ev.preventDefault();
    var cont2 = t.closest('.cmids-container') || document;
    var boxes2 = cont2.querySelectorAll('input[type="checkbox"][name="cmids[]"]');
    boxes2.forEach(function(b){ b.checked = false; });
  } else if (t.classList.contains('select-section')){
    ev.preventDefault();
    var sec = t.getAttribute('data-section');
    var cont3 = t.closest('.cmids-container') || document;
    var bs = cont3.querySelectorAll('input[type="checkbox"][name="cmids[]"][data-section="' + sec + '"]');
    bs.forEach(function(b){ b.checked = true; });
  } else if (t.classList.contains('clear-section')){
    ev.preventDefault();
    var sec2 = t.getAttribute('data-section');
    var cont4 = t.closest('.cmids-container') || document;
    var bs2 = cont4.querySelectorAll('input[type="checkbox"][name="cmids[]"][data-section="' + sec2 + '"]');
    bs2.forEach(function(b){ b.checked = false; });
  }
});
JS
);

if ($action === 'dryrun_settings' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);
    $timeclose = optional_param('timeclose', '', PARAM_RAW);
    $activityclass = optional_param('activityclass', 'New', PARAM_TEXT);
    $timeclose_enable = optional_param('timeclose_enable', 0, PARAM_BOOL);
    $timelimit = optional_param('timelimit', 0, PARAM_INT);
    $timelimit_enable = optional_param('timelimit_enable', 0, PARAM_BOOL);
    if ($timeclose_enable && (empty($timeclose) || strlen(trim($timeclose)) < 10)) {
        $yy = optional_param('timeclose_year', '', PARAM_RAW);
        $mm = optional_param('timeclose_month', '', PARAM_RAW);
        $dd = optional_param('timeclose_day', '', PARAM_RAW);
        $hh = optional_param('timeclose_hour', '', PARAM_RAW);
        $ii = optional_param('timeclose_min', '', PARAM_RAW);
        if ($yy && $mm && $dd && $hh !== '' && $ii !== '') {
            $timeclose = sprintf('%04d-%02d-%02d %02d:%02d', (int)$yy, (int)$mm, (int)$dd, (int)$hh, (int)$ii);
        }
    }
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

if ($action === 'confirm_settings' && confirm_sesskey()) {
    $cmids = optional_param_array('cmids', [], PARAM_INT);
    $preset = optional_param('preset', 'default', PARAM_ALPHA);
    $timeclose = optional_param('timeclose', '', PARAM_RAW);
    $activityclass = optional_param('activityclass', 'New', PARAM_TEXT);
    $timeclose_enable = optional_param('timeclose_enable', 0, PARAM_BOOL);
    $timelimit = optional_param('timelimit', 0, PARAM_INT);
    $timelimit_enable = optional_param('timelimit_enable', 0, PARAM_BOOL);
    if ($timeclose_enable && (empty($timeclose) || strlen(trim($timeclose)) < 10)) {
        $yy = optional_param('timeclose_year', '', PARAM_RAW);
        $mm = optional_param('timeclose_month', '', PARAM_RAW);
        $dd = optional_param('timeclose_day', '', PARAM_RAW);
        $hh = optional_param('timeclose_hour', '', PARAM_RAW);
        $ii = optional_param('timeclose_min', '', PARAM_RAW);
        if ($yy && $mm && $dd && $hh !== '' && $ii !== '') {
            $timeclose = sprintf('%04d-%02d-%02d %02d:%02d', (int)$yy, (int)$mm, (int)$dd, (int)$hh, (int)$ii);
        }
    }
    if (empty($cmids)) {
        echo $OUTPUT->notification('Select at least one quiz to update.', 'warning');
    } else {
        $results = \local_quiz_uploader\settings_service::apply_bulk_settings($cmids, $preset, $timeclose, $activityclass, (int)$timeclose_enable, (int)$timelimit, (int)$timelimit_enable);
        $ok = array_reduce($results, function($c,$r){ return $c && !empty($r->success); }, true);
        if ($ok) { echo $OUTPUT->notification('Settings updated successfully.', 'success'); }
        else { echo $OUTPUT->notification('Settings updated with some errors. Review the report below.', 'warning'); }
        echo html_writer::tag('pre', print_r($results, true));
    }
}

echo $OUTPUT->footer();
