<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/homework_manager.php');

require_login();
$context = context_system::instance();

if (!has_capability('local/homeworkdashboard:view', $context)) {
    print_error('nopermissions', 'error', '', 'local_homeworkdashboard:view');
}

$PAGE->set_url('/local/homeworkdashboard/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_homeworkdashboard'));
$PAGE->set_heading(get_string('heading', 'local_homeworkdashboard'));
$PAGE->set_pagelayout('admin');

$PAGE->requires->css('/local/homeworkdashboard/styles.css');
$PAGE->requires->css('/local/quizdashboard/styles.css');

$tab           = optional_param('tab', 'live', PARAM_ALPHA);
$userid        = optional_param('userid', 0, PARAM_INT);
$categoryid    = optional_param('categoryid', 0, PARAM_INT);
$courseid      = optional_param('courseid', 0, PARAM_INT);
$sectionid     = optional_param('sectionid', 0, PARAM_INT);
$quizid        = optional_param('quizid', 0, PARAM_INT);
$studentname   = optional_param('studentname', '', PARAM_TEXT);
$statusfilter  = optional_param('status', '', PARAM_TEXT);
$quiztypefilter = optional_param('quiztype', '', PARAM_TEXT);
$classfilter   = optional_param('classification', '', PARAM_ALPHA);
$weekvalue     = optional_param('week', '', PARAM_TEXT);
$sort          = optional_param('sort', 'timeclose', PARAM_ALPHA);
$dir           = optional_param('dir', 'DESC', PARAM_ALPHA);
$filtersubmitted = optional_param('filtersubmitted', 0, PARAM_BOOL);
if ($filtersubmitted) {
    $excludestaff = optional_param('excludestaff', 0, PARAM_BOOL);
} else {
    $excludestaff = 1;
}
$duedate       = optional_param('duedate', 0, PARAM_INT);

// If a specific due date is selected, clear the week filter to avoid confusion/conflict
if ($duedate > 0) {
    $weekvalue = '';
}

function local_homeworkdashboard_sort_arrows($field, $current_sort, $current_dir) {
    $up_class   = '';
    $down_class = '';
    if ($field === $current_sort) {
        if (strtoupper($current_dir) === 'ASC') {
            $up_class = ' active';
        } else {
            $down_class = ' active';
        }
    }
    return '<span class="sort-arrows">'
        . '<span class="arrow up' . $up_class . '">&#9650;</span>'
        . '<span class="arrow down' . $down_class . '">&#9660;</span>'
        . '</span>';
}

// Categories, default to "Category 1" if present.
$categories = [];
try {
    $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
    // Default to All Categories (0) if not specified
    if (empty($categoryid)) {
        $categoryid = 0;
    }
} catch (Throwable $e) {
    // Ignore; page will just show empty filters.
}

$courses = [];
if (!empty($categoryid)) {
    $courses = $DB->get_records('course', ['category' => $categoryid, 'visible' => 1], 'fullname ASC', 'id, fullname');
}

$manager = new \local_homeworkdashboard\homework_manager();

$canmanage = has_capability('local/homeworkdashboard:manage', $context);
$backfillmessage = '';
if ($canmanage && optional_param('backfill', 0, PARAM_BOOL)) {
    require_sesskey();
    $backfilldates = optional_param_array('backfilldates', [], PARAM_INT);
    if (!empty($backfilldates)) {
        $created = $manager->backfill_snapshots_from_dates($backfilldates);
        $backfillmessage = get_string('backfill_done', 'local_homeworkdashboard', $created);
    } else {
        $backfillmessage = "No dates selected.";
    }
}

$weekoptions = [];
$now = time();
$basesunday = ((int)date('w', $now) === 0) ? $now : strtotime('next Sunday', $now);
if ($basesunday === false) {
    $basesunday = $now;
}
$currsunday = $basesunday + (7 * 24 * 60 * 60);

for ($i = 0; $i < 12; $i++) {
    $ts = $currsunday - ($i * 7 * 24 * 60 * 60);
    $label = userdate($ts, get_string('strftimedate', 'langconfig'));
    $weekoptions[$ts] = $label;
}

