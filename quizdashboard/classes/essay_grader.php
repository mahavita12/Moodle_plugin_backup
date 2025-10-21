<?php
namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

// Load Moodle's core libraries needed for grading.
require_once($GLOBALS['CFG']->libdir . '/filelib.php');
require_once($GLOBALS['CFG']->dirroot . '/question/engine/lib.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/locallib.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/lib.php');

// Optional (Moodle 4.4+): grade calculator
$usecalc = class_exists('\\mod_quiz\\grade_calculator');
if ($usecalc) {
    require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/classes/grade_calculator.php');
}

/**
 * Essay auto-grading functionality for Moodle
 */
class essay_grader {

    private $google_folder_id;
    private $service_account_path;
    
    // ADDED: Timeout configuration constants
    private const API_CONNECT_TIMEOUT = 45;  // Allow slower initial connection
    private const API_TOTAL_TIMEOUT = 240;   // 4 minutes total to accommodate GPT-5
    private const MAX_RETRY_ATTEMPTS = 3;    // One extra retry for slow model

    public function __construct() {
        $this->google_folder_id = get_config('local_quizdashboard', 'google_drive_folder_id');
        $this->service_account_path = $this->get_service_account_path();
    }

    /**
     * Get current AI provider from Quiz Dashboard config.
     */
    protected function get_provider(): string {
        $provider = get_config('local_quizdashboard', 'provider');
        $provider = is_string($provider) ? strtolower(trim($provider)) : '';
        return in_array($provider, ['anthropic', 'openai']) ? $provider : 'anthropic';
    }

    /**
     * Get Anthropic API key from Quiz Dashboard config.
     */
    protected function get_anthropic_api_key(): string {
        $key = get_config('local_quizdashboard', 'anthropic_apikey');
        if (empty($key)) {
            throw new \moodle_exception('Anthropic API key not configured. Set it in Quiz Dashboard configuration.');
        }
        $key = preg_replace('/\s+/', '', trim((string)$key));
        return $key;
    }

    /**
     * Get Anthropic model from Quiz Dashboard config.
     */
    protected function get_anthropic_model(): string {
        $model = get_config('local_quizdashboard', 'anthropic_model');
        $model = is_string($model) ? trim($model) : '';
        // Map friendly aliases to official Claude 4 Sonnet model identifier
        if ($model === '' || in_array(strtolower($model), ['sonnet-4', 'sonnet4', 'claude-4', 'claude4'], true)) {
            return 'claude-sonnet-4-20250514';
        }
        return $model;
    }

    /**
     * Get OpenAI model from config with sensible default (GPT-5)
     */
    protected function get_openai_model(): string {
        $model = get_config('local_quizdashboard', 'openai_model');
        $model = is_string($model) ? trim($model) : '';
        if ($model === '') {
            return 'gpt-5';
        }
        return $model;
    }

    /**
     * Robustly gets the OpenAI API key from various Moodle configs.
     */
    protected function get_openai_api_key(): string {
        global $CFG;

        $key = '';
        
        // Try plugin-scoped config first (recommended).
        $plugin_key = get_config('local_quizdashboard', 'openai_api_key');
        
        if (!empty($plugin_key)) {
            if (is_object($plugin_key) && isset($plugin_key->value)) {
                $key = $plugin_key->value;
            } elseif (is_string($plugin_key)) {
                $key = $plugin_key;
            }
        }

        // Fallbacks if plugin key isn't set.
        if (empty($key)) { 
            $global_key = get_config('openai_api_key');
            if (!empty($global_key)) {
                if (is_object($global_key) && isset($global_key->value)) {
                    $key = $global_key->value;
                } elseif (is_string($global_key)) {
                    $key = $global_key;
                }
            }
        }
        
        if (empty($key) && !empty($CFG->openai_api_key)) { 
            $key = $CFG->openai_api_key; 
        }

        $key = preg_replace('/\s+/', '', trim((string)$key));
        
        if (empty($key)) {
            throw new \moodle_exception('OpenAI API key not configured. Please set it in the Auto-Grading Settings.');
        }
        
        if (!preg_match('/^sk-[a-zA-Z0-9_-]{20,}$/', $key)) {
            error_log('Invalid OpenAI API key format detected.');
            throw new \moodle_exception('Invalid OpenAI API key format. The key should start with "sk-".');
        }

        return $key;
    }

    /**
     * Get the path to service account JSON file.
     */
    protected function get_service_account_path() {
        global $CFG;
        return $CFG->dataroot . '/local_quizdashboard/service-account.json';
    }

    /**
     * Main function to auto-grade an essay attempt.
     */
    public function auto_grade_attempt($attempt_id, $level = 'general', $include_homework = false) {
        global $DB;

        // DEBUG: Log auto-grading start
        error_log("DEBUG: auto_grade_attempt called for attempt {$attempt_id}, level: {$level}");

        // TIMEOUT FIX: Increase PHP execution time for this operation
        $original_time_limit = ini_get('max_execution_time');
        ini_set('max_execution_time', 900); // 15 minutes for complex API operations
        error_log("DEBUG: Increased PHP max_execution_time from {$original_time_limit} to 900 seconds for attempt {$attempt_id}");

        try {
            // 1. Get essay text and metadata
            $essay_data = $this->extract_essay_data($attempt_id);
            if (!$essay_data) {
                // TIMEOUT FIX: Restore timeout before early return
                ini_set('max_execution_time', $original_time_limit);
                return ['success' => false, 'message' => 'Could not extract essay data.'];
            }

            if (str_word_count($essay_data['answer_text']) < 10) {
                // TIMEOUT FIX: Restore timeout before early return
                ini_set('max_execution_time', $original_time_limit);
                return ['success' => false, 'message' => 'Essay is too short for grading.'];
            }

            // 2. Generate AI feedback
            $feedback_result = $this->generate_essay_feedback($essay_data, $level);
            if (!$feedback_result['success']) {
                // TIMEOUT FIX: Restore timeout before early return
                ini_set('max_execution_time', $original_time_limit);
                return $feedback_result;
            }

            // 3. Detect AI likelihood on INITIAL DRAFT (not final submission)
            // This makes more sense as we want to check if the ORIGINAL work was AI-generated
            // Get initial essay first for AI detection
            $initial_essay_for_ai = $this->get_initial_essay_submission($essay_data['attempt_uniqueid']);
            $ai_likelihood = 'N/A';
            if ($initial_essay_for_ai) {
                $ai_likelihood = $this->detect_ai_assistance($initial_essay_for_ai);
                error_log("Essays Master: AI detection on INITIAL DRAFT - Likelihood: " . $ai_likelihood);
            } else {
                // Fallback to final submission if initial draft not found
                $ai_likelihood = $this->detect_ai_assistance($essay_data['answer_text']);
                error_log("Essays Master: AI detection on FINAL submission (fallback) - Likelihood: " . $ai_likelihood);
            }

            // 4. Generate revision
            $revision_html = $this->generate_essay_revision($essay_data['answer_text'], $level, $feedback_result['data']);
            
            // 4.5. Get initial essay and generate progress commentary
            $initial_essay = $this->get_initial_essay_submission($essay_data['attempt_uniqueid']);
            $progress_commentary = '';
            if ($initial_essay) {
                $progress_commentary = $this->generate_progress_commentary($initial_essay, $essay_data['answer_text']);
            }
            
            // 5. Generate homework if requested
            $homework_html = '';
            if ($include_homework) {
                $homework_result = $this->generate_homework_exercises($essay_data['answer_text'], $feedback_result['data'], $level);
                if ($homework_result['success']) {
                    $homework_html = $homework_result['homework_html'];
                }
            }

            // 6. Build complete feedback HTML
            $complete_html = $this->build_complete_feedback_html(
                $feedback_result['data'], 
                $revision_html, 
                $essay_data,
                $homework_html,
                $initial_essay,
                $progress_commentary
            );

            // 7. Save results
            $this->save_grading_result($attempt_id, $complete_html, $feedback_result['data'], $ai_likelihood, $homework_html);

            // 8. Save grade to Moodle
            $this->save_grade_to_moodle($essay_data, $feedback_result['data']);

            // 9. Upload to Google Drive if configured
            $drive_link = null;
            if ($this->is_google_drive_configured()) {
                // Determine appropriate filename suffix
                $suffix = '';
                if ($include_homework && !empty($homework_html)) {
                    $suffix = 'with_homework';
                }
                $drive_link = $this->upload_to_google_drive($complete_html, $essay_data, $suffix);
            }

            // TIMEOUT FIX: Restore original timeout before successful return
            ini_set('max_execution_time', $original_time_limit);
            error_log("DEBUG: Restored PHP max_execution_time to {$original_time_limit} seconds for successful completion");
            
            return [
                'success' => true, 
                'message' => 'Essay graded successfully.' . ($include_homework ? ' Homework exercises included.' : ''),
                'ai_likelihood' => $ai_likelihood,
                'drive_link' => $drive_link,
                'has_homework' => !empty($homework_html)
            ];

        } catch (\Exception $e) {
            error_log('Essay grading error for attempt ' . $attempt_id . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'A critical error occurred: ' . $e->getMessage()];
        } finally {
            // TIMEOUT FIX: Always restore original timeout
            ini_set('max_execution_time', $original_time_limit);
            error_log("DEBUG: Restored PHP max_execution_time to {$original_time_limit} seconds");
        }
    }

