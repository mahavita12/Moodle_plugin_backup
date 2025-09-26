<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Feedback engine for Essays Master that integrates with Quiz Dashboard AI.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_essaysmaster;

defined('MOODLE_INTERNAL') || die();

// Check if Quiz Dashboard is available
$quizdashboard_available = file_exists($CFG->dirroot . '/local/quizdashboard/classes/essay_grader.php');
if ($quizdashboard_available) {
    require_once($CFG->dirroot . '/local/quizdashboard/classes/essay_grader.php');
}

/**
 * Class feedback_engine
 *
 * Handles AI feedback generation using existing Quiz Dashboard infrastructure
 */
class feedback_engine {

    /** @var \local_quizdashboard\essay_grader Essay grader instance */
    private $essay_grader;

    /** @var array Level-specific prompts */
    private $level_prompts;

    /** @var object Session configuration */
    private $session;

    /**
     * Constructor
     *
     * @param object $session Essays Master session
     */
    public function __construct($session) {
        global $CFG;

        $this->session = $session;

        // Initialize essay grader if Quiz Dashboard is available
        if (class_exists('\local_quizdashboard\essay_grader')) {
            try {
                $this->essay_grader = new \local_quizdashboard\essay_grader();

                // Test if we can actually use the method
                $reflection = new \ReflectionClass($this->essay_grader);
                $method = $reflection->getMethod('generate_essay_feedback');
                if (!$method->isPublic()) {
                    error_log('Essays Master: Quiz Dashboard essay_grader method is not public, using fallback');
                    $this->essay_grader = null;
                }
            } catch (Exception $e) {
                error_log('Essays Master: Cannot initialize Quiz Dashboard essay grader: ' . $e->getMessage());
                $this->essay_grader = null;
            }
        } else {
            $this->essay_grader = null;
        }

        $this->initialize_level_prompts();
    }

    /**
     * Initialize level-specific prompts from configuration
     */
    private function initialize_level_prompts() {
        $this->level_prompts = [
            1 => get_config('local_essaysmaster', 'level1_prompt') ?: $this->get_default_level1_prompt(),
            2 => get_config('local_essaysmaster', 'level2_prompt') ?: $this->get_default_level2_prompt(),
            3 => get_config('local_essaysmaster', 'level3_prompt') ?: $this->get_default_level3_prompt(),
        ];
    }

    /**
     * Get default Level 1 prompt
     *
     * @return string Default prompt for Level 1
     */
    private function get_default_level1_prompt() {
        return 'Focus on basic grammar, spelling, and punctuation errors. Highlight specific mistakes and provide simple corrections. ' .
               'Identify areas where the student can improve basic writing mechanics. Format your response with specific text ' .
               'references using <span class="highlight-target">text to highlight</span> tags around problematic text.';
    }

    /**
     * Get default Level 2 prompt
     *
     * @return string Default prompt for Level 2
     */
    private function get_default_level2_prompt() {
        return 'Analyze language sophistication, word choice, and sentence variety. Suggest more advanced vocabulary and ' .
               'sentence structures. Look for opportunities to improve flow, clarity, and style. Use <span class="highlight-target">text to highlight</span> ' .
               'tags around areas that need vocabulary or structural improvements.';
    }

    /**
     * Get default Level 3 prompt
     *
     * @return string Default prompt for Level 3
     */
    private function get_default_level3_prompt() {
        return 'Evaluate overall structure, argument development, and content depth. Provide high-level organizational and ' .
               'analytical feedback. Assess thesis strength, evidence quality, and logical flow. Highlight structural issues ' .
               'using <span class="highlight-target">text to highlight</span> tags.';
    }

    /**
     * Generate feedback for a specific level
     *
     * @param string $essay_text The essay text to analyze
     * @param int $level The feedback level (1-3)
     * @param string $question_context Optional question context
     * @return array Feedback data including HTML, highlights, and score
     */
    public function generate_level_feedback($essay_text, $level, $question_context = '') {
        $start_time = microtime(true);

        // Validate level
        if (!isset($this->level_prompts[$level])) {
            throw new \invalid_parameter_exception('Invalid feedback level: ' . $level);
        }

        // Build the complete prompt
        $prompt = $this->build_complete_prompt($essay_text, $level, $question_context);

        try {
            // Generate feedback using Quiz Dashboard's essay grader or fallback
            if ($this->essay_grader && method_exists($this->essay_grader, 'generate_essay_feedback')) {
                try {
                    $feedback_result = $this->essay_grader->generate_essay_feedback($essay_text, $prompt);
                } catch (Exception $e) {
                    // If Quiz Dashboard method fails, use fallback
                    error_log('Essays Master: Quiz Dashboard method failed, using fallback: ' . $e->getMessage());
                    $feedback_result = $this->generate_fallback_feedback($essay_text, $level, $prompt);
                }
            } else {
                // Fallback to simulated feedback when Quiz Dashboard is not available
                $feedback_result = $this->generate_fallback_feedback($essay_text, $level, $prompt);
            }

            // Process the feedback
            $processed_feedback = $this->process_feedback($feedback_result, $essay_text, $level);

            // Calculate API response time
            $response_time = microtime(true) - $start_time;

            // Log the interaction if enabled
            if (get_config('local_essaysmaster', 'log_ai_interactions')) {
                $this->log_ai_interaction($level, $essay_text, $processed_feedback, $response_time);
            }

            return [
                'success' => true,
                'feedback_html' => $processed_feedback['html'],
                'highlighted_areas' => $processed_feedback['highlights'],
                'completion_score' => $processed_feedback['score'],
                'level' => $level,
                'response_time' => $response_time,
                'word_count' => str_word_count($essay_text),
                'character_count' => strlen($essay_text),
            ];

        } catch (\Exception $e) {
            // Handle API errors gracefully
            error_log('Essays Master AI Error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'Failed to generate AI feedback: ' . $e->getMessage(),
                'level' => $level,
                'response_time' => microtime(true) - $start_time,
            ];
        }
    }

