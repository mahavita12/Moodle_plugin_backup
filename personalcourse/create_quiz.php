<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

$systemcontext = context_system::instance();
require_capability('local/personalcourse:viewdashboard', $systemcontext);

$userid = required_param('userid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT); // Source course.
$quizid = optional_param('quizid', 0, PARAM_INT);     // Source quiz.
$attemptid = optional_param('attemptid', 0, PARAM_INT); // Optional specific attempt.

$selfurl = new moodle_url('/local/personalcourse/create_quiz.php');
$PAGE->set_url(new moodle_url('/local/personalcourse/create_quiz.php', ['userid' => $userid] + ($courseid ? ['courseid' => $courseid] : [])));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('action_createquiz', 'local_personalcourse'));
$PAGE->set_heading(get_string('action_createquiz', 'local_personalcourse'));
// Do not call admin_externalpage_setup() here; it overrides $PAGE->url to the dashboard.

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('action_createquiz', 'local_personalcourse'));

// Validate user exists.
$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, firstname, lastname, email', MUST_EXIST);
$displayname = fullname($user) . ' (' . $user->id . ')';
echo html_writer::tag('p', get_string('for_user', 'local_personalcourse', $displayname));

// Normalize/repair quizid and courseid if launched from an attempt or if the quiz was deleted/changed.
try {
    $quizexists = ($quizid > 0) ? $DB->record_exists('quiz', ['id' => $quizid]) : false;
    if (!$quizexists && $attemptid > 0) {
        $quizfromattempt = (int)$DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
        if ($quizfromattempt) { $quizid = $quizfromattempt; }
    }
    // If we still don't have a valid quiz, try latest finished attempt for this user in the provided course.
    if (($quizid <= 0 || !$DB->record_exists('quiz', ['id' => $quizid])) && $courseid > 0) {
        $cand = $DB->get_record_sql(
            "SELECT qa.quiz
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE qa.userid = ? AND q.course = ? AND qa.state = 'finished'
           ORDER BY COALESCE(qa.timefinish, qa.timemodified, qa.timecreated) DESC, qa.id DESC",
            [$userid, $courseid]
        );
        if ($cand && !empty($cand->quiz)) { $quizid = (int)$cand->quiz; }
    }
    // If we have a quiz now, ensure courseid matches the quiz's course.
    if ($quizid > 0 && $DB->record_exists('quiz', ['id' => $quizid])) {
        $qcourse = (int)$DB->get_field('quiz', 'course', ['id' => $quizid]);
        if ($qcourse > 0) { $courseid = $qcourse; }
    }
} catch (\Throwable $e) {
    // Non-fatal; proceed to selection forms which will help the admin complete inputs.
}

// Step 1: Choose a source course from user's enrolments if not provided.
if ($courseid <= 0) {
    list($insql, $inparams) = $DB->get_in_or_equal([$userid], SQL_PARAMS_QM);
    $enrolsql = "
        SELECT DISTINCT c.id, c.fullname, c.shortname
          FROM {user_enrolments} ue
          JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
          JOIN {course} c ON c.id = e.courseid
         WHERE ue.userid {$insql}
      ORDER BY c.fullname
    ";
    $courses = $DB->get_records_sql($enrolsql, $inparams);

    // Exclude the student's personal course from source selection to avoid confusion.
    $pcmap = $DB->get_record('local_personalcourse_courses', ['userid' => $userid], 'id, courseid');
    if ($pcmap) {
        unset($courses[(int)$pcmap->courseid]);
    }

    if (empty($courses)) {
        echo $OUTPUT->notification(get_string('no_enrolments', 'local_personalcourse'), 'warning');
    } else {
        $form = html_writer::start_tag('form', ['method' => 'get', 'action' => $selfurl->out(false)]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int)$userid]) .
            html_writer::tag('label', get_string('select_source_course', 'local_personalcourse'), ['for' => 'courseid']) . ' ';
        $opts = [];
        foreach ($courses as $c) {
            $label = format_string($c->shortname ?: $c->fullname);
            $opts[(int)$c->id] = $label;
        }
        $form .= html_writer::select($opts, 'courseid', '', ['' => get_string('choose')], ['id' => 'courseid']);
        $form .= ' ' . html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('next')]);
        $form .= html_writer::end_tag('form');
        echo $form;
    }

    echo $OUTPUT->footer();
    exit;
}

