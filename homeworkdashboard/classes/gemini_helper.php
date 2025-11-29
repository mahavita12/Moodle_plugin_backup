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
     * @return string|null Generated commentary or null on failure
     */
    public function generate_commentary(string $student_name, array $new_activities, array $revision_activities, string $lang = 'en'): ?string {
        if (!$this->is_configured()) {
            error_log('GEMINI_DEBUG: API Key is missing in settings.');
            return "AI Commentary unavailable: API Key not configured.";
        }

        // Calculate completion stats
        $total_new = count($new_activities);
        $completed_new = 0;
        foreach ($new_activities as $act) {
            $status = $act['status'] ?? 'Unknown';
            error_log("GEMINI_DEBUG: New Activity '{$act['name']}' Status: $status");
            
            // Use status from table logic if available
            if (isset($act['status'])) {
                if ($act['status'] === 'Completed' || $act['status'] === 'Low grade') {
                    $completed_new++;
                }
            } else {
                // Fallback to attempt check (legacy)
                if (!empty($act['attempts'])) {
                    $completed_new++;
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

    /**
     * Construct the prompt for Gemini.
     */
    private function construct_prompt(string $student_name, array $new_activities, array $revision_activities, int $completed_new, int $total_new, int $completed_revision, int $total_revision, string $lang): string {
        $prompt = "You are a supportive and encouraging tutor for a student named {$student_name}.\n";
        
        if ($lang === 'ko') {
            $prompt .= "IMPORTANT: You MUST write this report in KOREAN (Hangul).\n";
        }

        $prompt .= "Analyze their homework progress for this week and write a summary report for their parents.\n\n";
        
        $prompt .= "[Rules]\n";
        $prompt .= "- Use 'We' instead of 'I' (e.g., 'We noticed...', 'We encourage...').\n";
        $prompt .= "- Refer to the student ONLY by their FIRST NAME (e.g., if name is 'Hannah Jung', use 'Hannah').\n";
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
        $prompt .= "- Format: Use HTML (<p>, <strong>, <ul>, <li>). No <html>/<body> tags.\n";
        if ($lang === 'ko') {
            $prompt .= "- **Language Rules (Korean)**:\n";
            $prompt .= "  - Output the report in Korean (Hangul), BUT keep the Subject Names (e.g., 'Math:', 'English:') in English at the start of each bullet point.\n";
            $prompt .= "  - Translate the commentary text following the subject name into natural Korean.\n";
            $prompt .= "  - Do NOT translate 'STERN WARNING'. Keep it as 'Stern Warning' in English.\n";
            $prompt .= "  - Translate the Introductory Summary (completion counts) into Korean.\n";
            $prompt .= "  - **Natural Phrasing**:\n";
            $prompt .= "    - Instead of literal translations like '새로운 활동' or '복습 활동', use more natural terms.\n";
            $prompt .= "    - For 'New Activities', use terms like '이번 주 학습 진도' (This week's learning progress) or '새 진도' (New progress).\n";
            $prompt .= "    - For 'Revision Activities', use terms like '복습' (Review) or '오답 노트' (Incorrect answer note).\n";
            $prompt .= "    - Ensure the tone is professional yet encouraging.\n";
        }

        $prompt .= "- Length: Concise (~200 words).\n\n";

        $prompt .= "[Data]\n";
        
        $prompt .= "New Activities:\n";
        if (empty($new_activities)) {
            $prompt .= "- None this week.\n";
        } else {
            foreach ($new_activities as $act) {
                $q_count = $act['question_count'] ?? 'Unknown';
                $prompt .= "- Activity: {$act['name']} (Max Score: {$act['maxscore']}, Questions: {$q_count})\n";
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
                $prompt .= "- Activity: {$act['name']} (Max Score: {$act['maxscore']}, Questions: {$q_count})\n";
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
            // Thinking models require higher token limits
            // Temperature: 1.0 (Recommended for reasoning models)
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