    /**
     * Extract essay data from a quiz attempt.
     */
    public function extract_essay_data($attempt_id) {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attempt_id]);
        if (!$attempt) {
            return false;
        }

        $user = $DB->get_record('user', ['id' => $attempt->userid]);
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
        $course = $DB->get_record('course', ['id' => $quiz->course]);

        $sql = "SELECT q.questiontext, qasd.value as answer_text
                FROM {question_attempts} qa
                JOIN {question} q ON q.id = qa.questionid
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                WHERE qa.questionusageid = :uniqueid
                  AND q.qtype = 'essay'
                  AND qasd.name = 'answer'
                ORDER BY qa.slot ASC, qas.sequencenumber DESC
                LIMIT 1";

        $essay_record = $DB->get_record_sql($sql, ['uniqueid' => $attempt->uniqueid]);
        
        if (!$essay_record) {
            return false;
        }

        return [
            'attempt'           => $attempt,
            'quiz'              => $quiz,
            'user'              => $user,
            'attempt_id'        => $attempt_id,
            'attempt_uniqueid'  => $attempt->uniqueid,
            'user_id'           => $user->id,
            'user_name'         => fullname($user),
            'quiz_name'         => $quiz->name,
            'question_text'     => strip_tags(html_entity_decode($essay_record->questiontext, ENT_QUOTES, 'UTF-8')),
            'answer_text'       => $essay_record->answer_text,
            'submission_time'   => userdate($attempt->timefinish ?: $attempt->timestart)
        ];
    }

        
    /**
     * Get initial essay submission (before Essays Master Round 1)
     */
    protected function get_initial_essay_submission($attempt_uniqueid) {
        global $DB;
        
        $sql = "SELECT qasd.value as answer
                FROM {question_attempts} qa
                JOIN {question} q ON q.id = qa.questionid
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                WHERE qa.questionusageid = ?
                AND q.qtype = 'essay'
                AND qasd.name = 'answer'
                AND qas.sequencenumber = 1
                ORDER BY qa.slot ASC
                LIMIT 1";
        
        $result = $DB->get_record_sql($sql, [$attempt_uniqueid]);
        return $result ? $result->answer : null;
    }

    /**
     * Generate progress commentary comparing initial draft to final submission
     */
    protected function generate_progress_commentary($initial_text, $final_text) {
        // Clean both texts
        $clean_initial = strip_tags($initial_text);
        $clean_final = strip_tags($final_text);
        
        // If texts are identical or very similar, return warning
        similar_text($clean_initial, $clean_final, $similarity);
        
        if ($similarity > 95) {
            return '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">' .
                   '<p style="color: #856404; margin: 0;"><strong>Limited Progress Detected:</strong> Your final submission is very similar to your initial draft. ' .
                   'It appears minimal revisions were made during the Essays Master process. Consider using the feedback from each round to make more substantial improvements.</p>' .
                   '</div>';
        }
        
        $provider = $this->get_provider();
        
        $system_prompt = "You are an encouraging writing coach at GrowMinds Academy. Compare the student's initial draft with their final submission after going through the Essays Master 6-round revision process.

Write a moderate-length paragraph (4-6 sentences) that:
1. Acknowledges specific improvements made (grammar, vocabulary, sentence structure, content depth)
2. Is encouraging but factual
3. Highlights measurable progress
4. Uses Australian English
5. Keeps a positive, motivational tone

If there are NO significant improvements, you MUST note this and provide a constructive warning.

Format your response as plain text (no HTML tags, no markdown). Be specific about what improved.";

        $user_prompt = "Initial Draft:
{$clean_initial}

Final Submission:
{$clean_final}

Provide an encouraging but factual commentary about the student's writing journey from initial draft to final submission.";
        
        try {
            if ($provider === 'anthropic') {
                $data = [
                    'model' => $this->get_anthropic_model(),
                    'system' => $system_prompt,
                    'messages' => [
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => $user_prompt]]]
                    ],
                    'max_tokens' => 500
                ];
                $result = $this->make_anthropic_api_call($data, 'generate_progress_commentary');
            } else {
                $data = [
                    'model' => $this->get_openai_model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user', 'content' => $user_prompt]
                    ],
                    'max_completion_tokens' => 500
                ];
                $result = $this->make_openai_api_call($data, 'generate_progress_commentary');
            }
            
            if ($result['success']) {
                return '<div style="background: #e8f5e9; padding: 15px; border-radius: 8px; border-left: 4px solid #4caf50; margin: 15px 0;">' .
                       '<p style="color: #2e7d32; margin: 0; line-height: 1.6;">' . htmlspecialchars($result['response']) . '</p>' .
                       '</div>';
            } else {
                return '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">' .
                       '<p style="color: #856404; margin: 0;">Progress commentary temporarily unavailable.</p>' .
                       '</div>';
            }
        } catch (Exception $e) {
            error_log("Progress commentary error: " . $e->getMessage());
            return '<div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">' .
                   '<p style="color: #856404; margin: 0;">Progress commentary temporarily unavailable.</p>' .
                   '</div>';
        }
    }

    /**
         * Generate homework exercises for an already graded essay
         */
        public function generate_homework_for_attempt($attempt_id, $level = 'general') {
            global $DB;

            // TIMEOUT FIX: Increase PHP execution time for homework generation
            $original_time_limit = ini_get('max_execution_time');
            ini_set('max_execution_time', 600); // 10 minutes for homework generation
            error_log("DEBUG: Increased PHP max_execution_time from {$original_time_limit} to 600 seconds for homework generation attempt {$attempt_id}");

            try {
                // Check if essay is already graded
                $existing_grading = $this->get_grading_result($attempt_id);
                if (!$existing_grading) {
                    // TIMEOUT FIX: Restore timeout before early return
                    ini_set('max_execution_time', $original_time_limit);
                    return ['success' => false, 'message' => 'Essay must be graded first before generating homework.'];
                }

                // Get essay data
                $essay_data = $this->extract_essay_data($attempt_id);
                if (!$essay_data) {
                    // TIMEOUT FIX: Restore timeout before early return
                    ini_set('max_execution_time', $original_time_limit);
                    return ['success' => false, 'message' => 'Could not extract essay data.'];
                }

                // Generate homework using existing feedback
                $homework_result = $this->generate_homework_exercises(
                    $essay_data['answer_text'], 
                    ['feedback_html' => $existing_grading->feedback_html], 
                    $level
                );

                // CRITICAL FIX: Ensure homework_result is always an array with proper structure
                if (!is_array($homework_result)) {
                    error_log("ERROR: homework_result is not an array, got: " . gettype($homework_result) . " - " . substr((string)$homework_result, 0, 200));
                    ini_set('max_execution_time', $original_time_limit);
                    return [
                        'success' => false, 
                        'message' => 'Homework generation failed: ' . (is_string($homework_result) ? $homework_result : 'Invalid response format')
                    ];
                }

                // CRITICAL FIX: Check if homework generation was successful
                if (!isset($homework_result['success']) || !$homework_result['success']) {
                    ini_set('max_execution_time', $original_time_limit);
                    $error_message = isset($homework_result['message']) ? $homework_result['message'] : 'Unknown homework generation error';
                    return ['success' => false, 'message' => $error_message];
                }

                // FIXED: Remove any existing homework from feedback_html before adding new homework
                $clean_feedback_html = $this->remove_existing_homework($existing_grading->feedback_html);

                // Ensure homework has strategic markers
                $marked_homework_html = $homework_result['homework_html'];
                if (strpos($marked_homework_html, '<!-- EXTRACT_HOMEWORK_START -->') === false) {
                    $marked_homework_html = '<!-- EXTRACT_HOMEWORK_START -->' . $marked_homework_html . '<!-- EXTRACT_HOMEWORK_END -->';
                }

                // Update grading record with new homework only
                $existing_grading->homework_html = $marked_homework_html;
                $existing_grading->timemodified = time();

                // FIXED: Rebuild complete feedback HTML with new homework only
                $complete_html = $clean_feedback_html . $marked_homework_html;
                $existing_grading->feedback_html = $complete_html;
                
                $DB->update_record('local_quizdashboard_gradings', $existing_grading);

                // Auto-upload the combined feedback (with homework) to Google Drive
                $drive_link = null;
                if ($this->is_google_drive_configured()) {
                    error_log("DEBUG: Uploading combined feedback (with_homework) to Google Drive for attempt {$attempt_id}");
                    $drive_link = $this->upload_to_google_drive($complete_html, $essay_data, 'with_homework');
                    if ($drive_link) {
                        // Persist link if schema supports it (non-fatal if column absent)
                        try {
                            $existing_grading->drive_link = $drive_link;
                            $DB->update_record('local_quizdashboard_gradings', $existing_grading);
                        } catch (\Throwable $e) {
                            error_log('Drive link DB update failed (non-fatal): ' . $e->getMessage());
                        }
                    } else {
                        error_log("DEBUG: Google Drive upload returned no link for attempt {$attempt_id}");
                    }
                }

                // TIMEOUT FIX: Restore original timeout before successful return
                ini_set('max_execution_time', $original_time_limit);
                error_log("DEBUG: Restored PHP max_execution_time to {$original_time_limit} seconds for successful homework generation");
                
                return [
                    'success' => true,
                    'message' => 'Homework exercises generated successfully.',
                    'drive_link' => isset($drive_link) ? $drive_link : null
                ];

            } catch (\Exception $e) {
                error_log('Homework generation error for attempt ' . $attempt_id . ': ' . $e->getMessage());
                return ['success' => false, 'message' => 'Error generating homework: ' . $e->getMessage()];
            } finally {
                // TIMEOUT FIX: Always restore original timeout
                ini_set('max_execution_time', $original_time_limit);
                error_log("DEBUG: Restored PHP max_execution_time to {$original_time_limit} seconds");
            }
        }

        /**
         * Remove existing homework section from feedback HTML using strategic markers
         */
        protected function remove_existing_homework($feedback_html) {
            // First try to remove using strategic markers (most reliable method)
            $clean_html = preg_replace('/<!-- EXTRACT_HOMEWORK_START -->.*?<!-- EXTRACT_HOMEWORK_END -->/s', '', $feedback_html);
            
            // If markers worked, return clean result
            if ($clean_html !== $feedback_html) {
                return rtrim($clean_html);
            }

            // Fallback: Remove homework section using multiple patterns for backward compatibility
            $patterns = [
                // Remove div with homework-section class and everything until its closing div
                '/<div[^>]*class="homework-section"[^>]*>.*?<\/div>\s*$/s',
                '/<div[^>]*class="homework-section"[^>]*>.*$/s',
                // Remove homework exercises heading and everything after it
                '/<h2[^>]*>(?:Advanced\s+)?Homework\s+Exercises<\/h2>.*$/is',
                '/<h2[^>]*>(?:Advanced\s+)?Homework\s+Exercises<\/h2>.*?<\/div>\s*$/s',
                // Remove feedback-section containing homework
                '/<div[^>]*class="feedback-section[^"]*"[^>]*>\s*<h2[^>]*>(?:Advanced\s+)?Homework\s+Exercises<\/h2>.*?<\/div>\s*$/s',
                // Remove homework-appendix sections
                '/<div[^>]*class="homework-appendix"[^>]*>.*?<\/div>\s*$/s',
                '/<div[^>]*class="homework-appendix"[^>]*>.*$/s',
                // Fallback: remove anything that looks like homework content
                '/These exercises target specific areas.*$/s',
                '/These challenging exercises target sophisticated.*$/s'
            ];
            
            foreach ($patterns as $pattern) {
                $clean_html = preg_replace($pattern, '', $clean_html);
            }
            
            // Clean up any trailing whitespace or empty divs
            $clean_html = rtrim($clean_html);
            
            return $clean_html;
        }

  

    /**
     * ✅ UPDATED: Extracts the grade and calls the new helper to save it to Moodle.
     */
    protected function save_grade_to_moodle(array $essay_data, array $feedback_data): void {
        $student_comment = $this->create_student_feedback($feedback_data['feedback_html'], $essay_data['attempt_id']);
        
        $fraction = 0.0;
        $score = 0.0;  // Initialize to prevent undefined variable error
        $max_score = 100.0;  // Default max score
        // Try first submission format: "Final Score: 82/100"
        if (preg_match('/<strong>Final Score:\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)<\/strong>/i', $feedback_data['feedback_html'], $matches)) {
            $score = (float) $matches[1];
            $max_score = (float) $matches[2];
            error_log("DEBUG: Grade extraction SUCCESS (first submission) - Found score: {$score}, max_score: {$max_score}");
            if ($max_score > 0) {
                $fraction = max(0.0, min(1.0, $score / $max_score)); // Clamp between 0.0 and 1.0.
                error_log("DEBUG: Calculated fraction: {$fraction} for attempt {$essay_data['attempt_id']}");
            }
        } 
        // Try resubmission format: "Final Score (Previous → New): 75/100 → 82/100"
        else if (preg_match('/<strong>Final Score \(Previous.*?(?:â†’|&rarr;|->|âž”|â–º).*?New\):\s*\d+\/\d+\s*(?:â†’|&rarr;|->|âž”|â–º)\s*(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)<\/strong>/iu', $feedback_data['feedback_html'], $matches)) {
            $score = (float) $matches[1];  // Gets the NEW score after the arrow
            $max_score = (float) $matches[2];
            error_log("DEBUG: Grade extraction SUCCESS (resubmission) - Found score: {$score}, max_score: {$max_score}");
            if ($max_score > 0) {
                $fraction = max(0.0, min(1.0, $score / $max_score)); // Clamp between 0.0 and 1.0.
                error_log("DEBUG: Calculated fraction: {$fraction} for attempt {$essay_data['attempt_id']}");
            }
        }
        // Fallback: Try extracting from scores data if available
        else if (empty($fraction) && isset($feedback_data['scores']) && is_array($feedback_data['scores'])) {
            // Calculate total from individual scores
            $total = 0;
            $total += isset($feedback_data['scores']['content_and_ideas']) ? (int)$feedback_data['scores']['content_and_ideas'] : 0;
            $total += isset($feedback_data['scores']['structure_and_organization']) ? (int)$feedback_data['scores']['structure_and_organization'] : 0;
            $total += isset($feedback_data['scores']['language_use']) ? (int)$feedback_data['scores']['language_use'] : 0;
            $total += isset($feedback_data['scores']['creativity_and_originality']) ? (int)$feedback_data['scores']['creativity_and_originality'] : 0;
            $total += isset($feedback_data['scores']['mechanics']) ? (int)$feedback_data['scores']['mechanics'] : 0;
            
            if ($total > 0) {
                $score = (float) $total;
                $max_score = 100.0;
                $fraction = max(0.0, min(1.0, $score / $max_score));
                error_log("DEBUG: Grade extraction from scores array - Total score: {$score}, fraction: {$fraction}");
            }
        } else {
            error_log("DEBUG: Grade extraction FAILED - neither regex matched for attempt {$essay_data['attempt_id']}");
            error_log("DEBUG: Feedback HTML snippet: " . substr($feedback_data['feedback_html'], max(0, strpos($feedback_data['feedback_html'], 'Final Score') - 50), 200));
        }

        // ADDED: Validation and debugging
        if (!is_numeric($fraction) || $fraction < 0.0 || $fraction > 1.0) {
            error_log("Invalid fraction calculated: $fraction for attempt {$essay_data['attempt_id']}");
            $fraction = 0.0;
        $score = 0.0;  // Initialize to prevent undefined variable error
        $max_score = 100.0;  // Default max score // Default to 0 if invalid
        }
        
        if (empty($student_comment) || !is_string($student_comment)) {
            error_log("Invalid comment for attempt {$essay_data['attempt_id']}");
            $student_comment = "Essay has been automatically graded.";
        }

        error_log("DEBUG: About to call grade_moodle_attempt with fraction: {$fraction} for attempt {$essay_data['attempt_id']}");
        
        try {
            $this->grade_moodle_attempt(
                $essay_data['attempt_id'],
                $fraction,
                $student_comment,
                $essay_data['user_id']
            );
            
            // CRITICAL FIX: Add direct sumgrades update to ensure grades are properly saved
            if (isset($score) && $score > 0 && isset($max_score) && $max_score > 0) {
                $this->ensure_sumgrades_updated($essay_data['attempt_id'], $score);
            }
        } catch (\Throwable $e) {
            debugging('[Essay AI Grade] Failed to update Moodle grade: ' . $e->getMessage(), DEBUG_DEVELOPER);
            error_log("Error saving grade to Moodle for attempt {$essay_data['attempt_id']}: " . $e->getMessage());
        }
    }

    /**
     * ✅ FIXED: Programmatically grades essay questions using corrected Moodle 4.4+ sequence.
     */
    protected function grade_moodle_attempt(int $attemptid, float $fraction, string $comment, int $userid): void {
        global $DB, $CFG;

        try {
            // ✅ EXACT TESTED SEQUENCE: Get attempt and QUBA
            $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
            $uniqueid = $attemptobj->get_attempt()->uniqueid;
            $quba = \question_engine::load_questions_usage_by_activity($uniqueid);

            $essaysGraded = 0;
            
            // Find and grade essay questions using QUBA slots
            foreach ($quba->get_slots() as $slot) {
                $question = $quba->get_question($slot);
                if ($question->get_type_name() !== 'essay') {
                    continue;
                }

                $qa = $quba->get_question_attempt($slot);
                
                // ✅ FIXED: Calculate mark and validate parameters before calling manual_grade
                $max_mark = $qa->get_max_mark();
                $mark = $fraction * $max_mark;
                
                // Validate parameters to prevent the format_text error
                if (!is_numeric($mark) || $mark < 0) {
                    error_log("ERROR: Invalid mark calculated: {$mark} for attempt {$attemptid}");
                    $mark = 0;
                }
                
                if (!is_string($comment) || empty(trim($comment))) {
                    error_log("ERROR: Invalid comment for attempt {$attemptid}");
                    $comment = "Essay has been automatically graded.";
                }
                
                // Ensure FORMAT_HTML is defined
                if (!defined('FORMAT_HTML')) {
                    error_log("ERROR: FORMAT_HTML not defined, using fallback value");
                    define('FORMAT_HTML', 1);
                }
                
                error_log("DEBUG: Grading attempt {$attemptid} - fraction: {$fraction}, max_mark: {$max_mark}, calculated_mark: {$mark}");
                error_log("DEBUG: Parameters - comment: " . substr($comment, 0, 100) . "..., mark: {$mark}, format: " . FORMAT_HTML);
                
                // ✅ FIXED: Use try-catch around manual_grade call and check method signature compatibility
                try {
                    // Check if we're using the correct signature by testing with reflection
                    $reflection = new \ReflectionMethod($qa, 'manual_grade');
                    $parameters = $reflection->getParameters();
                    
                    error_log("DEBUG: manual_grade method has " . count($parameters) . " parameters");
                    foreach ($parameters as $index => $param) {
                        error_log("DEBUG: Parameter {$index}: " . $param->getName());
                    }
                    
                    // Call manual_grade with proper error handling
                    $qa->manual_grade($comment, $mark, FORMAT_HTML);
                    $essaysGraded++;
                    
                    error_log("✅ Successfully called manual_grade for attempt {$attemptid}");
                    
                } catch (\ArgumentCountError $e) {
                    error_log("ERROR: ArgumentCountError in manual_grade for attempt {$attemptid}: " . $e->getMessage());
                    error_log("ERROR: This suggests wrong number of parameters. Trying alternative signature...");
                    
                    // Try alternative signature without format parameter
                    try {
                        $qa->manual_grade($comment, $mark);
                        $essaysGraded++;
                        error_log("✅ Successfully called manual_grade with 2 parameters for attempt {$attemptid}");
                    } catch (\Exception $e2) {
                        error_log("ERROR: Both manual_grade signatures failed for attempt {$attemptid}: " . $e2->getMessage());
                        throw $e2;
                    }
                    
                } catch (\TypeError $e) {
                    error_log("ERROR: TypeError in manual_grade for attempt {$attemptid}: " . $e->getMessage());
                    error_log("ERROR: This suggests parameter type mismatch in manual_grade method");
                    throw $e;
                }
            }

            if ($essaysGraded > 0) {
                // ✅ EXACT TESTED SEQUENCE: Save QUBA
                \question_engine::save_questions_usage_by_activity($quba);
                error_log("✅ QUBA saved directly via question_engine for attempt $attemptid");
                
                // ✅ EXACT TESTED SEQUENCE: Recompute totals via factory
                try {
                    $quizobj = \mod_quiz\quiz_settings::create($attemptobj->get_attempt()->quiz);
                    $calc = $quizobj->get_grade_calculator();
                    
                    if (method_exists($calc, 'recompute_quiz_sumgrades_for_attempts')) {
                        $calc->recompute_quiz_sumgrades_for_attempts([$attemptobj->get_attempt()]);
                        error_log("✅ Per-attempt recompute completed for attempt $attemptid");
                    } else {
                        $calc->recompute_quiz_sumgrades();
                        error_log("✅ Full quiz recompute completed for attempt $attemptid");
                    }
                    
                    // ✅ EXACT TESTED SEQUENCE: Update gradebook
                    quiz_update_grades($quizobj->get_quiz(), $attemptobj->get_userid());
                    error_log("✅ Gradebook updated via quiz_settings for attempt $attemptid");
                    
                } catch (\Exception $e) {
                    error_log("Grade calculator error for attempt $attemptid: " . $e->getMessage());
                    
                    // Fallback: Manual calculation using QUBA
                    try {
                        $fresh_quba = \question_engine::load_questions_usage_by_activity($uniqueid);
                        $totalmark = $fresh_quba->get_total_mark();
                        if ($totalmark !== null) {
                            $DB->set_field('quiz_attempts', 'sumgrades', $totalmark, ['id' => $attemptid]);
                            error_log("✅ Grade updated using QUBA fallback for attempt $attemptid: $totalmark");
                        }
                        
                        // Gradebook update with basic quiz record
                        $quiz = $DB->get_record('quiz', ['id' => $attemptobj->get_attempt()->quiz]);
                        quiz_update_grades($quiz, $attemptobj->get_userid());
                        error_log("✅ Gradebook updated via fallback for attempt $attemptid");
                        
                    } catch (\Exception $e2) {
                        error_log("Fallback also failed for attempt $attemptid: " . $e2->getMessage());
                    }
                }
                
                error_log("✅ Successfully graded {$essaysGraded} essay(s) for attempt {$attemptid} using proven working sequence");
                
            } else {
                error_log("No essay questions found to grade for attempt {$attemptid}");
            }
            
        } catch (\Exception $e) {
            error_log("Critical error in grade_moodle_attempt for attempt {$attemptid}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Creates a concise summary for the Moodle comments field.
     */
    protected function create_student_feedback($feedback_html, $attempt_id) {
        $student_feedback = '';
        
        if (preg_match('/<div id=["\']overall-comments["\'].*?>(.*?)<\/div>/si', $feedback_html, $matches)) {
            $overall = strip_tags($matches[1]);
            if (!empty(trim($overall))) {
                $student_feedback = "<p>" . htmlspecialchars(trim($overall)) . "</p>";
            }
        }
        
        if (empty(trim($student_feedback))) {
            $student_feedback = "<p>Your essay has been graded. Please review the detailed feedback for suggestions.</p>";
        }
        
        
        try {
            $view_url = new \moodle_url('/local/quizdashboard/viewfeedback.php', ['id' => $attempt_id]);
            $student_feedback .= \html_writer::link($view_url, 'View Full Feedback and Revision', [
                'class' => 'btn btn-primary btn-sm',
                'style' => 'color: #ffffff; text-decoration: none;',  
                'target' => '_blank'
            ]);
        } catch (\Exception $e) {
            error_log("Error creating feedback link for attempt {$attempt_id}: " . $e->getMessage());
            // Continue without the link if there's an error
        }
        
        return $student_feedback;
    }

    protected function generate_essay_feedback($essay_data, $level) {
        $apikey = $this->get_openai_api_key();

        $system_prompt = "You are an expert essay grader for students aged 11 to 16. You will ONLY provide structured feedback. When giving scores for each criteria, first, assess whether the student's essay directly responds to the given question and specific points asked. If not, detail what has not been addressed clearly and reflect in the marking. Use Australian English for feedback.

        **CRITICAL FORMATTING RULES FOR EXAMPLES:**
        - When showing original and improved versions in Language Use and Mechanics sections, ALWAYS put them on completely separate lines
        - Use this exact format for before/after examples:
        Original: [student's text]
        Improved: [corrected text]
        - Never put original and improved text on the same line
        - Always use line breaks between original and improved versions
        - ALL examples must be styled in blue color (#3399cc)
        - For other sections (Content, Structure, Creativity), do NOT use original->improved format

        **LIMITS (STRICT):**
        - For every category, the 'Areas for Improvement' list must contain no more than 3 concise bullets (maximum 3).
        - In Content and Ideas, Structure and Organization, and Creativity and Originality sections, the 'Examples' list must contain no more than 3 items (maximum 3). Do not mention quantities in the output.
        - In Language Use and Mechanics, include no more than 5 Original → Improved pairs (maximum 5). Do not mention quantities in the output.
        - Overall Comments must be concise and limited to at most 3 short paragraphs; aim for 1–2 sentences per paragraph.

        Output structured feedback with these sections: 

        <h2 style=\"font-size:18px;\">1. Content and Ideas (25%)</h2> 
        <p><strong>Score:</strong> X/25</p> 
        <ul> 
        <li><strong>Relevance to Question:</strong> <span class=\"relevance-output\" style=\"color:#87CEEB; font-weight: bold;\">Clearly state whether the essay directly answers the question. If Question includes 'what do you feel like writing today', you must only provide some positive comments without talking about relevance to Question.</span></li> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- Provide multiple clear and relevant examples that are specific, distinct, and contextual with clear details how they could be improved or corrected. Do NOT use original→improved format for this section.</span></li>
        <li><span style=\"color:#3399cc;\">- Provide up to three examples (maximum 3). Do not mention quantities in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">2. Structure and Organization (25%)</h2> 
        <p><strong>Score:</strong> X/25</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- Provide multiple clear and relevant examples that are specific, distinct, and contextual with clear details how they could be improved or corrected. Do NOT use original→improved format for this section.</span></li>
        <li><span style=\"color:#3399cc;\">- Provide up to three examples (maximum 3). Do not mention quantities in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">3. Language Use (20%)</h2> 
        <p><strong>Score:</strong> X/20</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- Provide clear and relevant examples showing original and improved versions (maximum 5 pairs). ALWAYS format as:
        <br>• <span style=\"color:#808080;\">Original: [student's text in grey]</span>
        <br>• <span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
        NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Use separate lines for each original and improved pair.</span></li>
        <li><span style=\"color:#3399cc;\">- Provide up to five examples (maximum 5) showing the original and improved version separately on different lines. Do not mention quantities in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">4. Creativity and Originality (20%)</h2> 
        <p><strong>Score:</strong> X/20</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- Provide multiple clear and relevant examples that are specific, distinct, and contextual with clear details how they could be improved or corrected. Do NOT use original→improved format for this section.</span></li>
        <li><span style=\"color:#3399cc;\">- Provide up to three examples (maximum 3). Do not mention quantities in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">5. Mechanics (10%)</h2> 
        <p><strong>Score:</strong> X/10</p> 
        <ul> 
        <li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li> 
        <li><strong>Areas for Improvement:</strong><ul><li>...</li></ul></li> 
        <li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
        <li><span style=\"color:#3399cc;\">- List specific grammar, punctuation, and spelling mistakes found in the essay with corrections. ALWAYS format corrections as:
        <br><span style=\"color:#808080;\">Original: [student's mistake in grey]</span>
        <br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
        NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Each original and improved pair must be on separate lines.</span></li>
        <li><span style=\"color:#3399cc;\">- Include up to 5 examples (maximum 5) showing the original and improved version separately on different lines. Do not mention the limit in the output.</span></li></ul></li> 
        </ul> 

        <h2 style=\"font-size:18px;\">Overall Comments</h2> 
        <div id=\"overall-comments\"><p>Provide up to three short paragraphs (1–2 sentences each), concise and encouraging with concrete next steps.</p></div> 

        <h2 style=\"font-size:16px;\"><p><strong>Final Score: X/100</strong></p></h2> 

        <!-- SCORES_JSON_START -->
        {\"content_and_ideas\": X, \"structure_and_organization\": X, \"language_use\": X, \"creativity_and_originality\": X, \"mechanics\": X, \"final_score\": X}
        <!-- SCORES_JSON_END -->

        Remember: ONLY provide feedback. Do NOT include any revision or rewritten version of the essay. When showing original and improved examples in Language Use and Mechanics sections, ALWAYS use separate lines with clear 'Original:' and 'Improved:' labels. All examples must be in blue color (#3399cc). 

        CRITICAL: After the Final Score section, you MUST include the JSON scores block exactly as shown above, replacing each X with the actual numeric score (no /25, /20, /10 - just the number). This JSON will be used for database storage.";

        $user_content = "Essay Question:\n" . $essay_data['question_text'] . "\n\nStudent Essay:\n" . $essay_data['answer_text'];
        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $user_content] ]]
                ],
                'max_tokens' => 6000,
                'temperature' => 0.3
            ];
            $result = $this->make_anthropic_api_call($data, 'generate_essay_feedback');
        } else {
            $data = [ 
                'model' => $this->get_openai_model(), 
                'messages' => [ 
                    ['role' => 'system', 'content' => $system_prompt], 
                    ['role' => 'user', 'content' => $user_content] 
                ], 
                'max_completion_tokens' => 6000,
                'temperature' => 0.3
            ];
            $result = $this->make_openai_api_call($data, 'generate_essay_feedback');
            if (!$result['success']) {
                error_log('ERROR: OpenAI generate_essay_feedback failed: ' . ($result['message'] ?? 'unknown'));
            }
        }
        if (!$result['success']) {
            return $result;
        }

        $feedback_html = $result['response'];
        // Normalize and sanitize AI output to prevent parsing issues
        // 1) Strip accidental markdown code fences around JSON or entire blocks
        $feedback_html = preg_replace('/```+\s*json\s*(.*?)```+/is', '$1', $feedback_html);
        $feedback_html = preg_replace('/```+(.*?)```+/is', '$1', $feedback_html);
        // 2) Ensure an Overall Comments section exists so downstream extraction succeeds
        if (stripos($feedback_html, 'id="overall-comments"') === false) {
            $feedback_html .= "\n<h2 style=\"font-size:18px;\">Overall Comments</h2>\n" .
                              '<div id="overall-comments"><p>Please see the detailed feedback above for strengths and next steps.</p></div>';
        }
        // 3) Ensure a JSON scores block exists (guards against LLM omissions)
        if (!preg_match('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', $feedback_html)) {
            $feedback_html .= "\n<!-- SCORES_JSON_START -->\n{" .
                '"content_and_ideas": null, "structure_and_organization": null, "language_use": null, '
                . '"creativity_and_originality": null, "mechanics": null, "final_score": null' .
            "}\n<!-- SCORES_JSON_END -->";
        }
        
        // Add strategic markers for resubmission grader extraction
        $feedback_html = $this->add_strategic_markers_to_feedback($feedback_html);
        
        preg_match('/<div id=["\']overall-comments["\'].*?>(.*?)<\/div>/si', $feedback_html, $matches);
        $overall_comments = strip_tags(trim($matches[1] ?? ''));

        return [ 
            'success' => true, 
            'data' => [ 
                'feedback_html' => $feedback_html, 
                'overall_comments' => $overall_comments 
            ] 
        ];
    }

    protected function generate_essay_revision($essay_text, $level, $feedback_data) {
        // This function's logic remains the same.
        $clean_revision = $this->get_clean_revision($essay_text, $level, $feedback_data);
        if (strpos($clean_revision, 'Error:') === 0) {
            return format_text($essay_text, FORMAT_HTML, ['trusted' => true]);
        }
        $formatted_revision = $this->get_formatted_diff($essay_text, $clean_revision);
        if (strpos($formatted_revision, 'Error:') === 0) {
            return format_text($clean_revision, FORMAT_HTML, ['trusted' => true]);
        }
        $revision_html = preg_replace_callback('/\[\*(.*?)\*\]/s', function($m) {
            return '<strong>[' . htmlspecialchars($m[1]) . ']</strong>';
        }, $formatted_revision);
        $revision_html = preg_replace_callback('/\~(.*?)\~/s', function($m) {
            return '<del style="color:#3399cc;">' . htmlspecialchars($m[1]) . '</del>';
        }, $revision_html);
        return $revision_html;
    }

    protected function get_clean_revision($essay_text, $level, $feedback_data) {
        $apikey = $this->get_openai_api_key();
        
        if ($level === 'advanced') {
            $advanced_prompt = "You are a Master Editor, operating with the standards of a top Australian curriculum editor.

    ### Transformation Mandate
    Your task is to transform a student's essay into an exemplary piece that would score the highest mark possible above 90/100. You make substantial, impactful changes using Australian English spelling and conventions.

    **Non-Negotiable Transformation Rules:**
    1.  **Sentence Restructuring:** Rewrite at least one sentences per paragraph for greater impact and sophistication.
    2.  **Structural Integrity:** Re-order sentences or even merge/split paragraphs to improve the logical flow.
    3.  **Elevated Language:** Introduce more sophisticated but commonly used vocabulary and varied sentence structures.
    4.  **Literary Devices:** Introduce at least one effective literary device (e.g., metaphor, analogy).
    5.  **Flawless Mechanics:** All grammar and spelling (Australian English) must be perfect.
    6.  **Punctuation Constraint:** You MUST NEVER use colons (:) or semicolons (;) anywhere in the revision. 

    Your final output MUST ONLY be the transformed essay text. Do not add any commentary or explanation.";
            
            $system_prompt = $advanced_prompt;
        } else {
            $general_prompt = "You are a helpful and supportive editor for young Australian students. Your tone should be encouraging.

    ### Revision Goal
    Your task is to revise the student's essay below to improve its clarity, correctness, and flow, using Australian English spelling and conventions. The goal is to make the essay better while preserving the student's original voice as much as possible.

    **Revision Guidelines:**
    1.  **Clarity and Flow:** Rewrite sentences that are awkward or unclear.
    2.  **Word Choice:** Replace simple or repetitive words with more precise and engaging vocabulary.
    3.  **Correction:** Fix all grammar, spelling (Australian English), and punctuation errors.
    4.  **Punctuation Constraint:** you MUST NEVER use colons (:) or semicolons (;) in your revision. 

    Your final output MUST ONLY be the revised essay text. Do not add any commentary or explanation.";
            
            $system_prompt = $general_prompt;
        }
        
        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [ 
                'model' => $this->get_anthropic_model(),
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => "Essay Question:\n" . ($feedback_data['question_text'] ?? '') . "\n\nStudent Essay to Revise:\n" . $essay_text ] ]]
                ],
                'max_tokens' => 4000,
                'temperature' => 0.25
            ];
            $result = $this->make_anthropic_api_call($data, 'get_clean_revision');
        } else {
            $data = [ 
                'model' => $this->get_openai_model(), 
                'messages' => [ 
                    ['role' => 'system', 'content' => $system_prompt], 
                    ['role' => 'user', 'content' => "Essay Question:\n" . ($feedback_data['question_text'] ?? '') . "\n\nStudent Essay to Revise:\n" . $essay_text] 
                ], 
                'max_completion_tokens' => 4000,
                'temperature' => 0.25
            ];
            // TIMEOUT FIX: Use new robust API call method
            $result = $this->make_openai_api_call($data, 'get_clean_revision');
            if (!$result['success']) {
                error_log('ERROR: OpenAI get_clean_revision failed: ' . ($result['message'] ?? 'unknown'));
            }
        }
        if (!$result['success']) {
            return 'Error: ' . $result['message'];
        }
        
        return trim($result['response']);
    }

    protected function get_formatted_diff($original_text, $revised_text) {
            $apikey = $this->get_openai_api_key();
            
            $system_prompt = "You are a precise text comparison and formatting tool that shows differences sentence by sentence. You will be given an [Original Text] and a [Revised Text]. Your task is to compare these texts and produce a clean, natural output that shows the differences.

            **ABSOLUTE FORMATTING RULES:**
            1. Text that was **removed** from the [Original Text] MUST be wrapped in tildes: ~text to remove~
            2. Text that was **added** in the [Revised Text] MUST be wrapped in brackets and asterisks: [*text to add*]
            3. Text that is **unchanged** between both versions should have NO formatting
            4. Process the comparison sentence by sentence for precision
            5. NEVER use any AI symbols like **, ##, $$, ^^^, ***, ---, ===, or any other unnatural formatting symbols
            6. Output must be natural, readable text suitable for students

            **EXAMPLES OF CORRECT FORMATTING:**

            Example 1 - Word replacement:
            Original: The dog ran quickly to the park.
            Revised: The dog sprinted swiftly to the park.
            CORRECT OUTPUT: The dog ~ran quickly~ [*sprinted swiftly*] to the park.

            Example 2 - Addition only:
            Original: The weather was nice.
            Revised: The weather was absolutely beautiful and nice.
            CORRECT OUTPUT: The weather was [*absolutely beautiful and*] nice.

            Example 3 - Removal only:
            Original: The extremely complicated and difficult task was completed.
            Revised: The task was completed.
            CORRECT OUTPUT: The ~extremely complicated and difficult~ task was completed.

            Example 4 - Sentence restructure:
            Original: When I arrived home, I was tired. I went to bed early.
            Revised: After arriving home exhausted, I immediately went to bed.
            CORRECT OUTPUT: ~When I arrived home, I was tired. I went to bed early.~ [*After arriving home exhausted, I immediately went to bed.*]

            Example 5 - Multiple changes in one sentence:
            Original: The small red car drove slowly down the street.
            Revised: The large blue car sped quickly down the road.
            CORRECT OUTPUT: The ~small red~ [*large blue*] car ~drove slowly~ [*sped quickly*] down the ~street~ [*road*].

            **CRITICAL REQUIREMENTS:**
            - Your final output must be the single, fully formatted text inside <p>...</p> tags
            - Do not provide any commentary, explanation, or markdown code blocks
            - Do not use any ** ## $$ ^^^ *** --- === symbols anywhere in your output
            - Ensure natural flow and readability for students aged 11-16
            - Compare sentence by sentence to catch all differences accurately";

            $user_content = "Please compare the following texts sentence by sentence and apply the formatting rules for a student revision.\\n\\n---\\n[Original Text]:\\n{$original_text}\\n\\n---\\n[Revised Text]:\\n{$revised_text}\\n---";
            
            $provider = $this->get_provider();
            if ($provider === 'anthropic') {
                $data = [
                    'model' => $this->get_anthropic_model(),
                    'system' => $system_prompt,
                    'messages' => [
                        ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $user_content ] ]]
                    ],
                    'max_tokens' => 3000,
                    'temperature' => 0.2
                ];
                $api_result = $this->make_anthropic_api_call($data, 'get_formatted_diff');
                if (!$api_result['success']) {
                    error_log('ERROR: Anthropic get_formatted_diff failed: ' . ($api_result['message'] ?? 'unknown'));
                }
            } else {
                $data = [ 
                    'model' => $this->get_openai_model(), 
                    'messages' => [
                        ['role' => 'system', 'content' => $system_prompt], 
                        ['role' => 'user', 'content' => $user_content]
                    ], 
                    'max_completion_tokens' => 3000,
                    'temperature' => 0.2
                ];
                
                // TIMEOUT FIX: Use new robust API call method
                $api_result = $this->make_openai_api_call($data, 'get_formatted_diff');
            }
            if (!$api_result['success']) {
                return 'Error: Formatting - ' . $api_result['message'];
            }
            
            $result = trim($api_result['response']);
            
            // Clean up any remaining AI symbols that might have slipped through
            $ai_symbols = ['**', '##', '$$', '^^^', '***', '---', '===', '+++', '~~~', '___'];
            foreach ($ai_symbols as $symbol) {
                $result = str_replace($symbol, '', $result);
            }
            
            return $result;
        }

        /**
     * Build complete feedback HTML with optional homework
     */
    protected function build_complete_feedback_html($feedback_result, $revision_html, $essay_data, $homework_html = '', $initial_essay = null, $progress_commentary = '') {
        // IMPROVED: Much better print styles
        $print_styles = "
        <style>
            /* Screen styles */
            .ld-essay-feedback {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                max-width: 850px; 
                margin: 20px auto;
                padding: 30px;
                line-height: 1.6;
                background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                border: 1px solid #dee2e6;
            }
            
            .feedback-section {
                background: #ffffff;
                margin-bottom: 25px;
                padding: 25px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                border: 2px solid #007bff;
                transition: all 0.3s ease;
            }
            
            .feedback-section:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
                transform: translateY(-2px);
            }
            
            .section-header {
                color: #2c3e50;
                font-size: 22px;
                margin-bottom: 15px;
                font-weight: 600;
                border-bottom: 2px solid #e9ecef;
                padding-bottom: 10px;
            }
            /* Align example labels and prevent double bullets */
            .example-label {
                display: inline-block;
                min-width: 90px;
                font-weight: 600;
                vertical-align: top;
            }
            .example-original { color: #808080; }
            .example-improved { color: #3399cc; }
            
            /* Print-specific styles */
            @media print {
                @page {
                    margin: 0.75in;
                    size: A4;
                }
                
                body { 
                    font-family: 'Times New Roman', Times, serif; 
                    font-size: 10pt; 
                    line-height: 1.3;
                    color: #000;
                    background: white;
                }
                
                .ld-essay-feedback { 
                    box-shadow: none !important; 
                    border: none !important; 
                    margin: 0 !important; 
                    padding: 0 !important; 
                    max-width: 100% !important;
                    width: 100% !important;
                }
                
                /* Headers */
                h1, h2, h3, h4, h5, h6 { 
                    color: #000000 !important; 
                    margin-top: 12pt !important;
                    margin-bottom: 6pt !important;
                    page-break-after: avoid;
                }
                
                h2 { 
                    font-size: 12pt !important; 
                    font-weight: bold !important;
                }
                
                /* Horizontal rules - thinner for print */
                hr { 
                    border: 0 !important;
                    border-top: 1pt solid #000 !important; 
                    margin: 6pt 0 !important;
                    page-break-after: avoid;
                }
                
                /* Paragraphs and text */
                p { 
                    font-size: 10pt !important; 
                    margin: 4pt 0 !important;
                    text-align: justify;
                    orphans: 2;
                    widows: 2;
                }
                
                /* Lists */
                ul, ol { 
                    font-size: 10pt !important; 
                    margin: 4pt 0 !important;
                    padding-left: 18pt !important;
                }
                
                li { 
                    font-size: 10pt !important; 
                    margin: 2pt 0 !important;
                    page-break-inside: avoid;
                }
                
                /* Special formatting */
                strong { 
                    font-weight: bold !important; 
                    color: #000 !important;
                }
                
                del { 
                    color: #444 !important; 
                    text-decoration: line-through; 
                }
                
                /* Remove colors for print */
                span[style*='color'] {
                    color: #000 !important;
                }
                
                /* Better spacing for feedback sections */
                .feedback-section {
                    margin-bottom: 10pt !important;
                    page-break-inside: auto;
                }
                
                /* Homework section print styles */
                .homework-section { 
                    page-break-inside: avoid;
                    margin-top: 12pt !important;
                }
                
                .exercise-section { 
                    page-break-inside: avoid;
                    margin-bottom: 10pt !important;
                }
                
                /* Ensure good page breaks */
                .page-break-before {
                    page-break-before: auto;
                    break-before: auto;
                }
                
                .no-page-break {
                    page-break-inside: avoid;
                }
                
                /* Homework-specific overrides to avoid unnecessary breaks between exercises */
                .homework-section .page-break,
                .homework-section .pagebreak {
                    display: none !important;
                }
                
                /* Hide any screen-only elements */
                .screen-only {
                    display: none !important;
                }
            }
        </style>";

        $html_output = $print_styles;
        $html_output .= '<div class="ld-essay-feedback">';
        
        // Header section with enhanced styling
        $html_output .= '<div class="feedback-section no-page-break">';
        $html_output .= '<div style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white !important; padding: 20px; border-radius: 8px; margin: -25px -25px 20px -25px;">';
        $html_output .= '<h1 style="margin: 0; font-size: 24px; font-weight: 300; color: white !important;">Essay Feedback Report</h1>';
        $html_output .= '</div>';
        $html_output .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">';
        $html_output .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 2px solid #28a745;">';
        $html_output .= '<strong style="color: #495057;">Student:</strong><br>' . htmlspecialchars($essay_data['user_name']) . ' (ID: ' . $essay_data['user_id'] . ')';
        $html_output .= '</div>';
        $html_output .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 2px solid #28a745;">';
        $html_output .= '<strong style="color: #495057;">Submission Date:</strong><br>' . htmlspecialchars($essay_data['submission_time']);
        $html_output .= '</div>';
        $html_output .= '</div>';
        $html_output .= '</div>';
        
        // Question section with enhanced styling
        if (!empty($essay_data['question_text'])) {
            $html_output .= '<div class="feedback-section">';
            $html_output .= '<h2 class="section-header" style="color: #6f42c1;">' . htmlspecialchars($essay_data['quiz_name']) . ' - First Submission</h2>';
            $html_output .= '<div style="background: #e3f2fd; border: 1px solid #90caf9; padding: 20px; border-radius: 6px; border-left: 4px solid #2196f3;">';
            $html_output .= '<div style="font-weight: 600; color: #1565c0; margin-bottom: 10px; font-size: 16px;">Essay Question:</div>';
            $html_output .= '<div style="font-size: 15px; line-height: 1.7; color: #1565c0;">' . nl2br(htmlspecialchars($essay_data['question_text'])) . '</div>';
            $html_output .= '</div>';
            $html_output .= '</div>';
        }
        
        // Initial Draft section (if available) - WITH STRATEGIC MARKERS FOR RESUBMISSION GRADER
        if (!empty($initial_essay)) {
            $html_output .= '<div class="feedback-section">';
            $html_output .= '<h2 class="section-header" style="color: #9c27b0;">Initial Draft</h2>';
            $html_output .= '<hr>';
            // START MARKER for initial draft extraction
            $html_output .= '<!-- EXTRACT_INITIAL_START -->';
            $html_output .= '<div style="background: #f3e5f5; padding: 20px; border-radius: 8px; border-left: 4px solid #9c27b0; margin: 10px 0;">';
            $clean_initial_text = $this->sanitize_original_essay_text($initial_essay);
            $initial_paragraphs = preg_split("/\r\n|\n|\r/", trim($clean_initial_text));
            foreach ($initial_paragraphs as $p) {
                if (!empty(trim($p))) {
                    $html_output .= '<p style="margin-bottom: 15px; font-size: 15px; line-height: 1.7; color: #4a148c;">' . htmlspecialchars($p) . '</p>';
                }
            }
            $html_output .= '</div>';
            // END MARKER for initial draft extraction
            $html_output .= '<!-- EXTRACT_INITIAL_END -->';
            $html_output .= '<hr>';
            $html_output .= '</div>';
        }

        // Original essay section - WITH STRATEGIC MARKERS FOR RESUBMISSION GRADER
        $html_output .= '<div class="feedback-section">';
        $html_output .= '<h2 class="section-header" style="color: #17a2b8;">Original Essay</h2>';
        $html_output .= '<hr>';
        // START MARKER for original essay text extraction
        $html_output .= '<!-- EXTRACT_ORIGINAL_START -->';
        $html_output .= '<div style="background: #e8f5f9; padding: 20px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 10px 0;">';
        // CLEANUP: sanitize pasted HTML/markdown artifacts (e.g., <p spellcheck="false">)
        $clean_original_text = $this->sanitize_original_essay_text($essay_data['answer_text']);
        $paragraphs = preg_split("/\r\n|\n|\r/", trim($clean_original_text));
        foreach ($paragraphs as $p) {
            if (!empty(trim($p))) {
                $html_output .= '<p style="margin-bottom: 15px; font-size: 15px; line-height: 1.7; color: #0c5460;">' . htmlspecialchars($p) . '</p>';
            }
        }
        $html_output .= '</div>';
        // END MARKER for original essay text extraction
        $html_output .= '<!-- EXTRACT_ORIGINAL_END -->';
        $html_output .= '<hr>';
        $html_output .= '</div>';
        
        // Your Writing Journey (progress commentary) - AFTER Original Essay
        if (!empty($progress_commentary)) {
            $html_output .= '<div class="feedback-section">';
            $html_output .= '<h2 class="section-header" style="color: #4caf50;">Your Writing Journey from Initial Draft</h2>';
            $html_output .= '<hr>';
            $html_output .= $progress_commentary;
            $html_output .= '<hr>';
            $html_output .= '</div>';
        }

        // Revision section (if exists) - WITH STRATEGIC MARKERS
        if (!empty($revision_html)) {
            $html_output .= '<div class="feedback-section page-break-before">';
            $html_output .= '<h2 style="font-size:16px; color:#003366;">GrowMinds Academy Essay Revision</h2>';
            $html_output .= '<hr>';
            // START MARKER for revision text extraction
            $html_output .= '<!-- EXTRACT_REVISION_START -->';
            $html_output .= $revision_html;
            // END MARKER for revision text extraction
            $html_output .= '<!-- EXTRACT_REVISION_END -->';
            $html_output .= '<hr>';
            $html_output .= '</div>';
        }
        
        // Feedback section - WITH STRATEGIC MARKERS FOR EACH SECTION
        $html_output .= '<div class="feedback-section page-break-before">';
        $html_output .= '<h2 style="font-size:16px; color:#003366;">GrowMinds Academy Essay Feedback</h2>';
        $html_output .= '<hr>';
        // START MARKER for feedback extraction
        $html_output .= '<!-- EXTRACT_FEEDBACK_START -->';
        // Hide JSON score summary at display time
        $display_feedback = $feedback_result['feedback_html'];
        $display_feedback = $this->hide_scores_json_for_display($display_feedback);
        $html_output .= $display_feedback;
        // END MARKER for feedback extraction  
        $html_output .= '<!-- EXTRACT_FEEDBACK_END -->';
        $html_output .= '<hr>';
        $html_output .= '</div>';
        
        // FIXED: Add homework if provided with proper wrapper and strategic markers
        if (!empty($homework_html)) {
            // Ensure homework is wrapped in feedback-section for consistent styling with clear markers
            $html_output .= '<div class="feedback-section page-break-before">';
            
            // Check if homework already has strategic markers, if not add them
            if (strpos($homework_html, '<!-- EXTRACT_HOMEWORK_START -->') === false) {
                $homework_html = '<!-- EXTRACT_HOMEWORK_START -->' . $homework_html . '<!-- EXTRACT_HOMEWORK_END -->';
            }

            // Sanitize homework: remove forced page-break elements/styles between exercises (keep markers)
            // Remove inline styles that force page breaks
            $homework_html = preg_replace('/\sstyle="[^"]*(?:page-break|break-(?:before|after|inside))[^"]*"/i', '', $homework_html);
            // Remove common page-break elements
            $homework_html = preg_replace('/<(?:div|p|span|hr|br)[^>]*(?:class\s*=\s*"[^"]*(?:page-break|pagebreak)[^"]*")[^>]*\/?>(?:\s|&nbsp;|\x{00A0})*/iu', '', $homework_html);
            // Remove comment-based page break markers
            $homework_html = preg_replace('/<!--\s*page\s*break\s*-->/i', '', $homework_html);
            
            // Ensure homework is wrapped so print CSS can target it precisely
            // Do NOT strip strategic markers; wrap outside them.
            $clean_homework = $homework_html;
            // If not already wrapped, add a wrapper that our print CSS understands
            if (stripos($clean_homework, 'class="homework-section"') === false && stripos($clean_homework, 'class=\'homework-section\'') === false) {
                $clean_homework = '<div class="homework-section">' . $clean_homework . '</div>';
            }

            $html_output .= $clean_homework;
            $html_output .= '</div>';
        }
        
        $html_output .= '</div>';
        return $html_output;
    }

    /**
     * Save grading result with optional homework
     */
        protected function save_grading_result($attempt_id, $complete_html, $feedback_data, $ai_likelihood = null, $homework_html = '') {
        global $DB;
        
        error_log("DEBUG: Starting save_grading_result for attempt {$attempt_id}");
        error_log("DEBUG: HTML length: " . strlen($complete_html));
        error_log("DEBUG: AI likelihood: " . ($ai_likelihood ?? 'null'));
        error_log("DEBUG: Homework HTML length: " . strlen($homework_html));
        
        try {
            // Sanitize AI likelihood to fit database constraint (varchar(10))
            $clean_ai_likelihood = $this->sanitize_ai_likelihood($ai_likelihood);
            error_log("DEBUG: Cleaned AI likelihood: " . ($clean_ai_likelihood ?? 'null'));
            
            // Extract subcategory scores from feedback data if available
            $scores = $feedback_data['scores'] ?? [];
            
            $score_content_ideas = $scores['content_and_ideas'] ?? null;
            $score_structure_organization = $scores['structure_and_organization'] ?? null;
            $score_language_use = $scores['language_use'] ?? null;
            $score_creativity_originality = $scores['creativity_and_originality'] ?? null;
            $score_mechanics = $scores['mechanics'] ?? null;

            // Fallback: if ANY score is missing, parse from HTML and fill only missing ones
            $need_parse = ($score_content_ideas === null || $score_structure_organization === null || $score_language_use === null || $score_creativity_originality === null || $score_mechanics === null);
            if ($need_parse) {
                $parsed_scores = $this->extract_subcategory_scores_from_html($feedback_data['feedback_html'] ?? $complete_html);
                if ($score_content_ideas === null) {
                    $score_content_ideas = $parsed_scores['content_and_ideas'] ?? $score_content_ideas;
                }
                if ($score_structure_organization === null) {
                    $score_structure_organization = $parsed_scores['structure_and_organization'] ?? $score_structure_organization;
                }
                if ($score_language_use === null) {
                    $score_language_use = $parsed_scores['language_use'] ?? $score_language_use;
                }
                if ($score_creativity_originality === null) {
                    $score_creativity_originality = $parsed_scores['creativity_and_originality'] ?? $score_creativity_originality;
                }
                if ($score_mechanics === null) {
                    $score_mechanics = $parsed_scores['mechanics'] ?? $score_mechanics;
                }
                error_log("DEBUG: Fallback parsed scores (filled missing) - Content: {" . ($score_content_ideas ?? 'null') . "}, Structure: {" . ($score_structure_organization ?? 'null') . "}, Language: {" . ($score_language_use ?? 'null') . "}, Creativity: {" . ($score_creativity_originality ?? 'null') . "}, Mechanics: {" . ($score_mechanics ?? 'null') . "}");
            }
            
            error_log("DEBUG: Extracted scores - Content: {" . ($score_content_ideas ?? 'null') . "}, " .
                     "Structure: {" . ($score_structure_organization ?? 'null') . "}, " . 
                     "Language: {" . ($score_language_use ?? 'null') . "}, " .
                     "Creativity: {" . ($score_creativity_originality ?? 'null') . "}, " . 
                     "Mechanics: {" . ($score_mechanics ?? 'null') . "}");
            
            $record = new \stdClass();
            $record->attempt_id = $attempt_id;
            $record->feedback_html = $complete_html;
            $record->overall_comments = $feedback_data['overall_comments'] ?? '';
            $record->ai_likelihood = $clean_ai_likelihood;
            $record->homework_html = $homework_html;
            $record->score_content_ideas = $score_content_ideas;
            $record->score_structure_organization = $score_structure_organization;
            $record->score_language_use = $score_language_use;
            $record->score_creativity_originality = $score_creativity_originality;
            $record->score_mechanics = $score_mechanics;
            // Similarity fields may have been computed in resubmission flow; keep existing if not provided
            if (isset($feedback_data['similarity_percent'])) {
                $record->similarity_percent = (int)$feedback_data['similarity_percent'];
                $record->similarity_flag = !empty($feedback_data['similarity_flag']) ? 1 : 0;
                $record->similarity_checkedat = time();
            }
            $record->timemodified = time();
            
            error_log("DEBUG: Checking for existing record...");
            $existing = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
            
            if ($existing) {
                error_log("DEBUG: Updating existing record with ID: " . $existing->id);
                $record->id = $existing->id;
                
                // Log the actual values being saved
                error_log("DEBUG: Record data - attempt_id: {$record->attempt_id}, feedback_html length: " . strlen($record->feedback_html) . ", homework_html length: " . strlen($record->homework_html));
                
                $result = $DB->update_record('local_quizdashboard_gradings', $record);
                error_log("DEBUG: Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
            } else {
                error_log("DEBUG: Creating new record...");
                $record->timecreated = time();
                
                // Log the actual values being saved
                error_log("DEBUG: Record data - attempt_id: {$record->attempt_id}, feedback_html length: " . strlen($record->feedback_html) . ", homework_html length: " . strlen($record->homework_html));
                
                $new_id = $DB->insert_record('local_quizdashboard_gradings', $record);
                error_log("DEBUG: Insert result - new ID: " . ($new_id ?: 'FAILED'));
            }
            
            error_log("DEBUG: Save operation completed successfully");
            
        } catch (\Exception $e) {
            error_log("DEBUG: Database error details: " . $e->getMessage());
            error_log("DEBUG: Error code: " . $e->getCode());
            error_log("DEBUG: Error file: " . $e->getFile() . " line " . $e->getLine());
            throw new \moodle_exception('Error writing to database: ' . $e->getMessage());
        }
    }

    protected function upload_file_to_drive($file_path, $mime_type) {
        global $CFG;
        
        // Check if service account file exists
        if (!file_exists($this->service_account_path)) { 
            error_log("Google Drive Error: Service account file not found at: " . $this->service_account_path);
            return false; 
        }
        
        // Load Google API client
        $vendor_path = $CFG->dirroot . '/vendor/autoload.php';
        if (!file_exists($vendor_path)) { 
            error_log("Google Drive Error: Google API client not found at: " . $vendor_path);
            return false; 
        }
        
        require_once($vendor_path);
        
        try {
            // Use the same authentication approach as working WordPress system
            $client = new \Google\Client();
            $client->setAuthConfig($this->service_account_path);
            $client->addScope(\Google\Service\Drive::DRIVE_FILE);
            
            $service = new \Google\Service\Drive($client);
            
            $file_metadata = new \Google\Service\Drive\DriveFile([
                'name' => basename($file_path), 
                'parents' => [$this->google_folder_id]
            ]);
            
            $uploadedFile = $service->files->create($file_metadata, [
                'data' => file_get_contents($file_path), 
                'mimeType' => $mime_type, 
                'uploadType' => 'multipart', 
                'fields' => 'id'
            ]);
            
            if (!$uploadedFile || !$uploadedFile->id) { 
                error_log("Google Drive Error: Upload failed - no file ID returned");
                return false; 
            }
            
            // Set public permissions
            $service->permissions->create($uploadedFile->id, new \Google\Service\Drive\Permission([
                'type' => 'anyone', 
                'role' => 'reader'
            ]));
            
            $drive_url = "https://drive.google.com/uc?export=download&id=" . $uploadedFile->id;
            error_log("Google Drive Success: File uploaded - " . $drive_url);
            
            return $drive_url;
            
        } catch (\Exception $e) {
            error_log("Google Drive API Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the attempt number (1, 2, 3, etc.) for display purposes
     */
    protected function get_attempt_number($attempt_id, $user_id, $quiz_id) {
        global $DB;
        
        try {
            // Get all attempts for this user and quiz, ordered by time
            $sql = "SELECT id 
                    FROM {quiz_attempts} 
                    WHERE userid = :userid AND quiz = :quizid 
                    ORDER BY timestart ASC";
            
            $attempts = $DB->get_records_sql($sql, ['userid' => $user_id, 'quizid' => $quiz_id]);
            
            // Find the position of our attempt in the ordered list
            $attempt_number = 1;
            foreach ($attempts as $attempt) {
                if ($attempt->id == $attempt_id) {
                    return $attempt_number;
                }
                $attempt_number++;
            }
            
            // Fallback if not found
            return 1;
            
        } catch (Exception $e) {
            error_log("Error calculating attempt number: " . $e->getMessage());
            return 1; // Default fallback
        }
    }

    /**
     * Upload complete HTML feedback to Google Drive
     */
    protected function upload_to_google_drive($complete_html, $essay_data, $suffix = '') {
        global $CFG;
        
        try {
            // Create a temporary file with the HTML content
            $temp_dir = $CFG->tempdir . '/local_quizdashboard';
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }
            
            // Generate filename with NEW format: user_id-name-essay_topic-attempt#-submitted_date/time
            $clean_user_name = strtolower(str_replace(' ', '-', trim($essay_data['user_name'])));
            $clean_quiz_name = strtolower(str_replace(' ', '-', trim($essay_data['quiz_name'])));
            $timestamp = date('Y-m-d-His'); // Format: 2025-09-17-153045
            
            // Calculate actual attempt number (not attempt_id) for better filename
            $attempt_number = $this->get_attempt_number($essay_data['attempt_id'], $essay_data['user_id'], $essay_data['quiz']->id);
            
            $base_filename = "{$essay_data['user_id']}_{$clean_user_name}_{$clean_quiz_name}_attempt_{$attempt_number}_{$timestamp}";
            
            // Add suffix for different submission types
            if (!empty($suffix)) {
                $filename = $this->sanitize_filename($base_filename . '_' . $suffix) . '.html';
            } else {
                $filename = $this->sanitize_filename($base_filename) . '.html';
            }
            
            $file_path = $temp_dir . '/' . $filename;
            
            // Write HTML content to temporary file
            if (file_put_contents($file_path, $complete_html) === false) {
                error_log("Failed to write temporary file: {$file_path}");
                return null;
            }
            
            // Upload to Google Drive
            $drive_link = $this->upload_file_to_drive($file_path, 'text/html');
            
            // Clean up temporary file
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            if ($drive_link) {
                error_log("Successfully uploaded essay feedback to Google Drive: {$drive_link}");
            } else {
                error_log("Failed to upload essay feedback to Google Drive");
            }
            
            return $drive_link;
            
        } catch (Exception $e) {
            error_log("Error uploading to Google Drive: " . $e->getMessage());
            return null;
        }
    }

    protected function sanitize_filename($filename) {
        // Enhanced sanitization for the new filename format
        // Replace any non-alphanumeric, non-hyphen, non-underscore characters with underscores
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
        
        // Replace multiple consecutive underscores with single underscore
        $clean = preg_replace('/_+/', '_', $clean);
        
        // Remove leading/trailing underscores or hyphens
        $clean = trim($clean, '_-');
        
        // Limit length to 200 characters for filesystem compatibility
        return substr($clean, 0, 200);
    }

    public function get_grading_result($attempt_id) {
        // This function's logic remains the same.
        global $DB;
        return $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
    }

    public function is_graded($attempt_id) {
        // This function's logic remains the same.
        global $DB;
        return $DB->record_exists('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
    }

    public function detect_ai_assistance($essay_text) {
        $truncated_essay = mb_strimwidth($essay_text, 0, 8000, "...");
        $system_prompt = "You are an AI detection tool. Analyse the following text and determine the likelihood that it was written or heavily assisted by an AI. Consider factors like vocabulary choice, sentence structure complexity, tone, and the presence of common AI-generated phrases. Your response MUST be a JSON object with a single key 'likelihood', containing an integer value from 0 to 100. For example: {\"likelihood\": 85}. Do not provide any other text, explanation, or markdown.";

        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $truncated_essay ] ]]
                ],
                'max_tokens' => 400
            ];
            $result = $this->make_anthropic_api_call($data, 'detect_ai_assistance');
        } else {
            $data = [
                'model' => $this->get_openai_model(), 
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt], 
                    ['role' => 'user', 'content' => $truncated_essay]
                ], 
                'response_format' => ['type' => 'json_object'], 
                'max_completion_tokens' => 800
            ];
            $result = $this->make_openai_api_call($data, 'detect_ai_assistance');
        }
        if (!$result['success']) {
            return 'Timeout';
        }
        
        $content = $result['response'];
        
        if (!empty($content)) {
            
            // Clean content before JSON parsing
            $cleaned_content = trim($content);
            if (strpos($cleaned_content, '```json') !== false) {
                $cleaned_content = preg_replace('/```json\s*/', '', $cleaned_content);
                $cleaned_content = preg_replace('/\s*```/', '', $cleaned_content);
            }
            
            $json_content = json_decode($cleaned_content, true);
            
            if (isset($json_content['likelihood']) && is_numeric($json_content['likelihood'])) {
                $likelihood = (int) $json_content['likelihood'];
                return $likelihood . '%';
            } else {
                // Fallback regex pattern
                if (preg_match('/["\']?likelihood["\']?\s*:\s*(\d+)/', $cleaned_content, $matches)) {
                    return (int) $matches[1] . '%';
                }
            }
        }
        
        return 'Error';
    }

    /**
     * Get AI likelihood from database or detect and save it
     */
    public function get_or_detect_ai_likelihood($attempt_id) {
        global $DB;
        
        // First check if we already have it in the database
        $existing = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
        
        if ($existing && !empty($existing->ai_likelihood)) {
            return $existing->ai_likelihood;
        }
        
        // If not found, detect it and save
        $essay_data = $this->extract_essay_data($attempt_id);
        if (!$essay_data || empty($essay_data['answer_text'])) {
            return 'No essay text';
        }
        
        $likelihood = $this->detect_ai_assistance($essay_data['answer_text']);
        
        // Save the result to database
        $this->save_ai_likelihood($attempt_id, $likelihood);
        
        return $likelihood;
    }

    /**
     * Sanitize AI likelihood value to fit database constraint (varchar(10))
     */
    protected function sanitize_ai_likelihood($ai_likelihood) {
        if (empty($ai_likelihood)) {
            return null;
        }
        
        // Convert to string and trim whitespace
        $clean_value = trim((string)$ai_likelihood);
        
        // If it's longer than 10 characters, handle appropriately
        if (strlen($clean_value) > 10) {
            // Common error cases
            if (stripos($clean_value, 'parse') !== false) {
                return 'Error';
            } elseif (stripos($clean_value, 'api') !== false) {
                return 'API Err';
            } elseif (stripos($clean_value, 'timeout') !== false) {
                return 'Timeout';
            } elseif (stripos($clean_value, 'network') !== false) {
                return 'Net Err';
            } else {
                // Generic truncation for other cases
                return substr($clean_value, 0, 10);
            }
        }
        
        return $clean_value;
    }

    /**
     * Save AI likelihood to database
     */
    protected function save_ai_likelihood($attempt_id, $likelihood) {
        global $DB;
        
        // Sanitize the likelihood value
        $clean_likelihood = $this->sanitize_ai_likelihood($likelihood);
        
        $existing = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
        
        if ($existing) {
            // Update existing record
            $existing->ai_likelihood = $clean_likelihood;
            $existing->timemodified = time();
            $DB->update_record('local_quizdashboard_gradings', $existing);
        } else {
            // Create new record with just the AI likelihood
            $record = new \stdClass();
            $record->attempt_id = $attempt_id;
            $record->ai_likelihood = $clean_likelihood;
            $record->timecreated = time();
            $record->timemodified = time();
            $DB->insert_record('local_quizdashboard_gradings', $record);
        }
    }

    /**
     * Add strategic markers to feedback HTML for resubmission grader extraction
     */
    protected function add_strategic_markers_to_feedback($feedback_html) {
        // Add markers around each grading section for reliable extraction by resubmission grader
        $sections = [
            'content_and_ideas' => ['CONTENT_IDEAS', '/(<h2[^>]*>.*?Content and Ideas.*?<\/h2>.*?)(?=<h2|$)/si'],
            // Accept both Organization (US) and Organisation (AU)
            'structure_and_organization' => ['STRUCTURE_ORG', '/(<h2[^>]*>.*?Structure\s+and\s+Organi[sz]ation.*?<\/h2>.*?)(?=<h2|$)/si'],
            'language_use' => ['LANGUAGE_USE', '/(<h2[^>]*>.*?Language Use.*?<\/h2>.*?)(?=<h2|$)/si'],
            'creativity_and_originality' => ['CREATIVITY_ORIG', '/(<h2[^>]*>.*?Creativity and Originality.*?<\/h2>.*?)(?=<h2|$)/si'],
            'mechanics' => ['MECHANICS', '/(<h2[^>]*>.*?Mechanics.*?<\/h2>.*?)(?=<h2|$)/si'],
            'final_score' => ['FINAL_SCORE', '/(<h2[^>]*>.*?<strong>Final Score:.*?<\/strong>.*?<\/h2>)/si']
        ];
        
        foreach ($sections as $key => $config) {
            $marker_name = $config[0];
            $pattern = $config[1];
            
            $feedback_html = preg_replace_callback($pattern, function($matches) use ($marker_name) {
                return "<!-- EXTRACT_{$marker_name}_START -->" . $matches[1] . "<!-- EXTRACT_{$marker_name}_END -->";
            }, $feedback_html);
        }
        
        // Add markers around individual score lines for easier extraction
        $feedback_html = preg_replace('/(<p><strong>Score:.*?<\/p>)/si', '<!-- SCORE_MARKER -->$1<!-- /SCORE_MARKER -->', $feedback_html);
        $feedback_html = preg_replace('/(<p><strong>Score \(Previous.*?<\/p>)/si', '<!-- SCORE_MARKER -->$1<!-- /SCORE_MARKER -->', $feedback_html);
        
        // Normalise arrows so extraction regexes match consistently,
        // without breaking HTML comment closers (-->). Avoid replacing
        // the '->' sequence when it is immediately preceded by '-'.
        $feedback_html = str_replace(['&rarr;', '&#8594;', '-&gt;'], '→', $feedback_html);
        $feedback_html = preg_replace('/(?<!-)\->/u', '→', (string)$feedback_html);

        // Standardise example labels so they align nicely via CSS
        $feedback_html = preg_replace('/\bOriginal:\s*/i', '<span class="example-label example-original">Original:</span> ', $feedback_html);
        $feedback_html = preg_replace('/\bImproved:\s*/i', '<span class="example-label example-improved">Improved:</span> ', $feedback_html);
        // Remove stray bullet characters placed before labels to avoid double bullets
        $feedback_html = preg_replace('/(<li[^>]*>\s*)(?:&bull;|•)\s*(<span class=\"example-label\s+example-(?:original|improved)\">)/iu', '$1$2', $feedback_html);
        $feedback_html = preg_replace('/([>\s])(?:&bull;|•)\s*(<span class=\"example-label\s+example-(?:original|improved)\">)/iu', '$1$2', $feedback_html);

        return $feedback_html;
    }

    /**
     * Display-only cleanup to ensure bullets align with Original/Improved labels
     * without altering section markers.
     */
    protected function normalize_example_labels_for_display(string $html): string {
        // Wrap labels if missing
        $html = preg_replace('/\bOriginal:\s*/i', '<span class="example-label example-original">Original:</span> ', $html);
        $html = preg_replace('/\bImproved:\s*/i', '<span class="example-label example-improved">Improved:</span> ', $html);
        // Remove stray bullet glyphs immediately before our labels
        $html = preg_replace('/(<li[^>]*>\s*)(?:&bull;|•)\s*(<span class=\"example-label\s+example-(?:original|improved)\">)/iu', '$1$2', $html);
        $html = preg_replace('/(^|>|\s)(?:&bull;|•)\s*(<span class=\"example-label\s+example-(?:original|improved)\">)/iu', '$1$2', $html);
        // Also handle plain text label case
        $html = preg_replace('/(<li[^>]*>\s*)(?:&bull;|•)\s*(Original:)/iu', '$1$2', $html);
        $html = preg_replace('/(^|>|\s)(?:&bull;|•)\s*(Original:)/iu', '$1$2', $html);

        // Ensure ULs that contain Original/Improved examples render without bullets
        $html = preg_replace_callback('/<ul([^>]*)>([\s\S]*?)<\/ul>/i', function($m) {
            $attrs = $m[1];
            $content = $m[2];
            if (preg_match('/example-label|Original:|Improved:/i', $content)) {
                // Strip any existing list-style and enforce none
                if (preg_match('/style\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                    $style = $sm[1];
                    $style = preg_replace('/list-style[^;]*;?/i', '', $style);
                    $style = trim($style);
                    $style = ($style !== '') ? $style . '; list-style-type:none; padding-left:0; margin-left:0;' : 'list-style-type:none; padding-left:0; margin-left:0;';
                    $attrs = preg_replace('/style\s*=\s*"[^"]*"/i', 'style="' . $style . '"', $attrs);
                } else {
                    $attrs .= ' style="list-style-type:none; padding-left:0; margin-left:0;"';
                }
                return '<ul' . $attrs . '>' . $content . '</ul>';
            }
            return $m[0];
        }, $html);
        return $html;
    }

    /**
     * Remove the machine-readable JSON scores block from visible HTML, while
     * keeping it available in the raw feedback (passed separately for parsing).
     */
    protected function hide_scores_json_for_display(string $html): string {
        return preg_replace('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', '', $html) ?? $html;
    }

    /**
     * Extract scores from JSON block in feedback HTML (preferred method)
     */
    protected function extract_scores_from_json($feedback_html) {
        if (preg_match('/<!-- SCORES_JSON_START -->(.*?)<!-- SCORES_JSON_END -->/s', $feedback_html, $matches)) {
            $json_string = trim($matches[1]);
            $scores = json_decode($json_string, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($scores)) {
                error_log("DEBUG: Successfully extracted scores from JSON: " . json_encode($scores));
                return $scores;
            } else {
                error_log("DEBUG: JSON decode failed: " . json_last_error_msg());
            }
        }
        return null;
    }

    /**
     * Extract the last score (assumed NEW score) from a section segment.
     */
    protected function extract_last_score_from_segment($segment, $max) {
        if (empty($segment)) {
            return null;
        }

        if (preg_match('/<p>\s*<strong>\s*Score[^:]*:\s*<\/strong>\s*(.*?)<\/p>/si', $segment, $lineMatch)) {
            $line = $lineMatch[1];
            if (preg_match_all('/(\d+)\s*\/\s*' . $max . '/si', $line, $nums) && !empty($nums[1])) {
                return (int) end($nums[1]);
            }
        }

        if (preg_match_all('/(\d+)\s*\/\s*' . $max . '/si', $segment, $allNums) && !empty($allNums[1])) {
            return (int) end($allNums[1]);
        }

        return null;
    }

    /**
     * Extract subcategory scores from feedback HTML (handles first submissions and resubmissions)
     */
    protected function extract_subcategory_scores_from_html($feedback_html) {
        $scores = [];
        if (empty($feedback_html)) {
            return $scores;
        }

        // Debug: Log a sample of the HTML we're trying to parse
        error_log("DEBUG: extract_subcategory_scores - HTML sample (first 2000 chars): " . substr($feedback_html, 0, 2000));

        // Try JSON extraction first (preferred method)
        $json_scores = $this->extract_scores_from_json($feedback_html);
        if ($json_scores !== null) {
            error_log("DEBUG: Using JSON scores for subcategory extraction");
            return [
                'content_and_ideas' => $json_scores['content_and_ideas'] ?? null,
                'structure_and_organization' => $json_scores['structure_and_organization'] ?? null,
                'language_use' => $json_scores['language_use'] ?? null,
                'creativity_and_originality' => $json_scores['creativity_and_originality'] ?? null,
                'mechanics' => $json_scores['mechanics'] ?? null
            ];
        }

        error_log("DEBUG: JSON extraction failed, falling back to regex parsing");
        
        // Look for the actual pattern used: "Score: X/Y" (for first submission) 
        // or "Score (Previous → New): X/Y → Z/Y" (for resubmission)
        // Try simpler patterns that match what the AI actually outputs
        
        // Pattern 1: First submission format - "Score: 14/20"
        if (preg_match('/Content and Ideas.*?Score:\s*(\d+)\s*\/\s*25/si', $feedback_html, $m)) {
            $scores['content_and_ideas'] = (int)$m[1];
            error_log("DEBUG: Found content_and_ideas via simple pattern: " . $m[1]);
        }
        if (preg_match('/Structure and Organi[sz]ation.*?Score:\s*(\d+)\s*\/\s*25/si', $feedback_html, $m)) {
            $scores['structure_and_organization'] = (int)$m[1];
            error_log("DEBUG: Found structure_and_organization via simple pattern: " . $m[1]);
        }
        if (preg_match('/Language Use.*?Score:\s*(\d+)\s*\/\s*20/si', $feedback_html, $m)) {
            $scores['language_use'] = (int)$m[1];
            error_log("DEBUG: Found language_use via simple pattern: " . $m[1]);
        }
        if (preg_match('/Creativity and Originality.*?Score:\s*(\d+)\s*\/\s*20/si', $feedback_html, $m)) {
            $scores['creativity_and_originality'] = (int)$m[1];
            error_log("DEBUG: Found creativity_and_originality via simple pattern: " . $m[1]);
        }
        if (preg_match('/Mechanics.*?Score:\s*(\d+)\s*\/\s*10/si', $feedback_html, $m)) {
            $scores['mechanics'] = (int)$m[1];
            error_log("DEBUG: Found mechanics via simple pattern: " . $m[1]);
        }

        // Pattern 2: Resubmission format - "Score (Previous → New): 14/20 → 15/20"
        if (empty($scores['content_and_ideas'])) {
            if (preg_match('/Content and Ideas.*?Score.*?→.*?(\d+)\s*\/\s*25/si', $feedback_html, $m)) {
                $scores['content_and_ideas'] = (int)$m[1];
                error_log("DEBUG: Found content_and_ideas via resubmission pattern: " . $m[1]);
            }
        }
        if (empty($scores['structure_and_organization'])) {
            if (preg_match('/Structure and Organi[sz]ation.*?Score.*?→.*?(\d+)\s*\/\s*25/si', $feedback_html, $m)) {
                $scores['structure_and_organization'] = (int)$m[1];
                error_log("DEBUG: Found structure_and_organization via resubmission pattern: " . $m[1]);
            }
        }
        if (empty($scores['language_use'])) {
            if (preg_match('/Language Use.*?Score.*?→.*?(\d+)\s*\/\s*20/si', $feedback_html, $m)) {
                $scores['language_use'] = (int)$m[1];
                error_log("DEBUG: Found language_use via resubmission pattern: " . $m[1]);
            }
        }
        if (empty($scores['creativity_and_originality'])) {
            if (preg_match('/Creativity and Originality.*?Score.*?→.*?(\d+)\s*\/\s*20/si', $feedback_html, $m)) {
                $scores['creativity_and_originality'] = (int)$m[1];
                error_log("DEBUG: Found creativity_and_originality via resubmission pattern: " . $m[1]);
            }
        }
        if (empty($scores['mechanics'])) {
            if (preg_match('/Mechanics.*?Score.*?→.*?(\d+)\s*\/\s*10/si', $feedback_html, $m)) {
                $scores['mechanics'] = (int)$m[1];
                error_log("DEBUG: Found mechanics via resubmission pattern: " . $m[1]);
            }
        }

        // If we found scores via simple patterns, return them
        if (!empty($scores)) {
            error_log("DEBUG: Extracted scores via regex: " . json_encode($scores));
            return $scores;
        }

        error_log("DEBUG: Simple patterns failed, trying complex parsing");

        // Fallback to regex parsing
        $sections = [
            'content_and_ideas' => ['title' => 'Content and Ideas', 'marker' => 'CONTENT_IDEAS', 'max' => 25],
            'structure_and_organization' => ['title' => 'Structure and Organization', 'marker' => 'STRUCTURE_ORG', 'max' => 25],
            'language_use' => ['title' => 'Language Use', 'marker' => 'LANGUAGE_USE', 'max' => 20],
            'creativity_and_originality' => ['title' => 'Creativity and Originality', 'marker' => 'CREATIVITY_ORIG', 'max' => 20],
            'mechanics' => ['title' => 'Mechanics', 'marker' => 'MECHANICS', 'max' => 10],
        ];
        foreach ($sections as $key => $cfg) {
            $max = (int)$cfg['max'];
            $value = null;

            // Marker-based extraction
            $marker_pattern = '/<!-- EXTRACT_' . $cfg['marker'] . '_START -->(.*?)<!-- EXTRACT_' . $cfg['marker'] . '_END -->/si';
            if (preg_match($marker_pattern, $feedback_html, $msection)) {
                $value = $this->extract_last_score_from_segment($msection[1], $max);
            }

            if ($value === null) {
                $titlePattern = ($key === 'structure_and_organization')
                    ? 'Structure\\s+and\\s+Organi[sz]ation'
                    : preg_quote($cfg['title'], '/');
                if (preg_match('/<h2[^>]*>.*?' . $titlePattern . '.*?<\\/h2>(.*?)(?=<h2|$)/si', $feedback_html, $sec)) {
                    $value = $this->extract_last_score_from_segment($sec[1], $max);
                }
            }

            if ($value === null) {
                // Absolute fallback: look for any X/max occurrences following the title
                $fallbackPattern = ($key === 'structure_and_organization')
                    ? '/Structure\\s+and\\s+Organi[sz]ation.*?(\d+)\s*\/\s*' . $max . '/si'
                    : '/' . preg_quote($cfg['title'], '/') . '.*?(\d+)\s*\/\s*' . $max . '/si';
                if (preg_match_all($fallbackPattern, $feedback_html, $matches) && !empty($matches[1])) {
                    $value = (int) end($matches[1]);
                }
            }

            $scores[$key] = $value !== null ? (int)$value : null;
        }
        return $scores;
    }

    /**
     * CRITICAL FIX: Ensure sumgrades field is properly updated in quiz_attempts table
     */
    protected function ensure_sumgrades_updated($attempt_id, $extracted_score) {
        global $DB;
        
        try {
            error_log("DEBUG: ensure_sumgrades_updated called for attempt {$attempt_id} with score {$extracted_score}");
            
            // First check current sumgrades value
            $current_attempt = $DB->get_record('quiz_attempts', ['id' => $attempt_id], 'sumgrades,uniqueid');
            if (!$current_attempt) {
                error_log("ERROR: Could not find attempt {$attempt_id}");
                return false;
            }
            
            error_log("DEBUG: Current sumgrades: " . ($current_attempt->sumgrades ?? 'NULL'));
            
            // If sumgrades is already set and matches our score (within 0.5 tolerance), no need to update
            if ($current_attempt->sumgrades !== null && abs($current_attempt->sumgrades - $extracted_score) < 0.5) {
                error_log("DEBUG: sumgrades already correctly set to {$current_attempt->sumgrades}");
                return true;
            }
            
            // Try to get the total mark from QUBA as a verification
            try {
                $quba = \question_engine::load_questions_usage_by_activity($current_attempt->uniqueid);
                $quba_total = $quba->get_total_mark();
                error_log("DEBUG: QUBA total mark: " . ($quba_total ?? 'NULL'));
                
                // If QUBA has a total mark and it matches our extracted score, use it
                if ($quba_total !== null && abs($quba_total - $extracted_score) < 0.5) {
                    $update_score = $quba_total;
                } else {
                    // Otherwise use our extracted score
                    $update_score = $extracted_score;
                }
            } catch (\Exception $e) {
                error_log("DEBUG: Could not load QUBA, using extracted score: " . $e->getMessage());
                $update_score = $extracted_score;
            }
            
            // Direct update to quiz_attempts.sumgrades
            $result = $DB->set_field('quiz_attempts', 'sumgrades', $update_score, ['id' => $attempt_id]);
            
            if ($result) {
                error_log("✅ Successfully updated sumgrades to {$update_score} for attempt {$attempt_id}");
                
                // Verify the update
                $updated_attempt = $DB->get_record('quiz_attempts', ['id' => $attempt_id], 'sumgrades');
                if ($updated_attempt && $updated_attempt->sumgrades == $update_score) {
                    error_log("✅ Verification successful - sumgrades is now {$updated_attempt->sumgrades}");
                    return true;
                } else {
                    error_log("❌ Verification failed - sumgrades is still " . ($updated_attempt->sumgrades ?? 'NULL'));
                }
            } else {
                error_log("❌ Failed to update sumgrades for attempt {$attempt_id}");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("ERROR: Exception in ensure_sumgrades_updated for attempt {$attempt_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ADDED: Robust OpenAI API call with proper timeout handling and retry logic
     */
    protected function make_openai_api_call($data, $operation_name = 'API call') {
        $attempts = 0;
        $last_error = '';

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            $attempts++;
            error_log("DEBUG: Attempting {$operation_name} - attempt {$attempts}/" . self::MAX_RETRY_ATTEMPTS);

            try {
                $apikey = $this->get_openai_api_key();
                // Ensure Moodle curl class is available in all environments
                global $CFG; require_once($CFG->libdir . '/filelib.php');
                
                $curl = new \curl();
                $curl->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $apikey]);
                
                // FIXED: Optimized timeout settings
                $curl->setopt([
                    'CURLOPT_TIMEOUT' => max(180, (int) (self::API_TOTAL_TIMEOUT * 0.9)),
                    'CURLOPT_CONNECTTIMEOUT' => self::API_CONNECT_TIMEOUT,
                    'CURLOPT_NOSIGNAL' => 1,  // Prevent timeout issues on some systems
                    'CURLOPT_TCP_KEEPALIVE' => 1,
                    'CURLOPT_TCP_KEEPIDLE' => 120,
                    'CURLOPT_TCP_KEEPINTVL' => 60
                ]);
                
                error_log("DEBUG: Set curl timeouts for {$operation_name} - connect: " . self::API_CONNECT_TIMEOUT . "s, total: " . self::API_TOTAL_TIMEOUT . "s");
                
                // NORMALIZE OpenAI payload for Chat Completions
                $modelName = isset($data['model']) ? strtolower((string)$data['model']) : '';
                $isGpt5 = ($modelName !== '' && strpos($modelName, 'gpt-5') !== false);

                if ($isGpt5) {
                    // GPT-5 chat completions expects max_completion_tokens
                    if (isset($data['temperature'])) {
                        unset($data['temperature']);
                    }
                    if (isset($data['max_tokens']) && !isset($data['max_completion_tokens'])) {
                        $data['max_completion_tokens'] = $data['max_tokens'];
                        unset($data['max_tokens']);
                    }
                    if (isset($data['max_output_tokens'])) {
                        $data['max_completion_tokens'] = $data['max_output_tokens'];
                        unset($data['max_output_tokens']);
                    }
                } else {
                    // Non-GPT-5 models expect max_tokens
                    if (isset($data['max_tokens'])) {
                        // ok
                    } elseif (isset($data['max_output_tokens'])) {
                        $data['max_tokens'] = $data['max_output_tokens'];
                        unset($data['max_output_tokens']);
                    } elseif (isset($data['max_completion_tokens'])) {
                        $data['max_tokens'] = $data['max_completion_tokens'];
                        unset($data['max_completion_tokens']);
                    }
                }

                $response = $curl->post('https://api.openai.com/v1/chat/completions', json_encode($data));
                
                // Check for curl errors (including timeout)
                if ($curl->get_errno() !== 0) {
                    $curl_error = $curl->error;
                    $last_error = "cURL error: {$curl_error}";
                    error_log("DEBUG: {$operation_name} attempt {$attempts} failed - {$last_error}");
                    
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts); // Progressive backoff
                        continue;
                    }
                    
                    return ['success' => false, 'message' => "Request timeout after {$attempts} attempts: {$curl_error}"];
                }
                
                $body = json_decode($response, true);

                if (isset($body['error'])) {
                    $errmsg = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : (string)$body['error']['message'];
                    $last_error = "API error: {$errmsg}";
                    error_log("DEBUG: {$operation_name} attempt {$attempts} failed - {$last_error}");

                    $lower = strtolower($errmsg);
                    $isRate = (strpos($lower, 'rate limit') !== false) || (strpos($lower, 'too many requests') !== false) || (strpos($lower, 'capacity') !== false) || (strpos($lower, 'overloaded') !== false);
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        if ($isRate) {
                            $wait = rand(10, 20);
                            error_log("DEBUG: Rate limit detected for {$operation_name}. Backing off {$wait}s before retry #" . ($attempts + 1));
                            sleep($wait);
                            continue;
                        }
                        sleep(7 * $attempts);
                        continue;
                    }
                    return ['success' => false, 'message' => $last_error];
                }

                // Extract text from Chat Completions (with fallbacks)
                $text = '';
                if (isset($body['choices'][0]['message']['content'])) {
                    $text = (string)$body['choices'][0]['message']['content'];
                } elseif (isset($body['output_text'])) {
                    $text = (string)$body['output_text'];
                } elseif (isset($body['content']) && is_array($body['content'])) {
                    foreach ($body['content'] as $part) {
                        if (is_array($part) && isset($part['type'])) {
                            if ($part['type'] === 'output_text' && isset($part['text'])) {
                                $text .= (string)$part['text'];
                            } elseif ($part['type'] === 'text' && isset($part['text'])) {
                                $text .= (string)$part['text'];
                            }
                        }
                    }
                }

                if ($text === '') {
                    $last_error = 'Invalid API response structure';
                    error_log("DEBUG: {$operation_name} attempt {$attempts} failed - {$last_error}");
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }
                    return ['success' => false, 'message' => $last_error];
                }

                // Success!
                error_log("DEBUG: {$operation_name} succeeded on attempt {$attempts}");
                return ['success' => true, 'response' => $text];

            } catch (\Exception $e) {
                $last_error = "Exception: " . $e->getMessage();
                error_log("DEBUG: {$operation_name} attempt {$attempts} failed - {$last_error}");
                
                if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    sleep(3 * $attempts);
                    continue;
                }
            }
        }

        return ['success' => false, 'message' => "Failed after {$attempts} attempts. Last error: {$last_error}"];
    }

    protected function generate_homework_exercises($essay_text, $feedback_data, $level) {
        try {
            $apikey = $this->get_openai_api_key();
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'OpenAI API key error: ' . $e->getMessage()];
        }

        if ($level === 'advanced') {
            $system_prompt = <<<'PROMPT'
You are an expert homework generator for Australian students aged 11-16. Create comprehensive personalized homework exercises that EXACTLY match the following structure and quality.

**CRITICAL: You MUST create ALL of these sections, including a COMPLETE ANSWER KEY at the end. The answer key is NOT optional.**

1. Exercise 1: [Advanced Style/Register topic from the **author's writing patterns**] - 5 questions.
2. Exercise 2: [Advanced Grammar/Sophistication topic from the **author's writing patterns**] - 5 questions.
3. Exercise 3: [Second grammar topic from the **author's second common mistakes**] - 5 questions.
4. Vocabulary Builder table - exactly 6 rows with sophisticated words.
5. Sentence Improvement - up to 10 of the author's actual problematic sentences (maximum 10).
6. Complete Answer Key for ALL exercises above.

**ADVANCED MODIFICATIONS FOR EXERCISES 1 & 2 ONLY:**

**Exercise 1 - Advanced Style and Register (7 questions):**
Instead of basic grammar errors, test sophisticated concepts like:
- Formal vs informal register appropriateness
- Academic tone and vocabulary choices
- Sentence sophistication and variety
- Audience-appropriate language selection
- Professional writing conventions

**Exercise 2 - Advanced Grammar and Rhetoric (7 questions):**
Instead of basic grammar errors, test complex concepts like:
- Subjunctive mood usage
- Complex conditional structures
- Parallel structure for rhetorical effect
- Sentence combining for sophistication

**ADVANCED QUESTION EXAMPLES:**
- Which sentence demonstrates the most appropriate academic register for a formal essay?
- Which option shows the most effective use of parallel structure?
- Which revision best employs the subjunctive mood?
- Which word choice creates the most precise professional tone?

**ADVANCED VOCABULARY BUILDER:**
Use sophisticated but age-appropriate words like: articulate, comprehensive, facilitate, contemporary, paradigm, intrinsic, etc.

**KEEP EXERCISES IN THE EXACT FORMAT SHOWN BELOW**

**ABSOLUTE FORMATTING RULES:**
- The final output must be ONLY the raw HTML content. Do NOT wrap the output in ```html or ```.
- The main heading must be exactly: `<h2 style="font-size:18px; color:#003366;">Homework Exercises</h2>`.
- For all grammar and spelling exercises, you MUST provide four multiple-choice options on new lines.

**ENHANCED QUESTION GENERATION RULES:**
Your primary goal is to create questions that test a specific grammatical rule within a sentence that provides enough context to make only ONE answer correct as shown in the examples below.

###The sentences must contain clear clues (like time markers or plural/singular identifiers) that force the verb to take a specific number (singular/plural) and tense (past/present).
* **BAD EXAMPLE:** `The children ___ curious about the exhibit.`
* **GOOD EXAMPLE:** `During the tour yesterday, the children ___ very curious about the mummy exhibit and asked many questions.`
* **GOOD EXAMPLE:** `Although the museum contains thousands of items, each individual exhibit ___ a unique story to tell.`
* **GOOD EXAMPLE:** `In the archives right now, one of the ancient manuscripts ___ beginning to fade under the harsh lights.`

###The sentences must include either a clear time marker (e.g., "yesterday," "every Tuesday," "next year") or another verb that establishes a dominant tense for the narrative.
* **BAD EXAMPLE:** `When the kids threw tomatoes, the knight ___ silent.`
* **GOOD EXAMPLE:** `As the children looked at the detailed display, they ___ at the ridiculous size of the knight's armor.`
* **GOOD EXAMPLE:** `According to the museum's schedule, next week's special demonstration ___ the art of sword fighting.`
* **GOOD EXAMPLE:** `Every time my family visits that museum, it ___ more crowded than the last.`

###The context must establish whether the noun is specific (previously mentioned or unique) or non-specific (being introduced for the first time).
* **BAD EXAMPLE:** `At the museum, there was ___ forgotten object.`
* **GOOD EXAMPLE:** `The tour guide pointed to a dusty corner. "Here," she said, "is ___ forgotten object I was telling you about earlier."`
* **GOOD EXAMPLE:** `While walking through the gallery, my little brother spotted ___ unusual sculpture tucked away behind a curtain.`

- **VOCABULARY BUILDER FORMAT:** The 'Complete the sentence' column MUST be a fill-in-the-blank exercise. Replace the target word in the sentence with a long underscore `__________`.
- The correct answer in the multiple-choice questions should NOT be bolded. It should only be bolded in the Answer Key.
- For the sentence improvement section, provide adequate writing space above each line for handwritten responses.
 - In the Complete Answer Key, PROVIDE a model "Improved:" sentence for EACH of the 10 Sentence Improvement items. Number them 1 through 10 and show only the improved revision for each.

**EXACT HTML TEMPLATE TO USE:**
<div class="page-break"></div>
<div class="feedback-section" style="background: #ffffff; margin-bottom: 25px; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 2px solid #007bff;">
<h2 style="font-size:16px; color:#003366;">Homework Exercises</h2>
<hr style="border:0;border-top:2px solid #003366;margin:18px 0;">

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-family: Calibri, Arial, sans-serif;">
<h3 style="color:#0066cc; font-family: Calibri, Arial, sans-serif; margin-bottom:8px;">Exercise 1: [Advanced Style/Register Topic]</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px; font-family: Calibri, Arial, sans-serif;"><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>

<ol style="font-family: Calibri, Arial, sans-serif;">
<li style="margin-bottom:20px; line-height:1.4;">[Question 1]<br><br><ul style="list-style-type: none; padding-left: 20px; margin-top: 10px; line-height:1.6;"><li style="margin-bottom:4px;">a) option</li><li style="margin-bottom:4px;">b) option</li><li style="margin-bottom:4px;">c) option</li><li style="margin-bottom:4px;">d) option</li></ul></li>
<li>[Question 2]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 3]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 4]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 5]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>

