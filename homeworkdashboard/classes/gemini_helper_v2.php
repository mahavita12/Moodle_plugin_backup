<?php
namespace local_homeworkdashboard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Helper class for Google Gemini AI interactions.
 */
class gemini_helper {

    private $api_key;
    private $model;
    private $api_url_base = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private $last_error = null;
    private $last_response = null;

    public function __construct() {
        $config = get_config('local_homeworkdashboard');
        $this->api_key = $config->gemini_api_key ?? '';
        // Default to Gemini 3 Pro Preview as requested
        $this->model = $config->gemini_model ?? 'gemini-3-pro-preview';
    }

    /**
     * Check if API key is configured.
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Get the last error message.
     */
    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /**
     * Get the last raw API response (JSON).
     */
    public function get_last_response(): ?string {
        return $this->last_response;
    }

    /**
     * Generate commentary for a student based on their homework activity.
     *
     * @param string $student_name
     * @param array $new_activities Array of activity objects with 'name', 'attempts' (array of attempt objects), 'maxscore'
     * @param array $revision_activities Array of activity objects
     * @param string $lang Language code ('en' or 'ko')
     * @return string|null Generated commentary or null on failure
     */
    public function generate_commentary(string $student_name, array $new_activities, array $revision_activities, string $lang = 'en'): ?string {
        if (!$this->is_configured()) {
            return "AI Commentary unavailable: API Key not configured.";
        }

        // Calculate completion stats
        $total_new = count($new_activities);
        $completed_new = 0;
        foreach ($new_activities as $act) {
            $status = $act['status'] ?? 'Unknown';
            $attempts_count = count($act['attempts'] ?? []);
            error_log("GEMINI_DEBUG: New Activity '{$act['name']}' | Status: '$status' | Attempts: $attempts_count");

            // Use status from table logic if available
            if (isset($act['status'])) {
                if ($act['status'] === 'Completed' || $act['status'] === 'Low grade') {
                    $completed_new++;
                    error_log("GEMINI_DEBUG: -> Counted as COMPLETED (Status match)");
                } else {
                    error_log("GEMINI_DEBUG: -> NOT counted (Status is '$status')");
                }
            } else {
                // Fallback to attempt check (legacy)
                if (!empty($act['attempts'])) {
                    $completed_new++;
                    error_log("GEMINI_DEBUG: -> Counted as COMPLETED (Fallback attempts)");
                } else {
                    error_log("GEMINI_DEBUG: -> NOT counted (No status, no attempts)");
                }
            }
        }

        $total_revision = count($revision_activities);
        $completed_revision = 0;
        $rev_stats = ['total_qs' => 0, 'flags' => 0, 'notes' => 0];

        foreach ($revision_activities as $act) {
            // Logic for completion
            if ((isset($act['status']) && ($act['status'] === 'Completed' || $act['status'] === 'Low grade')) || 
                (empty($act['status']) && !empty($act['attempts']))) {
                $completed_revision++;
            }
             
            // Agreggate Stats
            $rev_stats['total_qs'] += ($act['question_count'] ?? 0);
            $rev_stats['flags'] += ($act['stats_flags'] ?? 0);
            $rev_stats['notes'] += ($act['stats_notes'] ?? 0);
        }

        // Calculate New Stats
        $new_stats = ['total_qs' => 0, 'flags' => 0, 'notes' => 0];
        foreach ($new_activities as $act) {
            $new_stats['total_qs'] += ($act['question_count'] ?? 0);
            $new_stats['flags'] += ($act['stats_flags'] ?? 0);
            $new_stats['notes'] += ($act['stats_notes'] ?? 0);
        }

        $prompt = $this->construct_prompt($student_name, $new_activities, $revision_activities, $completed_new, $total_new, $completed_revision, $total_revision, $lang, $new_stats, $rev_stats);
        error_log('GEMINI_DEBUG: Prompt constructed. Length: ' . strlen($prompt));

        return $this->call_api($prompt);
    }

