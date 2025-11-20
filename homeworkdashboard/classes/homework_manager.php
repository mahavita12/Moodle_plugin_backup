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
    private const COMPLETION_PERCENT_THRESHOLD = 20.0;

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
            $field = $DB->get_record('customfield_field', ['shortname' => 'homework_window_days'], 'id', IGNORE_MISSING);
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
     * Build [start, end] timestamps for the homework window.
     */
    private function build_window(int $timeclose, int $windowdays): array {
        $days = max(1, $windowdays);
        $end = $timeclose;
        $start = $timeclose - ($days * 24 * 60 * 60);
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

        list($insql, $params) = $DB->get_in_or_equal($roster, SQL_PARAMS_NAMED, 'uid');
        $params['quizid'] = $quizid;
        $params['start'] = $windowstart;
        $params['end'] = $windowend;

        $sql = "SELECT qa.userid, qa.sumgrades, qa.timefinish
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

    /**
     * Build homework dashboard rows (one row per student + quiz).
     *
     * The returned objects mirror the quizdashboard row structure where possible:
     *  - userid, studentname, courseid, coursename, categoryid, categoryname
     *  - quizid, quizname, cmid, attemptno
     *  - status (homework status: Completed/Low grade/No attempt)
     *  - timestart, timefinish, time_taken (string), score, maxscore, percentage, quiz_type
     */
    public function get_homework_rows(
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
        string $dir
    ): array {
        global $DB;

        $rows = [];

        // Resolve optional week filter to quiz timeclose bounds.
        [$weekstart, $weekend] = $this->get_week_bounds($weekvalue);

        $now = time();
        $usesnapshots = ($weekstart > 0 && $weekend > 0 && $weekend < $now);

        if ($usesnapshots) {
            $params = [
                'weekstart' => $weekstart,
                'weekend'   => $weekend,
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
                   WHERE s.timeclose BETWEEN :weekstart AND :weekend";

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
                $userids[] = (int)$s->userid;
            }
            $userids = array_values(array_unique($userids));

            if (!empty($userids)) {
                list($userinsql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
                $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $userinsql", $userparams);
            } else {
                $userrecs = [];
            }

            foreach ($snapshots as $s) {
                $uid = (int)$s->userid;
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
                    'score'        => 0.0,
                    'maxscore'     => ($s->grade > 0.0) ? (float)$s->grade : 0.0,
                    'percentage'   => (float)$s->bestpercent,
                    'quiz_type'    => $s->quiztype ?? '',
                    'timeclose'    => (int)$s->timeclose,
                ];
            }

            if (empty($rows)) {
                return [];
            }

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

        // Base query to locate quizzes included in the dashboard.
        $params = [];
        $sql = "SELECT
                    q.id       AS quizid,
                    q.name     AS quizname,
                    q.course   AS courseid,
                    q.timeclose,
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
                WHERE q.timeclose IS NOT NULL AND q.timeclose > 0";

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
        if ($weekstart > 0 && $weekend > 0) {
            $sql .= " AND q.timeclose BETWEEN :weekstart AND :weekend";
            $params['weekstart'] = $weekstart;
            $params['weekend'] = $weekend;
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

            // Activity classification is per course module.
            $classification = $this->get_activity_classification((int)$qrec->cmid);
            if ($classificationfilter !== '' && strcasecmp($classificationfilter, $classification) !== 0) {
                continue;
            }

            // Quiz type filter (Essay / Non-Essay).
            $quiztype = $this->quiz_has_essay((int)$qrec->quizid) ? 'Essay' : 'Non-Essay';
            if ($quiztypefilter !== '' && strcasecmp($quiztypefilter, $quiztype) !== 0) {
                continue;
            }

            $windowdays = $this->get_course_window_days((int)$qrec->courseid);
            [$windowstart, $windowend] = $this->build_window($qtimeclose, $windowdays);

            $roster = $this->get_course_roster((int)$qrec->courseid);
            if (empty($roster)) {
                continue;
            }

            // Optionally restrict roster by userid filter.
            if ($userid > 0) {
                $roster = array_values(array_intersect($roster, [$userid]));
                if (empty($roster)) {
                    continue;
                }
            }

            list($insql, $inparams) = $DB->get_in_or_equal($roster, SQL_PARAMS_NAMED, 'uid');
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

            // Index attempts per user.
            $peruser = [];
            $grade = ($qrec->grade > 0.0) ? (float)$qrec->grade : 0.0;

            foreach ($attempts as $a) {
                $uid = (int)$a->userid;
                if (!isset($peruser[$uid])) {
                    $peruser[$uid] = [
                        'attempts' => [],
                        'bestpercent' => 0.0,
                        'last' => null,
                    ];
                }
                $peruser[$uid]['attempts'][] = $a;

                if ($grade > 0.0 && $a->sumgrades !== null) {
                    $pct = ((float)$a->sumgrades / $grade) * 100.0;
                    if ($pct > $peruser[$uid]['bestpercent']) {
                        $peruser[$uid]['bestpercent'] = $pct;
                    }
                }

                if ($peruser[$uid]['last'] === null || (int)$a->timefinish > (int)$peruser[$uid]['last']->timefinish) {
                    $peruser[$uid]['last'] = $a;
                }
            }

            // Pre-fetch user names for roster once per quiz.
            list($userinsql, $userparams) = $DB->get_in_or_equal($roster, SQL_PARAMS_NAMED, 'u');
            $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $userinsql", $userparams);

            foreach ($roster as $uid) {
                $uid = (int)$uid;

                $userdata = $userrecs[$uid] ?? null;
                $fullname = $userdata ? ($userdata->firstname . ' ' . $userdata->lastname) : '';

                // Student name filter by full name (if provided).
                if ($studentname !== '' && $fullname !== $studentname) {
                    continue;
                }

                $summary = $peruser[$uid] ?? ['attempts' => [], 'bestpercent' => 0.0, 'last' => null];
                $best = $summary['bestpercent'];
                $last = $summary['last'];

                // Determine homework status.
                if (empty($summary['attempts'])) {
                    $hwstatus = 'No attempt';
                } else if ($best >= self::COMPLETION_PERCENT_THRESHOLD) {
                    $hwstatus = 'Completed';
                } else {
                    $hwstatus = 'Low grade';
                }

                // Status filter (homework status).
                if ($statusfilter !== '' && strcasecmp($statusfilter, $hwstatus) !== 0) {
                    continue;
                }

                $timestart = $last ? (int)$last->timestart : 0;
                $timefinish = $last ? (int)$last->timefinish : 0;
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

                $lastscore = $last && $last->sumgrades !== null ? (float)$last->sumgrades : 0.0;

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
                    'lastattemptid'=> $last ? (int)$last->id : 0,
                    'attemptno'    => $last ? (int)$last->attempt : 0,
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

        if (empty($rows)) {
            return [];
        }

        // Sorting in PHP to keep behaviour close to quizdashboard.
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
     * Get all homework attempts for a single student + quiz within its homework window.
     *
     * @return array of stdClass {id, attempt, timestart, timefinish, sumgrades, state}
     */
    public function get_homework_attempts_for_user_quiz(int $userid, int $quizid): array {
        global $DB;

        if ($userid <= 0 || $quizid <= 0) {
            return [];
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', IGNORE_MISSING);
        if (!$quiz || empty($quiz->timeclose)) {
            return [];
        }

        $courseid = (int)$quiz->course;
        $windowdays = $this->get_course_window_days($courseid);
        [$windowstart, $windowend] = $this->build_window((int)$quiz->timeclose, $windowdays);

        $sql = "SELECT qa.id, qa.attempt, qa.timestart, qa.timefinish, qa.sumgrades, qa.state
                  FROM {quiz_attempts} qa
                 WHERE qa.quiz = :quizid
                   AND qa.userid = :userid
                   AND qa.timefinish BETWEEN :start AND :end
                 ORDER BY qa.timefinish ASC";
        $params = [
            'quizid' => $quizid,
            'userid' => $userid,
            'start'  => $windowstart,
            'end'    => $windowend,
        ];

        return array_values($DB->get_records_sql($sql, $params));
    }

    public function compute_due_snapshots(): void {
        global $DB;

        $now = time();

        $sql = "SELECT q.id AS quizid, q.course AS courseid, q.timeclose, q.grade, cm.id AS cmid
                  FROM {quiz} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE q.timeclose IS NOT NULL
                   AND q.timeclose > 0
                   AND q.timeclose <= :now";
        $quizzes = $DB->get_records_sql($sql, ['now' => $now]);
        if (empty($quizzes)) {
            return;
        }

        foreach ($quizzes as $qrec) {
            $quizid = (int)$qrec->quizid;
            $courseid = (int)$qrec->courseid;
            $cmid = (int)$qrec->cmid;
            $timeclose = (int)$qrec->timeclose;

            if ($timeclose <= 0) {
                continue;
            }

            $classification = $this->get_activity_classification($cmid);
            $quiztype = $this->quiz_has_essay($quizid) ? 'Essay' : 'Non-Essay';

            $existing = $DB->get_records('local_homework_status', ['quizid' => $quizid, 'timeclose' => $timeclose], '', 'id,classification,quiztype');
            if (!empty($existing)) {
                $needsclassupdate = ($classification !== null && $classification !== '');
                $needstypeupdate = ($quiztype !== null && $quiztype !== '');

                if ($needsclassupdate) {
                    foreach ($existing as $ex) {
                        if (empty($ex->classification)) {
                            $DB->set_field('local_homework_status', 'classification', $classification, ['quizid' => $quizid, 'timeclose' => $timeclose]);
                            break;
                        }
                    }
                }

                if ($needstypeupdate) {
                    foreach ($existing as $ex) {
                        if (empty($ex->quiztype)) {
                            $DB->set_field('local_homework_status', 'quiztype', $quiztype, ['quizid' => $quizid, 'timeclose' => $timeclose]);
                            break;
                        }
                    }
                }

                continue;
            }

            $windowdays = $this->get_course_window_days($courseid);
            [$windowstart, $windowend] = $this->build_window($timeclose, $windowdays);

            $roster = $this->get_course_roster($courseid);
            if (empty($roster)) {
                continue;
            }

            list($insql, $params) = $DB->get_in_or_equal($roster, SQL_PARAMS_NAMED, 'uid');
            $params['quizid'] = $quizid;
            $params['start'] = $windowstart;
            $params['end'] = $windowend;

            $attemptsql = "SELECT qa.userid, qa.attempt, qa.timestart, qa.timefinish, qa.sumgrades
                             FROM {quiz_attempts} qa
                            WHERE qa.quiz = :quizid
                              AND qa.state = 'finished'
                              AND qa.userid $insql
                              AND qa.timefinish BETWEEN :start AND :end";
            $attempts = $DB->get_records_sql($attemptsql, $params);

            $grade = ($qrec->grade > 0.0) ? (float)$qrec->grade : 0.0;

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

                $tf = (int)$a->timefinish;
                if ($tf > 0) {
                    if ($peruser[$uid]['firstfinish'] === 0 || $tf < $peruser[$uid]['firstfinish']) {
                        $peruser[$uid]['firstfinish'] = $tf;
                    }
                    if ($tf > $peruser[$uid]['lastfinish']) {
                        $peruser[$uid]['lastfinish'] = $tf;
                    }
                }

                if ($grade > 0.0 && $a->sumgrades !== null) {
                    $pct = ((float)$a->sumgrades / $grade) * 100.0;
                    if ($pct > $peruser[$uid]['bestpercent']) {
                        $peruser[$uid]['bestpercent'] = $pct;
                    }
                }
            }

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
                    'cmid'         => $cmid,
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
            }
        }
    }

    public function backfill_snapshots_from_events(int $weeks): int {
        global $DB;

        $weeks = max(1, $weeks);
        $now = time();
        $start = $now - ($weeks * 7 * 24 * 60 * 60);

        $params = [
            'start' => $start,
            'end'   => $now,
        ];

        $sql = "SELECT e.id AS eventid, e.timestart AS timeclose,
                       q.id AS quizid, q.course AS courseid, q.grade,
                       cm.id AS cmid
                  FROM {event} e
                  JOIN {quiz} q ON q.id = e.instance AND e.modulename = 'quiz'
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE e.eventtype = 'close'
                   AND e.timestart BETWEEN :start AND :end";

        $events = $DB->get_records_sql($sql, $params);
        if (empty($events)) {
            return 0;
        }

        $inserted = 0;

        foreach ($events as $erec) {
            $quizid = (int)$erec->quizid;
            $courseid = (int)$erec->courseid;
            $cmid = (int)$erec->cmid;
            $timeclose = (int)$erec->timeclose;

            if ($timeclose <= 0) {
                continue;
            }

            if ($DB->record_exists('local_homework_status', ['quizid' => $quizid, 'timeclose' => $timeclose])) {
                continue;
            }

            $windowdays = $this->get_course_window_days($courseid);
            [$windowstart, $windowend] = $this->build_window($timeclose, $windowdays);

            $roster = $this->get_course_roster($courseid);
            if (empty($roster)) {
                continue;
            }

            list($insql, $apparams) = $DB->get_in_or_equal($roster, SQL_PARAMS_NAMED, 'uid');
            $apparams['quizid'] = $quizid;
            $apparams['start'] = $windowstart;
            $apparams['end'] = $windowend;

            $attemptsql = "SELECT qa.userid, qa.attempt, qa.timestart, qa.timefinish, qa.sumgrades
                             FROM {quiz_attempts} qa
                            WHERE qa.quiz = :quizid
                              AND qa.state = 'finished'
                              AND qa.userid $insql
                              AND qa.timefinish BETWEEN :start AND :end";
            $attempts = $DB->get_records_sql($attemptsql, $apparams);

            $grade = ($erec->grade > 0.0) ? (float)$erec->grade : 0.0;

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

                $tf = (int)$a->timefinish;
                if ($tf > 0) {
                    if ($peruser[$uid]['firstfinish'] === 0 || $tf < $peruser[$uid]['firstfinish']) {
                        $peruser[$uid]['firstfinish'] = $tf;
                    }
                    if ($tf > $peruser[$uid]['lastfinish']) {
                        $peruser[$uid]['lastfinish'] = $tf;
                    }
                }

                if ($grade > 0.0 && $a->sumgrades !== null) {
                    $pct = ((float)$a->sumgrades / $grade) * 100.0;
                    if ($pct > $peruser[$uid]['bestpercent']) {
                        $peruser[$uid]['bestpercent'] = $pct;
                    }
                }
            }

            $classification = $this->get_activity_classification($cmid);
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
                    'cmid'         => $cmid,
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

        return $inserted;
    }
}