</ol>
</div>

<div class="exercise-section" style="margin-bottom:30px; background-color:white; padding:15px; border-radius:5px;">
<h3 style="color:#0066cc;">Exercise 2: [Advanced Grammar/Sophistication Topic]</h3>
<p><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>

<ol>
<li>[Question 1]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 2]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 3]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 4]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 5]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>

</ol>
</div>



<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-family: Calibri, Arial, sans-serif;">
<h3 style="color:#0066cc; font-family: Calibri, Arial, sans-serif; margin-bottom:8px;">Exercise 3: [Third Grammar Topic]</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px; font-family: Calibri, Arial, sans-serif;"><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
<p style="font-family: Calibri, Arial, sans-serif; margin-bottom:15px;"><strong>Instructions:</strong> Choose the correct option for each sentence.</p>
[Continue with 5 questions using same format]
</div>

<!-- Exercise 4 removed intentionally -->

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-family: Calibri, Arial, sans-serif;">
<h3 style="color:#0066cc; font-family: Calibri, Arial, sans-serif; margin-bottom:8px;">Vocabulary Builder</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px; font-family: Calibri, Arial, sans-serif;"><strong>Tip for Improvement:</strong> Learning precise vocabulary will make your writing more sophisticated and engaging.</p>
[Vocabulary table with 6 words]
</div>

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-family: Calibri, Arial, sans-serif;">
<h3 style="color:#0066cc; font-family: Calibri, Arial, sans-serif; margin-bottom:8px;">Sentence Improvement</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px; font-family: Calibri, Arial, sans-serif;"><strong>Tip for Improvement:</strong> Rewriting sentences helps you develop better sentence structure and clarity in your writing.</p>