    private function construct_prompt(string $student_name, array $new_activities, array $revision_activities, int $completed_new, int $total_new, int $completed_revision, int $total_revision, string $lang, array $new_stats = [], array $rev_stats = []): string {

        if ($lang === 'ko') {
            // --- KOREAN PROMPT (Native Generation) ---
            $prompt = "\n[Summary Statistics]\n";
            $prompt .= "- New Topics: {$completed_new} out of {$total_new} completed.\n";
            $prompt .= "- Revision Work: {$completed_revision} out of {$total_revision} completed.\n";
            if (!empty($new_stats) && $new_stats['total_qs'] > 0) {
                 $prompt .= "- New Topic Stats: Attempted {$new_stats['total_qs']} Qs, Flagged {$new_stats['flags']}, Notes {$new_stats['notes']}.\n";
            }
            if (!empty($rev_stats) && $rev_stats['total_qs'] > 0) {
                 $prompt .= "- Revision Stats: Reviewed {$rev_stats['total_qs']} Qs, Flagged {$rev_stats['flags']}, Notes {$rev_stats['notes']}.\n";
            }
            $prompt .= "\n";

            $prompt .= "당신은 '{$student_name}'의 학업 성취도를 분석하여 학부모님께 보고하는 교육 전문가입니다.\n";
            $prompt .= "이번 주 과제 수행 결과를 바탕으로 **상세하고 구체적인 학습 분석 보고서**를 작성해주세요.\n\n";
            
            $prompt .= "[작성 원칙]\n";
            $prompt .= "- **대상**: 학부모님.\n";
            $prompt .= "- **관점**: 철저히 3인칭 관점. 학생에게 말을 걸지 말고, 학부모님께 {$student_name}의 상태를 객관적으로 설명하세요.\n";
            $prompt .= "- **어조**: 전문적, 분석적, 정중함 (예: '분석됩니다', '권장합니다', '확인이 필요합니다').\n";
            $prompt .= "- **길이**: **상세하게 작성하세요 (400단어 이상)**. 단순 요약이 아닌, 데이터에 기반한 심층 분석이 필요합니다.\n";
            
            $prompt .= "[필수 포함 내용]\n";
            $prompt .= "   1. **종합 요약** (헤더: <h4 style='color: #2C3E50; border-bottom: 2px solid #2C3E50; padding-bottom: 5px;'>Summary</h4>):\n";
            $prompt .= "      - '{$student_name} (은)는 이번 주 새로운 과제 {$total_new}개 중 {$completed_new}개, 복습 과제 {$total_revision}개 중 {$completed_revision}개를 완료했습니다.'로 시작.\n";
            $prompt .= "      - 전체적인 성실도와 학습 태도를 총평해주세요.\n";
            
            $prompt .= "   2. **New Topics (새로운 과제) 상세 분석** (헤더: <h4 style='color: #2980B9; border-bottom: 1px solid #2980B9; padding-bottom: 5px;'>New Topics</h4>):\n";
            $prompt .= "      - **통계 요약 필수**: '이번 주 새로운 과제에서 총 {$new_stats['total_qs']}문항을 풀었으며, 플래그(Flag) {$new_stats['flags']}개, 노트(Note) {$new_stats['notes']}개를 기록했습니다.' 라는 문장을 반드시 섹션 시작 부분에 포함하세요.\n";
            $prompt .= "      - 각 과목(Math, English 등)별로 **점수(%), 소요 시간, 문항 수**를 구체적으로 언급하며 분석하세요.\n";
            $prompt .= "      - **데이터**를 반드시 인용하세요 (예: '20문항/8분/60%').\n";
            $prompt .= "      - **시간 분석**: 문제당 평균 1분 미만이면 '내용을 제대로 읽지 않고 풀었을 가능성'을 우려하세요.\n";
            $prompt .= "      - **점수 분석**: 점수가 낮다면(60% 미만) '개념 이해가 부족하니 해당 주제 복습이 시급함'을 구체적으로 알리세요.\n";

            $prompt .= "   3. **Revision Work (복습 과제) 상세 분석** (헤더: <h4 style='color: #D35400; border-bottom: 1px solid #D35400; padding-bottom: 5px;'>Revision Work</h4>):\n";
            $prompt .= "      - **통계 요약 필수**: '이번 주 복습 과제에서 총 {$rev_stats['total_qs']}문항을 검토했으며, 플래그(Flag) {$rev_stats['flags']}개, 노트(Note) {$rev_stats['notes']}개를 작성했습니다.' 라는 문장을 반드시 섹션 시작 부분에 포함하세요.\n";
            $prompt .= "      - **점수 변화**를 중점적으로 분석하세요 (1차 시도 vs 마지막 시도).\n";
            $prompt .= "      - 점수 향상 시: '1차 시도 대비 OO% 향상되어 학습 효과가 뚜렷합니다'라고 칭찬.\n";
            $prompt .= "      - 변화 없음/하락 시: '오답 노트 피드백이 충분히 학습되지 않은 것으로 보입니다. 가정에서 오답 정리를 다시 지도해주시기 바랍니다'라고 조언.\n";
            
            $prompt .= "   4. **학습 태도 및 제언** (헤더: <h4 style='color: #2C3E50; border-bottom: 1px solid #2C3E50; padding-bottom: 5px;'>Learning Attitude</h4>):\n";
            $prompt .= "      - 만약 1차, 2차 시도 모두 **시간이 매우 짧고(문제당 1분 미만)** 점수가 **매우 높다면(80% 이상)**:\n";
            $prompt .= "        - **부정행위 가능성 경고**: '두 번의 시도 모두 풀이 시간이 비정상적으로 짧아(예: 5분), 답안을 암기하여 수행했을 가능성이 우려됩니다. 가정에서 실제 이해도를 확인해주실 것을 권장합니다'라고 정중히 경고하세요.\n";
            $prompt .= "      - 복습 과제 완료 속도가 너무 빠르면 '학습의 질'에 대한 우려를 표명하세요.\n";

            $prompt .= "- **형식**: HTML 태그(<p>, <strong>, <ul>, <li>)를 사용하세요. **모든 <ul> 태그는 반드시 닫아야 합니다**.\n\n";

        } else {
            // --- ENGLISH PROMPT (Existing) ---
            $prompt = "\n[Summary Statistics]\n";
            $prompt .= "- New Topics: {$completed_new} out of {$total_new} completed.\n";
            $prompt .= "- Revision Work: {$completed_revision} out of {$total_revision} completed.\n\n";
            $prompt .= "You are a supportive and encouraging tutor for a student named {$student_name}.\n";
            $prompt .= "Analyze their homework progress for this week and write a summary report for their parents.\n\n";
            
            $prompt .= "[Rules]\n";
            $prompt .= "- Use 'We' instead of 'I' (e.g., 'We noticed...', 'We encourage...').\n";
            $prompt .= "- Refer to the student ONLY by their FIRST NAME (e.g., if name is '{$student_name}', use '{$student_name}').\n";
            $prompt .= "- Do NOT include any greeting or sign-off. Start directly with the summary.\n";
            $prompt .= "- Structure:\n";
            $prompt .= "   - **Introductory Summary** (First Paragraph):\n";
            $prompt .= "     - State: '{$student_name} completed {$completed_new} out of {$total_new} new activities and {$completed_revision} out of {$total_revision} revision activities.'\n";
            $prompt .= "     - Evaluate completion rates SEPARATELY for New Topics and Revision Work:\n";
            $prompt .= "       - For New Topics:\n";
            $prompt .= "         - > 90%: Praise.\n";
            $prompt .= "         - < 70%: Encourage.\n";
            $prompt .= "         - < 50%: Warn.\n";
            $prompt .= "       - For Revision Work:\n";
            $prompt .= "         - > 90%: Praise.\n";
            $prompt .= "         - < 70%: Encourage.\n";
            $prompt .= "         - < 50%: Warn.\n";
            $prompt .= "   - Section: 'New Topics'. Header: <h4 style=\"color: #3498db; font-size: 16px; margin-top: 15px; margin-bottom: 5px;\">New Topics</h4>\n";
            $prompt .= "   - Section: 'Revision Work'. Header: <h4 style=\"color: #f39c12; font-size: 16px; margin-top: 15px; margin-bottom: 5px;\">Revision Work</h4>\n";
            $prompt .= "- Content:\n";
            $prompt .= "   - Group feedback by Subject (e.g., Math, English) using bullet points.\n";
            $prompt .= "   - Highlight high scores/effort.\n";
            $prompt .= "   - Address low scores/no attempts politely.\n";
            $prompt .= "   - **Duration & Integrity Analysis**:\n";
            $prompt .= "     - Minimum expected time is approx 1 minute per question.\n";
            $prompt .= "     - If duration < (Question Count * 1 min), explicitly mention it was 'Rushed' or 'Skipped'.\n";
            $prompt .= "     - **Revision Integrity Check**: 복습 과제의 점수가 높은데(80% 이상), 시간이 너무 짧다면(예: 10문제에 5분 미만) **엄중히 경고**하세요. 답을 베낀 것으로 의심됩니다.\n";
            $prompt .= "     - **Revision Quality Analysis (복습 퀄리티 분석)**:\n";
            $prompt .= "       - Flag(표시한 문제) 수와 Note(노트) 수를 비교하세요. Flag가 Note보다 훨씬 많다면 경고하세요. ('문제는 많이 체크했는데 노트가 부족합니다.')\n";
            $prompt .= "       - 제공된 'Sample Revision Notes' 내용을 분석하세요.\n";
            $prompt .= "       - **비판 기준 (CRITICISM)**: 노트가 영어로 작성되며, 'I don't know', 'Hard', '???' 처럼 성의가 없으면 **따끔하게 지적**하세요. 왜 몰랐는지 설명하라고 하세요.\n";
            $prompt .= "       - **수용 기준 (ACCEPT)**: 'mistake', 'typo', 'silly error' 등 단순 실수는 비판하지 말고 넘어가세요.\n";
            $prompt .= "       - **칭찬 기준 (PRAISE)**: 노트가 구체적이고 배운 점이 적혀있으면 칭찬하세요.\n";
            $prompt .= "     - **필수 포함 문장 (Mandatory Summaries)**: 각 섹션 시작 부분에 아래 문장을 통계에 맞춰 정확히 포함하세요:\n";
            if (!empty($new_stats) && $new_stats['total_qs'] > 0) {
                 $prompt .= "       - \"이번 주 새로운 과제에서 {$student_name}은(는) 총 {$new_stats['total_qs']} 문제를 풀었으며, {$new_stats['flags']} 개의 플래그를 표시하고 {$new_stats['notes']} 개의 오답 노트를 작성했습니다.\"\n";
            }
            if (!empty($rev_stats) && $rev_stats['total_qs'] > 0) {
                 $prompt .= "       - \"복습(Revision) 과제에서는 총 {$rev_stats['total_qs']} 문제를 복습했고, {$rev_stats['flags']} 개의 문제를 체크했으며, {$rev_stats['notes']} 개의 노트를 남겼습니다.\"\n";
            }
            $prompt .= "     - **Revision Quality Analysis**:\n";
            $prompt .= "       - Compare Flags vs Notes. If Flags > Notes, warn them: 'You flagged X questions but only wrote Y notes.'\n";
            $prompt .= "       - Analyze the 'Sample Revision Notes' provided in the data.\n";
            $prompt .= "       - **CRITICISM CRITERIA**: \n";
            $prompt .= "         - If notes are dismissive (e.g., 'I don't know', 'Hard', '???') -> CRITICIZE firmly. Ask them to explain *why* they didn't know.\n";
            $prompt .= "         - If notes are simple errors (e.g., 'typo', 'silly mistake') -> ACCEPT them (do not criticize).\n";
            $prompt .= "         - If notes are detailed/reflective -> PRAISE them.\n";
            $prompt .= "     - **MANDATORY SUMMARIES**: Include these EXACT sentences at the start of your relevant sections:\n";
            if (!empty($new_stats) && $new_stats['total_qs'] > 0) {
                 $prompt .= "       - \"From this week's NEW homework activities, {$student_name} attempted {$new_stats['total_qs']} questions. There were {$new_stats['flags']} flags raised and {$new_stats['notes']} revision notes added.\"\n";
            }
            if (!empty($rev_stats) && $rev_stats['total_qs'] > 0) {
                 $prompt .= "       - \"In their REVISION work, {$student_name} reviewed {$rev_stats['total_qs']} questions, flagged {$rev_stats['flags']} issues, and wrote {$rev_stats['notes']} notes.\"\n";
            }
            $prompt .= "     - Do NOT praise long durations (e.g. > 30 mins) as it may indicate inactivity.\n";
            $prompt .= "     - If a student's score is low (e.g., < 50%), identify it as 'Needs Improvement' or 'Retry'. Encourage them to retake it to improve their understanding.\n";
            $prompt .= "     - If the score is very low (e.g., < 30%), express SERIOUS CONCERN (treat as 'Not Done'). Tell them they need to put in significantly more effort.\n";
            $prompt .= "     - For 'New' activities, if the Course Name includes 'Selective Trial Test', the expected duration is 40 minutes. If completed in less than 35 minutes, warn them that they rushed.\n";
            $prompt .= "     - If Course Name includes 'OC Trial Test': For 'Math', expected is 40 mins. For 'Reading'/'Thinking', expected is 30 mins. Warn if completed > 5 mins too fast.\n";
            $prompt .= "     - **Writing or Essay Activities**:\n";
            $prompt .= "       - If 'Writing' or 'Essay' is in the title, or if there are 2+ attempts:\n";
            $prompt .= "       - **COMPARE the score of the latest attempt against the first attempt**.\n";
            $prompt .= "       - If Score Improved significantly (>20%): Praise the improvement (e.g., 'Great job improving your essay!').\n";
            $prompt .= "       - If Score is same/lower: Remark that the feedback does not seem to be applied. We suggest the student to review it carefully.\n";

            $prompt .= "       - **Cheating Check (Suspicious Speed)**:\n";
            $prompt .= "         - If BOTH the first and second attempts are very short (e.g., < 1 min/question) AND the score is high (e.g., >80%):\n";
            $prompt .= "         - Suspect copying. Warn: 'Both attempts were too fast, raising concerns about potential copying. Please verify their understanding.' (Do not ask questions)\n";

            $prompt .= "- Format: Use HTML (<p>, <strong>, <ul>, <li>). No <html>/<body> tags. Ensure all lists are properly closed and contained.\n";
            $prompt .= "- Length: Concise (~200 words).\n\n";
        }

        $prompt .= "[Data]\n";

        $prompt .= "New Activities:\n";
        if (empty($new_activities)) {
            $prompt .= "- None this week.\n";
        } else {
            foreach ($new_activities as $act) {
                $q_count = $act['question_count'] ?? 'Unknown';
                $status_str = $act['status'] ?? 'Unknown';
                $course_str = $act['coursename'] ?? 'Unknown';
                $flags = $act['stats_flags'] ?? 0;
                $notes = $act['stats_notes'] ?? 0;
                $prompt .= "- Activity: {$act['name']} (Course: {$course_str}, Status: {$status_str}, Max Score: {$act['maxscore']}, Questions: {$q_count})\n";
                $prompt .= "  Stats: Flags={$flags}, Notes={$notes}\n";
                if (!empty($act['sample_notes'])) {
                    $prompt .= "  Sample Revision Notes:\n";
                    foreach ($act['sample_notes'] as $sn) {
                        $prompt .= "    - \"$sn\"\n";
                    }
                }
                if (empty($act['attempts'])) {
                    $prompt .= "  - No attempts made.\n";
                } else {
                    foreach ($act['attempts'] as $att) {
                        $duration_min = round($att['duration'] / 60, 1);
                        $prompt .= "  - Attempt {$att['attempt']}: Score {$att['score']}, Duration {$duration_min} mins ({$att['duration']} sec).";
                        if ($att['duration'] < 180) {
                            $prompt .= " [RUSHED/SKIPPED]";
                        }
                        $prompt .= "\n";
                    }
                }
            }
        }

        $prompt .= "\nRevision Activities:\n";
        if (empty($revision_activities)) {
            $prompt .= "- None this week.\n";
        } else {
            foreach ($revision_activities as $act) {
                $q_count = $act['question_count'] ?? 'Unknown';
                $course_str = $act['coursename'] ?? 'Unknown';
                $flags = $act['stats_flags'] ?? 0;
                $notes = $act['stats_notes'] ?? 0;

                // Unified Format for AI
                $prompt .= "- Revision: {$act['name']} (Course: {$course_str}, Questions: {$q_count})\n";
                $prompt .= "  Stats: Flags={$flags}, Notes={$notes}\n";

                if (!empty($act['sample_notes'])) {
                    $prompt .= "  Sample Revision Notes:\n";
                    foreach ($act['sample_notes'] as $sn) {
                        $prompt .= "    - \"$sn\"\n";
                    }
                }
                
                // Also show attempts if any (for context)
                if (!empty($act['attempts'])) {
                     foreach ($act['attempts'] as $att) {
                        $duration_min = round($att['duration'] / 60, 1);
                        $prompt .= "  - Attempt {$att['attempt']}: Score {$att['score']}, Duration {$duration_min} mins.\n";
                    }
                }
            }
        }

        return $prompt;
    }

