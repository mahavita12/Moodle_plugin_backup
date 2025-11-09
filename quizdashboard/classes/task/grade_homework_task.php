<?php
namespace local_quizdashboard\task;

defined('MOODLE_INTERNAL') || die();

class grade_homework_task extends \core\task\adhoc_task {
    public function get_name() {
        return 'Homework grading task';
    }

    public function execute() {
        global $DB, $CFG;
        $data = $this->get_custom_data();
        $attemptid = isset($data->attemptid) ? (int)$data->attemptid : 0;
        if ($attemptid <= 0) { return; }
        try {
            $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
            $uniqueid = $attemptobj->get_attempt()->uniqueid;
            $userid = (int)$attemptobj->get_userid();
            $quizid = (int)$attemptobj->get_attempt()->quiz;
            $quba = \question_engine::load_questions_usage_by_activity($uniqueid);
            $essays = [];
            foreach ($quba->get_slots() as $slot) {
                $question = $quba->get_question($slot);
                if ($question->get_type_name() === 'essay') {
                    $qa = $quba->get_question_attempt($slot);
                    $qaid = (int)$qa->get_database_id();
                    $stepid = (int)$DB->get_field_sql("SELECT id FROM {question_attempt_steps} WHERE questionattemptid = ? ORDER BY sequencenumber DESC", [$qaid]);
                    $answerhtml = (string)$DB->get_field('question_attempt_step_data', 'value', ['attemptstepid' => $stepid, 'name' => 'answer'], \IGNORE_MISSING);
                    $answertext = trim(strip_tags($answerhtml));
                    $qid = (int)$question->id;
                    $sjson = (string)$DB->get_field('qtype_essay_options', 'graderinfo', ['questionid' => $qid], \IGNORE_MISSING);
                    $sdata = $sjson ? json_decode($sjson, true) : null;
                    $suggested = (is_array($sdata) && isset($sdata['suggested']) && is_array($sdata['suggested'])) ? $sdata['suggested'] : [];
                    $essays[] = [ 'slot' => (int)$slot, 'qa' => $qa, 'answer' => $answertext, 'suggested' => $suggested ];
                }
            }
            if (empty($essays)) { return; }
            $e = $essays[0];
            $items = $e['suggested'];
            if (empty($items)) { return; }
            $provider = (string)(get_config('local_quizdashboard', 'provider') ?: 'anthropic');
            $prompt = $this->build_prompt($e['answer'], $items);
            $resp = $this->call_ai($provider, $prompt);
            $parsed = $this->parse_ai_json($resp);
            $overall = isset($parsed['overall']['score']) ? (float)$parsed['overall']['score'] : $this->avg_items($parsed);
            if ($overall < 0) { $overall = 0.0; }
            if ($overall > 100) { $overall = 100.0; }
            $fraction = $overall / 100.0;
            $comment = $this->build_brief_feedback($parsed, $items);
            $this->apply_grade($attemptobj, $quba, $fraction, $comment);
        } catch (\Throwable $t) {
            debugging('grade_homework_task error: '.$t->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private function build_prompt(string $student, array $suggested): string {
        $sjson = json_encode($suggested, JSON_UNESCAPED_UNICODE);
        $text = substr($student, 0, 8000);
        return "You are grading numbered sentence improvements. Suggested answers JSON: $sjson. Student response: \n$text\n. Parse the student's response as items 1..N and compare to suggested[i]. Return JSON with keys: items:[{index,score,verdict,brief}], overall:{score}. 'score' 0-100, verdict in [correct, partial, incorrect], brief <= 15 words.";
    }

    private function call_ai(string $provider, string $prompt): string {
        $provider = strtolower(trim($provider ?: 'anthropic'));
        if ($provider === 'openai') { return $this->call_openai($prompt); }
        return $this->call_anthropic($prompt);
    }

    private function call_openai(string $prompt): string {
        $key = (string)(get_config('local_quizdashboard', 'openai_api_key') ?: '');
        $model = (string)(get_config('local_quizdashboard', 'openai_model') ?: 'gpt-5');
        if ($key === '') { return ''; }
        $payload = [
            'model' => $model,
            'messages' => [ ['role'=>'system','content'=>'Return only JSON.'], ['role'=>'user','content'=>$prompt] ],
            'response_format' => ['type'=>'json_object'],
            'max_completion_tokens' => 800,
            'temperature' => 0.2,
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [ 'Authorization: Bearer '.$key, 'Content-Type: application/json' ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 40,
        ]);
        $out = curl_exec($ch); curl_close($ch);
        if (!$out) { return ''; }
        $j = json_decode($out, true);
        $content = $j['choices'][0]['message']['content'] ?? '';
        return (string)$content;
    }

    private function call_anthropic(string $prompt): string {
        $key = (string)(get_config('local_quizdashboard', 'anthropic_apikey') ?: '');
        $model = (string)(get_config('local_quizdashboard', 'anthropic_model') ?: 'claude-sonnet-4-20250514');
        if ($key === '') { return ''; }
        $payload = [
            'model' => $model,
            'system' => 'Return only JSON. Do not include prose.',
            'messages' => [ [ 'role' => 'user', 'content' => [ ['type'=>'text','text'=>$prompt] ] ] ],
            'max_tokens' => 800,
            'temperature' => 0.2,
        ];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [ 'x-api-key: '.$key, 'content-type: application/json', 'anthropic-version: 2023-06-01' ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 40,
        ]);
        $out = curl_exec($ch); curl_close($ch);
        if (!$out) { return ''; }
        $j = json_decode($out, true);
        $content = '';
        if (isset($j['content'][0]['text'])) { $content = (string)$j['content'][0]['text']; }
        return $content;
    }

    private function parse_ai_json(string $text): array {
        $text = trim($text ?: '');
        $start = strpos($text, '{'); $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) { $text = substr($text, $start, $end - $start + 1); }
        $j = json_decode($text, true);
        if (!is_array($j)) { $j = []; }
        return $j;
    }

    private function avg_items(array $parsed): float {
        $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : [];
        if (empty($items)) { return 0.0; }
        $sum = 0; $n = 0;
        foreach ($items as $it) { if (isset($it['score'])) { $sum += max(0, min(100, (float)$it['score'])); $n++; } }
        return $n ? ($sum / $n) : 0.0;
    }

    private function build_brief_feedback(array $parsed, array $suggested): string {
        $items = isset($parsed['items']) && is_array($parsed['items']) ? $parsed['items'] : [];
        $lines = [];
        foreach ($items as $it) {
            $idx = (int)($it['index'] ?? 0);
            $ver = (string)($it['verdict'] ?? '');
            $sc = isset($it['score']) ? (int)$it['score'] : null;
            $lines[] = $idx.': '.($ver ?: 'n/a').($sc!==null?(' ('.$sc.'%)'):'');
        }
        if (empty($lines)) { return 'Auto-graded homework.'; }
        return 'Auto-graded. '.implode('; ', array_slice($lines, 0, 8));
    }

    private function apply_grade($attemptobj, $quba, float $fraction, string $comment): void {
        global $DB;
        foreach ($quba->get_slots() as $slot) {
            $question = $quba->get_question($slot);
            if ($question->get_type_name() !== 'essay') { continue; }
            $qa = $quba->get_question_attempt($slot);
            $max = (float)$qa->get_max_mark();
            $mark = $fraction * $max;
            if (!defined('FORMAT_HTML')) { define('FORMAT_HTML', 1); }
            try { $qa->manual_grade($comment, $mark, FORMAT_HTML); }
            catch (\ArgumentCountError $e) { $qa->manual_grade($comment, $mark); }
        }
        \question_engine::save_questions_usage_by_activity($quba);
        try {
            $quizobj = \mod_quiz\quiz_settings::create($attemptobj->get_attempt()->quiz);
            $calc = $quizobj->get_grade_calculator();
            if (method_exists($calc, 'recompute_quiz_sumgrades_for_attempts')) {
                $calc->recompute_quiz_sumgrades_for_attempts([$attemptobj->get_attempt()]);
            } else {
                $calc->recompute_quiz_sumgrades();
            }
            quiz_update_grades($quizobj->get_quiz(), $attemptobj->get_userid());
        } catch (\Throwable $e) {
            try {
                $fresh = \question_engine::load_questions_usage_by_activity($attemptobj->get_attempt()->uniqueid);
                $total = $fresh->get_total_mark();
                if ($total !== null) { $DB->set_field('quiz_attempts', 'sumgrades', $total, ['id' => $attemptobj->get_attempt()->id]); }
                $quiz = $DB->get_record('quiz', ['id' => $attemptobj->get_attempt()->quiz]);
                quiz_update_grades($quiz, $attemptobj->get_userid());
            } catch (\Throwable $e2) {}
        }
    }
}
