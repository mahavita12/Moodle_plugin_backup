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
        // Add 2 hours offset to avoid overlap with previous week's close time
        $start = $timeclose - ($days * 24 * 60 * 60) + 7200;
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
                    COALESCE(sc.id, s.courseid) AS courseid,
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
                    s.quizgrade,
                    s.points,
                    s.score,
                    s.computedat,
                    lhr.timeemailsent,
                    q.name      AS quizname,
                    (SELECT MIN(q2.timeclose) FROM {quiz} q2 WHERE q2.course = s.courseid AND q2.timeclose > s.timeclose AND q2.timeclose > 0) AS next_due_date,
                    q.grade,
                    COALESCE(sc.fullname, c.fullname)  AS coursename,
                    COALESCE(sc.shortname, c.shortname) AS courseshortname,
                    COALESCE(pq.sourcecategory, cat.name) AS categoryname,
                    COALESCE(sc.category, cat.id) AS categoryid,
                    cm.id       AS cmid_real,
                    cs.id       AS sectionid,
                    cs.name     AS sectionname,
                    cs.section  AS sectionnumber
                FROM {local_homework_status} s
                JOIN {quiz} q ON q.id = s.quizid
                JOIN {course} c ON c.id = s.courseid
                JOIN {course_categories} cat ON cat.id = c.category
                LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = s.quizid
                LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
                JOIN {course_modules} cm ON cm.id = s.cmid
                JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                JOIN {course_sections} cs ON cs.id = cm.section
                LEFT JOIN {local_homework_reports} lhr ON lhr.userid = s.userid AND lhr.timeclose = s.timeclose
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
            $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE id $userinsql", $userparams);
            $parentinfo = $this->get_users_parent_info($userids);
        } else {
            $userrecs = [];
            $parentinfo = [];
        }

        $rows = [];

        foreach ($snapshots as $s) {
            $uid = (int)$s->userid;
            if (!empty($excludeduserids) && in_array($uid, $excludeduserids)) {
                continue;
            }
            $userdata = $userrecs[$uid] ?? null;
            $fullname = $userdata ? ($userdata->firstname . ' ' . $userdata->lastname) : '';
            $email = $userdata ? $userdata->email : '';
            $pinfo = $parentinfo[$uid] ?? null;

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

            // Use frozen values if available, otherwise fallback to live calculation
            if (!empty($s->quizgrade) && $s->quizgrade > 0) {
                $maxscore = (float)$s->quizgrade;
                $points = (float)$s->points;
                $bestscore = (float)$s->score;
                $bestpercent = (float)$s->bestpercent;
            } else {
                // Fallback for old snapshots
                $maxscore = ($s->grade > 0.0) ? (float)$s->grade : 0.0;
                
                // Calculate Points based on Status
                $points = 0.0;
                if ($hwstatus === 'Completed') {
                    $points = $maxscore;
                } elseif ($hwstatus === 'Low grade') {
                    $points = $maxscore * 0.5;
                }

                $bestpercent = (float)$s->bestpercent;
                $bestscore = 0.0;
                if ($maxscore > 0.0 && $bestpercent > 0.0) {
                    $bestscore = round(($bestpercent / 100.0) * $maxscore, 2);
                }
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
                // Removed duration check here so ALL attempts are displayed
                // if ($duration < 180) { continue; }
                $attempts[] = $a;
            }

            // Find best attempt (only consider attempts >= 180s AND score > 10%)
            $bestattempt = null;
            $highestgrade = -1.0;
            // Use $maxscore which was set earlier from $s->grade or $s->quizgrade
            $qgrade = ($maxscore > 0.0) ? (float)$maxscore : 0.0;

            foreach ($attempts as $at) {
                $dur = (int)$at->timefinish - (int)$at->timestart;
                if ($dur < 180) {
                    continue;
                }
                
                $g = (float)$at->sumgrades;
                $pct = ($qgrade > 0.0) ? ($g / $qgrade) * 100.0 : 0.0;
                
                // Filter: Ignore if score <= 10%
                if ($pct <= 10.0) {
                    continue;
                }

                if ($g > $highestgrade) {
                    $highestgrade = $g;
                    $bestattempt = $at;
                }
            }

            $time_taken = '';
            if ($bestattempt) {
                $ts = (int)$bestattempt->timestart;
                $tf = (int)$bestattempt->timefinish;
                if ($ts > 0 && $tf > $ts) {
                    $duration = $tf - $ts;
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
            }

            $rows[] = (object) [
                'id'           => (int)$s->id,
                'userid'       => $uid,
                'studentname'  => $fullname,
                'email'        => $email,
                'parent1'      => $pinfo ? (object)['name' => $pinfo->p1_name, 'email' => $pinfo->p1_email, 'phone' => $pinfo->p1_phone, 'lang' => $pinfo->p1_lang] : null,
                'parent2'      => $pinfo ? (object)['name' => $pinfo->p2_name, 'email' => $pinfo->p2_email, 'phone' => $pinfo->p2_phone, 'lang' => $pinfo->p2_lang] : null,
                'courseid'     => (int)$s->courseid,
                'coursename'   => $s->coursename,
                'categoryid'   => (int)$s->categoryid,
                'categoryname' => $s->categoryname,
                'sectionid'    => (int)$s->sectionid,
                'sectionname'  => $s->sectionname,
                'sectionnumber'=> $s->sectionnumber,
                'quizid'       => (int)$s->quizid,
                'quizname'     => $s->quizname,
                'next_due_date'=> $s->next_due_date,
                'cmid'         => (int)$s->cmid_real,
                'classification'=> $s->classification ?? '',
                'lastattemptid'=> 0,
                'attemptno'    => (int)$s->attempts,
                'status'       => $hwstatus,
                'timestart'    => $bestattempt ? (int)$bestattempt->timestart : 0,
                'timefinish'   => $timefinish,
                'time_taken'   => $time_taken,
                'score'        => $bestscore,
                'maxscore'     => $maxscore,
                'points'       => $points,
                'percentage'   => $bestpercent,
                'quiz_type'    => $s->quiztype ?? '',
                'timeclose'    => (int)$s->timeclose,
                'timeemailsent'=> (int)($s->timeemailsent ?? 0),
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
                    (SELECT MIN(q2.timeclose) FROM {quiz} q2 WHERE q2.course = q.course AND q2.timeclose > COALESCE(NULLIF(q.timeclose, 0), ev.eventclose) AND q2.timeclose > 0) AS next_due_date,
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
                // Removed duration check here so ALL attempts are displayed
                // if ($duration < 180) { continue; }

                $uid = (int)$a->userid;
                if (!isset($peruser[$uid])) {
                    $peruser[$uid] = [
                        'attempts' => [],
                        'valid_attempts' => 0,
                        'bestpercent' => 0.0,
                        'best' => null,
                    ];
                }
                $peruser[$uid]['attempts'][] = $a;

                // Only consider attempts with duration >= 180s AND score > 10% for status/best score
                if ($duration >= 180) {
                    if ($grade > 0.0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $grade) * 100.0;
                        // Filter: Ignore if score <= 10%
                        if ($pct > 10.0) {
                            $peruser[$uid]['valid_attempts']++;
                            if ($pct > $peruser[$uid]['bestpercent']) {
                                $peruser[$uid]['bestpercent'] = $pct;
                                $peruser[$uid]['best'] = $a;
                            }
                        }
                    }
                }
            }

            // Roster names
            list($userinsql, $userparams) = $DB->get_in_or_equal($roster, \SQL_PARAMS_NAMED, 'u');
            $userrecs = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE id $userinsql", $userparams);

            foreach ($roster as $uid) {
                $uid = (int)$uid;
                $userdata = $userrecs[$uid] ?? null;
                $fullname = $userdata ? ($userdata->firstname . ' ' . $userdata->lastname) : '';
                $email = $userdata ? $userdata->email : '';

                if ($studentname !== '' && $fullname !== $studentname) {
                    continue;
                }

                $summary = $peruser[$uid] ?? ['attempts' => [], 'valid_attempts' => 0, 'bestpercent' => 0.0, 'best' => null];
                $best = $summary['bestpercent'];
                $bestattempt = $summary['best'];

                // Status logic:
                // 1. No valid attempts (attempts <= 10% or < 180s) -> 'To do'
                // 2. Best score > 30% -> 'Completed'
                // 3. Otherwise (10% < score <= 30%) -> 'Low grade' (Retry)
                if ($summary['valid_attempts'] === 0) {
                    $hwstatus = 'To do';
                } else if ($best > self::COMPLETION_PERCENT_THRESHOLD) {
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

                // Calculate Points based on Status
                $points = 0.0;
                if ($hwstatus === 'Completed') {
                    $points = $grade;
                } elseif ($hwstatus === 'Low grade') {
                    $points = $grade * 0.5;
                }

    

            $rows[] = (object) [
                    'userid'       => $uid,
                    'studentname'  => $fullname,
                    'email'        => $email,
                    'courseid'     => (int)$qrec->courseid,
                    'next_due_date'=> $qrec->next_due_date,
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
                    'points'       => $points,
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
        array $duedates = [],
        bool $pastonly = false
    ): array {
        global $DB;

        $rows = [];
        $now = time();

        $snapparams = [];
        $snapsql = "SELECT DISTINCT CONCAT(s.quizid, '_', s.timeclose) AS unique_key, s.quizid, s.timeclose
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

        // Filter by user IDs in the first query to ensure we get all quizzes for filtered users
        $filter_userids = array_filter($userids, function($id) { return $id > 0; });
        if (!empty($filter_userids)) {
            list($usql, $uparams) = $DB->get_in_or_equal($filter_userids, SQL_PARAMS_NAMED, 'suid');
            $snapsql .= " AND s.userid $usql";
            $snapparams = array_merge($snapparams, $uparams);
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
        }

        if ($pastonly) {
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
                // Add 2 hours offset to avoid overlap with previous week's close time
                $windowstart = $timeclose - ($windowdays * 24 * 60 * 60) + 7200;
                $windowend = $timeclose;
                
                // Update windowstart in the object to ensure it's saved
                $snap->windowstart = $windowstart;

                // Fetch attempts in window
                $params = [
                    'quizid' => $quizid,
                    'userid' => $userid,
                    'start'  => $windowstart,
                    'end'    => $windowend
                ];

                $sql = "SELECT qa.id, qa.sumgrades, qa.timefinish, qa.timestart
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
                $valid_attempts = 0;

                // We need the quiz grade to calculate percentage
                $quizgrade = $DB->get_field('quiz', 'grade', ['id' => $quizid]);
                $grade = ($quizgrade > 0.0) ? (float)$quizgrade : 0.0;

                foreach ($attempts as $a) {
                    $timefinish = (int)$a->timefinish;
                    $timestart = (int)$a->timestart; // Assuming timestart is selected in SQL
                    
                    if ($firstfinish === 0 || $timefinish < $firstfinish) {
                        $firstfinish = $timefinish;
                    }
                    if ($timefinish > $lastfinish) {
                        $lastfinish = $timefinish;
                    }

                    // Calculate duration
                    $duration = $timefinish - $timestart;

                    if ($grade > 0.0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $grade) * 100.0;
                        
                        // Filter: Only consider attempts with duration >= 180s AND score > 10%
                        if ($duration >= 180 && $pct > 10.0) {
                            $valid_attempts++;
                            if ($pct > $bestpercent) {
                                $bestpercent = $pct;
                            }
                        }
                    }
                }

                if ($valid_attempts === 0) {
                    $status = 'noattempt';
                } else if ($bestpercent > self::COMPLETION_PERCENT_THRESHOLD) {
                    $status = 'completed';
                } else {
                    $status = 'lowgrade';
                }

                // Calculate Points and Score for Snapshot
                $snap_points = 0.0;
                $snap_score = 0.0;

                if ($status === 'completed') {
                    $snap_points = $grade;
                } elseif ($status === 'lowgrade') {
                    $snap_points = $grade * 0.5;
                }

                if ($grade > 0.0 && $bestpercent > 0.0) {
                    $snap_score = round(($bestpercent / 100.0) * $grade, 2);
                }

                // Update the existing record
                $snap->attempts = $attempts_count;
                $snap->bestpercent = (int)round($bestpercent);
                $snap->firstfinish = $firstfinish ?: null;
                $snap->lastfinish = $lastfinish ?: null;
                $snap->status = $status;
                $snap->computedat = $now;
                $snap->quizgrade = $grade;
                $snap->points = $snap_points;
                $snap->score = $snap_score;
                
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

        // Get snapshot due dates (historical only)
        $now = time();
        $sql = "SELECT DISTINCT timeclose 
                  FROM {local_homework_status} 
                 WHERE timeclose > 0 
                   AND timeclose <= :now 
              ORDER BY timeclose DESC";
        
        $quizdates = $DB->get_fieldset_sql($sql, ['now' => $now]);
        
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

    /**
     * Get quizzes for specific courses and deadline.
     * Used for populating 'Activities 2' column.
     */
    public function get_quizzes_for_deadline(array $courseids, int $timeclose): array {
        global $DB;

        $courseids = array_filter($courseids, function($id) { return $id > 0; });
        if (empty($courseids) || $timeclose <= 0) {
            return [];
        }

        list($csql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($cparams, ['timeclose' => $timeclose]);

        $sql = "SELECT q.id, q.name, cm.id AS cmid, c.fullname AS coursename, cc.name AS categoryname
                  FROM {quiz} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                  JOIN {course} c ON c.id = q.course
                  JOIN {course_categories} cc ON cc.id = c.category
                 WHERE q.course $csql
                   AND q.timeclose = :timeclose
                 ORDER BY CASE WHEN cc.name IN ('Category 1', 'Category 2') THEN 0 ELSE 1 END, q.name";

        $quizzes = $DB->get_records_sql($sql, $params);
        
        $results = [];
        foreach ($quizzes as $q) {
            $q->classification = $this->get_activity_classification((int)$q->cmid) ?? 'New';
            $results[] = $q;
        }

        return $results;
    }

    /**
     * Get parent info (Name, Email, Phone, Language) for a list of users.
     */
    public function get_users_parent_info(array $userids): array {
        global $DB;

        $userids = array_filter($userids, function($id) { return $id > 0; });
        if (empty($userids)) {
            return [];
        }

        // Define the fields we want
        $fields = [
            'parent1name' => 'p1_name',
            'parent1pmail' => 'p1_email',
            'parent1phone' => 'p1_phone',
            'P1_language' => 'p1_lang',
            'parent2name' => 'p2_name',
            'parent2email' => 'p2_email',
            'parent2phone' => 'p2_phone',
            'P2_language' => 'p2_lang'
        ];

        list($usql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        list($fsql, $fparams) = $DB->get_in_or_equal(array_keys($fields), SQL_PARAMS_NAMED, 'sn');

        $sql = "SELECT d.id, d.userid, f.shortname, d.data
                  FROM {user_info_data} d
                  JOIN {user_info_field} f ON f.id = d.fieldid
                 WHERE d.userid $usql
                   AND f.shortname $fsql";
        
        $params = array_merge($uparams, $fparams);
        $records = $DB->get_records_sql($sql, $params);

        $info = [];
        foreach ($userids as $uid) {
            $info[$uid] = (object)[
                'p1_name' => '', 'p1_email' => '', 'p1_phone' => '', 'p1_lang' => '',
                'p2_name' => '', 'p2_email' => '', 'p2_phone' => '', 'p2_lang' => ''
            ];
        }

        foreach ($records as $r) {
            if (isset($info[$r->userid]) && isset($fields[$r->shortname])) {
                $prop = $fields[$r->shortname];
                $info[$r->userid]->$prop = $r->data;
            }
        }

        return $info;
    }

    /**
     * Get all attempts for a specific user and quiz.
     * Used for AI commentary to analyze progress history.
     *
     * @param int $userid
     * @param int $quizid
     * @return array List of attempts with details
     */
    public function get_user_quiz_attempts(int $userid, int $quizid): array {
        global $DB;

        $sql = "SELECT qa.id, qa.attempt, qa.timestart, qa.timefinish, qa.sumgrades
                  FROM {quiz_attempts} qa
                 WHERE qa.quiz = :quizid
                   AND qa.userid = :userid
                   AND qa.state = 'finished'
              ORDER BY qa.attempt ASC";

        return $DB->get_records_sql($sql, ['quizid' => $quizid, 'userid' => $userid]);
    }

    /**
     * Compute snapshots for quizzes that have recently closed.
     * Called by scheduled task.
     */
    public function compute_due_snapshots() {
        global $DB;

        // Process Weekly Revision Snapshots (Active Revisions for past week)
        $this->snapshot_recent_revisions();

        // 1. Find quizzes that closed in the last 24 hours (or since last run).
        // For robustness, we look back 2 days to ensure we don't miss anything if cron failed.
        $now = time();
        $since = $now - (2 * 24 * 60 * 60);

        $sql = "SELECT q.id, q.course, q.timeclose, q.grade, cm.id as cmid
                  FROM {quiz} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE q.timeclose > :since
                   AND q.timeclose <= :now";
        
        $quizzes = $DB->get_records_sql($sql, ['since' => $since, 'now' => $now]);
        
        if (empty($quizzes)) {
            mtrace("No quizzes closed in the last 48 hours.");
            return;
        }

        $inserted = 0;
        foreach ($quizzes as $qrec) {
            $quizid = (int)$qrec->id;
            $courseid = (int)$qrec->course;
            $timeclose = (int)$qrec->timeclose;
            $cmid = (int)$qrec->cmid;

            mtrace("Processing quiz $quizid (Course $courseid, Close $timeclose)...");

            // Get roster
            $roster = $this->get_course_roster($courseid);
            if (empty($roster)) {
                continue;
            }

            // Get existing snapshots for this quiz
            $existing = $DB->get_records('local_homework_status', ['quizid' => $quizid, 'timeclose' => $timeclose], '', 'userid, id, computedat');
            
            // Filter roster: keep users who DON'T have a snapshot OR have a STALE snapshot.
            $users_to_process = [];
            foreach ($roster as $uid) {
                if (!isset($existing[$uid])) {
                    // No snapshot exists -> Process
                    $users_to_process[] = $uid;
                } else {
                    // Snapshot exists. Check if it's stale.
                    // If computedat < timeclose, it means the snapshot was taken before the quiz closed (e.g. due date extended).
                    $rec = $existing[$uid];
                    if ((int)$rec->computedat < $timeclose) {
                        $users_to_process[] = $uid;
                    }
                }
            }
            
            if (empty($users_to_process)) {
                continue;
            }

            // Calculate window
            $windowdays = $this->get_course_window_days($courseid);
            // Add 2 hours offset to avoid overlap with previous week's close time
            $windowstart = $timeclose - ($windowdays * 24 * 60 * 60) + 7200;
            $windowend = $timeclose;

            // Bulk fetch attempts for remaining users
            list($insql, $inparams) = $DB->get_in_or_equal($users_to_process, SQL_PARAMS_NAMED, 'uid');
            $apparams = $inparams;
            $apparams['quizid'] = $quizid;
            $apparams['start'] = $windowstart;
            $apparams['end'] = $windowend;

            $attemptsql = "SELECT qa.id, qa.userid, qa.sumgrades, qa.timestart, qa.timefinish
                             FROM {quiz_attempts} qa
                            WHERE qa.quiz = :quizid
                              AND qa.state = 'finished'
                              AND qa.userid $insql
                              AND qa.timefinish BETWEEN :start AND :end";
            
            $attempts = $DB->get_records_sql($attemptsql, $apparams);

            // Group by user
            $peruser = [];
            $grade = ($qrec->grade > 0.0) ? (float)$qrec->grade : 0.0;

            foreach ($attempts as $a) {
                $uid = (int)$a->userid;
                if (!isset($peruser[$uid])) {
                    $peruser[$uid] = [
                        'attempts' => 0,
                        'valid_attempts' => 0,
                        'bestpercent' => 0.0,
                        'firstfinish' => 0,
                        'lastfinish' => 0
                    ];
                }
                $peruser[$uid]['attempts']++;
                
                $tf = (int)$a->timefinish;
                $ts = (int)$a->timestart;
                $duration = $tf - $ts;

                if ($peruser[$uid]['firstfinish'] === 0 || $tf < $peruser[$uid]['firstfinish']) {
                    $peruser[$uid]['firstfinish'] = $tf;
                }
                if ($tf > $peruser[$uid]['lastfinish']) {
                    $peruser[$uid]['lastfinish'] = $tf;
                }

                // Only consider attempts with duration >= 180s AND score > 10% for best score/status
                if ($duration >= 180) {
                    if ($grade > 0.0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $grade) * 100.0;
                        // Filter: Ignore if score <= 10%
                        if ($pct > 10.0) {
                            $peruser[$uid]['valid_attempts']++;
                            if ($pct > $peruser[$uid]['bestpercent']) {
                                $peruser[$uid]['bestpercent'] = $pct;
                            }
                        }
                    }
                }
            }

            $classification = $this->get_activity_classification($cmid);
            $quiztype = $this->quiz_has_essay($quizid) ? 'Essay' : 'Non-Essay';

            foreach ($users_to_process as $uid) {
                $uid = (int)$uid;
                $summary = $peruser[$uid] ?? [
                    'attempts' => 0,
                    'valid_attempts' => 0,
                    'bestpercent' => 0.0,
                    'firstfinish' => 0,
                    'lastfinish' => 0,
                ];

                // Status logic:
                // 1. No valid attempts (attempts <= 10% or < 180s) -> 'noattempt' (To do)
                // 2. Best score > 30% -> 'completed' (Done)
                // 3. Otherwise (10% < score <= 30%) -> 'lowgrade' (Retry)
                if ($summary['valid_attempts'] === 0) {
                    $status = 'noattempt';
                } else if ($summary['bestpercent'] > self::COMPLETION_PERCENT_THRESHOLD) {
                    $status = 'completed';
                } else {
                    $status = 'lowgrade';
                }

                // Calculate Points and Score for Snapshot
                $snap_points = 0.0;
                $snap_score = 0.0;
                $qgrade = ($qrec->grade > 0.0) ? (float)$qrec->grade : 0.0;

                if ($status === 'completed') {
                    $snap_points = $qgrade;
                } elseif ($status === 'lowgrade') {
                    $snap_points = $qgrade * 0.5;
                }

                if ($qgrade > 0.0 && $summary['bestpercent'] > 0) {
                    $snap_score = ($summary['bestpercent'] / 100.0) * $qgrade;
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
                    'quizgrade'      => $qgrade,
                    'points'         => $snap_points,
                    'score'          => $snap_score,
                    'computedat'     => $now,
                ];

                if (isset($existing[$uid])) {
                    $record->id = $existing[$uid]->id;
                    $DB->update_record('local_homework_status', $record);
                } else {
                    $record->id = $DB->insert_record('local_homework_status', $record);
                }
                $inserted++;
            }
        }
        mtrace("Inserted $inserted new snapshot records.");
        return $inserted;
    }

    /**
     * Delete a snapshot record and its associated reports.
     *
     * @param int $snapshotid
     * @return bool
     */
    public function delete_snapshot(int $snapshotid): bool {
        global $DB;

        $snapshot = $DB->get_record('local_homework_status', ['id' => $snapshotid]);
        if (!$snapshot) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // Delete associated reports (child rows)
            $DB->delete_records('local_homework_reports', [
                'userid' => $snapshot->userid,
                'timeclose' => $snapshot->timeclose
            ]);

            // Delete the snapshot itself
            $DB->delete_records('local_homework_status', ['id' => $snapshotid]);

            $transaction->allow_commit();
            return true;
        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    /**
     * Get global Intellect Points for users.
     * This aggregates ALL points for each user across the entire system.
     * Used for gamification features like levels and items.
     *
     * @param array $userids Optional array of user IDs to filter. Empty = all users.
     * @return array [userid => total_intellect_points]
     */
    public function get_intellect_points(array $userids = []): array {
        global $DB;
        
        $params = [];
        $userwhere = '';
        
        // Filter by specific users if provided
        $userids = array_filter($userids, function($id) { return $id > 0; });
        if (!empty($userids)) {
            list($usql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $userwhere = " AND lhs.userid $usql";
            $params = array_merge($params, $uparams);
        }
        
        // Aggregate all points from historical snapshots (global, all time)
        $sql = "SELECT lhs.userid, SUM(lhs.points) AS total_points
                FROM {local_homework_status} lhs
                JOIN {user} u ON u.id = lhs.userid AND u.deleted = 0
                WHERE 1=1 $userwhere
                GROUP BY lhs.userid";
        
        $results = $DB->get_records_sql($sql, $params);
        
        $intellect_points = [];
        foreach ($results as $row) {
            $intellect_points[$row->userid] = (float)$row->total_points;
        }
        
        return $intellect_points;
    }

    /**
     * Get leaderboard data: Aggregated points for students across different time periods.
     * 
     * NEW LOGIC (Dec 2025):
     * - One row per user per category (not per course)
     * - "Personal Review Courses" category merges into Category 1 anchor
     * - Sorted by category name, then student name
     * - Live = points from quizzes not yet closed
     * - 2 Weeks = Live + 1 most recent due date
     * - 4 Weeks = Live + 3 most recent due dates
     * - 10 Weeks = Live + 7 most recent due dates
     * - All Time = Live + ALL due dates
     *
     * @param int $categoryid Filter by course category (0 for all).
     * @param array $courseids Filter by specific course IDs ([0] for all).
     * @param bool $excludestaff Whether to exclude users with 'staff' in their email.
     * @return array List of objects with user details, badges, and point columns.
     */
    public function get_leaderboard_data(int $categoryid, array $courseids, bool $excludestaff, array $userids = []): array {
        global $DB;

        $now = time();
        $personal_review_category_name = 'Personal Review Courses';

        // Build user filter
        $userwhere = "";
        $user_params = [];
        if (!empty($userids) && !in_array(0, $userids)) {
            list($user_insql, $user_params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $userwhere = "AND u.id $user_insql";
        }

        // Build staff exclusion filter (role-based, not email-based)
        $staffwhere = "";
        $staff_params = [];
        if ($excludestaff) {
            // Get all users with staff roles (manager, editingteacher, teacher) or site admins
            $excluded_staff_ids = [];
            $admins = \get_admins();
            foreach ($admins as $admin) {
                $excluded_staff_ids[] = (int)$admin->id;
            }
            
            $staff_sql = "SELECT DISTINCT ra.userid
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid
                          WHERE r.shortname IN ('manager', 'editingteacher', 'teacher')";
            $staff_users = $DB->get_fieldset_sql($staff_sql);
            $excluded_staff_ids = array_unique(array_merge($excluded_staff_ids, array_map('intval', $staff_users)));
            
            if (!empty($excluded_staff_ids)) {
                list($staff_insql, $staff_params) = $DB->get_in_or_equal($excluded_staff_ids, SQL_PARAMS_NAMED, 'staff', false); // false = NOT IN
                $staffwhere = "AND u.id $staff_insql";
            }
        }

        // Build course filter
        $course_filter_sql = "";
        $course_params = [];
        if (!empty($courseids) && !in_array(0, $courseids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
            $course_filter_sql = "AND c.id $insql";
            $course_params = $inparams;
        }

        // =====================================================
        // STEP 1: Get distinct due dates PER ANCHOR COURSE from snapshots
        // We'll build this after we know the anchor courses (moved to Step 4b)
        // =====================================================

        // =====================================================
        // STEP 2: Fetch LIVE data (quizzes not yet closed)
        // =====================================================
        $live_sql = "
            SELECT
                " . $DB->sql_concat('u.id', "'_'", 'q.id') . " AS unique_key,
                u.id AS userid,
                u.firstname,
                u.lastname,
                u.firstnamephonetic,
                u.lastnamephonetic,
                u.middlename,
                u.alternatename,
                u.email,
                u.idnumber,
                COALESCE(sc.id, c.id) AS courseid,
                COALESCE(sc.fullname, c.fullname) AS coursename,
                COALESCE(sc.category, cc.id) AS categoryid,
                COALESCE(pq.sourcecategory, cc.name) AS categoryname,
                q.id AS quizid,
                q.name AS quizname,
                q.timeclose,
                q.grade AS points
            FROM {quiz} q
            JOIN {course} c ON c.id = q.course
            JOIN {course_categories} cc ON cc.id = c.category
            LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = q.id
            LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
            JOIN {course_modules} cm ON cm.instance = q.id
            JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
            JOIN {user_enrolments} ue ON ue.enrolid IN (SELECT id FROM {enrol} WHERE courseid = c.id)
            JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
            WHERE q.timeclose > :now
              AND q.timeclose IS NOT NULL
              AND q.timeclose > 0
              $course_filter_sql
              $staffwhere
              $userwhere
        ";
        $live_params = array_merge(['now' => $now], $course_params, $staff_params, $user_params);
        $live_rows = $DB->get_records_sql($live_sql, $live_params);

        // =====================================================
        // STEP 3: Fetch SNAPSHOT data (historical)
        // =====================================================
        $snap_sql = "
            SELECT
                " . $DB->sql_concat('u.id', "'_'", 'lbs.quizid', "'_'", 'lbs.timeclose') . " AS unique_key,
                lbs.id,
                lbs.userid,
                u.firstname,
                u.lastname,
                u.firstnamephonetic,
                u.lastnamephonetic,
                u.middlename,
                u.alternatename,
                u.email,
                u.idnumber,
                COALESCE(sc.id, lbs.courseid) AS courseid,
                COALESCE(sc.fullname, c.fullname) AS coursename,
                COALESCE(sc.category, cc.id) AS categoryid,
                COALESCE(pq.sourcecategory, cc.name) AS categoryname,
                lbs.timeclose AS due_date,
                lbs.points AS points,
                lbs.status AS status,
                lbs.classification AS classification,
                lbs.quizgrade AS quizgrade,
                lbs.score AS score,
                lbs.bestpercent AS bestpercent,
                lbs.firstfinish AS firstfinish,
                lbs.lastfinish AS lastfinish,
                lbs.windowstart AS windowstart,
                q.name AS quizname,
                q.grade AS quiz_grade_live
            FROM {local_homework_status} lbs
            JOIN {user} u ON u.id = lbs.userid
            JOIN {course} c ON c.id = lbs.courseid
            JOIN {course_categories} cc ON c.category = cc.id
            LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = lbs.quizid
            LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
            LEFT JOIN {quiz} q ON q.id = lbs.quizid
            WHERE u.deleted = 0
              $course_filter_sql
              $staffwhere
              $userwhere
        ";
        $snap_rows = $DB->get_records_sql($snap_sql, array_merge($course_params, $staff_params, $user_params));

        // =====================================================
        // STEP 4: Build anchor map (user -> category -> course)
        // =====================================================
        $user_anchors = [];
        $all_rows = array_merge($live_rows, $snap_rows);
        
        foreach ($all_rows as $r) {
            $uid = $r->userid;
            $catid = (int)$r->categoryid;
            $catname = $r->categoryname ?? '';
            
            // Restore Anchor Filter: Skip Personal Review category so it doesn't become an anchor
            if (strcasecmp($catname, $personal_review_category_name) === 0) {
                continue;
            }
            
            if (!isset($user_anchors[$uid][$catid])) {
                $user_anchors[$uid][$catid] = [
                    'courseid' => (int)$r->courseid,
                    'coursename' => $r->coursename,
                    'categoryid' => $catid,
                    'categoryname' => $catname,
                ];
            }
        }

        // =====================================================
        // STEP 4b: Build per-user-course due date buckets
        // Each user has their own set of due dates for bucketing
        // =====================================================
        $user_due_dates = []; // [userid_courseid => [due_date1, due_date2, ...] DESC]
        
        // Build user+course keys from snap_rows
        $user_course_keys = [];
        foreach ($snap_rows as $r) {
            $key = $r->userid . '_' . $r->courseid;
            $user_course_keys[$key] = ['userid' => $r->userid, 'courseid' => $r->courseid];
        }
        
        // For each user+course, get their distinct due dates from snapshots
        foreach ($user_course_keys as $key => $info) {
            // Fix: EXCLUDE 'Revision Note' dates if in future.
            // We want historical buckets (2W, 4W) to only reflect PAST due dates.
            // Future revision notes should typically appear in LIVE column if anywhere.
            $user_dates_sql = "SELECT DISTINCT lbs.timeclose 
                               FROM {local_homework_status} lbs 
                               WHERE lbs.userid = :userid
                                 AND lbs.courseid = :courseid
                                 AND lbs.timeclose < :now
                               ORDER BY lbs.timeclose DESC";
            $user_due_dates[$key] = $DB->get_fieldset_sql($user_dates_sql, [
                'userid' => $info['userid'],
                'courseid' => $info['courseid'],
                'now' => $now
            ]);
        }

        // =====================================================
        // STEP 5: Aggregate data - one row per user per category
        // =====================================================
        $aggregated = [];
        
        // Helper to get or create aggregated row
        $get_or_create_row = function($uid, $catid, $catname, $r, &$aggregated, $user_anchors) {
            global $DB; // Required for DB access inside closure
            $key = $uid . '_' . $catid;
            
            if (!isset($aggregated[$key])) {
                $anchor = $user_anchors[$uid][$catid] ?? null;
                $anchor_coursename = $anchor ? $anchor['coursename'] : ($r->coursename ?? 'Unknown');
                
                // DATA REPAIR: Calculate TRUE Latest Past Due Date
                // "Latest Due Date" intends to show the most recent HISTORICAL due date (e.g., Jan 6), not the future one (Jan 13).
                // We search for the latest quiz close date that is < NOW.
                $now = time();
                $past_anchor_date = 0;
                
                // Reuse the anchor course logic
                $anchor_info = $this->get_anchor_course_for_user($uid, $catid, $catname);
                if ($anchor_info) {
                    $anchor_cid = (int)$anchor_info->id;
                    $moduleid = $DB->get_field('modules', 'id', ['name' => 'quiz']);
                    
                    // Find latest PAEST due date
                    $sql_past = "SELECT MAX(q.timeclose) 
                                 FROM {course_modules} cm
                                 JOIN {quiz} q ON q.id = cm.instance
                                 WHERE cm.course = :courseid 
                                   AND cm.module = :mid 
                                   AND q.timeclose < :now
                                   AND q.timeclose > 0";
                    $past_anchor_date = (int)$DB->get_field_sql($sql_past, ['courseid' => $anchor_cid, 'mid' => $moduleid, 'now' => $now]);
                    
                    // Fallback to course-specific rule if no past quizzes found (e.g. relying on manual recurring logic)
                    if (!$past_anchor_date) {
                         // Calculate based on "Last Week" relative to now
                         // calculate_course_fallback_date returns logic for "Next X". 
                         // To get "Last X", we might simply subtract 7 days from "Next X"? 
                         // Or call it with a past base date.
                         $next_fallback = $this->calculate_course_fallback_date($anchor_info, $now);
                         if ($next_fallback) {
                             $past_anchor_date = $next_fallback - (7 * 24 * 60 * 60);
                         }
                    }
                }

                $aggregated[$key] = (object)[
                    'userid' => $uid,
                    'fullname' => fullname((object)[
                        'firstname' => $r->firstname ?? '', 
                        'lastname' => $r->lastname ?? '',
                        'firstnamephonetic' => $r->firstnamephonetic ?? '',
                        'lastnamephonetic' => $r->lastnamephonetic ?? '',
                        'middlename' => $r->middlename ?? '',
                        'alternatename' => $r->alternatename ?? '',
                    ]),
                    'idnumber' => $r->idnumber ?? '',
                    'categoryid' => $catid,
                    'categoryname' => $catname,
                    'anchor_courseid' => $anchor ? $anchor['courseid'] : (int)$r->courseid,
                    'anchor_coursename' => $anchor_coursename,
                    'latest_due_date' => $past_anchor_date, // Initialize with Past Anchor
                    'live_due_date' => 0,
                    'points_live' => 0,
                    'points_2w' => 0,
                    'points_4w' => 0,
                    'points_10w' => 0,
                    'points_all' => 0,
                    // Max possible points for each period
                    'max_live' => 0,
                    'max_2w' => 0,
                    'max_4w' => 0,
                    'max_10w' => 0,
                    'max_all' => 0,
                    'courses' => [],
                    // Breakdown details for tooltip/modal
                    'breakdown_live' => [],
                    'breakdown_2w' => [],
                    'breakdown_4w' => [],
                    'breakdown_10w' => [],
                    'breakdown_all' => [],
                    // Due dates included in each period (for tooltip)
                    'duedates_live' => [],
                    'duedates_2w' => [],
                    'duedates_4w' => [],
                    'duedates_10w' => [],
                ];
            }
            return $aggregated[$key];
        };

        // Process LIVE rows - fetch actual homework status for each user/quiz
        // Group live_rows by user to fetch their actual attempt data
        $live_user_quizzes = [];
        foreach ($live_rows as $r) {
            $uid = $r->userid;
            if (!isset($live_user_quizzes[$uid])) {
                $live_user_quizzes[$uid] = [];
            }
            $live_user_quizzes[$uid][] = $r;
        }
        
        foreach ($live_user_quizzes as $uid => $user_live_rows) {
            foreach ($user_live_rows as $r) {
                $catid = (int)$r->categoryid;
                $catname = $r->categoryname ?? '';
                $cid = (int)$r->courseid;
                $quizid = (int)$r->quizid;
                $live_timeclose = (int)$r->timeclose;
                
                // Personal Review merges into Category 1
                if (strcasecmp($catname, $personal_review_category_name) === 0) {
                    $cat1_anchor = null;
                    foreach ($user_anchors[$uid] ?? [] as $anchor_info) {
                        if (strcasecmp($anchor_info['categoryname'], 'Category 1') === 0) {
                            $cat1_anchor = $anchor_info;
                            break;
                        }
                    }
                    if (!$cat1_anchor) continue;
                    $catid = $cat1_anchor['categoryid'];
                    $catname = $cat1_anchor['categoryname'];
                }
                
                $row = $get_or_create_row($uid, $catid, $catname, $r, $aggregated, $user_anchors);
                
                // Fetch actual attempt data for this user/quiz (same logic as Live tab)
                $windowdays = $this->get_course_window_days($cid);
                [$windowstart, $windowend] = $this->build_window($live_timeclose, $windowdays);
                
                // Get ALL attempts for this user/quiz within window
                $attempt_sql = "SELECT qa.id, qa.userid, qa.sumgrades, qa.timestart, qa.timefinish
                                FROM {quiz_attempts} qa
                                WHERE qa.quiz = :quizid
                                  AND qa.userid = :userid
                                  AND qa.state = 'finished'
                                  AND qa.timefinish BETWEEN :start AND :end";
                $attempts = $DB->get_records_sql($attempt_sql, [
                    'quizid' => $quizid,
                    'userid' => $uid,
                    'start' => $windowstart,
                    'end' => $windowend
                ]);
                
                // Get classification
                $cmid = $DB->get_field('course_modules', 'id', ['instance' => $quizid, 'course' => $cid]);
                $classification = $cmid ? ($this->get_activity_classification($cmid) ?? '-') : '-';
                
                // Calculate status using same logic as Live tab
                $quizgrade = (float)$r->points; // This is q.grade
                $status = 'noattempt';
                $duration = '-';
                $score_display = '-';
                $score_percent = '-';
                $pts = 0;
                $bestpercent = 0.0;
                $bestattempt = null;
                
                // Find best valid attempt (duration >= 180s AND score > 10%)
                foreach ($attempts as $a) {
                    $timestart = (int)$a->timestart;
                    $timefinish = (int)$a->timefinish;
                    if ($timestart <= 0 || $timefinish <= $timestart) {
                        continue;
                    }
                    $dur = $timefinish - $timestart;
                    if ($dur >= 180 && $quizgrade > 0 && $a->sumgrades !== null) {
                        $pct = ((float)$a->sumgrades / $quizgrade) * 100.0;
                        if ($pct > 10.0 && $pct > $bestpercent) {
                            $bestpercent = $pct;
                            $bestattempt = $a;
                        }
                    }
                }
                
                // Determine status based on best attempt
                if ($bestattempt) {
                    $duration_seconds = (int)$bestattempt->timefinish - (int)$bestattempt->timestart;
                    $duration = gmdate('H:i:s', $duration_seconds);
                    $score_raw = (float)$bestattempt->sumgrades;
                    $score_display = round($score_raw, 1) . '/' . round($quizgrade, 1);
                    $score_percent = round($bestpercent, 0) . '%';
                    
                    if ($bestpercent > self::COMPLETION_PERCENT_THRESHOLD) {
                        $status = 'completed';
                        $pts = $quizgrade;
                    } else {
                        $status = 'lowgrade';
                        $pts = $quizgrade * 0.5;
                    }
                }
                
                // Track live due date
                if ($row->live_due_date == 0 || $live_timeclose < $row->live_due_date) {
                    $row->live_due_date = $live_timeclose;
                }
                
                $row->points_live += $pts;
                $row->points_2w += $pts;
                $row->points_4w += $pts;
                $row->points_10w += $pts;
                $row->points_all += $pts;
                
                // Add max possible points (quizgrade) for each period
                $row->max_live += $quizgrade;
                $row->max_2w += $quizgrade;
                $row->max_4w += $quizgrade;
                $row->max_10w += $quizgrade;
                $row->max_all += $quizgrade;
                
                // Add breakdown detail for LIVE with actual attempt data
                $detail = [
                    'due_date' => $live_timeclose,
                    'due_date_formatted' => userdate($live_timeclose, get_string('strftimedate')),
                    'course_name' => $r->coursename ?? '',
                    'quiz_name' => $r->quizname ?? 'Live Quiz',
                    'classification' => $classification,
                    'status' => $status,
                    'duration' => $duration,
                    'score_display' => $score_display,
                    'score_percent' => $score_percent,
                    'points' => $pts,
                ];
                $row->breakdown_live[] = $detail;
                $row->breakdown_2w[] = $detail;
                $row->breakdown_4w[] = $detail;
                $row->breakdown_10w[] = $detail;
                $row->breakdown_all[] = $detail;
                
                // Track due dates
                if (!in_array($live_timeclose, $row->duedates_live)) {
                    $row->duedates_live[] = $live_timeclose;
                }
                
                if (!isset($row->courses[$cid])) {
                    $row->courses[$cid] = ['name' => $r->coursename, 'categoryname' => $r->categoryname ?? ''];
                }
            }
        }

        // =====================================================
        // STEP 2.5: Process ACTIVE REVISION (Weekly Reflection Points)
        // =====================================================
        // Revisions count if they happen within the current active week
        [$rev_start, $rev_end] = $this->get_latest_sunday_week();

        // 1. Fetch revision points (notes with points_earned > 0) in this window
        // Group by user and quiz to create "Active Revision" rows
        $rev_sql = "
            SELECT
                " . $DB->sql_concat('lqf.userid', "'_'", 'q.id') . " AS unique_key,
                lqf.userid,
                q.id AS quizid,
                q.name AS quizname,
                COALESCE(sc.id, c.id) AS courseid,
                COALESCE(sc.fullname, c.fullname) AS coursename,
                COALESCE(sc.category, cc.id) AS categoryid,
                COALESCE(pq.sourcecategory, cc.name) AS categoryname,
                SUM(lqf.points_earned) AS total_points,
                MAX(lqf.timemodified) AS last_modified,
                COUNT(lqf.id) AS note_count
            FROM {local_questionflags} lqf
            JOIN {quiz} q ON q.id = lqf.quizid
            JOIN {course} c ON c.id = q.course
            JOIN {course_categories} cc ON cc.id = c.category
            LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = q.id
            LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
            WHERE lqf.timemodified BETWEEN :start AND :end
              AND lqf.points_earned > 0
              $course_filter_sql
            GROUP BY lqf.userid, q.id, q.name, c.id, c.fullname, cc.id, cc.name, pq.sourcecategory, sc.id, sc.category, sc.fullname
        ";

        $rev_params = array_merge(['start' => $rev_start, 'end' => $rev_end], $course_params);
        
        // Add User/Staff filters if needed
        if ($userwhere) {
            $rev_sql .= " HAVING lqf.userid $user_insql"; // HAVING because it's after GROUP BY, though WHERE is better if possible. 
                                                          // Actually, lqf.userid is available in WHERE. 
                                                          // Let's safe-move it to WHERE for performance.
            // Simplified: $userwhere uses 'u.id', here we have 'lqf.userid'.
            // I'll rebuild the filter for lqf.
        }

        // Re-construct robust WHERE clauses
        $rev_where = "lqf.timemodified BETWEEN :start AND :end AND lqf.points_earned > 0";
        if (!empty($courseids) && !in_array(0, $courseids)) {
             $rev_where .= " AND c.id $insql"; // reusing $insql from line 1972
        }
        
        // User Filter
        if (!empty($userids) && !in_array(0, $userids)) {
            list($rus_sql, $rus_params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ruid');
            $rev_where .= " AND lqf.userid $rus_sql";
            $rev_params = array_merge($rev_params, $rus_params);
        }
        
        // Staff Filter
        if ($excludestaff && !empty($excluded_staff_ids)) {
            list($rs_sql, $rs_params) = $DB->get_in_or_equal($excluded_staff_ids, SQL_PARAMS_NAMED, 'rstaff', false);
            $rev_where .= " AND lqf.userid $rs_sql";
            $rev_params = array_merge($rev_params, $rs_params);
        }

        $rev_sql = "
            SELECT
                " . $DB->sql_concat('lqf.userid', "'_'", 'q.id') . " AS unique_key,
                lqf.userid,
                u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.idnumber,
                q.id AS quizid,
                q.name AS quizname,
                COALESCE(sc.id, c.id) AS courseid,
                COALESCE(sc.fullname, c.fullname) AS coursename,
                COALESCE(sc.category, cc.id) AS categoryid,
                COALESCE(pq.sourcecategory, cc.name) AS categoryname,
                SUM(lqf.points_earned) AS total_points,
                MAX(lqf.timemodified) AS last_modified,
                COUNT(lqf.id) AS note_count
            FROM {local_questionflags} lqf
            JOIN {user} u ON u.id = lqf.userid
            JOIN {quiz} q ON q.id = lqf.quizid
            JOIN {course} c ON c.id = q.course
            JOIN {course_categories} cc ON cc.id = c.category
            LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = q.id
            LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
            WHERE $rev_where
            GROUP BY lqf.userid, u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.idnumber, 
                     q.id, q.name, c.id, c.fullname, cc.id, cc.name, pq.sourcecategory, sc.id, sc.category, sc.fullname
        ";
        
        $rev_rows = $DB->get_records_sql($rev_sql, $rev_params);

        // 2. Merge revisions into aggregation
        foreach ($rev_rows as $r) {
            $uid = $r->userid;
            $catid = (int)$r->categoryid;
            $catname = $r->categoryname ?? '';
            $cid = (int)$r->courseid;
            
            // Personal Review merges into Category 1
            if (strcasecmp($catname, $personal_review_category_name) === 0) {
                 $cat1_anchor = null;
                 foreach ($user_anchors[$uid] ?? [] as $anchor_info) {
                     if (strcasecmp($anchor_info['categoryname'], 'Category 1') === 0) {
                         $cat1_anchor = $anchor_info;
                         break;
                     }
                 }
                 if (!$cat1_anchor) continue;
                 $catid = $cat1_anchor['categoryid'];
                 $catname = $cat1_anchor['categoryname'];
            }

            $row = $get_or_create_row($uid, $catid, $catname, $r, $aggregated, $user_anchors);
            
            $pts = (float)$r->total_points;
            
            // Add to ALL relevant columns (Live, +2w, etc.) because it is "Active" (happening now)
            $row->points_live += $pts;
            $row->points_2w += $pts;
            $row->points_4w += $pts;
            $row->points_10w += $pts;
            $row->points_all += $pts;
            
            $anchor_due_date = $this->get_student_anchor_due_date($uid, $catid, $catname, $rev_start);
            if ($anchor_due_date <= 0) {
                $anchor_due_date = $rev_end; // Fallback to Week End
            }

            // Detail row
            $detail = [
                'due_date' => $anchor_due_date, // Align to Anchor Date
                'due_date_formatted' => $anchor_due_date > 0 ? userdate($anchor_due_date, get_string('strftimedate')) : '-',
                'course_name' => $r->coursename,
                'quiz_name' => $r->quizname, // Match snapshot naming
                'classification' => 'Revision Note',
                'status' => $r->note_count . ' Notes Added',
                'duration' => '-',
                'score_display' => $r->total_points . ' pts', // Show points directly
                'score_percent' => '-',
                'points' => $pts,
                'windowstart' => $rev_start // Important for deduplication
            ];
            
            // Add to all breakdown arrays
            $row->breakdown_live[] = $detail;
            $row->breakdown_2w[] = $detail;
            $row->breakdown_4w[] = $detail;
            $row->breakdown_10w[] = $detail;
            $row->breakdown_all[] = $detail;
            
            if (!isset($row->courses[$cid])) {
                $row->courses[$cid] = ['name' => $r->coursename, 'categoryname' => $r->categoryname ?? ''];
            }
        }

        // Process SNAPSHOT rows (add to appropriate time buckets)
        foreach ($snap_rows as $r) {
            $uid = $r->userid;
            $catid = (int)$r->categoryid;
            $catname = $r->categoryname ?? '';
            $cid = (int)$r->courseid;
            $due_date = (int)$r->due_date;
            
            // Personal Review merges into Category 1 (Restored as fallback for unmapped quizzes)
            if (strcasecmp($catname, $personal_review_category_name) === 0) {
                $cat1_anchor = null;
                foreach ($user_anchors[$uid] ?? [] as $anchor_info) {
                    if (strcasecmp($anchor_info['categoryname'], 'Category 1') === 0) {
                        $cat1_anchor = $anchor_info;
                        break;
                    }
                }
                if ($cat1_anchor) {
                    $catid = $cat1_anchor['categoryid'];
                    $catname = $cat1_anchor['categoryname'];
                }
            }
            
            $row = $get_or_create_row($uid, $catid, $catname, $r, $aggregated, $user_anchors);
            
            // Get points - use stored value, or fallback to calculation if quizgrade was 0
            $pts = (float)$r->points;
            if ($pts == 0 && !empty($r->status) && $r->status !== 'noattempt') {
                // Fallback: calculate from quiz grade if stored points is 0
                $fallback_grade = (float)($r->quizgrade ?? 0);
                if ($fallback_grade == 0) {
                    // Try to get from joined quiz table
                    $fallback_grade = isset($r->quiz_grade_live) ? (float)$r->quiz_grade_live : 0;
                }
                if ($fallback_grade > 0) {
                    if ($r->status === 'completed') {
                        $pts = $fallback_grade;
                    } elseif ($r->status === 'lowgrade') {
                        $pts = $fallback_grade * 0.5;
                    }
                }
            }
            
            // Update latest due date
            // Fix: Only track historical due dates here (<= NOW), ignore future upcoming.
            if ($due_date <= time() && $due_date > $row->latest_due_date) {
                $row->latest_due_date = $due_date;
            }
            
            // Build detail record for breakdown
            // Calculate duration if we have finish times
            $duration_seconds = 0;
            if (!empty($r->firstfinish) && !empty($r->windowstart) && $r->firstfinish > $r->windowstart) {
                $duration_seconds = (int)$r->firstfinish - (int)$r->windowstart;
            }
            $duration_formatted = $duration_seconds > 0 ? gmdate('H:i:s', $duration_seconds) : '-';
            
            // Score display
            $score_raw = (float)($r->score ?? 0);
            $quizgrade = (float)($r->quizgrade ?? 0);
            
            if (($r->classification ?? '') === 'Revision Note') {
                $score_display = $pts . ' pts';
                $score_percent = '-';
            } else {
                $score_display = $quizgrade > 0 ? round($score_raw, 1) . '/' . round($quizgrade, 1) : '-';
                $score_percent = round((float)($r->bestpercent ?? 0), 0) . '%';
            }
            
            $detail = [
                'due_date' => $due_date,
                'due_date_formatted' => userdate($due_date, get_string('strftimedate')),
                'course_name' => $r->coursename ?? '',
                'quiz_name' => $r->quizname ?? 'Quiz',
                'classification' => $r->classification ?? '-',
                'status' => $r->status ?? 'unknown',
                'duration' => $duration_formatted,
                'score_display' => $score_display,
                'score_percent' => $score_percent,
                'points' => $pts,
                'windowstart' => $r->windowstart ?? 0 // From DB snapshot
            ];
            
            // Get the user's due dates for this course to determine buckets
            $user_course_key = $uid . '_' . $cid;
            $user_dates = $user_due_dates[$user_course_key] ?? [];
            
            // Define buckets based on user's own due dates for this course
            $due_dates_2w = array_slice($user_dates, 0, 1);  // 1 most recent due date for this user
            $due_dates_4w = array_slice($user_dates, 0, 3);  // 3 most recent due dates
            $due_dates_10w = array_slice($user_dates, 0, 9); // 9 most recent due dates
            
            // Get max grade for this quiz (use stored quizgrade or fallback to live)
            $max_grade = $quizgrade;
            if ($max_grade == 0 && isset($r->quiz_grade_live)) {
                $max_grade = (float)$r->quiz_grade_live;
            }
            
            // Add to Live bucket if future dated (e.g. Anchor Date Revision)
            if ($due_date > $now) {
                $row->points_live += $pts;
                $row->max_live += $max_grade;
                $row->breakdown_live[] = $detail;
                // Track live due date
                if ($row->live_due_date == 0 || $due_date < $row->live_due_date) {
                    $row->live_due_date = $due_date;
                }
                if (!in_array($due_date, $row->duedates_live)) {
                   $row->duedates_live[] = $due_date;
                }
            }

            // Add to appropriate buckets based on due date
            if (in_array($due_date, $due_dates_2w)) {
                $row->points_2w += $pts;
                $row->max_2w += $max_grade;
                $row->breakdown_2w[] = $detail;
                if (!in_array($due_date, $row->duedates_2w)) {
                    $row->duedates_2w[] = $due_date;
                }
            }
            if (in_array($due_date, $due_dates_4w)) {
                $row->points_4w += $pts;
                $row->max_4w += $max_grade;
                $row->breakdown_4w[] = $detail;
                if (!in_array($due_date, $row->duedates_4w)) {
                    $row->duedates_4w[] = $due_date;
                }
            }
            if (in_array($due_date, $due_dates_10w)) {
                $row->points_10w += $pts;
                $row->max_10w += $max_grade;
                if (!in_array($due_date, $row->duedates_10w)) {
                    $row->duedates_10w[] = $due_date;
                }
            }
            $row->points_all += $pts;
            $row->max_all += $max_grade;
            
            if (!isset($row->courses[$cid])) {
                $row->courses[$cid] = ['name' => $r->coursename, 'categoryname' => $r->categoryname ?? ''];
            }
        }

        // =====================================================
        // STEP 6: Apply category filter
        // =====================================================
        if ($categoryid > 0) {
            $aggregated = array_filter($aggregated, function($row) use ($categoryid) {
                return (int)$row->categoryid === $categoryid;
            });
        }

        // =====================================================
        // STEP 7: Sort by category name, then student name
        // =====================================================
        $final_rows = array_values($aggregated);
        usort($final_rows, function($a, $b) {
            $cat_cmp = strcasecmp($a->categoryname, $b->categoryname);
            if ($cat_cmp !== 0) return $cat_cmp;
            return strcasecmp($a->fullname, $b->fullname);
        });

        // =====================================================
        // STEP 7.5: Consolidate Revision Notes with same Name + Date
        // =====================================================
        // Because "Live" notes (current week) and "Snapshot" notes (past weeks) 
        // might both map to the same future Anchor Date, they appear as duplicates.
        // We merge them here for cleaner display.
        $consolidate_revisions = function(array $items) {
            $merged = [];
            $by_key = [];

            foreach ($items as $item) {
                // Only merge Revision Notes
                if (($item['classification'] ?? '') !== 'Revision Note') {
                    $merged[] = $item;
                    continue;
                }

                // Key by Name + Date
                $key = ($item['quiz_name'] ?? '') . '|' . ($item['due_date'] ?? 0);
                
                if (!isset($by_key[$key])) {
                    $by_key[$key] = $item;
                    $merged[] = &$by_key[$key];
                } else {
                    // Merge into existing
                    $existing = &$by_key[$key];
                    
                    $ws_existing = $existing['windowstart'] ?? 0;
                    $ws_new = $item['windowstart'] ?? 0;
                    
                    // Logic:
                    // If Windows match (Same Week) -> Use MAX (Deduplicate)
                    // If Windows differ (Different Weeks) -> Use SUM (Accumulate)
                    
                    if ($ws_existing == $ws_new) {
                        // SAME WEEK: Use High Score (Max)
                         $pts_existing = $existing['points'];
                         $pts_new = $item['points'];
                         
                         if ($pts_new > $pts_existing) {
                             $existing['points'] = $pts_new;
                             $existing['score_display'] = $pts_new . ' pts';
                             $existing['status'] = ($item['status'] ?? '0 Notes Added');
                         }
                    } else {
                        // DIFFERENT WEEKS: Accumulate
                        $existing['points'] += $item['points'];
                        
                        // Sum Status (Counts)
                        $count_existing = (int)($existing['status']);
                        $count_new = (int)($item['status']);
                        $existing['status'] = ($count_existing + $count_new) . ' Notes Added';
                        
                        $existing['score_display'] = $existing['points'] . ' pts';
                    }
                }
            }
            return $merged;
        };

        foreach ($final_rows as &$row) {
            if (!empty($row->breakdown_2w)) {
                $row->breakdown_2w = $consolidate_revisions($row->breakdown_2w);
            }
            if (!empty($row->breakdown_4w)) {
                $row->breakdown_4w = $consolidate_revisions($row->breakdown_4w);
            }
            if (!empty($row->breakdown_live)) { // Also consolidate live if needed
                 $row->breakdown_live = $consolidate_revisions($row->breakdown_live);
            }
        }
        unset($row);

        // =====================================================
        // STEP 8: Sort breakdown details (Date DESC, then New before Revision)
        // =====================================================
        foreach ($final_rows as &$row) {
            $sort_breakdown = function($a, $b) {
                // Primary sort: due date DESC
                if ($a['due_date'] !== $b['due_date']) {
                    return $b['due_date'] <=> $a['due_date'];
                }
                
                // Secondary sort: Quiz Name (ASC) - Group same quizzes together
                $name_a = strtoupper(trim((string)($a['quiz_name'] ?? '')));
                $name_b = strtoupper(trim((string)($b['quiz_name'] ?? '')));
                $cmp_name = strcmp($name_a, $name_b);
                if ($cmp_name !== 0) {
                    return $cmp_name;
                }

                // Tertiary sort: classification (New < Revision)
                $class_a = strtoupper(trim((string)($a['classification'] ?? '')));
                $class_b = strtoupper(trim((string)($b['classification'] ?? '')));
                
                return strcmp($class_a, $class_b);
            };

            if (!empty($row->breakdown_2w)) {
                usort($row->breakdown_2w, $sort_breakdown);
            }
            if (!empty($row->breakdown_4w)) {
                usort($row->breakdown_4w, $sort_breakdown);
            }
        }
        unset($row);

        return $final_rows;
    }
    /**
     * Snapshot Active Revision points for the most recently completed week.
     * This ensures granular revision history is permanently recorded.
     */
    public function snapshot_recent_revisions() {
        global $DB;
        
        // 1. Snapshot CURRENT Active Week (Immediate Persistence)
        // We capture everything from the start of the week up to NOW.
        
        [$start, $end] = $this->get_latest_sunday_week(); 
        
        // SNAPSHOT BACKFILL: Also check Previous Week to catch any "Orphaned" Sunday notes
        // that might have been missed if cron didn't run.
        // We do this by expanding the search start back 7 days.
        // The Dedupler (in get_leaderboard_data) handles the SUM logic correctly if they are different weeks.
        
        $backfill_start = $start - (7 * 24 * 60 * 60); // Look back 1 extra week
        
        // Override End to NOW to capture live activity immediately
        $search_end = time();
        
        mtrace("Snapshotting revisions for extended window: " . userdate($backfill_start) . " to " . userdate($search_end));

        // 2. Fetch all valid revision points for that week
        // Granularity: User + Quiz
        $sql = "
            SELECT
                " . $DB->sql_concat('lqf.userid', "'_'", 'q.id') . " AS unique_key,
                lqf.userid,
                q.id AS quizid,
                q.course AS courseid,
                cm.id AS cmid,
                q.grade AS quizgrade,
                COALESCE(sc.category, cc.id) AS categoryid,
                COALESCE(pq.sourcecategory, cc.name) AS categoryname,
                pq.sourcecategory,
                SUM(lqf.points_earned) AS total_points,
                MAX(lqf.timemodified) AS last_modified,
                COUNT(lqf.id) AS note_count
            FROM {local_questionflags} lqf
            JOIN {quiz} q ON q.id = lqf.quizid
            JOIN {course} c ON c.id = q.course
            JOIN {course_categories} cc ON cc.id = c.category
            LEFT JOIN {local_personalcourse_quizzes} pq ON pq.quizid = q.id
            LEFT JOIN {course} sc ON sc.id = pq.sourcecourseid
            JOIN {course_modules} cm ON cm.instance = q.id
            JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
            WHERE lqf.timemodified BETWEEN :start AND :end
              AND lqf.points_earned > 0
            GROUP BY lqf.userid, q.id, q.course, cm.id, q.grade
        ";

        $revisions = $DB->get_records_sql($sql, ['start' => $start, 'end' => $end]);
        
        $inserted = 0;
        $updated = 0;
        
        foreach ($revisions as $rev) {
            $userid = (int)$rev->userid;
            $quizid = (int)$rev->quizid;
            $courseid = (int)$rev->courseid;
            $cmid = (int)$rev->cmid;
            $points = (float)$rev->total_points;
            
            // Fix: Anchor Date Logic
            // Instead of default 'end' (Sunday), try to match the Active Homework Due Date for this user's Anchor course
            $anchor_due_date = $end; // Fallback
            
            // 1. Determine Category (Source or Direct)
            $catname = $rev->sourcecategory ?? $rev->categoryname; // Requires SQL update below
            $catid = $rev->categoryid;
            
            // 2. Find Anchor Due Date using helper
            $anchor_due_date = $this->get_student_anchor_due_date($userid, $catid, $catname, $start);
            if ($anchor_due_date <= 0) {
                // Try Custom Course Fallback
                $res_course = $this->get_anchor_course_for_user($userid, $catid, $catname);
                $custom_fallback = $this->calculate_course_fallback_date($res_course, $start);
                
                $anchor_due_date = ($custom_fallback > 0) ? $custom_fallback : $end; // Bespoke or Sunday Fallback
            }

            // 3. Strict Window Check (Match Main Quiz Logic)
            // Use existing build_window logic: [Due - 7days + 2hrs, Due]
            // If the latest revision note activity is OUTSIDE this window (too old), ignore it AND cleanup any stale entry.
            if ($anchor_due_date > 0) {
                [$strict_start, $strict_end] = $this->build_window($anchor_due_date, self::DEFAULT_WINDOW_DAYS);
                $last_modified = (int)($rev->last_modified ?? 0);
                
                if ($last_modified < $strict_start) {
                    // INVALID: Activity is too old for this due date.
                    // Explicitly delete ANY snapshot for this week (including stale ones like Jan 11)
                    $DB->delete_records_select('local_homework_status', 
                        "userid = ? AND quizid = ? AND classification = 'Revision Note' AND timeclose >= ?", 
                        [$userid, $quizid, $start]
                    );
                    continue; 
                }
            }

            // 4. Deduplication: Remove any Stale Revision snapshots for this user/quiz/week AND QuizID match
            if ($anchor_due_date > 0) {
                 $DB->delete_records_select('local_homework_status', 
                    "userid = ? AND quizid = ? AND classification = 'Revision Note' AND timeclose >= ? AND timeclose != ?", 
                    [$userid, $quizid, $start, $anchor_due_date]
                 );
            }

            // Check if snapshot exists (keyed by the calculated anchor date now)
            $existing = $DB->get_record('local_homework_status', [
                'userid' => $userid, 
                'quizid' => $quizid, 
                'timeclose' => $anchor_due_date, 
                'classification' => 'Revision Note'
            ]);
            
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->cmid = $cmid;
            $record->quizid = $quizid;
            $record->timeclose = $anchor_due_date;
            $record->windowdays = 7;
            $record->windowstart = $start;
            $record->classification = 'Revision Note';
            $record->status = $rev->note_count . ' Notes Added';
            $record->quiztype = $this->quiz_has_essay($quizid) ? 'Essay' : 'Non-Essay';
            $record->quizgrade = (float)$rev->quizgrade;
            $record->points = $points;
            $record->score = 0; // Usage score N/A for revision
            $record->attempts = 0;
            $record->computedat = time();

            if ($existing) {
                // Update if points changed (re-run of cron)
                if ((float)$existing->points != $points) {
                    $record->id = $existing->id;
                    $DB->update_record('local_homework_status', $record);
                    $updated++;
                }
            } else {
                $DB->insert_record('local_homework_status', $record);
                $inserted++;
            }
        }
        
        mtrace("Revision Snapshots: Inserted $inserted, Updated $updated.");
    }

    /**
     * Helper to find the "Anchor" Due Date for a student in a category.
     * Used to align Revision Points with the main homework of the week.
     */
    private function get_student_anchor_due_date(int $userid, int $catid, string $catname, int $search_start_time): int {
        global $DB;
        $course = $this->get_anchor_course_for_user($userid, $catid, $catname);
        
        if ($course) {
             $due_sql = "SELECT timeclose FROM {quiz} 
                         WHERE course = :cid 
                           AND timeclose >= :start 
                           AND timeclose > 0
                         ORDER BY timeclose ASC";
             $next_due = $DB->get_field_sql($due_sql, ['cid' => $course->id, 'start' => $search_start_time], IGNORE_MULTIPLE);
             if ($next_due) {
                 return (int)$next_due;
             }
        }
        return 0; 
    }

    /**
     * Find Anchor Course Record for User/Category
     */
    private function get_anchor_course_for_user(int $userid, int $catid, string $catname) {
        global $DB;
        static $cache = [];
        $key = $userid . '_' . $catid;
        
        if (!array_key_exists($key, $cache)) {
            $anchor_sql = "SELECT c.id, c.fullname, c.shortname 
                           FROM {course} c
                           JOIN {course_categories} cc ON cc.id = c.category
                           JOIN {enrol} e ON e.courseid = c.id
                           JOIN {user_enrolments} ue ON ue.enrolid = e.id
                           WHERE ue.userid = :userid
                             AND (cc.id = :catid OR cc.name = :catname)
                             AND cc.name != 'Personal Review Courses'
                             AND c.visible = 1
                           ORDER BY c.startdate DESC";
            $cache[$key] = $DB->get_record_sql($anchor_sql, ['userid' => $userid, 'catid' => $catid, 'catname' => $catname], IGNORE_MULTIPLE) ?: null;
        }
        return $cache[$key];
    }

    /**
     * Calculate Fallback Due Date based on Course ID Rules
     */
    private function calculate_course_fallback_date($course, int $week_start): int {
        if (!$course) return 0;
        
        $base = time(); 
        
        switch ((int)$course->id) {
            case 7: // Year 3A Classroom
                return strtotime('next tuesday 15:30', $base);
            
            case 3: // Year 5A Classroom
                return strtotime('next wednesday 19:00', $base);
                
            case 2: // Selective Trial Test
                return strtotime('next sunday 18:30', $base);

            case 6: // OC Trial Test
                return strtotime('next sunday 15:30', $base);
        }
        
        return 0; // Default to End of Week
    }
}
