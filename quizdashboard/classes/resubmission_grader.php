<?php
namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/essay_grader.php');
require_once(__DIR__ . '/quiz_manager.php');

class resubmission_grader extends essay_grader {

    private $quiz_manager;

    public function __construct() {
        parent::__construct();
        $this->quiz_manager = new quiz_manager();
    }
    /**
     * Generate comparative feedback using OpenAI with robust API call method
     */
    /**
     * Generate comparative feedback using OpenAI with robust API call method
     */
    private function generate_comparative_feedback($current_essay_data, $previous_grading, $previous_scores, $level, $submission_number) {

        $ordinal = $this->get_ordinal_string($submission_number);
        $prev_ordinal = $this->get_ordinal_string($submission_number - 1);

        $system_prompt = "You are an expert essay grader providing feedback on a {$ordinal} submission for students aged 11-16. 

This student previously submitted this essay and received feedback. You are now comparing their {$ordinal} attempt against their {$prev_ordinal} attempt and its feedback.

**GRADING PHILOSOPHY**: 
- Compare the current submission directly against the previous submission and its feedback
- Assess how well the student incorporated the feedback from their {$prev_ordinal} submission
- Genuine improvement should be rewarded, minimal changes should not
- Only increase scores where there is genuine, measurable improvement
- Maintain the current scores where improvement is minimal or absent
- Refrain from reducing the scores from the previous scores
- If they simply copied the revision from the previous feedback, this should result in zero scores
- Use Australian English for all feedback

**SCORING APPROACH**:
Previous scores from {$prev_ordinal} submission:
" . $this->format_previous_scores_for_prompt($previous_scores) . "

- Show improvement as: Previous Score → New Score  


**FINAL SCORE RULE**:
- The Final Score (Previous → New) MUST equal the sum of the five NEW subcategory scores.
- Use exact integer arithmetic. Do not invent totals; compute them from your NEW subcategory scores.

**CRITICAL FORMATTING RULES FOR EXAMPLES:**
- When showing original and improved versions in Language Use and Mechanics sections, ALWAYS put them on completely separate lines
- Use this exact format for before/after examples:
Original: [student's text]
Improved: [corrected text]
- Never put original and improved text on the same line
- Always use line breaks between original and improved versions
- ALL examples must be styled in blue color (#3399cc)

**LIMITS (STRICT):**
- For every category, the 'Areas for Improvement' list must contain no more than 3 concise bullets (maximum 3).
- In Content and Ideas, Structure and Organization, and Creativity and Originality sections, the 'Examples' list must contain no more than 3 items (maximum 3). Do not mention quantities in the output.
- In Language Use and Mechanics, include no more than 5 Original → Improved pairs (maximum 5). Do not mention quantities in the output.

**OUTPUT STRUCTURE**: You must follow this exact HTML format:

<h2 style=\"font-size:18px;\">1. Content and Ideas (25%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/25 → [NEW_SCORE]/25</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide up to three examples (maximum 3) with clear improvement suggestions in blue color.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">2. Structure and Organization (25%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/25 → [NEW_SCORE]/25</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide up to three examples (maximum 3) with clear improvement suggestions in blue color.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">3. Language Use (20%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/20 → [NEW_SCORE]/20</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
<li><span style=\"color:#3399cc;\">- Provide clear and relevant examples showing original and improved versions (maximum 5 pairs). ALWAYS format as:
<br> <span style=\"color:#808080;\">Original: [student's text in grey]</span>
<br> <span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Use separate lines for each original and improved pair.</span></li>
<li><span style=\"color:#3399cc;\">- Provide up to five examples (maximum 5) showing the original and improved version separately on different lines. Do not mention quantities in the output.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">4. Creativity and Originality (20%)</h2>
<p><strong>Score (Previous  New):</strong> [PREVIOUS_SCORE]/20  [NEW_SCORE]/20</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Strengths:</strong><ul><li>Provide exactly one concise bullet summarising the main strength.</li></ul></li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide up to three examples (maximum 3) with clear improvement suggestions in blue color.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">5. Mechanics (10%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/10 → [NEW_SCORE]/10</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
<li><span style=\"color:#3399cc;\">- List specific grammar, punctuation, and spelling mistakes found in the essay with corrections. ALWAYS format corrections as:
<br><span style=\"color:#808080;\">Original: [student's mistake in grey]</span>
<br><span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Each original and improved pair must be on separate lines.</span></li>
<li><span style=\"color:#3399cc;\">- Include up to 5 examples (maximum 5) showing the original and improved version separately on different lines. Do not mention the limit in the output.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">Overall Comments</h2>
<div id=\"overall-comments\"><p>Provide up to three short paragraphs (1–2 sentences each), concise and encouraging with concrete next steps.</p></div>

<h2 style=\"font-size:16px;\"><p><strong>Final Score (Previous → New): [PREVIOUS_TOTAL]/100 → [NEW_TOTAL]/100</strong></p></h2>

<!-- SCORES_JSON_START -->
{\"content_and_ideas\": [NEW_SCORE], \"structure_and_organization\": [NEW_SCORE], \"language_use\": [NEW_SCORE], \"creativity_and_originality\": [NEW_SCORE], \"mechanics\": [NEW_SCORE], \"final_score\": [NEW_TOTAL]}
<!-- SCORES_JSON_END -->

Remember: When showing original and improved examples in Language Use and Mechanics sections, ALWAYS use separate lines with clear 'Original:' and 'Improved:' labels. All examples must be in blue color.

CRITICAL: After the Final Score section, you MUST include the JSON scores block exactly as shown above, replacing each [NEW_SCORE] and [NEW_TOTAL] with the actual numeric NEW scores (no /25, /20, /10 - just the number). This JSON will be used for database storage.";

        // Extract previous essay text and feedback using strategic markers
        $previous_essay_text = $this->extract_original_essay_from_feedback($previous_grading->feedback_html);
        $key_feedback_points = $this->extract_key_feedback_points($previous_grading->feedback_html);

        // Include previous scores in the prompt for context
        $previous_scores_text = "Previous Scores from {$prev_ordinal} submission:\n";
        foreach ($previous_scores as $key => $score) {
            if ($key !== 'final_score') {
                $title = ucwords(str_replace('_', ' ', $key));
                $previous_scores_text .= "- {$title}: {$score['score']}/{$score['max']}\n";
    }
    }
        $previous_scores_text .= "- Final Score: {$previous_scores['final_score']['score']}/100\n\n";

        $user_content = "Essay Question:\n" . $current_essay_data['question_text'] . 
                       "\n\nPrevious ({$prev_ordinal}) Essay:\n" . $previous_essay_text .
                       "\n\n" . $previous_scores_text .
                       "Previous Feedback Summary:\n" . $key_feedback_points .
                       "\n\nCurrent ({$ordinal}) Essay:\n" . $current_essay_data['answer_text'];

        $provider = $this->get_provider();
        if ($provider === 'anthropic') {
            $data = [
                'model' => $this->get_anthropic_model(),
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => [ ['type' => 'text', 'text' => $user_content] ]]
                ],
                'max_tokens' => 5000,
                'temperature' => 0.3
            ];
            $result = $this->make_anthropic_api_call($data, 'generate_comparative_feedback');
        } else {
            $data = [
                'model' => $this->get_openai_model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_content]
                ],
                'max_completion_tokens' => 5000,
                'temperature' => 0.3
            ];
            $result = $this->make_openai_api_call($data, 'generate_comparative_feedback');
        }
        if (!$result['success']) {
            return $result;
    }
        return [
            'success' => true,
            'data' => ['feedback_html' => $result['response']]
        ];
    }
    /**
     * Build complete resubmission feedback HTML
     */
    private function build_resubmission_feedback_html($current_essay_data, $feedback_data, $revision_html, $previous_grading, $submission_number, $progress_commentary_current = '', $current_initial_essay = null) {
        $ordinal = ucfirst($this->get_ordinal_string($submission_number));

        $print_styles = "
        <style>
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
            .section-header {
                color: #2c3e50;
                font-size: 22px;
                margin-bottom: 15px;
                font-weight: 600;
                border-bottom: 2px solid #e9ecef;
                padding-bottom: 10px;
    }
            @media print {
                @page { margin: 0.75in; size: A4; }
                body { font-family: 'Times New Roman', Times, serif; font-size: 10pt; line-height: 1.3; color: #000; background: #fff; }
                .ld-essay-feedback { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; max-width: 100% !important; width: 100% !important; }
                h1, h2, h3, h4, h5, h6 { color: #000 !important; margin-top: 12pt !important; margin-bottom: 6pt !important; page-break-after: avoid; }
                h2 { font-size: 12pt !important; font-weight: bold !important; }
                hr { border: 0 !important; border-top: 1pt solid #000 !important; margin: 6pt 0 !important; page-break-after: avoid; }
                p { font-size: 10pt !important; margin: 4pt 0 !important; text-align: justify; orphans: 2; widows: 2; }
                ul, ol { font-size: 10pt !important; margin: 4pt 0 !important; padding-left: 18pt !important; }
                li { font-size: 10pt !important; margin: 2pt 0 !important; page-break-inside: avoid; }
                .feedback-section { margin-bottom: 10pt !important; page-break-inside: auto; }
                .homework-section { page-break-inside: avoid; margin-top: 12pt !important; }
                .page-break-before { page-break-before: auto; break-before: auto; }
                .no-page-break { page-break-inside: avoid; }
                .screen-only { display: none !important; }
            }
        </style>";

        $html = $print_styles;
        $html .= '<div class="ld-essay-feedback">';

        // Header section with enhanced styling (consistent with first submission)
        $html .= '<div class="feedback-section no-page-break">';
        $html .= '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white !important; padding: 20px; border-radius: 8px; margin: -25px -25px 20px -25px;">';
        $html .= '<h1 style="margin: 0; font-size: 24px; font-weight: 300; color: white !important;">Essay Resubmission Feedback Report</h1>';
        $html .= '</div>';
        $html .= '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 2px solid #28a745;">';
        $html .= '<strong style="color: #495057;">Student:</strong><br>' . htmlspecialchars($current_essay_data['user_name']) . ' (ID: ' . $current_essay_data['user_id'] . ')';
        $html .= '</div>';
        $html .= '<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 2px solid #28a745;">';
        $html .= '<strong style="color: #495057;">Submission Date:</strong><br>' . htmlspecialchars($current_essay_data['submission_time']);
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        // Quiz name and question section (consistent styling)
        if (!empty($current_essay_data['question_text'])) {
            $html .= '<div class="feedback-section">';
            $html .= '<h2 class="section-header" style="color: #6f42c1;">' . htmlspecialchars($current_essay_data['quiz_name']) . ' - ' . $ordinal . ' Submission</h2>';
            $html .= '<div style="background: #e3f2fd; border: 1px solid #90caf9; padding: 20px; border-radius: 6px; border-left: 4px solid #2196f3;">';
            $html .= '<div style="font-weight: 600; color: #1565c0; margin-bottom: 10px; font-size: 16px;">Essay Question:</div>';
            $html .= '<div style="font-size: 15px; line-height: 1.7; color: #1565c0;">' . nl2br(htmlspecialchars($current_essay_data['question_text'])) . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="feedback-section">';
            $html .= '<h2 class="section-header" style="color: #6f42c1;">' . htmlspecialchars($current_essay_data['quiz_name']) . ' - ' . $ordinal . ' Submission</h2>';
            $html .= '</div>';
        }

        // Initial Draft for THIS resubmission (if available)
        if (!empty($current_initial_essay)) {
            $html .= '<div class="feedback-section">';
            $html .= '<h2 class="section-header" style="color: #9c27b0;">Initial Draft - ' . $ordinal . ' Submission</h2>';
            $html .= '<hr>';
            // Optional markers if needed later
            $html .= '<!-- EXTRACT_INITIAL_START -->';
            $html .= '<div style="background: #f3e5f5; padding: 20px; border-radius: 8px; border-left: 4px solid #9c27b0; margin: 10px 0;">';
            $clean_initial = method_exists($this, 'sanitize_original_essay_text') ? $this->sanitize_original_essay_text($current_initial_essay) : trim(strip_tags($current_initial_essay));
            $paras = preg_split("/\r\n|\n|\r/", trim($clean_initial));
            foreach ($paras as $p) {
                if (!empty(trim($p))) {
                    $html .= '<p style="margin-bottom: 15px; font-size: 15px; line-height: 1.7; color: #4a148c;">' . htmlspecialchars($p) . '</p>';
                }
            }
            $html .= '</div>';
            $html .= '<!-- EXTRACT_INITIAL_END -->';
            $html .= '<hr>';
            $html .= '</div>';
        }

        // Current essay section - WITH STRATEGIC MARKERS FOR CONSISTENCY
        $html .= '<div class="feedback-section">';
        $html .= '<h2 class="section-header" style="color: #17a2b8;">Current Essay - ' . $ordinal . ' Submission</h2>';
        $html .= '<hr>';
        $html .= '<div style="background: #e8f5f9; padding: 20px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 10px 0;">';
        $answer_text = trim($current_essay_data['answer_text']);
        // If the LLM returned block HTML, keep it as-is to avoid escaping tags like <p>.
        if (preg_match('/<p[^>]*>/i', $answer_text)) {
            $html .= $answer_text;
        } else {
            $paragraphs = preg_split("/\r\n|\n|\r/", $answer_text);
            foreach ($paragraphs as $p) {
                if (!empty(trim($p))) {
                    $html .= '<p style="margin-bottom: 15px; font-size: 15px; line-height: 1.7; color: #0c5460;">' . htmlspecialchars($p) . '</p>';
                }
            }
        }
        $html .= '</div>';
        $html .= '<hr>';
        $html .= '</div>';

        // Your Writing Journey for THIS resubmission (against its own initial draft)
        if (!empty($progress_commentary_current)) {
            $html .= '<div class="feedback-section">';
            $html .= '<h2 class="section-header" style="color: #4caf50;">Your Writing Journey from Initial Draft</h2>';
            $html .= '<hr>';
            $html .= $progress_commentary_current;
            $html .= '<hr>';
            $html .= '</div>';
        }

        // Revision section (with consistent styling)
        if (!empty($revision_html)) {
            $filtered_revision_html = $this->remove_homework_from_html($revision_html);
            if (!empty($filtered_revision_html)) {
                $html .= '<div class="feedback-section page-break-before">';
                $html .= '<h2 style="font-size:16px; color:#003366;">GrowMinds Academy Essay Revision - ' . $ordinal . ' Submission</h2>';
                $html .= '<hr>';
                $html .= $filtered_revision_html;
                $html .= '<hr>';
                $html .= '</div>';
    }
    }
        // Comparative feedback section (with consistent styling)
        $html .= '<div class="feedback-section page-break-before">';
        $html .= '<h2 style="font-size:16px; color:#003366;">GrowMinds Academy Comparative Feedback - ' . $ordinal . ' Submission</h2>';
        $html .= '<hr>';
        // Hide JSON score summary from the visible comparative feedback
        if (method_exists($this, 'hide_scores_json_for_display')) {
            $segment = $this->hide_scores_json_for_display($feedback_data['feedback_html']);
            if (method_exists($this, 'normalize_example_labels_for_display')) {
                $segment = $this->normalize_example_labels_for_display($segment);
            }
            $html .= $segment;
        } else {
            $segment = preg_replace('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', '', $feedback_data['feedback_html']);
            $segment = preg_replace('/(<li[^>]*>\s*)(?:&bull;|•)\s*(Original:|Improved:)/iu', '$1$2', $segment);
            $html .= $segment;
        }
        $html .= '<hr>';
        $html .= '</div>';

        // Previous feedback (for reference) - include ONLY the first submission's
        // Revision and Feedback sections (omit Initial Draft, Original Essay, Journey)
        if (!empty($previous_grading->feedback_html)) {
            $prev = $this->remove_homework_from_html($previous_grading->feedback_html);
            if (!empty($prev)) {
                // Extract ONLY Revision and Feedback sections using markers
                $extract = '';
                
                if (preg_match('/<!--\s*EXTRACT_REVISION_START\s*-->(.*?)<!--\s*EXTRACT_REVISION_END\s*-->/si', $prev, $m1)) {
                    $extract .= '<div class="feedback-section page-break-before">'
                             . '<h2 style="font-size:16px; color:#003366;">First Submission Revision</h2><hr>'
                             . $m1[1] . '<hr></div>';
                }
                if (preg_match('/<!--\s*EXTRACT_FEEDBACK_START\s*-->(.*?)<!--\s*EXTRACT_FEEDBACK_END\s*-->/si', $prev, $m2)) {
                    // Hide JSON if present
                    $fb = method_exists($this, 'hide_scores_json_for_display') ? $this->hide_scores_json_for_display($m2[1]) : preg_replace('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', '', $m2[1]);
                    if (method_exists($this, 'normalize_example_labels_for_display')) {
                        $fb = $this->normalize_example_labels_for_display($fb);
                    }
                    $extract .= '<div class="feedback-section page-break-before">'
                             . '<h2 style="font-size:16px; color:#003366;">First Submission Feedback</h2><hr>'
                             . $fb . '<hr></div>';
                }
                if (!empty($extract)) {
                    $html .= '<hr style="border:0;border-top:3px double #ccc;margin:40px 0;" />';
                    $html .= '<h2>Previous Submission Feedback & Revision</h2>';
                    $html .= '<div style="background:#f9f9f9;border:1px solid #ddd;padding:20px;margin-top:1em;">' . $extract . '</div>';
                }
            }
        }
        $html .= '</div>';
        return $html;
    }
    /**
     * Save resubmission tracking record with scores
     */
    private function save_resubmission_record($current_id, $previous_id, $submission_number, $is_copy, $similarity_percentage, $previous_total = null, $current_total = null) {
        global $DB;

        try {
            error_log("DEBUG: Saving resubmission record - current_id: {$current_id}, previous_id: {$previous_id}, submission_number: {$submission_number}");
            error_log("DEBUG: Scores - previous_total: {$previous_total}, current_total: {$current_total}");

            // Check if record already exists
            $existing = $DB->get_record('local_quizdashboard_resubmissions', ['current_attempt_id' => $current_id]);

            if ($existing) {
                error_log("DEBUG: Updating existing resubmission record");
                $existing->previous_attempt_id = $previous_id;
                $existing->submission_number = $submission_number;
                $existing->is_copy_detected = $is_copy ? 1 : 0;
                $existing->similarity_percentage = $similarity_percentage;
                $existing->previous_total_score = $previous_total;
                $existing->current_total_score = $current_total;
                $existing->timecreated = time(); // Update time

                $result = $DB->update_record('local_quizdashboard_resubmissions', $existing);
                error_log("DEBUG: Resubmission record update result: " . ($result ? 'SUCCESS' : 'FAILED'));
            } else {
                error_log("DEBUG: Creating new resubmission record");
                $record = new \stdClass();
                $record->current_attempt_id = $current_id;
                $record->previous_attempt_id = $previous_id;
                $record->submission_number = $submission_number;
                $record->is_copy_detected = $is_copy ? 1 : 0;
                $record->similarity_percentage = $similarity_percentage;
                $record->previous_total_score = $previous_total;
                $record->current_total_score = $current_total;
                $record->timecreated = time();

                $new_id = $DB->insert_record('local_quizdashboard_resubmissions', $record);
                error_log("DEBUG: Resubmission record insert result - new ID: " . ($new_id ?: 'FAILED'));
            }
            error_log("DEBUG: Resubmission record saved successfully");
            return true;

        } catch (\Exception $e) {
            error_log("DEBUG: Error saving resubmission record: " . $e->getMessage());
            error_log("DEBUG: Error code: " . $e->getCode());
            error_log("DEBUG: Error file: " . $e->getFile() . " line " . $e->getLine());
            throw $e; // Re-throw the exception so we can see the exact error
        }
    }
    
    /**
     * Save resubmission grade to Moodle with calculated total score
     * This method ensures the correct total score is saved instead of trying to extract it from HTML
     */
    protected function save_resubmission_grade_to_moodle(array $essay_data, array $feedback_data, int $calculated_total, int $max_score = 100): void {
        global $DB;
        
        try {
            error_log("DEBUG: save_resubmission_grade_to_moodle called with calculated_total: {$calculated_total}/{$max_score}");
            
            // Use the calculated total score directly
            $score = $calculated_total;
            $max_score = $max_score ?: 100;
            
            // Handle scores array if present
            if (isset($feedback_data['scores']) && is_array($feedback_data['scores'])) {
                $scores_array = $feedback_data['scores'];
            } else {
                $scores_array = [];
            }
            
            error_log("DEBUG: Using calculated score for resubmission: {$score}/{$max_score}");
            
            // Save concise student-facing comment (same as first submission)
            $comment = $this->create_student_feedback($feedback_data['feedback_html'] ?? '', $essay_data['attempt_id']);
            $fraction = $score / $max_score;
            
            // Use parent's quiz_manager to save the grade
            if (property_exists($this, 'quiz_manager') && $this->quiz_manager) {
                $success = $this->quiz_manager->save_comment_and_grade(
                    $essay_data['attempt_id'],
                    $comment,
                    $fraction
                );
                
                if ($success) {
                    error_log("DEBUG: Successfully saved resubmission grade: {$score}/{$max_score} (fraction: {$fraction})");
                    $this->ensure_sumgrades_updated($essay_data['attempt_id'], $score);
                } else {
                    error_log("ERROR: Failed to save resubmission grade for attempt {$essay_data['attempt_id']}");
                }
            }
            
        } catch (\Exception $e) {
            error_log("ERROR: Exception in save_resubmission_grade_to_moodle: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Helper methods
    private function get_ordinal_string($number) {
        $ordinals = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];
        return $ordinals[$number] ?? $number . 'th';
    }
    private function format_previous_scores_for_prompt($scores) {
        $formatted = '';
        foreach ($scores as $key => $data) {
            if ($key !== 'final_score') {
                $title = ucwords(str_replace('_', ' ', $key));
                $formatted .= "- {$title}: {$data['score']}/{$data['max']}\n";
    }
    }
        $formatted .= "- Final Score: {$scores['final_score']['score']}/{$scores['final_score']['max']}";
        return $formatted;
    }
    private function extract_original_essay_from_feedback($feedback_html) {
        // First try to extract using strategic markers (more reliable)
        if (preg_match('/<!-- EXTRACT_ORIGINAL_START -->(.*?)<!-- EXTRACT_ORIGINAL_END -->/s', $feedback_html, $matches)) {
            return trim(strip_tags($matches[1]));
    }
        // Fallback to original method for backward compatibility
        if (preg_match('/<h2[^>]*>.*?Original Essay.*?<\/h2>(.*?)(?=<h2|<hr)/si', $feedback_html, $matches)) {
            return trim(strip_tags($matches[1]));
    }
        return '';
    }
    private function extract_initial_essay_from_feedback($feedback_html) {
        // Extract using strategic markers
        if (preg_match('/<!-- EXTRACT_INITIAL_START -->(.*?)<!-- EXTRACT_INITIAL_END -->/s', $feedback_html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        // Fallback to heading-based extraction
        if (preg_match('/<h2[^>]*>.*?Initial Draft.*?<\/h2>(.*?)(?=<h2|<hr)/si', $feedback_html, $matches)) {
            return trim(strip_tags($matches[1]));
        }
        return '';
    }
    
    private function extract_progress_commentary_from_feedback($feedback_html) {
        // Extract the Your Writing Journey section
        if (preg_match('/<h2[^>]*>.*?Your Writing Journey from Initial Draft.*?<\/h2>.*?<hr>(.*?)<hr>/si', $feedback_html, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
        private function extract_key_feedback_points($feedback_html) {
        $key_points = '';
        $sections = [
            'Content and Ideas' => 'CONTENT_IDEAS',
            'Structure and Organization' => 'STRUCTURE_ORG', 
            'Language Use' => 'LANGUAGE_USE',
            'Creativity and Originality' => 'CREATIVITY_ORIG',
            'Mechanics' => 'MECHANICS'
        ];

        foreach ($sections as $section_title => $marker_name) {
            // First try to extract using strategic markers (more reliable)
            $marker_pattern = "/<!-- EXTRACT_{$marker_name}_START -->(.*?)<!-- EXTRACT_{$marker_name}_END -->/si";
            if (preg_match($marker_pattern, $feedback_html, $marker_matches)) {
                // Extract from marked content
                if (preg_match('/Areas for Improvement:.*?<ul>(.*?)<\/ul>/si', $marker_matches[1], $improvement_matches)) {
                    $improvements = strip_tags($improvement_matches[1]);
                    $key_points .= "{$section_title}: " . trim($improvements) . "\n";
    }
            } else {
                // Fallback to original method for backward compatibility
                if (preg_match("/<h2[^>]*>{$section_title}.*?<\/h2>(.*?)(?=<h2|$)/si", $feedback_html, $matches)) {
                    if (preg_match('/Areas for Improvement:.*?<ul>(.*?)<\/ul>/si', $matches[1], $improvement_matches)) {
                        $improvements = strip_tags($improvement_matches[1]);
                        $key_points .= "{$section_title}: " . trim($improvements) . "\n";
    }
    }
    }
    }
        return $key_points;
    }
    /**
     * Remove homework content from HTML using strategic markers (most reliable)
     */
    private function remove_homework_from_html($html) {
        if (empty($html)) {
            return $html;
    }
        // First try to remove using strategic markers (most reliable method)
        $clean_html = preg_replace('/<!-- EXTRACT_HOMEWORK_START -->.*?<!-- EXTRACT_HOMEWORK_END -->/s', '', $html);

        // If markers worked, return clean result
        if ($clean_html !== $html) {
            return rtrim($clean_html);
    }
        // Fallback: Define patterns to match homework sections for backward compatibility
        $homework_patterns = [
            // Remove div with homework-section class and everything until its closing div
            '/<div[^>]*class="homework-section"[^>]*>.*?<\\/div>\\s*$/s',
            '/<div[^>]*class="homework-section"[^>]*>.*$/s',
            // Remove homework exercises heading and everything after it
            '/<h2[^>]*>(?:Advanced\\s+)?Homework\\s+Exercises<\\/h2>.*$/is',
            '/<h2[^>]*>(?:Advanced\\s+)?Homework\\s+Exercises<\\/h2>.*?<\\/div>\\s*$/s',
            // Remove feedback-section containing homework
            '/<div[^>]*class="feedback-section[^"]*"[^>]*>\\s*<h2[^>]*>(?:Advanced\\s+)?Homework\\s+Exercises<\\/h2>.*?<\\/div>\\s*$/s',
            // Remove homework-appendix sections
            '/<div[^>]*class="homework-appendix"[^>]*>.*?<\\/div>\\s*$/s',
            '/<div[^>]*class="homework-appendix"[^>]*>.*$/s',
            // Remove page-break before homework
            '/<div[^>]*class="page-break"[^>]*><\\/div>\\s*<div[^>]*class="homework-appendix"[^>]*>.*$/s',
            // Fallback: remove anything that looks like homework content
            '/These exercises target specific areas.*$/s',
            '/These challenging exercises target sophisticated.*$/s'
        ];

        foreach ($homework_patterns as $pattern) {
            $clean_html = preg_replace($pattern, '', $clean_html);
    }
        // Clean up any trailing whitespace or empty divs
        $clean_html = rtrim($clean_html);

        // Remove any dangling empty divs at the end
        $clean_html = preg_replace('/<\\/div>\\s*$/', '', $clean_html);

        return $clean_html;
    }
    /**
     * Upload complete resubmission feedback to Google Drive with consistent naming
     */
    protected function upload_to_google_drive($complete_html, $essay_data, $submission_number = null) {
        global $CFG;

        try {
            // Determine submission number if not provided
            if ($submission_number === null) {
                $submission_number = $this->get_submission_number($essay_data['attempt_id']);
    }
            // Generate appropriate suffix for resubmission
            $submission_ordinals = [
                2 => 'second_submission',
                3 => 'third_submission', 
                4 => 'fourth_submission',
                5 => 'fifth_submission'
            ];

            $suffix = $submission_ordinals[$submission_number] ?? "{$submission_number}th_submission";

            error_log("DEBUG: Uploading resubmission to Google Drive with suffix: {$suffix}");

            // Call parent's upload method with the submission suffix
            return parent::upload_to_google_drive($complete_html, $essay_data, $suffix);

        } catch (Exception $e) {
            error_log("Error uploading resubmission to Google Drive: " . $e->getMessage());
            return null;
        }
    }

    // Helper: deterministically set the Final Score (Previous → New) line to avoid AI hallucination
    private function enforce_final_score($html, $prev_total, $new_total) {
        // Debug logging
        error_log("DEBUG: enforce_final_score called with prev_total=$prev_total, new_total=$new_total");

        // Build replacement strong line and wrapped block
        $strong = '<strong>Final Score (Previous → New): ' . (int)$prev_total . '/100 → ' . (int)$new_total . '/100</strong>';
        $wrapped = '<h2 style="font-size:16px;"><p>' . $strong . '</p></h2>';

        // Normalise common arrow entities to a single form to ease matching
        $normalized = str_replace(['&rarr;', '&#8594;', '-&gt;'], '→', $html);

        // Remove any bare numeric Final Score lines (various wrappers)
        $patternsToStrip = [
            // <h2><p><strong>X/100 → Y/100</strong></p></h2>
            '/<h2[^>]*>\s*(?:<p[^>]*>\s*)?<strong>\s*\d+\s*\/\s*100\s*(?:→|&gt;|➡|➔|►)\s*\d+\s*\/\s*100\s*<\/strong>\s*(?:<\/p>)?\s*<\/h2>/siu',
            // <p><strong>X/100 → Y/100</strong></p>
            '/<p[^>]*>\s*<strong>\s*\d+\s*\/\s*100\s*(?:→|&gt;|➡|➔|►)\s*\d+\s*\/\s*100\s*<\/strong>\s*<\/p>/siu',
            // <strong>X/100 → Y/100</strong>
            '/<strong>\s*\d+\s*\/\s*100\s*(?:→|&gt;|➡|➔|►)\s*\d+\s*\/\s*100\s*<\/strong>/siu',
            // Any existing "Final Score (Previous → New): ..." line
            '/<h2[^>]*>\s*(?:<p[^>]*>\s*)?<strong>\s*Final\s+Score\s*\(Previous.*?New\)\s*:.*?<\/strong>\s*(?:<\/p>)?\s*<\/h2>/siu',
            '/<strong>\s*Final\s+Score\s*\(Previous.*?New\)\s*:.*?<\/strong>/siu',
        ];
        foreach ($patternsToStrip as $pat) {
            $normalized = preg_replace($pat, '', $normalized);
        }

        // Inject our deterministic line right after the comparative Overall Comments block
        // Only inject after the FIRST occurrence to avoid touching the attached first submission
        $injected = preg_replace(
            '/(<div[^>]+id=["\']overall-comments["\'][^>]*>.*?<\/div>)/si',
            '$1' . $wrapped,
            $normalized,
            1
        );
        if ($injected !== null && $injected !== $normalized) {
            error_log('DEBUG: enforce_final_score injected after overall-comments');
            return $injected;
        }

        // Fallbacks: try replacing any remaining simple patterns (legacy)
        $html2 = preg_replace('/<p><strong>\s*\d+\/100\s*→\s*\d+\/100\s*<\/strong><\/p>/si', '<p>' . $strong . '</p>', $normalized);
        if ($html2 !== null && $html2 !== $normalized) {
            error_log('DEBUG: enforce_final_score replaced bare score paragraph');
            return $html2;
        }
        $html2 = preg_replace('/<strong>\s*Final\s+Score\s*\(Previous.*?New\)\s*:\s*.*?<\/strong>/si', $strong, $normalized);
        if ($html2 !== null && $html2 !== $normalized) {
            error_log('DEBUG: enforce_final_score replaced existing Final Score line');
            return $html2;
        }

        // Last resort: append at end of block
        error_log('DEBUG: enforce_final_score appended at end');
        return $normalized . $wrapped;
    }

    /**
     * Extract the last X/max pair from a feedback segment (treating it as the NEW score).
     */
    protected function extract_last_score_from_segment($segment, $max) {
        if (empty($segment)) {
            return null;
        }

        // Normalise entities so → or &rarr; are comparable
        $normalized = html_entity_decode($segment, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Prefer explicit Previous → New pattern and capture the NEW score (second number)
        if (preg_match('/(\d+)\s*\/\s*' . $max . '\s*(?:→|->)\s*(\d+)\s*\/\s*' . $max . '/siu', $normalized, $arrowMatch)) {
            return (int)$arrowMatch[2];
        }
        if (preg_match('/→\s*(\d+)\s*\/\s*' . $max . '/siu', $normalized, $justArrow)) {
            return (int)$justArrow[1];
        }

        // Try to narrow to the Score line first for higher accuracy.
        if (preg_match('/<p>\s*<strong>\s*Score[^:]*:\s*<\/strong>\s*(.*?)<\/p>/si', $normalized, $lineMatch)) {
            $line = $lineMatch[1];
            if (preg_match_all('/(\d+)\s*\/\s*' . $max . '/si', $line, $nums) && !empty($nums[1])) {
                return (int) end($nums[1]);
            }
        }

        if (preg_match_all('/(\d+)\s*\/\s*' . $max . '/si', $normalized, $allNums) && !empty($allNums[1])) {
            return (int) end($allNums[1]);
        }

        return null;
    }

    /**
     * Main resubmission processing function
     */
    public function process_resubmission($attempt_id, $level = 'general') {
        global $DB;

        $original_time_limit = ini_get('max_execution_time');
        ini_set('max_execution_time', 900);
        error_log("DEBUG: [Resubmission] Increased PHP max_execution_time from {$original_time_limit} to 900 seconds");

        try {
            error_log("DEBUG: [Resubmission] Start processing attempt {$attempt_id}");

            // 1) Validate this is a resubmission
            $submission_number = $this->get_submission_number($attempt_id);
            if ($submission_number < 2) {
                return ['success' => false, 'message' => 'This is not a resubmission (submission #1).'];
            }

            // 2) Find previous submission and its grading
            $previous_attempt_id = $this->find_immediate_previous_submission($attempt_id);
            if (!$previous_attempt_id) {
                return ['success' => false, 'message' => 'Cannot find previous submission.'];
            }
            $previous_grading = $this->get_grading_result($previous_attempt_id);
            if (!$previous_grading || empty($previous_grading->feedback_html)) {
                return ['success' => false, 'message' => 'Previous submission must be graded first.'];
            }

            // 3) Current essay data
            $current_essay_data = $this->extract_essay_data($attempt_id);
            if (!$current_essay_data) {
                return ['success' => false, 'message' => 'Could not extract current essay data.'];
            }

            // 4) Previous scores for context - this will use DB scores when available
            $previous_scores = $this->extract_previous_scores($previous_grading);

            // 4a) Verify we have valid previous scores
            if (!isset($previous_scores['final_score']) || !isset($previous_scores['final_score']['score'])) {
                error_log("ERROR: Could not extract previous scores properly");
                error_log("DEBUG: Previous grading record: " . json_encode([
                    'attempt_id' => $previous_grading->attempt_id ?? 'unknown',
                    'has_feedback' => !empty($previous_grading->feedback_html),
                    'score_content_ideas' => $previous_grading->score_content_ideas ?? 'null',
                    'score_structure_organization' => $previous_grading->score_structure_organization ?? 'null',
                    'score_language_use' => $previous_grading->score_language_use ?? 'null',
                    'score_creativity_originality' => $previous_grading->score_creativity_originality ?? 'null',
                    'score_mechanics' => $previous_grading->score_mechanics ?? 'null'
                ]));
                return ['success' => false, 'message' => 'Could not extract scores from previous submission. Please check if previous submission was graded properly.'];
            }

            // 5) AI likelihood: use THIS resubmission's Initial Draft (sequencenumber=1) if available
            $ai_likelihood = 'N/A';
            if (!empty($current_essay_data['attempt_id'])) {
                // Reuse parent's method to fetch initial draft for this attempt's usage id
                $initial_for_ai = null;
                try {
                    if (method_exists($this, 'get_initial_essay_submission') && !empty($current_essay_data['attempt_uniqueid'])) {
                        $initial_for_ai = $this->get_initial_essay_submission($current_essay_data['attempt_uniqueid']);
                    }
                } catch (\Throwable $e) {
                    error_log('DEBUG: get_initial_essay_submission failed in resubmission grader: ' . $e->getMessage());
                }
                if ($initial_for_ai) {
                    $ai_likelihood = $this->detect_ai_assistance($initial_for_ai);
                    error_log("[Resubmission] AI detection on THIS submission INITIAL DRAFT - Likelihood: " . $ai_likelihood);
                } else {
                    $ai_likelihood = $this->detect_ai_assistance($current_essay_data['answer_text']);
                    error_log("[Resubmission] AI detection on CURRENT FINAL submission (fallback) - Likelihood: " . $ai_likelihood);
                }
            } else {
                $ai_likelihood = $this->detect_ai_assistance($current_essay_data['answer_text']);
            }

            // 6) Copy detection vs previous revision
            error_log("DEBUG: About to perform similarity check for attempt $attempt_id");
            $similarity_check = $this->detect_revision_copying($current_essay_data, $previous_grading);
            error_log("DEBUG: Similarity check result: " . json_encode($similarity_check));
            if ($similarity_check['is_copy']) {
                error_log("DEBUG: Copy detected! Applying penalty for similarity: " . $similarity_check['percentage'] . "%");
                return $this->handle_copy_penalty(
                    $attempt_id,
                    $previous_attempt_id,
                    $previous_scores,
                    $submission_number,
                    $similarity_check['percentage']
                );
            } else {
                error_log("DEBUG: No copy detected. Similarity: " . $similarity_check['percentage'] . "%");
            }

            // 7) Comparative feedback
            $feedback_result = $this->generate_comparative_feedback(
                $current_essay_data,
                $previous_grading,
                $previous_scores,
                $level,
                $submission_number
            );
            if (!$feedback_result['success']) {
                return $feedback_result;
            }

            // Add strategic markers to improve later parsing
            if (!empty($feedback_result['data']['feedback_html'])) {
                $feedback_result['data']['feedback_html'] = $this->add_strategic_markers_to_feedback($feedback_result['data']['feedback_html']);
            }

            // 8) Revision of the current essay
            $revision_html = $this->generate_essay_revision($current_essay_data['answer_text'], $level, $feedback_result['data']);

            // 8.5) Generate "Your Writing Journey" commentary for THIS resubmission
            $progress_commentary_current = '';
            try {
                if (method_exists($this, 'get_initial_essay_submission') && !empty($current_essay_data['attempt_uniqueid'])) {
                    $current_initial = $this->get_initial_essay_submission($current_essay_data['attempt_uniqueid']);
                    if (!empty($current_initial) && method_exists($this, 'generate_progress_commentary')) {
                        $progress_commentary_current = $this->generate_progress_commentary($current_initial, $current_essay_data['answer_text']);
                    }
                }
            } catch (\Throwable $e) {
                error_log('DEBUG: Progress commentary generation failed for resubmission: ' . $e->getMessage());
            }

            // 9) Build complete HTML (resubmission-flavoured)
            $complete_html = $this->build_resubmission_feedback_html(
                $current_essay_data,
                $feedback_result['data'],
                $revision_html,
                $previous_grading,
                $submission_number,
                $progress_commentary_current,
                isset($current_initial) ? $current_initial : (isset($initial_for_ai) ? $initial_for_ai : null)
            );

            // 10) Extract current (NEW) subcategory scores from comparative feedback
            $current_scores = $this->extract_resubmission_scores($feedback_result['data']['feedback_html'] ?? '');
            $feedback_result['data']['scores'] = $current_scores;

            // Log current scores for debugging
            error_log("DEBUG: Current (new) scores extracted: " . json_encode($current_scores));

            // 10a) Calculate previous total from DB scores (most reliable)
            // The extract_previous_scores already calculates this correctly from DB
            $prev_total = isset($previous_scores['final_score']['score']) ? (int)$previous_scores['final_score']['score'] : 0;

            // Double-check with direct DB query as fallback
            if ($prev_total === 0) {
                $db_total = $this->get_db_total_score($previous_attempt_id);
                if ($db_total !== null) {
                    $prev_total = $db_total;
                    error_log("DEBUG: Used direct DB query for previous total: {$prev_total}");
                }
            }

            // Debug logging to verify we're getting the correct previous total
            error_log("DEBUG: Previous scores breakdown: " . json_encode($previous_scores));
            error_log("DEBUG: Previous total score being used: {$prev_total}/100");
            // Calculate new total from individual scores
            $new_total = (int)($current_scores['content_and_ideas'] ?? 0)
                        + (int)($current_scores['structure_and_organization'] ?? 0)
                        + (int)($current_scores['language_use'] ?? 0)
                        + (int)($current_scores['creativity_and_originality'] ?? 0)
                        + (int)($current_scores['mechanics'] ?? 0);

            error_log("DEBUG: New scores breakdown: Content={$current_scores['content_and_ideas']}, Structure={$current_scores['structure_and_organization']}, Language={$current_scores['language_use']}, Creativity={$current_scores['creativity_and_originality']}, Mechanics={$current_scores['mechanics']}");
            error_log("DEBUG: New total calculated: {$new_total}/100");
            error_log("DEBUG: Score comparison: Previous={$prev_total}/100 → New={$new_total}/100");

            // Patch the comparative feedback block and the already-built complete HTML
            if (!empty($feedback_result['data']['feedback_html'])) {
                $feedback_result['data']['feedback_html'] = $this->enforce_final_score($feedback_result['data']['feedback_html'], $prev_total, $new_total);
            }
            if (!empty($complete_html)) {
                $complete_html = $this->enforce_final_score($complete_html, $prev_total, $new_total);
            }

            // 11) Similarity check before save; enforce settings
            $similarity = $this->detect_revision_copying($current_essay_data, $previous_grading);
            $threshold = (int)(get_config('local_quizdashboard', 'similarity_threshold') ?: 70);
            $autozero = (int)(get_config('local_quizdashboard', 'similarity_autozero') ?? 1);
            if ($similarity['is_copy'] && $autozero) {
                return $this->handle_copy_penalty(
                    $attempt_id,
                    $previous_attempt_id,
                    $previous_scores,
                    $submission_number,
                    $similarity['percentage']
                );
            }

            // 11b) Save grading record including scores and AI likelihood (+ similarity metadata if computed)
            if (!isset($feedback_result['data'])) { $feedback_result['data'] = []; }
            $feedback_result['data']['similarity_percent'] = isset($similarity['percentage']) ? (int)$similarity['percentage'] : null;
            $feedback_result['data']['similarity_flag'] = (!empty($similarity['is_copy']) && !$autozero) ? 1 : 0;
            $this->save_grading_result($attempt_id, $complete_html, $feedback_result['data'], $ai_likelihood, '');

            // 12) Save resubmission tracking with scores
            $this->save_resubmission_record($attempt_id, $previous_attempt_id, $submission_number, !empty($similarity['is_copy']), (float)($similarity['percentage'] ?? 0), $prev_total, $new_total);

            // 13) Save grade to Moodle using the calculated new_total
            // Use the specialized resubmission method that takes the calculated total
            $this->save_resubmission_grade_to_moodle($current_essay_data, $feedback_result['data'], $new_total, 100);

            // 14) Optional Drive upload with submission suffix
            $drive_link = null;
            if ($this->is_google_drive_configured()) {
                $drive_link = $this->upload_to_google_drive($complete_html, $current_essay_data, $submission_number);
            }

            return [
                'success' => true,
                'message' => "Resubmission #{$submission_number} graded successfully.",
                'submission_number' => $submission_number,
                'ai_likelihood' => $ai_likelihood,
                'drive_link' => $drive_link
            ];

        } catch (\Exception $e) {
            error_log('DEBUG: Exception in process_resubmission: ' . $e->getMessage());
            error_log('DEBUG: Exception file: ' . $e->getFile() . ' line ' . $e->getLine());
            return ['success' => false, 'message' => 'A critical error occurred: ' . $e->getMessage()];
        } finally {
            ini_set('max_execution_time', $original_time_limit);
            error_log("DEBUG: [Resubmission] Restored PHP max_execution_time to {$original_time_limit} seconds");
        }
    }

    /**
     * Get total score directly from DB for an attempt
     */
    private function get_db_total_score($attempt_id) {
        global $DB;

        $grading = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attempt_id]);
        if (!$grading) {
            return null;
        }

        $total = 0;
        $fields = ['score_content_ideas', 'score_structure_organization', 'score_language_use',
                   'score_creativity_originality', 'score_mechanics'];

        foreach ($fields as $field) {
            if (isset($grading->$field) && $grading->$field !== null) {
                $total += (int)$grading->$field;
            } else {
                // If any score is missing, return null
                return null;
            }
        }

        error_log("DEBUG: Retrieved DB total score for attempt {$attempt_id}: {$total}/100");
        return $total;
    }

    /**
     * Get submission number in sequence for this user/quiz
     */
    private function get_submission_number($attempt_id) {
        global $DB;

        $current = $DB->get_record('quiz_attempts', ['id' => $attempt_id]);
        if (!$current) return 1;

        $count = $DB->count_records_select(
            'quiz_attempts',
            'userid = ? AND quiz = ? AND timestart <= ? AND state IN (?, ?)',
            [$current->userid, $current->quiz, $current->timestart, 'finished', 'inprogress']
        );

        return max(1, (int)$count);
    }

    /**
     * Find the immediate previous submission (same user/quiz)
     */
    private function find_immediate_previous_submission($attempt_id) {
        global $DB;

        $current = $DB->get_record('quiz_attempts', ['id' => $attempt_id]);
        if (!$current) return null;

        $previous = $DB->get_record_sql(
            "SELECT id FROM {quiz_attempts}
             WHERE userid = ? AND quiz = ? AND timestart < ?
             AND state IN ('finished','inprogress')
             ORDER BY timestart DESC",
            [$current->userid, $current->quiz, $current->timestart]
        );

        return $previous ? $previous->id : null;
    }

    /**
     * Extract structured scores from previous feedback HTML (for context)
     * This method prioritizes DB-stored scores which are most reliable
     */
    private function extract_previous_scores($previous_grading_record) {
        $feedback_html = $previous_grading_record->feedback_html ?? '';
        $scores = [];

        // 1) ALWAYS prefer DB-stored scores if present - these are authoritative
        $db_scores = [
            'content_and_ideas' => ['value' => $previous_grading_record->score_content_ideas ?? null, 'max' => 25],
            'structure_and_organization' => ['value' => $previous_grading_record->score_structure_organization ?? null, 'max' => 25],
            'language_use' => ['value' => $previous_grading_record->score_language_use ?? null, 'max' => 20],
            'creativity_and_originality' => ['value' => $previous_grading_record->score_creativity_originality ?? null, 'max' => 20],
            'mechanics' => ['value' => $previous_grading_record->score_mechanics ?? null, 'max' => 10],
        ];

        // Check what we have from DB
        $has_all_db = true;
        $db_score_count = 0;
        foreach ($db_scores as $key => $info) {
            if ($info['value'] !== null && $info['value'] !== '') {
                $scores[$key] = ['score' => (int)$info['value'], 'max' => $info['max']];
                $db_score_count++;
            } else {
                $has_all_db = false;
            }
        }

        // If we have all scores from DB, calculate total and return immediately
        if ($has_all_db) {
            $total = 0;
            foreach ($scores as $score_data) {
                $total += $score_data['score'];
            }
            $scores['final_score'] = ['score' => $total, 'max' => 100];
            error_log("DEBUG: Successfully extracted all scores from DB for previous submission");
            error_log("DEBUG: Previous DB scores breakdown: Content={$scores['content_and_ideas']['score']}, Structure={$scores['structure_and_organization']['score']}, Language={$scores['language_use']['score']}, Creativity={$scores['creativity_and_originality']['score']}, Mechanics={$scores['mechanics']['score']}");
            error_log("DEBUG: Previous total from DB: {$total}/100");
            return $scores;
        }

        error_log("DEBUG: Only {$db_score_count}/5 scores found in DB, falling back to HTML parsing");

        // 2) Fallback to parsing HTML if DB fields missing
        $sections = [
            'content_and_ideas' => [
                'marker' => 'CONTENT_IDEAS',
                'max' => 25,
                'title_pattern' => 'Content\s+and\s+Ideas',
                'patterns' => [
                    '/Content\s+and\s+Ideas.*?Score[^:]*:\s*(?:<[^>]+>\s*)*(\d+)\s*\/\s*25/si'
                ]
            ],
            'structure_and_organization' => [
                'marker' => 'STRUCTURE_ORG',
                'max' => 25,
                'title_pattern' => 'Structure\s+and\s+Organi[sz]ation',
                'patterns' => [
                    '/Structure\s+and\s+Organi[sz]ation.*?Score[^:]*:\s*(?:<[^>]+>\s*)*(\d+)\s*\/\s*25/si'
                ]
            ],
            'language_use' => [
                'marker' => 'LANGUAGE_USE',
                'max' => 20,
                'title_pattern' => 'Language\s+Use',
                'patterns' => [
                    '/Language\s+Use.*?Score[^:]*:\s*(?:<[^>]+>\s*)*(\d+)\s*\/\s*20/si'
                ]
            ],
            'creativity_and_originality' => [
                'marker' => 'CREATIVITY_ORIG',
                'max' => 20,
                'title_pattern' => 'Creativity\s+and\s+Originality',
                'patterns' => [
                    '/Creativity\s+and\s+Originality.*?Score[^:]*:\s*(?:<[^>]+>\s*)*(\d+)\s*\/\s*20/si'
                ]
            ],
            'mechanics' => [
                'marker' => 'MECHANICS',
                'max' => 10,
                'title_pattern' => 'Mechanics',
                'patterns' => [
                    '/Mechanics.*?Score[^:]*:\s*(?:<[^>]+>\s*)*(\d+)\s*\/\s*10/si'
                ]
            ],
        ];

        foreach ($sections as $key => $cfg) {
            $max = (int) $cfg['max'];

            // If we already have a DB score, keep it.
            if (isset($scores[$key])) {
                if (!isset($scores[$key]['max'])) {
                    $scores[$key]['max'] = $max;
                }
                continue;
            }

            $value = null;

            if (!empty($cfg['marker'])) {
                $marker_pattern = "/<!-- EXTRACT_{$cfg['marker']}_START -->(.*?)<!-- EXTRACT_{$cfg['marker']}_END -->/si";
                if (preg_match($marker_pattern, $feedback_html, $marker_matches)) {
                    $segment = trim($marker_matches[1]);
                    $value = $this->extract_last_score_from_segment($segment, $max);
                }
            }

            if ($value === null && !empty($cfg['title_pattern'])) {
                if (preg_match('/<h2[^>]*>.*?' . $cfg['title_pattern'] . '.*?<\/h2>(.*?)(?=<h2|$)/si', $feedback_html, $sec)) {
                    $value = $this->extract_last_score_from_segment($sec[1], $max);
                }
            }

            if ($value === null && !empty($cfg['patterns'])) {
                foreach ($cfg['patterns'] as $pattern) {
                    if (preg_match($pattern, $feedback_html, $m)) {
                        $value = (int) $m[1];
                        break;
                    }
                }
            }

            if ($value === null) {
                $value = 0;
            }

            $scores[$key] = ['score' => (int) $value, 'max' => $max];
        }

        // Determine final score (prefer HTML explicit value)
        if (preg_match('/<!-- EXTRACT_FINAL_SCORE_START -->(.*?)<!-- EXTRACT_FINAL_SCORE_END -->/si', $feedback_html, $final_marker_matches)) {
            $final = $final_marker_matches[1];
            if (preg_match('/Final Score \(Previous.*?→.*?New\):.*?\d+\s*\/\s*100\s*→\s*(\d+)\s*\/\s*100/si', $final, $m)) {
                $scores['final_score'] = ['score' => (int)$m[1], 'max' => 100];
            } elseif (preg_match('/Final Score:.*?(\d+)\s*\/\s*100/si', $final, $m)) {
                $scores['final_score'] = ['score' => (int)$m[1], 'max' => 100];
            }
        }
        if (!isset($scores['final_score'])) {
            if (preg_match('/Final Score \(Previous.*?→.*?New\):.*?\d+\s*\/\s*100\s*→\s*(\d+)\s*\/\s*100/si', $feedback_html, $m)) {
                $scores['final_score'] = ['score' => (int)$m[1], 'max' => 100];
            } elseif (preg_match('/Final Score:.*?(\d+)\s*\/\s*100/si', $feedback_html, $m)) {
                $scores['final_score'] = ['score' => (int)$m[1], 'max' => 100];
            } else {
                // Sum parts as last resort (after HTML parsing above)
                $parts_total = 0; foreach (['content_and_ideas','structure_and_organization','language_use','creativity_and_originality','mechanics'] as $k) { $parts_total += (int)($scores[$k]['score'] ?? 0); }
                $scores['final_score'] = ['score' => $parts_total, 'max' => 100];
            }
        }

        return $scores;
    }

    /**
     * Extract current (NEW) scores from comparative feedback HTML
     */
    private function extract_resubmission_scores($feedback_html) {
        $scores = [];
        if (empty($feedback_html)) {
            return $scores;
        }

        // Try JSON extraction first (preferred method)
        $json_scores = $this->extract_scores_from_json($feedback_html);
        if ($json_scores !== null) {
            error_log("DEBUG: Using JSON scores for resubmission extraction");
            return [
                'content_and_ideas' => $json_scores['content_and_ideas'] ?? null,
                'structure_and_organization' => $json_scores['structure_and_organization'] ?? null,
                'language_use' => $json_scores['language_use'] ?? null,
                'creativity_and_originality' => $json_scores['creativity_and_originality'] ?? null,
                'mechanics' => $json_scores['mechanics'] ?? null
            ];
        }

        error_log("DEBUG: JSON extraction failed for resubmission, falling back to regex parsing");

        // Fallback to regex parsing
        $sections = [
            'content_and_ideas' => ['max' => 25, 'title' => 'Content and Ideas'],
            'structure_and_organization' => ['max' => 25, 'title' => 'Structure\\s+and\\s+Organi[sz]ation'],
            'language_use' => ['max' => 20, 'title' => 'Language Use'],
            'creativity_and_originality' => ['max' => 20, 'title' => 'Creativity and Originality'],
            'mechanics' => ['max' => 10, 'title' => 'Mechanics']
        ];

        foreach ($sections as $key => $cfg) {
            $max = (int)$cfg['max'];
            $title = $cfg['title'];

            // Try multiple patterns to extract the NEW score (after the arrow)
            // We need to handle various arrow encodings and formats
            $patterns = [
                // Pattern 1: Look for "X/max → Y/max" and capture Y
                "/{$title}.*?Score.*?\\d+\\s*\\/\\s*{$max}\\s*(?:→|->|➔|►|&rarr;|&gt;)\\s*(\\d+)\\s*\\/\\s*{$max}/si",
                // Pattern 2: Look for just "→ Y/max" and capture Y
                "/{$title}.*?Score.*?(?:→|->|➔|►|&rarr;|&gt;)\\s*(\\d+)\\s*\\/\\s*{$max}/si",
                // Pattern 3: If no arrow found, look for the LAST score in the section (which should be the NEW score)
                "/{$title}.*?Score.*?(\\d+)\\s*\\/\\s*{$max}(?!.*\\d+\\s*\\/\\s*{$max})/si",
                // Pattern 4: Simple fallback - just find any score for this section
                "/{$title}.*?Score.*?(\\d+)\\s*\\/\\s*{$max}/si"
            ];

            $found = false;
            $value = null;

            // Try each pattern
            foreach ($patterns as $pattern_idx => $pattern) {
                if (preg_match($pattern, $feedback_html, $matches)) {
                    // For patterns looking for the last occurrence, we need to find all matches
                    if ($pattern_idx == 2 || $pattern_idx == 3) {
                        if (preg_match_all("/\\d+\\s*\\/\\s*{$max}/", $matches[0], $all_scores)) {
                            // Get the last score
                            $last_score = end($all_scores[0]);
                            if (preg_match('/(\\d+)/', $last_score, $score_match)) {
                                $value = (int)$score_match[1];
                                error_log("DEBUG: Extracted {$key} NEW score using pattern {$pattern_idx}: {$value}/{$max}");
                                $found = true;
                                break;
                            }
                        }
                    } else {
                        $value = (int)$matches[1];
                        error_log("DEBUG: Extracted {$key} NEW score using pattern {$pattern_idx}: {$value}/{$max}");
                        $found = true;
                        break;
                    }
                }
            }

            if ($found && $value !== null) {
                $scores[$key] = $value;
            } else {
                error_log("WARNING: Could not extract NEW score for {$key}");
                $scores[$key] = 0;
            }
        }

        return $scores;
    }

    /**
     * Detect if current submission is a copy of previous revision
     */
    private function detect_revision_copying($current_essay_data, $previous_grading) {
        error_log("DEBUG: detect_revision_copying called at " . date('Y-m-d H:i:s'));
        error_log("DEBUG: File loaded from: " . __FILE__);
        
        $previous_revision_text = $this->extract_revision_text_from_html($previous_grading->feedback_html);
        $current_text = trim($current_essay_data['answer_text'] ?? '');

        error_log("DEBUG: Previous revision text length: " . strlen($previous_revision_text));
        error_log("DEBUG: Current text length: " . strlen($current_text));
        error_log("DEBUG: First 100 chars of current: " . substr($current_text, 0, 100));
        error_log("DEBUG: First 100 chars of previous: " . substr($previous_revision_text, 0, 100));

        if (empty($previous_revision_text) || empty($current_text)) {
            error_log("DEBUG: Empty text detected, returning no copy");
            return ['is_copy' => false, 'percentage' => 0];
        }

        $normalized_current = preg_replace('/\s+/', ' ', $current_text);
        $normalized_previous = preg_replace('/\s+/', ' ', $previous_revision_text);

        similar_text($normalized_current, $normalized_previous, $similarity_percent);
        error_log("DEBUG: Similarity percentage calculated: " . $similarity_percent . "%");

        $threshold = (int)(get_config('local_quizdashboard', 'similarity_threshold') ?: 70);
        error_log("DEBUG: Similarity threshold from config: " . $threshold . "%");
        
        $is_violation = ($similarity_percent >= $threshold);
        error_log("DEBUG: Is violation? " . ($is_violation ? 'YES' : 'NO'));
        
        return [
            'is_copy' => $is_violation,
            'percentage' => round($similarity_percent, 2)
        ];
    }

    /**
     * Extract revision text from HTML feedback
     */
    private function extract_revision_text_from_html($feedback_html) {
        if (preg_match('/<!-- EXTRACT_REVISION_START -->(.*?)<!-- EXTRACT_REVISION_END -->/s', $feedback_html, $matches)) {
            $revision_html = $matches[1];
            $revision_html = preg_replace('/<del[^>]*>.*?<\\/del>/si', '', $revision_html);
            $revision_html = preg_replace('/\[\*(.*?)\*\]/s', '$1', $revision_html);
            return trim(html_entity_decode(strip_tags($revision_html)));
        }
        if (preg_match('/<h2[^>]*>.*?Essay Revision.*?<\\/h2>(.*?)(?=<h2|$)/si', $feedback_html, $matches)) {
            $revision_html = $matches[1];
            $revision_html = preg_replace('/<del[^>]*>.*?<\\/del>/si', '', $revision_html);
            $revision_html = preg_replace('/\[\*(.*?)\*\]/s', '$1', $revision_html);
            return trim(html_entity_decode(strip_tags($revision_html)));
        }
        return '';
    }

    /**
     * Generate penalty feedback for copied submissions
     */
    private function generate_penalty_feedback($previous_scores, $submission_number) {
        $ordinal = $this->get_ordinal_string($submission_number);
        $penalty_message = "This {$ordinal} submission was identified as a copy of the revision from the previous feedback. Submitting work that is not your own does not demonstrate learning or effort.";

        $sections = [
            'content_and_ideas' => ['title' => '1. Content and Ideas (25%)', 'max' => 25],
            'structure_and_organization' => ['title' => '2. Structure and Organization (25%)', 'max' => 25],
            'language_use' => ['title' => '3. Language Use (20%)', 'max' => 20],
            'creativity_and_originality' => ['title' => '4. Creativity and Originality (20%)', 'max' => 20],
            'mechanics' => ['title' => '5. Mechanics (10%)', 'max' => 10]
        ];

        $html = '';
        $previous_total = 0;
        foreach ($sections as $key => $cfg) {
            $prev_score = (int)($previous_scores[$key]['score'] ?? 0);
            $previous_total += $prev_score;
            $html .= '<h2 style="font-size:18px;">' . $cfg['title'] . '</h2>';
            $html .= '<p><strong>Score (Previous → New):</strong> ' . $prev_score . '/' . $cfg['max'] . ' → 0/' . $cfg['max'] . '</p>';
            $html .= '<ul>';
            $html .= '<li><strong>Analysis of Changes:</strong> ' . $penalty_message . '</li>';
            $html .= '<li><strong>Areas for Improvement:</strong><ul><li>Please revise the essay using your own ideas and words, incorporating the feedback provided. Focus on genuine improvement rather than copying.</li></ul></li>';
            $html .= '</ul>';
        }

        $html .= '<h2 style="font-size:18px;">Overall Comments</h2>';
        $html .= '<div id="overall-comments"><p><strong>' . $penalty_message . ' We encourage you to try again with your own improvements.</strong></p></div>';
        $html .= '<h2 style="font-size:16px;"><p><strong>Final Score (Previous → New): ' . $previous_total . '/100 → 0/100</strong></p></h2>';

        return $html;
    }

    /**
     * Handle copy penalty scenario
     */
    private function handle_copy_penalty($attempt_id, $previous_attempt_id, $previous_scores, $submission_number, $similarity_percentage) {
        $current_essay_data = $this->extract_essay_data($attempt_id);
        $ai_likelihood = null;
        if ($current_essay_data && !empty($current_essay_data['answer_text'])) {
            $ai_likelihood = $this->detect_ai_assistance($current_essay_data['answer_text']);
        }

        $penalty_feedback_html = $this->generate_penalty_feedback($previous_scores, $submission_number);
        // Prepend similarity warning banner using configured text
        $warn = get_config('local_quizdashboard', 'similarity_warning_text');
        if (empty($warn)) {
            $warn = 'Similarity violation detected. All category scores and the final score have been set to 0.';
        }
        $banner = '<div style="background:#fdecea;border-left:6px solid #d93025;padding:12px 16px;margin-bottom:16px;">'
                . '<strong>Similarity Violation (' . (int)$similarity_percentage . '%)</strong><br>'
                . htmlspecialchars($warn) . '</div>';
        $penalty_feedback_html = $banner . $penalty_feedback_html;

        // Save result with similarity metadata
        $this->save_grading_result(
            $attempt_id,
            $penalty_feedback_html,
            [
                'feedback_html' => $penalty_feedback_html,
                'scores' => [
                'content_and_ideas' => 0,
                'structure_and_organization' => 0,
                'language_use' => 0,
                'creativity_and_originality' => 0,
                'mechanics' => 0
                ],
                'similarity_percent' => (int)$similarity_percentage,
                'similarity_flag' => 1
            ],
            $ai_likelihood,
            ''
        );

        $this->save_resubmission_record($attempt_id, $previous_attempt_id, $submission_number, true, $similarity_percentage);

        // CRITICAL FIX: Save the zero score to Moodle's grading system
        // Without this, students cannot see their penalty score in the quiz review
        if ($current_essay_data) {
            $this->save_resubmission_grade_to_moodle(
                $current_essay_data, 
                ['feedback_html' => $penalty_feedback_html, 'scores' => [
                    'content_and_ideas' => 0,
                    'structure_and_organization' => 0,
                    'language_use' => 0,
                    'creativity_and_originality' => 0,
                    'mechanics' => 0
                ]], 
                0, // Zero total score for penalty
                100
            );
            error_log("DEBUG: Zero score saved to Moodle for similarity violation in attempt {$attempt_id}");
        }

        return [
            'success' => true,
            'message' => "Copy detected and penalty applied for submission #{$submission_number}.",
            'is_penalty' => true,
            'similarity_percentage' => $similarity_percentage,
            'ai_likelihood' => $ai_likelihood
        ];
    }
}
