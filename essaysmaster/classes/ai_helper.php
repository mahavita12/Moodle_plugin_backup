<?php
namespace local_essaysmaster;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/filelib.php');

/**
 * AI Helper for Essays Master - supports Anthropic Sonnet 4 and OpenAI
 * Uses Essays Master plugin's own configuration (no dependency on other plugins)
 */
class ai_helper {

    private const API_CONNECT_TIMEOUT = 30;
    private const API_TOTAL_TIMEOUT = 180;
    private const MAX_RETRY_ATTEMPTS = 2;

    /**
     * Get current AI provider from Essays Master config.
     */
    protected function get_provider(): string {
        $provider = get_config('local_essaysmaster', 'provider');
        $provider = is_string($provider) ? strtolower(trim($provider)) : '';
        return in_array($provider, ['anthropic', 'openai']) ? $provider : 'anthropic';
    }

    /**
     * Get OpenAI API key from Essays Master config.
     */
    protected function get_openai_api_key(): string {
        $key = get_config('local_essaysmaster', 'openai_apikey');
        if (empty($key)) {
            throw new \moodle_exception('OpenAI API key not configured. Set it in Essays Master configuration.');
        }
        $key = preg_replace('/\s+/', '', trim((string)$key));
        if (!preg_match('/^sk-[a-zA-Z0-9_-]{20,}$/', $key)) {
            throw new \moodle_exception('Invalid OpenAI API key format.');
        }
        return $key;
    }

    /**
     * Get OpenAI model from Essays Master config.
     */
    protected function get_openai_model(): string {
        $model = get_config('local_essaysmaster', 'openai_model');
        $model = is_string($model) ? trim($model) : '';
        return $model !== '' ? $model : 'gpt-4o';
    }

    /**
     * Get Anthropic API key from Essays Master config.
     */
    protected function get_anthropic_api_key(): string {
        $key = get_config('local_essaysmaster', 'anthropic_apikey');
        if (empty($key)) {
            throw new \moodle_exception('Anthropic API key not configured. Set it in Essays Master configuration.');
        }
        $key = preg_replace('/\s+/', '', trim((string)$key));
        // Anthropic keys typically start with sk-ant- but allow broader formats
        if (!preg_match('/^(sk|ak|tok)[-a-zA-Z0-9_]{10,}$/', $key)) {
            // Do not block too strictly; accept as-is if non-empty
        }
        return $key;
    }

    /**
     * Get Anthropic model from Essays Master config (default: Sonnet 4).
     */
    protected function get_anthropic_model(): string {
        $model = get_config('local_essaysmaster', 'anthropic_model');
        $model = is_string($model) ? trim(strtolower($model)) : '';
        // Map friendly aliases to current Anthropic model ids
        if ($model === '' || in_array($model, ['sonnet-4', 'sonnet4', 'sonnet'], true)) {
            // Fallback to widely available Sonnet model id
            return 'claude-3-5-sonnet-latest';
        }
        return $model;
    }

