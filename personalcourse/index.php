<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

$context = context_system::instance();
require_capability('local/personalcourse:viewdashboard', $context);

$PAGE->set_url(new moodle_url('/local/personalcourse/index.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
admin_externalpage_setup('local_personalcourse_dashboard');

$title = get_string('dashboard', 'local_personalcourse');
$PAGE->set_title($title);
$PAGE->set_heading($title);

// New default view: show attempts like Quiz Dashboard and allow admin actions.
$mode = optional_param('mode', 'attempts', PARAM_ALPHA);

if ($mode === 'attempts') {
    // Add CSS from Quiz Dashboard to ensure matching style
    $PAGE->requires->css('/local/quizdashboard/styles.css');
}

// Handle actions.
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
if ($action === 'forcecreate' && $userid > 0) {
    require_sesskey();
    try {
        $gen = new \local_personalcourse\course_generator();
        $result = $gen->ensure_personal_course($userid);
        // Enrol the student to their personal course.
        $enrol = new \local_personalcourse\enrollment_manager();
        $enrol->ensure_manual_instance_and_enrol_student((int)$result->course->id, $userid);
        redirect($PAGE->url, get_string('forcecreate_success', 'local_personalcourse'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        redirect($PAGE->url, get_string('forcecreate_error', 'local_personalcourse', $e->getMessage()), 0, \core\output\notification::NOTIFY_ERROR);
    }
}
if ($action === 'rename' && $userid > 0) {
    require_sesskey();
    try {
        $gen = new \local_personalcourse\course_generator();
        $gen->ensure_personal_course($userid); // Will normalize the name if needed.
        redirect($PAGE->url, get_string('rename_success', 'local_personalcourse'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\Throwable $e) {
        redirect($PAGE->url, get_string('rename_error', 'local_personalcourse', $e->getMessage()), 0, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);

if ($mode === 'attempts') {
    require_once($CFG->dirroot . '/local/quizdashboard/classes/quiz_manager.php');
    $qm = new \local_quizdashboard\quiz_manager();

    // --- Filter Parameters (matching Quiz Dashboard) ---
    $userid      = optional_param('userid', '', PARAM_INT);
    $categoryid  = optional_param('categoryid', 0, PARAM_INT);
    $filter_userid = optional_param('filter_userid', '', PARAM_INT);
    $studentname = optional_param('studentname', '', PARAM_TEXT);
    $coursename  = optional_param('coursename', '', PARAM_TEXT);
    $filter_coursename = optional_param('filter_coursename', '', PARAM_TEXT);
    $quizname    = optional_param('quizname', '', PARAM_TEXT);
    $sectionid   = optional_param('sectionid', '', PARAM_INT);
    $month       = optional_param('month', '', PARAM_TEXT);
    $status      = optional_param('status', '', PARAM_ALPHA);
    $quiztype    = optional_param('quiztype', 'Non-Essay', PARAM_TEXT); // Default to Non-Essay as per Quiz Dashboard
    $sort        = optional_param('sort', 'timefinish', PARAM_ALPHA);
    $dir         = optional_param('dir', 'DESC', PARAM_ALPHA);
    $filtersubmitted = optional_param('filtersubmitted', 0, PARAM_BOOL);
if ($filtersubmitted) {
    $excludestaff = optional_param('excludestaff', 0, PARAM_BOOL);
} else {
    $excludestaff = 1;
}

    // Filter by clicked user or course
    $filter_by_user   = optional_param('filter_user', '', PARAM_TEXT);
    $filter_by_course = optional_param('filter_course', '', PARAM_TEXT);

    if (!empty($filter_userid)) { $userid = $filter_userid; }
    if (!empty($filter_coursename)) { $coursename = $filter_coursename; }
    if (!empty($filter_by_user)) { $studentname = $filter_by_user; }
    if (!empty($filter_by_course)) { $coursename = $filter_by_course; }

    // --- Fetch Filter Options ---
    $unique_users    = $qm->get_unique_users();
    $unique_courses  = $qm->get_unique_course_names((int)$categoryid);
    $unique_quizzes  = $qm->get_unique_quiz_names();
    
    // Determine selected courseid for section filtering
    $selected_courseid = 0;
    if (!empty($coursename) && !empty($unique_courses)) {
        foreach ($unique_courses as $cobj) {
            if (isset($cobj->fullname) && $cobj->fullname === $coursename) { $selected_courseid = (int)$cobj->id; break; }
        }
    }
    $unique_sections = $qm->get_unique_sections((int)$categoryid, (int)$selected_courseid);

    // Get unique user IDs for dropdown
    $unique_userids = [];
    try {
        $sql = "SELECT DISTINCT u.id, u.id AS userid
                  FROM {user} u
                  JOIN {quiz_attempts} qa ON qa.userid = u.id
                  JOIN {quiz} q ON qa.quiz = q.id
                  JOIN {course} c ON q.course = c.id
                 WHERE u.deleted = 0 
                   AND c.visible = 1
                   AND qa.state IN ('finished', 'inprogress')
              ORDER BY u.id";
        $unique_userids = $DB->get_records_sql($sql);
    } catch (\Exception $e) { }

    // Categories
    $categories = [];
    try {
        $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
        if (empty($categoryid)) {
            $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id');
            if ($catrow) { $categoryid = (int)$catrow->id; }
        }
    } catch (\Throwable $e) { }


    // --- Fetch Data ---
    $records = $qm->get_filtered_quiz_attempts(
        $userid, $studentname, $coursename, $quizname, '', '', $quiztype, $sort, $dir, 0, 0, $status, $sectionid, (int)$categoryid, $excludestaff
    );

    // Apply month filter (PHP side)
    if (!empty($month)) {
        $records = array_filter($records, function($r) use ($month) {
            if (!empty($r->timefinish)) {
                return date('Y-m', $r->timefinish) === $month;
            }
            return false;
        });
    }
    // Apply status filter (PHP side if not fully handled by SQL)
    if (!empty($status)) {
        $records = array_filter($records, function($r) use ($status){ 
            return strtolower($r->status) === strtolower($status); 
        });
    }

    // --- Render Filter Form ---
    ?>
    <div class="essay-dashboard-container">
        <div class="dashboard-filters">
            <form method="get" action="<?php echo $PAGE->url->out(false); ?>" class="filter-form">
            <input type="hidden" name="filtersubmitted" value="1" />
                <input type="hidden" name="mode" value="attempts">
                
                <div class="filter-row">
                    <div class="filter-group">
                        <label><?php echo get_string('category'); ?></label>
                        <select name="categoryid" class="form-control" onchange="this.form.submit()">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><?php echo get_string('course'); ?></label>
                        <select name="coursename" class="form-control" onchange="this.form.submit()">
                            <option value="">All Courses</option>
                            <?php foreach ($unique_courses as $c): ?>
                                <?php if(!empty($c->fullname)): ?>
                                <option value="<?php echo s($c->fullname); ?>" <?php echo ($coursename === $c->fullname) ? 'selected' : ''; ?>>
                                    <?php echo format_string($c->fullname); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><?php echo get_string('section'); ?></label>
                        <select name="sectionid" class="form-control" onchange="this.form.submit()">
                            <option value="">All Sections</option>
                            <?php foreach ($unique_sections as $sec): ?>
                                <option value="<?php echo $sec->id; ?>" <?php echo ((int)$sectionid === (int)$sec->id) ? 'selected' : ''; ?>>
                                    <?php echo format_string($sec->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><?php echo get_string('modulename', 'quiz'); ?></label>
                        <select name="quizname" class="form-control" onchange="this.form.submit()">
                            <option value="">All Quizzes</option>
                            <?php foreach ($unique_quizzes as $q): ?>
                                <?php if(!empty($q->quizname)): ?>
                                <option value="<?php echo s($q->quizname); ?>" <?php echo ($quizname === $q->quizname) ? 'selected' : ''; ?>>
                                    <?php echo format_string($q->quizname); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                
                    <div class="filter-group">
                        <label><?php echo get_string('quiztype', 'local_homeworkdashboard'); ?></label>
                        <select name="quiztype" class="form-control" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="Essay" <?php echo ($quiztype === 'Essay') ? 'selected' : ''; ?>>Essay</option>
                            <option value="Non-Essay" <?php echo ($quiztype === 'Non-Essay') ? 'selected' : ''; ?>>Non-Essay</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><?php echo get_string('user'); ?></label>
                        <select name="studentname" class="form-control" onchange="this.form.submit()">
                            <option value="">All Users</option>
                            <?php foreach ($unique_users as $u): ?>
                                <?php if(!empty($u->fullname)): ?>
                                <option value="<?php echo s($u->fullname); ?>" <?php echo ($studentname === $u->fullname) ? 'selected' : ''; ?>>
                                    <?php echo format_string($u->fullname); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>User ID</label>
                        <select name="userid" class="form-control" onchange="this.form.submit()">
                            <option value="">All User IDs</option>
                            <?php foreach ($unique_userids as $u): ?>
                                <option value="<?php echo $u->id; ?>" <?php echo ((int)$userid === (int)$u->id) ? 'selected' : ''; ?>>
                                    <?php echo $u->id; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label><?php echo get_string('status'); ?></label>
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="Completed" <?php echo ($status === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="In Progress" <?php echo ($status === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Overdue" <?php echo ($status === 'Overdue') ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Month</label>
                        <select name="month" class="form-control" onchange="this.form.submit()">
                            <option value="">All Months</option>
                            <?php
                            // Generate last 12 months dynamic options or similar
                            for ($i = 0; $i < 12; $i++) {
                                $m = date('Y-m', strtotime("-$i months"));
                                $l = date('F Y', strtotime("-$i months"));
                                $sel = ($month === $m) ? 'selected' : '';
                                echo "<option value='$m' $sel>$l</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group checkbox-group">
                        <div class="form-check" style="padding-top: 30px;">
                            <input type="checkbox" name="excludestaff" value="1" id="id_excludestaff" class="form-check-input" <?php echo $excludestaff ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <label class="form-check-label" for="id_excludestaff">Exclude staff</label>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary"><?php echo get_string('filter'); ?></button>
                        <a href="<?php echo $PAGE->url->out(false, ['mode'=>'attempts']); ?>" class="btn btn-secondary"><?php echo get_string('reset'); ?></a>
                    </div>
                </div>
            </form>
        </div>

    <?php
    // --- Render Table ---
    // Helper for sorting headers
    $sort_link = function($colname, $label) use ($PAGE, $sort, $dir) {
        $newdir = ($sort == $colname && $dir == 'ASC') ? 'DESC' : 'ASC';
        $icon = '';
        if ($sort == $colname) {
            $icon = ($dir == 'ASC') ? ' ▲' : ' ▼';
        }
        $url = new moodle_url($PAGE->url, $_GET); // preserve current filters
        $url->param('sort', $colname);
        $url->param('dir', $newdir);
        return html_writer::link($url, $label . $icon);
    };

    $table = new html_table();
    $table->attributes['class'] = 'dashboard-table table table-striped table-hover';
    $table->head = [
        '<input type="checkbox" id="select-all-attempts">', // Bulk action checkbox
        $sort_link('attemptid', 'ID'),
        $sort_link('studentname', get_string('fullnameuser')),
        $sort_link('categoryname', get_string('category')),
        $sort_link('coursename', get_string('course')),
        $sort_link('sectionname', get_string('section')),
        $sort_link('quizname', get_string('modulename', 'quiz')),
        $sort_link('attemptnumber', 'Attempt #'),
        $sort_link('quiztype', 'Quiz Type'),
        $sort_link('status', get_string('status')),
        $sort_link('timefinish', 'Finished'),
        $sort_link('duration', 'Duration'),
        $sort_link('score', get_string('score', 'quiz')),
        get_string('col_actions', 'local_personalcourse'),
    ];
    $table->data = [];

    if ($records) {
        foreach ($records as $r) {
            $attemptid = (int)$r->attemptid;
            $useridrow = (int)$r->userid;
            $quizidrow = (int)$DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
            $cmid = null;
            if ($quizidrow && $DB->record_exists('quiz', ['id' => $quizidrow])) {
                $cm = get_coursemodule_from_instance('quiz', $quizidrow, (int)$r->courseid, IGNORE_MISSING);
                if ($cm) { $cmid = (int)$cm->id; }
            }
            $reviewurl = new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]);
            $courselink = new moodle_url('/course/view.php', ['id' => (int)$r->courseid]);
            $userprofile = new moodle_url('/user/profile.php', ['id' => $useridrow]);

            // Duration
            $time_taken = '-';
            if (!empty($r->timestart) && !empty($r->timefinish)) {
                $seconds = max(0, (int)$r->timefinish - (int)$r->timestart);
                $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
                $time_taken = $h > 0 ? sprintf('%dh %dm %ds', $h, $m, $s) : ($m > 0 ? sprintf('%dm %ds', $m, $s) : sprintf('%ds', $s));
            }

            // Score
            $scorecell = '-';
            if (isset($r->score) && isset($r->maxscore)) {
                $scorecell = round((float)$r->score) . ' / ' . round((float)$r->maxscore);
            }

            // Status Badge
            $status_class = 'badge ';
            switch(strtolower($r->status)) {
                case 'completed': $status_class .= 'badge-success'; break;
                case 'in progress': $status_class .= 'badge-info'; break;
                case 'overdue': $status_class .= 'badge-danger'; break;
                default: $status_class .= 'badge-secondary';
            }
            $statusbadge = html_writer::span($r->status, $status_class);

            // Actions: Create Personal Quiz
            $actions = [];
            $haspc = $DB->record_exists('local_personalcourse_courses', ['userid' => $useridrow]);
            if (!$haspc) {
                $forceurl = new moodle_url($PAGE->url, ['action' => 'forcecreate', 'userid' => $useridrow, 'sesskey' => sesskey()]);
                $actions[] = html_writer::link($forceurl, get_string('action_forcecreate', 'local_personalcourse'));
            }
            if ($quizidrow) {
                $createquizurl = new moodle_url('/local/personalcourse/create_quiz.php', [
                    'userid' => $useridrow,
                    'courseid' => (int)$r->courseid,
                    'quizid' => $quizidrow,
                    'attemptid' => $attemptid,
                    'sesskey' => sesskey(),
                ]);
                $actions[] = html_writer::link($createquizurl, get_string('action_createquiz', 'local_personalcourse'));
            }
            $actionscell = implode(' | ', $actions);

            $table->data[] = [
                '<input type="checkbox" class="attempt-select" name="attempt_ids[]" value="'.$attemptid.'">',
                $attemptid,
                html_writer::link($userprofile, s($r->studentname)),
                s($r->categoryname ?? '-'),
                html_writer::link($courselink, s($r->coursename)),
                s($r->sectionname ?? '-'),
                html_writer::link($reviewurl, s($r->quizname)),
                html_writer::link($reviewurl, (string)$r->attemptnumber),
                s($r->quiztype ?? '-'),
                $statusbadge,
                !empty($r->timefinish) ? userdate($r->timefinish, '%Y-%m-%d %H:%M') : '-',
                $time_taken,
                $scorecell,
                $actionscell,
            ];
        }
    } else {
        $table->data[] = [
            ['data' => get_string('no_records', 'local_personalcourse'), 'colspan' => 14, 'class' => 'text-center text-muted']
        ];
    }

    echo html_writer::table($table);
    
    echo '</div>'; // Close essay-dashboard-container

    echo $OUTPUT->footer();
    exit;
}

// Optional search filter.
$q = optional_param('q', '', PARAM_TEXT);
$params = [];
$wheres = ['u.deleted = 0'];
if ($q !== '') {
    $wheres[] = $DB->sql_like("CONCAT(u.firstname, ' ', u.lastname)", ":q1", false, false) . ' OR ' . $DB->sql_like('u.email', ':q2', false, false);
    $params['q1'] = "%{$q}%";
    $params['q2'] = "%{$q}%";
}

// Fetch users and their personal course mapping and enrolled course count.
$sql = "
    SELECT u.id AS userid,
           u.firstname, u.lastname, u.email,
           pc.courseid AS personalcourseid,
           c.fullname AS personalcoursefullname,
           c.shortname AS personalcourseshortname,
           (
             SELECT COUNT(DISTINCT crs.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
               JOIN {course} crs ON crs.id = e.courseid
              WHERE ue.userid = u.id
           ) AS enrolledcount
      FROM {user} u
 LEFT JOIN {local_personalcourse_courses} pc ON pc.userid = u.id
 LEFT JOIN {course} c ON c.id = pc.courseid
     WHERE " . implode(' AND ', $wheres) . "
  ORDER BY u.lastname, u.firstname
";

$users = $DB->get_records_sql($sql, $params);

$table = new html_table();
$table->head = [
    get_string('col_user', 'local_personalcourse'),
    get_string('col_userid', 'local_personalcourse'),
    get_string('col_personalcourse', 'local_personalcourse'),
    get_string('col_courses', 'local_personalcourse'),
    get_string('col_sections', 'local_personalcourse'),
    get_string('col_quizzes', 'local_personalcourse'),
    get_string('col_enrolledcourses', 'local_personalcourse'),
    get_string('col_actions', 'local_personalcourse'),
];

$table->data = [];
$userids = array_map(function($u){ return (int)$u->userid; }, array_values($users));
$coursesbyuser = [];
if (!empty($userids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);
    $enrolsql = "
        SELECT ue.userid, c.id AS courseid, c.fullname, c.shortname
          FROM {user_enrolments} ue
          JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
          JOIN {course} c ON c.id = e.courseid
         WHERE ue.userid {$insql}
      ORDER BY c.fullname
    ";
    $enrolrows = $DB->get_records_sql($enrolsql, $inparams);
    foreach ($enrolrows as $row) {
        $uid = (int)$row->userid;
        if (!isset($coursesbyuser[$uid])) { $coursesbyuser[$uid] = []; }
        $coursesbyuser[$uid][] = (object)[
            'id' => (int)$row->courseid,
            'fullname' => $row->fullname,
            'shortname' => $row->shortname,
        ];
    }
    // Preload sections and quizzes for listed personal courses.
    $sectionsbycourse = [];
    $quizzesbycourse = [];
    $cmidbyquiz = [];
    $pcids = array_filter(array_map(function($u){ return (int)($u->personalcourseid ?? 0); }, array_values($users)), function($v){ return $v > 0; });
    if (!empty($pcids)) {
        list($inpc, $pcparams) = $DB->get_in_or_equal($pcids, SQL_PARAMS_QM);
        // Sections (named, non-empty, skip section 0), ordered by section number.
        $secsql = "SELECT id, course, section, name
                     FROM {course_sections}
                    WHERE course {$inpc} AND section > 0 AND name IS NOT NULL AND name <> ''
                 ORDER BY course, section";
        $secrows = $DB->get_records_sql($secsql, $pcparams);
        foreach ($secrows as $s) {
            $cid = (int)$s->course;
            if (!isset($sectionsbycourse[$cid])) { $sectionsbycourse[$cid] = []; }
            $sectionsbycourse[$cid][] = (string)$s->name;
        }
        // Quizzes and corresponding cmid.
        $quizrows = $DB->get_records_select('quiz', 'course '.$inpc, $pcparams, 'course, name', 'id, course, name');
        $quizids = [];
        foreach ($quizrows as $q) {
            $cid = (int)$q->course;
            if (!isset($quizzesbycourse[$cid])) { $quizzesbycourse[$cid] = []; }
            $quizzesbycourse[$cid][] = (object)['id' => (int)$q->id, 'name' => (string)$q->name];
            $quizids[] = (int)$q->id;
        }
        if (!empty($quizids)) {
            $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            list($inq, $qparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_QM);
            $cms = $DB->get_records_select('course_modules', 'module = ? AND instance '.$inq, array_merge([$moduleid], $qparams), '', 'id, instance');
            foreach ($cms as $cm) { $cmidbyquiz[(int)$cm->instance] = (int)$cm->id; }
        }
    }
}

if ($users) {
    foreach ($users as $u) {
        $username = fullname((object)['firstname' => $u->firstname, 'lastname' => $u->lastname]);
        $usercell = html_writer::span($username) . html_writer::empty_tag('br') . html_writer::span(s($u->email), 'text-muted');

        if (!empty($u->personalcourseid)) {
            $courselink = new moodle_url('/course/view.php', ['id' => $u->personalcourseid]);
            $coursecell = html_writer::link($courselink, format_string($u->personalcoursefullname)) .
                html_writer::empty_tag('br') .
                html_writer::span(format_string((string)$u->personalcourseshortname), 'text-muted');
            $renameurl = new moodle_url($PAGE->url, ['action' => 'rename', 'userid' => $u->userid, 'sesskey' => sesskey()]);
            $createquizurl = new moodle_url('/local/personalcourse/create_quiz.php', ['userid' => $u->userid]);
            $actions = html_writer::link($courselink, get_string('view_course', 'local_personalcourse')) . ' | ' .
                       html_writer::link($renameurl, get_string('action_rename', 'local_personalcourse')) . ' | ' .
                       html_writer::link($createquizurl, get_string('action_createquiz', 'local_personalcourse'));
        } else {
            $coursecell = html_writer::span(get_string('no_personalcourse', 'local_personalcourse'), 'text-muted');
            $forceurl = new moodle_url($PAGE->url, ['action' => 'forcecreate', 'userid' => $u->userid, 'sesskey' => sesskey()]);
            $createquizurl = new moodle_url('/local/personalcourse/create_quiz.php', ['userid' => $u->userid]);
            $actions = html_writer::link($forceurl, get_string('action_forcecreate', 'local_personalcourse'), ['class' => 'btn btn-primary']) . ' | ' .
                       html_writer::link($createquizurl, get_string('action_createquiz', 'local_personalcourse'));
        }

        $enrolledcount = isset($u->enrolledcount) ? (int)$u->enrolledcount : 0;
        // Build courses list cell with links (limit to first 6, then show +N more).
        $list = [];
        $usercourses = $coursesbyuser[$u->userid] ?? [];
        $maxshow = 6;
        for ($i = 0; $i < min(count($usercourses), $maxshow); $i++) {
            $cc = $usercourses[$i];
            $cl = new moodle_url('/course/view.php', ['id' => $cc->id]);
            $label = format_string($cc->shortname ?: $cc->fullname);
            $list[] = html_writer::link($cl, $label);
        }
        if (count($usercourses) > $maxshow) {
            $list[] = html_writer::span('+' . (count($usercourses) - $maxshow) . ' ' . get_string('more'), 'text-muted');
        }
        $coursescell = $list ? implode(', ', $list) : html_writer::span(get_string('no_enrolments', 'local_personalcourse'), 'text-muted');

        // Sections cell (limit 6)
        $secs = $sectionsbycourse[(int)($u->personalcourseid ?? 0)] ?? [];
        $secdisplay = [];
        for ($i = 0; $i < min(count($secs), 6); $i++) { $secdisplay[] = format_string($secs[$i]); }
        if (count($secs) > 6) { $secdisplay[] = '+' . (count($secs) - 6) . ' ' . get_string('more'); }
        $sectionscell = $secdisplay ? implode(', ', $secdisplay) : html_writer::span(get_string('no_sections', 'local_personalcourse'), 'text-muted');

        // Quizzes cell (limit 6) with links to quiz view (cmid)
        $qzs = $quizzesbycourse[(int)($u->personalcourseid ?? 0)] ?? [];
        $qdisplay = [];
        $maxq = 6;
        for ($i = 0; $i < min(count($qzs), $maxq); $i++) {
            $q = $qzs[$i];
            $cmid = $cmidbyquiz[$q->id] ?? 0;
            if ($cmid) {
                $qlink = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
                $qdisplay[] = html_writer::link($qlink, format_string($q->name));
            } else {
                $qdisplay[] = format_string($q->name);
            }
        }
        if (count($qzs) > $maxq) { $qdisplay[] = '+' . (count($qzs) - $maxq) . ' ' . get_string('more'); }
        $quizzescell = $qdisplay ? implode(', ', $qdisplay) : html_writer::span(get_string('no_quizzes', 'local_personalcourse'), 'text-muted');

        $table->data[] = [
            $usercell,
            (string)$u->userid,
            $coursecell,
            $coursescell,
            $sectionscell,
            $quizzescell,
            (string)$enrolledcount,
            $actions,
        ];
    }
} else {
    $table->data[] = [
        html_writer::span(get_string('no_records', 'local_personalcourse'), 'text-muted'),
        '', '', ''
    ];
}

// Simple search box.
$searchform = html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url->out(false)]) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => s($q), 'placeholder' => get_string('search')]) . ' ' .
    html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('search')]) .
    html_writer::end_tag('form');

echo html_writer::div($searchform, ['class' => 'pb-3']);
echo html_writer::table($table);

echo $OUTPUT->footer();