// Fetch data
$rows = [];
if ($tab === "snapshot") {
    $rows = $manager->get_snapshot_homework_rows(
        $categoryid,
        $courseid,
        $sectionid,
        $quizid,
        $userid,
        $studentname,
        $quiztypefilter,
        $statusfilter,
        $classfilter,
        $weekvalue,
        $sort,
        $dir,
        $excludestaff,
        $duedate
    );
} else {
    $rows = $manager->get_live_homework_rows(
        $categoryid,
        $courseid,
        $sectionid,
        $quizid,
        $userid,
        $studentname,
        $quiztypefilter,
        $statusfilter,
        $classfilter,
        $weekvalue,
        $sort,
        $dir,
        $excludestaff,
        $duedate
    );
}

// Populate filters based on returned data
$uniquesections = [];
$uniquequizzes = [];
$uniqueusers = [];
$uniqueuserids = [];
$uniqueduedates = [];

foreach ($rows as $r) {
    if (!empty($r->sectionid)) {
        $uniquesections[$r->sectionid] = (object)['id' => $r->sectionid, 'name' => $r->sectionname, 'coursename' => $r->coursename];
    }
    if (!empty($r->quizid)) {
        $uniquequizzes[$r->quizid] = (object)['id' => $r->quizid, 'name' => $r->quizname];
    }
    if (!empty($r->userid)) {
        $uniqueusers[$r->userid] = (object)['id' => $r->userid, 'fullname' => $r->studentname];
        $uniqueuserids[$r->userid] = true;
    }
    if (!empty($r->timeclose)) {
        $duedatekey = $r->timeclose;
        if (!isset($uniqueduedates[$duedatekey])) {
            $uniqueduedates[$duedatekey] = (object) [
                'timestamp' => $duedatekey,
                'formatted' => userdate($r->timeclose, get_string('strftimedatetime', 'langconfig')),
            ];
        }
    }
}

if (!empty($uniqueusers)) {
    uasort($uniqueusers, function($a, $b) {
        return strcmp($a->fullname, $b->fullname);
    });
}
if (!empty($uniquesections)) {
    uasort($uniquesections, function($a, $b) {
        return strcmp($a->coursename . ' ' . ($a->name ?? ''), $b->coursename . ' ' . ($b->name ?? ''));
    });
}
if (!empty($uniquequizzes)) {
    uasort($uniquequizzes, function($a, $b) {
        return strcmp($a->name, $b->name);
    });
}
if (!empty($uniqueuserids)) {
    ksort($uniqueuserids);
}
if (!empty($uniqueduedates)) {
    krsort($uniqueduedates);
    $uniqueduedates = array_slice($uniqueduedates, 0, 50, true);
}

$PAGE->requires->js_init_code("document.addEventListener('DOMContentLoaded', function() {var toggles = document.querySelectorAll('.hw-expand-toggle');for (var i = 0; i < toggles.length; i++) {toggles[i].addEventListener('click', function(e) {var targetId = this.getAttribute('data-target');var row = document.getElementById(targetId);if (!row) {return;}if (row.style.display === 'none' || row.style.display === '') {row.style.display = 'table-row';this.innerHTML = '-';} else {row.style.display = 'none';this.innerHTML = '+';}e.preventDefault();e.stopPropagation();});}});");

echo $OUTPUT->header();

// TABS
$tabs = [
    new tabobject('live', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'live']), 'Live Homework'),
    new tabobject('snapshot', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'snapshot']), 'Historical Snapshots'),
];
echo $OUTPUT->tabtree($tabs, $tab);

// BACKFILL UI (Only for snapshots tab and managers)
if ($tab === 'snapshot' && $canmanage) {
    echo '<div class="backfill-section" style="margin: 20px 0; padding: 10px; background: #fff; border: 1px solid #dee2e6; border-radius: 4px;">';
    if ($backfillmessage) {
        echo $OUTPUT->notification($backfillmessage, 'success');
    }
    echo '<form method="post" action="" class="form-inline" style="display: flex; align-items: center; gap: 10px;">';
    echo '<input type="hidden" name="tab" value="snapshot">';
    echo '<input type="hidden" name="backfill" value="1">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<label style="margin-bottom: 0;">Due dates to backfill:</label>';
    echo '<select name="backfilldates[]" class="form-control" style="max-width: 200px;">';
    foreach ($uniqueduedates as $dd) {
        echo '<option value="' . $dd->timestamp . '">' . $dd->formatted . '</option>';
    }
    echo '</select>';
    echo '<button type="submit" class="btn btn-secondary">Backfill snapshot from Due dates</button>';
    echo '</form>';
    echo '</div>';
}

