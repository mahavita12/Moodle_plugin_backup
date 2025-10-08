<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify_ added
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
 * Library functions for Question Flags plugin.
 *
 * @package    local_questionflags
 * @copyright  2024 Question Flags Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * DEPRECATED: Legacy callback function - functionality moved to hook system.
 * 
 * @deprecated since Moodle 4.4+ - use hook system instead
 * @see classes/hook/output/before_footer_html_generation.php
 */
function local_questionflags_before_footer_DEPRECATED() {
    // DEPRECATED: This function is no longer used - functionality moved to hook system
    // See: classes/hook/output/before_footer_html_generation.php
    error_log('QUESTIONFLAGS: Deprecated callback function called - should be using hook system');
    return;
}

// Helper functions for question metadata storage

/**
 * Save structure guide to question metadata (persists across all quizzes)
 *
 * @param int $questionid Question ID
 * @param string $content Guide content
 * @return bool Success status
 */
function local_questionflags_save_question_guide($questionid, $content) {
    global $DB;
    
    try {
        // Check if Moodle has question_metadata table (Moodle 4.0+)
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('question_metadata')) {
            // Use built-in question metadata system
            $existing = $DB->get_record('question_metadata', [
                'questionid' => $questionid,
                'name' => 'structure_guide'
            ]);
            
            if ($existing) {
                $existing->value = $content;
                $existing->timemodified = time();
                $result = $DB->update_record('question_metadata', $existing);
                error_log("Updated metadata record for question $questionid: " . ($result ? 'success' : 'failed'));
            } else {
                $record = new stdClass();
                $record->questionid = $questionid;
                $record->name = 'structure_guide';
                $record->value = $content;
                $record->timecreated = time();
                $record->timemodified = time();
                $result = $DB->insert_record('question_metadata', $record);
                error_log("Created metadata record for question $questionid: " . ($result ? 'success' : 'failed'));
            }
        } else {
            // Fallback to custom table for older Moodle versions
            $existing = $DB->get_record('local_questionflags_guides', [
                'questionid' => $questionid
            ]);
            
            if ($existing) {
                $existing->guide_content = $content;
                $existing->timemodified = time();
                $result = $DB->update_record('local_questionflags_guides', $existing);
                error_log("Updated custom table record for question $questionid: " . ($result ? 'success' : 'failed'));
            } else {
                $record = new stdClass();
                $record->questionid = $questionid;
                $record->guide_content = $content;
                $record->timecreated = time();
                $record->timemodified = time();
                $result = $DB->insert_record('local_questionflags_guides', $record);
                error_log("Created custom table record for question $questionid: " . ($result ? 'success' : 'failed'));
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error saving structure guide for question $questionid: " . $e->getMessage());
        return false;
    }
}

/**
 * Get structure guides for all questions in current quiz
 *
 * @param object $page Moodle PAGE object
 * @return array Array of guides indexed by question ID
 */
function local_questionflags_get_quiz_guides($page) {
    global $DB;
    
    $guides = [];
    
    try {
        // Just get all guides from the custom table for now
        $records = $DB->get_records('local_questionflags_guides');
        
        foreach ($records as $record) {
            $guides[$record->questionid] = $record->guide_content;
        }
        
        error_log('Successfully loaded ' . count($guides) . ' structure guides');
    } catch (Exception $e) {
        error_log('Error loading structure guides: ' . $e->getMessage());
    }
    
    return $guides;
}

/**
 * Force reload guides data after save
 *
 * @param int $questionid Question ID
 * @return string Guide content or empty string
 */
function local_questionflags_get_single_guide($questionid) {
    global $DB;
    
    try {
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('question_metadata')) {
            $metadata = $DB->get_record('question_metadata', [
                'questionid' => $questionid,
                'name' => 'structure_guide'
            ]);
            
            return $metadata ? $metadata->value : '';
        } else {
            $guide = $DB->get_record('local_questionflags_guides', ['questionid' => $questionid]);
            return $guide ? $guide->guide_content : '';
        }
    } catch (Exception $e) {
        error_log('Error getting single guide: ' . $e->getMessage());
        return '';
    }
}

/**
 * Get structure guide for a single question (helper function)
 *
 * @param int $questionid Question ID
 * @return string|null Guide content or null if not found
 */
function local_questionflags_get_question_guide($questionid) {
    global $DB;
    
    // Check if question_metadata table exists
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('question_metadata')) {
        $metadata = $DB->get_record('question_metadata', [
            'questionid' => $questionid,
            'name' => 'structure_guide'
        ]);
        
        if ($metadata) {
            return $metadata->value;
        }
    } else {
        // Fallback to custom table
        $guide = $DB->get_record('local_questionflags_guides', ['questionid' => $questionid]);
        
        if ($guide) {
            return $guide->guide_content;
        }
    }
    
    return null;
}
