<?php
namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

class homework_injector {
    public static function inject_single_essay(int $userid, string $label, array $items): object {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/local/personalcourse/classes/course_generator.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/enrollment_manager.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/section_manager.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/quiz_builder.php');
        $cg = new \local_personalcourse\course_generator();
        $pcctx = $cg->ensure_personal_course($userid);
        $courseid = (int)$pcctx->course->id;
        try { $en = new \local_personalcourse\enrollment_manager(); $en->ensure_manual_instance_and_enrol_student($courseid, $userid); } catch (\Throwable $e) {}
        // Compute student initials for section and quiz naming
        $u = $DB->get_record('user', ['id' => (int)$userid], 'id,firstname,lastname,username');
        $fi = $u && !empty($u->firstname) ? mb_substr($u->firstname, 0, 1, 'UTF-8') : '';
        $li = $u && !empty($u->lastname) ? mb_substr($u->lastname, 0, 1, 'UTF-8') : '';
        $initial = strtoupper(($fi.$li) !== '' ? ($fi.$li) : ($u && !empty($u->username) ? mb_substr($u->username, 0, 2, 'UTF-8') : 'HW'));
        $sm = new \local_personalcourse\section_manager();
        $sectionnum = $sm->ensure_section_by_prefix($courseid, $initial.'-Essay Feedback Homework');
        $qb = new \local_personalcourse\quiz_builder();
        // Derive topic from label: remove trailing "- / – Attempt N" and leading class code like "5A - "
        $topic = (string)$label;
        // Strip attempt suffix (both hyphen and en-dash variants)
        $topic = preg_replace('/\s*[\x{2013}\x{2014}\-]\s*Attempt\s*#?\s*\d+$/u', '', (string)$topic);
        // If label looks like "5A - Topic", drop the class code prefix
        if (preg_match('/^\s*([0-9]+[A-Za-z]?)\s*-\s*(.+)$/u', (string)$topic, $m)) {
            $topic = $m[2];
        }
        // Remove leading "Writing - " (or variants) from topic if present
        $topic = preg_replace('/^\s*Writing\s*[-–—:]\s*/iu', '', (string)$topic);
        $topic = trim((string)$topic);
        $name = $initial . '-' . $topic;

        // Overwrite existing quiz for the same topic by deleting any prior quiz with the same name.
        require_once($CFG->dirroot . '/course/lib.php');
        $existingquiz = $DB->get_record('quiz', ['course' => (int)$courseid, 'name' => (string)$name], 'id', \IGNORE_MISSING);
        if ($existingquiz && !empty($existingquiz->id)) {
            $cm = get_coursemodule_from_instance('quiz', (int)$existingquiz->id, (int)$courseid, \IGNORE_MISSING);
            if ($cm && !empty($cm->id)) {
                try { course_delete_module((int)$cm->id); } catch (\Throwable $e) { /* ignore and continue */ }
            } else {
                // Fallback: remove the quiz record if CM lookup failed.
                $DB->delete_records('quiz', ['id' => (int)$existingquiz->id]);
            }
        }

        $res = $qb->create_quiz($courseid, $sectionnum, $name, '', 'default');
        $quizid = (int)$res->quizid;
        $coursectx = \context_course::instance($courseid);
        $qcat = $DB->get_record('question_categories', ['contextid' => (int)$coursectx->id, 'idnumber' => 'pc_homework'], 'id,contextid');
        if (!$qcat) {
            $qcat = (object)[
                'name' => 'Personal Course Homework',
                'contextid' => (int)$coursectx->id,
                'info' => '',
                'infoformat' => 1,
                'stamp' => uniqid('pc_hw'),
                'parent' => 0,
                'sortorder' => 9999,
                'idnumber' => 'pc_homework',
            ];
            $qcat->id = (int)$DB->insert_record('question_categories', $qcat);
        }
        $orig = [];
        $sugg = [];
        foreach ($items as $it) {
            $o = isset($it['original']) ? (string)$it['original'] : '';
            $g = isset($it['suggested']) ? (string)$it['suggested'] : '';
            if ($o !== '') { $orig[] = $o; }
            if ($g !== '') { $sugg[] = $g; }
        }
        $count = count($orig);
        $list = '';
        for ($i=0;$i<$count;$i++) { $n=$i+1; $safeo = htmlspecialchars($orig[$i], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); $list .= '<p><strong>'.$n.'. Original:</strong> '. $safeo .'</p>'; }
        $questiontext = '<div><h4>Sentence Improvement</h4><p style="color:#0066cc;">Rewrite each sentence clearly and correctly.</p>'.$list.'<p>Write your improved sentences below, numbered 1 to '.$count.'.</p></div>';
        $responsetemplate = '';
        for ($i=0;$i<$count;$i++) { $n=$i+1; $responsetemplate .= $n.")\n\n"; }
        $now = time();
        $q = (object)[
            'category' => (int)$qcat->id,
            'parent' => 0,
            'name' => 'Sentence Improvement',
            'questiontext' => $questiontext,
            'questiontextformat' => 1,
            'generalfeedback' => '',
            'generalfeedbackformat' => 1,
            'defaultmark' => 10.0,
            'penalty' => 0.0,
            'qtype' => 'essay',
            'length' => 1,
            'stamp' => uniqid('qd_hw_'),
            'timecreated' => $now,
            'timemodified' => $now,
            'createdby' => isset($USER->id) ? (int)$USER->id : 0,
            'modifiedby' => isset($USER->id) ? (int)$USER->id : 0,
        ];
        $qid = (int)$DB->insert_record('question', $q);
        $idnumber = 'qd_hw_'.$userid.'_'.substr(sha1(json_encode($orig).json_encode($sugg).$label),0,12);
        $qbe = (object)['questioncategoryid' => (int)$qcat->id, 'idnumber' => $idnumber];
        $qbe->id = (int)$DB->insert_record('question_bank_entries', $qbe);
        $qv = (object)['questionbankentryid' => (int)$qbe->id, 'version' => 1, 'questionid' => (int)$qid, 'status' => 'ready', 'timecreated' => $now];
        $DB->insert_record('question_versions', $qv);
        $opts = (object)[
            'questionid' => (int)$qid,
            'responseformat' => 'editor',
            'responserequired' => 1,
            'responsefieldlines' => 12,
            'attachments' => 0,
            'attachmentsrequired' => 0,
            'graderinfo' => json_encode(['suggested' => $sugg], JSON_UNESCAPED_UNICODE),
            'graderinfoformat' => 1,
            'responsetemplate' => $responsetemplate,
            'responsetemplateformat' => 1,
        ];
        $DB->insert_record('qtype_essay_options', $opts);
        $qb->add_questions($quizid, [(int)$qid]);
        $cmid = (int)$DB->get_field('course_modules', 'id', ['instance' => (int)$quizid, 'module' => (int)$DB->get_field('modules', 'id', ['name' => 'quiz'])], \IGNORE_MISSING);
        return (object)['quizid' => $quizid, 'cmid' => $cmid, 'courseid' => $courseid, 'questionid' => (int)$qid];
    }

