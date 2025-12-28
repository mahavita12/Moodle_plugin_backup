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
        foreach ($revision_activities as $act) {
            // Use status from table logic if available
            if (isset($act['status'])) {
                if ($act['status'] === 'Completed' || $act['status'] === 'Low grade') {
                    $completed_revision++;
                }
            } else {
                // Fallback to attempt check (legacy)
                if (!empty($act['attempts'])) {
                    $completed_revision++;
                }
            }
        }

        $prompt = $this->construct_prompt($student_name, $new_activities, $revision_activities, $completed_new, $total_new, $completed_revision, $total_revision, $lang);
        error_log('GEMINI_DEBUG: Prompt constructed. Length: ' . strlen($prompt));

        return $this->call_api($prompt);
    }

    private function construct_prompt(string $student_name, array $new_activities, array $revision_activities, int $completed_new, int $total_new, int $completed_revision, int $total_revision, string $lang): string {

        if ($lang === 'ko') {
            // --- KOREAN PROMPT (Native Generation) ---
            $prompt = "\n[Summary Statistics]\n";
            $prompt .= "- New Topics: {$completed_new} out of {$total_new} completed.\n";
            $prompt .= "- Revision Work: {$completed_revision} out of {$total_revision} completed.\n\n";
            $prompt .= "당신은 '{$student_name}' 학생을 아끼고 격려하며 영어 라이팅, 영어 리딩, 수학, 띵킹 스킬을 가르치는 한국인 선생님입니다.\n";
            $prompt .= "이번 주 학생의 과제 수행 결과를 분석하여 학부모님께 보낼 요약 보고서를 작성해주세요.\n\n";
            
            $prompt .= "[작성 규칙]\n";
            $prompt .= "- **언어**: 자연스러운 한국어로 작성하세요. (번역투 지양)\n";
            $prompt .= "- **어조**: 예의 바르면서도 따뜻하고 격려하는 어조를 사용하세요. (예: '했습니다', '보입니다', '응원합니다')\n";
            $prompt .= "- **호칭**: 학생의 이름은 '{$student_name}'(으)로 지칭하세요.\n";
            $prompt .= "- **인사말 생략**: '안녕하세요' 등의 인사말 없이 바로 본론으로 시작하세요.\n";
            
            $prompt .= "- **구성**:\n";
            $prompt .= "   1. **종합 요약** (첫 문단):\n";
            $prompt .= "      - '{$student_name} (은)는 이번 주 새로운 과제 {$total_new}개 중 {$completed_new}개, 복습 과제 {$total_revision}개 중 {$completed_revision}개를 완료했습니다.' 형태로 시작.\n";
            $prompt .= "      - 수행률에 따라 칭찬(90% 이상), 격려(70% 미만), 또는 주의(50% 미만)를 해주세요.\n";
            
            $prompt .= "   2. **상세 분석**:\n";
            $prompt .= "      - **섹션 구분**: 반드시 아래 HTML 헤더를 사용하여 두 섹션을 명확히 구분하세요.\n";
            $prompt .= "        - 새로운 과제: <h4 style=\"color: #3498db; font-size: 16px; margin-top: 15px; margin-bottom: 5px;\">New Topics</h4>\n";
            $prompt .= "        - 복습 과제: <h4 style=\"color: #f39c12; font-size: 16px; margin-top: 15px; margin-bottom: 5px;\">Revision Work</h4>\n";
            $prompt .= "      - **내용 구성**: 각 섹션 내에서 과목명(예: Math, English)을 불릿 포인트로 구분하여 작성하세요.\n";
            $prompt .= "      - 높은 점수나 노력한 부분은 칭찬하세요.\n";
            $prompt .= "      - **성실도 점검**:\n";
            $prompt .= "        - 문제 풀이 시간이 너무 짧은 경우(문제당 1분 미만), '건성으로 풀었음' 또는 '찍었음'을 우회적으로 지적하세요.\n";
            $prompt .= "        - 특히 복습 과제에서 점수는 높으나(80점 이상) 시간이 매우 짧으면(5분 미만), '답을 베낀 것으로 의심됨'을 정중하지만 단호하게 경고하세요 (Warning).\n";
            $prompt .= "        - 만약 학생의 점수가 낮다면 (예: 30% 미만), 단순히 격려만 하지 말고 따끔하게 지적해주세요. 내용 이해가 부족해 보이니 다시 복습하라고 강력하게 권고해야 합니다.\n";
            $prompt .= "        - 'New' 과제 중 코스 이름에 'Selective Trial Test'가 포함된 경우, 45분 풀이 시간을 준수해야 합니다. 40분 미만이라면 너무 빨리 풀었다고 지적하세요.\n";
            $prompt .= "        - 'New' 과제 중 코스 이름에 'OC Trial Test'가 포함된 경우: 과제 이름에 'Math'가 있으면 40분, 'Reading'이나 'Thinking'이 있으면 30분을 준수해야 합니다. 이보다 5분 이상 빨리 끝냈다면 지적하세요.\n";
            
            $prompt .= "- **형식**: HTML 태그(<p>, <strong>, <ul>, <li>)를 사용하여 가독성 있게 작성하세요.\n";
            $prompt .= "- **길이**: 200단어 내외로 간결하게 작성하세요.\n\n";
            
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
            $prompt .= "     - **Revision Integrity Check**: For Revision activities, if Score is High (> 80%) BUT Duration is significantly low (e.g. < 5 mins for 10 questions), issue a **STERN WARNING**. This suggests copying answers without genuine effort.\n";
            $prompt .= "     - Do NOT praise long durations (e.g. > 30 mins) as it may indicate inactivity.\n";
            $prompt .= "     - If a student's score is low (e.g., < 30%), do not just be supportive. Explicitly mention the low score and express serious concern. Tell them they need to put in more effort to understand the material.\n";
            $prompt .= "     - For 'New' activities, if the Course Name includes 'Selective Trial Test', the expected duration is 45 minutes. If completed in less than 40 minutes, warn them that they rushed.\n";
            $prompt .= "     - If Course Name includes 'OC Trial Test': For 'Math', expected is 40 mins. For 'Reading'/'Thinking', expected is 30 mins. Warn if completed > 5 mins too fast.\n";
            $prompt .= "- Format: Use HTML (<p>, <strong>, <ul>, <li>). No <html>/<body> tags.\n";
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
                $prompt .= "- Activity: {$act['name']} (Course: {$course_str}, Status: {$status_str}, Max Score: {$act['maxscore']}, Questions: {$q_count})\n";
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
                $status_str = $act['status'] ?? 'Unknown';
                $course_str = $act['coursename'] ?? 'Unknown';
                $classification = $act['classification'] ?? 'Unknown';

                if ($classification === 'Revision Note' || $classification === 'Active Revision') {
                     // Handle Active Revision (Notes)
                     $prompt .= "- Activity: Revision Note [{$act['name']}] (Course: {$course_str})\n";
                     $prompt .= "  - Status: {$status_str} (Active Notes added this week)\n";
                     $prompt .= "  - Points Earned: {$act['score_display']} (Total notes validated)\n";
                } else {
                    // Standard Quiz Revision with attempts
                    $prompt .= "- Activity: {$act['name']} (Course: {$course_str}, Status: {$status_str}, Max Score: {$act['maxscore']}, Questions: {$q_count})\n";
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
