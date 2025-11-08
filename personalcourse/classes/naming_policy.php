<?php
namespace local_personalcourse;

defined('MOODLE_INTERNAL') || die();

class naming_policy {
    public static function initials_for_user(int $userid): string {
        global $DB;
        $u = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname', IGNORE_MISSING);
        $first = trim((string)($u->firstname ?? ''));
        $last = trim((string)($u->lastname ?? ''));
        if ($first === '' && $last === '') { return 'UU'; }
        if ($last === '') { $s = strtoupper(substr($first, 0, 2)); return $s !== '' ? $s : 'U'; }
        $i1 = strtoupper(substr($first, 0, 1));
        $i2 = strtoupper(substr($last, 0, 1));
        $res = ($i1 !== '' ? $i1 : 'U') . ($i2 !== '' ? $i2 : 'U');
        return $res;
    }

    public static function subject_for_source(int $sourcecourseid, int $sourcequizid): string {
        global $DB;
        $sectionname = '';
        $moduleidquiz = (int)$DB->get_field('modules', 'id', ['name' => 'quiz']);
        if ($moduleidquiz > 0) {
            $cmid = (int)$DB->get_field('course_modules', 'id', ['module' => $moduleidquiz, 'instance' => $sourcequizid, 'course' => $sourcecourseid], IGNORE_MISSING);
            if ($cmid) {
                $sectionid = (int)$DB->get_field('course_modules', 'section', ['id' => $cmid], IGNORE_MISSING);
                if ($sectionid) {
                    $sectionname = (string)$DB->get_field('course_sections', 'name', ['id' => $sectionid], IGNORE_MISSING);
                }
            }
        }
        $quizname = (string)$DB->get_field('quiz', 'name', ['id' => $sourcequizid], IGNORE_MISSING);
        $candidates = [];
        if ($sectionname !== '') { $candidates[] = $sectionname; }
        if ($quizname !== '') { $candidates[] = $quizname; }
        $map = self::subject_map();
        foreach ($candidates as $text) {
            foreach ($map as $pattern => $label) {
                if (@preg_match($pattern, $text)) {
                    if (preg_match($pattern, $text)) { return $label; }
                } else {
                    $quoted = '/' . preg_quote($pattern, '/') . '/i';
                    if (preg_match($quoted, $text)) { return $label; }
                }
            }
            if (preg_match('/-\s*([A-Za-z][A-Za-z]+)$/', $text, $m)) { return $m[1]; }
        }
        return 'General';
    }

    public static function code_for_quiz(int $quizid): string {
        global $DB;
        $name = (string)$DB->get_field('quiz', 'name', ['id' => $quizid], IGNORE_MISSING);
        $rx = self::code_regex();
        if ($name !== '' && $rx !== '' && preg_match($rx, $name, $m) && !empty($m[1])) {
            $code = strtoupper(trim($m[1]));
            $code = preg_replace('/[^A-Za-z0-9-]/', '', $code);
            if ($code !== '') { return $code; }
        }
        $row = $DB->get_record_sql("SELECT qc.id, qc.name, qc.idnumber, COUNT(*) AS cnt\n                                     FROM {quiz_slots} qs\n                                     JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'\n                                     JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid\n                                     JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid\n                                    WHERE qs.quizid = ?\n                                 GROUP BY qc.id, qc.name, qc.idnumber\n                                 ORDER BY cnt DESC, qc.id DESC", [$quizid]);
        if ($row) {
            $catname = (string)($row->name ?? '');
            if ($catname !== '' && $rx !== '' && preg_match($rx, $catname, $m2) && !empty($m2[1])) {
                $code = strtoupper(trim($m2[1]));
                $code = preg_replace('/[^A-Za-z0-9-]/', '', $code);
                if ($code !== '') { return $code; }
            }
            $idn = (string)($row->idnumber ?? '');
            $idn = strtoupper(trim($idn));
            $idn = preg_replace('/[^A-Za-z0-9-]/', '', $idn);
            if ($idn !== '') { return $idn; }
        }
        return 'Q' . (int)$quizid;
    }

    public static function section_prefix(int $userid, int $sourcecourseid, int $sourcequizid): string {
        $ini = self::initials_for_user($userid);
        $subj = self::subject_for_source($sourcecourseid, $sourcequizid);
        return trim($ini . '-' . $subj);
    }

    public static function personal_quiz_name(int $userid, int $sourcequizid): string {
        $ini = self::initials_for_user($userid);
        $code = self::code_for_quiz($sourcequizid);
        return trim($ini . '-' . $code);
    }

    private static function subject_map(): array {
        $raw = (string)\get_config('local_personalcourse', 'subjectmap');
        $raw = trim($raw);
        if ($raw === '') {
            $raw = "/\\bthinking\\b/i => Thinking\n/\\bmath(?:ematics)?\\b/i => Math\n/\\bread(?:ing)?\\b/i => Reading\n/\\bwriting\\b/i => Writing";
        }
        $lines = preg_split('/\r?\n/', $raw);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=>') === false) { continue; }
            list($pat, $lab) = array_map('trim', explode('=>', $line, 2));
            if ($pat === '' || $lab === '') { continue; }
            $out[$pat] = $lab;
        }
        return $out;
    }

    private static function code_regex(): string {
        $rx = (string)\get_config('local_personalcourse', 'coderegex');
        $rx = trim($rx);
        if ($rx === '') { $rx = '/\(([A-Za-z0-9-]{2,})\)\s*$/'; }
        return $rx;
    }
}
