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
 * Progress tracker for Essays Master completion requirements.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_essaysmaster;

defined('MOODLE_INTERNAL') || die();

/**
 * Class progress_tracker
 *
 * Tracks completion requirements for each level of Essays Master
 */
class progress_tracker {

    /** @var object Session record */
    private $session;

    /** @var array Level requirements configuration */
    private $requirements;

    /**
     * Constructor
     *
     * @param object $session Essays Master session
     */
    public function __construct($session) {
        $this->session = $session;
        $this->initialize_requirements();
    }

    /**
     * Initialize level requirements
     */
    private function initialize_requirements() {
        $this->requirements = [
            1 => [
                'grammar_fixes' => ['target' => 5, 'weight' => 0.4, 'description' => 'Fix grammar errors'],
                'spelling_fixes' => ['target' => 3, 'weight' => 0.3, 'description' => 'Correct spelling mistakes'],
                'punctuation_fixes' => ['target' => 2, 'weight' => 0.3, 'description' => 'Improve punctuation'],
            ],
            2 => [
                'vocabulary_improvements' => ['target' => 4, 'weight' => 0.5, 'description' => 'Enhance vocabulary choices'],
                'sentence_variety' => ['target' => 3, 'weight' => 0.5, 'description' => 'Improve sentence structure variety'],
            ],
            3 => [
                'structure_improvements' => ['target' => 2, 'weight' => 0.6, 'description' => 'Strengthen essay structure'],
                'content_depth' => ['target' => 1, 'weight' => 0.4, 'description' => 'Deepen content analysis'],
            ],
        ];
    }

    /**
     * Initialize progress tracking for a session level
     *
     * @param int $level Level number
     * @return bool Success status
     */
    public function initialize_level_progress($level) {
        global $DB;

        if (!isset($this->requirements[$level])) {
            return false;
        }

        // Check if progress already exists
        $existing = $DB->get_records('local_essaysmaster_progress', [
            'session_id' => $this->session->id,
            'level_number' => $level,
        ]);

        if (!empty($existing)) {
            return true; // Already initialized
        }

        // Create progress records for each requirement
        $success = true;
        foreach ($this->requirements[$level] as $req_type => $config) {
            $progress = new \stdClass();
            $progress->session_id = $this->session->id;
            $progress->level_number = $level;
            $progress->requirement_type = $req_type;
            $progress->requirement_description = $config['description'];
            $progress->is_completed = 0;
            $progress->timecreated = time();
            $progress->timemodified = time();

            try {
                $DB->insert_record('local_essaysmaster_progress', $progress);
            } catch (\Exception $e) {
                $success = false;
                error_log('Essays Master: Failed to create progress record: ' . $e->getMessage());
            }
        }

        return $success;
    }

    /**
     * Update progress based on feedback analysis
     *
     * @param int $level Level number
     * @param array $feedback_data Feedback data from AI
     * @param string $original_text Original essay text
     * @param string $revised_text Revised essay text (if any)
     * @return bool Success status
     */
    public function update_progress($level, $feedback_data, $original_text, $revised_text = null) {
        global $DB;

        if (!isset($this->requirements[$level])) {
            return false;
        }

        // Analyze changes based on level
        $improvements = $this->analyze_improvements($level, $feedback_data, $original_text, $revised_text);

        // Update progress records
        foreach ($this->requirements[$level] as $req_type => $config) {
            $progress = $DB->get_record('local_essaysmaster_progress', [
                'session_id' => $this->session->id,
                'level_number' => $level,
                'requirement_type' => $req_type,
            ]);

            if ($progress) {
                $current_count = isset($improvements[$req_type]) ? $improvements[$req_type] : 0;
                $is_completed = $current_count >= $config['target'];

                // Update progress
                $progress->is_completed = $is_completed ? 1 : 0;
                $progress->completion_time = $is_completed ? time() : null;
                $progress->completion_data = json_encode([
                    'current_count' => $current_count,
                    'target_count' => $config['target'],
                    'improvement_details' => $improvements[$req_type . '_details'] ?? [],
                ]);
                $progress->timemodified = time();

                $DB->update_record('local_essaysmaster_progress', $progress);
            }
        }

        return true;
    }

