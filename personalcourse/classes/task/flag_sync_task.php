<?php
namespace local_personalcourse\task;

defined('MOODLE_INTERNAL') || die();

class flag_sync_task extends \core\task\adhoc_task {
    public function get_component() {
        return 'local_personalcourse';
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $data = (object)$this->get_custom_data();

        $userid = (int)$data->userid;
        $questionid = (int)$data->questionid;
        $flagcolor = (string)$data->flagcolor;
        $added = (bool)$data->added;
        $cmid = (int)$data->cmid;
        $quizid = isset($data->quizid) ? (int)$data->quizid : null;
        $origin = isset($data->origin) ? (string)$data->origin : 'manual';

        // Resolve course and student-only gating again (cron safety).
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;
        if (!$quizid) { $quizid = (int)$cm->instance; }
        $coursectx = \context_course::instance($courseid);
        $roles = get_user_roles($coursectx, $userid, true);
        $isstudent = false;
        if (is_siteadmin($userid)) {
            $isstudent = true; // Allow admin-driven testing to sync immediately.
        } else {
            foreach ($roles as $ra) { if (!empty($ra->shortname) && $ra->shortname === 'student') { $isstudent = true; break; } }
        }
        if (!$isstudent) { return; }

        // Ensure personal course.
        $cg = new \local_personalcourse\course_generator();
        $pcctx = $cg->ensure_personal_course($userid);
        $personalcourseid = (int)$pcctx->pc->id; // local table id
        $personalcoursecourseid = (int)$pcctx->course->id; // mdl_course.id

        // Enrol student to personal course.
        $enrol = new \local_personalcourse\enrollment_manager();
        $enrol->ensure_manual_instance_and_enrol_student($personalcoursecourseid, $userid);
        // Enrol only staff who are actually enrolled in the student's public course.
        $enrol->sync_staff_from_source_course($personalcoursecourseid, $courseid);

        // Ensure mapping quiz exists for source quiz.
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => $personalcourseid,
            'sourcequizid' => $quizid
        ]);

        if (!$pq) {
            // Gate first creation by initial thresholds on student's attempts.
            $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quizid, 'userid' => $userid], 'attempt ASC', 'id,attempt,sumgrades');
            $quizrow = $DB->get_record('quiz', ['id' => $quizid], 'id,sumgrades,course,name', MUST_EXIST);
            $totalsum = (float)($quizrow->sumgrades ?? 0.0);
            $allow = false;
            foreach ($attempts as $a) {
                $n = (int)$a->attempt;
                $grade = ($totalsum > 0.0) ? (((float)($a->sumgrades ?? 0.0) / $totalsum) * 100.0) : 0.0;
                if ($n === 1 && $grade > 80.0) { $allow = true; break; }
                if ($n === 2 && $grade >= 30.0) { $allow = true; break; }
                if ($n >= 3 && $grade >= 30.0) { $allow = true; break; }
            }
            if (!$allow) {
                // Not yet eligible to create personal quiz for this source; return early.
                return;
            }

            // Determine current flagged questions that belong to this source quiz.
            $flagged = $DB->get_records_sql(
                'SELECT DISTINCT qf.questionid, qf.flagcolor
                   FROM {local_questionflags} qf
                   JOIN {quiz_slots} qs ON qs.questionid = qf.questionid AND qs.quizid = ?
                  WHERE qf.userid = ?',
                [$quizid, $userid]
            );

            // Choose settings mode based on question types: essay-only => Moodle defaults (null), else interactive ('default').
            $settingsmode = 'default';
            if ($flagged) {
                list($in, $params) = $DB->get_in_or_equal(array_keys($flagged), SQL_PARAMS_QM);
                $qtypes = $DB->get_fieldset_select('question', 'DISTINCT qtype', 'id ' . $in, $params);
                $allessay = !empty($qtypes) && count(array_unique(array_map('strval', $qtypes))) === 1 && reset($qtypes) === 'essay';
                if ($allessay) { $settingsmode = null; }
            } else {
                // No flagged questions yet; default to interactive settings when created.
                $settingsmode = 'default';
            }

            // Try to reuse existing personal quiz by name before creating.
            $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
            $existingquiz = $DB->get_record_sql(
                "SELECT q.id
                   FROM {quiz} q
                   JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                  WHERE q.course = ? AND q.name = ?
               ORDER BY q.id DESC",
                [$moduleidquiz, $personalcoursecourseid, $quizrow->name]
            );

            if ($existingquiz) {
                // Create mapping row to existing quiz and proceed.
                $pqrec = new \stdClass();
                $pqrec->personalcourseid = $personalcourseid;
                $pqrec->quizid = (int)$existingquiz->id;
                $pqrec->sourcequizid = $quizid;
                // Use shortname as section label if available.
                $sourcecourse = $DB->get_record('course', ['id' => $quizrow->course], 'id,shortname,fullname', MUST_EXIST);
                $pqrec->sectionname = (string)$sourcecourse->shortname;
                $pqrec->quiztype = 'non_essay';
                $pqrec->timecreated = time();
                $pqrec->timemodified = $pqrec->timecreated;
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $pq = $pqrec;
            } else {
                // Create personal quiz under section named after source course shortname.
                $sourcecourse = $DB->get_record('course', ['id' => $quizrow->course], 'id,shortname,fullname', MUST_EXIST);
                $prefix = (string)$sourcecourse->shortname;
                $sm = new \local_personalcourse\section_manager();
                $sectionnumber = $sm->ensure_section_by_prefix($personalcoursecourseid, $prefix);
                $name = $quizrow->name;
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

            // Bulk inject all currently flagged questions for this quiz so state is up-to-date immediately.
            if ($flagged) {
                $qb = new \local_personalcourse\quiz_builder();
                $ids = array_map(function($r){ return (int)$r->questionid; }, array_values($flagged));
                if (!empty($ids)) {
                    $qb->add_questions((int)$pq->quizid, $ids);
                    // Record each mapping with current flagcolor.
                    foreach ($flagged as $f) {
                        // Get slot id for record.
                        $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$pq->quizid, 'questionid' => (int)$f->questionid]);
                        $pcq = new \stdClass();
                        $pcq->personalcourseid = $personalcourseid;
                        $pcq->personalquizid = $pq->id;
                        $pcq->questionid = (int)$f->questionid;
                        $pcq->slotid = $slotid ? (int)$slotid : null;
                        $pcq->flagcolor = (string)$f->flagcolor;
                        $pcq->source = 'manual_flag';
                        $pcq->originalposition = null;
                        $pcq->currentposition = null;
                        $pcq->timecreated = time();
                        $pcq->timemodified = $pcq->timecreated;
                        // Dedupe constraint on (personalcourseid, questionid) protects duplicates.
                        if (!$DB->record_exists('local_personalcourse_questions', [
                            'personalcourseid' => $personalcourseid,
                            'questionid' => (int)$f->questionid,
                        ])) {
                            $DB->insert_record('local_personalcourse_questions', $pcq);
                        }
                    }
                }
            }
        }

        if ($added) {
            // Dedupe across personal course.
            $existing = $DB->get_record('local_personalcourse_questions', [
                'personalcourseid' => $personalcourseid,
                'questionid' => $questionid
            ]);
            if ($existing && (int)$existing->personalquizid !== (int)$pq->id) {
                // Remove from previous quiz in mod_quiz as well.
                $oldpq = $DB->get_record('local_personalcourse_quizzes', ['id' => $existing->personalquizid], '*', MUST_EXIST);
                $qb = new \local_personalcourse\quiz_builder();
                $qb->remove_question((int)$oldpq->quizid, $questionid);
                $DB->delete_records('local_personalcourse_questions', ['id' => $existing->id]);
            }

            // Add to current quiz if not present.
            $present = $DB->record_exists('local_personalcourse_questions', [
                'personalcourseid' => $personalcourseid,
                'personalquizid' => $pq->id,
                'questionid' => $questionid
            ]);
            if (!$present) {
                $qb = new \local_personalcourse\quiz_builder();
                $qb->add_questions((int)$pq->quizid, [$questionid]);
                // Get slot id for record.
                $slotid = $DB->get_field('quiz_slots', 'id', ['quizid' => (int)$pq->quizid, 'questionid' => $questionid]);
                $pcq = new \stdClass();
                $pcq->personalcourseid = $personalcourseid;
                $pcq->personalquizid = $pq->id;
                $pcq->questionid = $questionid;
                $pcq->slotid = $slotid ? (int)$slotid : null;
                $pcq->flagcolor = $flagcolor;
                $pcq->source = ($origin === 'auto') ? 'auto_incorrect' : 'manual_flag';
                $pcq->originalposition = null;
                $pcq->currentposition = null;
                $pcq->timecreated = time();
                $pcq->timemodified = $pcq->timecreated;
                $DB->insert_record('local_personalcourse_questions', $pcq);
            }
        } else {
            // Removal: remove this question from any personal quiz within the student's personal course.
            $existing = $DB->get_record('local_personalcourse_questions', [
                'personalcourseid' => $personalcourseid,
                'questionid' => $questionid
            ]);
            if ($existing) {
                $targetpq = $DB->get_record('local_personalcourse_quizzes', ['id' => $existing->personalquizid], 'id, quizid');
                if ($targetpq) {
                    $qb = new \local_personalcourse\quiz_builder();
                    $qb->remove_question((int)$targetpq->quizid, $questionid);
                }
                $DB->delete_records('local_personalcourse_questions', ['id' => $existing->id]);
            }
        }
    }
}