    /**
     * Generate AI feedback for rounds 1, 3, 5
     */
    public function generate_feedback($round, $student_text, $question_prompt = '') {
        error_log("🤖 Helper: Generating feedback for round $round");
        
        // Get the prompt based on round
        $prompt = $this->get_feedback_prompt($round, $student_text, $question_prompt);

        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $prompt['system'],
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $prompt['user']] ]]
                ],
                'max_tokens' => 1500
            ];
            $result = $this->make_anthropic_api_call($data, "feedback_round_$round");
        } else {
            $data = [
                'model' => $this->get_openai_model(),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']]
                ],
                'max_completion_tokens' => 1500
            ];
            $result = $this->make_openai_api_call($data, "feedback_round_$round");
        }
        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['message']
            ];
        }

        return [
            'success' => true,
            'feedback' => $result['response']
        ];
    }

    /**
     * Generate AI validation for rounds 2, 4, 6  
     */
    public function generate_validation($round, $original_text, $current_text, $question_prompt = '') {
        error_log("🔍 Helper: Generating validation for round $round");
        
        // Get the validation prompt based on round
        $prompt = $this->get_validation_prompt($round, $original_text, $current_text, $question_prompt);

        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $prompt['system'],
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $prompt['user']] ]]
                ],
                'max_tokens' => 2000
            ];
            $result = $this->make_anthropic_api_call($data, "validation_round_$round");
        } else {
            $data = [
                'model' => $this->get_openai_model(),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt['system']],
                    ['role' => 'user', 'content' => $prompt['user']]
                ],
                'max_completion_tokens' => 2000
            ];
            $result = $this->make_openai_api_call($data, "validation_round_$round");
        }
        if (!$result['success']) {
            return [
                'success' => false,
                'score' => 0,
                'message' => $result['message']
            ];
        }

        // Parse validation response
        return $this->parse_validation_response($result['response']);
    }

    /**
     * Get feedback prompts for rounds 1, 3, 5 (same as frontend)
     */
    private function get_feedback_prompt($round, $student_text, $question_prompt) {
        $prompts = [
            1 => [
                'system' => 'You are a humorous writing tutor from GrowMinds Academy doing an initial proofreading check. Count all spelling and grammar errors in the student\'s essay.

TASK:
- Count spelling mistakes
- Count grammar errors by type (subject-verb agreement, tense errors, punctuation, etc.)
- Return a structured message about proofreading with specific counts
- Include light encouragement about improvement
- DO NOT provide any corrections, suggestions, or specific examples
- DO NOT use any emojis, asterisks, hashtags, or markdown formatting
- Structure your response in exactly 3 parts with specific formatting

LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

FORMAT:
Part 1: Brief humorous comment about proofreading (1 sentence)
Part 2: <span class="error-count">SPECIFIC error counts with WHY self revision is required (separate line, to be styled in red)</span>
Part 3: <strong>Strong encouragement for self proofreading and revision (separate line, to be styled in bold)</strong>

EXAMPLE:
Looks like your essay needs a proofreading session before it meets the teacher!
<span class="error-count">I found 7 spelling mistakes and 12 grammar errors that need your attention - these must be fixed through careful self-revision to meet the GrowMinds Academy standards.</span>
<strong>Take time to carefully proofread and revise your work - this self-editing process is essential for strong essay writing.</strong>
DO NOT use emojis, asterisks, hashtags, or markdown formatting.',
                'user' => "STUDENT ESSAY:\n{$student_text}"
            ],
            
            3 => [
                'system' => 'You are a witty writing tutor from GrowMinds Academy focused on vocabulary and language sophistication.

MANDATORY REQUIREMENT: You MUST provide at least 5 specific improvements in [HIGHLIGHT]original[/HIGHLIGHT] => improved format. This is absolutely required - do not just give general feedback.

CRITICAL INSTRUCTION: You must ONLY suggest improvements for words and phrases that actually exist in the student\'s text. DO NOT create new sentences or add content that is not already present.

TASK:
- Find EXACT words/phrases from the student\'s text that are basic or weak
- Suggest more sophisticated alternatives for those EXACT words/phrases
- Present in "original => improved" format using ONLY text that exists in the essay
- Each pair on separate lines
- The "original" part must be copied word-for-word from the student\'s text
- Add humorous commentary about vocabulary upgrades
- Focus on elevating casual language to more sophisticated writing
- Wrap ALL problematic words in [HIGHLIGHT]word[/HIGHLIGHT] tags for highlighting
- Minimum 5 improvements required, maximum 9
- DO NOT use any emojis, asterisks, hashtags, or markdown formatting

LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

FORMAT:
1. Brief humorous introduction about vocabulary enhancement
2. List improvements in "original => improved" format (original text must exist in essay)
3. Wrap each original word in [HIGHLIGHT] tags
4. End with witty encouragement

EXAMPLE:
Time to upgrade your vocabulary! Let\'s polish those words:

[HIGHLIGHT]good[/HIGHLIGHT] => excellent
[HIGHLIGHT]things[/HIGHLIGHT] => aspects  
[HIGHLIGHT]a lot[/HIGHLIGHT] => significantly

Your writing has potential - let\'s make it shine!
DO NOT use emojis, asterisks, hashtags, or markdown formatting.',
                'user' => "STUDENT TEXT: {$student_text}"
            ],
            
            5 => [
                'system' => 'You are a sharp-witted writing tutor from GrowMinds Academy evaluating essay relevance and sentence structure.

MANDATORY REQUIREMENT: You MUST provide at least 5 specific improvements in [HIGHLIGHT]original[/HIGHLIGHT] => improved format. This is absolutely required - do not just give general feedback.

CRITICAL INSTRUCTION: You must ONLY suggest improvements for sentences and phrases that actually exist in the student\'s essay. Quote their exact text word-for-word.

TASK:
- Find EXACT sentences from their essay that need improvement
- For EACH sentence, provide: [HIGHLIGHT]exact original text[/HIGHLIGHT] => clear improved version
- Focus on: relevance to the question, clarity, sentence structure, specificity
- You must provide specific examples, not just general commentary
- Minimum 5 improvements required, maximum 9
- DO NOT use any emojis, asterisks, hashtags, or markdown formatting

LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

FORMAT:
Brief introduction, then MANDATORY list of improvements:

[HIGHLIGHT]original sentence from essay[/HIGHLIGHT] => improved version
[HIGHLIGHT]another original sentence[/HIGHLIGHT] => improved version
[HIGHLIGHT]third original sentence[/HIGHLIGHT] => improved version
[HIGHLIGHT]fourth original sentence[/HIGHLIGHT] => improved version
[HIGHLIGHT]fifth original sentence[/HIGHLIGHT] => improved version

End with encouragement. DO NOT use section headers - just list improvements directly.
DO NOT use emojis, asterisks, hashtags, or markdown formatting.',

                'user' => "ORIGINAL QUESTION: {$question_prompt}\nSTUDENT ESSAY: {$student_text}"
            ]
        ];

        return $prompts[$round] ?? $prompts[1];
    }

    /**
     * Get validation prompts for rounds 2, 4, 6 (same as frontend)
     */
    private function get_validation_prompt($round, $original_text, $current_text, $question_prompt) {
        $prompts = [
            2 => [
                'system' => 'You are a writing tutor from GrowMinds Academy validating proofreading improvements. Compare original vs revised text.

IMPORTANT: If the original text already has few or no spelling/grammar errors, the student should PASS automatically since no corrections were needed.

SCORING CRITERIA (0-100 points):
- If original text has 0-2 minor errors: Automatic 80+ points (student already proficient)
- If original text has 3+ errors AND student fixed most: 60-90 points based on fixes
- If original text has 3+ errors AND student fixed few: 20-49 points (needs more work)
- Improved punctuation: Additional 10-20 points


THRESHOLD: Score ≥50 = PASS, Score <50 = FAIL

TASK:
1) First assess: How many spelling/grammar errors were in the ORIGINAL text?
2) If original had 0-2 errors: PASS automatically (student already proficient)
3) If original had 3+ errors: Check how many were fixed and score accordingly
4) MANDATORY: Provide remaining errors from the REVISED TEXT ONLY in "original => improved" format and highlight basic words with [HIGHLIGHT]word[/HIGHLIGHT] tags (regardless of PASS/FAIL). CRITICAL: Only show errors that exist in the REVISED TEXT, not the original text.
5) If PASS (score ≥50): Add humorous praise message
6) Minimum 2 improvements required, maximum 9
7) DO NOT use any emojis, asterisks, hashtags, or markdown formatting


LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

RESPONSE FORMAT:
Score: [number 0-100]
Status: [PASS or FAIL]
Analysis: [brief analysis - mention if original was already good, what has been improved, and what still needs attention]
Feedback: [humorous message]. Here are opportunities to elevate your spelling and grammar:

[Multiple examples in this format - USE ONLY WORDS FROM REVISED TEXT:]
[HIGHLIGHT]word_from_revised_text[/HIGHLIGHT] => corrected_version
[HIGHLIGHT]another_word_from_revised_text[/HIGHLIGHT] => corrected_version
[HIGHLIGHT]third_word_from_revised_text[/HIGHLIGHT] => corrected_version

CRITICAL: Every "original" word must exist exactly as written in the REVISED TEXT. Do not use words from the original text.

[End with specific encouragement about speeling and grammar]

DO NOT use emojis, asterisks, hashtags, or markdown formatting.',
                'user' => "ORIGINAL TEXT: {$original_text}\nREVISED TEXT: {$current_text}"
            ],

            4 => [
                'system' => 'You are a writing tutor from GrowMinds Academy validating vocabulary and language improvements. Compare original vs revised text.

SCORING CRITERIA (0-100 points):
- Upgraded basic vocabulary: 50 points (very important)
- Improved sentence variety: 30 points (important)
- Enhanced tone: 20 points (supporting)

THRESHOLD: Score ≥50 = PASS, Score <50 = FAIL

TASK:
1) Analyze vocabulary and language sophistication improvements
2) Calculate numerical score (0-100) based on criteria above  
3) MANDATORY: Provide remaining vocabulary improvements from the REVISED TEXT ONLY in "original => improved" format and highlight basic words with [HIGHLIGHT]word[/HIGHLIGHT] tags (regardless of PASS/FAIL). CRITICAL: Only show words that exist in the REVISED TEXT, not the original text.
4) If PASS (score ≥50): Add humorous praise about language elevation
5) Minimum 2 improvements required, maximum 9
6) DO NOT use any emojis, asterisks, hashtags, or markdown formatting

LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

RESPONSE FORMAT:
Score: [number 0-100]
Status: [PASS or FAIL] 
Analysis: [brief analysis of language improvements - mention what has been improved, and what still needs attention]
Feedback: [humorous message]. Here are opportunities to elevate your language:

[Multiple examples in this format:]
[HIGHLIGHT]good[/HIGHLIGHT] => excellent  
[HIGHLIGHT]things[/HIGHLIGHT] => aspects
[HIGHLIGHT]a lot[/HIGHLIGHT] => significantly

[End with specific encouragement about language sophistication]

DO NOT use emojis, asterisks, hashtags, or markdown formatting.',
                'user' => "ORIGINAL TEXT: {$original_text}\nREVISED TEXT: {$current_text}"
            ],

            6 => [
                'system' => 'You are a writing tutor from GrowMinds Academy doing final validation of relevance and sentence structure.

MANDATORY REQUIREMENT: You MUST provide at least 3-5 specific improvements in [HIGHLIGHT]original[/HIGHLIGHT] => improved format for BOTH PASS and FAIL results. This is absolutely required - do not just give general feedback.

CRITICAL INSTRUCTION: You must ONLY suggest improvements for sentences and phrases that actually exist in the student\'s revised essay. Quote their exact text word-for-word.

SCORING CRITERIA (0-100 points):
- Addresses question directly: 40 points (critical)
- Clear sentence structure: 30 points (important) 
- Removed passive voice: 20 points (supporting)
- Added specific examples: 10 points (bonus)

THRESHOLD: Score ≥50 = PASS, Score <50 = FAIL

TASK:
1) Evaluate how well essay answers the original question
2) Assess sentence clarity and structure improvements
3) Calculate numerical score (0-100) based on criteria above
4) MANDATORY: Provide 3-5 specific remaining sentence improvements from the REVISED TEXT ONLY in "original => improved" format (regardless of PASS/FAIL). CRITICAL: Only show sentences that exist in the REVISED TEXT, not the original text.
5) If PASS (score ≥50): Add celebratory message about readiness for submission
6) Minimum 2 improvements required, maximum 9
7) DO NOT use any emojis, asterisks, hashtags, or markdown formatting

LANGUAGE REQUIREMENT: Use Australian English spelling and conventions only

