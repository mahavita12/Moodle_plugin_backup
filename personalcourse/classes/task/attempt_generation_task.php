<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class attempt_generation_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $data = (object)$this->get_custom_data();
        $userid = (int)$data->userid;
        $quizid = (int)$data->quizid;
        $attemptid = (int)$data->attemptid;
        $cmid = (int)$data->cmid;

        // Resolve course and ensure student-only gating.
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        $coursectx = \context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        $isstudent = is_siteadmin($userid) ? true : false;
        if (!$isstudent) {
            foreach ($roles as $ra) { if (!empty($ra->shortname) && $ra->shortname === 'student') { $isstudent = true; break; } }
        }
        if (!$isstudent) { return; }

        // Load personal course context and any existing mapping.
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
        $pq = null;
        if ($pc) {
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pc->id,
                'sourcequizid' => $quizid,
            ], 'id, quizid');
        }

        // Compute grade percent and attempt number for threshold gating.
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id,attempt,sumgrades,quiz,userid', MUST_EXIST);
        if ((int)$attempt->userid !== $userid || (int)$attempt->quiz !== $quizid) { return; }
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades,name,course', MUST_EXIST);
        $sumgrades = (float)($attempt->sumgrades ?? 0.0);
        $totalsum = (float)($quiz->sumgrades ?? 0.0);
        $grade = ($totalsum > 0.0) ? (($sumgrades / $totalsum) * 100.0) : 0.0;
        $n = (int)$attempt->attempt;

        // Persist auto-blue for any new incorrects on this attempt.
        $analyzer = new \local_personalcourse\attempt_analyzer();
        $incorrectqids = $analyzer->get_incorrect_questionids_from_attempt($attemptid);
        $time = time();
        $context = \context_module::instance($cmid);

        // Delegate to admin-path generator for consistent behavior.
        // Attempt-number driven policy for public attempts:
        // - Attempt 1: if grade >= 30, generate (admin may reset attempts).
        // - Attempt 2: if grade >= 20, generate.
        // - Attempts 3+: if grade >= 20 AND no mapping exists, generate (first-time only).
        $pc = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id');
        $pq = null;
        if ($pc) {
            $pq = $DB->get_record('local_personalcourse_quizzes', [
                'personalcourseid' => (int)$pc->id,
                'sourcequizid' => $quizid,
            ], 'id');
        }
        // Determine if a real personal quiz already exists in the student's personal course for this source quiz.
        $hasquiz = false;
        $pcinfo = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id,courseid');
        if ($pcinfo && !empty($pcinfo->courseid) && !empty($quiz->name)) {
            $moduleidquiz_chk = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            $existingquiz = $DB->get_record_sql(
                'SELECT q.id, cm.deletioninprogress
                   FROM {quiz} q
                   JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                  WHERE q.course = ? AND q.name = ?
               ORDER BY q.id DESC',
                [$moduleidquiz_chk, (int)$pcinfo->courseid, (string)$quiz->name]
            );
            $hasquiz = ($existingquiz && empty($existingquiz->deletioninprogress));
        }
        // Attempt 1 rule (creation only).
        if ($n === 1 && $grade > 80.0 && !$hasquiz) {
            try {
                $svc = new \local_personalcourse\generator_service();
                $svc->generate_from_source($userid, $quizid, $attemptid);
            } catch (\Throwable $e) { /* best-effort */ }
            return;
        }

        // Attempt 2 rule (creation only).
        if ($n === 2 && $grade >= 40.0 && !$hasquiz) {
            try {
                $svc = new \local_personalcourse\generator_service();
                $svc->generate_from_source($userid, $quizid, $attemptid);
            } catch (\Throwable $e) { /* best-effort */ }
            return;
        }

        // Attempts 3+ rule: only if a personal quiz does NOT already exist (ignore mapping).
        if ($n >= 3 && $grade >= 40.0 && !$hasquiz) {
            try {
                $svc = new \local_personalcourse\generator_service();
                $svc->generate_from_source($userid, $quizid, $attemptid);
            } catch (\Throwable $e) { /* best-effort */ }
            return;
        }

        if (!$hasquiz) {
            $allow = false;
            if ($n === 1 && $grade > 80.0) { $allow = true; }
            else if ($n === 2 && $grade >= 40.0) { $allow = true; }
            else if ($n >= 3 && $grade >= 40.0) { $allow = true; }
            if (!$allow) { return; }

            $cg = new \local_personalcourse\course_generator();
            $pcctx = $cg->ensure_personal_course($userid);
            $personalcourseid = (int)$pcctx->pc->id;
            $personalcoursecourseid = (int)$pcctx->course->id;

            $enrol = new \local_personalcourse\enrollment_manager();
            $enrol->ensure_manual_instance_and_enrol_student($personalcoursecourseid, $userid);
            $enrol->sync_staff_from_source_course($personalcoursecourseid, $courseid);

            $quizrow = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades,course,name', MUST_EXIST);

            $flagged = $DB->get_records_sql(
                'SELECT DISTINCT qf.questionid, qf.flagcolor
                   FROM {local_questionflags} qf
                   JOIN {quiz_slots} qs ON qs.questionid = qf.questionid AND qs.quizid = ?
                  WHERE qf.userid = ?',
                [$quizid, $userid]
            );
            $flagids = $flagged ? array_map('intval', array_keys($flagged)) : [];
            $unionids = array_values(array_unique(array_merge($flagids, array_map('intval', $incorrectqids ?: []))));
            if (empty($unionids)) { return; }

            $settingsmode = 'default';
            list($in, $params) = $DB->get_in_or_equal($unionids, SQL_PARAMS_QM);
            $qtypes = $DB->get_fieldset_select('question', 'DISTINCT qtype', 'id ' . $in, $params);
            $allessay = !empty($qtypes) && count(array_unique(array_map('strval', $qtypes))) === 1 && reset($qtypes) === 'essay';
            if ($allessay) { $settingsmode = null; }

            $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            $existingquiz = $DB->get_record_sql(
                'SELECT q.id
                   FROM {quiz} q
                   JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                  WHERE q.course = ? AND q.name = ?
               ORDER BY q.id DESC',
                [$moduleidquiz, $personalcoursecourseid, (string)$quizrow->name]
            );

            $reuseok = false;
            if ($existingquiz) {
                $cmrow_chk = $DB->get_record('course_modules', [
                    'module' => $moduleidquiz,
                    'instance' => (int)$existingquiz->id,
                    'course' => $personalcoursecourseid,
                ], 'id, deletioninprogress');
                if ($cmrow_chk && empty($cmrow_chk->deletioninprogress)) {
                    $reuseok = true;
                }
            }

            if ($reuseok) {
                $pqrec = new \stdClass();
                $pqrec->personalcourseid = $personalcourseid;
                $pqrec->quizid = (int)$existingquiz->id;
                $pqrec->sourcequizid = $quizid;
                $sourcecourse = $DB->get_record('course', ['id' => $DB->get_field('quiz', 'course', ['id' => $quizid])], 'id,shortname,fullname', MUST_EXIST);
                $pqrec->sectionname = (string)$sourcecourse->shortname;
                $pqrec->quiztype = 'non_essay';
                $pqrec->timecreated = time();
                $pqrec->timemodified = $pqrec->timecreated;
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $pq = $pqrec;
            } else {
                $sourcecourse = $DB->get_record('course', ['id' => $DB->get_field('quiz', 'course', ['id' => $quizid])], 'id,shortname,fullname', MUST_EXIST);
                $prefix = (string)$sourcecourse->shortname;
                $sm = new \local_personalcourse\section_manager();
                $sectionnumber = $sm->ensure_section_by_prefix($personalcoursecourseid, $prefix);
                $name = (string)$quizrow->name;
                $intro = '';
                $qb = new \local_personalcourse\quiz_builder();
                $res = $qb->create_quiz($personalcoursecourseid, $sectionnumber, $name, $intro, $settingsmode);

                $pqrec = new \stdClass();
                $pqrec->personalcourseid = $personalcourseid;
                $pqrec->quizid = (int)$res->quizid;
                $pqrec->sourcequizid = $quizid;
                $pqrec->sectionname = $prefix;
                $pqrec->quiztype = ($settingsmode === null) ? 'essay' : 'non_essay';
                $pqrec->timecreated = time();
                $pqrec->timemodified = $pqrec->timecreated;
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $pq = $pqrec;
            }

            $qb = new \local_personalcourse\quiz_builder();
            if (!empty($unionids)) {
                $qb->add_questions((int)$pq->quizid, $unionids);
                foreach ($unionids as $qid) {
                    $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$pq->quizid, 'questionid' => (int)$qid]);
                    $color = (isset($flagged[$qid]) && !empty($flagged[$qid]->flagcolor)) ? (string)$flagged[$qid]->flagcolor : 'blue';
                    $src = isset($flagged[$qid]) ? 'manual_flag' : 'auto';
                    if (!$DB->record_exists('local_personalcourse_questions', [
                        'personalcourseid' => $personalcourseid,
                        'questionid' => (int)$qid,
                    ])) {
                        $DB->insert_record('local_personalcourse_questions', (object)[
                            'personalcourseid' => $personalcourseid,
                            'personalquizid' => $pq->id,
                            'questionid' => (int)$qid,
                            'slotid' => $slotid ? (int)$slotid : null,
                            'flagcolor' => $color,
                            'source' => $src,
                            'originalposition' => null,
                            'currentposition' => null,
                            'timecreated' => time(),
                            'timemodified' => time(),
                        ]);
                    }
                }
                foreach ((array)$incorrectqids as $qid) {
                    $existing = $DB->get_record('local_questionflags', ['userid' => $userid, 'questionid' => (int)$qid], 'id');
                    if ($existing) { continue; }
                    $rec = (object)[
                        'userid' => $userid,
                        'questionid' => (int)$qid,
                        'flagcolor' => 'blue',
                        'cmid' => $cmid,
                        'quizid' => $quizid,
                        'timecreated' => $time,
                        'timemodified' => $time,
                    ];
                    $id = $DB->insert_record('local_questionflags', $rec);
                    $event = \local_questionflags\event\flag_added::create([
                        'context' => $context,
                        'objectid' => $id,
                        'relateduserid' => $userid,
                        'other' => [
                            'questionid' => (int)$qid,
                            'flagcolor' => 'blue',
                            'cmid' => $cmid,
                            'quizid' => $quizid,
                            'origin' => 'auto',
                        ],
                    ]);
                    $event->trigger();
                }
            }

            return;
        }
        

        if ($pq) { return; }

        // If we get here, it's first-time generation (allowed). Persist incorrects and rely on flag events to finalize creation.
        if (empty($incorrectqids)) { return; }
        foreach ($incorrectqids as $qid) {
            // Already persisted above if not existing; re-check not necessary.
        }
    }
}
