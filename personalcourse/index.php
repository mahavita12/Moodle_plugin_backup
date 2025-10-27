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

// New default view: show attempts like Quiz Dashboard and allow admin actions.
$mode = optional_param('mode', 'attempts', PARAM_ALPHA);
if ($mode === 'attempts') {
    require_once($CFG->dirroot . '/local/quizdashboard/classes/quiz_manager.php');
    $qm = new \local_quizdashboard\quiz_manager();

    // Basic filters (optional): user id and course filter.
    $filteruserid = optional_param('userid', 0, PARAM_INT);
    $studentname = optional_param('studentname', '', PARAM_TEXT);
    $coursename = optional_param('coursename', '', PARAM_TEXT);
    $quizname = optional_param('quizname', '', PARAM_TEXT);
    $sectionid = optional_param('sectionid', 0, PARAM_INT);
    $status = optional_param('status', '', PARAM_TEXT);
    $quiztype = optional_param('quiztype', '', PARAM_TEXT); // '' = all.
    $sort = optional_param('sort', 'timefinish', PARAM_ALPHA);
    $dir = optional_param('dir', 'DESC', PARAM_ALPHA);

    $records = $qm->get_filtered_quiz_attempts(
        $filteruserid ?: '', $studentname, $coursename, $quizname, '', '', $quiztype, $sort, $dir, 0, 0, $status, $sectionid
    );

    // Build table similar to quiz dashboard.
    $table = new html_table();
    $table->head = [
        get_string('id', 'moodle'),
        get_string('fullnameuser'),
        get_string('course'),
        get_string('modulename', 'quiz'),
        get_string('attempt', 'quiz'),
        get_string('status'),
        get_string('finished', 'quiz'),
        get_string('duration', 'quiz'),
        get_string('score', 'quiz'),
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

            // Duration display.
            $time_taken = '-';
            if (!empty($r->timestart) && !empty($r->timefinish)) {
                $seconds = max(0, (int)$r->timefinish - (int)$r->timestart);
                $h = intdiv($seconds, 3600); $m = intdiv($seconds % 3600, 60); $s = $seconds % 60;
                $time_taken = $h > 0 ? sprintf('%dh %dm %ds', $h, $m, $s) : ($m > 0 ? sprintf('%dm %ds', $m, $s) : sprintf('%ds', $s));
            }

            // Score.
            $scorecell = '-';
            if (isset($r->score) && isset($r->maxscore)) {
                $scorecell = round((float)$r->score) . ' / ' . round((float)$r->maxscore);
            }

            // Actions: create personal course (if missing) and create personal quiz from this attempt.
            $haspc = $DB->record_exists('local_personalcourse_courses', ['userid' => $useridrow]);
            $actions = [];
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
            $actionscell = $actions ? implode(' | ', $actions) : '';

            $table->data[] = [
                (string)$useridrow,
                html_writer::link($userprofile, s((string)$r->studentname)),
                html_writer::link($courselink, s((string)$r->coursename)),
                html_writer::link($reviewurl, s((string)$r->quizname)),
                html_writer::link($reviewurl, (string)$r->attemptnumber),
                s((string)$r->status),
                !empty($r->timefinish) ? userdate($r->timefinish, '%Y-%m-%d %H:%M') : '-',
                $time_taken,
                $scorecell,
                $actionscell,
            ];
        }
    } else {
        $table->data[] = [html_writer::span(get_string('no_records', 'local_personalcourse'), 'text-muted')];
    }

    echo html_writer::table($table);
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
