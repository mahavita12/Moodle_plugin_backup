<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class generator_service {
    public static function generate_from_source(int $userid, int $sourcequizid, ?int $attemptid = null, string $mode = 'union'): object {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $cg = new \local_personalcourse\course_generator();
        $pcctx = $cg->ensure_personal_course($userid);
        $personalcourseid = (int)$pcctx->pc->id;
        $pccourseid = (int)$pcctx->course->id;

        // Ensure student enrolment only (no staff sync as per requirement).
        try {
            $enrol = new \local_personalcourse\enrollment_manager();
            $enrol->ensure_manual_instance_and_enrol_student($pccourseid, $userid);
        } catch (\Throwable $e) { /* best-effort */ }

        $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);

        // Only compute a latest finished attempt when operating in 'union' mode.
        if ($mode === 'union' && empty($attemptid)) {
            $attemptid = (int)$DB->get_field_sql(
                "SELECT qa.id FROM {quiz_attempts} qa WHERE qa.quiz = ? AND qa.userid = ? AND qa.state = 'finished' ORDER BY COALESCE(qa.timefinish, qa.timemodified, qa.timecreated) DESC, qa.id DESC",
                [$sourcequizid, $userid]
            );
        }

        // Determine section prefix from source course shortname; include source section name if available.
        $sourcecourseid = (int)$DB->get_field('quiz', 'course', ['id' => $sourcequizid]);
        $srcourse = $DB->get_record('course', ['id' => $sourcecourseid], 'id,shortname,fullname', MUST_EXIST);
        $src_cmid = (int)$DB->get_field('course_modules', 'id', ['module' => $moduleidquiz, 'instance' => (int)$sourcequizid, 'course' => $sourcecourseid], IGNORE_MISSING);
        $srsectionname = '';
        if ($src_cmid) {
            $src_sectionid = (int)$DB->get_field('course_modules', 'section', ['id' => $src_cmid], IGNORE_MISSING);
            if ($src_sectionid) {
                $src_section = $DB->get_record('course_sections', ['id' => $src_sectionid], 'id,section,name');
                if ($src_section) { $srsectionname = (string)($src_section->name ?: ('Section ' . (int)$src_section->section)); }
            }
        }
        $prefix = trim($srsectionname !== '' ? $srsectionname : (string)$srcourse->shortname);

        // Settings mode based on qtypes.
        $qtypes = [];
        try {
            $qtypes = $DB->get_fieldset_sql("SELECT DISTINCT q.qtype\n                                           FROM {quiz_slots} qs\n                                           JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                           JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid\n                                           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id\n                                           JOIN {question} q ON q.id = qv.questionid\n                                          WHERE qs.quizid = ?", [$sourcequizid]);
        } catch (\Throwable $e) { $qtypes = []; }
        if (empty($qtypes)) {
            try { $qtypes = $DB->get_fieldset_sql("SELECT DISTINCT q.qtype FROM {quiz_slots} qs JOIN {question} q ON q.id = qs.questionid WHERE qs.quizid = ?", [$sourcequizid]); } catch (\Throwable $e) { $qtypes = []; }
        }
        $qtypes = array_map('strval', $qtypes);
        $allessay = (!empty($qtypes) && count(array_unique($qtypes)) === 1 && reset($qtypes) === 'essay');
        $settingsmode = $allessay ? null : 'default';

        // Ensure mapping (prefer existing mapping; otherwise reuse quiz by name; else create).
        $pq = $DB->get_record('local_personalcourse_quizzes', [
            'personalcourseid' => $personalcourseid,
            'sourcequizid' => $sourcequizid,
        ]);

        $qb = new \local_personalcourse\quiz_builder();
        $sm = new \local_personalcourse\section_manager();

        if (!$pq) {
            $existingquiz = $DB->get_record_sql(
                "SELECT q.id FROM {quiz} q JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = ? WHERE q.course = ? AND q.name = ? ORDER BY q.id DESC",
                [$moduleidquiz, $pccourseid, (string)$DB->get_field('quiz', 'name', ['id' => $sourcequizid])]
            );
            $reuseok = false;
            if ($existingquiz) {
                $cmrow_chk = $DB->get_record('course_modules', ['module' => $moduleidquiz, 'instance' => (int)$existingquiz->id, 'course' => $pccourseid], 'id,deletioninprogress');
                if ($cmrow_chk && empty($cmrow_chk->deletioninprogress)) { $reuseok = true; }
            }
            if ($reuseok) {
                $pqrec = (object)[
                    'personalcourseid' => $personalcourseid,
                    'quizid' => (int)$existingquiz->id,
                    'sourcequizid' => $sourcequizid,
                    'sectionname' => $prefix,
                    'quiztype' => $allessay ? 'essay' : 'non_essay',
                    'timecreated' => time(),
                    'timemodified' => time(),
                ];
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $pq = $pqrec;
            } else {
                $sectionnumber = $sm->ensure_section_by_prefix($pccourseid, $prefix);
                $name = (string)$DB->get_field('quiz', 'name', ['id' => $sourcequizid]);
                $res = $qb->create_quiz($pccourseid, $sectionnumber, $name, '', $settingsmode);
                $pqrec = (object)[
                    'personalcourseid' => $personalcourseid,
                    'quizid' => (int)$res->quizid,
                    'sourcequizid' => $sourcequizid,
                    'sectionname' => $prefix,
                    'quiztype' => $allessay ? 'essay' : 'non_essay',
                    'timecreated' => time(),
                    'timemodified' => time(),
                ];
                $pqrec->id = $DB->insert_record('local_personalcourse_quizzes', $pqrec);
                $pq = $pqrec;
            }
        } else {
            // Recreate if mapped quiz is missing or CM is being deleted.
            $needrecreate = false;
            if (!$DB->record_exists('quiz', ['id' => (int)$pq->quizid])) { $needrecreate = true; }
            else {
                $cmrow_chk = $DB->get_record('course_modules', ['module' => $moduleidquiz, 'instance' => (int)$pq->quizid, 'course' => $pccourseid], 'id,deletioninprogress');
                if (!$cmrow_chk || (!empty($cmrow_chk->deletioninprogress))) { $needrecreate = true; }
            }
            if ($needrecreate) {
                $sectionnumber = $sm->ensure_section_by_prefix($pccourseid, $prefix);
                $name = (string)$DB->get_field('quiz', 'name', ['id' => $sourcequizid]);
                $res = $qb->create_quiz($pccourseid, $sectionnumber, $name, '', $settingsmode);
                $pq->quizid = (int)$res->quizid;
                $DB->update_record('local_personalcourse_quizzes', (object)['id' => (int)$pq->id, 'quizid' => (int)$pq->quizid, 'timemodified' => time()]);
            }
        }

        // Build desired set = (source order) ∩ (flags ∪ incorrect from attempt/latest attempt).
        $srcquizqids = [];
        try {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                                   FROM {quiz_slots} qs\n                                                   JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                                   JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                                  WHERE qs.quizid = ?\n                                               ORDER BY qs.slot", [$sourcequizid]);
        } catch (\Throwable $e) {
            $srcquizqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL ORDER BY slot", [$sourcequizid]);
        }
        $flagqids = [];
        if (!empty($srcquizqids)) {
            list($insqlq, $inparamsq) = $DB->get_in_or_equal(array_map('intval', $srcquizqids), SQL_PARAMS_QM);
            $flagqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {local_questionflags} WHERE userid = ? AND questionid {$insqlq}", array_merge([$userid], $inparamsq));
        }
        $incorrect = [];
        if ($mode === 'union') {
            if (!empty($attemptid)) {
                $an = new \local_personalcourse\attempt_analyzer();
                $incorrect = $an->get_incorrect_questionids_from_attempt((int)$attemptid);
            }
            $unionids = array_values(array_unique(array_merge(array_map('intval', $flagqids ?: []), array_map('intval', $incorrect ?: []))));
            $desired = array_values(array_intersect(array_map('intval', $srcquizqids ?: []), $unionids));
        } else { // flags_only
            $desired = array_values(array_intersect(array_map('intval', $srcquizqids ?: []), array_map('intval', $flagqids ?: [])));
        }

        // Persist auto-blue for incorrects not already flagged.
        $autoblue = ($mode === 'union') ? array_values(array_diff(array_map('intval', $incorrect ?: []), array_map('intval', $flagqids ?: []))) : [];
        if (!empty($autoblue)) {
            $qfcols = [];
            try { $qfcols = $DB->get_columns('local_questionflags'); } catch (\Throwable $t) { $qfcols = []; }
            $hascmid = isset($qfcols['cmid']);
            $hasquizid = isset($qfcols['quizid']);
            $now = time();
            foreach ($autoblue as $qid) {
                if (!$DB->record_exists('local_questionflags', ['userid' => $userid, 'questionid' => (int)$qid])) {
                    $rec = (object)[
                        'userid' => $userid,
                        'questionid' => (int)$qid,
                        'flagcolor' => 'blue',
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ];
                    if ($hascmid) { $rec->cmid = $src_cmid ?: null; }
                    if ($hasquizid) { $rec->quizid = $sourcequizid; }
                    $DB->insert_record('local_questionflags', $rec);
                }
            }
        }

        // If we are in flags_only mode and the desired set is empty, remove the active personal quiz.
        if ($mode === 'flags_only' && empty($desired)) {
            // If a mapping exists, handle quiz cleanup.
            if ($pq && !empty($pq->quizid) && $DB->record_exists('quiz', ['id' => (int)$pq->quizid])) {
                // Delete any in-progress/overdue attempts for this user to unlock structural/CM changes.
                $attempts = $DB->get_records_select('quiz_attempts', "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')", [(int)$pq->quizid, (int)$userid], 'id ASC');
                if (!empty($attempts)) {
                    $quiz = $DB->get_record('quiz', ['id' => (int)$pq->quizid], '*', IGNORE_MISSING);
                    if ($quiz) {
                        try { $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$quiz->course, false, MUST_EXIST); if ($cm && !isset($quiz->cmid)) { $quiz->cmid = (int)$cm->id; } } catch (\Throwable $e) {}
                        foreach ($attempts as $a) { try { quiz_delete_attempt($a, $quiz); } catch (\Throwable $e) {} }
                    }
                }

                $hasfinished = (bool)$DB->record_exists_select('quiz_attempts', "quiz = ? AND userid = ? AND state = 'finished'", [(int)$pq->quizid, (int)$userid]);
                if ($hasfinished) {
                    // Archive: rename and hide on course page, then detach mapping.
                    try {
                        $oldname = (string)$DB->get_field('quiz', 'name', ['id' => (int)$pq->quizid], IGNORE_MISSING);
                        if ($oldname && stripos($oldname, '(Archived)') === false) {
                            $DB->set_field('quiz', 'name', ($oldname . ' (Archived)'), ['id' => (int)$pq->quizid]);
                        }
                        $oldcm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pccourseid, false, MUST_EXIST);
                        if ($oldcm) {
                            $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => (int)$oldcm->id]);
                            $DB->set_field('course_modules', 'visible', 1, ['id' => (int)$oldcm->id]);
                        }
                    } catch (\Throwable $e) {}
                    // Remove the mapping so no active PQ exists.
                    $DB->delete_records('local_personalcourse_quizzes', ['id' => (int)$pq->id]);
                } else {
                    // Safe to delete the entire module and mapping.
                    try {
                        $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pccourseid, false, MUST_EXIST);
                        if ($cm) {
                            course_delete_module((int)$cm->id);
                        }
                    } catch (\Throwable $e) {
                        // Fallback: if CM missing, delete quiz record to avoid orphans.
                        $DB->delete_records('quiz', ['id' => (int)$pq->quizid]);
                    }
                    // Clean auxiliary rows and mapping.
                    $DB->delete_records('local_personalcourse_questions', ['personalquizid' => (int)$pq->id]);
                    $DB->delete_records('local_personalcourse_quizzes', ['id' => (int)$pq->id]);
                }
            }
            // Rebuild cache and exit early; no personal quiz should remain active for this source.
            try { rebuild_course_cache((int)$pccourseid, true); } catch (\Throwable $e) {}
            return (object)[
                'personalcourseid' => $personalcourseid,
                'mappingid' => 0,
                'quizid' => 0,
                'cmid' => 0,
                'toadd' => [],
                'toremove' => [],
            ];
        }

        // Dedupe across the student's personal course: if any desired qid exists in another personal quiz, move it here.
        if (!empty($desired)) {
            foreach ($desired as $qid) {
                $existingpcq = $DB->get_record('local_personalcourse_questions', [
                    'personalcourseid' => (int)$personalcourseid,
                    'questionid' => (int)$qid,
                ]);
                if ($existingpcq && (int)$existingpcq->personalquizid !== (int)$pq->id) {
                    $oldpq = $DB->get_record('local_personalcourse_quizzes', ['id' => (int)$existingpcq->personalquizid], 'id, quizid');
                    if ($oldpq) { $qb->remove_question((int)$oldpq->quizid, (int)$qid); }
                    $DB->delete_records('local_personalcourse_questions', ['id' => (int)$existingpcq->id]);
                }
            }
        }

        // Current qids in personal quiz.
        $currqids = [];
        try {
            $currqids = $DB->get_fieldset_sql("SELECT DISTINCT qv.questionid\n                                               FROM {quiz_slots} qs\n                                               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                              WHERE qs.quizid = ?\n                                           ORDER BY qs.slot", [(int)$pq->quizid]);
        } catch (\Throwable $e) {
            $currqids = $DB->get_fieldset_sql("SELECT DISTINCT questionid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL ORDER BY slot", [(int)$pq->quizid]);
        }
        $currqids = array_map('intval', $currqids ?: []);

        // Detect any existing placeholder description questions in this quiz by idnumber prefix.
        $placeholderprefix = 'pcq_placeholder_';
        $phqids = [];
        try {
            $phqids = $DB->get_fieldset_sql("SELECT qv.questionid\n                                               FROM {quiz_slots} qs\n                                               JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                               JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid\n                                               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid\n                                              WHERE qs.quizid = ? AND qbe.idnumber LIKE ?", [(int)$pq->quizid, $placeholderprefix . '%']);
        } catch (\Throwable $e) { $phqids = []; }

        $toadd = array_values(array_diff($desired, $currqids));
        // Ensure additions are in the exact source order for consistent slot placement.
        if (!empty($toadd)) {
            $toadd = array_values(array_intersect($desired, $toadd));
        }
        $toremove = array_values(array_diff($currqids, $desired));

        // Always remove placeholder when we have real desired questions.
        if (!empty($desired) && !empty($phqids)) {
            $toremove = array_values(array_unique(array_merge($toremove, array_map('intval', $phqids))));
        }
        // When desired is empty, ensure there are no real questions left in the active personal quiz.
        // If finished attempts exist, fork-and-switch to a fresh quiz to preserve history.
        $needplaceholder = false;
        if (empty($desired)) {
            $realcurrqids = !empty($phqids) ? array_values(array_diff($currqids, array_map('intval', $phqids))) : $currqids;
            $hasfinished = (bool)$DB->record_exists_select('quiz_attempts', "quiz = ? AND userid = ? AND state = 'finished'", [(int)$pq->quizid, (int)$userid]);

            if (!empty($realcurrqids)) {
                if ($hasfinished) {
                    // Delete in-progress/overdue attempts.
                    $attempts = $DB->get_records_select('quiz_attempts', "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')", [(int)$pq->quizid, (int)$userid], 'id ASC');
                    if (!empty($attempts)) {
                        $quiz = $DB->get_record('quiz', ['id' => (int)$pq->quizid], '*', IGNORE_MISSING);
                        if ($quiz) {
                            try { $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$quiz->course, false, MUST_EXIST); if ($cm && !isset($quiz->cmid)) { $quiz->cmid = (int)$cm->id; } } catch (\Throwable $e) {}
                            foreach ($attempts as $a) { try { quiz_delete_attempt($a, $quiz); } catch (\Throwable $e) {} }
                        }
                    }
                    // Mark old quiz as archived and hide it from the course page (but keep accessible via direct links).
                    try {
                        $oldname = (string)$DB->get_field('quiz', 'name', ['id' => (int)$pq->quizid], IGNORE_MISSING);
                        if ($oldname && stripos($oldname, '(Archived)') === false) {
                            $DB->set_field('quiz', 'name', ($oldname . ' (Archived)'), ['id' => (int)$pq->quizid]);
                        }
                        $oldcm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$pccourseid, false, MUST_EXIST);
                        if ($oldcm) {
                            // Keep visible=1 so direct attempt/review links still work, but hide on course page.
                            $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => (int)$oldcm->id]);
                            $DB->set_field('course_modules', 'visible', 1, ['id' => (int)$oldcm->id]);
                            // Rebuild course cache so the change is reflected immediately in the course index.
                            try { rebuild_course_cache((int)$pccourseid, true); } catch (\Throwable $e2) {}
                        }
                    } catch (\Throwable $e) {}

                    // Create a fresh quiz and switch mapping.
                    $sectionnumber = $sm->ensure_section_by_prefix($pccourseid, $prefix);
                    $newname = (string)$DB->get_field('quiz', 'name', ['id' => $sourcequizid]);
                    $resnew = $qb->create_quiz($pccourseid, $sectionnumber, $newname, '', $settingsmode);
                    $pq->quizid = (int)$resnew->quizid;
                    $DB->update_record('local_personalcourse_quizzes', (object)['id' => (int)$pq->id, 'quizid' => (int)$pq->quizid, 'timemodified' => time()]);

                    // Reset local sets; we will add a placeholder below.
                    $currqids = [];
                    $phqids = [];
                    $toadd = [];
                    $toremove = [];
                    $needplaceholder = true;
                } else {
                    // Edit in place: remove all real questions.
                    $toadd = [];
                    $toremove = array_values(array_unique(array_merge($toremove, $realcurrqids)));
                    $needplaceholder = true;
                }
            } else {
                // No real questions currently present; ensure a placeholder exists.
                $needplaceholder = true;
            }
        }

        // Delete in-progress/overdue attempts before structural changes.
        if (!empty($toadd) || !empty($toremove)) {
            $attempts = $DB->get_records_select('quiz_attempts', "quiz = ? AND userid = ? AND state IN ('inprogress','overdue')", [(int)$pq->quizid, (int)$userid], 'id ASC');
            if (!empty($attempts)) {
                $quiz = $DB->get_record('quiz', ['id' => (int)$pq->quizid], '*', IGNORE_MISSING);
                if ($quiz) {
                    try { $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, (int)$quiz->course, false, MUST_EXIST); if ($cm && !isset($quiz->cmid)) { $quiz->cmid = (int)$cm->id; } } catch (\Throwable $e) {}
                    foreach ($attempts as $a) { try { quiz_delete_attempt($a, $quiz); } catch (\Throwable $e) {} }
                }
            }
        }

        if (!empty($toremove)) {
            foreach ($toremove as $qid) {
                $qb->remove_question((int)$pq->quizid, (int)$qid);
                $DB->delete_records('local_personalcourse_questions', ['personalquizid' => (int)$pq->id, 'questionid' => (int)$qid]);
            }
        }
        if (!empty($toadd)) {
            $qb->add_questions((int)$pq->quizid, array_map('intval', $toadd));
            $now = time();
            foreach ($toadd as $qid) {
                $existsrow = $DB->get_record('local_personalcourse_questions', [
                    'personalcourseid' => (int)$personalcourseid,
                    'questionid' => (int)$qid,
                ]);
                if ($existsrow) {
                    $existsrow->personalquizid = (int)$pq->id;
                    if (empty($existsrow->flagcolor)) { $existsrow->flagcolor = 'blue'; }
                    $existsrow->timemodified = $now;
                    $DB->update_record('local_personalcourse_questions', $existsrow);
                } else {
                    $DB->insert_record('local_personalcourse_questions', (object)[
                        'personalcourseid' => (int)$personalcourseid,
                        'personalquizid' => (int)$pq->id,
                        'questionid' => (int)$qid,
                        'slotid' => null,
                        'flagcolor' => in_array((int)$qid, array_map('intval', $flagqids ?: []), true) ? 'blue' : 'blue',
                        'source' => in_array((int)$qid, array_map('intval', $flagqids ?: []), true) ? 'manual_flag' : 'auto',
                        'originalposition' => null,
                        'currentposition' => null,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }

        // Ensure a single zero-mark placeholder exists when needed and there are no slots.
        if ($needplaceholder) {
            $slotcount = (int)$DB->count_records('quiz_slots', ['quizid' => (int)$pq->quizid]);
            if ($slotcount === 0) {
                $placeholderprefix = 'pcq_placeholder_';
                $phidnumber = $placeholderprefix . $userid . '_' . $sourcequizid;
                $placeholderqid = (int)$DB->get_field_sql("SELECT qv.questionid FROM {question_versions} qv JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid WHERE qbe.idnumber = ?", [$phidnumber]);
                if ($placeholderqid <= 0) {
                    // Create placeholder in course context.
                    $coursectx = \context_course::instance($pccourseid);
                    $qcat = $DB->get_record('question_categories', ['contextid' => (int)$coursectx->id], 'id', IGNORE_MISSING);
                    if (!$qcat) {
                        $qcat = (object)[
                            'name' => 'Personal Course Placeholders',
                            'contextid' => (int)$coursectx->id,
                            'info' => '',
                            'infoformat' => 1,
                            'stamp' => uniqid('pcqcat_'),
                            'parent' => 0,
                            'sortorder' => 9999,
                            'idnumber' => 'pcq_placeholders',
                        ];
                        $qcat->id = (int)$DB->insert_record('question_categories', $qcat);
                    }
                    $now = time();
                    $q = (object)[
                        'category' => (int)$qcat->id,
                        'parent' => 0,
                        'name' => 'Personal Quiz Empty Placeholder',
                        'questiontext' => '<p>No questions are currently available. Add blue/red flags on your quizzes to populate your Personal Quiz.</p>',
                        'questiontextformat' => 1,
                        'generalfeedback' => '',
                        'generalfeedbackformat' => 1,
                        'defaultmark' => 0.0,
                        'penalty' => 0.0,
                        'qtype' => 'truefalse',
                        'length' => 1,
                        'stamp' => uniqid('pcq_'),
                        'timecreated' => $now,
                        'timemodified' => $now,
                        'createdby' => $userid,
                        'modifiedby' => $userid,
                    ];
                    $placeholderqid = (int)$DB->insert_record('question', $q);
                    $qbe = (object)[
                        'questioncategoryid' => (int)$qcat->id,
                        'idnumber' => $phidnumber,
                    ];
                    $qbe->id = (int)$DB->insert_record('question_bank_entries', $qbe);
                    $qv = (object)[
                        'questionbankentryid' => (int)$qbe->id,
                        'version' => 1,
                        'questionid' => (int)$placeholderqid,
                        'status' => 'ready',
                        'timecreated' => $now,
                    ];
                    $DB->insert_record('question_versions', $qv);
                    // Answers/options.
                    $ansTrue = (object)[
                        'question' => (int)$placeholderqid,
                        'answer' => get_string('true', 'qtype_truefalse'),
                        'fraction' => 1.0,
                        'feedback' => '',
                        'feedbackformat' => 1,
                    ];
                    $ansTrue->id = (int)$DB->insert_record('question_answers', $ansTrue);
                    $ansFalse = (object)[
                        'question' => (int)$placeholderqid,
                        'answer' => get_string('false', 'qtype_truefalse'),
                        'fraction' => 0.0,
                        'feedback' => '',
                        'feedbackformat' => 1,
                    ];
                    $ansFalse->id = (int)$DB->insert_record('question_answers', $ansFalse);
                    $opt = (object)[
                        'question' => (int)$placeholderqid,
                        'trueanswer' => (int)$ansTrue->id,
                        'falseanswer' => (int)$ansFalse->id,
                        'showstandardinstruction' => 1,
                    ];
                    $DB->insert_record('question_truefalse', $opt);
                }
                // Add placeholder as the only slot.
                require_once($CFG->dirroot . '/local/quiz_uploader/classes/quiz_creator.php');
                \local_quiz_uploader\quiz_creator::add_questions_to_quiz((int)$pq->quizid, [(int)$placeholderqid]);
            }
        }

        try {
            $slotrows = [];
            try {
                $slotrows = $DB->get_records_sql("SELECT qs.id AS slotid, qs.slot AS slotnum, qv.questionid AS qid
                                                     FROM {quiz_slots} qs
                                                     JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                                     JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                                                    WHERE qs.quizid = ?", [(int)$pq->quizid]);
            } catch (\Throwable $e) {
                $slotrows = $DB->get_records_sql("SELECT id AS slotid, slot AS slotnum, questionid AS qid FROM {quiz_slots} WHERE quizid = ? AND questionid IS NOT NULL", [(int)$pq->quizid]);
            }
            if (!empty($slotrows)) {
                $byqid = [];
                foreach ($slotrows as $r) { if (!isset($byqid[(int)$r->qid])) { $byqid[(int)$r->qid] = $r; } }
                $trans = $DB->start_delegated_transaction();
                $pos = 1;
                foreach ($desired as $qid) {
                    $qid = (int)$qid;
                    if (!isset($byqid[$qid])) { continue; }
                    $row = $byqid[$qid];
                    if ((int)$row->slotnum !== (int)$pos) {
                        $DB->set_field('quiz_slots', 'slot', (int)$pos, ['id' => (int)$row->slotid]);
                    }
                    $pos++;
                }
                $quiz = $DB->get_record('quiz', ['id' => (int)$pq->quizid], '*', IGNORE_MISSING);
                if ($quiz) {
                    $qpp = (int)($quiz->questionsperpage ?? 1);
                    if ($qpp <= 0) { $qpp = 1; }
                    quiz_repaginate_questions((int)$pq->quizid, $qpp);
                    quiz_update_sumgrades($quiz);
                }
                $trans->allow_commit();
            }
        } catch (\Throwable $e) { }

        // Compute cmid for return.
        $cmid = (int)$DB->get_field('course_modules', 'id', ['module' => $moduleidquiz, 'instance' => (int)$pq->quizid, 'course' => $pccourseid], IGNORE_MISSING);
        if ($cmid <= 0) {
            try { $cm = get_coursemodule_from_instance('quiz', (int)$pq->quizid, $pccourseid, false, MUST_EXIST); $cmid = (int)$cm->id; } catch (\Throwable $e) {}
        }

        return (object)[
            'personalcourseid' => $personalcourseid,
            'mappingid' => (int)$pq->id,
            'quizid' => (int)$pq->quizid,
            'cmid' => $cmid,
            'toadd' => $toadd,
            'toremove' => $toremove,
        ];
    }
}
