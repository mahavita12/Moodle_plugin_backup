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
        // Default to Gemini 3.1 Pro Preview as requested
        $this->model = $config->gemini_model ?? 'gemini-3.1-pro-preview';
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
            $prompt .= "- **금지 사항**: 학부모가 통제할 수 없는 것(교육과정 난이도, 진도 조정 등)에 대한 조언은 하지 마세요. 가정에서 실천 가능한 것(복습 시간 확보, 오답노트 확인, 집중력 향상 등)에 집중하세요.\n";
            
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
            
            $prompt .= "   4. **학습 태도 및 제언** (헤더: <h4 style='color: #8E44AD; border-bottom: 1px solid #8E44AD; padding-bottom: 5px;'>Learning Attitude</h4>):\n";
            $prompt .= "      - 만약 1차, 2차 시도 모두 **시간이 매우 짧고(문제당 1분 미만)** 점수가 **매우 높다면(80% 이상)**:\n";
            $prompt .= "        - **부정행위 가능성 경고**: '두 번의 시도 모두 풀이 시간이 비정상적으로 짧아(예: 5분), 답안을 암기하여 수행했을 가능성이 우려됩니다. 가정에서 실제 이해도를 확인해주실 것을 권장합니다'라고 정중히 경고하세요.\n";
            $prompt .= "      - 복습 과제 완료 속도가 너무 빠르면 '학습의 질'에 대한 우려를 표명하세요.\n";

            $prompt .= "- **형식**: HTML 태그(<p>, <strong>, <ul>, <li>)를 사용하세요. **모든 <ul> 태그는 반드시 닫아야 합니다**.\n\n";

        } else {
            // --- ENGLISH PROMPT (Existing) ---
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

            $prompt .= "You are an education expert analyzing {$student_name}'s academic performance to report to their parents.\n";
            $prompt .= "Write a **detailed and data-driven learning analysis report** based on this week's homework performance.\n\n";
            
            $prompt .= "[Writing Principles]\n";
            $prompt .= "- **Audience**: Parents.\n";
            $prompt .= "- **Perspective**: Strictly 3rd person. Do not address the student directly; objectively explain {$student_name}'s status to parents.\n";
            $prompt .= "- **Tone**: Professional, analytical, polite (e.g., 'We observed...', 'We recommend...', 'Verification is needed.').\n";
            $prompt .= "- **Length**: **Write in detail (400+ words)**. This is not a simple summary but an in-depth, data-driven analysis.\n";
            $prompt .= "- **Avoid**: Do NOT give advice about things parents cannot control (e.g., curriculum difficulty, pacing changes). Focus on actionable items at home (review time, checking notes, improving focus, etc.).\n";
            
            $prompt .= "[Required Content]\n";
            $prompt .= "   1. **Summary** (Header: <h4 style='color: #2C3E50; border-bottom: 2px solid #2C3E50; padding-bottom: 5px;'>Summary</h4>):\n";
            $prompt .= "      - Start with: '{$student_name} completed {$completed_new} out of {$total_new} new activities and {$completed_revision} out of {$total_revision} revision activities.'\n";
            $prompt .= "      - Provide an overall assessment of diligence and learning attitude.\n";
            
            $prompt .= "   2. **New Topics Detailed Analysis** (Header: <h4 style='color: #2980B9; border-bottom: 1px solid #2980B9; padding-bottom: 5px;'>New Topics</h4>):\n";
            $prompt .= "      - **Mandatory Stats**: 'From this week's NEW homework activities, {$student_name} attempted {$new_stats['total_qs']} questions. There were {$new_stats['flags']} flags raised and {$new_stats['notes']} revision notes added.'\n";
            $prompt .= "      - Analyze each subject (Math, English, etc.) with specific **Score (%), Duration, Question Count**.\n";
            $prompt .= "      - **Data-driven**: e.g., 'Completed 20 questions in 8 minutes (too fast), scoring 60%.'\n";
            $prompt .= "      - **Time Analysis**: If avg < 1 min/question, express concern about rushing.\n";
            $prompt .= "      - **Score Analysis**: If score < 60%, specifically advise that concept review is needed.\n";
            $prompt .= "      - 'Selective Trial Test' expected: 40 mins. 'OC Trial Test': 30-40 mins. Warn if significantly faster.\n";

            $prompt .= "   3. **Revision Work Detailed Analysis** (Header: <h4 style='color: #D35400; border-bottom: 1px solid #D35400; padding-bottom: 5px;'>Revision Work</h4>):\n";
            $prompt .= "      - **Mandatory Stats**: 'In their REVISION work, {$student_name} reviewed {$rev_stats['total_qs']} questions, flagged {$rev_stats['flags']} issues, and wrote {$rev_stats['notes']} notes.'\n";
            $prompt .= "      - **Score Progression**: Compare 1st attempt vs last attempt scores.\n";
            $prompt .= "      - **Score Improved**: 'Improved by XX% compared to the first attempt, showing clear learning progress.' - Praise.\n";
            $prompt .= "      - **No Change/Dropped**: 'The feedback does not appear to be fully absorbed. We recommend reviewing incorrect answers at home.'\n";
            
            $prompt .= "   4. **Learning Attitude Check** (Header: <h4 style='color: #8E44AD; border-bottom: 1px solid #8E44AD; padding-bottom: 5px;'>Learning Attitude</h4>):\n";
            $prompt .= "      - If BOTH 1st and 2nd attempts are **very short (< 1 min/question)** AND score is **very high (> 80%)**:\n";
            $prompt .= "        - **Cheating Concern**: 'Both attempts were completed unusually fast (e.g., 5 mins), raising concerns about answer memorization. We recommend verifying actual understanding at home.' - Warn politely.\n";
            $prompt .= "      - If revision work is completed too quickly, express concern about 'quality of learning'.\n";
            $prompt .= "      - **Revision Quality**: Analyze Flag vs Note counts. If many flags but few notes, warn: 'Many questions were flagged but notes are lacking.'\n";

            $prompt .= "- **Formatting**: Use HTML (<p>, <strong>, <ul>, <li>). **All <ul> tags must be properly closed**.\n\n";

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
        if (strpos($this->model, 'thinking') !== false || strpos($this->model, 'gemini-3.1-pro') !== false) {
            // Gemini 3.1 Pro / Thinking models often support/require higher token limits
            // and specific thinking config.
            // Note: 'thinking_config' is specific to some experimental endpoints.
            // For standard Gemini 1.5 Pro, maxOutputTokens is higher (8192).
            
            // Adjust for Gemini 3.1 Pro Preview / Thinking
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