    /**
     * Build complete prompt including level-specific instructions
     *
     * @param string $essay_text The essay text
     * @param int $level The feedback level
     * @param string $question_context Question context
     * @return string Complete prompt
     */
    private function build_complete_prompt($essay_text, $level, $question_context = '') {
        $base_prompt = $this->level_prompts[$level];

        $complete_prompt = "You are providing Level {$level} feedback for a student essay as part of a multi-level revision process.\n\n";

        if (!empty($question_context)) {
            $complete_prompt .= "Question/Prompt Context:\n{$question_context}\n\n";
        }

        $complete_prompt .= "Level {$level} Instructions:\n{$base_prompt}\n\n";

        $complete_prompt .= "IMPORTANT: Your feedback should help the student achieve at least 80% completion for this level. ";
        $complete_prompt .= "Provide specific, actionable suggestions that can be measured. ";
        $complete_prompt .= "Use <span class=\"highlight-target\">text to highlight</span> tags around specific text that needs attention.\n\n";

        $complete_prompt .= "Student Essay:\n{$essay_text}\n\n";

        $complete_prompt .= "Provide comprehensive Level {$level} feedback with specific highlights and actionable suggestions.";

        return $complete_prompt;
    }

    /**
     * Process raw AI feedback into structured format
     *
     * @param string $raw_feedback Raw feedback from AI
     * @param string $original_text Original essay text
     * @param int $level Feedback level
     * @return array Processed feedback with HTML, highlights, and score
     */
    private function process_feedback($raw_feedback, $original_text, $level) {
        // Extract highlight ranges
        $highlights = $this->extract_highlight_ranges($raw_feedback, $original_text);

        // Calculate completion score based on feedback content
        $score = $this->calculate_feedback_score($raw_feedback, $highlights, $level);

        // Clean up HTML and format for display
        $html = $this->format_feedback_html($raw_feedback);

        return [
            'html' => $html,
            'highlights' => json_encode($highlights),
            'score' => $score,
        ];
    }

    /**
     * Extract highlight ranges from feedback
     *
     * @param string $feedback_html HTML feedback content
     * @param string $original_text Original essay text
     * @return array Array of highlight ranges
     */
    private function extract_highlight_ranges($feedback_html, $original_text) {
        $ranges = [];

        // Find all highlighted text references
        preg_match_all('/<span class="highlight-target">(.*?)<\/span>/s', $feedback_html, $matches);

        foreach ($matches[1] as $index => $target_text) {
            // Find position in original text
            $position = strpos($original_text, trim($target_text));
            if ($position !== false) {
                $ranges[] = [
                    'start' => $position,
                    'end' => $position + strlen(trim($target_text)),
                    'type' => 'improvement',
                    'feedback' => $this->extract_specific_feedback($target_text, $feedback_html),
                    'id' => 'highlight_' . ($index + 1),
                ];
            }
        }

        return $ranges;
    }

    /**
     * Extract specific feedback for highlighted text
     *
     * @param string $target_text The highlighted text
     * @param string $feedback_html Full feedback HTML
     * @return string Specific feedback for this highlight
     */
    private function extract_specific_feedback($target_text, $feedback_html) {
        // Look for feedback sentences that mention the target text
        $sentences = preg_split('/[.!?]+/', $feedback_html);

        foreach ($sentences as $sentence) {
            if (stripos($sentence, $target_text) !== false) {
                return trim(strip_tags($sentence)) . '.';
            }
        }

        return 'Consider revising this text for improvement.';
    }

    /**
     * Calculate completion score based on feedback analysis
     *
     * @param string $feedback Feedback content
     * @param array $highlights Highlight ranges
     * @param int $level Feedback level
     * @return float Completion score (0-100)
     */
    private function calculate_feedback_score($feedback, $highlights, $level) {
        // Base score calculation logic
        $base_score = 60; // Starting point

        // Adjust based on number of issues found
        $issues_found = count($highlights);
        $max_issues_per_level = [1 => 10, 2 => 8, 3 => 5];
        $max_issues = $max_issues_per_level[$level] ?? 8;

        // Fewer issues = higher score
        $issue_score = max(0, ($max_issues - $issues_found) / $max_issues * 40);

        // Adjust based on feedback tone (positive indicators)
        $positive_indicators = ['good', 'well', 'clear', 'strong', 'effective'];
        $positive_count = 0;
        foreach ($positive_indicators as $indicator) {
            $positive_count += substr_count(strtolower($feedback), $indicator);
        }

        $positive_score = min(20, $positive_count * 2);

        $total_score = $base_score + $issue_score + $positive_score;

        return min(100, max(0, $total_score));
    }