?>

<div class="container-fluid">
    <div class="dashboard-controls">
        <form method="get" action="" class="form-inline">
            <input type="hidden" name="tab" value="<?php echo s($tab); ?>">
            <input type="hidden" name="filtersubmitted" value="1">
            
            <div class="hw-filter-row">
                <div class="hw-filter-group">
                    <label for="categoryid"><?php echo get_string('col_category', 'local_homeworkdashboard'); ?></label>
                    <select name="categoryid" id="categoryid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($cat->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group">
                    <label for="courseid"><?php echo get_string('col_course', 'local_homeworkdashboard'); ?></label>
                    <select name="courseid" id="courseid">
                        <option value="0"><?php echo get_string('all_courses', 'local_homeworkdashboard'); ?></option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?php echo (int)$c->id; ?>" <?php echo ((int)$courseid === (int)$c->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($c->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="hw-filter-group">
                    <label for="sectionid">Section</label>
                    <select name="sectionid" id="sectionid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($uniquesections as $s): ?>
                            <option value="<?php echo (int)$s->id; ?>" <?php echo ((int)$sectionid === (int)$s->id) ? 'selected' : ''; ?>>
                                <?php echo format_string(trim($s->coursename . ' ' . ($s->name ?? ''))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="quizid"><?php echo get_string('col_quiz', 'local_homeworkdashboard'); ?></label>
                    <select name="quizid" id="quizid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($uniquequizzes as $q): ?>
                            <option value="<?php echo (int)$q->id; ?>" <?php echo ((int)$quizid === (int)$q->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($q->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="classification"><?php echo get_string('filterclassification', 'local_homeworkdashboard'); ?></label>
                    <select name="classification" id="classification">
                        <option value=""><?php echo get_string('all'); ?></option>
                        <option value="New" <?php echo $classfilter === 'New' ? 'selected' : ''; ?>><?php echo get_string('classification_new', 'local_homeworkdashboard'); ?></option>
                        <option value="Revision" <?php echo $classfilter === 'Revision' ? 'selected' : ''; ?>><?php echo get_string('classification_revision', 'local_homeworkdashboard'); ?></option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="quiztype"><?php echo get_string('quiztype', 'local_homeworkdashboard'); ?></label>
                    <select name="quiztype" id="quiztype">
                        <option value=""><?php echo get_string('all'); ?></option>
                        <option value="Essay" <?php echo $quiztypefilter === 'Essay' ? 'selected' : ''; ?>>Essay</option>
                        <option value="Non-Essay" <?php echo $quiztypefilter === 'Non-Essay' ? 'selected' : ''; ?>>Non-Essay</option>
                    </select>
                </div>
                <div class="hw-filter-group">
                    <label for="studentname"><?php echo get_string("user"); ?></label>
                    <select name="studentname" id="studentname">
                        <option value=""><?php echo get_string("all"); ?></option>
                        <?php foreach ($uniqueusers as $u): ?>
                            <option value="<?php echo s($u->fullname); ?>" <?php echo ($studentname === $u->fullname) ? "selected" : ""; ?>>
                                <?php echo s($u->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="hw-filter-group">
                    <label for="userid">ID:</label>
                    <select name="userid" id="userid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($uniqueuserids as $uid => $dummy): ?>
                            <option value="<?php echo (int)$uid; ?>" <?php echo ((int)$userid === (int)$uid) ? 'selected' : ''; ?>>
                                <?php echo (int)$uid; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status"><?php echo get_string('status'); ?></label>
                    <select name="status" id="status">
                        <option value=""><?php echo get_string('all'); ?></option>
                        <option value="Completed" <?php echo $statusfilter === 'Completed' ? 'selected' : ''; ?>><?php echo get_string('badge_completed', 'local_homeworkdashboard'); ?></option>
                        <option value="Low grade" <?php echo $statusfilter === 'Low grade' ? 'selected' : ''; ?>><?php echo get_string('badge_lowgrade', 'local_homeworkdashboard'); ?></option>
                        <option value="No attempt" <?php echo $statusfilter === 'No attempt' ? 'selected' : ''; ?>><?php echo get_string('badge_noattempt', 'local_homeworkdashboard'); ?></option>
                    </select>
                </div>

                <div class="hw-filter-group">
                    <label for="week"><?php echo get_string('week'); ?></label>
                    <select name="week" id="week">
                        <option value=""><?php echo get_string('all'); ?></option>
                        <?php foreach ($weekoptions as $value => $label): ?>
                            <option value="<?php echo s($value); ?>" <?php echo ($weekvalue === $value) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group">
                    <label for="duedate">Due date</label>
                    <select name="duedate" id="duedate">
                        <option value="0"><?php echo get_string("all"); ?></option>
                        <?php foreach ($uniqueduedates as $dd): ?>
                            <option value="<?php echo (int)$dd->timestamp; ?>" <?php echo ((int)$duedate === (int)$dd->timestamp) ? "selected" : ""; ?>>
                                <?php echo userdate($dd->timestamp, get_string("strftimedatetime", "langconfig")); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="hw-filter-group checkbox-group" style="display: flex; align-items: flex-end; padding-bottom: 5px;">
                    <input type="checkbox" name="excludestaff" id="excludestaff" value="1" <?php echo $excludestaff ? 'checked' : ''; ?> style="margin-right: 5px;">
                    <label for="excludestaff" style="margin-bottom: 0;"><?php echo get_string('excludestaff', 'local_homeworkdashboard'); ?></label>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?php echo get_string('filter'); ?></button>
                    <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => $tab]))->out(false); ?>" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($duedate > 0): ?>
        <div class="alert alert-info">
            Filtering by Due Date: <strong><?php echo userdate($duedate, get_string('strftimedatetime', 'langconfig')); ?></strong>
            <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => $tab]))->out(false); ?>" class="ml-2">Clear</a>
        </div>
    <?php endif; ?>

    <div class="dashboard-table-wrapper">
        <table class="dashboard-table table table-striped">
            <thead class="thead-dark">
                <tr>
                    <th></th> <!-- Expand -->
                    <th class="sortable-column" data-sort="userid">ID <?php echo local_homeworkdashboard_sort_arrows('userid', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="studentname"><?php echo get_string('col_studentname', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('studentname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="categoryname"><?php echo get_string('col_category', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('categoryname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="coursename"><?php echo get_string('col_course', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('coursename', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="quizname"><?php echo get_string('col_quiz', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('quizname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="status"><?php echo get_string('status'); ?> <?php echo local_homeworkdashboard_sort_arrows('status', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="attemptno"><?php echo get_string('col_attempt', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('attemptno', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="classification">Activity classification <?php echo local_homeworkdashboard_sort_arrows('classification', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="quiz_type">Quiz type <?php echo local_homeworkdashboard_sort_arrows('quiz_type', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="timeclose">Due date <?php echo local_homeworkdashboard_sort_arrows('timeclose', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="timefinish">Finished <?php echo local_homeworkdashboard_sort_arrows('timefinish', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="time_taken">Duration <?php echo local_homeworkdashboard_sort_arrows('time_taken', $sort, $dir); ?></th>
                    <th>Score</th>
                    <th class="sortable-column" data-sort="score">% <?php echo local_homeworkdashboard_sort_arrows('score', $sort, $dir); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="14" class="no-data"><?php echo get_string('nothingtodisplay'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $rowkey = (int)$row->userid . '_' . (int)$row->quizid . '_' . (int)$row->timeclose;
                            $childid = 'child_' . $rowkey;
                            // Calculate duration
                            $durationstr = '';
                            if ($row->timestart > 0 && $row->timefinish > 0 && $row->timefinish > $row->timestart) {
                                $duration = $row->timefinish - $row->timestart;
                                $hours = floor($duration / 3600);
                                $minutes = floor(($duration % 3600) / 60);
                                $seconds = $duration % 60;
                                if ($hours > 0) {
                                    $durationstr = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
                                } else if ($minutes > 0) {
                                    $durationstr = sprintf('%dm %ds', $minutes, $seconds);
                                } else {
                                    $durationstr = sprintf('%ds', $seconds);
                                }
                            }

                            $rawscore = isset($row->score) ? (float)$row->score : 0.0;
                            $maxscore = isset($row->maxscore) ? (float)$row->maxscore : 0.0;
                            $percent = 0.0;
                            if ($maxscore > 0) {
                                $percent = ($rawscore / $maxscore) * 100.0;
                            }
                            $parentid = 'parent_' . $rowkey;
                        ?>
                        <tr class="hw-main-row" id="<?php echo $parentid; ?>">
                            <td>
                                <a href="#" class="hw-expand-toggle" data-target="<?php echo $childid; ?>">+</a>
                            </td>
                            <td><?php echo (int)$row->userid; ?></td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["userid" => (int)$row->userid]))->out(false); ?>">
                                    <?php echo s($row->studentname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["categoryid" => (int)$row->categoryid]))->out(false); ?>">
                                    <?php echo s($row->categoryname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["courseid" => (int)$row->courseid]))->out(false); ?>">
                                    <?php echo s($row->coursename); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["quizid" => (int)$row->quizid]))->out(false); ?>">
                                    <?php echo s($row->quizname); ?>
                                </a>
                                &nbsp;
                                <a href="<?php echo (new moodle_url("/mod/quiz/view.php", ["id" => $row->cmid]))->out(false); ?>" class="quiz-link" target="_blank">
                                    <?php echo get_string("view"); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                    $st = $row->status;
                                    $badgeclass = "hw-badge-lowgrade";
                                    $badgetext = $st;
                                    if ($st === "Completed") {
                                        $badgeclass = "hw-badge-completed";
                                        $badgetext = "Done";
                                    } else if ($st === "Low grade") {
                                        $badgeclass = "hw-badge-lowgrade";
                                        $badgetext = "? Policy";
                                    } else if ($st === "No attempt") {
                                        $badgeclass = "hw-badge-noattempt";
                                        $badgetext = "To do";
                                    }
                                    echo '<span class="hw-badge ' . $badgeclass . '">' . s($badgetext) . '</span>';
                                ?>
                            </td>
                            <td><?php echo (int)$row->attemptno; ?></td>
                            <td>
                                <?php 
                                    $cls = $row->classification;
                                    $clsbadge = "hw-classification-badge";
                                    $clsmod = "";
                                    if ($cls === "New") {
                                        $clsmod = "hw-classification-new";
                                    } else if ($cls === "Revision") {
                                        $clsmod = "hw-classification-revision";
                                    }
                                    echo '<span class="hw-classification-badge ' . $clsmod . '">' . s($cls) . '</span>';
                                ?>
                            </td>
                            <td><?php echo s($row->quiz_type); ?></td>
                            <td>
                                <?php 
                                    if ($row->timeclose > 0) {
                                        echo '<a href="' . (new moodle_url('/local/homeworkdashboard/index.php', ['duedate' => $row->timeclose]))->out(false) . '">';
                                        echo userdate($row->timeclose, get_string("strftimedatetime", "langconfig")); 
                                        echo '</a>';
                                    } else {
                                        echo "-";
                                    }
                                ?>
                            </td>
                            <td>
                                <?php 
                                    if ($row->timefinish > 0) {
                                        echo userdate($row->timefinish, get_string('strftimedatetime', 'langconfig')); 
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                            <td><?php echo $durationstr; ?></td>
                            <td>
                                <?php if ($maxscore > 0.0 && $rawscore > 0.0): ?>
                                    <?php echo format_float($rawscore, 2) . ' / ' . format_float($maxscore, 2); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($percent > 0.0): ?>
                                    <?php echo format_float($percent, 2) . '%'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <!-- Expansion row -->
                        <tr class="hw-attempts-row" id="<?php echo $childid; ?>" style="display:none;">
                            <td colspan="14" style="padding: 0; border: none;">
                                <?php if (empty($row->attempts)): ?>
                                    <div class="no-data">No attempts found for this user.</div>
                                <?php else: ?>
                                    <?php 
                                        // Sort attempts by attempt number ASC
                                        $attempts = $row->attempts;
                                        usort($attempts, function($a, $b) {
                                            return $a->attempt - $b->attempt;
                                        });
                                    ?>
                                    <table class="table table-sm hw-attempts-table" style="margin: 0; background-color: #f8f9fa;">
                                        <thead>
                                            <tr>
                                                <th>Attempt</th>
                                                <th><?php echo get_string('status'); ?></th>
                                                <th>Due date</th>
                                                <th>Finished</th>
                                                <th>Duration</th>
                                                <th>Score</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attempts as $attempt): ?>
                                                <?php
                                                    $timestart = (int)$attempt->timestart;
                                                    $timefinish = (int)$attempt->timefinish;
                                                    $durationstr = '';
                                                    $duration = 0;
                                                    if ($timestart > 0 && $timefinish > 0 && $timefinish > $timestart) {
                                                        $duration = $timefinish - $timestart;
                                                        $hours = (int) floor($duration / 3600);
                                                        $minutes = (int) floor(($duration % 3600) / 60);
                                                        $seconds = (int) ($duration % 60);
                                                        if ($hours > 0) {
                                                            $durationstr = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
                                                        } else if ($minutes > 0) {
                                                            $durationstr = sprintf('%dm %ds', $minutes, $seconds);
                                                        } else {
                                                            $durationstr = sprintf('%ds', $seconds);
                                                        }
                                                    }
                                                    $score = $attempt->sumgrades !== null ? (float)$attempt->sumgrades : 0.0;
                                                    $percent = ($row->maxscore > 0 && $score > 0) ? round(($score / $row->maxscore) * 100.0, 2) : 0.0;

                                                    $isshort = ($duration > 0 && $duration < 180);

                                                    if ($isshort) {
                                                        $statuslabel = get_string('attempt_status_short', 'local_homeworkdashboard');
                                                    } else if ($percent >= 30.0) {
                                                        $statuslabel = get_string('attempt_status_completed', 'local_homeworkdashboard');
                                                    } else if ($percent > 0.0) {
                                                        $statuslabel = get_string('attempt_status_below', 'local_homeworkdashboard');
                                                    } else if ($duration > 0) {
                                                        $statuslabel = get_string('attempt_status_attempted', 'local_homeworkdashboard');
                                                    } else {
                                                        $statuslabel = s($attempt->state);
                                                    }
                                                ?>
                                                <tr class="<?php echo $isshort ? 'hw-attempt-short' : ''; ?>">
                                                    <td>
                                                        <?php if (!empty($attempt->id)): ?>
                                                            <a href="<?php echo (new moodle_url('/mod/quiz/review.php', ['attempt' => (int)$attempt->id]))->out(false); ?>" target="_blank">
                                                                <?php echo (int)$attempt->attempt; ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <?php echo (int)$attempt->attempt; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo s($statuslabel); ?></td>
                                                    <td>
                                                        <?php if (!empty($row->timeclose)): ?>
                                                            <?php echo userdate($row->timeclose, get_string('strftimedatetime', 'langconfig')); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($attempt->timefinish)): ?>
                                                            <?php echo userdate($attempt->timefinish, get_string('strftimedatetime', 'langconfig')); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo s($durationstr); ?></td>
                                                    <td>
                                                        <?php if ($row->maxscore > 0 && $score > 0): ?>
                                                            <?php echo format_float($score, 2) . ' / ' . format_float($row->maxscore, 2); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($percent > 0.0): ?>
                                                            <?php echo format_float($percent, 2) . '%'; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sortable-column').forEach(function(header) {
        header.addEventListener('click', function() {
            var sortField = this.getAttribute('data-sort');
            var currentSort = '<?php echo $sort; ?>';
            var currentDir  = '<?php echo $dir; ?>';
            var newDir = 'ASC';
            if (currentSort === sortField && currentDir === 'ASC') {
                newDir = 'DESC';
            }
            var url = new URL(window.location.href);
            url.searchParams.set('sort', sortField);
            url.searchParams.set('dir', newDir);
            window.location.href = url.toString();
        });
    });
});
</script>

<?php
echo $OUTPUT->footer();