<ol style="font-family: Calibri, Arial, sans-serif;">
<li style="margin-bottom:35px; line-height:1.4;">
[Original problematic sentence from student's essay]<br><br>
<div style="margin-top:25px;">
<hr style="border:0; border-top:1px solid #ccc; margin:15px 0;">
</div>
</li>
[Continue with remaining 9 sentences using same format with writing lines]
</ol>
</div>

<div class="exercise-section" style="margin-bottom:20px; background-color:#e8f4f8; padding:20px; border-radius:5px; font-family: Calibri, Arial, sans-serif;">
<h3 style="color:#003366; font-family: Calibri, Arial, sans-serif; margin-bottom:15px;">Complete Answer Key</h3>
[Complete answer key for all exercises above]
</div>


</div>
PROMPT;
        } else {
            // General prompt
            $system_prompt = <<<'PROMPT'
You are an expert homework generator for Australian students aged 11-16. Create comprehensive personalized homework exercises that EXACTLY match the following structure and quality.

**CRITICAL: You MUST create ALL of these sections, including a COMPLETE ANSWER KEY at the end. The answer key is NOT optional.**

1. Exercise 1: [Grammar topic from the **author's most common mistakes**] - 5 questions.
2. Exercise 2: [Second grammar topic from the **author's second common mistakes**] - 5 questions.
3. Exercise 3: [Third grammar topic from the **author's third common mistakes**] - 5 questions.
4. Vocabulary Builder table - exactly 6 rows with their actual words.
5. Sentence Improvement - up to 10 of the author's actual problematic sentences (maximum 10).
6. Complete Answer Key for ALL exercises above.

**ABSOLUTE FORMATTING RULES:**
- The final output must be ONLY the raw HTML content. Do NOT wrap the output in ```html or ```.
- The main heading must be exactly: `<h2 style="font-size:18px; color:#003366;">Homework Exercises</h2>`.
- For all grammar and spelling exercises, you MUST provide four multiple-choice options on new lines.

**ENHANCED QUESTION GENERATION RULES:**
Your primary goal is to create questions that test a specific grammatical rule within a sentence that provides enough context to make only ONE answer correct as shown in the examples below.

###The sentences must contain clear clues (like time markers or plural/singular identifiers) that force the verb to take a specific number (singular/plural) and tense (past/present).
* **BAD EXAMPLE:** `The children ___ curious about the exhibit.`
* **GOOD EXAMPLE:** `During the tour yesterday, the children ___ very curious about the mummy exhibit and asked many questions.`
* **GOOD EXAMPLE:** `Although the museum contains thousands of items, each individual exhibit ___ a unique story to tell.`
* **GOOD EXAMPLE:** `In the archives right now, one of the ancient manuscripts ___ beginning to fade under the harsh lights.`

###The sentences must include either a clear time marker (e.g., "yesterday," "every Tuesday," "next year") or another verb that establishes a dominant tense for the narrative.
* **BAD EXAMPLE:** `When the kids threw tomatoes, the knight ___ silent.`
* **GOOD EXAMPLE:** `As the children looked at the detailed display, they ___ at the ridiculous size of the knight's armor.`
* **GOOD EXAMPLE:** `According to the museum's schedule, next week's special demonstration ___ the art of sword fighting.`
* **GOOD EXAMPLE:** `Every time my family visits that museum, it ___ more crowded than the last.`

###The context must establish whether the noun is specific (previously mentioned or unique) or non-specific (being introduced for the first time).
* **BAD EXAMPLE:** `At the museum, there was ___ forgotten object.`
* **GOOD EXAMPLE:** `The tour guide pointed to a dusty corner. "Here," she said, "is ___ forgotten object I was telling you about earlier."`
* **GOOD EXAMPLE:** `While walking through the gallery, my little brother spotted ___ unusual sculpture tucked away behind a curtain.`

- **VOCABULARY BUILDER FORMAT:** The 'Complete the sentence' column MUST be a fill-in-the-blank exercise. Replace the target word in the sentence with a long underscore `__________`.
- The correct answer in the multiple-choice questions should NOT be bolded. It should only be bolded in the Answer Key.
- For the sentence improvement section, provide adequate writing space above each line for handwritten responses.
 - In the Complete Answer Key, PROVIDE a model "Improved:" sentence for EACH of the 10 Sentence Improvement items. Number them 1 through 10 and show only the improved revision for each.

**EXACT HTML TEMPLATE TO USE:**
<div class="feedback-section" style="background: #ffffff; margin-bottom: 25px; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 2px solid #007bff;">
<h2 style="font-size:16px; color:#003366;">Homework Exercises</h2>
<hr style="border:0;border-top:2px solid #003366;margin:18px 0;">

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
<h3 style="color:#0066cc;">Exercise 1: [Grammar Topic]</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px;"><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
<ol>
<li>[Question 1]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 2]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 3]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 4]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 5]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>

