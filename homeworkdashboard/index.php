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

// Check capability for management (Staff vs Student)
$canmanage = has_capability('local/homeworkdashboard:manage', $context) || is_siteadmin();

$tab           = optional_param('tab', 'live', PARAM_ALPHA);
$userids       = optional_param_array('userid', [], PARAM_INT); // Retrieve user IDs from dropdown
$categoryid    = optional_param('categoryid', 0, PARAM_INT);
$courseids     = optional_param_array('courseid', [0], PARAM_INT);
$sectionid     = optional_param('sectionid', 0, PARAM_INT);
$quizids       = optional_param_array('quizid', [0], PARAM_INT);
$studentname   = optional_param('studentname', '', PARAM_TEXT);
$statusfilter  = optional_param('status', '', PARAM_TEXT);
$quiztypefilter = optional_param('quiztype', '', PARAM_TEXT);
$classfilter   = optional_param('classification', '', PARAM_ALPHA);
// $weekvalue     = optional_param('week', '', PARAM_TEXT);
$sort          = optional_param('sort', 'timeclose', PARAM_ALPHA);
$dir           = optional_param('dir', 'DESC', PARAM_ALPHA);
$filtersubmitted = optional_param('filtersubmitted', 0, PARAM_BOOL);

if ($filtersubmitted) {
    $excludestaff = optional_param('excludestaff', 0, PARAM_BOOL);
} else {
    $excludestaff = 1;
}
$duedates      = optional_param_array('duedate', [0], PARAM_INT);
$userids       = optional_param_array('userid', [0], PARAM_INT);

// Enforce Student View Restrictions
if (!$canmanage) {
    // Force view to current user only
    $userids = [$USER->id];
    // Disable exclude staff (irrelevant for single user)
    $excludestaff = 0;
    // Clear student name filter to avoid confusion
    $studentname = '';
}

// If a specific due date is selected, clear the week filter to avoid confusion/conflict
$hasduedate = !empty(array_filter($duedates, function($d) { return $d > 0; }));
if ($hasduedate) {
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
} else {
    $courses = $DB->get_records('course', ['visible' => 1], 'fullname ASC', 'id, fullname');
}

// Filter courses by selected user(s)
// Use $userids (from dropdown)
// Sanitize userids first (remove 0 or invalid)
$filter_userids = array_filter($userids, function($id) { return $id > 0; });