    /**
     * Analyze improvements made based on feedback
     *
     * @param int $level Level number
     * @param array $feedback_data Feedback data
     * @param string $original_text Original text
     * @param string $revised_text Revised text
     * @return array Improvement counts by requirement type
     */
    private function analyze_improvements($level, $feedback_data, $original_text, $revised_text = null) {
        $improvements = [];

        switch ($level) {
            case 1:
                $improvements = $this->analyze_level1_improvements($feedback_data, $original_text, $revised_text);
                break;
            case 2:
                $improvements = $this->analyze_level2_improvements($feedback_data, $original_text, $revised_text);
                break;
            case 3:
                $improvements = $this->analyze_level3_improvements($feedback_data, $original_text, $revised_text);
                break;
        }

        return $improvements;
    }

    /**
     * Analyze Level 1 improvements (grammar, spelling, punctuation)
     *
     * @param array $feedback_data Feedback data
     * @param string $original_text Original text
     * @param string $revised_text Revised text
     * @return array Improvement counts
     */
    private function analyze_level1_improvements($feedback_data, $original_text, $revised_text = null) {
        $improvements = [
            'grammar_fixes' => 0,
            'spelling_fixes' => 0,
            'punctuation_fixes' => 0,
        ];

        // Parse feedback for error types
        $feedback_html = $feedback_data['feedback_html'] ?? '';

        // Count grammar-related mentions
        $grammar_keywords = ['grammar', 'grammatical', 'tense', 'subject-verb', 'agreement'];
        foreach ($grammar_keywords as $keyword) {
            $improvements['grammar_fixes'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Count spelling-related mentions
        $spelling_keywords = ['spelling', 'misspelled', 'spell', 'typo'];
        foreach ($spelling_keywords as $keyword) {
            $improvements['spelling_fixes'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Count punctuation-related mentions
        $punctuation_keywords = ['punctuation', 'comma', 'period', 'semicolon', 'colon'];
        foreach ($punctuation_keywords as $keyword) {
            $improvements['punctuation_fixes'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // If revised text is provided, analyze actual changes
        if ($revised_text && $revised_text !== $original_text) {
            $actual_changes = $this->count_text_changes($original_text, $revised_text);

            // Boost counts based on actual changes made
            $improvements['grammar_fixes'] = max($improvements['grammar_fixes'], $actual_changes['word_changes']);
            $improvements['spelling_fixes'] = max($improvements['spelling_fixes'], $actual_changes['spelling_fixes']);
            $improvements['punctuation_fixes'] = max($improvements['punctuation_fixes'], $actual_changes['punctuation_changes']);
        }

        return $improvements;
    }

    /**
     * Analyze Level 2 improvements (vocabulary, sentence variety)
     *
     * @param array $feedback_data Feedback data
     * @param string $original_text Original text
     * @param string $revised_text Revised text
     * @return array Improvement counts
     */
    private function analyze_level2_improvements($feedback_data, $original_text, $revised_text = null) {
        $improvements = [
            'vocabulary_improvements' => 0,
            'sentence_variety' => 0,
        ];

        $feedback_html = $feedback_data['feedback_html'] ?? '';

        // Count vocabulary-related mentions
        $vocab_keywords = ['vocabulary', 'word choice', 'synonym', 'precise', 'specific', 'advanced'];
        foreach ($vocab_keywords as $keyword) {
            $improvements['vocabulary_improvements'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Count sentence variety mentions
        $variety_keywords = ['sentence', 'variety', 'structure', 'complex', 'compound', 'flow'];
        foreach ($variety_keywords as $keyword) {
            $improvements['sentence_variety'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Analyze actual changes if revised text provided
        if ($revised_text && $revised_text !== $original_text) {
            $vocab_changes = $this->count_vocabulary_improvements($original_text, $revised_text);
            $sentence_changes = $this->count_sentence_improvements($original_text, $revised_text);

            $improvements['vocabulary_improvements'] = max($improvements['vocabulary_improvements'], $vocab_changes);
            $improvements['sentence_variety'] = max($improvements['sentence_variety'], $sentence_changes);
        }

        return $improvements;
    }

    /**
     * Analyze Level 3 improvements (structure, content depth)
     *
     * @param array $feedback_data Feedback data
     * @param string $original_text Original text
     * @param string $revised_text Revised text
     * @return array Improvement counts
     */
    private function analyze_level3_improvements($feedback_data, $original_text, $revised_text = null) {
        $improvements = [
            'structure_improvements' => 0,
            'content_depth' => 0,
        ];

        $feedback_html = $feedback_data['feedback_html'] ?? '';

        // Count structure-related mentions
        $structure_keywords = ['structure', 'organization', 'paragraph', 'transition', 'thesis', 'conclusion'];
        foreach ($structure_keywords as $keyword) {
            $improvements['structure_improvements'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Count content depth mentions
        $depth_keywords = ['analysis', 'evidence', 'support', 'detail', 'example', 'depth', 'argument'];
        foreach ($depth_keywords as $keyword) {
            $improvements['content_depth'] += substr_count(strtolower($feedback_html), $keyword);
        }

        // Analyze structural changes if revised text provided
        if ($revised_text && $revised_text !== $original_text) {
            $structure_changes = $this->count_structural_improvements($original_text, $revised_text);
            $content_changes = $this->count_content_improvements($original_text, $revised_text);

            $improvements['structure_improvements'] = max($improvements['structure_improvements'], $structure_changes);
            $improvements['content_depth'] = max($improvements['content_depth'], $content_changes);
        }

        return $improvements;
    }

    /**
     * Count text changes between original and revised versions
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return array Change counts
     */
    private function count_text_changes($original, $revised) {
        $original_words = explode(' ', $original);
        $revised_words = explode(' ', $revised);

        return [
            'word_changes' => abs(count($revised_words) - count($original_words)),
            'spelling_fixes' => $this->count_spelling_corrections($original, $revised),
            'punctuation_changes' => $this->count_punctuation_changes($original, $revised),
        ];
    }

    /**
     * Count spelling corrections made
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of spelling corrections
     */
    private function count_spelling_corrections($original, $revised) {
        // Simple heuristic: count words that changed but are similar length
        $original_words = preg_split('/\s+/', strtolower($original));
        $revised_words = preg_split('/\s+/', strtolower($revised));

        $corrections = 0;
        $min_length = min(count($original_words), count($revised_words));

        for ($i = 0; $i < $min_length; $i++) {
            if ($original_words[$i] !== $revised_words[$i]) {
                $length_diff = abs(strlen($original_words[$i]) - strlen($revised_words[$i]));
                if ($length_diff <= 2) { // Likely a spelling correction
                    $corrections++;
                }
            }
        }

        return $corrections;
    }

    /**
     * Count punctuation changes
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of punctuation changes
     */
    private function count_punctuation_changes($original, $revised) {
        $original_punct = preg_match_all('/[.,;:!?]/', $original);
        $revised_punct = preg_match_all('/[.,;:!?]/', $revised);

        return abs($revised_punct - $original_punct);
    }

    /**
     * Count vocabulary improvements
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of vocabulary improvements
     */
    private function count_vocabulary_improvements($original, $revised) {
        $original_words = preg_split('/\s+/', strtolower($original));
        $revised_words = preg_split('/\s+/', strtolower($revised));

        // Count words that changed to longer/more complex words
        $improvements = 0;
        $min_length = min(count($original_words), count($revised_words));

        for ($i = 0; $i < $min_length; $i++) {
            if ($original_words[$i] !== $revised_words[$i]) {
                if (strlen($revised_words[$i]) > strlen($original_words[$i])) {
                    $improvements++;
                }
            }
        }

        return $improvements;
    }

    /**
     * Count sentence improvements
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of sentence improvements
     */
    private function count_sentence_improvements($original, $revised) {
        $original_sentences = preg_split('/[.!?]+/', $original);
        $revised_sentences = preg_split('/[.!?]+/', $revised);

        // Simple heuristic: more sentences or longer sentences indicate variety
        $length_diff = abs(count($revised_sentences) - count($original_sentences));
        $avg_length_diff = 0;

        if (count($original_sentences) > 0 && count($revised_sentences) > 0) {
            $orig_avg = array_sum(array_map('strlen', $original_sentences)) / count($original_sentences);
            $rev_avg = array_sum(array_map('strlen', $revised_sentences)) / count($revised_sentences);
            $avg_length_diff = abs($rev_avg - $orig_avg) / 10; // Scale down
        }

        return max($length_diff, $avg_length_diff);
    }

    /**
     * Count structural improvements
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of structural improvements
     */
    private function count_structural_improvements($original, $revised) {
        // Count paragraph breaks and transitions
        $original_paragraphs = preg_split('/\n\s*\n/', $original);
        $revised_paragraphs = preg_split('/\n\s*\n/', $revised);

        $structure_score = abs(count($revised_paragraphs) - count($original_paragraphs));

        // Look for transition words
        $transitions = ['however', 'furthermore', 'moreover', 'therefore', 'consequently', 'in addition'];
        $transition_count = 0;

        foreach ($transitions as $transition) {
            $original_count = substr_count(strtolower($original), $transition);
            $revised_count = substr_count(strtolower($revised), $transition);
            $transition_count += max(0, $revised_count - $original_count);
        }

        return $structure_score + $transition_count;
    }

    /**
     * Count content improvements
     *
     * @param string $original Original text
     * @param string $revised Revised text
     * @return int Number of content improvements
     */
    private function count_content_improvements($original, $revised) {
        // Look for evidence and analysis indicators
        $evidence_indicators = ['evidence', 'example', 'data', 'research', 'study', 'shows', 'demonstrates'];
        $evidence_count = 0;

        foreach ($evidence_indicators as $indicator) {
            $original_count = substr_count(strtolower($original), $indicator);
            $revised_count = substr_count(strtolower($revised), $indicator);
            $evidence_count += max(0, $revised_count - $original_count);
        }

        return $evidence_count;
    }

    /**
     * Get current progress for a level
     *
     * @param int $level Level number
     * @return array Progress data
     */
    public function get_level_progress($level) {
        global $DB;

        $progress_records = $DB->get_records('local_essaysmaster_progress', [
            'session_id' => $this->session->id,
            'level_number' => $level,
        ]);

        $progress = [];
        foreach ($progress_records as $record) {
            $progress[$record->requirement_type] = [
                'is_completed' => $record->is_completed,
                'completion_time' => $record->completion_time,
                'description' => $record->requirement_description,
                'data' => json_decode($record->completion_data, true),
            ];
        }

        return $progress;
    }

    /**
     * Calculate overall completion score for a level
     *
     * @param int $level Level number
     * @return float Completion score (0-100)
     */
    public function calculate_completion_score($level) {
        if (!isset($this->requirements[$level])) {
            return 0.0;
        }

        $progress = $this->get_level_progress($level);
        $total_score = 0;

        foreach ($this->requirements[$level] as $req_type => $config) {
            if (isset($progress[$req_type])) {
                $is_completed = $progress[$req_type]['is_completed'];
                $total_score += $is_completed * $config['weight'];
            }
        }

        return $total_score * 100; // Convert to percentage
    }
}