    /**
     * Inject a homework quiz from structured JSON (SI + MCQ)
     * JSON example: { "meta": {"level":"general"}, "items": [ {"type":"si","original":"...","improved":"..."}, {"type":"mcq","stem":"...","single":true,"options":[{"text":"...","correct":true},...] } ] }
     */
    public static function inject_from_json(int $userid, string $label, string $jsontext, string $level = 'general'): object {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/local/personalcourse/classes/course_generator.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/enrollment_manager.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/section_manager.php');
        require_once($CFG->dirroot . '/local/personalcourse/classes/quiz_builder.php');
        require_once($CFG->dirroot . '/local/quiz_uploader/classes/question_importer.php');

        $cg = new \local_personalcourse\course_generator();
        $pcctx = $cg->ensure_personal_course($userid);
        $courseid = (int)$pcctx->course->id;
        try { $en = new \local_personalcourse\enrollment_manager(); $en->ensure_manual_instance_and_enrol_student($courseid, $userid); } catch (\Throwable $e) {}
        // Compute student initials for section and quiz naming
        $u = $DB->get_record('user', ['id' => (int)$userid], 'id,firstname,lastname,username');
        $fi = $u && !empty($u->firstname) ? mb_substr($u->firstname, 0, 1, 'UTF-8') : '';
        $li = $u && !empty($u->lastname) ? mb_substr($u->lastname, 0, 1, 'UTF-8') : '';
        $initial = strtoupper(($fi.$li) !== '' ? ($fi.$li) : ($u && !empty($u->username) ? mb_substr($u->username, 0, 2, 'UTF-8') : 'HW'));
        $sm = new \local_personalcourse\section_manager();
        $sectionnum = $sm->ensure_section_by_prefix($courseid, $initial.'-Essay Feedback Homework');
        $qb = new \local_personalcourse\quiz_builder();
        // Derive topic from label: remove trailing "- / – Attempt N" and leading class code like "5A - "
        $topic = (string)$label;
        // Strip attempt suffix (both hyphen and en-dash variants)
        $topic = preg_replace('/\s*[\x{2013}\x{2014}\-]\s*Attempt\s*#?\s*\d+$/u', '', (string)$topic);
        // If label looks like "5A - Topic", drop the class code prefix
        if (preg_match('/^\s*([0-9]+[A-Za-z]?)\s*-\s*(.+)$/u', (string)$topic, $m)) {
            $topic = $m[2];
        }
        // Remove leading "Writing - " (or variants) from topic if present
        $topic = preg_replace('/^\s*Writing\s*[-–—:]\s*/iu', '', (string)$topic);
        $topic = trim((string)$topic);
        $name = $initial . '-' . $topic;
        // Overwrite existing quiz for the same topic by deleting any prior quiz with the same name.
        require_once($CFG->dirroot . '/course/lib.php');
        $existingquiz = $DB->get_record('quiz', ['course' => (int)$courseid, 'name' => (string)$name], 'id', \IGNORE_MISSING);
        if ($existingquiz && !empty($existingquiz->id)) {
            $cm = get_coursemodule_from_instance('quiz', (int)$existingquiz->id, (int)$courseid, \IGNORE_MISSING);
            if ($cm && !empty($cm->id)) {
                try { course_delete_module((int)$cm->id); } catch (\Throwable $e) { /* ignore */ }
            } else {
                $DB->delete_records('quiz', ['id' => (int)$existingquiz->id]);
            }
        }
        $res = $qb->create_quiz($courseid, $sectionnum, $name, '', 'default');
        $quizid = (int)$res->quizid;

        $coursectx = \context_course::instance($courseid);
        $qcat = $DB->get_record('question_categories', ['contextid' => (int)$coursectx->id, 'idnumber' => 'pc_homework'], 'id,contextid');
        if (!$qcat) {
            $qcat = (object)[
                'name' => 'Personal Course Homework',
                'contextid' => (int)$coursectx->id,
                'info' => '',
                'infoformat' => 1,
                'stamp' => uniqid('pc_hw'),
                'parent' => 0,
                'sortorder' => 9999,
                'idnumber' => 'pc_homework',
            ];
            $qcat->id = (int)$DB->insert_record('question_categories', $qcat);
        }

        $j = json_decode($jsontext, true);
        if (!is_array($j)) { throw new \moodle_exception('Invalid JSON for injection'); }
        $items = isset($j['items']) && is_array($j['items']) ? $j['items'] : [];

        // Split SI and MCQ (infer when type is missing)
        $si = [];
        $mcq = [];
        foreach ($items as $it) {
            $type = isset($it['type']) ? strtolower(trim((string)$it['type'])) : '';
            // Heuristics for type inference
            $orig = trim((string)($it['original'] ?? ''));
            $imprAny = (string)($it['improved'] ?? ($it['suggested'] ?? ($it['rewrite'] ?? ($it['improved_sentence'] ?? ''))));
            $impr = trim($imprAny);
            $stem = trim((string)($it['stem'] ?? ''));
            $options = isset($it['options']) && is_array($it['options']) ? $it['options'] : [];

            if ($type === '') {
                if ($orig !== '' && $impr !== '') { $type = 'si'; }
                elseif ($stem !== '' && !empty($options)) { $type = 'mcq'; }
            }
            if ($type !== '') {
                if ($type !== 'si' && (strpos($type, 'sentence') !== false || $type === 'sa' || $type === 'shortanswer')) { $type = 'si'; }
                if ($type !== 'mcq' && (strpos($type, 'mcq') !== false || strpos($type, 'multi') !== false || strpos($type, 'choice') !== false)) { $type = 'mcq'; }
                if ($type === 'si') { $si[] = $it; }
                if ($type === 'mcq') { $mcq[] = $it; }
            }
        }

        // Enforce SI length rule and cap at 10
        $siFiltered = [];
        $siDropped = 0;
        $siDropReasons = [];
        foreach ($si as $idx => $it) {
            $orig = trim((string)($it['original'] ?? ($it['original_sentence'] ?? ($it['before'] ?? ''))));
            // Expand field name recognition to include more AI variations
            $imprRaw = $it['improved'] ?? 
                       $it['suggested'] ?? 
                       $it['rewrite'] ?? 
                       $it['improved_sentence'] ?? 
                       $it['improvement'] ?? 
                       $it['corrected'] ?? 
                       $it['corrected_sentence'] ?? 
                       $it['revised'] ?? 
                       $it['revised_sentence'] ?? 
                       $it['better'] ?? 
                       $it['better_sentence'] ?? 
                       $it['fix'] ?? 
                       $it['fixed'] ?? 
                       $it['after'] ?? '';
            $impr = trim((string)$imprRaw);
            
            if ($orig === '' || $impr === '') {
                $siDropped++;
                $siDropReasons[] = "SI[$idx]: empty (orig=".mb_strlen($orig).", impr=".mb_strlen($impr).") - keys present: ".implode(',', array_keys($it));
                continue;
            }
            
            $origLen = mb_strlen($orig);
            $imprLen = mb_strlen($impr);
            
            $siFiltered[] = ['original' => $orig, 'improved' => $impr];
            if (count($siFiltered) >= 10) { break; }
        }
        
        // Log SI filtering details
        $logPath = $CFG->dirroot . '/local/quizdashboard/logs/homework_json.log';
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        $logMsg = "[".date('Y-m-d H:i:s')."] SI_FILTER; total=".count($si)."; accepted=".count($siFiltered)."; dropped=$siDropped";
        if ($siDropped > 0 && count($siDropReasons) > 0) {
            $logMsg .= "; reasons: " . implode('; ', array_slice($siDropReasons, 0, 5));
        }
        $logMsg .= "\n";
        @file_put_contents($logPath, $logMsg, FILE_APPEND);

        // Pre-validate MCQs using the same acceptance criteria as the builder loop
        $acceptedMcq = 0;
        foreach ($mcq as $m) {
            $stem = trim((string)($m['stem'] ?? ''));
            $options = isset($m['options']) && is_array($m['options']) ? $m['options'] : [];
            if ($stem === '') { continue; }
            $opts = [];
            foreach ($options as $o) {
                $txt = isset($o['text']) ? trim((string)$o['text']) : '';
                if ($txt !== '') { $opts[] = ['text' => $txt, 'correct' => !empty($o['correct'])]; }
            }
            if (count($opts) < 4) { continue; }
            $acceptedMcq++;
            if ($acceptedMcq >= 15) { break; }
        }

        if ($acceptedMcq < 15 || count($siFiltered) < 3) {
            // Clean up the quiz we just created to avoid leaving partial/empty quizzes
            try {
                require_once($CFG->dirroot . '/course/lib.php');
                $cm = get_coursemodule_from_instance('quiz', (int)$quizid, (int)$courseid, \IGNORE_MISSING);
                if ($cm && !empty($cm->id)) { course_delete_module((int)$cm->id); }
                else { $DB->delete_records('quiz', ['id' => (int)$quizid]); }
            } catch (\Throwable $e) { /* non-fatal */ }
            throw new \moodle_exception('Homework generation incomplete: accepted '.$acceptedMcq.' MCQ and '.count($siFiltered).' SI (min: 15 MCQ, 3 SI). Please retry.');
        }

        // Build Moodle XML - MCQs first, then SI last
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><quiz>";

        // MCQ (includes Vocabulary Builder as MCQ)
        $qnum = 0;
        $sectionExercise = '';
        $sectionTips = '';
        $acceptedInSection = 0; // reset after every 5 accepted MCQs
        foreach ($mcq as $m) {
            $stem = trim((string)($m['stem'] ?? ''));
            $options = isset($m['options']) && is_array($m['options']) ? $m['options'] : [];
            if ($stem === '') { continue; }
            $opts = [];
            foreach ($options as $o) {
                $txt = isset($o['text']) ? trim((string)$o['text']) : '';
                if ($txt !== '') { $opts[] = ['text' => $txt, 'correct' => !empty($o['correct']), 'feedback' => isset($o['feedback']) ? (string)$o['feedback'] : '' ]; }
            }
            if (count($opts) < 4) { continue; }
            if (count($opts) > 4) { $opts = array_slice($opts, 0, 4); }
            $firstCorrect = null;
            foreach ($opts as $idx => $o) { if (!empty($o['correct']) && $firstCorrect === null) { $firstCorrect = $idx; } }
            if ($firstCorrect === null) { $firstCorrect = 0; }
            foreach ($opts as $idx => $o) { $opts[$idx]['correct'] = ($idx === $firstCorrect); }
            $single = !empty($m['single']) ? 'true' : 'false';
            $qnum++;
            $qname = htmlspecialchars('MCQ '.$qnum, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

            // Keep Exercise/Tips consistent within a 5-question section
            $incomingExercise = isset($m['exercise']) ? trim((string)$m['exercise']) : '';
            $incomingTips = isset($m['tips']) ? trim((string)$m['tips']) : '';
            if ($sectionExercise === '' && $incomingExercise !== '') { $sectionExercise = $incomingExercise; }
            if ($sectionTips === '' && $incomingTips !== '') { $sectionTips = $incomingTips; }
            $exercise = $sectionExercise !== '' ? $sectionExercise : $incomingExercise;
            $tips = $sectionTips !== '' ? $sectionTips : $incomingTips;

            // Build question text with section-level Exercise Type and Tips header
            $header = '';
            if ($exercise !== '' || $tips !== '') {
                $hp = [];
                if ($exercise !== '') { $hp[] = '<strong>Exercise Type:</strong> '.htmlspecialchars($exercise, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
                if ($tips !== '') { $hp[] = '<strong style="color:#0066cc;">Tips for Improvement:</strong> <span style="color:#0066cc;">'.htmlspecialchars($tips, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</span>'; }
                $header = '<div class="hw-context">'.implode('<br>', $hp).'</div>';
            }
            $qtext = ($header !== '' ? $header.'<hr>' : '').'<p>'.htmlspecialchars($stem, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</p>';

            // Build general feedback with Exercise Type / Tips / Explanation if provided
            $gfparts = [];
            $expl = isset($m['explanation']) ? trim((string)$m['explanation']) : '';
            if ($exercise !== '') { $gfparts[] = 'Exercise Type: '.htmlspecialchars($exercise, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
            if ($tips !== '') { $gfparts[] = 'Tips for Improvement: '.htmlspecialchars($tips, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
            if ($expl !== '') { $gfparts[] = 'Explanation: '.htmlspecialchars($expl, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
            $gfb = implode('<br>', $gfparts);

            $xml .= '<question type="multichoice">'
                 . '<name><text>'.$qname.'</text></name>'
                 . '<questiontext format="html"><text><![CDATA['.$qtext.']]></text></questiontext>'
                 . '<generalfeedback format="html"><text><![CDATA['.$gfb.']]></text></generalfeedback>'
                 . '<defaultgrade>1</defaultgrade>'
                 . '<penalty>0</penalty>'
                 . '<single>'.$single.'</single>'
                 . '<shuffleanswers>1</shuffleanswers>'
                 . '<answernumbering>abc</answernumbering>';
            foreach ($opts as $o) {
                $txt = htmlspecialchars((string)$o['text'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
                $frac = !empty($o['correct']) ? '100' : '0';
                $xml .= '<answer fraction="'.$frac.'" format="moodle_auto_format"><text>'.$txt.'</text>'
                     . '<feedback><text>'.htmlspecialchars((string)$o['feedback'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</text></feedback></answer>';
            }
            $xml .= '</question>';
            $acceptedInSection++;
            if ($acceptedInSection >= 5) {
                // Start a new section
                $acceptedInSection = 0;
                $sectionExercise = '';
                $sectionTips = '';
            }
            if ($qnum >= 20) { break; }
        }

        // Short Answer for SI (last)
        $index = 0;
        foreach ($siFiltered as $it) {
            $index++;
            $orig = $it['original'];
            $impr = $it['improved'];
            $minlen = 10; // cap pattern length
            $pattern = str_repeat('?', $minlen) . '*';
            $qname = htmlspecialchars('SI '.$index, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
            $qtext = '<p style="color:#0066cc;">Rewrite the following sentence clearly and correctly:</p><p><em>Original: '.htmlspecialchars($orig, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</em></p>';
            $gfb = 'Suggested improvement: '.htmlspecialchars($impr, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
            $xml .= '<question type="shortanswer">'
                 . '<name><text>'.$qname.'</text></name>'
                 . '<questiontext format="html"><text><![CDATA['.$qtext.']]></text></questiontext>'
                 . '<generalfeedback format="html"><text><![CDATA['.$gfb.']]></text></generalfeedback>'
                 . '<defaultgrade>1</defaultgrade>'
                 . '<penalty>0</penalty>'
                 . '<usecase>0</usecase>'
                 . '<answer fraction="100" format="moodle_auto_format"><text>'.$pattern.'</text><feedback><text></text></feedback></answer>'
                 . '</question>';
        }

        $xml .= '</quiz>';

        // Import XML and add questions
        $category = (object)['id' => (int)$qcat->id, 'contextid' => (int)$qcat->contextid];
        $import = \local_quiz_uploader\question_importer::import_from_xml($xml, $category, $courseid);
        if (empty($import->success) || empty($import->questionids)) {
            throw new \moodle_exception('Failed to import generated questions');
        }
        $qb->add_questions($quizid, array_map('intval', $import->questionids));
        $cmid = (int)$DB->get_field('course_modules', 'id', ['instance' => (int)$quizid, 'module' => (int)$DB->get_field('modules', 'id', ['name' => 'quiz'])], \IGNORE_MISSING);
        return (object)['quizid' => $quizid, 'cmid' => $cmid, 'courseid' => $courseid, 'questioncount' => count($import->questionids)];
    }
}