    /**
     * Format feedback HTML for display
     *
     * @param string $raw_feedback Raw feedback text
     * @return string Formatted HTML
     */
    private function format_feedback_html($raw_feedback) {
        // Convert line breaks to HTML
        $html = nl2br($raw_feedback);

        // Ensure highlight spans are properly formatted
        $html = preg_replace('/<span class="highlight-target">(.*?)<\/span>/', '<mark class="essay-highlight">$1</mark>', $html);

        // Add CSS classes for styling
        $html = '<div class="essaysmaster-feedback level-feedback">' . $html . '</div>';

        return $html;
    }

    /**
     * Log AI interaction for debugging and analytics
     *
     * @param int $level Feedback level
     * @param string $essay_text Essay text
     * @param array $feedback Processed feedback
     * @param float $response_time Response time
     */
    private function log_ai_interaction($level, $essay_text, $feedback, $response_time) {
        $log_data = [
            'session_id' => $this->session->id,
            'level' => $level,
            'essay_length' => strlen($essay_text),
            'word_count' => str_word_count($essay_text),
            'highlights_count' => count(json_decode($feedback['highlights'] ?? '[]', true)),
            'completion_score' => $feedback['score'],
            'response_time' => $response_time,
            'timestamp' => time(),
        ];

        error_log('Essays Master AI Interaction: ' . json_encode($log_data));
    }

    /**
     * Save feedback to database
     *
     * @param int $version_id Version ID
     * @param array $feedback_data Feedback data
     * @return int Feedback record ID
     */
    public function save_feedback($version_id, $feedback_data) {
        global $DB;

        $feedback = new \stdClass();
        $feedback->version_id = $version_id;
        $feedback->level_type = 'level_' . $feedback_data['level'];
        $feedback->feedback_html = $feedback_data['feedback_html'];
        $feedback->highlighted_areas = $feedback_data['highlighted_areas'];
        $feedback->completion_score = $feedback_data['completion_score'];
        $feedback->feedback_generated_time = time();
        $feedback->api_response_time = $feedback_data['response_time'];
        $feedback->timecreated = time();

        return $DB->insert_record('local_essaysmaster_feedback', $feedback);
    }

    /**
     * Get cached feedback for a version and level
     *
     * @param int $version_id Version ID
     * @param int $level Level number
     * @return object|false Feedback record or false if not found
     */
    public function get_cached_feedback($version_id, $level) {
        global $DB;

        return $DB->get_record('local_essaysmaster_feedback', [
            'version_id' => $version_id,
            'level_type' => 'level_' . $level,
        ]);
    }

    /**
     * Generate fallback feedback when Quiz Dashboard is not available
     *
     * @param string $essay_text Essay text
     * @param int $level Feedback level
     * @param string $prompt AI prompt
     * @return string Simulated feedback
     */
    private function generate_fallback_feedback($essay_text, $level, $prompt) {
        $word_count = str_word_count($essay_text);
        $char_count = strlen($essay_text);

        $feedback_templates = [
            1 => [
                "Your essay shows good effort with {$word_count} words. <span class=\"highlight-target\">Check for basic grammar and spelling errors</span> throughout your text.",
                "Focus on <span class=\"highlight-target\">punctuation consistency</span> and <span class=\"highlight-target\">sentence structure</span> to improve readability.",
                "Review <span class=\"highlight-target\">capitalization rules</span> and ensure proper use of periods and commas."
            ],
            2 => [
                "Your vocabulary choices could be enhanced. Consider using <span class=\"highlight-target\">more sophisticated words</span> to express your ideas.",
                "Work on <span class=\"highlight-target\">sentence variety</span> by combining short sentences and using different structures.",
                "Improve <span class=\"highlight-target\">word choice precision</span> to make your arguments more compelling."
            ],
            3 => [
                "Strengthen your <span class=\"highlight-target\">thesis statement</span> to provide clearer direction for your essay.",
                "Develop <span class=\"highlight-target\">stronger transitions</span> between paragraphs to improve flow.",
                "Add <span class=\"highlight-target\">more detailed evidence</span> to support your main arguments."
            ]
        ];

        $level_feedback = $feedback_templates[$level] ?? $feedback_templates[1];
        $selected_feedback = $level_feedback[array_rand($level_feedback)];

        return "<p><strong>Level {$level} Feedback (Demo Mode):</strong></p><p>{$selected_feedback}</p>" .
               "<p><em>Note: This is simulated feedback. Install and configure Quiz Dashboard for AI-powered feedback.</em></p>";
    }
}