</ol>
</div>

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
<h3 style="color:#0066cc;">Exercise 2: [Grammar Topic]</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px;"><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
[Continue with 5 questions using same format]
</div>

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
<h3 style="color:#0066cc;">Exercise 3: [Grammar Topic]</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px;"><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
[Continue with 5 questions using same format]
</div>

<!-- Exercise 4 removed intentionally -->

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
<h3 style="color:#0066cc;">Vocabulary Builder</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px;"><strong>Tip for Improvement:</strong> Learning precise vocabulary will make your writing more sophisticated and engaging.</p>
[Vocabulary table with 6 words]
</div>

<div class="exercise-section" style="margin-bottom:35px; background-color:white; padding:20px; border-radius:5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
<h3 style="color:#0066cc;">Sentence Improvement</h3>
<p style="background-color:#e8f4f8; padding:8px; border-radius:4px; margin-bottom:15px;"><strong>Tip for Improvement:</strong> Rewriting sentences helps you develop better sentence structure and clarity in your writing.</p>
<ol>
<li style="margin-bottom:35px;">[Original problematic sentence]<br><br>
<div style="margin-top:25px;">
<hr style="border:0; border-top:1px solid #ccc; margin:15px 0;">
</div>
</li>
[Continue with remaining 9 sentences using same format]
</ol>
</div>