RESPONSE FORMAT:
Score: [number 0-100]
Status: [PASS or FAIL]
Analysis: [brief analysis of relevance and structure]
Feedback: Brief introduction, then MANDATORY list of improvements:

[HIGHLIGHT]sentence_from_revised_essay_only[/HIGHLIGHT] => improved version
[HIGHLIGHT]another_sentence_from_revised_essay_only[/HIGHLIGHT] => improved version
[HIGHLIGHT]third_sentence_from_revised_essay_only[/HIGHLIGHT] => improved version
[HIGHLIGHT]fourth_sentence_from_revised_essay_only[/HIGHLIGHT] => improved version
[HIGHLIGHT]fifth_sentence_from_revised_essay_only[/HIGHLIGHT] => improved version

CRITICAL: Every "original" sentence must exist exactly as written in the REVISED TEXT. Do not use sentences from the original text.

[End with specific encouragement about essary writing]

DO NOT use section headers - just list improvements directly. DO NOT use emojis, asterisks, hashtags, or markdown formatting.',
                'user' => "ORIGINAL QUESTION: {$question_prompt}\nORIGINAL TEXT: {$original_text}\nREVISED TEXT: {$current_text}"
            ]
        ];

        return $prompts[$round] ?? $prompts[2];
    }

    /**
     * Parse AI validation response to extract score, status, and FULL feedback
     */
    private function parse_validation_response($response) {
        $score = 0;
        $status = 'FAIL';
        $analysis = '';
        $feedback = '';
        $feedback_started = false;
        $feedback_lines = [];
        
        $lines = explode("\n", $response);
        
        foreach ($lines as $line) {
            if (strpos($line, 'Score:') !== false) {
                $score = (int) preg_replace('/[^0-9]/', '', $line);
            } elseif (strpos($line, 'Status:') !== false) {
                $status = trim(str_replace('Status:', '', $line));
            } elseif (strpos($line, 'Analysis:') !== false) {
                $analysis = trim(str_replace('Analysis:', '', $line));
            } elseif (strpos($line, 'Feedback:') !== false) {
                $feedback_started = true;
                $feedback_lines[] = trim(str_replace('Feedback:', '', $line));
            } elseif ($feedback_started && trim($line) !== '') {
                // Capture all feedback lines including original=>improved examples
                $feedback_lines[] = $line;
            }
        }
        
        // Join all feedback lines to preserve original=>improved format
        $feedback = implode("\n", $feedback_lines);
        
        $passed = ($status === 'PASS' || $score >= 50);
        
        return [
            'success' => $passed,
            'score' => $score,
            'analysis' => $analysis,
            'feedback' => $feedback, // Now includes full rich feedback with examples
            'full_response' => $response
        ];
    }

    /**
     * Make robust OpenAI API call (same pattern as quizdashboard)
     */
    protected function make_openai_api_call($data, $operation_name = 'API call') {
        $attempts = 0;
        $last_error = '';

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            $attempts++;
            error_log("🤖 Helper: Attempting {$operation_name} - attempt {$attempts}/" . self::MAX_RETRY_ATTEMPTS);

            try {
                $apikey = $this->get_openai_api_key();
                
                $curl = new \curl();
                $curl->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $apikey]);
                
                $curl->setopt([
                    'CURLOPT_TIMEOUT' => self::API_TOTAL_TIMEOUT,
                    'CURLOPT_CONNECTTIMEOUT' => self::API_CONNECT_TIMEOUT,
                    'CURLOPT_NOSIGNAL' => 1,
                    'CURLOPT_TCP_KEEPALIVE' => 1,
                    'CURLOPT_TCP_KEEPIDLE' => 120,
                    'CURLOPT_TCP_KEEPINTVL' => 60
                ]);
                
                $response = $curl->post('https://api.openai.com/v1/chat/completions', json_encode($data));
                
                // Check for curl errors (including timeout)
                if ($curl->get_errno() !== 0) {
                    $curl_error = $curl->error;
                    $last_error = "cURL error: {$curl_error}";
                    error_log("🚨 Helper: {$operation_name} attempt {$attempts} failed - {$last_error}");
                    
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }
                    
                    return ['success' => false, 'message' => "Request timeout after {$attempts} attempts: {$curl_error}"];
                }
                
                $body = json_decode($response, true);

                if (isset($body['error'])) {
                    $last_error = "API error: {$body['error']['message']}";
                    error_log("🚨 Helper: {$operation_name} attempt {$attempts} failed - {$last_error}");
                    
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(5 * $attempts);
                        continue;
                    }
                    
                    return ['success' => false, 'message' => $last_error];
                }

                if (!isset($body['choices'][0]['message']['content'])) {
                    $last_error = 'Invalid API response structure';
                    error_log("🚨 Helper: {$operation_name} attempt {$attempts} failed - {$last_error}");
                    
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }
                    
                    return ['success' => false, 'message' => $last_error];
                }

                // Success!
                error_log("✅ Helper: {$operation_name} succeeded on attempt {$attempts}");
                return ['success' => true, 'response' => $body['choices'][0]['message']['content']];

            } catch (\Exception $e) {
                $last_error = "Exception: " . $e->getMessage();
                error_log("🚨 Helper: {$operation_name} attempt {$attempts} failed - {$last_error}");
                
                if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    sleep(3 * $attempts);
                    continue;
                }
            }
        }

        return ['success' => false, 'message' => "Failed after {$attempts} attempts. Last error: {$last_error}"];
    }

    /**
     * Make robust Anthropic API call.
     */
    protected function make_anthropic_api_call($data, $operation_name = 'API call') {
        $attempts = 0;
        $last_error = '';

        // Log the actual model being used
        error_log("🤖 Essays Master (Anthropic): Using model: " . ($data['model'] ?? 'unknown'));

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            $attempts++;
            error_log("🤖 Helper (Anthropic): Attempting {$operation_name} - attempt {$attempts}/" . self::MAX_RETRY_ATTEMPTS);

            try {
                $apikey = $this->get_anthropic_api_key();

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
                    'CURLOPT_TCP_KEEPALIVE' => 1,
                    'CURLOPT_TCP_KEEPIDLE' => 120,
                    'CURLOPT_TCP_KEEPINTVL' => 60
                ]);

                $response = $curl->post('https://api.anthropic.com/v1/messages', json_encode($data));

                if ($curl->get_errno() !== 0) {
                    $curl_error = $curl->error;
                    $last_error = "cURL error: {$curl_error}";
                    error_log("🚨 Helper (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }

                    return ['success' => false, 'message' => "Request timeout after {$attempts} attempts: {$curl_error}"];
                }

                $body = json_decode($response, true);

                if (isset($body['error'])) {
                    $last_error = 'API error: ' . (is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : $body['error']);
                    error_log("🚨 Helper (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(5 * $attempts);
                        continue;
                    }

                    return ['success' => false, 'message' => $last_error];
                }

                // Extract text content from Anthropic response
                $text = '';
                if (isset($body['content']) && is_array($body['content'])) {
                    foreach ($body['content'] as $part) {
                        if (isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                            $text .= $part['text'];
                        }
                    }
                }

                if ($text === '' && isset($body['message']['content'])) {
                    foreach ($body['message']['content'] as $part) {
                        if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                            $text .= $part['text'];
                        }
                    }
                }

                if ($text === '') {
                    $last_error = 'Invalid Anthropic API response structure';
                    error_log("🚨 Helper (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }

                    return ['success' => false, 'message' => $last_error];
                }

                error_log("✅ Helper (Anthropic): {$operation_name} succeeded on attempt {$attempts}");
                return ['success' => true, 'response' => $text];

            } catch (\Exception $e) {
                $last_error = 'Exception: ' . $e->getMessage();
                error_log("🚨 Helper (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    sleep(3 * $attempts);
                    continue;
                }
            }
        }

        return ['success' => false, 'message' => "Failed after {$attempts} attempts. Last error: {$last_error}"];
    }
}
