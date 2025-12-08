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

$tab           = optional_param('tab', 'leaderboard', PARAM_ALPHA);
$userids       = optional_param_array('userid', [], PARAM_INT); // Retrieve user IDs from dropdown
$categoryid    = optional_param('categoryid', 0, PARAM_INT);
// Handle both single courseid (leaderboard) and courseid[] (other tabs)
$_courseid_single = optional_param('courseid', null, PARAM_INT);
$_courseid_array = optional_param_array('courseid', [], PARAM_INT);
if ($_courseid_single !== null) {
    $courseids = [$_courseid_single];
} elseif (!empty($_courseid_array)) {
    $courseids = $_courseid_array;
} else {
    $courseids = [0];
}
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
    // Always exclude staff for students (they shouldn't see staff in leaderboard)
    $excludestaff = 1;
    // Clear student name filter to avoid confusion
    $studentname = '';
    
    // For leaderboard tab: Set default course by category priority (1 > 2 > 3)
    // This will be applied later when we have the enrolled courses
    if ($tab === 'leaderboard' && (empty($courseids) || $courseids === [0])) {
        $student_courses = enrol_get_users_courses($USER->id, true, 'id, fullname, category');
        $default_course = null;
        $best_priority = PHP_INT_MAX;
        
        foreach ($student_courses as $course) {
            $cat_priority = (int)$course->category;
            if ($cat_priority > 0 && $cat_priority < $best_priority) {
                $best_priority = $cat_priority;
                $default_course = $course;
            }
        }
        
        if ($default_course) {
            $courseids = [(int)$default_course->id];
        }
    }
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
    
    
    $enrolled_course_ids = [];
    foreach ($filter_userids as $uid) {
        // Get enrolled courses for this user
        // true = only active enrollments, 'id' = return only fields needed (id is key)
        $enrolled = enrol_get_users_courses($uid, true, 'id');
        foreach ($enrolled as $ec) {
            $enrolled_course_ids[$ec->id] = true;
        }
    }
    
    

    // Intersect: Keep only courses that are in the enrolled list
    // If multiple users selected, we show union of their courses (any course ANY selected user is in)
    $courses = array_filter($courses, function($c) use ($enrolled_course_ids) {
        return isset($enrolled_course_ids[$c->id]);
    });
    
    
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
} elseif ($tab === "reports") {
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
    
    
    // Debug: Log activities per user/timeclose
    $debug_counts = [];
    foreach ($raw_rows as $r) {
        $key = $r->userid . '_' . $r->timeclose;
        if (!isset($debug_counts[$key])) {
            $debug_counts[$key] = ['name' => $r->studentname, 'timeclose' => $r->timeclose, 'count' => 0, 'quizzes' => []];
        }
        $debug_counts[$key]['count']++;
        $debug_counts[$key]['quizzes'][] = $r->quizname . ' (' . $r->classification . ')';
    }
    foreach ($debug_counts as $k => $v) {
        
    }

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
                'reports' => $DB->get_records_menu('local_homework_reports', ['userid' => $r->userid, 'timeclose' => $r->timeclose], '', 'lang, id'),
                'next_due_date' => null,
                'courses' => [],
                'categories' => [],
                // 'classifications' => [], // Removed column
                'activities' => [],
                'status' => 'Not Sent', // Placeholder
                'emailsent' => $r->timeemailsent ?? 0,
            ];
        }
        $grouped_rows[$key]->courses[$r->courseid] = ['name' => $r->coursename, 'category' => $r->categoryname];
        $grouped_rows[$key]->categories[$r->categoryid] = $r->categoryname;
        
        // Capture Next Due Date for Category 1
        if ((strcasecmp($r->categoryname, 'Category 1') === 0 || strcasecmp($r->categoryname, 'Category 2') === 0) && !empty($r->next_due_date)) {
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
            $a_is_cat1 = (strcasecmp($a, 'Category 1') === 0 || strcasecmp($a, 'Category 2') === 0);
            $b_is_cat1 = (strcasecmp($b, 'Category 1') === 0 || strcasecmp($b, 'Category 2') === 0);
            if ($a_is_cat1 && !$b_is_cat1) return -1;
            if (!$a_is_cat1 && $b_is_cat1) return 1;
            return strcasecmp($a, $b);
        });

        // Sort Courses: Category 1 and Category 2 courses first
        uasort($g->courses, function($a, $b) {
            $a_cat1 = (strcasecmp($a['category'], 'Category 1') === 0 || strcasecmp($a['category'], 'Category 2') === 0);
            $b_cat1 = (strcasecmp($b['category'], 'Category 1') === 0 || strcasecmp($b['category'], 'Category 2') === 0);
            if ($a_cat1 && !$b_cat1) return -1;
            if (!$a_cat1 && $b_cat1) return 1;
            return strcasecmp($a['name'], $b['name']);
        });

        // Sort Activities 1: Category 1 and Category 2 activities first
        usort($g->activities, function($a, $b) {
            $a_cat1 = (strcasecmp($a->category, 'Category 1') === 0 || strcasecmp($a->category, 'Category 2') === 0);
            $b_cat1 = (strcasecmp($b->category, 'Category 1') === 0 || strcasecmp($b->category, 'Category 2') === 0);
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
    new tabobject('leaderboard', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'leaderboard']), 'Leaderboard'),
    new tabobject('live', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'live']), 'Live Homework'),
];

if ($canmanage) {
    $tabs[] = new tabobject('snapshot', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'snapshot']), 'Historical Snapshots');
}

$tabs[] = new tabobject('reports', new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'reports']), 'Homework Reports');
echo $OUTPUT->tabtree($tabs, $tab);