<div class="exercise-section" style="margin-bottom:20px; background-color:#e8f4f8; padding:20px; border-radius:5px;">
<h3 style="color:#003366; margin-bottom:15px;">Complete Answer Key</h3>
[Complete answer key for all exercises above]
</div>
</div>
PROMPT;
        }

        // Rest remains exactly the same
        $feedback_text = strip_tags($feedback_data['feedback_html'] ?? '');
        $feedback_text = mb_strimwidth($feedback_text, 0, 3000, "...");
        $essay_text_truncated = mb_strimwidth($essay_text, 0, 1500, "...");

        $user_content = "Create personalized homework exercises based on this student's essay and feedback:\n\n";
        $user_content .= "ESSAY:\n" . $essay_text_truncated . "\n\n";
        $user_content .= "FEEDBACK:\n" . $feedback_text . "\n\n";
        $user_content .= "LEVEL: " . $level;

        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $user_content] ]]
                ],
                'max_tokens' => 9000,
                'temperature' => 0.45
            ];
            $result = $this->make_anthropic_api_call($data, 'homework generation');
        } else {
            $data = [
                'model' => $this->get_openai_model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_content]
                ],
                'max_completion_tokens' => 9000,
                'temperature' => 0.45
            ];
            $result = $this->make_openai_api_call($data, 'homework generation');
        }
        
        if (!$result['success']) {
            return $result; // Already has proper structure
        }

        $homework_html = trim($result['response']);
        
        // Clean up any markdown artifacts
        $homework_html = preg_replace('/^```html\s*/', '', $homework_html);
        $homework_html = preg_replace('/\s*```$/', '', $homework_html);

        // Add strategic markers around the entire homework content
        $homework_html = '<!-- EXTRACT_HOMEWORK_START -->' . $homework_html . '<!-- EXTRACT_HOMEWORK_END -->';

        return [
            'success' => true,
            'homework_html' => $homework_html
        ];
    }

    protected function is_google_drive_configured() {
        // This function's logic remains the same.
        return !empty($this->google_folder_id) && file_exists($this->service_account_path);
    }

    /**
     * Make robust Anthropic API call.
     */
    protected function make_anthropic_api_call($data, $operation_name = 'API call') {
        $attempts = 0;
        $last_error = '';

        // Log the actual model being used
        error_log("🤖 Quiz Dashboard (Anthropic): Using model: " . ($data['model'] ?? 'unknown'));

        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            $attempts++;
            error_log("🤖 Quiz Dashboard (Anthropic): Attempting {$operation_name} - attempt {$attempts}/" . self::MAX_RETRY_ATTEMPTS);

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
                    error_log("🚨 Quiz Dashboard (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }

                    return ['success' => false, 'message' => "Request timeout after {$attempts} attempts: {$curl_error}"];
                }

                $body = json_decode($response, true);

                if (isset($body['error'])) {
                    $msg = is_array($body['error']) ? ($body['error']['message'] ?? json_encode($body['error'])) : (string)$body['error'];
                    $last_error = 'API error: ' . $msg;
                    error_log("🚨 Quiz Dashboard (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    $lower = strtolower($msg);
                    $isRate = (strpos($lower, 'rate limit') !== false) || (strpos($lower, 'too many requests') !== false) || (strpos($lower, 'capacity') !== false) || (strpos($lower, 'overloaded') !== false);
                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        if ($isRate) {
                            $wait = rand(10, 20);
                            error_log("DEBUG: Anthropic rate limit detected for {$operation_name}. Backing off {$wait}s before retry #" . ($attempts + 1));
                            sleep($wait);
                            continue;
                        }
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

                if ($text === '') {
                    $last_error = 'Invalid Anthropic API response structure';
                    error_log("🚨 Quiz Dashboard (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                    if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                        sleep(2 * $attempts);
                        continue;
                    }

                    return ['success' => false, 'message' => $last_error];
                }

                error_log("✅ Quiz Dashboard (Anthropic): {$operation_name} succeeded on attempt {$attempts}");
                return ['success' => true, 'response' => $text];

            } catch (\Exception $e) {
                $last_error = 'Exception: ' . $e->getMessage();
                error_log("🚨 Quiz Dashboard (Anthropic): {$operation_name} attempt {$attempts} failed - {$last_error}");

                if ($attempts < self::MAX_RETRY_ATTEMPTS) {
                    sleep(3 * $attempts);
                    continue;
                }
            }
        }

        return ['success' => false, 'message' => "Failed after {$attempts} attempts. Last error: {$last_error}"];
    }

    /**
     * Sanitize original essay text to remove pasted HTML/markdown wrappers while preserving strategic markers.
     * - Strips common copied tags like <p ...>...</p> and attributes such as spellcheck.
     * - Converts <br> to newlines so paragraph splitting works.
     * - Removes leading/trailing quotes left by markdown.
     */
    protected function sanitize_original_essay_text($raw_text) {
        if ($raw_text === null) {
            return '';
        }

        $text = $raw_text;

        // Replace <br> variants with newlines
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);

        // Remove opening <p ...> and closing </p> tags while keeping inner text
        $text = preg_replace('/<\s*p\b[^>]*>/i', '', $text);
        $text = preg_replace('/<\s*\/\s*p\s*>/i', "\n\n", $text);

        // Remove other harmless attributes often copied in Word/Editors
        $text = preg_replace('/\s*(contenteditable|spellcheck|style|class|dir|lang)\s*=\s*"[^"]*"/i', '', $text);

        // Strip remaining tags except markers we never expect inside the raw text anyway
        $text = strip_tags($text);

        // Normalize whitespace
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Trim smart quotes or stray quotes at text boundaries
        $text = trim($text);
        $text = preg_replace('/^[\"\'\x{2018}\x{2019}\x{201C}\x{201D}]+/u', '', $text);
        $text = preg_replace('/[\"\'\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $text);

        return $text;
    }

}
