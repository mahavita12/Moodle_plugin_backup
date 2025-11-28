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
        array $userids,
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
        
        // Filter by specific user IDs if provided
        $userids = array_filter($userids, function($id) { return $id > 0; });
        if (!empty($userids)) {
            list($usql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $sql .= " AND s.userid $usql";
            $params = array_merge($params, $uparams);
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

            $windowstart = (int)$s->windowstart;
            $windowend = (int)$s->timeclose;

            $attempts = [];
            $attemptparams = [
                'quizid' => (int)$s->quizid,
                'userid' => $uid,
                'start'  => $windowstart,
                'end'    => $windowend,
            ];

            $attemptsql = "SELECT qa.id, qa.attempt, qa.state, qa.userid, qa.sumgrades, qa.timestart, qa.timefinish
                              FROM {quiz_attempts} qa
                             WHERE qa.quiz = :quizid
                               AND qa.userid = :userid
                               AND qa.state = 'finished'
                               AND qa.timefinish BETWEEN :start AND :end
                         ORDER BY qa.timefinish ASC";

            $attemptrecords = $DB->get_records_sql($attemptsql, $attemptparams);

            foreach ($attemptrecords as $a) {
                $timestart = (int)$a->timestart;
                $afinish = (int)$a->timefinish;
                if ($timestart <= 0 || $afinish <= $timestart) {
                    continue;
                }
                $duration = $afinish - $timestart;
                if ($duration < 180) {
                    continue;
                }
                $attempts[] = $a;
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
                'attempts'     => $attempts,
            ];
        }

        return $rows;
    }

    /**
     * Get LIVE homework rows (quizzes with future close dates), calculated on-the-fly.
     */
    public function get_live_homework_rows(
        int $categoryid,
        array $courseids,
        int $sectionid,
        array $quizids,
        array $userids,
        string $studentname,
        string $quiztypefilter,
        string $statusfilter,
        string $classificationfilter,
        ?string $weekvalue,
        string $sort,
        string $dir,
        bool $excludestaff = false,
        array $duedates = [],
        int $customstart = 0,
        int $customend = 0,
        bool $include_past = false
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
        
        // Filter for LIVE quizzes (close time > now) unless include_past is true
        if (!$include_past) {
            $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) > :now";
            $params['now'] = $now;
        }

        if ($categoryid > 0) {
            $sql .= " AND c.category = :categoryid";
            $params['categoryid'] = $categoryid;
        }
        
        $courseids = array_filter($courseids, function($id) { return $id > 0; });
        if (!empty($courseids)) {
            list($csql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $sql .= " AND c.id $csql";
            $params = array_merge($params, $cparams);
        }

        if ($sectionid > 0) {
            $sql .= " AND cs.id = :sectionid";
            $params['sectionid'] = $sectionid;
        }

        $quizids = array_filter($quizids, function($id) { return $id > 0; });
        if (!empty($quizids)) {
            list($qsql, $qparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qid');
            $sql .= " AND q.id $qsql";
            $params = array_merge($params, $qparams);
        }

        $duedates = array_filter($duedates, function($d) { return $d > 0; });
        if (!empty($duedates)) {
            // Complex logic for due dates: check both q.timeclose and ev.eventclose
            // Simplification: We filter where the effective close time matches one of the dates.
            // Note: This might be slow if many dates.
            list($dsql, $dparams) = $DB->get_in_or_equal($duedates, SQL_PARAMS_NAMED, 'dd');
            $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) $dsql";
            $params = array_merge($params, $dparams);
        } else {
            if ($customstart > 0 && $customend > 0) {
                $weekstart = $customstart;
                $weekend = $customend;
            } else {
                [$weekstart, $weekend] = $this->get_week_bounds($weekvalue);
            }
            
            if ($weekstart > 0 && $weekend > 0) {
                $sql .= " AND COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) BETWEEN :weekstart AND :weekend";
                $params['weekstart'] = $weekstart;
                $params['weekend'] = $weekend;
            }
        }

        $sql .= " ORDER BY c.fullname, q.name";

        // Debug logging
        error_log("HM_DEBUG: SQL: " . $sql);
        error_log("HM_DEBUG: Params: " . json_encode($params));

        $quizrecords = $DB->get_records_sql($sql, $params);
        error_log("HM_DEBUG: Records found: " . count($quizrecords));

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
                error_log("HM_DEBUG: Course " . $qrec->courseid . " Excluded Staff Count: " . count($excludeduserids));
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
                    error_log("HM_DEBUG: Course " . $qrec->courseid . " skipped because roster is empty after excluding staff.");
                    continue;
                }
                $roster = array_values($roster);
            }

            $userids = array_filter($userids, function($id) { return $id > 0; });
            if (!empty($userids)) {
                $roster = array_values(array_intersect($roster, $userids));
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
                    'attempts'     => $summary['attempts'],
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
        array $courseids,
        int $sectionid,
        array $quizids,
        array $userids,
        string $studentname,
        string $quiztypefilter,
        string $statusfilter,
        string $classificationfilter,
        ?string $weekvalue,
        string $sort,
        string $dir,
        bool $excludestaff = false,
        array $duedates = []
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
        $courseids = array_filter($courseids, function($id) { return $id > 0; });
        if (!empty($courseids)) {
            list($csql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'scid');
            $snapsql .= " AND c.id $csql";
            $snapparams = array_merge($snapparams, $cparams);
        }

        if ($sectionid > 0) {
            $snapsql .= " AND cs.id = :ssectionid";
            $snapparams['ssectionid'] = $sectionid;
        }

        $quizids = array_filter($quizids, function($id) { return $id > 0; });
        if (!empty($quizids)) {
            list($qsql, $qparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'sqid');
            $snapsql .= " AND s.quizid $qsql";
            $snapparams = array_merge($snapparams, $qparams);
        }

        $duedates = array_filter($duedates, function($d) { return $d > 0; });
        if (!empty($duedates)) {
            list($dsql, $dparams) = $DB->get_in_or_equal($duedates, SQL_PARAMS_NAMED, 'sdd');
            $snapsql .= " AND s.timeclose $dsql";
            $snapparams = array_merge($snapparams, $dparams);
        } else {
            [$weekstart, $weekend] = $this->get_week_bounds($weekvalue);
            if ($weekstart > 0 && $weekend > 0) {
                $snapsql .= " AND s.timeclose BETWEEN :sweekstart AND :sweekend";
                $snapparams['sweekstart'] = $weekstart;
                $snapparams['sweekend'] = $weekend;
            }

            // By default, Historical Snapshots should only include past-due quizzes.
            $snapsql .= " AND s.timeclose <= :snow";
            $snapparams['snow'] = $now;
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
                    $userids,
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
     * Get users for filter context (Category/Course).
     * Used to populate the User dropdown with all eligible users, independent of the current User filter.
     */
    public function get_users_for_filter_context(int $categoryid, array $courseids, bool $excludestaff): array {
        global $DB;

        $users = [];
        $courseids = array_filter($courseids, function($id) { return $id > 0; });

        // 1. Identify relevant courses
        $target_courseids = [];
        if (!empty($courseids)) {
            $target_courseids = $courseids;
        } else if ($categoryid > 0) {
            $target_courseids = $DB->get_fieldset_select('course', 'id', 'category = :cat', ['cat' => $categoryid]);
            $target_courseids = array_map('intval', $target_courseids);
        }

        if (empty($target_courseids)) {
            // If no course/category selected, we might want to return ALL users who have attempts?
            // Or just return empty to force course selection?
            // For performance, let's limit to users who have attempts in quizzes if no course is selected.
            // However, the dashboard usually shows "All courses" by default.
            // Let's try to get users from visible courses if list is not too huge, or just return empty if too broad.
            // Better approach: Return users from the rows logic if no context, BUT the rows logic is filtered.
            // Let's stick to: if no course selected, return empty (user must select course/category), OR
            // fetch from all visible courses (might be heavy).
            // Compromise: If no filter, return empty array (UI will show "All" or nothing).
            // Actually, the previous behavior was "users in the rows".
            // Let's try to fetch users from all visible courses? No, too many.
            // Let's return empty if no context, and handle it in index.php (fallback to existing logic or empty).
            return [];
        }

        // 2. Fetch users enrolled in these courses
        list($csql, $cparams) = $DB->get_in_or_equal($target_courseids, SQL_PARAMS_NAMED, 'cid');
        
        // Get distinct users enrolled in these courses
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid $csql
                   AND u.deleted = 0 AND u.suspended = 0";
        
        $userrecords = $DB->get_records_sql($sql, $cparams);

        // 3. Filter excluded staff
        if ($excludestaff) {
            $staffids = [];
            foreach ($target_courseids as $cid) {
                $staffids = array_merge($staffids, $this->get_staff_users_for_course($cid));
            }
            $staffids = array_unique($staffids);
            
            foreach ($userrecords as $uid => $u) {
                if (in_array($uid, $staffids)) {
                    unset($userrecords[$uid]);
                }
            }
        }

        // Format for dropdown
        foreach ($userrecords as $u) {
            $users[$u->id] = (object)[
                'id' => $u->id,
                'fullname' => fullname($u),
            ];
        }

        // Sort by name
        uasort($users, function($a, $b) {
            return strcmp($a->fullname, $b->fullname);
        });

        return $users;
    }

    /**
     * Backfill snapshots for specific due dates (quiz close times).
     * STRICTLY RECALCULATES existing snapshots. Does NOT add new ones or delete existing ones.
     * Preserves historical metadata (classification, quiztype).
     */
    public function backfill_snapshots_from_dates(array $timestamps): int {
        global $DB;

        if (empty($timestamps)) {
            return 0;
        }

        $updated = 0;
        $now = time();
        $timestamps = array_map('intval', $timestamps);
        $timestamps = array_unique($timestamps);

        foreach ($timestamps as $ts) {
            if ($ts <= 0) continue;

            // Get ALL existing snapshots for this due date.
            $snapshots = $DB->get_records('local_homework_status', ['timeclose' => $ts]);

            if (empty($snapshots)) {
                continue;
            }

            foreach ($snapshots as $snap) {
                $quizid = (int)$snap->quizid;
                $userid = (int)$snap->userid;
                $timeclose = (int)$snap->timeclose;
                $windowdays = (int)$snap->windowdays;
                
                // Recalculate window based on stored windowdays
                $windowstart = $timeclose - ($windowdays * 24 * 60 * 60);
                $windowend = $timeclose;

                // Fetch attempts in window
                $params = [
                    'quizid' => $quizid,
                    'userid' => $userid,
                    'start'  => $windowstart,
                    'end'    => $windowend
                ];

                $sql = "SELECT qa.id, qa.sumgrades, qa.timefinish
                          FROM {quiz_attempts} qa
                         WHERE qa.quiz = :quizid
                           AND qa.userid = :userid
                           AND qa.state = 'finished'
                           AND qa.timefinish BETWEEN :start AND :end
                      ORDER BY qa.timefinish ASC";

                $attempts = $DB->get_records_sql($sql, $params);

                $attempts_count = count($attempts);
                $bestpercent = 0.0;
                $firstfinish = 0;
                $lastfinish = 0;

                // We need the quiz grade to calculate percentage
                $quizgrade = $DB->get_field('quiz', 'grade', ['id' => $quizid]);
                $grade = ($quizgrade > 0.0) ? (float)$quizgrade : 0.0;

                foreach ($attempts as $a) {
                    $timefinish = (int)$a->timefinish;
                    
                    if ($firstfinish === 0 || $timefinish < $firstfinish) {
                        $firstfinish = $timefinish;
                    }
                    if ($timefinish > $lastfinish) {
                        $lastfinish = $timefinish;
                    }

                    if ($grade > 0.0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $grade) * 100.0;
                        if ($pct > $bestpercent) {
                            $bestpercent = $pct;
                        }
                    }
                }

                if ($attempts_count === 0) {
                    $status = 'noattempt';
                } else if ($bestpercent >= self::COMPLETION_PERCENT_THRESHOLD) {
                    $status = 'completed';
                } else {
                    $status = 'lowgrade';
                }

                // Update the existing record
                $snap->attempts = $attempts_count;
                $snap->bestpercent = (int)round($bestpercent);
                $snap->firstfinish = $firstfinish ?: null;
                $snap->lastfinish = $lastfinish ?: null;
                $snap->status = $status;
                $snap->computedat = $now;
                
                $DB->update_record('local_homework_status', $snap);
                $updated++;
            }
        }

        return $updated;
    }
    /**
     * Get all distinct due dates (timeclose) from quizzes and events.
     * Used for populating the Reports tab filter.
     */
    public function get_all_distinct_due_dates(): array {
        global $DB;

        // Get quiz due dates
        $sql = "SELECT DISTINCT timeclose FROM {quiz} WHERE timeclose > 0 ORDER BY timeclose DESC";
        $quizdates = $DB->get_fieldset_sql($sql);
        
        $dates = [];
        foreach ($quizdates as $timestamp) {
            $dates[$timestamp] = (object)[
                'timestamp' => $timestamp,
                'formatted' => userdate($timestamp, get_string('strftimedatetime', 'langconfig')),
            ];
        }
        
        return $dates;
    }

    /**
     * Get all students who have attempted at least one quiz.
     */
    public function get_all_students_with_homework(): array {
        global $DB;
        
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                FROM {user} u
                JOIN {quiz_attempts} qa ON qa.userid = u.id
                WHERE u.deleted = 0
                ORDER BY u.lastname, u.firstname";
        
        $users = $DB->get_records_sql($sql);
        return $users;
    }
}
