<?php
namespace local_homeworkdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Homework manager: computes per-user homework status for quizzes.
 */
class homework_manager {

    /** Default window in days if nothing configured. */
    private const DEFAULT_WINDOW_DAYS = 7;

    /** Minimum percentage to treat an attempt as completed (for green tick). */
    private const COMPLETION_PERCENT_THRESHOLD = 30.0;

    /**
     * Get effective homework window (days) for a course.
     * Tries course custom field 'homework_window_days', then plugin config, then default.
     */
    public function get_course_window_days(int $courseid): int {
        global $DB;

        $days = self::DEFAULT_WINDOW_DAYS;

        // Plugin config fallback (optional, safe if not set).
        $config = get_config('local_homeworkdashboard');
        if (!empty($config->defaultwindowdays)) {
            $days = max(1, (int)$config->defaultwindowdays);
        }

        // Course custom field override if available.
        try {
            $field = $DB->get_record('customfield_field', ['shortname' => 'homework_window_days'], 'id', \IGNORE_MISSING);
            if (!$field) {
                return $days;
            }
            $data = $DB->get_record('customfield_data', ['fieldid' => $field->id, 'instanceid' => $courseid], 'value', IGNORE_MISSING);
            if (!$data || $data->value === null || $data->value === '') {
                return $days;
            }
            $val = (int)$data->value;
            return ($val > 0) ? $val : $days;
        } catch (\Throwable $e) {
            return $days;
        }
    }

