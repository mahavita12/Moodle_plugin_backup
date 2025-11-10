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
        $sm = new \local_personalcourse\section_manager();
        $sectionnum = $sm->ensure_section_by_prefix($courseid, 'Homework');
        $qb = new \local_personalcourse\quiz_builder();
        // Derive topic from label: remove trailing "- / – Attempt N" and leading class code like "5A - "
        $topic = (string)$label;
        // Strip attempt suffix (both hyphen and en-dash variants)
        $topic = preg_replace('/\s*[\x{2013}\x{2014}\-]\s*Attempt\s*#?\s*\d+$/u', '', (string)$topic);
        // If label looks like "5A - Topic", drop the class code prefix
        if (preg_match('/^\s*([0-9]+[A-Za-z]?)\s*-\s*(.+)$/u', (string)$topic, $m)) {
            $topic = $m[2];
        }
        $topic = trim((string)$topic);
        $name = 'Essay Homework - ' . $topic;
        $res = $qb->create_quiz($courseid, $sectionnum, $name, '', 'default');
        $quizid = (int)$res->quizid;
        $coursectx = \context_course::instance($courseid);
        $qcat = $DB->get_record('question_categories', ['contextid' => (int)$coursectx->id, 'idnumber' => 'pc_homework'], 'id');
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
        $questiontext = '<div><h4>Sentence Improvement</h4><p>Rewrite each sentence clearly and correctly.</p>'.$list.'<p>Write your improved sentences below, numbered 1 to '.$count.'.</p></div>';
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
        $sm = new \local_personalcourse\section_manager();
        $sectionnum = $sm->ensure_section_by_prefix($courseid, 'Homework');
        $qb = new \local_personalcourse\quiz_builder();
        // Derive topic from label: remove trailing "- / – Attempt N" and leading class code like "5A - "
        $topic = (string)$label;
        // Strip attempt suffix (both hyphen and en-dash variants)
        $topic = preg_replace('/\s*[\x{2013}\x{2014}\-]\s*Attempt\s*#?\s*\d+$/u', '', (string)$topic);
        // If label looks like "5A - Topic", drop the class code prefix
        if (preg_match('/^\s*([0-9]+[A-Za-z]?)\s*-\s*(.+)$/u', (string)$topic, $m)) {
            $topic = $m[2];
        }
        $topic = trim((string)$topic);
        $name = 'Essay Homework - ' . $topic;
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

        // Split SI and MCQ
        $si = [];
        $mcq = [];
        foreach ($items as $it) {
            $type = isset($it['type']) ? strtolower((string)$it['type']) : '';
            if ($type === 'si') { $si[] = $it; }
            if ($type === 'mcq') { $mcq[] = $it; }
        }

        // Enforce SI length rule and cap at 10
        $siFiltered = [];
        foreach ($si as $it) {
            $orig = trim((string)($it['original'] ?? ''));
            $impr = trim((string)($it['improved'] ?? ''));
            if ($orig === '' || $impr === '') { continue; }
            if (mb_strlen($impr) < mb_strlen($orig)) { continue; }
            $siFiltered[] = ['original' => $orig, 'improved' => $impr];
            if (count($siFiltered) >= 10) { break; }
        }

        // Build Moodle XML - MCQs first, then SI last
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><quiz>";

        // MCQ (includes Vocabulary Builder as MCQ)
        $qnum = 0;
        foreach ($mcq as $m) {
            $stem = trim((string)($m['stem'] ?? ''));
            $options = isset($m['options']) && is_array($m['options']) ? $m['options'] : [];
            if ($stem === '' || count($options) < 4) { continue; }
            $hasCorrect = false; foreach ($options as $o) { if (!empty($o['correct'])) { $hasCorrect = true; break; } }
            if (!$hasCorrect) { continue; }
            $single = !empty($m['single']) ? 'true' : 'false';
            $qnum++;
            $qname = htmlspecialchars('MCQ '.$qnum, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
            $qtext = '<p>'.htmlspecialchars($stem, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</p>';

            // Build general feedback with Exercise Type / Tips / Explanation if provided
            $gfparts = [];
            $exercise = isset($m['exercise']) ? trim((string)$m['exercise']) : '';
            $tips = isset($m['tips']) ? trim((string)$m['tips']) : '';
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
            foreach ($options as $o) {
                $txt = htmlspecialchars((string)($o['text'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
                if ($txt === '') { continue; }
                $frac = !empty($o['correct']) ? '100' : '0';
                $xml .= '<answer fraction="'.$frac.'" format="moodle_auto_format"><text>'.$txt.'</text>'
                     . '<feedback><text>'.htmlspecialchars((string)($o['feedback'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</text></feedback></answer>';
            }
            $xml .= '</question>';
        }

        // Short Answer for SI (last)
        $index = 0;
        foreach ($siFiltered as $it) {
            $index++;
            $orig = $it['original'];
            $impr = $it['improved'];
            $minlen = min( max(mb_strlen($orig), 10), 60 ); // cap pattern length
            $pattern = str_repeat('?', $minlen) . '*';
            $qname = htmlspecialchars('SI '.$index, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
            $qtext = '<p>Rewrite the following sentence clearly and correctly:</p><p><em>Original: '.htmlspecialchars($orig, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</em></p>';
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
