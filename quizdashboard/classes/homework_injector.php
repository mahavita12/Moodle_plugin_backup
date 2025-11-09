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
        $name = 'Homework – ' . trim($label);
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
}