    /**
     * Get homework sessions (quizzes with close dates) for a course, with aggregated badge counts.
     *
     * @param int $courseid
     * @param string $classificationfilter 'New'|'Revision'|''
     * @param string $quiztypefilter 'Essay'|'Non-Essay'|''
     * @return array list of stdClass rows
     */
    public function get_sessions_for_course(int $courseid, string $classificationfilter = '', string $quiztypefilter = ''): array {
        global $DB;
        if ($courseid <= 0) {
            return [];
        }

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
        if (!$moduleid) {
            return [];
        }

        // Quizzes must have a close time; quizzes without timeclose are ignored per requirements.
        $sql = "SELECT cm.id AS cmid, q.id AS quizid, q.name, q.course, q.timeclose, q.grade
                  FROM {course_modules} cm
                  JOIN {quiz} q ON q.id = cm.instance
                 WHERE cm.course = :courseid
                   AND cm.module = :moduleid
                   AND q.timeclose IS NOT NULL
                   AND q.timeclose > 0
              ORDER BY q.timeclose ASC";
        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'moduleid' => $moduleid]);
        if (empty($records)) {
            return [];
        }

        $windowdays = $this->get_course_window_days($courseid);
        $sessions = [];

        foreach ($records as $r) {
            $timeclose = (int)$r->timeclose;
            if ($timeclose <= 0) {
                continue;
            }

            // Classification and quiz type.
            $classification = $this->get_activity_classification((int)$r->cmid) ?? '';
            $quiztype = $this->quiz_has_essay((int)$r->quizid) ? 'Essay' : 'Non-Essay';

            if ($classificationfilter !== '' && strcasecmp($classificationfilter, $classification) !== 0) {
                continue;
            }
            if ($quiztypefilter !== '' && strcasecmp($quiztypefilter, $quiztype) !== 0) {
                continue;
            }

            [$windowstart, $windowend] = $this->build_window($timeclose, $windowdays);

            $counts = $this->summarise_status_counts(
                $courseid,
                (int)$r->quizid,
                $windowstart,
                $windowend,
                (float)$r->grade
            );
            if ($counts === null) {
                // No enrolled users; skip.
                continue;
            }

            $sessions[] = (object) [
                'cmid'          => (int)$r->cmid,
                'quizid'        => (int)$r->quizid,
                'quizname'      => $r->name,
                'courseid'      => $courseid,
                'timeclose'     => $timeclose,
                'windowdays'    => $windowdays,
                'classification'=> $classification,
                'quiztype'      => $quiztype,
                'completed'     => $counts['completed'] ?? 0,
                'lowgrade'      => $counts['lowgrade'] ?? 0,
                'noattempt'     => $counts['noattempt'] ?? 0,
            ];
        }

        return $sessions;
    }

    /**
     * Get list of user IDs who have staff roles in the course context or are site admins.
     * Staff roles: manager, editingteacher, teacher.
     */
    private function get_staff_users_for_course(int $courseid): array {
        global $DB;

        // 1. Site admins.
        $admins = \get_admins();
        $staffids = array_map(function($u) { return (int)$u->id; }, $admins);

        // 2. Course context roles.
        $context = \context_course::instance($courseid, \IGNORE_MISSING);
        if (!$context) {
            return $staffids;
        }

        $staffroles = $DB->get_records_sql("SELECT id FROM {role} WHERE shortname IN ('manager', 'editingteacher', 'teacher')");
        if (empty($staffroles)) {
            return $staffids;
        }
        $roleids = array_keys($staffroles);

        // Get users with these roles in this context.
        list($rsql, $rparams) = $DB->get_in_or_equal($roleids, \SQL_PARAMS_NAMED, 'r');
        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                 WHERE ra.contextid = :contextid
                   AND ra.roleid $rsql";
        $rparams['contextid'] = $context->id;
        
        $course_staff = $DB->get_fieldset_sql($sql, $rparams);
        $course_staff = array_map('intval', $course_staff);

        $allstaff = array_merge($staffids, $course_staff);
        return array_unique($allstaff);
    }

    /**
     * Build [start, end] timestamps for the homework window.
     */
    private function build_window(int $timeclose, int $windowdays): array {
        $days = max(1, $windowdays);
        $end = $timeclose;
        $start = $timeclose - ($days * 24 * 60 * 60) + 60;
        return [$start, $end];
    }

    /**
     * Summarise badge-status counts for all enrolled users in a course for a quiz.
     *
     * @return array|null ['completed' => n, 'lowgrade' => n, 'noattempt' => n] or null if no enrolled users
     */
    private function summarise_status_counts(int $courseid, int $quizid, int $windowstart, int $windowend, float $quizgrade): ?array {
        global $DB;

        $roster = $this->get_course_roster($courseid);
        if (empty($roster)) {
            return null;
        }

        list($insql, $params) = $DB->get_in_or_equal($roster, \SQL_PARAMS_NAMED, 'uid');
        $params['quizid'] = $quizid;
        $params['start'] = $windowstart;
        $params['end'] = $windowend;

        $sql = "SELECT qa.userid, qa.sumgrades, qa.timestart, qa.timefinish
                  FROM {quiz_attempts} qa
                 WHERE qa.quiz = :quizid
                   AND qa.state = 'finished'
                   AND qa.userid $insql
                   AND qa.timefinish BETWEEN :start AND :end";

        $attempts = $DB->get_records_sql($sql, $params);

        // Per-user aggregate.
        $peruser = [];
        $grade = ($quizgrade > 0.0) ? $quizgrade : 0.0;

        foreach ($attempts as $row) {
            $timestart = (int)$row->timestart;
            $timefinish = (int)$row->timefinish;
            if ($timestart <= 0 || $timefinish <= $timestart) {
                continue;
            }
            $duration = $timefinish - $timestart;
            if ($duration < 180) {
                // Treat attempts shorter than 3 minutes as non-attempts for status.
                continue;
            }

            $uid = (int)$row->userid;
            if (!isset($peruser[$uid])) {
                $peruser[$uid] = ['attempts' => 0, 'bestpercent' => 0.0];
            }
            $peruser[$uid]['attempts']++;
            if ($grade > 0.0 && $row->sumgrades !== null) {
                $pct = ((float)$row->sumgrades / $grade) * 100.0;
                if ($pct > $peruser[$uid]['bestpercent']) {
                    $peruser[$uid]['bestpercent'] = $pct;
                }
            }
        }

        $counts = ['completed' => 0, 'lowgrade' => 0, 'noattempt' => 0];

        foreach ($roster as $uid) {
            if (!isset($peruser[$uid])) {
                $counts['noattempt']++;
                continue;
            }
            $best = $peruser[$uid]['bestpercent'];
            if ($best >= self::COMPLETION_PERCENT_THRESHOLD) {
                $counts['completed']++;
            } else {
                $counts['lowgrade']++;
            }
        }

        return $counts;
    }

    /**
     * Get IDs of enrolled users in a course (students + others), excluding deleted/suspended.
     */
    private function get_course_roster(int $courseid): array {
        global $DB;

        $sql = "SELECT DISTINCT u.id
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                 WHERE u.deleted = 0 AND u.suspended = 0";
        $ids = $DB->get_fieldset_sql($sql, ['courseid' => $courseid]);
        return array_map('intval', $ids);
    }

    /**
     * Get activity classification (New / Revision) for a course module.
     */
    private function get_activity_classification(int $cmid): string {
        global $DB;

        try {
            if (!$DB->get_manager()->table_exists('customfield_field')) {
                return '';
            }
            if (!$DB->get_manager()->table_exists('customfield_category')) {
                return '';
            }
            if (!$DB->get_manager()->table_exists('customfield_data')) {
                return '';
            }

            $sql = "SELECT f.id
                      FROM {customfield_field} f
                      JOIN {customfield_category} c ON c.id = f.categoryid
                     WHERE c.component IN ('core_course', 'mod_quiz')
                       AND c.area = 'course_modules'
                       AND f.shortname = :s1
                  ORDER BY f.id ASC";
            $field = $DB->get_record_sql($sql, ['s1' => 'activity_tag']);
            if (!$field) {
                return '';
            }

            $data = $DB->get_record('customfield_data', ['fieldid' => $field->id, 'instanceid' => $cmid], 'value', IGNORE_MISSING);
            if (!$data || $data->value === null) {
                return '';
            }
            return (string)trim($data->value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * True if the quiz contains at least one essay question (schema-agnostic).
     */
    private function quiz_has_essay(int $quizid): bool {
        global $DB;
        $slotscols = $DB->get_columns('quiz_slots');

        if (isset($slotscols['questionid'])) {
            // Moodle 3.x direct link.
            $sql = "SELECT 1
                      FROM {quiz_slots} qs
                      JOIN {question} q ON q.id = qs.questionid
                     WHERE qs.quizid = ? AND q.qtype = 'essay'";
            return $DB->record_exists_sql($sql, [$quizid]);
        }

        // Moodle 4.x question bank path.
        $sql = "SELECT 1
                  FROM {quiz_slots} qs
                  JOIN {question_references} qr
                    ON qr.itemid = qs.id
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN (
                        SELECT questionbankentryid, MAX(version) AS maxver
                          FROM {question_versions}
                      GROUP BY questionbankentryid
                       ) vmax ON vmax.questionbankentryid = qbe.id
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.version = vmax.maxver
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qs.quizid = ? AND q.qtype = 'essay'";
        return $DB->record_exists_sql($sql, [$quizid]);
    }

    /**
     * Build Sunday-based week boundaries for a filter value.
     *
     * @param string|null $weekvalue ISO date string 'Y-m-d' for the Sunday in that week, or ''/null for all weeks.
     * @return array [int $weekstart, int $weekend] or [0, 0] if no filter.
     */
    private function get_week_bounds(?string $weekvalue): array {
        if (empty($weekvalue)) {
            return [0, 0];
        }
        $ts = strtotime($weekvalue . ' 23:59:59');
        if ($ts === false) {
            return [0, 0];
        }

        // Treat the given Sunday as the end of the week (7-day window).
        $weekend = $ts;
        $weekstart = $weekend - (6 * 24 * 60 * 60);
        $weekstart = strtotime('midnight', $weekstart);
        return [$weekstart, $weekend];
    }

    private function get_latest_sunday_week(): array {
        $now = time();
        if ((int)date('w', $now) === 0) {
            // If today is Sunday, use today as the week end.
            $sundaydate = date('Y-m-d', $now);
        } else {
            // Otherwise, use the upcoming Sunday as the week end.
            $sundayts = strtotime('next sunday', $now);
            if ($sundayts === false) {
                return [0, 0];
            }
            $sundaydate = date('Y-m-d', $sundayts);
        }

        return $this->get_week_bounds($sundaydate);
    }

    /**
     * Build snapshot-backed rows for a single quiz + close time, if any snapshots exist.
     */
    private function build_snapshot_rows_for_quiz(
        int $quizid,
        int $timeclose,
        int $categoryid,
        int $courseid,
        int $sectionid,
        int $userid,
        string $studentname,
        string $statusfilter,
        string $classificationfilter,
        string $quiztypefilter,
        array $excludeduserids = []
    ): array {
        global $DB;

        $params = [
            'quizid'    => $quizid,
            'timeclose' => $timeclose,
        ];

        $sql = "SELECT
                    s.id,
                    s.userid,
                    s.courseid,
                    s.cmid,
                    s.quizid,
                    s.timeclose,
                    s.windowdays,
                    s.windowstart,
                    s.attempts,
                    s.bestpercent,
                    s.firstfinish,
                    s.lastfinish,
                    s.status,
                    s.classification,
                    s.quiztype,
                    s.computedat,
                    q.name      AS quizname,
                    q.grade,
                    c.fullname  AS coursename,
                    cat.id      AS categoryid,
                    cat.name    AS categoryname,
                    cm.id       AS cmid_real,
                    cs.id       AS sectionid,
                    cs.name     AS sectionname,
                    cs.section  AS sectionnumber
                FROM {local_homework_status} s
                JOIN {quiz} q ON q.id = s.quizid
                JOIN {course} c ON c.id = s.courseid
                JOIN {course_categories} cat ON cat.id = c.category
                JOIN {course_modules} cm ON cm.id = s.cmid
                JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                JOIN {course_sections} cs ON cs.id = cm.section
               WHERE s.quizid = :quizid AND s.timeclose = :timeclose";

        if ($categoryid > 0) {
            $sql .= " AND c.category = :categoryid";
            $params['categoryid'] = $categoryid;
        }
        if ($courseid > 0) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }
        if ($sectionid > 0) {
            $sql .= " AND cs.id = :sectionid";
            $params['sectionid'] = $sectionid;
        }
        if ($userid > 0) {
            $sql .= " AND s.userid = :userid";
            $params['userid'] = $userid;
        }
        if ($classificationfilter !== '') {
            $sql .= " AND s.classification = :classification";
            $params['classification'] = $classificationfilter;
        }
        if ($quiztypefilter !== '') {
            $sql .= " AND s.quiztype = :quiztype";
            $params['quiztype'] = $quiztypefilter;
        }

        $sql .= " ORDER BY c.fullname, q.name";

        $snapshots = $DB->get_records_sql($sql, $params);
        if (empty($snapshots)) {
            return [];
        }

        $userids = [];
        foreach ($snapshots as $s) {
            $uid = (int)$s->userid;
            if (!empty($excludeduserids) && in_array($uid, $excludeduserids)) {
                continue;
            }
            $userids[] = $uid;
        }
        $userids = array_values(array_unique($userids));

        if (!empty($userids)) {
            list($userinsql, $userparams) = $DB->get_in_or_equal($userids, \SQL_PARAMS_NAMED, 'u');
            $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $userinsql", $userparams);
        } else {
            $userrecs = [];
        }

        $rows = [];

        foreach ($snapshots as $s) {
            $uid = (int)$s->userid;
            if (!empty($excludeduserids) && in_array($uid, $excludeduserids)) {
                continue;
            }
            $userdata = $userrecs[$uid] ?? null;
            $fullname = $userdata ? ($userdata->firstname . ' ' . $userdata->lastname) : '';

            if ($studentname !== '' && $fullname !== $studentname) {
                continue;
            }

            $snapstatus = (string)$s->status;
            if ($snapstatus === 'completed') {
                $hwstatus = 'Completed';
            } else if ($snapstatus === 'lowgrade') {
                $hwstatus = 'Low grade';
            } else {
                $hwstatus = 'No attempt';
            }

            if ($statusfilter !== '' && strcasecmp($statusfilter, $hwstatus) !== 0) {
                continue;
            }

            $timefinish = $s->lastfinish ? (int)$s->lastfinish : 0;

            $maxscore = ($s->grade > 0.0) ? (float)$s->grade : 0.0;
            $bestpercent = (float)$s->bestpercent;
            $bestscore = 0.0;
            if ($maxscore > 0.0 && $bestpercent > 0.0) {
                $bestscore = round(($bestpercent / 100.0) * $maxscore, 2);
            }

            $rows[] = (object) [
                'userid'       => $uid,
                'studentname'  => $fullname,
                'courseid'     => (int)$s->courseid,
                'coursename'   => $s->coursename,
                'categoryid'   => (int)$s->categoryid,
                'categoryname' => $s->categoryname,
                'sectionid'    => (int)$s->sectionid,
                'sectionname'  => $s->sectionname,
                'sectionnumber'=> $s->sectionnumber,
                'quizid'       => (int)$s->quizid,
                'quizname'     => $s->quizname,
                'cmid'         => (int)$s->cmid_real,
                'classification'=> $s->classification ?? '',
                'lastattemptid'=> 0,
                'attemptno'    => (int)$s->attempts,
                'status'       => $hwstatus,
                'timestart'    => 0,
                'timefinish'   => $timefinish,
                'time_taken'   => '',
                'score'        => $bestscore,
                'maxscore'     => $maxscore,
                'percentage'   => $bestpercent,
                'quiz_type'    => $s->quiztype ?? '',
                'timeclose'    => (int)$s->timeclose,
            ];
        }

        return $rows;
    }

    /**
     * Get LIVE homework rows (quizzes with future close dates), calculated on-the-fly.
     */
    public function get_live_homework_rows(
        int $categoryid,
        int $courseid,
        int $sectionid,
        int $quizid,
        int $userid,
        string $studentname,
        string $quiztypefilter,
        string $statusfilter,
        string $classificationfilter,
        ?string $weekvalue,
        string $sort,
        string $dir,
        bool $excludestaff = false,
        int $duedate = 0
    ): array {
        global $DB;

        $rows = [];
        $now = time();

        // Resolve filters to SQL
        $params = [];
        $sql = "SELECT
                    q.id       AS quizid,
                    q.name     AS quizname,
                    q.course   AS courseid,
                    COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) AS timeclose,
                    q.grade,
                    c.fullname AS coursename,
                    cat.id     AS categoryid,
                    cat.name   AS categoryname,
                    cm.id      AS cmid,
                    cs.id      AS sectionid,
                    cs.name    AS sectionname,
                    cs.section AS sectionnumber
                FROM {quiz} q
                JOIN {course} c ON c.id = q.course
                JOIN {course_categories} cat ON cat.id = c.category
                JOIN {course_modules} cm ON cm.instance = q.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                JOIN {course_sections} cs ON cs.id = cm.section
                LEFT JOIN (
                    SELECT instance AS quizid, MAX(timestart) AS eventclose
                      FROM {event}
                     WHERE modulename = 'quiz' AND eventtype = 'close'
                  GROUP BY instance
                ) ev ON ev.quizid = q.id
                WHERE ((q.timeclose IS NOT NULL AND q.timeclose > 0)
                   OR ((q.timeclose IS NULL OR q.timeclose = 0) AND ev.eventclose IS NOT NULL))";
        
        // Filter for LIVE quizzes (close time > now)
        $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) > :now";
        $params['now'] = $now;

        if ($categoryid > 0) {
            $sql .= " AND c.category = :categoryid";
            $params['categoryid'] = $categoryid;
        }
        if ($courseid > 0) {
            $sql .= " AND c.id = :courseid";
            $params['courseid'] = $courseid;
        }
        if ($sectionid > 0) {
            $sql .= " AND cs.id = :sectionid";
            $params['sectionid'] = $sectionid;
        }
        if ($quizid > 0) {
            $sql .= " AND q.id = :quizid";
            $params['quizid'] = $quizid;
        }
        if ($duedate > 0) {
            $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) = :duedate";
            $params['duedate'] = $duedate;
        } else {
            [$weekstart, $weekend] = $this->get_week_bounds($weekvalue);
            if ($weekstart > 0 && $weekend > 0) {
                $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) BETWEEN :weekstart AND :weekend";
                $params['weekstart'] = $weekstart;
                $params['weekend'] = $weekend;
            }
        }

        $sql .= " ORDER BY c.fullname, q.name";

        $quizrecords = $DB->get_records_sql($sql, $params);
        if (empty($quizrecords)) {
            return [];
        }

        foreach ($quizrecords as $qrec) {
            $qtimeclose = (int)$qrec->timeclose;
            if ($qtimeclose <= 0) {
                continue;
            }

            // Activity classification
            $classification = $this->get_activity_classification((int)$qrec->cmid);
            if ($classificationfilter !== '' && strcasecmp($classificationfilter, $classification) !== 0) {
                continue;
            }

            // Quiz type
            $quiztype = $this->quiz_has_essay((int)$qrec->quizid) ? 'Essay' : 'Non-Essay';
            if ($quiztypefilter !== '' && strcasecmp($quiztypefilter, $quiztype) !== 0) {
                continue;
            }

            // Determine excluded staff
            $excludeduserids = [];
            if ($excludestaff) {
                $excludeduserids = $this->get_staff_users_for_course((int)$qrec->courseid);
            }

            // Calculate Live Window
            $windowdays = $this->get_course_window_days((int)$qrec->courseid);
            [$windowstart, $windowend] = $this->build_window($qtimeclose, $windowdays);

            $roster = $this->get_course_roster((int)$qrec->courseid);
            if (empty($roster)) {
                continue;
            }

            if (!empty($excludeduserids)) {
                $roster = array_diff($roster, $excludeduserids);
                if (empty($roster)) {
                    continue;
                }
                $roster = array_values($roster);
            }

            if ($userid > 0) {
                $roster = array_values(array_intersect($roster, [$userid]));
                if (empty($roster)) {
                    continue;
                }
            }

            list($insql, $inparams) = $DB->get_in_or_equal($roster, \SQL_PARAMS_NAMED, 'uid');
            $apparams = $inparams;
            $apparams['quizid'] = (int)$qrec->quizid;
            $apparams['start'] = $windowstart;
            $apparams['end'] = $windowend;

            $attemptsql = "SELECT qa.id, qa.userid, qa.attempt, qa.timestart, qa.timefinish, qa.sumgrades
                             FROM {quiz_attempts} qa
                            WHERE qa.quiz = :quizid
                              AND qa.state = 'finished'
                              AND qa.userid $insql
                              AND qa.timefinish BETWEEN :start AND :end";
            $attempts = $DB->get_records_sql($attemptsql, $apparams);

            $peruser = [];
            $grade = ($qrec->grade > 0.0) ? (float)$qrec->grade : 0.0;

            foreach ($attempts as $a) {
                $timestart = (int)$a->timestart;
                $timefinish = (int)$a->timefinish;
                if ($timestart <= 0 || $timefinish <= $timestart) {
                    continue;
                }
                $duration = $timefinish - $timestart;
                if ($duration < 180) {
                    continue;
                }

                $uid = (int)$a->userid;
                if (!isset($peruser[$uid])) {
                    $peruser[$uid] = [
                        'attempts' => [],
                        'bestpercent' => 0.0,
                        'best' => null,
                    ];
                }
                $peruser[$uid]['attempts'][] = $a;

                if ($grade > 0.0 && $a->sumgrades !== null) {
                    $pct = ((float)$a->sumgrades / $grade) * 100.0;
                    if ($pct > $peruser[$uid]['bestpercent']) {
                        $peruser[$uid]['bestpercent'] = $pct;
                        $peruser[$uid]['best'] = $a;
                    }
                }
            }

            // Roster names
            list($userinsql, $userparams) = $DB->get_in_or_equal($roster, \SQL_PARAMS_NAMED, 'u');
            $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $userinsql", $userparams);

            foreach ($roster as $uid) {
                $uid = (int)$uid;
                $userdata = $userrecs[$uid] ?? null;
                $fullname = $userdata ? ($userdata->firstname . ' ' . $userdata->lastname) : '';

                if ($studentname !== '' && $fullname !== $studentname) {
                    continue;
                }

                $summary = $peruser[$uid] ?? ['attempts' => [], 'bestpercent' => 0.0, 'best' => null];
                $best = $summary['bestpercent'];
                $bestattempt = $summary['best'];

                if (empty($summary['attempts'])) {
                    $hwstatus = 'No attempt';
                } else if ($best >= self::COMPLETION_PERCENT_THRESHOLD) {
                    $hwstatus = 'Completed';
                } else {
                    $hwstatus = 'Low grade';
                }

                if ($statusfilter !== '' && strcasecmp($statusfilter, $hwstatus) !== 0) {
                    continue;
                }

                $timestart = $bestattempt ? (int)$bestattempt->timestart : 0;
                $timefinish = $bestattempt ? (int)$bestattempt->timefinish : 0;
                $time_taken = '';
                if ($timestart > 0 && $timefinish > 0 && $timefinish > $timestart) {
                    $duration = $timefinish - $timestart;
                    $hours = (int)floor($duration / 3600);
                    $minutes = (int)floor(($duration % 3600) / 60);
                    $seconds = (int)($duration % 60);
                    if ($hours > 0) {
                        $time_taken = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
                    } else if ($minutes > 0) {
                        $time_taken = sprintf('%dm %ds', $minutes, $seconds);
                    } else {
                        $time_taken = sprintf('%ds', $seconds);
                    }
                }

                $lastscore = $bestattempt && $bestattempt->sumgrades !== null ? (float)$bestattempt->sumgrades : 0.0;

                $rows[] = (object) [
                    'userid'       => $uid,
                    'studentname'  => $fullname,
                    'courseid'     => (int)$qrec->courseid,
                    'coursename'   => $qrec->coursename,
                    'categoryid'   => (int)$qrec->categoryid,
                    'categoryname' => $qrec->categoryname,
                    'sectionid'    => (int)$qrec->sectionid,
                    'sectionname'  => $qrec->sectionname,
                    'sectionnumber'=> $qrec->sectionnumber,
                    'quizid'       => (int)$qrec->quizid,
                    'quizname'     => $qrec->quizname,
                    'cmid'         => (int)$qrec->cmid,
                    'classification'=> $classification,
                    'lastattemptid'=> $bestattempt ? (int)$bestattempt->id : 0,
                    'attemptno'    => $bestattempt ? (int)$bestattempt->attempt : 0,
                    'status'       => $hwstatus,
                    'timestart'    => $timestart,
                    'timefinish'   => $timefinish,
                    'time_taken'   => $time_taken,
                    'score'        => $lastscore,
                    'maxscore'     => $grade,
                    'percentage'   => ($grade > 0.0 && $lastscore > 0.0) ? round(($lastscore / $grade) * 100.0, 2) : 0.0,
                    'quiz_type'    => $quiztype,
                    'timeclose'    => $qtimeclose,
                ];
            }
        }

        return $this->sort_rows($rows, $sort, $dir);
    }

    /**
     * Get SNAPSHOT homework rows (historical data from local_homework_status).
     */
    public function get_snapshot_homework_rows(
        int $categoryid,
        int $courseid,
        int $sectionid,
        int $quizid,
        int $userid,
        string $studentname,
        string $quiztypefilter,
        string $statusfilter,
        string $classificationfilter,
        ?string $weekvalue,
        string $sort,
        string $dir,
        bool $excludestaff = false,
        int $duedate = 0
    ): array {
        global $DB;

        $rows = [];
        $now = time();

        $snapparams = [];
        $snapsql = "SELECT DISTINCT s.quizid, s.timeclose
                      FROM {local_homework_status} s
                      JOIN {course} c ON c.id = s.courseid
                      JOIN {course_categories} cat ON cat.id = c.category
                      JOIN {course_modules} cm ON cm.id = s.cmid
                      JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                      JOIN {course_sections} cs ON cs.id = cm.section
                     WHERE 1 = 1"; // Historical implies timeclose <= now, but we show all stored snapshots.

        if ($categoryid > 0) {
            $snapsql .= " AND c.category = :scategoryid";
            $snapparams['scategoryid'] = $categoryid;
        }
        if ($courseid > 0) {
            $snapsql .= " AND c.id = :scourseid";
            $snapparams['scourseid'] = $courseid;
        }
        if ($sectionid > 0) {
            $snapsql .= " AND cs.id = :ssectionid";
            $snapparams['ssectionid'] = $sectionid;
        }
        if ($quizid > 0) {
            $snapsql .= " AND s.quizid = :squizid";
            $snapparams['squizid'] = $quizid;
        }
        if ($duedate > 0) {
            $snapsql .= " AND s.timeclose = :sduedate";
            $snapparams['sduedate'] = $duedate;
        } else {
             [$weekstart, $weekend] = $this->get_week_bounds($weekvalue);
             if ($weekstart > 0 && $weekend > 0) {
                $snapsql .= " AND s.timeclose BETWEEN :sweekstart AND :sweekend";
                $snapparams['sweekstart'] = $weekstart;
                $snapparams['sweekend'] = $weekend;
            }
        }

        $snapsql .= " ORDER BY s.timeclose ASC";

        $snapshotrecords = $DB->get_records_sql($snapsql, $snapparams);

        if (!empty($snapshotrecords)) {
            // Fetch excluded staff if needed.
            $staff_cache = [];

            foreach ($snapshotrecords as $srec) {
                $stimeclose = (int)$srec->timeclose;
                if ($stimeclose <= 0) {
                    continue;
                }

                // Find courseid from snapshot record (need to query or assume from join).
                // The query above selects DISTINCT quizid, timeclose. It doesn't select courseid.
                // I need courseid to get staff.
                $cid = $DB->get_field('quiz', 'course', ['id' => $srec->quizid], IGNORE_MISSING);
                if (!$cid) {
                     // Try local_homework_status
                     $cid = $DB->get_field('local_homework_status', 'courseid', ['quizid' => $srec->quizid, 'timeclose' => $stimeclose], IGNORE_MULTIPLE);
                }
                if (!$cid) continue;

                $excludeduserids = [];
                if ($excludestaff) {
                    if (!isset($staff_cache[$cid])) {
                        $staff_cache[$cid] = $this->get_staff_users_for_course((int)$cid);
                    }
                    $excludeduserids = $staff_cache[$cid];
                }

                $snaprows = $this->build_snapshot_rows_for_quiz(
                    (int)$srec->quizid,
                    $stimeclose,
                    $categoryid,
                    (int)$cid,
                    $sectionid,
                    $userid,
                    $studentname,
                    $statusfilter,
                    $classificationfilter,
                    $quiztypefilter,
                    $excludeduserids
                );

                foreach ($snaprows as $sr) {
                    $rows[] = $sr;
                }
            }
        }

        return $this->sort_rows($rows, $sort, $dir);
    }

    /**
     * Get homework status string (Completed, Low grade, No attempt) for a specific user/quiz event.
     * Used by external blocks/plugins.
     */
    public function get_homework_status_for_user_quiz_event(int $userid, int $quizid, int $courseid, int $timeclose): ?string {
        global $DB;

        if ($userid <= 0 || $quizid <= 0 || $courseid <= 0 || $timeclose <= 0) {
            return null;
        }

        // 1. Check snapshot first
        $snap = $DB->get_record('local_homework_status', [
            'userid' => $userid,
            'quizid' => $quizid,
            'timeclose' => $timeclose,
        ], 'status', IGNORE_MISSING);

        if ($snap && !empty($snap->status)) {
            $code = (string)$snap->status;
            if ($code === 'completed') {
                return 'Completed';
            } else if ($code === 'lowgrade') {
                return 'Low grade';
            } else if ($code === 'noattempt') {
                return 'No attempt';
            }
        }

        // 2. Fallback to live calculation
        $windowdays = $this->get_course_window_days($courseid);
        [$windowstart, $windowend] = $this->build_window($timeclose, $windowdays);

        $params = [
            'quizid' => $quizid,
            'userid' => $userid,
            'start' => $windowstart,
            'end' => $windowend
        ];

        $sql = "SELECT qa.sumgrades, qa.timestart, qa.timefinish
                  FROM {quiz_attempts} qa
                 WHERE qa.quiz = :quizid
                   AND qa.userid = :userid
                   AND qa.state = 'finished'
                   AND qa.timefinish BETWEEN :start AND :end";

        $attempts = $DB->get_records_sql($sql, $params);

        $attempts_count = 0;
        $bestpercent = 0.0;
        
        $quizgrade = $DB->get_field('quiz', 'grade', ['id' => $quizid]);
        $grade = ($quizgrade > 0.0) ? (float)$quizgrade : 0.0;

        foreach ($attempts as $a) {
            $timestart = (int)$a->timestart;
            $timefinish = (int)$a->timefinish;
            if ($timestart <= 0 || $timefinish <= $timestart) {
                continue;
            }
            $duration = $timefinish - $timestart;
            if ($duration < 180) {
                continue;
            }

            $attempts_count++;
            if ($grade > 0.0 && $a->sumgrades !== null) {
                $pct = ((float)$a->sumgrades / $grade) * 100.0;
                if ($pct > $bestpercent) {
                    $bestpercent = $pct;
                }
            }
        }

        if ($attempts_count === 0) {
            return 'No attempt';
        } else if ($bestpercent >= self::COMPLETION_PERCENT_THRESHOLD) {
            return 'Completed';
        } else {
            return 'Low grade';
        }
    }

    private function sort_rows(array $rows, string $sort, string $dir): array {
        $sortkey = $sort ?: 'timefinish';
        $direction = (strtoupper($dir) === 'ASC') ? 1 : -1;

        usort($rows, function($a, $b) use ($sortkey, $direction) {
            $map = [
                'userid'       => 'userid',
                'studentname'  => 'studentname',
                'categoryname' => 'categoryname',
                'coursename'   => 'coursename',
                'quizname'     => 'quizname',
                'attemptno'    => 'attemptno',
                'classification'=> 'classification',
                'quiz_type'    => 'quiz_type',
                'status'       => 'status',
                'timeclose'    => 'timeclose',
                'timefinish'   => 'timefinish',
                'time_taken'   => 'time_taken',
                'score'        => 'percentage',
            ];
            $field = $map[$sortkey] ?? 'timefinish';
            $va = $a->$field ?? null;
            $vb = $b->$field ?? null;
            if ($va == $vb) {
                return 0;
            }
            return ($va < $vb ? -1 : 1) * $direction;
        });

        return $rows;
    }

    /**
     * Backfill snapshots for specific due dates (quiz close times).
     * Overwrites existing snapshots for these dates.
     */
    public function backfill_snapshots_from_dates(array $timestamps): int {
        global $DB;

        if (empty($timestamps)) {
            return 0;
        }

        $inserted = 0;
        $now = time();
        $timestamps = array_map('intval', $timestamps);
        $timestamps = array_unique($timestamps);

        foreach ($timestamps as $ts) {
            if ($ts <= 0) continue;

            // 1. Try to find a quiz that CURRENTLY closes at this time.
            $quizzes = $DB->get_records('quiz', ['timeclose' => $ts]);

            if (empty($quizzes)) {
                // 2. If not found (quiz date changed), look for historical records in local_homework_status
                // to identify which quiz it was.
                $sql = "SELECT DISTINCT quizid FROM {local_homework_status} WHERE timeclose = :ts";
                $historical_quizids = $DB->get_fieldset_sql($sql, ['ts' => $ts]);

                if (!empty($historical_quizids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($historical_quizids);
                    $quizzes = $DB->get_records_select('quiz', "id $insql", $inparams);
                }
            }

            if (empty($quizzes)) {
                continue;
            }

            foreach ($quizzes as $quiz) {
                $quizid = (int)$quiz->id;
                $courseid = (int)$quiz->course;
                $cm = get_coursemodule_from_instance('quiz', $quizid, $courseid);
                if (!$cm) continue;

                // Use the HISTORICAL timeclose ($ts), not the quiz's current timeclose.
                $timeclose = $ts; 

                // Clear existing snapshots for this specific historical date
                $DB->delete_records('local_homework_status', ['quizid' => $quizid, 'timeclose' => $timeclose]);

                $windowdays = $this->get_course_window_days($courseid);
                [$windowstart, $windowend] = $this->build_window($timeclose, $windowdays);

                $roster = $this->get_course_roster($courseid);
                if (empty($roster)) {
                    continue;
                }

                // Fetch attempts in window
                list($insql, $params) = $DB->get_in_or_equal($roster, \SQL_PARAMS_NAMED, 'uid');
                $params['quizid'] = $quizid;
                $params['start'] = $windowstart;
                $params['end'] = $windowend;

                $sql = "SELECT qa.userid, qa.sumgrades, qa.timestart, qa.timefinish
                          FROM {quiz_attempts} qa
                         WHERE qa.quiz = :quizid
                           AND qa.userid $insql
                           AND qa.state = 'finished'
                           AND qa.timefinish BETWEEN :start AND :end
                      ORDER BY qa.timefinish ASC";

                $attempts = $DB->get_records_sql($sql, $params);

                // Group by user
                $peruser = [];
                foreach ($attempts as $a) {
                    $uid = (int)$a->userid;
                    if (!isset($peruser[$uid])) {
                        $peruser[$uid] = [
                            'attempts' => 0,
                            'bestpercent' => 0.0,
                            'firstfinish' => 0,
                            'lastfinish' => 0,
                        ];
                    }

                    $peruser[$uid]['attempts']++;
                    $timefinish = (int)$a->timefinish;
                    $grade = (float)$quiz->grade;

                    if ($timefinish > 0) {
                        if ($peruser[$uid]['firstfinish'] === 0 || $timefinish < $peruser[$uid]['firstfinish']) {
                            $peruser[$uid]['firstfinish'] = $timefinish;
                        }
                        if ($timefinish > $peruser[$uid]['lastfinish']) {
                            $peruser[$uid]['lastfinish'] = $timefinish;
                        }
                    }

                    if ($grade > 0.0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $grade) * 100.0;
                        if ($pct > $peruser[$uid]['bestpercent']) {
                            $peruser[$uid]['bestpercent'] = $pct;
                        }
                    }
                }

                $classification = $this->get_activity_classification((int)$cm->id);
                $quiztype = $this->quiz_has_essay($quizid) ? 'Essay' : 'Non-Essay';

                foreach ($roster as $uid) {
                    $uid = (int)$uid;
                    $summary = $peruser[$uid] ?? [
                        'attempts' => 0,
                        'bestpercent' => 0.0,
                        'firstfinish' => 0,
                        'lastfinish' => 0,
                    ];

                    if ($summary['attempts'] === 0) {
                        $status = 'noattempt';
                    } else if ($summary['bestpercent'] >= self::COMPLETION_PERCENT_THRESHOLD) {
                        $status = 'completed';
                    } else {
                        $status = 'lowgrade';
                    }

                    $record = (object) [
                        'userid'       => $uid,
                        'courseid'     => $courseid,
                        'cmid'         => (int)$cm->id,
                        'quizid'       => $quizid,
                        'timeclose'    => $timeclose,
                        'windowdays'   => $windowdays,
                        'windowstart'  => $windowstart,
                        'attempts'     => $summary['attempts'],
                        'bestpercent'  => (int)round($summary['bestpercent']),
                        'firstfinish'  => $summary['firstfinish'] ?: null,
                        'lastfinish'   => $summary['lastfinish'] ?: null,
                        'status'       => $status,
                        'classification' => $classification ?: null,
                        'quiztype'       => $quiztype ?: null,
                        'computedat'   => $now,
                    ];

                    $DB->insert_record('local_homework_status', $record);
                    $inserted++;
                }
            }
        }

        return $inserted;
    }
}
