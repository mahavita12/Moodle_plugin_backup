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
- Be stringent but fair - genuine improvement should be rewarded, minimal changes should not
- If they simply copied the revision from the previous feedback, this should result in zero scores

**SCORING APPROACH**:
Previous scores from {$prev_ordinal} submission:
" . $this->format_previous_scores_for_prompt($previous_scores) . "

- Show improvement as: Previous Score → New Score  
- Only increase scores where there is genuine, measurable improvement
- Maintain or decrease scores where improvement is minimal or absent
- Use Australian English for all feedback

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

**OUTPUT STRUCTURE**: You must follow this exact HTML format:

<h2 style=\"font-size:18px;\">1. Content and Ideas (25%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/25 → [NEW_SCORE]/25</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide at least three specific examples with clear improvement suggestions in blue color.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">2. Structure and Organization (25%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/25 → [NEW_SCORE]/25</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide at least three specific examples with clear improvement suggestions in blue color.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">3. Language Use (20%)</h2>
<p><strong>Score (Previous → New):</strong> [PREVIOUS_SCORE]/20 → [NEW_SCORE]/20</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\">
<li><span style=\"color:#3399cc;\">- Provide multiple clear and relevant examples showing original and improved versions. ALWAYS format as:
<br> <span style=\"color:#808080;\">Original: [student's text in grey]</span>
<br> <span style=\"color:#3399cc;\">Improved: [corrected version in blue]</span>
NON-NEGOTIABLE REQUIREMENT: The word 'Original:' and all student text following it MUST be in grey color (#808080). Use separate lines for each original and improved pair.</span></li>
<li><span style=\"color:#3399cc;\">- At least five examples must be included showing the original and improved version separately on different lines, but do not mention quantity in the output.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">4. Creativity and Originality (20%)</h2>
<p><strong>Score (Previous  New):</strong> [PREVIOUS_SCORE]/20  [NEW_SCORE]/20</p>
<ul>
<li><strong>Analysis of Changes:</strong> [How the student addressed previous feedback for this criterion]</li>
<li><strong>Areas for Improvement:</strong><ul><li>[Specific areas still needing work]</li></ul></li>
<li><strong>Examples:</strong><ul style=\"list-style-type:disc; padding-left:20px; margin-top:10px;\"><li><span style=\"color:#3399cc;\">Provide at least four specific examples with clear improvement suggestions in blue color.</span></li></ul></li>
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
<li><span style=\"color:#3399cc;\">- Include up to 10 examples showing the original and improved version separately on different lines, but do not mention the limit in the output.</span></li></ul></li>
</ul>

<h2 style=\"font-size:18px;\">Overall Comments</h2>
<div id=\"overall-comments\"><p>[Summary of progress and encouragement]</p></div>

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
                'max_tokens' => 3500
            ];
            $result = $this->make_anthropic_api_call($data, 'generate_comparative_feedback');
        } else {
            $data = [
                'model' => $this->get_openai_model(),
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_content]
                ],
                'max_completion_tokens' => 3500
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
    private function build_resubmission_feedback_html($current_essay_data, $feedback_data, $revision_html, $previous_grading, $submission_number) {
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

        // Current essay section - WITH STRATEGIC MARKERS FOR CONSISTENCY
        $html .= '<div class="feedback-section">';
        $html .= '<h2 class="section-header" style="color: #17a2b8;">Current Essay - ' . $ordinal . ' Submission</h2>';
        $html .= '<hr>';
        $html .= '<div style="background: #e8f5f9; padding: 20px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 10px 0;">';
        $paragraphs = preg_split("/\r\n|\n|\r/", trim($current_essay_data['answer_text']));
        foreach ($paragraphs as $p) {
            if (!empty(trim($p))) {
                $html .= '<p style="margin-bottom: 15px; font-size: 15px; line-height: 1.7; color: #0c5460;">' . htmlspecialchars($p) . '</p>';
            }
        }
        $html .= '</div>';
        $html .= '<hr>';
        $html .= '</div>';

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
            $html .= $this->hide_scores_json_for_display($feedback_data['feedback_html']);
        } else {
            $html .= preg_replace('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', '', $feedback_data['feedback_html']);
        }
        $html .= '<hr>';
        $html .= '</div>';

        // Previous feedback (for reference) - include ONLY the first submission's
        // Revision and Feedback sections. Strip student header, question and original essay.
        if (!empty($previous_grading->feedback_html)) {
            $prev = $this->remove_homework_from_html($previous_grading->feedback_html);
            if (!empty($prev)) {
                // Extract only the revision and feedback sections using markers
                $extract = '';
                if (preg_match('/<!--\s*EXTRACT_REVISION_START\s*-->(.*?)<!--\s*EXTRACT_REVISION_END\s*-->/si', $prev, $m1)) {
                    $extract .= '<div class="feedback-section page-break-before">'
                             . '<h2 style="font-size:16px; color:#003366;">First Submission Revision</h2><hr>'
                             . $m1[1] . '<hr></div>';
                }
                if (preg_match('/<!--\s*EXTRACT_FEEDBACK_START\s*-->(.*?)<!--\s*EXTRACT_FEEDBACK_END\s*-->/si', $prev, $m2)) {
                    // Hide JSON if present
                    $fb = method_exists($this, 'hide_scores_json_for_display') ? $this->hide_scores_json_for_display($m2[1]) : preg_replace('/<!--\s*SCORES_JSON_START\s*-->.*?<!--\s*SCORES_JSON_END\s*-->/s', '', $m2[1]);
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
     * Save resubmission tracking record
     */
    private function save_resubmission_record($current_id, $previous_id, $submission_number, $is_copy, $similarity_percentage) {
        global $DB;

        try {
            error_log("DEBUG: Saving resubmission record - current_id: {$current_id}, previous_id: {$previous_id}, submission_number: {$submission_number}");

            // Check if record already exists
            $existing = $DB->get_record('local_quizdashboard_resubmissions', ['current_attempt_id' => $current_id]);

            if ($existing) {
                error_log("DEBUG: Updating existing resubmission record");
                $existing->previous_attempt_id = $previous_id;
                $existing->submission_number = $submission_number;
                $existing->is_copy_detected = $is_copy ? 1 : 0;
                $existing->similarity_percentage = $similarity_percentage;
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
        $replacement = '<strong>Final Score (Previous → New): ' . (int)$prev_total . '/100 → ' . (int)$new_total . '/100</strong>';
        // Replace common pattern where the whole strong tag is on one line
        $html2 = preg_replace('/<strong>\s*Final\s+Score\s*\(Previous.*?New\)\s*:\s*.*?<\/strong>/si', $replacement, $html);
        if ($html2 !== null && $html2 !== $html) {
            return $html2;
        }
        // Fallback: try to replace just the numeric part inside the existing strong tag
        $html2 = preg_replace('/(Final\s+Score\s*\(Previous.*?New\)\s*:\s*).*?(?=<\/strong>)/si', '$1' . (int)$prev_total . '/100 → ' . (int)$new_total . '/100', $html);
        if ($html2 !== null) {
            return $html2;
        }
        return $html;
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

            // 4) Previous scores for context
            $previous_scores = $this->extract_previous_scores($previous_grading);

            // 5) AI likelihood
            $ai_likelihood = $this->detect_ai_assistance($current_essay_data['answer_text']);

            // 6) Copy detection vs previous revision
            $similarity_check = $this->detect_revision_copying($current_essay_data, $previous_grading);
            if ($similarity_check['is_copy']) {
                return $this->handle_copy_penalty(
                    $attempt_id,
                    $previous_attempt_id,
                    $previous_scores,
                    $submission_number,
                    $similarity_check['percentage']
                );
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

            // 9) Build complete HTML (resubmission-flavoured)
            $complete_html = $this->build_resubmission_feedback_html($current_essay_data, $feedback_result['data'], $revision_html, $previous_grading, $submission_number);

            // 10) Extract current (NEW) subcategory scores from comparative feedback
            $current_scores = $this->extract_resubmission_scores($feedback_result['data']['feedback_html'] ?? '');
            $feedback_result['data']['scores'] = $current_scores;

            // 10a) Enforce FINAL score line using deterministic values to avoid AI hallucination
            $prev_total = isset($previous_scores['final_score']['score']) ? (int)$previous_scores['final_score']['score'] : (
                (int)($previous_scores['content_and_ideas']['score'] ?? 0)
                + (int)($previous_scores['structure_and_organization']['score'] ?? 0)
                + (int)($previous_scores['language_use']['score'] ?? 0)
                + (int)($previous_scores['creativity_and_originality']['score'] ?? 0)
                + (int)($previous_scores['mechanics']['score'] ?? 0)
            );
            $new_total = (int)($current_scores['content_and_ideas'] ?? 0)
                        + (int)($current_scores['structure_and_organization'] ?? 0)
                        + (int)($current_scores['language_use'] ?? 0)
                        + (int)($current_scores['creativity_and_originality'] ?? 0)
                        + (int)($current_scores['mechanics'] ?? 0);

            // Patch the comparative feedback block and the already-built complete HTML
            if (!empty($feedback_result['data']['feedback_html'])) {
                $feedback_result['data']['feedback_html'] = $this->enforce_final_score($feedback_result['data']['feedback_html'], $prev_total, $new_total);
            }
            if (!empty($complete_html)) {
                $complete_html = $this->enforce_final_score($complete_html, $prev_total, $new_total);
            }

            // 11) Save grading record including scores and AI likelihood
            $this->save_grading_result($attempt_id, $complete_html, $feedback_result['data'], $ai_likelihood, '');

            // 12) Save resubmission tracking
            $this->save_resubmission_record($attempt_id, $previous_attempt_id, $submission_number, false, null);

            // 13) Save grade to Moodle
            $this->save_grade_to_moodle($current_essay_data, $feedback_result['data']);

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
     */
    private function extract_previous_scores($previous_grading_record) {
        $feedback_html = $previous_grading_record->feedback_html ?? '';
        $scores = [];

        // 1) Prefer DB-stored scores if present, but do NOT return early
        $db_scores = [
            'content_and_ideas' => ['value' => $previous_grading_record->score_content_ideas ?? null, 'max' => 25],
            'structure_and_organization' => ['value' => $previous_grading_record->score_structure_organization ?? null, 'max' => 25],
            'language_use' => ['value' => $previous_grading_record->score_language_use ?? null, 'max' => 20],
            'creativity_and_originality' => ['value' => $previous_grading_record->score_creativity_originality ?? null, 'max' => 20],
            'mechanics' => ['value' => $previous_grading_record->score_mechanics ?? null, 'max' => 10],
        ];
        $has_any_db = false;
        foreach ($db_scores as $key => $info) {
            if ($info['value'] !== null && $info['value'] !== '') {
                $scores[$key] = ['score' => (int)$info['value'], 'max' => $info['max']];
                $has_any_db = true;
            }
        }

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
            // Title may already contain regex (for Organisation/Organization)
            $title = $cfg['title'];
            $patterns = [
                "/{$title}.*?Score \(Previous.*?→.*?New\):.*?\\d+\\s*\\/\\s*{$max}\\s*→\\s*(\\d+)\\s*\\/\\s*{$max}/si",
                "/{$title}.*?Score:.*?(\\d+)\\s*\\/\\s*{$max}/si"
            ];
            $found = false;
            foreach ($patterns as $p) {
                if (preg_match($p, $feedback_html, $m)) {
                    $scores[$key] = (int)$m[1];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $scores[$key] = null;
            }
        }

        return $scores;
    }

    /**
     * Detect if current submission is a copy of previous revision
     */
    private function detect_revision_copying($current_essay_data, $previous_grading) {
        $previous_revision_text = $this->extract_revision_text_from_html($previous_grading->feedback_html);
        $current_text = trim($current_essay_data['answer_text'] ?? '');

        if (empty($previous_revision_text) || empty($current_text)) {
            return ['is_copy' => false, 'percentage' => 0];
        }

        $normalized_current = preg_replace('/\s+/', ' ', $current_text);
        $normalized_previous = preg_replace('/\s+/', ' ', $previous_revision_text);

        similar_text($normalized_current, $normalized_previous, $similarity_percent);
        return [
            'is_copy' => $similarity_percent > 80.0,
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

        $this->save_grading_result(
            $attempt_id,
            $penalty_feedback_html,
            ['feedback_html' => $penalty_feedback_html, 'scores' => [
                'content_and_ideas' => 0,
                'structure_and_organization' => 0,
                'language_use' => 0,
                'creativity_and_originality' => 0,
                'mechanics' => 0
            ]],
            $ai_likelihood,
            ''
        );

        $this->save_resubmission_record($attempt_id, $previous_attempt_id, $submission_number, true, $similarity_percentage);

        return [
            'success' => true,
            'message' => "Copy detected and penalty applied for submission #{$submission_number}.",
            'is_penalty' => true,
            'similarity_percentage' => $similarity_percentage,
            'ai_likelihood' => $ai_likelihood
        ];
    }
}