    /**
     * Call the Gemini API.
     */
    private function call_api(string $text_prompt): ?string {
        $url = $this->api_url_base . $this->model . ':generateContent?key=' . $this->api_key;

        $generation_config = [
            'temperature' => 0.7,
            'maxOutputTokens' => 4000,
        ];

        // Thinking Model Configuration (e.g. gemini-2.0-flash-thinking)
        if (strpos($this->model, 'thinking') !== false || strpos($this->model, 'gemini-3-pro') !== false) {
            // Gemini 3 Pro / Thinking models often support/require higher token limits
            // and specific thinking config.
            // Note: 'thinking_config' is specific to some experimental endpoints.
            // For standard Gemini 1.5 Pro, maxOutputTokens is higher (8192).
            
            // Adjust for Gemini 3 Pro Preview / Thinking
            // Max Output Tokens: Increased to 8192 to allow for thinking process + output

            $generation_config['temperature'] = 1.0;
            $generation_config['maxOutputTokens'] = 8192;

            // Note: thinking_level defaults to 'high' if not specified.
            // We explicitly set it to ensure deep reasoning.
            $generation_config['thinking_config'] = [
                'include_thoughts' => false,
                'thinking_level' => 'high'
            ];
        }

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $text_prompt]
                    ]
                ]
            ],
            'generationConfig' => $generation_config
        ];

        $curl = new \curl();
        $options = [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json']
        ];

        $response = $curl->post($url, json_encode($payload), $options);

        $this->last_response = $response; // Store raw response
        error_log('GEMINI_DEBUG: Raw API Response: ' . substr($response, 0, 500)); // Log first 500 chars

        if ($curl->get_errno()) {
            $this->last_error = 'Curl Error: ' . $curl->error;
            error_log('GEMINI_DEBUG: ' . $this->last_error);
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return $data['candidates'][0]['content']['parts'][0]['text'];
        } else {
            $this->last_error = 'API Error: ' . substr($response, 0, 500);
            error_log('GEMINI_DEBUG: ' . $this->last_error);
            return null;
        }
    }
}