if (!empty($filter_userids)) {
    error_log("HM_DEBUG: Filtering courses for " . count($filter_userids) . " users.");
    
    $enrolled_course_ids = [];
    foreach ($filter_userids as $uid) {
        // Get enrolled courses for this user
        // true = only active enrollments, 'id' = return only fields needed (id is key)
        $enrolled = enrol_get_users_courses($uid, true, 'id');
        foreach ($enrolled as $ec) {
            $enrolled_course_ids[$ec->id] = true;
        }
    }
    
    error_log("HM_DEBUG: Found " . count($enrolled_course_ids) . " unique enrolled courses.");

    // Intersect: Keep only courses that are in the enrolled list
    // If multiple users selected, we show union of their courses (any course ANY selected user is in)
    $courses = array_filter($courses, function($c) use ($enrolled_course_ids) {
        return isset($enrolled_course_ids[$c->id]);
    });
    
    error_log("HM_DEBUG: Filtered course list to " . count($courses) . " items.");
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

// Week options removed
$weekvalue = ''; // Ensure variable exists but is empty

// Fetch data
$rows = [];
if ($tab === "snapshot") {
    $rows = $manager->get_snapshot_homework_rows(
        $categoryid,
        $courseids,
        $sectionid,
        $quizids,
        $userids,
        $studentname,
        $quiztypefilter,
        $statusfilter,
        $classfilter,
        $weekvalue,
        $sort,
        $dir,
        $excludestaff,
        $duedates,
        true // pastonly
    );
} elseif ($tab === "reports" && $canmanage) {
    // Reports Tab Logic
    // Use the main duedates variable
    $report_duedates = array_filter($duedates, function($d) { return $d > 0; });

    $customstart = 0;
    $customend = 0;
    
    // If no due dates selected, default to last 30 days to future
    if (empty($report_duedates)) {
        $customstart = strtotime('-30 days');
        $customend = strtotime('+30 days'); 
    }

    $raw_rows = $manager->get_snapshot_homework_rows(
        $categoryid,
        $courseids,
        $sectionid,
        $quizids,
        $userids,
        $studentname,
        $quiztypefilter,
        $statusfilter,
        $classfilter,
        null, // No week value
        'timeclose', // Default sort by date
        'DESC',
        $excludestaff,
        $report_duedates, // Pass selected due dates
        true // pastonly
    );
    error_log("HM_DEBUG: Raw Rows Count: " . count($raw_rows));

    // Group by Student + Due Date
    $grouped_rows = [];
    foreach ($raw_rows as $r) {
        $key = $r->userid . '_' . $r->timeclose;
        if (!isset($grouped_rows[$key])) {
            $grouped_rows[$key] = (object)[
                'userid' => $r->userid,
                'studentname' => $r->studentname,
                'email' => $r->email,
                'parent1' => $r->parent1,
                'parent2' => $r->parent2,
                'timeclose' => $r->timeclose,
                'reportid' => $DB->get_field('local_homework_reports', 'id', ['userid' => $r->userid, 'timeclose' => $r->timeclose]),
                'next_due_date' => null,
                'courses' => [],
                'categories' => [],
                // 'classifications' => [], // Removed column
                'activities' => [],
                'status' => 'Not Sent', // Placeholder
            ];
        }
        $grouped_rows[$key]->courses[$r->courseid] = ['name' => $r->coursename, 'category' => $r->categoryname];
        $grouped_rows[$key]->categories[$r->categoryid] = $r->categoryname;
        
        // Capture Next Due Date for Category 1
        if (strcasecmp($r->categoryname, 'Category 1') === 0 && !empty($r->next_due_date)) {
            if (empty($grouped_rows[$key]->next_due_date) || $r->next_due_date < $grouped_rows[$key]->next_due_date) {
                $grouped_rows[$key]->next_due_date = $r->next_due_date;
                $grouped_rows[$key]->next_due_date_courseid = $r->courseid; // Store course ID for fetching activities
            }
        }

        // Store activity with classification and category
        $grouped_rows[$key]->activities[] = (object)[
            'name' => $r->quizname,
            'classification' => $r->classification,
            'category' => $r->categoryname
        ];
    }

    // Sort Categories and Courses
    foreach ($grouped_rows as $g) {
        // Fetch Activities 2 if applicable
        $g->activities_2 = [];
        if (!empty($g->next_due_date)) {
            // Get all course IDs for this user/group
            $g_courseids = array_keys($g->courses);
            $g->activities_2 = $manager->get_quizzes_for_deadline($g_courseids, $g->next_due_date);
        }

        // Sort Categories: Category 1 first
        uasort($g->categories, function($a, $b) {
            $a_is_cat1 = strcasecmp($a, 'Category 1') === 0;
            $b_is_cat1 = strcasecmp($b, 'Category 1') === 0;
            if ($a_is_cat1 && !$b_is_cat1) return -1;
            if (!$a_is_cat1 && $b_is_cat1) return 1;
            return strcasecmp($a, $b);
        });

        // Sort Courses: Category 1 courses first
        uasort($g->courses, function($a, $b) {
            $a_cat1 = strcasecmp($a['category'], 'Category 1') === 0;
            $b_cat1 = strcasecmp($b['category'], 'Category 1') === 0;
            if ($a_cat1 && !$b_cat1) return -1;
            if (!$a_cat1 && $b_cat1) return 1;
            return strcasecmp($a['name'], $b['name']);
        });

        // Sort Activities 1: Category 1 activities first
        usort($g->activities, function($a, $b) {
            $a_cat1 = strcasecmp($a->category, 'Category 1') === 0;
            $b_cat1 = strcasecmp($b->category, 'Category 1') === 0;
            if ($a_cat1 && !$b_cat1) return -1;
            if (!$a_cat1 && $b_cat1) return 1;
            return strcasecmp($a->name, $b->name);
        });
    }

    $rows = array_values($grouped_rows);

} else {
    // Live Tab Logic
    $rows = $manager->get_live_homework_rows(
        $categoryid,
        $courseids,
        $sectionid,
        $quizids,
        $userids,
        $studentname,
        $quiztypefilter,
        $statusfilter,
        $classfilter,
        $weekvalue,
        $sort,
        $dir,
        $excludestaff,
        $duedates
    );
}

// Populate filters based on returned data
$uniquesections = [];
$uniquequizzes = [];
$uniqueuserids = [];
$uniqueduedates = [];

foreach ($rows as $r) {
    if (!empty($r->sectionid)) {
        $uniquesections[$r->sectionid] = (object)['id' => $r->sectionid, 'name' => $r->sectionname, 'coursename' => $r->coursename];
    }
    if (!empty($r->quizid)) {
        $uniquequizzes[$r->quizid] = (object)['id' => $r->quizid, 'name' => $r->quizname];
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

if ($tab === 'reports') {
    $uniqueduedates = $manager->get_all_distinct_due_dates();
}

// Populate User Dropdown from Context (Category/Course)
$uniqueusers = $manager->get_users_for_filter_context($categoryid, $courseids, $excludestaff);

// Fallback: If no users found (e.g. no course selected), try to populate from rows as backup?
// Or just leave empty. The previous behavior was from rows.
if (empty($uniqueusers) && !empty($rows)) {
    foreach ($rows as $r) {
        if (!empty($r->userid)) {
            $uniqueusers[$r->userid] = (object)['id' => $r->userid, 'fullname' => $r->studentname];
        }
    }
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
if ($canmanage) {
    $tabs[] = new tabobject('reports', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'reports']), 'Homework Reports');
}
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
    echo '<select name="backfilldates[]" id="backfill_duedates" class="form-control">';
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
    <div class="essay-dashboard-container">
        <div class="dashboard-filters">
            <form method="get" action="" class="filter-form">
                <input type="hidden" name="tab" value="<?php echo s($tab); ?>">
                <input type="hidden" name="filtersubmitted" value="1">
                
                <div class="filter-row">
                    <?php if (true): ?>
                        <div class="filter-group">
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
                        <div class="filter-group">
                            <label for="courseid"><?php echo get_string('col_course', 'local_homeworkdashboard'); ?></label>
                            <select name="courseid[]" id="courseid" multiple="multiple">
                                <option value="0"><?php echo get_string('allcourses', 'local_homeworkdashboard'); ?></option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo (int)$c->id; ?>" <?php echo in_array((int)$c->id, $courseids) ? 'selected' : ''; ?>>
                                        <?php echo format_string($c->fullname); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
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
                            <select name="quizid[]" id="quizid" multiple="multiple">
                                <option value="0"><?php echo get_string('all'); ?></option>
                                <?php foreach ($uniquequizzes as $q): ?>
                                    <option value="<?php echo (int)$q->id; ?>" <?php echo in_array((int)$q->id, $quizids) ? 'selected' : ''; ?>>
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

                    <?php endif; ?>

                    <?php if ($canmanage): ?>
                        <div class="filter-group">
                            <label for="studentname"><?php echo get_string("user"); ?></label>
                            <select name="userid[]" id="studentname" multiple="multiple">
                                <option value="0"><?php echo get_string("all"); ?></option>
                                <?php
                                // Populate students independently for Reports tab
                                $report_students = $manager->get_all_students_with_homework();
                                foreach ($report_students as $u): ?>
                                    <option value="<?php echo (int)$u->id; ?>" <?php echo in_array((int)$u->id, $userids) ? "selected" : ""; ?>>
                                        <?php echo s(fullname($u)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (true): ?>
                        <div class="filter-group">
                            <label for="duedate">Due date</label>
                            <select name="duedate[]" id="duedate" multiple="multiple">
                                <option value="0"><?php echo get_string("all"); ?></option>
                                <?php foreach ($uniqueduedates as $dd): ?>
                                    <option value="<?php echo (int)$dd->timestamp; ?>" <?php echo in_array((int)$dd->timestamp, $duedates) ? "selected" : ""; ?>>
                                        <?php echo userdate($dd->timestamp, get_string("strftimedatetime", "langconfig")); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>


                    <?php endif; ?>

                    <?php if ($canmanage): ?>
                    <div class="filter-group checkbox-group" style="display: flex; align-items: flex-end; padding-bottom: 5px;">
                        <input type="checkbox" name="excludestaff" id="excludestaff" value="1" <?php echo $excludestaff ? 'checked' : ''; ?> style="margin-right: 5px;">
                        <label for="excludestaff" style="margin-bottom: 0;"><?php echo get_string('excludestaff', 'local_homeworkdashboard'); ?></label>
                    </div>
                    <?php endif; ?>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><?php echo get_string('filter'); ?></button>
                        <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => $tab]))->out(false); ?>" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>

    <?php if ($hasduedate): ?>
        <div class="alert alert-info">
            Filtering by Due Date: 
            <strong>
                <?php 
                $dates = array_filter($duedates, function($d) { return $d > 0; });
                $datestrs = array_map(function($d) { return userdate($d, get_string('strftimedatetime', 'langconfig')); }, $dates);
                echo implode(', ', $datestrs); 
                ?>
            </strong>
            <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => $tab]))->out(false); ?>" class="ml-2">Clear</a>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'reports' && $canmanage): ?>
    <!-- REPORTS TABLE -->
    <div class="dashboard-table-wrapper">
        <!-- Bulk Actions -->
        <div class="bulk-actions-container" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
            <select id="bulk-action-select" class="custom-select" style="width: auto;">
                <option value="">With selected...</option>
                <option value="sendreport">Send Report</option>
            </select>
            <button id="bulk-action-btn" class="btn btn-primary">Apply</button>
        </div>

        <table class="dashboard-table table table-striped" id="reports-table">
            <thead class="thead-dark">
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="select-all-reports"></th>
                    <th>Name</th>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Parent 1</th>
                    <th>Parent 2</th>
                    <th>Due Date 1</th>
                    <th>Due Date 2</th>
                    <th>Categories</th>
                    <th>Courses</th>
                    <!-- <th>Classifications</th> Removed -->
                    <th>Activities 1</th>
                    <th>Activities 2</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="14" class="no-data"><?php echo get_string('nothingtodisplay'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="report-checkbox" 
                                       data-userid="<?php echo $row->userid; ?>" 
                                       data-duedate="<?php echo $row->timeclose; ?>"
                                       data-studentname="<?php echo s($row->studentname); ?>">
                            </td>
                            <td><?php echo s($row->studentname); ?></td>
                            <td><?php echo (int)$row->userid; ?></td>
                            <td><?php echo s($row->email); ?></td>
                            <td>
                                <?php if (!empty($row->parent1) && !empty($row->parent1->name)): ?>
                                    <div><?php echo s($row->parent1->name); ?></div>
                                    <?php if (!empty($row->parent1->email)): ?>
                                        <div><a href="mailto:<?php echo s($row->parent1->email); ?>"><?php echo s($row->parent1->email); ?></a></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row->parent1->phone)): ?>
                                        <div><?php echo s($row->parent1->phone); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row->parent1->lang)): ?>
                                        <div><?php echo s($row->parent1->lang); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row->parent2) && !empty($row->parent2->name)): ?>
                                    <div><?php echo s($row->parent2->name); ?></div>
                                    <?php if (!empty($row->parent2->email)): ?>
                                        <div><a href="mailto:<?php echo s($row->parent2->email); ?>"><?php echo s($row->parent2->email); ?></a></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row->parent2->phone)): ?>
                                        <div><?php echo s($row->parent2->phone); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row->parent2->lang)): ?>
                                        <div><?php echo s($row->parent2->lang); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo userdate($row->timeclose, get_string('strftimedate', 'langconfig')); ?></td>
                            <td>
                                <?php echo !empty($row->next_due_date) ? userdate($row->next_due_date, get_string('strftimedate', 'langconfig')) : '-'; ?>
                            </td>
                            <td>
                                <?php foreach ($row->categories as $cat): ?>
                                    <div class="mb-1"><span class="badge badge-primary text-white"><?php echo s($cat); ?></span></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php foreach ($row->courses as $c): ?>
                                    <div class="mb-1"><span class="badge badge-info"><?php echo s($c['name']); ?></span></div>
                                <?php endforeach; ?>
                            </td>
                            <!-- Classifications column removed -->
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                        <?php echo count($row->activities); ?> Activities
                                    </button>
                                    <div class="dropdown-menu">
                                        <?php 
                                            // Group activities by classification
                                            $new_acts = [];
                                            $rev_acts = [];
                                            $other_acts = [];
                                            
                                            foreach ($row->activities as $act) {
                                                if ($act->classification === 'New') {
                                                    $new_acts[] = $act->name;
                                                } elseif ($act->classification === 'Revision') {
                                                    $rev_acts[] = $act->name;
                                                } else {
                                                    $other_acts[] = $act->name;
                                                }
                                            }
                                        ?>
                                        
                                        <?php if (!empty($new_acts)): ?>
                                            <h6 class="dropdown-header"><span class="hw-classification-badge hw-classification-new">New</span></h6>
                                            <?php foreach ($new_acts as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($rev_acts)): ?>
                                            <?php if (!empty($new_acts)) echo '<div class="dropdown-divider"></div>'; ?>
                                            <h6 class="dropdown-header"><span class="hw-classification-badge hw-classification-revision">Revision</span></h6>
                                            <?php foreach ($rev_acts as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($other_acts)): ?>
                                            <?php if (!empty($new_acts) || !empty($rev_acts)) echo '<div class="dropdown-divider"></div>'; ?>
                                            <h6 class="dropdown-header">Other</h6>
                                            <?php foreach ($other_acts as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <!-- Activities 2 Column -->
                                <?php if (!empty($row->activities_2)): ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                        <?php echo count($row->activities_2); ?> Activities
                                    </button>
                                    <div class="dropdown-menu">
                                        <?php 
                                            // Group activities 2 by classification
                                            $new_acts_2 = [];
                                            $rev_acts_2 = [];
                                            $other_acts_2 = [];
                                            
                                            foreach ($row->activities_2 as $act) {
                                                if ($act->classification === 'New') {
                                                    $new_acts_2[] = $act->name;
                                                } elseif ($act->classification === 'Revision') {
                                                    $rev_acts_2[] = $act->name;
                                                } else {
                                                    $other_acts_2[] = $act->name;
                                                }
                                            }
                                        ?>
                                        
                                        <?php if (!empty($new_acts_2)): ?>
                                            <h6 class="dropdown-header"><span class="hw-classification-badge hw-classification-new">New</span></h6>
                                            <?php foreach ($new_acts_2 as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($rev_acts_2)): ?>
                                            <?php if (!empty($new_acts_2)) echo '<div class="dropdown-divider"></div>'; ?>
                                            <h6 class="dropdown-header"><span class="hw-classification-badge hw-classification-revision">Revision</span></h6>
                                            <?php foreach ($rev_acts_2 as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (!empty($other_acts_2)): ?>
                                            <?php if (!empty($new_acts_2) || !empty($rev_acts_2)) echo '<div class="dropdown-divider"></div>'; ?>
                                            <h6 class="dropdown-header">Other</h6>
                                            <?php foreach ($other_acts_2 as $aname): ?>
                                                <a class="dropdown-item" href="#"><?php echo s($aname); ?></a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td id="status-<?php echo $row->userid . '-' . $row->timeclose; ?>">
                                <?php if (!empty($row->reportid)): ?>
                                    <a href="view_report.php?id=<?php echo $row->reportid; ?>" class="btn btn-info btn-sm" target="_blank">View</a>
                                <?php else: ?>
                                    <span class="badge badge-light">Not Sent</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Bulk Actions
        document.getElementById('bulk-action-btn').addEventListener('click', function() {
            var action = document.getElementById('bulk-action-select').value;
            if (action === 'sendreport') {
                var selected = [];
                document.querySelectorAll('.report-checkbox:checked').forEach(function(cb) {
                    selected.push({
                        userid: cb.dataset.userid,
                        timeclose: cb.dataset.duedate
                    });
                });

                if (selected.length === 0) {
                    alert('Please select at least one student.');
                    return;
                }

                if (!confirm('Send report for ' + selected.length + ' students?')) {
                    return;
                }

                // Process sequentially to avoid overwhelming server
                var processQueue = function(index) {
                    if (index >= selected.length) {
                        alert('Reports sent successfully.');
                        location.reload();
                        return;
                    }

                    var item = selected[index];
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', 'ajax_send_report.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4) {
                            if (xhr.status === 200) {
                                try {
                                    var resp = JSON.parse(xhr.responseText);
                                    if (resp.status === 'success') {
                                        // Update UI row status if possible, or just continue
                                        console.log('Success for ' + item.userid);
                                    } else {
                                        console.error('Error for ' + item.userid + ': ' + resp.message);
                                    }
                                } catch (e) {
                                    console.error('Invalid response for ' + item.userid);
                                }
                            }
                            processQueue(index + 1);
                        }
                    };
                    xhr.send('userid=' + item.userid + '&timeclose=' + item.timeclose);
                };

                processQueue(0);
            }
        });

        // Select All
        document.getElementById('select-all-reports').addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.report-checkbox').forEach(function(cb) {
                cb.checked = checked;
            });
        });

        // Individual Send (Legacy/Removed but keeping script structure clean)
        document.querySelectorAll('.send-report-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userid = this.getAttribute('data-userid');
                var duedate = this.getAttribute('data-duedate');
                var studentname = this.getAttribute('data-studentname');
                var dateStr = this.getAttribute('data-date');
                
                if (confirm('Send homework report for ' + studentname + ' due on ' + dateStr + '?')) {
                    sendReport(userid, duedate, studentname);
                }
            });
        });

        // Select All
        var selectAll = document.getElementById('select-all-reports');
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                var checkboxes = document.querySelectorAll('.report-checkbox');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = this.checked;
                }
            });
        }

        // Bulk Action
        var bulkBtn = document.getElementById('bulk-action-btn');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', function() {
                var action = document.getElementById('bulk-action-select').value;
                if (!action) {
                    alert('Please select an action.');
                    return;
                }
                if (action === 'sendreport') {
                    var selected = document.querySelectorAll('.report-checkbox:checked');
                    if (selected.length === 0) {
                        alert('No students selected.');
                        return;
                    }
                    if (confirm('Send reports to ' + selected.length + ' students?')) {
                        // Process sequentially to avoid overwhelming server or hitting limits
                        // Or use a bulk endpoint. Let's use sequential for now for better UI feedback.
                        processBulkQueue(Array.from(selected), 0);
                    }
                }
            });
        }

        function processBulkQueue(items, index) {
            if (index >= items.length) {
                alert('Bulk processing complete.');
                return;
            }
            var item = items[index];
            var userid = item.getAttribute('data-userid');
            var duedate = item.getAttribute('data-duedate');
            var studentname = item.getAttribute('data-studentname');
            
            // Update status to 'Sending...'
            var statusCell = document.getElementById('status-' + userid + '-' + duedate);
            if (statusCell) statusCell.innerHTML = '<span class="badge badge-warning">Sending...</span>';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'ajax.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            if (statusCell) statusCell.innerHTML = '<span class="badge badge-success">Sent</span>';
                        } else {
                            if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                        }
                    } catch (e) {
                        if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                    }
                } else {
                    if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                }
                // Next item
                processBulkQueue(items, index + 1);
            };
            xhr.onerror = function() {
                 if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                 processBulkQueue(items, index + 1);
            };
            xhr.send('userid=' + userid + '&duedate=' + duedate + '&sesskey=' + M.cfg.sesskey);
        }

        function sendReport(userid, duedate, studentname) {
            var statusCell = document.getElementById('status-' + userid + '-' + duedate);
            statusCell.innerHTML = '<span class="badge badge-warning">Sending...</span>';
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'ajax.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.success) {
                            statusCell.innerHTML = '<span class="badge badge-success">Sent</span>';
                            alert(resp.message);
                        } else {
                            statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                            alert('Error: ' + resp.message);
                        }
                    } catch (e) {
                        statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                        alert('Invalid response from server.');
                    }
                } else {
                    statusCell.innerHTML = '<span class="badge badge-danger">Error</span>';
                    alert('Request failed.');
                }
            };
            xhr.send('userid=' + userid + '&duedate=' + duedate + '&sesskey=' + M.cfg.sesskey);
        }
    });
    </script>
    
    <?php else: ?>
    <!-- EXISTING TABLE (Live/Snapshot) -->
    <div class="dashboard-table-wrapper">
        <table class="dashboard-table table table-striped">

            <thead class="thead-dark">
                <tr>
                    <th></th> <!-- Expand -->
                    <th class="sortable-column" data-sort="userid">ID <?php echo local_homeworkdashboard_sort_arrows('userid', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="studentname">Name <?php echo local_homeworkdashboard_sort_arrows('studentname', $sort, $dir); ?></th>
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
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["tab" => $tab, "userid[]" => (int)$row->userid]))->out(false); ?>">
                                    <?php echo s($row->studentname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["tab" => $tab, "categoryid" => (int)$row->categoryid]))->out(false); ?>">
                                    <?php echo s($row->categoryname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["tab" => $tab, "courseid[]" => (int)$row->courseid]))->out(false); ?>">
                                    <?php echo s($row->coursename); ?>
                                </a>
                                <?php if ($canmanage): ?>
                                <a href="<?php echo (new moodle_url("/course/edit.php", ["id" => (int)$row->courseid]))->out(false); ?>" class="hw-action-icon" target="_blank" title="<?php echo get_string("edit"); ?>">
                                    <i class="fa fa-pencil"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url("/local/homeworkdashboard/index.php", ["tab" => $tab, "quizid[]" => (int)$row->quizid]))->out(false); ?>">
                                    <?php echo s($row->quizname); ?>
                                </a>
                                <a href="<?php echo (new moodle_url("/mod/quiz/view.php", ["id" => $row->cmid]))->out(false); ?>" class="hw-action-icon" target="_blank" title="<?php echo get_string("view"); ?>">
                                    <i class="fa fa-external-link"></i>
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
                                        $badgetext = "Retry";
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
                                    // Make badge clickable to filter
                                    echo '<span class="hw-classification-badge ' . $clsmod . '" ' .
                                         'style="cursor: pointer;" ' .
                                         'onclick="setFilter(\'classification\', \'' . s($cls) . '\');" ' .
                                         'title="' . get_string('filterby', 'local_homeworkdashboard') . ' ' . s($cls) . '">' . 
                                         s($cls) . '</span>';
                                ?>
                            </td>
                            <td><?php echo s($row->quiz_type); ?></td>
                            <td>
                                <?php 
                                    if ($row->timeclose > 0) {
                                        echo '<a href="' . (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => $tab, 'duedate[]' => $row->timeclose]))->out(false) . '">';
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
                            <td colspan="14">
                                <?php
                                    // Sort attempts by attempt number ASC to match production layout.
                                    $attempts = $row->attempts ?? [];
                                    usort($attempts, function($a, $b) {
                                        return $a->attempt - $b->attempt;
                                    });
                                ?>
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
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Sortable columns
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

    // Interactive Filters
    const filterForm = document.querySelector('.filter-form');

    // Make available globally for onclick handlers
    window.setFilter = function(fieldId, value) {
        const field = document.getElementById(fieldId);
        if (field && filterForm) {
            field.value = value;
            filterForm.submit();
        }
    };

    if (filterForm) {
        const categorySelect = document.getElementById('categoryid');
        const sectionSelect = document.getElementById('sectionid');

        // Helper to reset a select to value '0'
        const resetSelect = (select) => {
            if (select) select.value = '0';
        };

        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                // resetSelect(courseSelect); // Course is now multi-select, don't reset blindly or it breaks UX
                // resetSelect(sectionSelect);
                // resetSelect(quizSelect);
                filterForm.submit();
            });
        }

        if (sectionSelect) {
            sectionSelect.addEventListener('change', function() {
                // resetSelect(quizSelect);
                filterForm.submit();
            });
        }
    }
    
    // Initialize Moodle Autocomplete
    require(['core/form-autocomplete'], function(Autocomplete) {
        // Enhance Course, Quiz, UserID, DueDate
        Autocomplete.enhance('#courseid', false, false, "<?php echo get_string('allcourses', 'local_homeworkdashboard'); ?>", false, true, "<?php echo get_string('noselection', 'form'); ?>");
        Autocomplete.enhance('#quizid', false, false, "<?php echo get_string('all'); ?>", false, true, "<?php echo get_string('noselection', 'form'); ?>");

        Autocomplete.enhance('#studentname', false, false, "<?php echo get_string('all'); ?>", false, true, "<?php echo get_string('noselection', 'form'); ?>");
        Autocomplete.enhance('#duedate', false, false, "<?php echo get_string('all'); ?>", false, true, "<?php echo get_string('noselection', 'form'); ?>");
        
        // Enhance Reports Tab Due Date
        Autocomplete.enhance('#report_duedates', false, false, "<?php echo get_string('all'); ?>", false, true, "<?php echo get_string('noselection', 'form'); ?>");
    });
});
</script>

<?php
echo $OUTPUT->footer();
