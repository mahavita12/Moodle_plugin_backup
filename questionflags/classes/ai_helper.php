<?php
namespace local_questionflags;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/filelib.php');

/**
 * Lightweight Anthropic client for generating brief structure guides (<=150 words).
 */
class ai_helper {
    private const API_CONNECT_TIMEOUT = 20;
    private const API_TOTAL_TIMEOUT = 90;
    private const MAX_RETRY_ATTEMPTS = 2;

    /**
     * Generate a concise structure guide from a question prompt.
     * Always returns plain text with short bold-style headers rendered later by existing formatter.
     *
     * @param string $questionPrompt Plain-text question/prompt
     * @param string $audience Optional audience (primary/middle/secondary)
     * @param string $locale Optional locale (default en-AU)
     * @return array [success => bool, guide => string, message => string]
     */
    public function generate_structure_guide(string $questionPrompt, string $audience = 'secondary', string $locale = 'en-AU'): array {
        $apikey = $this->get_anthropic_api_key();
        $model = $this->get_anthropic_model();

        $system = 'You are a K-12 writing coach. Produce a very brief, practical structure guide (max 150 words) for students. '
                . 'Use Australian English. No emojis. No checklists. Do NOT use any markdown or styling tokens (**, #, -, *, _, backticks). '
                . 'Return plain text only, with each line starting with a short section label followed by a colon (e.g., Introduction: ) and a concise sentence. '
                . 'Keep lines concise and scannable. Choose genre-appropriate sections.';

        // User content instructs genre mapping with strict brevity.
        $user = "PROMPT:\n" . trim($questionPrompt) . "\n\n" .
            "OUTPUT REQUIREMENTS (<=150 words):\n" .
            "- Output plain text with one section per line using 'Label: content' (no markdown, bullets or symbols).\n" .
            "- Detect genre from task words: narrative (story/narrate/imagine/character/setting), persuasive (argue/persuade/should/convince/position), discursive (discuss both sides/for and against/balanced), informative/explanatory (explain/inform/how/why/describe/report).\n" .
            "- Use exactly one schema based on the detected genre:\n" .
            "  Persuasive: Introduction (clear thesis); Body 1 (Pro + evidence type); Body 2 (Pro + evidence type); Body 3 (Rebuttal); Conclusion.\n" .
            "  Discursive: Introduction (topic + neutral frame); For (key point + support); Against (key point + support); Weigh-up; Conclusion.\n" .
            "  Narrative: Orientation (who/where/when); Complication; Rising Action; Climax; Resolution; Reflection. Use past tense and 'show, not tell' phrasing.\n" .
            "  Informative/Explanatory: Definition/Focus; Body 1 (subtopic); Body 2 (subtopic); Body 3 (subtopic); Examples/Explanations (1â€“2 concrete facts); Conclusion (implication/summary).\n" .
            "- One concise sentence per label; tie guidance to the exact topic words from the prompt; Australian English; total <=150 words.";

        $data = [
            'model' => $model,
            'system' => $system,
            'messages' => [ [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => $user ] ] ] ],
            'max_tokens' => 700,
            'temperature' => 0.2,
        ];

        $result = $this->post_anthropic($apikey, $data, 'generate_structure_guide');
        if (!$result['success']) {
            return $result;
        }

        // Trim and enforce length cap server-side as a safety.
        $guide = trim($result['response']);
        // Soft cap: truncate beyond ~1200 chars (~150-180 words worst case), preserving whole lines.
        $maxChars = 1200;
        if (mb_strlen($guide, 'UTF-8') > $maxChars) {
            $guide = mb_substr($guide, 0, $maxChars, 'UTF-8');
        }

        return [ 'success' => true, 'guide' => $guide, 'message' => '' ];
    }

    private function get_anthropic_api_key(): string {
        $key = get_config('local_essaysmaster', 'anthropic_apikey');
        if (empty($key)) {
            $key = get_config('local_quizdashboard', 'anthropic_apikey');
        }
        if (empty($key)) {
            $key = get_config('local_questionflags', 'anthropic_apikey');
        }
        if (empty($key)) {
            throw new \moodle_exception('Anthropic API key not configured.');
        }
        return preg_replace('/\s+/', '', (string)$key);
    }

    private function get_anthropic_model(): string {
        $model = get_config('local_essaysmaster', 'anthropic_model');
        if (empty($model)) {
            $model = get_config('local_quizdashboard', 'anthropic_model');
        }
        if (empty($model)) {
            $model = get_config('local_questionflags', 'anthropic_model');
        }
        $model = is_string($model) ? trim($model) : '';
        if ($model === '' || strtolower($model) === 'sonnet-4' || strtolower($model) === 'claude-4') {
            return 'claude-sonnet-4-5';
        }
        return $model;
    }

    private function post_anthropic(string $apikey, array $data, string $operationName): array {
        $attempts = 0;
        $lastError = '';

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            $attempts++;
            try {
                $curl = new \curl();
                $curl->setHeader([
                    'Content-Type: application/json',
                    'x-api-key: ' . $apikey,
                    'anthropic-version: 2023-06-01'
                ]);
                $curl->setopt([
                    'CURLOPT_TIMEOUT' => self::API_TOTAL_TIMEOUT,
                    'CURLOPT_CONNECTTIMEOUT' => self::API_CONNECT_TIMEOUT,
                    'CURLOPT_NOSIGNAL' => 1,
                ]);

                error_log("\xF0\x9F\xA4\x96 QuestionFlags (Anthropic): Using model: " . ($data['model'] ?? 'unknown'));
                $response = $curl->post('https://api.anthropic.com/v1/messages', json_encode($data));
                if ($curl->get_errno() !== 0) {
                    $lastError = 'cURL error: ' . $curl->error;
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) { sleep(2 * $attempts); continue; }
                    return [ 'success' => false, 'message' => $lastError ];
                }

                $body = json_decode($response, true);
                if (isset($body['error'])) {
                    $lastError = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : $body['error'];
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) { sleep(4 * $attempts); continue; }
                    return [ 'success' => false, 'message' => 'API error: ' . $lastError ];
                }

                $text = '';
                if (isset($body['content']) && is_array($body['content'])) {
                    foreach ($body['content'] as $part) {
                        if (($part['type'] ?? '') === 'text' && isset($part['text'])) { $text .= $part['text']; }
                    }
                }
                if ($text === '') {
                    return [ 'success' => false, 'message' => 'Invalid Anthropic API response structure' ];
                }
                return [ 'success' => true, 'response' => $text ];
            } catch (\Throwable $e) {
                $lastError = 'Exception: ' . $e->getMessage();
                if ($attempts < self::MAX_RETRY_ATTEMPTS) { sleep(3 * $attempts); continue; }
            }
        }
        return [ 'success' => false, 'message' => 'Failed after ' . $attempts . ' attempts. Last error: ' . $lastError ];
    }
}