// Step 2: Choose a quiz in the selected course if not provided or invalid.
if ($courseid > 0 && ($quizid <= 0 || !$DB->record_exists('quiz', ['id' => $quizid]))) {
    $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'name', 'id, name');
    if (empty($quizzes)) {
        echo $OUTPUT->notification(get_string('no_quizzes', 'local_personalcourse'), 'warning');
    } else {
        $form = html_writer::start_tag('form', ['method' => 'get', 'action' => $selfurl->out(false)]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => (int)$userid]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => (int)$courseid]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::tag('label', get_string('select_source_quiz', 'local_personalcourse'), ['for' => 'quizid']) . ' ';
        $opts = [];
        foreach ($quizzes as $q) { $opts[(int)$q->id] = format_string($q->name); }
        $form .= html_writer::select($opts, 'quizid', '', ['' => get_string('choose')], ['id' => 'quizid']);
        $form .= ' ' . html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('action_createquiz', 'local_personalcourse')]);
        $form .= html_writer::end_tag('form');
        echo $form;
    }

    echo $OUTPUT->footer();
    exit;
}

// Step 3: Create the personal quiz mapping immediately (ignore thresholds).
require_sesskey();

try {
    // Ensure student's personal course exists.
    $gen = new \local_personalcourse\course_generator();
    $res = $gen->ensure_personal_course($userid);
    $personalcourseid = (int)$res->pc->id;
    $personalcoursecourseid = (int)$res->course->id;

    // If mapping already exists for this source quiz, redirect to it.
    $existing = $DB->get_record('local_personalcourse_quizzes', [
        'personalcourseid' => $personalcourseid,
        'sourcequizid' => $quizid,
    ], 'id, quizid');
    $reuseexisting = false;
    $mappingid = 0;
    if ($existing) {
        // Always reuse existing mapping (update in place).
        $reuseexisting = true;
        $mappingid = (int)$existing->id;
    }

    // Determine section prefix as '<course shortname>-<source section name>'.
    $srcourse = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname', MUST_EXIST);
    // Find source quiz section name.
    $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
    $src_cmid = (int)$DB->get_field('course_modules', 'id', ['module' => $moduleidquiz, 'instance' => (int)$quizid, 'course' => $courseid], IGNORE_MISSING);
    $srsectionname = '';
    if ($src_cmid) {
        $src_sectionid = (int)$DB->get_field('course_modules', 'section', ['id' => $src_cmid], MUST_EXIST);
        $src_section = $DB->get_record('course_sections', ['id' => $src_sectionid], 'id, section, name');
        if ($src_section) { $srsectionname = (string)($src_section->name ?: 'Section ' . (int)$src_section->section); }
    }
    $prefix = trim((string)$srcourse->shortname . (strlen($srsectionname) ? '-' . $srsectionname : ''));
    $sm = new \local_personalcourse\section_manager();
    $sectionnumber = $sm->ensure_section_by_prefix($personalcoursecourseid, $prefix);

    // Determine settings by quiz qtypes: all essay => Moodle defaults (null), else interactive.
    $qtypes = [];
    try {
        $qtypes = $DB->get_fieldset_sql("SELECT DISTINCT q.qtype
                                           FROM {quiz_slots} qs
                                           JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                           JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                           JOIN {question} q ON q.id = qv.questionid
                                          WHERE qs.quizid = ?", [$quizid]);
    } catch (\Throwable $e) {
        $qtypes = [];
    }
    if (empty($qtypes)) {
        // Legacy fallback using quiz_slots.questionid
        try {
            $qtypes = $DB->get_fieldset_sql("SELECT DISTINCT q.qtype
                                               FROM {quiz_slots} qs
                                               JOIN {question} q ON q.id = qs.questionid
                                              WHERE qs.quizid = ?", [$quizid]);
        } catch (\Throwable $e) {
            $qtypes = [];
        }
    }
    $qtypes = array_map('strval', $qtypes);
    $allessay = (!empty($qtypes) && count(array_unique($qtypes)) === 1 && reset($qtypes) === 'essay');
    $settingsmode = $allessay ? null : 'default';

    // Create personal quiz now. Guard quiz existence.
    $sourcequiz = $DB->get_record('quiz', ['id' => $quizid], 'id, name', IGNORE_MISSING);
    if (!$sourcequiz) {
        // Redirect to quiz selection step for this course to avoid fatal.
        $selecturl = new moodle_url('/local/personalcourse/create_quiz.php', [
            'userid' => $userid,
            'courseid' => $courseid,
            'sesskey' => sesskey(),
        ]);
        redirect($selecturl);
    }
    $qb = new \local_personalcourse\quiz_builder();
    // Fallback discovery: if no mapping found, try to locate an existing personal quiz by name/section/course.
    if (!$reuseexisting) {
        // 1) Search mapping table by quiz name.
        $exbyname = [];
        try {
            $exbyname = $DB->get_records_sql("SELECT lpq.id, lpq.quizid
                                                 FROM {local_personalcourse_quizzes} lpq
                                                 JOIN {quiz} q ON q.id = lpq.quizid
                                                WHERE lpq.personalcourseid = ? AND q.name = ?
                                             ORDER BY lpq.id DESC", [$personalcourseid, $sourcequiz->name]);
        } catch (\Throwable $e) { $exbyname = []; }
        if (!empty($exbyname)) {
            $row = reset($exbyname);
            $reuseexisting = true;
            $mappingid = (int)$row->id;
            $existing = (object)['id' => (int)$row->id, 'quizid' => (int)$row->quizid];
        } else {
            // 2) Search for an unmapped quiz in the expected section with the same name.
            $sec = null;
            try { $sec = $DB->get_record('course_sections', ['course' => $personalcoursecourseid, 'name' => $prefix], 'id, section'); } catch (\Throwable $e) { $sec = null; }
            if ($sec) {
                $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
                $cms = $DB->get_records('course_modules', ['course' => $personalcoursecourseid, 'section' => (int)$sec->id, 'module' => $moduleidquiz], '', 'id, instance');
                if (!empty($cms)) {
                    foreach ($cms as $cm) {
                        $qrow = $DB->get_record('quiz', ['id' => (int)$cm->instance], 'id, name');
                        if ($qrow && (string)$qrow->name === (string)$sourcequiz->name) {
                            $reuseexisting = true;
                            // No mapping id yet; will insert later.
                            $existing = (object)['id' => 0, 'quizid' => (int)$qrow->id];
                            break;
                        }
                    }
                }
            }
            // 3) As a final fallback, search entire personal course for a quiz with the same name.
            if (!$reuseexisting) {
                try {
                    $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
                    $qrows = $DB->get_records_sql(
                        "SELECT q.id
                           FROM {quiz} q
                           JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ?
                          WHERE q.course = ? AND q.name = ?
                       ORDER BY q.id DESC",
                        [$moduleidquiz, $personalcoursecourseid, $sourcequiz->name]
                    );
                    if (!empty($qrows)) {
                        $first = reset($qrows);
                        $reuseexisting = true;
                        $existing = (object)['id' => 0, 'quizid' => (int)$first->id];
                    }
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
    }
    if ($reuseexisting) {
        // Build a created-like struct to reuse existing quiz.
        $created = (object)['success' => true, 'quizid' => (int)$existing->quizid, 'cmid' => 0];
    } else {
        $created = $qb->create_quiz($personalcoursecourseid, $sectionnumber, (string)$sourcequiz->name, '', $settingsmode);
        if (empty($created) || empty($created->success) || empty($created->quizid)) {
            $msg = isset($created->error) ? (string)$created->error : 'Unknown error creating quiz';
            throw new \Exception($msg);
        }
    }

    // Prepare map record (insert later; we will also ensure persistence even if no question changes are needed).
    $pqrec = new stdClass();
    $pqrec->personalcourseid = $personalcourseid;
    $pqrec->quizid = (int)$created->quizid;
    $pqrec->sourcequizid = (int)$quizid;
    $pqrec->sectionname = $prefix;
    $pqrec->quiztype = $allessay ? 'essay' : 'non_essay';
    $pqrec->timecreated = time();
    $pqrec->timemodified = $pqrec->timecreated;
    if ($mappingid > 0) {
        $pqrec->id = $mappingid;
    }

    // If we are reusing a mapping but the referenced quiz/CM is missing or being deleted, recreate now regardless of question set size.
    $needrecreate = false;
    if ((int)$pqrec->quizid <= 0 || !$DB->record_exists('quiz', ['id' => (int)$pqrec->quizid])) {
        $needrecreate = true;
    } else {
        $moduleidquiz_chk = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
        $cmrow_chk = $DB->get_record('course_modules', [
            'module' => $moduleidquiz_chk,
            'instance' => (int)$pqrec->quizid,
            'course' => $personalcoursecourseid,
        ], 'id, deletioninprogress');
        if (!$cmrow_chk || (!empty($cmrow_chk->deletioninprogress))) {
            $needrecreate = true;
        }
    }
    if ($needrecreate) {
        $newquiz = $qb->create_quiz($personalcoursecourseid, $sectionnumber, (string)$sourcequiz->name, '', $settingsmode);
        if (empty($newquiz) || empty($newquiz->success) || empty($newquiz->quizid)) {
            $msg = isset($newquiz->error) ? (string)$newquiz->error : 'Unknown error creating replacement quiz';
            throw new \Exception($msg);
        }
        $pqrec->quizid = (int)$newquiz->quizid;
        $created = $newquiz;
    }

    // If we have an attempt (specified or latest), pull incorrect questions and add now.
    $finalattemptid = 0;
    if ($attemptid > 0) {
        $finalattemptid = (int)$attemptid;
    } else {
        // Latest finished attempt by this user on the selected quiz.
        $finalattemptid = (int)$DB->get_field_sql(
            "SELECT qa.id
               FROM {quiz_attempts} qa
              WHERE qa.quiz = ? AND qa.userid = ? AND qa.state = 'finished'
           ORDER BY COALESCE(qa.timefinish, qa.timemodified, qa.timecreated) DESC, qa.id DESC",
            [$quizid, $userid]
        );
    }

    if ($finalattemptid > 0) {
        // 1) Source quiz question IDs.
        $srcquizqids = [];
        try {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                    FROM {quiz_slots} qs
                                                    JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                    JOIN {question} q ON q.id = qv.questionid
                                                   WHERE qs.quizid = ?", [$quizid]);
        } catch (\Throwable $e) {
            $srcquizqids = [];
        }
        if (empty($srcquizqids)) {
            try {
                $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT qs.questionid
                                                        FROM {quiz_slots} qs
                                                       WHERE qs.quizid = ? AND qs.questionid IS NOT NULL", [$quizid]);
            } catch (\Throwable $e) {
                $srcquizqids = [];
            }
        }

        // 2) Existing user flags (blue or red) intersected with source quiz questions.
        $flagqids = [];
        $dbman = $DB->get_manager();
        $hasflagtable = $dbman->table_exists('local_questionflags');
        if ($hasflagtable && !empty($srcquizqids)) {
            list($insqlq, $inparamsq) = $DB->get_in_or_equal(array_map('intval', $srcquizqids), SQL_PARAMS_QM);
            $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ? AND questionid {$insqlq}", array_merge([$userid], $inparamsq));
        }

        // 3) Incorrect question IDs from latest attempt.
        $analyzer = new \local_personalcourse\attempt_analyzer();
        $incorrect = $analyzer->get_incorrect_questionids_from_attempt($finalattemptid);

        // 4) Desired set = flagged ∪ incorrect.
        $qids = array_values(array_unique(array_merge($flagqids, $incorrect)));
        if (empty($qids)) {
            throw new \Exception('No flagged or incorrect questions found for the latest attempt.');
        }

        // 5) Persist auto-blue for incorrect questions that are not already flagged.
        $autoblue = array_values(array_diff($incorrect, $flagqids));
        if ($hasflagtable && !empty($autoblue)) {
            // Detect available columns to remain compatible with pre-upgrade schemas.
            $qfcols = [];
            try { $qfcols = $DB->get_columns('local_questionflags'); } catch (\Throwable $t) { $qfcols = []; }
            $hascmid = isset($qfcols['cmid']);
            $hasquizid = isset($qfcols['quizid']);
            foreach ($autoblue as $qid) {
                if (!$DB->record_exists('local_questionflags', ['userid' => $userid, 'questionid' => (int)$qid])) {
                    $rec = (object)[
                        'userid' => $userid,
                        'questionid' => (int)$qid,
                        'flagcolor' => 'blue',
                        'timecreated' => time(),
                        'timemodified' => time(),
                    ];
                    if ($hascmid) { $rec->cmid = $src_cmid ?: null; }
                    if ($hasquizid) { $rec->quizid = $quizid; }
                    $DB->insert_record('local_questionflags', $rec);
                }
            }
        }

        if (!empty($qids)) {
            // Dedupe policy: latest injection wins across personal course.
            foreach ($qids as $qid) {
                $existingpcq = $DB->get_record('local_personalcourse_questions', [
                    'personalcourseid' => $personalcourseid,
                    'questionid' => (int)$qid,
                ]);
                if ($existingpcq && (int)$existingpcq->personalquizid !== (int)$pqrec->id) {
                    $targetpq = $DB->get_record('local_personalcourse_quizzes', ['id' => $existingpcq->personalquizid], 'id, quizid');
                    if ($targetpq) {
                        $qb->remove_question((int)$targetpq->quizid, (int)$qid);
                    }
                    $DB->delete_records('local_personalcourse_questions', ['id' => $existingpcq->id]);
                }
            }
            // Ensure the personal quiz matches the desired set exactly.
            $targetquizid = (int)$created->quizid;
            // If the reused quiz/CM no longer exists or is being deleted (stale mapping), create a new one now.
            $stale = false;
            if ($targetquizid <= 0 || !$DB->record_exists('quiz', ['id' => $targetquizid])) { $stale = true; }
            else {
                $moduleidquiz2 = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
                $cmrow2 = $DB->get_record('course_modules', [
                    'module' => $moduleidquiz2,
                    'instance' => (int)$targetquizid,
                    'course' => $personalcoursecourseid,
                ], 'id, deletioninprogress');
                if (!$cmrow2 || (!empty($cmrow2->deletioninprogress))) { $stale = true; }
            }
            if ($stale) {
                $new = $qb->create_quiz($personalcoursecourseid, $sectionnumber, (string)$sourcequiz->name, '', $settingsmode);
                if (empty($new) || empty($new->success) || empty($new->quizid)) {
                    $msg = isset($new->error) ? (string)$new->error : 'Unknown error creating replacement quiz';
                    throw new \Exception($msg);
                }
                $targetquizid = (int)$new->quizid;
                $created = $new;
                $pqrec->quizid = $targetquizid;
                // Treat as new mapping if previous mapping pointed to a missing quiz.
                if ($mappingid > 0) {
                    $pqrec->id = $mappingid;
                }
            }

            // Current question IDs in target quiz.
            try {
                $currqids = $DB->get_fieldset_sql("SELECT DISTINCT q.id
                                                     FROM {quiz_slots} qs
                                                     JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                     JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                                                     JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                                     JOIN {question} q ON q.id = qv.questionid
                                                    WHERE qs.quizid = ?", [$targetquizid]);
            } catch (\Throwable $e) {
                $currqids = [];
            }
            if (empty($currqids)) {
                try {
                    $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [$targetquizid]);
                } catch (\Throwable $e) {
                    $currqids = [];
                }
            }
            $currqids = array_map('intval', $currqids);

            $toadd = array_values(array_diff(array_map('intval', $qids), $currqids));
            $toremove = array_values(array_diff($currqids, array_map('intval', $qids)));

            // Remove extras first (update mapping rows if present).
            if (!empty($toremove)) {
                foreach ($toremove as $qid) {
                    $qb->remove_question($targetquizid, (int)$qid);
                    if ($mappingid > 0) {
                        $DB->delete_records('local_personalcourse_questions', ['personalquizid' => $mappingid, 'questionid' => (int)$qid]);
                    }
                }
            }

            // Add missing questions.
            if (!empty($toadd)) {
                $addres = $qb->add_questions($targetquizid, array_map('intval', $toadd));
                $addedcount = (int)($addres->count ?? 0);
                if ($addedcount <= 0) {
                    $err = !empty($addres->errors) ? (is_array($addres->errors) ? implode('; ', $addres->errors) : (string)$addres->errors) : 'No questions could be added.';
                    throw new \Exception('No questions were added to the personal quiz: ' . $err);
                }
            }

            // Insert/update mapping row (reuse if duplicate exists).
            if ($mappingid === 0) {
                $dupe = $DB->get_record('local_personalcourse_quizzes', [
                    'personalcourseid' => $personalcourseid,
                    'sourcequizid' => (int)$quizid,
                ], 'id');
                if ($dupe) {
                    $mappingid = (int)$dupe->id;
                    $pqrec->id = $mappingid;
                    $pqrec->timemodified = time();
                    $DB->update_record('local_personalcourse_quizzes', $pqrec);
                } else {
                    $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                    $mappingid = (int)$pqrec->id;
                }
            } else {
                $pqrec->id = $mappingid;
                $pqrec->timemodified = time();
                $DB->update_record('local_personalcourse_quizzes', $pqrec);
            }

            $now = time();
            foreach ($toadd as $qid) {
                $existsrow = $DB->get_record('local_personalcourse_questions', [
                    'personalcourseid' => $personalcourseid,
                    'questionid' => (int)$qid,
                ]);
                if ($existsrow) {
                    $existsrow->personalquizid = (int)$mappingid;
                    if (empty($existsrow->flagcolor)) { $existsrow->flagcolor = 'blue'; }
                    $existsrow->timemodified = $now;
                    $DB->update_record('local_personalcourse_questions', $existsrow);
                } else {
                    $DB->insert_record('local_personalcourse_questions', (object)[
                        'personalcourseid' => $personalcourseid,
                        'personalquizid' => (int)$mappingid,
                        'questionid' => (int)$qid,
                        'slotid' => null,
                        'flagcolor' => 'blue',
                        'source' => 'admin',
                        'originalposition' => null,
                        'currentposition' => null,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
            // If no changes were needed but mapping didn't exist, ensure mapping is present.
            if (empty($toadd) && empty($toremove) && $mappingid === 0) {
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $mappingid = (int)$pqrec->id;
            }
        }
    }

    // Even if we did not enter the question-sync branch (e.g., no attempt or no desired questions),
    // make sure the mapping row exists and points to a valid quiz.
    if ($mappingid === 0) {
        $dupe = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => $personalcourseid,
            'sourcequizid' => (int)$quizid,
        ], 'id');
        if ($dupe) {
            $mappingid = (int)$dupe->id;
            $pqrec->id = $mappingid;
            $pqrec->timemodified = time();
            $DB->update_record('local_personalcourse_quizzes', $pqrec);
        } else {
            $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
            $mappingid = (int)$pqrec->id;
        }
    }

    // Success message and link.
    $cmid = (int)($created->cmid ?? 0);
    if ($cmid <= 0) {
        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
        $cmid = (int)$DB->get_field('course_modules', 'id', ['module' => $moduleid, 'instance' => (int)$created->quizid], IGNORE_MISSING);
        if ($cmid <= 0) {
            $cm = get_coursemodule_from_instance('quiz', (int)$created->quizid, $personalcoursecourseid, IGNORE_MISSING);
            if ($cm) { $cmid = (int)$cm->id; }
        }
    }
    $quizurl = $cmid ? new moodle_url('/mod/quiz/view.php', ['id' => $cmid]) : new moodle_url('/course/view.php', ['id' => $personalcoursecourseid]);

    redirect($quizurl, get_string('createquiz_success', 'local_personalcourse'), 0, \core\output\notification::NOTIFY_SUCCESS);
} catch (\Throwable $e) {
    redirect(new moodle_url('/local/personalcourse/index.php'), get_string('createquiz_error', 'local_personalcourse', $e->getMessage()), 0, \core\output\notification::NOTIFY_ERROR);
}

