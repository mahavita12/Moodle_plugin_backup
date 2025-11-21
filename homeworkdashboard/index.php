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

function local_homeworkdashboard_sort_arrows(string $column, string $current_sort, string $current_dir): string {
    $up_class = '';
    $down_class = '';
    if ($current_sort === $column) {
        if (strtoupper($current_dir) === 'ASC') {
            $up_class = ' active';
        } else {
            $down_class = ' active';
        }
    }
    return '<span class="sort-arrows">'
        . '<span class="arrow up' . $up_class . '">▲</span>'
        . '<span class="arrow down' . $down_class . '">▼</span>'
        . '</span>';
}

// Categories, default to "Category 1" if present.
$categories = [];
try {
    $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
    if (empty($categoryid)) {
        $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id', IGNORE_MISSING);
        if ($catrow) {
            $categoryid = (int)$catrow->id;
        }
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
    $backfillweeks = optional_param('backfillweeks', 4, PARAM_INT);
    $backfillweeks = max(1, min(52, $backfillweeks));
    $created = $manager->backfill_snapshots_from_events($backfillweeks);
    $backfillmessage = get_string('backfill_done', 'local_homeworkdashboard', $created);
}

$weekoptions = [];
$now = time();
$basesunday = ((int)date('w', $now) === 0) ? $now : strtotime('next Sunday', $now);
if ($basesunday === false) {
    $basesunday = $now;
}
$currsunday = $basesunday + (7 * 24 * 60 * 60);
for ($i = 0; $i < 12; $i++) {
    $sunday = $currsunday - ($i * 7 * 24 * 60 * 60);
    $value = date('Y-m-d', $sunday);
    $label = userdate($sunday, get_string('strftimedateshort', 'langconfig'));
    $weekoptions[$value] = $label;
}

$allrows = $manager->get_homework_rows(
    $categoryid,
    $courseid,
    $sectionid,
    0,
    0,
    '',
    $quiztypefilter,
    '',
    $classfilter,
    $weekvalue,
    $sort,
    $dir
);

$uniqueusers = [];
$uniqueuserids = [];
$uniquesections = [];
$uniquequizzes = [];

foreach ($allrows as $r) {
    if (!isset($uniqueusers[$r->userid])) {
        $uniqueusers[$r->userid] = (object) [
            'id' => $r->userid,
            'fullname' => $r->studentname,
        ];
    }
    if (!isset($uniqueuserids[$r->userid])) {
        $uniqueuserids[$r->userid] = (object) [
            'id' => $r->userid,
            'userid' => $r->userid,
        ];
    }
    if (!empty($r->sectionid)) {
        if (!isset($uniquesections[$r->sectionid])) {
            $uniquesections[$r->sectionid] = (object) [
                'id' => $r->sectionid,
                'name' => $r->sectionname,
                'section' => $r->sectionnumber,
                'coursename' => $r->coursename,
            ];
        }
    }
    if (!isset($uniquequizzes[$r->quizid])) {
        $uniquequizzes[$r->quizid] = (object) [
            'id' => $r->quizid,
            'name' => $r->quizname,
        ];
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

$rows = $manager->get_homework_rows(
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
    $dir
);

$PAGE->requires->js_init_code("document.addEventListener('DOMContentLoaded', function() {var toggles = document.querySelectorAll('.hw-expand-toggle');for (var i = 0; i < toggles.length; i++) {toggles[i].addEventListener('click', function(e) {var targetId = this.getAttribute('data-target');var row = document.getElementById(targetId);if (!row) {return;}if (row.style.display === 'none' || row.style.display === '') {row.style.display = 'table-row';this.innerHTML = '-';} else {row.style.display = 'none';this.innerHTML = '+';}e.preventDefault();e.stopPropagation();});}});");

echo $OUTPUT->header();

if ($backfillmessage !== '') {
    echo $OUTPUT->notification($backfillmessage, 'notifysuccess');
}

$baseurl = new moodle_url('/local/homeworkdashboard/index.php');
?>
<div class="essay-dashboard-container homework-dashboard-container">
    <?php if ($canmanage): ?>
    <div class="hw-backfill">
        <form method="post" action="<?php echo $baseurl->out(false); ?>" class="filter-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>" />
            <label for="backfillweeks"><?php echo get_string('backfill_weeks', 'local_homeworkdashboard'); ?></label>
            <input type="number" name="backfillweeks" id="backfillweeks" value="4" min="1" max="52" />
            <button type="submit" name="backfill" value="1" class="btn btn-secondary"><?php echo get_string('backfill_from_events', 'local_homeworkdashboard'); ?></button>
        </form>
    </div>
    <?php endif; ?>
    <div class="dashboard-filters">
        <form method="get" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="categoryid"><?php echo get_string('filtercategory', 'local_homeworkdashboard'); ?></label>
                    <select name="categoryid" id="categoryid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($cat->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="courseid"><?php echo get_string('filtercourse', 'local_homeworkdashboard'); ?></label>
                    <select name="courseid" id="courseid">
                        <option value="0"><?php echo get_string('allcourses', 'local_homeworkdashboard'); ?></option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?php echo (int)$c->id; ?>" <?php echo ((int)$courseid === (int)$c->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($c->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="userid"><?php echo get_string('user'); ?></label>
                    <select name="userid" id="userid">
                        <option value="0"><?php echo get_string('all'); ?></option>
                        <?php foreach ($uniqueusers as $u): ?>
                            <option value="<?php echo (int)$u->id; ?>" <?php echo ((int)$userid === (int)$u->id) ? 'selected' : ''; ?>>
                                <?php echo format_string($u->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sectionid"><?php echo get_string('section'); ?></label>
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

                <div class="filter-group">
                    <label for="status"><?php echo get_string('status'); ?></label>
                    <select name="status" id="status">
                        <option value=""><?php echo get_string('all'); ?></option>
                        <option value="Completed" <?php echo $statusfilter === 'Completed' ? 'selected' : ''; ?>><?php echo get_string('badge_completed', 'local_homeworkdashboard'); ?></option>
                        <option value="Low grade" <?php echo $statusfilter === 'Low grade' ? 'selected' : ''; ?>><?php echo get_string('badge_lowgrade', 'local_homeworkdashboard'); ?></option>
                        <option value="No attempt" <?php echo $statusfilter === 'No attempt' ? 'selected' : ''; ?>><?php echo get_string('badge_noattempt', 'local_homeworkdashboard'); ?></option>
                    </select>
                </div>

                <div class="filter-group">
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

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?php echo get_string('filter'); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='<?php echo $baseurl->out(false); ?>';"><?php echo get_string('reset'); ?></button>
                </div>
            </div>
            <input type="hidden" name="sort" value="<?php echo s($sort); ?>">
            <input type="hidden" name="dir" value="<?php echo s($dir); ?>">
        </form>
    </div>

    <div class="dashboard-table-container">
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th></th>
                    <th class="sortable-column" data-sort="studentname">
                        <?php echo get_string('fullname'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('studentname', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="categoryname">
                        <?php echo get_string('category'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('categoryname', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="coursename">
                        <?php echo get_string('course'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('coursename', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="quizname">
                        <?php echo get_string('col_quiz', 'local_homeworkdashboard'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('quizname', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="attemptno">
                        <?php echo get_string('col_attempt', 'local_homeworkdashboard'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('attemptno', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="classification">
                        <?php echo get_string('filterclassification', 'local_homeworkdashboard'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('classification', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="quiz_type">
                        <?php echo get_string('quiztype', 'local_homeworkdashboard'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('quiz_type', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="status">
                        <?php echo get_string('status'); ?>
                        <?php echo local_homeworkdashboard_sort_arrows('status', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="timeclose">
                        Due date
                        <?php echo local_homeworkdashboard_sort_arrows('timeclose', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="timefinish">
                        Finished
                        <?php echo local_homeworkdashboard_sort_arrows('timefinish', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="time_taken">
                        Duration
                        <?php echo local_homeworkdashboard_sort_arrows('time_taken', $sort, $dir); ?>
                    </th>
                    <th>
                        Score
                    </th>
                    <th class="sortable-column" data-sort="score">
                        %
                        <?php echo local_homeworkdashboard_sort_arrows('score', $sort, $dir); ?>
                    </th>
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
                            $parentid = 'hw-parent-' . $row->userid . '-' . $row->quizid;
                            $childid = 'hw-attempts-' . $row->userid . '-' . $row->quizid;
                            $attempts = $manager->get_homework_attempts_for_user_quiz($row->userid, $row->quizid);
                        ?>
                        <tr class="hw-parent-row" id="<?php echo $parentid; ?>">
                            <td>
                                <button type="button" class="hw-expand-toggle" data-target="<?php echo $childid; ?>">+</button>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['userid' => (int)$row->userid]))->out(false); ?>">
                                    <?php echo format_string($row->studentname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['categoryid' => (int)$row->categoryid]))->out(false); ?>">
                                    <?php echo format_string($row->categoryname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['courseid' => (int)$row->courseid]))->out(false); ?>">
                                    <?php echo format_string($row->coursename); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['quizid' => (int)$row->quizid]))->out(false); ?>">
                                    <?php echo format_string($row->quizname); ?>
                                </a>
                                &nbsp;
                                <a href="<?php echo (new moodle_url('/mod/quiz/view.php', ['id' => $row->cmid]))->out(false); ?>" class="quiz-link" target="_blank">
                                    <?php echo get_string('view'); ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($row->lastattemptid)): ?>
                                    <a href="<?php echo (new moodle_url('/mod/quiz/review.php', ['attempt' => (int)$row->lastattemptid]))->out(false); ?>" target="_blank">
                                        <?php echo (int)$row->attemptno; ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo (int)$row->attemptno; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row->classification !== ''): ?>
                                    <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['classification' => $row->classification]))->out(false); ?>">
                                        <?php echo s($row->classification); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo s($row->classification); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row->quiz_type !== ''): ?>
                                    <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['quiztype' => $row->quiz_type]))->out(false); ?>">
                                        <?php echo s($row->quiz_type); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo s($row->quiz_type); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $statusurl = new moodle_url('/local/homeworkdashboard/index.php', ['status' => $row->status]); ?>
                                <a href="<?php echo $statusurl->out(false); ?>">
                                    <?php if ($row->status === 'Completed'): ?>
                                        <span class="hw-badge hw-badge-completed"><span class="hw-badge-icon">&#10003;</span><?php echo get_string('badge_completed', 'local_homeworkdashboard'); ?></span>
                                    <?php elseif ($row->status === 'Low grade'): ?>
                                        <span class="hw-badge hw-badge-lowgrade"><span class="hw-badge-icon">?</span><?php echo get_string('badge_lowgrade', 'local_homeworkdashboard'); ?></span>
                                    <?php else: ?>
                                        <span class="hw-badge hw-badge-noattempt"><span class="hw-badge-icon">&#10006;</span><?php echo get_string('badge_noattempt', 'local_homeworkdashboard'); ?></span>
                                    <?php endif; ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($row->timeclose)): ?>
                                    <?php
                                        $closedts = (int)$row->timeclose;
                                        $closedSunday = strtotime('last Sunday', $closedts);
                                        if ($closedSunday === false) {
                                            $closedSunday = $closedts;
                                        }
                                        $weekvalueforclosed = date('Y-m-d', $closedSunday);
                                        $weekurl = new moodle_url('/local/homeworkdashboard/index.php', ['week' => $weekvalueforclosed]);
                                    ?>
                                    <a href="<?php echo $weekurl->out(false); ?>">
                                        <?php echo userdate($row->timeclose, get_string('strftimedatetime', 'langconfig')); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row->timefinish)): ?>
                                    <?php echo userdate($row->timefinish, get_string('strftimedatetime', 'langconfig')); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo s($row->time_taken); ?></td>
                            <?php
                                $rawscore = isset($row->score) ? (float)$row->score : 0.0;
                                $maxscore = isset($row->maxscore) ? (float)$row->maxscore : 0.0;
                                $percent  = isset($row->percentage) ? (float)$row->percentage : 0.0;
                            ?>
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
                        <tr class="hw-attempts-row" id="<?php echo $childid; ?>" style="display:none;">
                            <td colspan="14">
                                <?php if (empty($attempts)): ?>
                                    <div class="no-data"><?php echo get_string('none'); ?></div>
                                <?php else: ?>
                                    <table class="dashboard-table hw-attempts-table">
                                        <thead>
                                            <tr>
                                                <th><?php echo get_string('col_attempt', 'local_homeworkdashboard'); ?></th>
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