if ($tab === 'leaderboard') {
    // --- LEADERBOARD TAB LOGIC ---
    $manager = new \local_homeworkdashboard\homework_manager();
    
    // Get single duedate filter if provided
    $duedate_filter = optional_param('duedate', 0, PARAM_INT);
    
    // For students: get all peers enrolled in their courses (leaderboard shows course peers)
    if (!$canmanage) {
        global $DB;
        $user_courses = enrol_get_users_courses($USER->id, true, 'id');
        $course_ids = array_keys($user_courses);
        if (!empty($course_ids)) {
            list($in_sql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');
            $peer_sql = "SELECT DISTINCT ue.userid FROM {user_enrolments} ue
                         JOIN {enrol} e ON e.id = ue.enrolid
                         WHERE e.courseid $in_sql AND ue.status = 0";
            $peers = $DB->get_records_sql($peer_sql, $params);
            $leaderboard_userids = array_keys($peers);
        } else {
            $leaderboard_userids = [$USER->id];
        }
    } else {
        // Staff: use filter or show all (empty array = all users)
        $leaderboard_userids = array_filter($userids, function($id) { return $id > 0; });
    }
    
    // Get leaderboard data WITHOUT course filter to preserve grouping/aggregation
    // Course filter will be applied post-fetch to just filter which rows to display
    $rows = $manager->get_leaderboard_data($categoryid, [], $excludestaff, $leaderboard_userids);
    
    // Filter by course if specified (post-fetch to preserve aggregation)
    $courseids_filtered = array_filter($courseids, function($id) { return $id > 0; });
    if (!empty($courseids_filtered)) {
        $rows = array_filter($rows, function($row) use ($courseids_filtered) {
            // Check if any of the row's courses match the filter
            foreach ($row->courses as $cid => $course_info) {
                if (in_array($cid, $courseids_filtered)) {
                    return true;
                }
            }
            return false;
        });
        $rows = array_values($rows);
    }
    
    // Filter by due date if specified
    if ($duedate_filter > 0) {
        $rows = array_filter($rows, function($row) use ($duedate_filter) {
            return $row->latest_due_date == $duedate_filter || $row->live_due_date == $duedate_filter;
        });
        $rows = array_values($rows);
    }
    
    // Calculate Intellect Points and Max IP by summing All Time points across all categories for each user
    // This is simpler, includes live points, and is easy to verify (IP = sum of All Time column per user)
    $all_rows_for_ip = $manager->get_leaderboard_data(0, [], $excludestaff, []);
    $intellect_points = [];
    $max_intellect_points = []; // Max possible IP per user
    foreach ($all_rows_for_ip as $r) {
        if (!isset($intellect_points[$r->userid])) {
            $intellect_points[$r->userid] = 0;
            $max_intellect_points[$r->userid] = 0;
        }
        $intellect_points[$r->userid] += $r->points_all;
        $max_intellect_points[$r->userid] += $r->max_all ?? 0;
    }
    
    // Apply sorting to leaderboard rows
    if (!empty($rows)) {
        usort($rows, function($a, $b) use ($sort, $dir, $intellect_points) {
            $result = 0;
            switch ($sort) {
                case 'fullname':
                    $result = strcasecmp($a->fullname, $b->fullname);
                    break;
                case 'level':
                case 'intellect_point':
                    $ip_a = isset($intellect_points[$a->userid]) ? $intellect_points[$a->userid] : 0;
                    $ip_b = isset($intellect_points[$b->userid]) ? $intellect_points[$b->userid] : 0;
                    $result = $ip_a <=> $ip_b;
                    break;
                case 'categoryname':
                    $result = strcasecmp($a->categoryname, $b->categoryname);
                    break;
                case 'latest_due_date':
                    $result = ($a->latest_due_date ?? 0) <=> ($b->latest_due_date ?? 0);
                    break;
                case 'points_all':
                    $result = ($a->points_all ?? 0) <=> ($b->points_all ?? 0);
                    break;
                default:
                    // Default: category then name
                    $result = strcasecmp($a->categoryname, $b->categoryname);
                    if ($result === 0) {
                        $result = strcasecmp($a->fullname, $b->fullname);
                    }
            }
            return strtoupper($dir) === 'DESC' ? -$result : $result;
        });
    }
    
    // Build user list for filter from ALL leaderboard users (without user filter applied)
    $all_rows_for_users = $manager->get_leaderboard_data($categoryid, [], $excludestaff, []);
    $leaderboard_users = [];
    $seen_users = [];
    foreach ($all_rows_for_users as $r) {
        if (!isset($seen_users[$r->userid])) {
            $leaderboard_users[] = (object)['id' => $r->userid, 'fullname' => $r->fullname];
            $seen_users[$r->userid] = true;
        }
    }
    // Sort by name
    usort($leaderboard_users, function($a, $b) { return strcasecmp($a->fullname, $b->fullname); });
?>
<div class="container-fluid">
    <div class="essay-dashboard-container">
        <div class="dashboard-filters">
            <form method="get" action="" class="filter-form">
                <input type="hidden" name="tab" value="leaderboard">
                <input type="hidden" name="filtersubmitted" value="1">
                
                <div class="filter-row">
                    <!-- User Filter (Admin Only) -->
                    <?php if ($canmanage): ?>
                    <div class="filter-group">
                        <label for="lb_userid"><?php echo get_string('user'); ?></label>
                        <select name="userid[]" id="lb_userid" multiple="multiple">
                            <option value="0"><?php echo get_string('all'); ?></option>
                            <?php foreach ($leaderboard_users as $u): ?>
                                <option value="<?php echo (int)$u->id; ?>" <?php echo in_array((int)$u->id, $userids) ? 'selected' : ''; ?>>
                                    <?php echo s($u->fullname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Category Filter (Admin Only) -->
                    <?php if ($canmanage): ?>
                    <div class="filter-group">
                        <label for="lb_categoryid"><?php echo get_string('col_category', 'local_homeworkdashboard'); ?></label>
                        <select name="categoryid" id="lb_categoryid">
                            <option value="0"><?php echo get_string('all'); ?></option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo (int)$cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Course Filter (Single Select for Leaderboard) -->
                    <div class="filter-group">
                        <label for="lb_courseid"><?php echo get_string('col_course', 'local_homeworkdashboard'); ?></label>
                        <?php $selected_courseid = !empty($courseids) ? reset($courseids) : 0; ?>
                        <select name="courseid" id="lb_courseid">
                            <option value="0"><?php echo get_string('allcourses', 'local_homeworkdashboard'); ?></option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo (int)$c->id; ?>" <?php echo ((int)$c->id === (int)$selected_courseid) ? 'selected' : ''; ?>>
                                    <?php echo format_string($c->fullname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Exclude Staff (Admin Only) -->
                    <?php if ($canmanage): ?>
                    <div class="filter-group checkbox-group" style="display: flex; align-items: flex-end; padding-bottom: 5px;">
                        <input type="checkbox" name="excludestaff" id="lb_excludestaff" value="1" <?php echo $excludestaff ? 'checked' : ''; ?> style="margin-right: 5px;">
                        <label for="lb_excludestaff" style="margin-bottom: 0;"><?php echo get_string('excludestaff', 'local_homeworkdashboard'); ?></label>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Buttons -->
                    <div class="filter-actions" style="display: flex; gap: 5px; align-items: flex-end; padding-bottom: 2px;">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="<?php echo (new moodle_url('/local/homeworkdashboard/index.php', ['tab' => 'leaderboard']))->out(false); ?>" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Load Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        
        <!-- Charts Section (Always Visible) -->
        <div id="leaderboardCharts" style="margin-bottom: 20px;">
<?php
    // Determine chart title prefix based on selected course(s)
    $selected_course_ids = array_filter($courseids, function($id) { return $id > 0; });
    if (count($selected_course_ids) === 1 && !empty($courses)) {
        $selected_course_id = reset($selected_course_ids);
        $chart_title_prefix = isset($courses[$selected_course_id]) ? format_string($courses[$selected_course_id]->fullname) : 'Course Level';
    } else {
        $chart_title_prefix = 'Course Level';
    }
?>
            <!-- Course/Category Level Charts (2x2) -->
            <div class="row">
                <!-- Course Level: All Time & Class Level (FIRST) -->
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <strong><?php echo $chart_title_prefix; ?>: Intellect Points & Class Level</strong>
                        </div>
                        <div class="card-body" style="height: 350px;">
                            <canvas id="courseAllTimeChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Course Level: Students Points (Live, 2wk, 4wk) (SECOND) -->
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <strong><?php echo $chart_title_prefix; ?>: Intellect Points (Live / 2wk / 4wk)</strong>
                        </div>
                        <div class="card-body" style="height: 350px;">
                            <canvas id="courseStudentPointsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Period Goal Charts (Single Row - 4x1) - Between Course and User Level -->
            <div class="row">
                <!-- Live Points vs Goal -->
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white py-2">
                            <strong>Live vs Goal</strong>
                        </div>
                        <div class="card-body" style="height: 280px;">
                            <canvas id="liveGoalChart"></canvas>
                        </div>
                    </div>
                </div>
                <!-- 2 Week Points vs Goal -->
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white py-2">
                            <strong>2 Week vs Goal</strong>
                        </div>
                        <div class="card-body" style="height: 280px;">
                            <canvas id="w2GoalChart"></canvas>
                        </div>
                    </div>
                </div>
                <!-- 4 Week Points vs Goal -->
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white py-2">
                            <strong>4 Week vs Goal</strong>
                        </div>
                        <div class="card-body" style="height: 280px;">
                            <canvas id="w4GoalChart"></canvas>
                        </div>
                    </div>
                </div>
                <!-- 10 Week Points vs Goal -->
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-header bg-primary text-white py-2">
                            <strong>10 Week vs Goal</strong>
                        </div>
                        <div class="card-body" style="height: 280px;">
                            <canvas id="w10GoalChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- User Level (Aggregated) -->
            <div class="row">
                <!-- User Level: Intellect Points & Level (FIRST) -->
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <strong>User Level: Intellect Points & Level</strong>
                        </div>
                        <div class="card-body" style="height: 350px;">
                            <canvas id="userIntellectChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- User Level: Students Points (Live, 2wk, 4wk) (SECOND) -->
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <strong>User Level: Intellect Points (Live / 2wk / 4wk)</strong>
                        </div>
                        <div class="card-body" style="height: 350px;">
                            <canvas id="userStudentPointsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leaderboard Table (Admin Only) -->
        <?php if ($canmanage): ?>
        <div class="table-responsive">
            <table class="table dashboard-table">
                <thead>
                    <tr>
                        <th class="sortable-column" data-sort="fullname" style="cursor:pointer;">NAME <?php echo local_homeworkdashboard_sort_arrows('fullname', $sort, $dir); ?></th>
                        <th>ID</th>
                        <th class="sortable-column text-center" data-sort="level" style="cursor:pointer;">LEVEL <?php echo local_homeworkdashboard_sort_arrows('level', $sort, $dir); ?></th>
                        <th class="sortable-column text-center" data-sort="intellect_point" style="cursor:pointer;">Intellect Point <?php echo local_homeworkdashboard_sort_arrows('intellect_point', $sort, $dir); ?></th>
                        <th class="text-center">MAX IP</th>
                        <th class="sortable-column" data-sort="categoryname" style="cursor:pointer;">CATEGORY <?php echo local_homeworkdashboard_sort_arrows('categoryname', $sort, $dir); ?></th>
                        <th>COURSES</th>
                        <th class="text-center">CLASS LEVEL</th>
                        <th>LIVE DUE DATE</th>
                        <th class="sortable-column" data-sort="latest_due_date" style="cursor:pointer;">LATEST DUE DATE <?php echo local_homeworkdashboard_sort_arrows('latest_due_date', $sort, $dir); ?></th>
                        <th class="text-center">LIVE</th>
                        <th class="text-center">MAX</th>
                        <th class="text-center">2 WK</th>
                        <th class="text-center">MAX</th>
                        <th class="text-center">4 WK</th>
                        <th class="text-center">MAX</th>
                        <th class="text-center">10 WK</th>
                        <th class="text-center">MAX</th>
                        <th class="sortable-column text-center" data-sort="points_all" style="cursor:pointer;">ALL TIME <?php echo local_homeworkdashboard_sort_arrows('points_all', $sort, $dir); ?></th>
                        <th class="text-center">MAX</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="20" class="text-center">No data found for current filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <!-- Name (clickable filter) -->
                                <td>
                                    <a href="#" class="filter-link" onclick="setLbFilter('lb_userid', '<?php echo (int)$row->userid; ?>'); return false;" title="Click to filter by this student">
                                        <?php echo s($row->fullname); ?>
                                    </a>
                                </td>
                                
                                <!-- ID -->
                                <td><?php echo !empty($row->idnumber) ? s($row->idnumber) : $row->userid; ?></td>
                                
                                <?php 
                                    // Intellect Point = sum of All Time values (already divided by 10 for display)
                                    $raw_points = isset($intellect_points[$row->userid]) ? $intellect_points[$row->userid] : 0;
                                    $raw_max_ip = isset($max_intellect_points[$row->userid]) ? $max_intellect_points[$row->userid] : 0;
                                    $ip = $raw_points / 10; // IP = sum of all time / 10 (matches All Time column)
                                    $max_ip = $raw_max_ip / 10; // Max IP = sum of all max_all / 10
                                    $level = ceil($ip / 100); // Level = IP / 100, rounded up
                                    if ($level < 1) $level = 1; // Minimum level 1
                                ?>
                                <!-- Level (IP / 100, rounded up) -->
                                <td class="text-center font-weight-bold" style="color: #fd7e14;">
                                    <?php echo $level; ?>
                                </td>
                                <!-- Intellect Points = sum of All Time (divided by 10, 1 decimal) -->
                                <td class="text-center font-weight-bold" style="color: #6f42c1;">
                                    <?php echo number_format($ip, 1); ?>
                                </td>
                                <!-- Max IP = sum of all max_all (divided by 10, 1 decimal) -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format($max_ip, 1); ?>
                                </td>
                                
                                <!-- Category (clickable filter) -->
                                <td>
                                    <?php
                                    // Different colors for different categories (blue/green/navy)
                                    $cat_colors = [
                                        '#003366', // Dark Navy
                                        '#198754', // Green
                                        '#0d6efd', // Blue
                                        '#084298', // Navy Blue
                                        '#146c43', // Dark Green
                                    ];
                                    $cat_color = $cat_colors[$row->categoryid % count($cat_colors)];
                                    ?>
                                    <a href="#" class="filter-link" onclick="setLbFilter('lb_categoryid', '<?php echo (int)$row->categoryid; ?>'); return false;" title="Click to filter by this category">
                                        <span class="badge" style="background-color: <?php echo $cat_color; ?>; color: white; padding: 4px 8px;"><?php echo s($row->categoryname ?? 'Unknown'); ?></span>
                                    </a>
                                </td>
                                
                                <!-- Courses -->
                                <td>
                                    <?php 
                                    // Define specific colors for known courses, fallback to rotation
                                    $specific_course_colors = [
                                        3 => '#003366',  // Year 5A Classroom - Dark Navy
                                        7 => '#0a58ca',  // Year 3A Classroom - Dark Blue
                                        9 => '#084298',  // Year 9A Classroom - Navy Blue
                                        2 => '#198754',  // Selective Trial Test - Green
                                        6 => '#20c997',  // OC Trial Test - Teal
                                    ];
                                    $fallback_colors = [
                                        '#003366', // Dark Navy
                                        '#0d6efd', // Blue
                                        '#198754', // Green
                                        '#0a58ca', // Medium Blue
                                        '#084298', // Navy Blue
                                        '#20c997', // Teal
                                        '#0f5132', // Forest Green
                                        '#052c65', // Deep Navy
                                    ];
                                    foreach ($row->courses as $cid => $course_info): 
                                        $cname = $course_info['name'] ?? "Course $cid";
                                        $orig_cat = $course_info['categoryname'] ?? '';
                                        // Personal Review courses get light blue
                                        if (stripos($orig_cat, 'Personal Review') !== false) {
                                            $color = '#5bc0de'; // Light blue
                                        } else if (isset($specific_course_colors[$cid])) {
                                            $color = $specific_course_colors[$cid];
                                        } else {
                                            $color = $fallback_colors[$cid % count($fallback_colors)];
                                        }
                                    ?>
                                        <div class="mb-1">
                                            <a href="#" class="filter-link" onclick="setLbFilter('lb_courseid', '<?php echo (int)$cid; ?>'); return false;" title="Click to filter by this course">
                                                <span class="badge" style="background-color: <?php echo $color; ?>; color: white; padding: 4px 8px;"><?php echo s($cname); ?></span>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                
                                <!-- Class Level (All Time / 100, rounded up) -->
                                <td class="text-center font-weight-bold" style="color: #17a2b8;">
                                    <?php 
                                        $class_level = ceil(($row->points_all / 10) / 100);
                                        if ($class_level < 1) $class_level = 1;
                                        echo $class_level;
                                    ?>
                                </td>
                                
                                <!-- Live Due Date -->
                                <td>
                                    <?php echo $row->live_due_date > 0 ? userdate($row->live_due_date, get_string('strftimedatetime')) : '-'; ?>
                                </td>
                                
                                <!-- Latest Due Date (clickable filter) -->
                                <td>
                                    <?php if ($row->latest_due_date > 0): ?>
                                        <a href="#" class="filter-link" onclick="setLbDueDateFilter(<?php echo (int)$row->latest_due_date; ?>); return false;" title="Click to filter by this due date" style="color: #0d6efd;">
                                            <?php echo userdate($row->latest_due_date, get_string('strftimedatetime')); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Points Columns -->
                                <?php
                                // Prepare tooltip data (due dates for each period)
                                $tooltip_live = !empty($row->duedates_live) ? implode(', ', array_map(function($d) { return userdate($d, get_string('strftimedate')); }, $row->duedates_live)) : 'No live quizzes';
                                $tooltip_2w = !empty($row->duedates_2w) ? implode(', ', array_map(function($d) { return userdate($d, get_string('strftimedate')); }, $row->duedates_2w)) : 'No due dates';
                                $tooltip_4w = !empty($row->duedates_4w) ? implode(', ', array_map(function($d) { return userdate($d, get_string('strftimedate')); }, $row->duedates_4w)) : 'No due dates';
                                $tooltip_10w = !empty($row->duedates_10w) ? implode(', ', array_map(function($d) { return userdate($d, get_string('strftimedate')); }, $row->duedates_10w)) : 'No due dates';
                                
                                // Create unique row key for JavaScript data storage
                                $row_key = $row->userid . '_' . $row->categoryid;
                                ?>
                                <!-- Live Points (clickable + tooltip) - divided by 10 -->
                                <td class="text-center font-weight-bold text-success">
                                    <a href="#" class="points-link" 
                                       onclick="showBreakdown('<?php echo s($row->fullname); ?>', 'Live', '<?php echo $row_key; ?>_live'); return false;"
                                       title="Due dates: <?php echo s($tooltip_live); ?>">
                                        <?php echo number_format($row->points_live / 10, 1); ?>
                                    </a>
                                </td>
                                <!-- Max Live - divided by 10 -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format(($row->max_live ?? 0) / 10, 1); ?>
                                </td>
                                <!-- 2 Weeks (clickable + tooltip) - divided by 10 -->
                                <td class="text-center">
                                    <a href="#" class="points-link"
                                       onclick="showBreakdown('<?php echo s($row->fullname); ?>', '2 Weeks', '<?php echo $row_key; ?>_2w'); return false;"
                                       title="Due dates: <?php echo s($tooltip_2w); ?>">
                                        <?php echo number_format($row->points_2w / 10, 1); ?>
                                    </a>
                                </td>
                                <!-- Max 2 Weeks - divided by 10 -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format(($row->max_2w ?? 0) / 10, 1); ?>
                                </td>
                                <!-- 4 Weeks (clickable + tooltip) - divided by 10 -->
                                <td class="text-center">
                                    <a href="#" class="points-link"
                                       onclick="showBreakdown('<?php echo s($row->fullname); ?>', '4 Weeks', '<?php echo $row_key; ?>_4w'); return false;"
                                       title="Due dates: <?php echo s($tooltip_4w); ?>">
                                        <?php echo number_format($row->points_4w / 10, 1); ?>
                                    </a>
                                </td>
                                <!-- Max 4 Weeks - divided by 10 -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format(($row->max_4w ?? 0) / 10, 1); ?>
                                </td>
                                <!-- 10 Weeks (tooltip only) - divided by 10 -->
                                <td class="text-center" title="Due dates: <?php echo s($tooltip_10w); ?>" style="cursor: help;">
                                    <?php echo number_format($row->points_10w / 10, 1); ?>
                                </td>
                                <!-- Max 10 Weeks - divided by 10 -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format(($row->max_10w ?? 0) / 10, 1); ?>
                                </td>
                                <!-- All Time (no tooltip/modal) - divided by 10 -->
                                <td class="text-center font-weight-bold text-primary"><?php echo number_format($row->points_all / 10, 1); ?></td>
                                <!-- Max All Time - divided by 10 -->
                                <td class="text-center" style="color: #999;">
                                    <?php echo number_format(($row->max_all ?? 0) / 10, 1); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; // End admin-only table ?>

<?php if ($canmanage): // Breakdown data and modal for admin only ?>
<!-- Breakdown Data (stored in JavaScript) -->
<script>
var breakdownData = {
<?php foreach ($rows as $row): 
    $row_key = $row->userid . '_' . $row->categoryid;
?>
    '<?php echo $row_key; ?>_live': <?php echo json_encode($row->breakdown_live ?? []); ?>,
    '<?php echo $row_key; ?>_2w': <?php echo json_encode($row->breakdown_2w ?? []); ?>,
    '<?php echo $row_key; ?>_4w': <?php echo json_encode($row->breakdown_4w ?? []); ?>,
<?php endforeach; ?>
};
</script>

<!-- Points Breakdown Modal -->
<div class="modal fade" id="pointsModal" tabindex="-1" role="dialog" aria-labelledby="pointsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background: #343a40; color: white;">
                <h5 class="modal-title" id="pointsModalLabel">Points Breakdown</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="pointsModalContent">
                    <!-- Content populated by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; // End admin-only breakdown and modal ?>

<style>
.points-link {
    text-decoration: none;
    color: inherit;
    cursor: pointer;
}
.points-link:hover {
    text-decoration: underline;
    color: #007bff;
}
.breakdown-table {
    width: 100%;
    margin-top: 10px;
}
.breakdown-table th {
    background: #f8f9fa;
    padding: 8px;
    text-align: left;
    border-bottom: 2px solid #dee2e6;
}
.breakdown-table td {
    padding: 8px;
    border-bottom: 1px solid #dee2e6;
}
.breakdown-table .status-completed { color: #28a745; font-weight: bold; }
.breakdown-table .status-lowgrade { color: #ffc107; font-weight: bold; }
.breakdown-table .status-noattempt { color: #dc3545; font-weight: bold; }
.breakdown-table .status-live { color: #17a2b8; font-weight: bold; }
.total-row { font-weight: bold; background: #e9ecef; }
</style>

<script>
// Leaderboard filter function (global scope for onclick)
window.setLbFilter = function(fieldId, value) {
    var field = document.getElementById(fieldId);
    var form = field ? field.closest('form') : null;
    if (field && form) {
        // Handle multi-select fields
        if (field.multiple) {
            // Clear all selections first
            Array.from(field.options).forEach(function(opt) { opt.selected = false; });
            // Select the matching option
            var option = Array.from(field.options).find(function(opt) { return opt.value === String(value); });
            if (option) {
                option.selected = true;
            }
        } else {
            field.value = value;
        }
        form.submit();
    }
};

// Due date filter function - redirects with duedate parameter
window.setLbDueDateFilter = function(timestamp) {
    var currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('duedate', timestamp);
    currentUrl.searchParams.set('filtersubmitted', '1');
    window.location.href = currentUrl.toString();
};

document.addEventListener('DOMContentLoaded', function() {
    require(['core/form-autocomplete'], function(Autocomplete) {
        Autocomplete.enhance('#lb_userid', false, false, '<?php echo get_string('all'); ?>', false, true, '<?php echo get_string('noselection', 'form'); ?>');
        // Note: lb_courseid is now single-select, no autocomplete needed
    });
    
    // Show breakdown modal function
    window.showBreakdown = function(student, period, dataKey) {
        var breakdown = breakdownData[dataKey] || [];
        
        // Sort by Due Date (desc), Type/Classification (desc), Activity (desc)
        breakdown.sort(function(a, b) {
            // Due date descending
            if (b.due_date !== a.due_date) {
                return b.due_date - a.due_date;
            }
            // Classification descending
            var classA = (a.classification || '').toLowerCase();
            var classB = (b.classification || '').toLowerCase();
            if (classB !== classA) {
                return classB.localeCompare(classA);
            }
            // Activity name descending
            var nameA = (a.quiz_name || '').toLowerCase();
            var nameB = (b.quiz_name || '').toLowerCase();
            return nameB.localeCompare(nameA);
        });
        
        var modal = $('#pointsModal');
        modal.find('.modal-title').text(student + ' - ' + period + ' Points Breakdown');
        
        var content = '';
        if (!breakdown || breakdown.length === 0) {
            content = '<p class="text-muted">No activities in this period.</p>';
        } else {
            var total = 0;
            content = '<table class="breakdown-table">';
            content += '<thead><tr><th>Due Date</th><th>Course</th><th>Activity</th><th>Classification</th><th>Status</th><th>Duration</th><th>Score</th><th>Score %</th><th class="text-right">Points</th></tr></thead>';
            content += '<tbody>';
            
            breakdown.forEach(function(item) {
                // Classification badge (using exact CSS classes from styles.css)
                var classification = (item.classification || '').toLowerCase();
                var classificationBadge = '-';
                if (classification === 'new') {
                    classificationBadge = '<span class="hw-classification-badge hw-classification-new">New</span>';
                } else if (classification === 'revision') {
                    classificationBadge = '<span class="hw-classification-badge hw-classification-revision">Revision</span>';
                }
                
                // Status badge (using exact CSS classes from styles.css)
                var status = (item.status || 'unknown').toLowerCase();
                var statusBadge = '-';
                if (status === 'completed') {
                    statusBadge = '<span class="hw-badge hw-badge-completed">Done</span>';
                } else if (status === 'lowgrade') {
                    statusBadge = '<span class="hw-badge hw-badge-lowgrade">Retry</span>';
                } else if (status === 'noattempt') {
                    statusBadge = '<span class="hw-badge hw-badge-noattempt">To do</span>';
                }
                
                content += '<tr>';
                content += '<td>' + (item.due_date_formatted || '-') + '</td>';
                content += '<td>' + (item.course_name || '-') + '</td>';
                content += '<td>' + (item.quiz_name || '-') + '</td>';
                content += '<td>' + classificationBadge + '</td>';
                content += '<td>' + statusBadge + '</td>';
                content += '<td>' + (item.duration || '-') + '</td>';
                content += '<td>' + (item.score_display || '-') + '</td>';
                content += '<td>' + (item.score_percent || '-') + '</td>';
                content += '<td class="text-right">' + parseFloat(item.points || 0).toFixed(0) + '</td>';
                content += '</tr>';
                total += parseFloat(item.points || 0);
            });
            
            content += '</tbody>';
            content += '<tfoot><tr class="total-row"><td colspan="8" class="text-right">Total:</td><td class="text-right">' + total.toFixed(0) + '</td></tr></tfoot>';
            content += '</table>';
        }
        
        modal.find('#pointsModalContent').html(content);
        modal.modal('show');
    };
    
    // Sortable column headers for leaderboard
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
    
    // Initialize charts if Chart.js is available
    initLeaderboardCharts();
});



// Chart data from PHP
var leaderboardChartData = {
    // Goal Charts Data: Course Level with points and max (goal)
    goalChartData: <?php
        $goal_data = [];
        foreach ($rows as $row) {
            $goal_data[] = [
                'name' => $row->fullname,
                'category' => $row->categoryname ?? 'Unknown',
                'live' => round(($row->points_live ?? 0) / 10, 1),
                'max_live' => round(($row->max_live ?? 0) / 10, 1),
                'w2' => round(($row->points_2w ?? 0) / 10, 1),
                'max_w2' => round(($row->max_2w ?? 0) / 10, 1),
                'w4' => round(($row->points_4w ?? 0) / 10, 1),
                'max_w4' => round(($row->max_4w ?? 0) / 10, 1),
                'w10' => round(($row->points_10w ?? 0) / 10, 1),
                'max_w10' => round(($row->max_10w ?? 0) / 10, 1),
                'all_time' => round(($row->points_all ?? 0) / 10, 1),
                'max_all' => round(($row->max_all ?? 0) / 10, 1),
                'class_level' => max(1, ceil(($row->points_all / 10) / 100)),
            ];
        }
        // Sort by live points desc and take top 10
        usort($goal_data, function($a, $b) { return $b['all_time'] <=> $a['all_time']; });
        $goal_data = array_slice($goal_data, 0, 10);
        echo json_encode($goal_data);
    ?>,
    
    // Course Level: Students with Live, 2wk, 4wk points (per category row)
    courseStudentPoints: <?php 
        $course_data = [];
        foreach ($rows as $row) {
            $course_data[] = [
                'name' => $row->fullname,
                'category' => $row->categoryname ?? 'Unknown',
                'live' => round(($row->points_live ?? 0) / 10, 1),
                'w2' => round(($row->points_2w ?? 0) / 10, 1),
                'w4' => round(($row->points_4w ?? 0) / 10, 1),
                'all_time' => round(($row->points_all ?? 0) / 10, 1),
                'class_level' => max(1, ceil(($row->points_all / 10) / 100)),
                'max_live' => round(($row->max_live ?? 0) / 10, 1),
                'max_all' => round(($row->max_all ?? 0) / 10, 1)
            ];
        }
        // Sort by live points desc and take top 10
        usort($course_data, function($a, $b) { return $b['all_time'] <=> $a['all_time']; });
        $course_data = array_slice($course_data, 0, 10);
        echo json_encode($course_data);
    ?>,
    
    // User Level: Aggregated points across all courses
    userStudentPoints: <?php
        $user_agg = [];
        foreach ($rows as $row) {
            $uid = $row->userid;
            if (!isset($user_agg[$uid])) {
                $user_agg[$uid] = [
                    'name' => $row->fullname,
                    'live' => 0,
                    'w2' => 0,
                    'w4' => 0,
                    'max_live' => 0,
                    'max_w2' => 0,
                    'max_w4' => 0,
                    'max_all' => 0,
                    'intellect_points' => 0,
                    'level' => 1
                ];
            }
            $user_agg[$uid]['live'] += ($row->points_live ?? 0) / 10;
            $user_agg[$uid]['w2'] += ($row->points_2w ?? 0) / 10;
            $user_agg[$uid]['w4'] += ($row->points_4w ?? 0) / 10;
            $user_agg[$uid]['max_live'] += ($row->max_live ?? 0) / 10;
            $user_agg[$uid]['max_w2'] += ($row->max_2w ?? 0) / 10;
            $user_agg[$uid]['max_w4'] += ($row->max_4w ?? 0) / 10;
            $user_agg[$uid]['max_all'] += ($row->max_all ?? 0) / 10;
        }
        // Add intellect points and level
        foreach ($user_agg as $uid => &$u) {
            $raw = isset($intellect_points[$uid]) ? $intellect_points[$uid] : 0;
            $raw_max = isset($max_intellect_points[$uid]) ? $max_intellect_points[$uid] : 0;
            $u['intellect_points'] = round($raw / 10, 1);
            $u['max_ip'] = round($raw_max / 10, 1);
            $u['level'] = max(1, ceil(($raw / 10) / 100));
            $u['live'] = round($u['live'], 1);
            $u['w2'] = round($u['w2'], 1);
            $u['w4'] = round($u['w4'], 1);
            $u['max_live'] = round($u['max_live'], 1);
            $u['max_w2'] = round($u['max_w2'], 1);
            $u['max_w4'] = round($u['max_w4'], 1);
            $u['max_all'] = round($u['max_all'], 1);
        }
        unset($u);
        $user_list = array_values($user_agg);
        // Sort by live points desc and take top 10
        usort($user_list, function($a, $b) { return $b['intellect_points'] <=> $a['intellect_points']; });
        $user_list = array_slice($user_list, 0, 10);
        echo json_encode($user_list);
    ?>,
    
    // Average/Max levels for gauge display
    avgClassLevel: <?php
        $total_class_level = 0;
        $count = 0;
        foreach ($rows as $row) {
            $total_class_level += max(1, ceil(($row->points_all / 10) / 100));
            $count++;
        }
        echo $count > 0 ? round($total_class_level / $count, 1) : 1;
    ?>,
    maxClassLevel: <?php
        $max_cl = 1;
        foreach ($rows as $row) {
            $cl = max(1, ceil(($row->points_all / 10) / 100));
            if ($cl > $max_cl) $max_cl = $cl;
        }
        echo $max_cl;
    ?>,
    avgUserLevel: <?php
        $user_levels_arr = [];
        foreach ($rows as $row) {
            $uid = $row->userid;
            if (!isset($user_levels_arr[$uid])) {
                $raw = isset($intellect_points[$uid]) ? $intellect_points[$uid] : 0;
                $user_levels_arr[$uid] = max(1, ceil(($raw / 10) / 100));
            }
        }
        $avg = count($user_levels_arr) > 0 ? array_sum($user_levels_arr) / count($user_levels_arr) : 1;
        echo round($avg, 1);
    ?>,
    maxUserLevel: <?php
        $max_ul = 1;
        foreach ($rows as $row) {
            $uid = $row->userid;
            $raw = isset($intellect_points[$uid]) ? $intellect_points[$uid] : 0;
            $ul = max(1, ceil(($raw / 10) / 100));
            if ($ul > $max_ul) $max_ul = $ul;
        }
        echo $max_ul;
    ?>
};

var chartsInitialized = false;

function initLeaderboardCharts() {
    
    if (chartsInitialized) return;
    
    if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded!');
        return;
    }
    
    
    renderCharts();
}

function renderCharts() {
    
    
    chartsInitialized = true;
    
    // Color palette
    var colors = {
        live: 'rgba(25, 135, 84, 0.8)',      // Green for live
        w2: 'rgba(13, 110, 253, 0.8)',       // Blue for 2 weeks
        w4: 'rgba(111, 66, 193, 0.8)',       // Purple for 4 weeks
        w10: 'rgba(255, 193, 7, 0.8)',       // Yellow for 10 weeks
        allTime: 'rgba(13, 110, 253, 0.8)', // Blue for all time
        goal: 'rgba(220, 53, 69, 1)'         // Red for goal line
    };
    
    // =====================================================
    // GOAL CHARTS (Combo: Bar + Line)
    // =====================================================
    var goalData = leaderboardChartData.goalChartData || [];
    
    // Helper function to create goal chart
    function createGoalChart(canvasId, pointsKey, maxKey, barColor, title) {
        var ctx = document.getElementById(canvasId);
        if (!ctx || goalData.length === 0) return;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: goalData.map(function(s) { return s.name; }),
                datasets: [
                    {
                        type: 'bar',
                        label: 'Points',
                        data: goalData.map(function(s) { return s[pointsKey]; }),
                        backgroundColor: function(context) {
                            if (context.dataIndex === 0) {
                                var chart = context.chart;
                                var chartArea = chart.chartArea;
                                if (!chartArea) return '#FFD700';
                                var ctx = chart.ctx;
                                var gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                                gradient.addColorStop(0, '#FFD700');
                                gradient.addColorStop(0.5, '#FFA500');
                                gradient.addColorStop(1, '#FF6B00');
                                return gradient;
                            }
                            return barColor;
                        },
                        borderColor: function(context) {
                            if (context.dataIndex === 0) return 'transparent';
                            return barColor.replace('0.8', '1');
                        },
                        borderWidth: function(context) {
                            if (context.dataIndex === 0) return 0;
                            return 1;
                        },
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Goal',
                        data: goalData.map(function(s) { return s[maxKey]; }),
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        borderWidth: 3,
                        borderDash: [5, 5],
                        pointBackgroundColor: '#dc3545',
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        fill: false,
                        tension: 0,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        position: 'top',
                        reverse: true,
                        labels: { 
                            usePointStyle: true, 
                            boxWidth: 6, 
                            boxHeight: 6,
                            font: { size: 10 },
                            generateLabels: function(chart) {
                                var datasets = chart.data.datasets;
                                return datasets.map(function(dataset, i) {
                                    return {
                                        text: dataset.label,
                                        fillStyle: i === 0 ? barColor : dataset.borderColor,
                                        strokeStyle: i === 0 ? barColor : dataset.borderColor,
                                        lineWidth: dataset.borderWidth || 0,
                                        pointStyle: i === 0 ? 'rect' : 'circle',
                                        hidden: !chart.isDatasetVisible(i),
                                        datasetIndex: i
                                    };
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function(context) {
                                var idx = context[0].dataIndex;
                                var pts = goalData[idx][pointsKey];
                                var max = goalData[idx][maxKey];
                                var pct = max > 0 ? Math.round((pts / max) * 100) : 0;
                                return 'Progress: ' + pct + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true,
                        beginAtZero: true,
                        title: { display: false }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }
    
    // Create the 4 goal charts
    createGoalChart('liveGoalChart', 'live', 'max_live', colors.live, 'Live');
    createGoalChart('w2GoalChart', 'w2', 'max_w2', colors.w2, '2 Week');
    createGoalChart('w4GoalChart', 'w4', 'max_w4', colors.w4, '4 Week');
    createGoalChart('w10GoalChart', 'w10', 'max_w10', colors.w10, '10 Week');
    
    // Level badge colors (different color per level)
    var levelColors = [
        '#6c757d', // Level 0 (fallback) - Gray
        '#17a2b8', // Level 1 - Teal/Cyan
        '#6f42c1', // Level 2 - Purple
        '#28a745', // Level 3 - Green
        '#fd7e14', // Level 4 - Orange
        '#dc3545', // Level 5 - Red
        '#007bff', // Level 6 - Blue
        '#e83e8c', // Level 7 - Pink
        '#20c997', // Level 8 - Teal Green
        '#ffc107', // Level 9 - Yellow
        '#343a40'  // Level 10+ - Dark
    ];
    
    function getLevelColor(level) {
        if (level >= levelColors.length) return levelColors[levelColors.length - 1];
        return levelColors[level] || levelColors[0];
    }
    
    // 1. Course Level: Student Points (Live, 2wk, 4wk) - Horizontal Bar with Goal Dots
    var courseStudentCtx = document.getElementById('courseStudentPointsChart');
    if (courseStudentCtx && leaderboardChartData.goalChartData && leaderboardChartData.goalChartData.length > 0) {
        var cspData = leaderboardChartData.goalChartData;
        new Chart(courseStudentCtx, {
            type: 'bar',
            data: {
                labels: cspData.map(function(s) { return s.name + ' (' + s.category + ')'; }),
                datasets: [
                    {
                        label: 'Live',
                        data: cspData.map(function(s) { return s.live; }),
                        backgroundColor: colors.live,
                        borderWidth: 1
                    },
                    {
                        label: '2 Weeks',
                        data: cspData.map(function(s) { return s.w2; }),
                        backgroundColor: colors.w2,
                        borderWidth: 1
                    },
                    {
                        label: '4 Weeks',
                        data: cspData.map(function(s) { return s.w4; }),
                        backgroundColor: colors.w4,
                        borderWidth: 1
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    x: { 
                        beginAtZero: true, 
                        stacked: false, 
                        title: { display: false },
                        max: Math.max(...cspData.map(function(s) { return Math.max(s.live || 0, s.w2 || 0, s.w4 || 0, s.max_live || 0, s.max_w2 || 0, s.max_w4 || 0); })) * 1.15
                    }
                }
            },
            plugins: [{
                id: 'goalDots',
                afterDraw: function(chart) {
                    var ctx = chart.ctx;
                    var yAxis = chart.scales.y;
                    var xAxis = chart.scales.x;
                    var meta = chart.getDatasetMeta(0);
                    
                    if (!meta.data || meta.data.length === 0) return;
                    
                    // Get bar height from first bar
                    var barHeight = meta.data[0].height || 10;
                    
                    // Draw goal dots for each period (Live, 2wk, 4wk)
                    var goalKeys = ['max_live', 'max_w2', 'max_w4'];
                    var numBars = goalKeys.length;
                    
                    cspData.forEach(function(item, index) {
                        var baseY = yAxis.getPixelForValue(index);
                        
                        goalKeys.forEach(function(key, barIndex) {
                            if (item[key] > 0) {
                                var goalX = xAxis.getPixelForValue(item[key]);
                                // Offset y position to align with each bar in the group
                                var barMeta = chart.getDatasetMeta(barIndex);
                                var y = barMeta.data[index] ? barMeta.data[index].y : baseY;
                                
                                // Draw red goal dot
                                ctx.save();
                                ctx.beginPath();
                                ctx.arc(goalX, y, 6, 0, 2 * Math.PI);
                                ctx.fillStyle = '#dc3545';
                                ctx.fill();
                                ctx.strokeStyle = '#fff';
                                ctx.lineWidth = 2;
                                ctx.stroke();
                                ctx.restore();
                            }
                        });
                    });
                }
            }]
        });
    }
    
    // 2. Course Level: All Time Points (Bar) + Class Level (Gauge)
    var courseAllTimeCtx = document.getElementById('courseAllTimeChart');
    if (courseAllTimeCtx && leaderboardChartData.goalChartData && leaderboardChartData.goalChartData.length > 0) {
        var courseData = leaderboardChartData.goalChartData;
        new Chart(courseAllTimeCtx, {
            type: 'bar',
            data: {
                labels: courseData.map(function(s) { return s.name; }),
                datasets: [{
                    label: 'All Time Points',
                    data: courseData.map(function(s) { return s.all_time; }),
                    backgroundColor: function(context) {
                        if (context.dataIndex === 0) {
                            var chart = context.chart;
                            var ctx = chart.ctx;
                            var gradient = ctx.createLinearGradient(0, 0, chart.width, 0);
                            gradient.addColorStop(0, '#FFD700');
                            gradient.addColorStop(0.5, '#FFA500');
                            gradient.addColorStop(1, '#FF6B00');
                            return gradient;
                        }
                        return colors.allTime;
                    },
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { 
                        beginAtZero: true, 
                        title: { display: false },
                        max: Math.max(...courseData.map(function(s) { return Math.max(s.all_time, s.max_all || 0); })) * 1.1
                    },
                    y: {
                        ticks: {
                            callback: function(value, index) {
                                return courseData[index].name;
                            }
                        }
                    }
                }
            },
            plugins: [{
                id: 'levelBadges',
                afterDraw: function(chart) {
                    var ctx = chart.ctx;
                    var yAxis = chart.scales.y;
                    var xAxis = chart.scales.x;
                    
                    courseData.forEach(function(item, index) {
                        var y = yAxis.getPixelForValue(index);
                        var barEnd = xAxis.getPixelForValue(item.all_time);
                        
                        // Draw level badge after bar with level-based color
                        ctx.save();
                        ctx.font = 'bold 10px Arial';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        
                        // Badge background with level-specific color
                        var badgeText = 'Lvl ' + item.class_level;
                        var textWidth = ctx.measureText(badgeText).width;
                        var badgeX = barEnd + 5;
                        var badgeY = y;
                        var padding = 6;
                        
                        ctx.fillStyle = getLevelColor(item.class_level);
                        ctx.beginPath();
                        ctx.roundRect(badgeX, badgeY - 10, textWidth + padding * 2, 20, 4);
                        ctx.fill();
                        
                        ctx.fillStyle = '#fff';
                        ctx.fillText(badgeText, badgeX + padding, badgeY);
                        ctx.restore();
                        
                        // Medal badges: Gold for 1st, Silver for 2nd
                        if (index === 0) {
                            ctx.save();
                            ctx.font = '14px Arial';
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('🥇', badgeX + textWidth + padding * 2 + 5, y);
                            ctx.restore();
                        } else if (index === 1) {
                            ctx.save();
                            ctx.font = '14px Arial';
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('🥈', badgeX + textWidth + padding * 2 + 5, y);
                            ctx.restore();
                        }
                        
                        // Draw red goal dot at max_all position (separate save/restore)
                        if (item.max_all > 0) {
                            ctx.save();
                            var goalX = xAxis.getPixelForValue(item.max_all);
                            ctx.beginPath();
                            ctx.arc(goalX, y, 6, 0, 2 * Math.PI);
                            ctx.fillStyle = '#dc3545';
                            ctx.fill();
                            ctx.strokeStyle = '#fff';
                            ctx.lineWidth = 2;
                            ctx.stroke();
                            ctx.restore();
                        }
                    });
                }
            }]
        });
    }
    
    
    // 3. User Level: Student Points (Live, 2wk, 4wk) - Horizontal Bar with Goal Dots
    var userStudentCtx = document.getElementById('userStudentPointsChart');
    if (userStudentCtx && leaderboardChartData.userStudentPoints && leaderboardChartData.userStudentPoints.length > 0) {
        var uspData = leaderboardChartData.userStudentPoints;
        new Chart(userStudentCtx, {
            type: 'bar',
            data: {
                labels: uspData.map(function(s) { return s.name; }),
                datasets: [
                    {
                        label: 'Live',
                        data: uspData.map(function(s) { return s.live; }),
                        backgroundColor: colors.live,
                        borderWidth: 1
                    },
                    {
                        label: '2 Weeks',
                        data: uspData.map(function(s) { return s.w2; }),
                        backgroundColor: colors.w2,
                        borderWidth: 1
                    },
                    {
                        label: '4 Weeks',
                        data: uspData.map(function(s) { return s.w4; }),
                        backgroundColor: colors.w4,
                        borderWidth: 1
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    x: { 
                        beginAtZero: true, 
                        stacked: false, 
                        title: { display: false },
                        max: Math.max(...uspData.map(function(s) { return Math.max(s.live || 0, s.w2 || 0, s.w4 || 0, s.max_live || 0, s.max_w2 || 0, s.max_w4 || 0); })) * 1.15
                    }
                }
            },
            plugins: [{
                id: 'userGoalDots',
                afterDraw: function(chart) {
                    var ctx = chart.ctx;
                    var yAxis = chart.scales.y;
                    var xAxis = chart.scales.x;
                    var meta = chart.getDatasetMeta(0);
                    
                    if (!meta.data || meta.data.length === 0) return;
                    
                    // Draw goal dots for each period (Live, 2wk, 4wk)
                    var goalKeys = ['max_live', 'max_w2', 'max_w4'];
                    
                    uspData.forEach(function(item, index) {
                        var baseY = yAxis.getPixelForValue(index);
                        
                        goalKeys.forEach(function(key, barIndex) {
                            if (item[key] > 0) {
                                var goalX = xAxis.getPixelForValue(item[key]);
                                // Offset y position to align with each bar in the group
                                var barMeta = chart.getDatasetMeta(barIndex);
                                var y = barMeta.data[index] ? barMeta.data[index].y : baseY;
                                
                                // Draw red goal dot
                                ctx.save();
                                ctx.beginPath();
                                ctx.arc(goalX, y, 6, 0, 2 * Math.PI);
                                ctx.fillStyle = '#dc3545';
                                ctx.fill();
                                ctx.strokeStyle = '#fff';
                                ctx.lineWidth = 2;
                                ctx.stroke();
                                ctx.restore();
                            }
                        });
                    });
                }
            }]
        });
    }
    
    // 4. User Level: Intellect Points (Bar) + Level (Gauge)
    var userIntellectCtx = document.getElementById('userIntellectChart');
    if (userIntellectCtx && leaderboardChartData.userStudentPoints && leaderboardChartData.userStudentPoints.length > 0) {
        var userData = leaderboardChartData.userStudentPoints;
        new Chart(userIntellectCtx, {
            type: 'bar',
            data: {
                labels: userData.map(function(s) { return s.name; }),
                datasets: [{
                    label: 'Intellect Points',
                    data: userData.map(function(s) { return s.intellect_points; }),
                    backgroundColor: function(context) {
                        if (context.dataIndex === 0) {
                            var chart = context.chart;
                            var ctx = chart.ctx;
                            var gradient = ctx.createLinearGradient(0, 0, chart.width, 0);
                            gradient.addColorStop(0, '#FFD700');
                            gradient.addColorStop(0.5, '#FFA500');
                            gradient.addColorStop(1, '#FF6B00');
                            return gradient;
                        }
                        return colors.allTime;
                    },
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { 
                        beginAtZero: true, 
                        title: { display: false },
                        max: Math.max(...userData.map(function(s) { return Math.max(s.intellect_points || 0, s.max_ip || 0); })) * 1.15
                    },
                    y: {
                        ticks: {
                            callback: function(value, index) {
                                return userData[index].name;
                            }
                        }
                    }
                }
            },
            plugins: [{
                id: 'userLevelBadges',
                afterDraw: function(chart) {
                    var ctx = chart.ctx;
                    var yAxis = chart.scales.y;
                    var xAxis = chart.scales.x;
                    
                    userData.forEach(function(item, index) {
                        var y = yAxis.getPixelForValue(index);
                        var barEnd = xAxis.getPixelForValue(item.intellect_points);
                        
                        // Draw level badge after bar with level-based color
                        ctx.save();
                        ctx.font = 'bold 10px Arial';
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        
                        // Badge background with level-specific color
                        var badgeText = 'Lvl ' + item.level;
                        var textWidth = ctx.measureText(badgeText).width;
                        var badgeX = barEnd + 5;
                        var badgeY = y;
                        var padding = 6;
                        
                        ctx.fillStyle = getLevelColor(item.level);
                        ctx.beginPath();
                        ctx.roundRect(badgeX, badgeY - 10, textWidth + padding * 2, 20, 4);
                        ctx.fill();
                        
                        ctx.fillStyle = '#fff';
                        ctx.fillText(badgeText, badgeX + padding, badgeY);
                        ctx.restore();
                        
                        // Medal badges: Gold for 1st, Silver for 2nd
                        if (index === 0) {
                            ctx.save();
                            ctx.font = '14px Arial';
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('🥇', badgeX + textWidth + padding * 2 + 5, y);
                            ctx.restore();
                        } else if (index === 1) {
                            ctx.save();
                            ctx.font = '14px Arial';
                            ctx.textAlign = 'left';
                            ctx.textBaseline = 'middle';
                            ctx.fillText('🥈', badgeX + textWidth + padding * 2 + 5, y);
                            ctx.restore();
                        }
                        
                        // Draw red goal dot at max_ip position (separate save/restore)
                        if (item.max_ip > 0) {
                            ctx.save();
                            var goalX = xAxis.getPixelForValue(item.max_ip);
                            ctx.beginPath();
                            ctx.arc(goalX, y, 6, 0, 2 * Math.PI);
                            ctx.fillStyle = '#dc3545';
                            ctx.fill();
                            ctx.strokeStyle = '#fff';
                            ctx.lineWidth = 2;
                            ctx.stroke();
                            ctx.restore();
                        }
                    });
                }
            }]
        });
    }
}
</script>
<?php
    echo $OUTPUT->footer();
    exit; // Stop processing to isolate tab
}

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
                    <?php if ($tab !== 'reports'): ?>
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

    <?php if ($tab === 'snapshot' && $canmanage): ?>
    <!-- Bulk Actions Section -->
    <div class="bulk-actions-container" style="margin-bottom: 15px;">
        <div class="bulk-actions-row" style="display: flex; align-items: center; gap: 10px;">
            <div class="bulk-actions-group" style="display: flex; gap: 5px;">
                <select id="bulk-action-select" class="custom-select" style="width: auto;">
                    <option value="">With selected...</option>
                    <option value="delete">Delete Permanently</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="executeBulkAction()" disabled id="apply-bulk-action">Apply</button>
            </div>
            <div class="selected-count">
                <span id="selected-count" class="text-muted">0 items selected</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'reports'): ?>
    <!-- REPORTS TABLE -->
    <!-- Bulk Actions -->
    <?php if ($canmanage): ?>
    <div class="bulk-actions-container" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
        <span class="font-weight-bold mr-2">Generate:</span>
        <select id="report-lang-select" class="custom-select" style="width: auto;">
            <option value="en">English</option>
            <option value="ko">Korean</option>
            <option value="both">Both (English & Korean)</option>
        </select>
        <button id="btn-generate-reports" type="button" class="btn btn-primary">Generate Reports</button>
        
        <span class="border-left mx-3" style="height: 24px; border-color: #ccc;"></span>

        <button id="btn-send-emails" type="button" class="btn btn-success">Send Emails</button>
    </div>
    <?php endif; ?>

    <div class="dashboard-table-wrapper">
        <table class="dashboard-table table table-striped" id="reports-table">
            <thead class="thead-dark">
                <tr>
                    <?php if ($canmanage): ?>
                    <th style="width: 40px;"><input type="checkbox" id="select-all-reports"></th>
                    <?php endif; ?>
                    <th>Name</th>
                    <th>ID</th>
                    <?php if ($canmanage): ?>
                    <th>Email</th>
                    <th>Parent 1</th>
                    <th>Parent 2</th>
                    <?php endif; ?>
                    <th>Due Date</th>
                    <?php if ($canmanage): ?>
                    <th>Due Date 2</th>
                    <th>Categories</th>
                    <?php endif; ?>
                    <th>Courses</th>
                    <!-- <th>Classifications</th> Removed -->
                    <?php if ($canmanage): ?>
                    <th>Activities 1</th>
                    <th>Activities 2</th>
                    <?php endif; ?>
                    <th>Reports</th>
                    <?php if ($tab !== 'reports'): ?>
                    <th>Points</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="15" class="no-data"><?php echo get_string('nothingtodisplay'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php if ($canmanage): ?>
                            <td>
                                <input type="checkbox" class="report-checkbox" 
                                       data-userid="<?php echo $row->userid; ?>" 
                                       data-duedate="<?php echo $row->timeclose; ?>"
                                       data-studentname="<?php echo s($row->studentname); ?>">
                            </td>
                            <?php endif; ?>
                            <td>
                                <a href="#" class="filter-link" onclick="setFilter('studentname', '<?php echo (int)$row->userid; ?>'); return false;" title="Click to filter by this student">
                                    <?php echo s($row->studentname); ?>
                                </a>
                            </td>
                            <td><?php echo (int)$row->userid; ?></td>
                            <?php if ($canmanage): ?>
                            <td><?php echo s($row->email); ?></td>
                            <td>
                                <?php if (!empty($row->parent1) && (!empty($row->parent1->name) || !empty($row->parent1->email))): ?>
                                    <?php if (!empty($row->parent1->name)): ?>
                                        <div><?php echo s($row->parent1->name); ?></div>
                                    <?php endif; ?>
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
                            <?php endif; ?>
                            <?php if ($canmanage): ?>
                            <td>
                                <?php if (!empty($row->parent2) && (!empty($row->parent2->name) || !empty($row->parent2->email))): ?>
                                    <?php if (!empty($row->parent2->name)): ?>
                                        <div><?php echo s($row->parent2->name); ?></div>
                                    <?php endif; ?>
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
                            <?php endif; ?>
                            <td>
                                <a href="#" class="filter-link" onclick="setFilter('duedate', '<?php echo (int)$row->timeclose; ?>'); return false;" title="Click to filter by this due date">
                                    <?php echo userdate($row->timeclose, get_string('strftimedate', 'langconfig')); ?>
                                </a>
                            </td>
                            <?php if ($canmanage): ?>
                            <td>
                                <?php echo !empty($row->next_due_date) ? userdate($row->next_due_date, get_string('strftimedate', 'langconfig')) : '-'; ?>
                            </td>
                            <td>
                                <?php foreach ($row->categories as $cat): ?>
                                    <div class="mb-1"><span class="badge badge-primary text-white"><?php echo s($cat); ?></span></div>
                                <?php endforeach; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php foreach ($row->courses as $c): ?>
                                    <div class="mb-1"><span class="badge badge-info"><?php echo s($c['name']); ?></span></div>
                                <?php endforeach; ?>
                            </td>
                            <!-- Classifications column removed -->
                            <?php if ($canmanage): ?>
                            <td>
                                <?php if (!empty($row->activities)): ?>
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
                                <?php else: ?>
                                    -
                                <?php endif; ?>
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
                            <?php endif; ?>
                            <td id="status-<?php echo $row->userid . '-' . $row->timeclose; ?>">
                                <?php
                                $reports = $row->reports ?? [];
                                $has_report = false;
                                if (!empty($reports['en'])) {
                                    echo '<a href="view_report.php?id=' . $reports['en'] . '" class="btn btn-info btn-sm" target="_blank" style="margin-right: 5px;">English</a>';
                                    $has_report = true;
                                }
                                if (!empty($reports['ko'])) {
                                    echo '<a href="view_report.php?id=' . $reports['ko'] . '" class="btn btn-success btn-sm" target="_blank">Korean</a>';
                                    $has_report = true;
                                }
                                if ($canmanage) {
                                    if (!empty($row->emailsent) && $row->emailsent > 0) {
                                        echo ' <span class="badge badge-success">Email Sent</span>';
                                    } elseif (!$has_report) {
                                        echo '<span class="badge badge-light">Not Sent</span>';
                                    }
                                }
                                ?>
                            </td>
                            <?php if ($tab !== 'reports'): ?>
                            <td>
                                <?php echo isset($row->points) ? number_format($row->points, 1) : '-'; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        

        // Process Queue Function
        var processQueue = function(queue, index) {
            
            
            if (index >= queue.length) {
                alert('Process completed.');
                return;
            }

            var item = queue[index];
            

            var actionUrl = (item.action === 'sendemail') ? 'ajax_email_report.php' : 'ajax_send_report.php';
            var statusText = (item.action === 'sendemail') ? 'Sending Email...' : 'Generating...';
            
            // Update UI status
            var statusCell = document.getElementById('status-' + item.userid + '-' + item.timeclose);
            if (statusCell) {
                var badges = statusCell.querySelectorAll('.badge');
                badges.forEach(function(b) { 
                    if (b.innerText === 'Not Sent' || b.innerText === 'Error' || b.innerText === 'Email Sent') b.remove(); 
                });

                // Add status badge
                if (!statusCell.innerHTML.includes(statusText)) {
                        statusCell.insertAdjacentHTML('beforeend', ' <span class="badge badge-warning">' + statusText + '</span>');
                }
            } else {
                console.warn('Status cell not found for user ' + item.userid);
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', actionUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    
                    

                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.status === 'success') {
                                
                                if (statusCell) {
                                    // Remove status badge
                                    var badges = statusCell.querySelectorAll('.badge');
                                    badges.forEach(function(b) { 
                                        if (b.innerText === statusText) b.remove(); 
                                    });

                                    if (item.action === 'generate') {
                                        // Append Button
                                        var btnClass = (item.lang === 'ko') ? 'btn-success' : 'btn-info';
                                        var btnText = (item.lang === 'ko') ? 'Korean' : 'English';
                                        var btnHtml = '<a href="view_report.php?id=' + resp.reportid + '" class="btn ' + btnClass + ' btn-sm" target="_blank" style="margin-right: 5px;">' + btnText + '</a>';
                                        
                                        if (!statusCell.innerHTML.includes('>' + btnText + '<')) {
                                            statusCell.insertAdjacentHTML('beforeend', btnHtml);
                                        }
                                    } else if (item.action === 'sendemail') {
                                        statusCell.insertAdjacentHTML('beforeend', ' <span class="badge badge-success">Email Sent</span>');
                                    }
                                }
                            } else {
                                console.error('Error for ' + item.userid + ': ' + resp.message);
                                if (statusCell) {
                                    var badges = statusCell.querySelectorAll('.badge');
                                    badges.forEach(function(b) { 
                                        if (b.innerText === statusText) b.remove(); 
                                    });
                                    statusCell.insertAdjacentHTML('beforeend', ' <span class="badge badge-danger">Error: ' + resp.message + '</span>');
                                }
                                alert('Error for user ' + item.userid + ': ' + resp.message);
                            }
                        } catch (e) {
                            console.error('JSON Parse Error', e);
                            console.error('Raw Response:', xhr.responseText);
                            if (statusCell) {
                                var badges = statusCell.querySelectorAll('.badge');
                                badges.forEach(function(b) { 
                                    if (b.innerText === statusText) b.remove(); 
                                });
                                statusCell.insertAdjacentHTML('beforeend', ' <span class="badge badge-danger">Error: JSON Parse</span>');
                            }
                            alert('JSON Parse Error for user ' + item.userid + '. Check console for details.');
                        }
                    } else {
                        console.error('HTTP Error:', xhr.status);
                        if (statusCell) statusCell.innerHTML = '<span class="badge badge-danger">HTTP Error ' + xhr.status + '</span>';
                        alert('HTTP Error ' + xhr.status + ' for user ' + item.userid);
                    }
                    // Next
                    processQueue(queue, index + 1);
                }
            };
            
            xhr.onerror = function() {
                console.error('XHR Network Error');
                alert('Network Error occurred.');
                processQueue(queue, index + 1);
            };

            var params = 'userid=' + item.userid + '&timeclose=' + item.timeclose;
            if (item.lang) {
                params += '&lang=' + item.lang;
            }
            
            xhr.send(params);
        };

        // Generate Reports Button
        var btnGenerate = document.getElementById('btn-generate-reports');
        if (btnGenerate) {
            
            btnGenerate.addEventListener('click', function(e) {
                
                e.preventDefault();
                var lang = document.getElementById('report-lang-select').value;
                
                
                var selected = [];
                document.querySelectorAll('.report-checkbox:checked').forEach(function(cb) {
                    if (lang === 'both') {
                        selected.push({
                            userid: cb.dataset.userid,
                            timeclose: cb.dataset.duedate,
                            lang: 'en',
                            action: 'generate'
                        });
                        selected.push({
                            userid: cb.dataset.userid,
                            timeclose: cb.dataset.duedate,
                            lang: 'ko',
                            action: 'generate'
                        });
                    } else {
                        selected.push({
                            userid: cb.dataset.userid,
                            timeclose: cb.dataset.duedate,
                            lang: lang,
                            action: 'generate'
                        });
                    }
                });

                

                if (selected.length === 0) {
                    alert('Please select at least one student.');
                    return;
                }

                alert('Starting generation for ' + selected.length + ' reports...');
                processQueue(selected, 0);
            });
        } else {
            console.error('Generate Reports button NOT found');
        }

        // Send Emails Button
        var btnSend = document.getElementById('btn-send-emails');
        if (btnSend) {
            
            btnSend.addEventListener('click', function(e) {
                
                e.preventDefault();
                var selected = [];
                document.querySelectorAll('.report-checkbox:checked').forEach(function(cb) {
                    selected.push({
                        userid: cb.dataset.userid,
                        timeclose: cb.dataset.duedate,
                        action: 'sendemail'
                    });
                });

                

                if (selected.length === 0) {
                    alert('Please select at least one student.');
                    return;
                }

                if (confirm('Are you sure you want to send emails to ' + selected.length + ' students?')) {
                    processQueue(selected, 0);
                }
            });
        } else {
            console.error('Send Emails button NOT found');
        }
    });
    </script>
    
    <?php else: ?>
    <!-- EXISTING TABLE (Live/Snapshot) -->
    <div class="dashboard-table-wrapper">
        <table class="dashboard-table table table-striped">

            <thead class="thead-dark">
                <tr>
                    <th class="bulk-select-header">
                        <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)">
                    </th>
                    <th></th> <!-- Expand -->
                    <th class="sortable-column" data-sort="userid">ID <?php echo local_homeworkdashboard_sort_arrows('userid', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="studentname">Name <?php echo local_homeworkdashboard_sort_arrows('studentname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="categoryname"><?php echo get_string('col_category', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('categoryname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="coursename"><?php echo get_string('col_course', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('coursename', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="quizname"><?php echo get_string('col_quiz', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('quizname', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="status"><?php echo get_string('status'); ?> <?php echo local_homeworkdashboard_sort_arrows('status', $sort, $dir); ?></th>
                    <th>Points</th>
                    <th class="sortable-column" data-sort="attemptno"><?php echo get_string('col_attempt', 'local_homeworkdashboard'); ?> <?php echo local_homeworkdashboard_sort_arrows('attemptno', $sort, $dir); ?></th>
                    <th class="sortable-column" data-sort="classification">Classification <?php echo local_homeworkdashboard_sort_arrows('classification', $sort, $dir); ?></th>
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
                        <td colspan="15" class="no-data"><?php echo get_string('nothingtodisplay'); ?></td>
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
                                <input type="checkbox" class="row-checkbox" value="<?php echo $row->id; ?>" onchange="updateSelectedCount()">
                            </td>
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
                                    } else if ($st === "No attempt" || $st === "To do") {
                                        $badgeclass = "hw-badge-noattempt";
                                        $badgetext = "To do";
                                    }
                                    echo '<span class="hw-badge ' . $badgeclass . '">' . s($badgetext) . '</span>';
                                ?>
                            </td>
                            <td>
                                <?php echo isset($row->points) ? number_format($row->points, 1) : '-'; ?>
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
                            <td colspan="16">
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
            // Handle multi-select fields
            if (field.multiple) {
                // Clear all selections first
                Array.from(field.options).forEach(opt => opt.selected = false);
                // Select the matching option
                const option = Array.from(field.options).find(opt => opt.value === String(value));
                if (option) {
                    option.selected = true;
                }
            } else {
                field.value = value;
            }
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

<script>
// Bulk Action Functions
function toggleAllCheckboxes(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selected-count');
    const applyButton = document.getElementById('apply-bulk-action');
    
    if (selectedCountSpan) {
        selectedCountSpan.textContent = count + (count === 1 ? ' item selected' : ' items selected');
    }
    if (applyButton) {
        applyButton.disabled = count === 0;
    }
    
    // Update master checkbox state
    const masterCheckbox = document.getElementById('select-all');
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    if (masterCheckbox) {
        masterCheckbox.checked = count > 0 && count === allCheckboxes.length;
        masterCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
    }
}

function executeBulkAction() {
    const actionSelect = document.getElementById('bulk-action-select');
    const selectedAction = actionSelect.value;
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    
    if (!selectedAction || checkboxes.length === 0) {
        alert('Please select an action and at least one item.');
        return;
    }
    
    const snapshotIds = Array.from(checkboxes).map(cb => cb.value);
    let confirmMessage = '';
    
    if (selectedAction === 'delete') {
        confirmMessage = `⚠️ WARNING: This will permanently delete ${snapshotIds.length} snapshot(s) and their associated reports.\n\nThe actual quiz attempts will NOT be deleted.\n\nAre you sure you want to proceed?`;
    } else {
        return; // Unknown action
    }
    
    if (!confirm(confirmMessage)) {
        return;
    }
    
    const button = document.getElementById('apply-bulk-action');
    const originalText = button.textContent;
    button.textContent = 'Processing...';
    button.disabled = true;
    
    const formData = new FormData();
    formData.append('snapshotids', snapshotIds.join(','));
    formData.append('sesskey', M.cfg.sesskey);

    fetch('ajax_delete_snapshot.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            // Remove deleted rows from UI
            checkboxes.forEach(cb => {
                const row = cb.closest('tr');
                const nextRow = row.nextElementSibling;
                row.remove();
                if (nextRow && nextRow.classList.contains('hw-attempts-row')) {
                    nextRow.remove();
                }
            });
            // Reset UI
            document.getElementById('select-all').checked = false;
            updateSelectedCount();
            actionSelect.value = '';
        } else {
            alert('Error: ' + data.message);
        }
        button.textContent = originalText;
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred.');
        button.textContent = originalText;
        button.disabled = false;
    });
}
</script>
<?php
echo $OUTPUT->footer();
