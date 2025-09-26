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
 * Essays Master plugin upgrade script.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the Essays Master plugin.
 *
 * @param int $oldversion The old version of the plugin
 * @return bool
 */
function xmldb_local_essaysmaster_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024091801) {
        // Add any future upgrade steps here

        // Essays Master savepoint reached.
        upgrade_plugin_savepoint(true, 2024091801, 'local', 'essaysmaster');
    }

    // ✅ NEW UPGRADE STEP: Add feedback_rounds_completed field
    if ($oldversion < 2024092001) {
        
        // Define table local_essaysmaster_sessions to be modified.
        $table = new xmldb_table('local_essaysmaster_sessions');
        
        // Define field feedback_rounds_completed to be added to local_essaysmaster_sessions.
        $field = new xmldb_field('feedback_rounds_completed', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'current_level');
        
        // Conditionally launch add field feedback_rounds_completed.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            
            // Update existing records to set feedback_rounds_completed based on current_level
            $DB->execute("UPDATE {local_essaysmaster_sessions} 
                         SET feedback_rounds_completed = current_level 
                         WHERE feedback_rounds_completed = 0 AND current_level > 0");
            
            // Mark sessions as completed if they have 3+ rounds
            $DB->execute("UPDATE {local_essaysmaster_sessions} 
                         SET final_submission_allowed = 1, 
                             status = 'completed',
                             feedback_rounds_completed = 3
                         WHERE current_level >= 3 AND final_submission_allowed = 0");
                         
            // Log the upgrade
            mtrace('Essays Master: Added feedback_rounds_completed field and migrated existing data');
        }

        // Essays Master savepoint reached.
        upgrade_plugin_savepoint(true, 2024092001, 'local', 'essaysmaster');
    }

    // ✅ NEW UPGRADE STEP: Add unique constraints and clean up duplicates
    if ($oldversion < 2024092002) {
        
        // Step 1: Clean up duplicate sessions first
        mtrace('Essays Master: Cleaning up duplicate sessions...');
        
        // Find and remove duplicate sessions, keeping the newest one
        $duplicate_sessions = $DB->get_records_sql("
            SELECT attempt_id, user_id, COUNT(*) as cnt, MIN(id) as keep_id, MAX(id) as latest_id
            FROM {local_essaysmaster_sessions} 
            GROUP BY attempt_id, user_id 
            HAVING COUNT(*) > 1
        ");
        
        $cleaned_count = 0;
        foreach ($duplicate_sessions as $duplicate) {
            // Keep the latest session (highest feedback_rounds_completed)
            $sessions_to_check = $DB->get_records_sql("
                SELECT * FROM {local_essaysmaster_sessions} 
                WHERE attempt_id = ? AND user_id = ? 
                ORDER BY feedback_rounds_completed DESC, timemodified DESC
            ", [$duplicate->attempt_id, $duplicate->user_id]);
            
            $keep_session = array_shift($sessions_to_check); // Keep the first (best) one
            
            // Delete the rest
            foreach ($sessions_to_check as $delete_session) {
                $DB->delete_records('local_essaysmaster_sessions', ['id' => $delete_session->id]);
                $cleaned_count++;
            }
        }
        
        mtrace("Essays Master: Cleaned up {$cleaned_count} duplicate sessions");
        
        // Step 2: Add unique constraint to prevent future duplicates
        $sessions_table = new xmldb_table('local_essaysmaster_sessions');
        $unique_key = new xmldb_key('unique_attempt_user', XMLDB_KEY_UNIQUE, ['attempt_id', 'user_id']);
        
        // Try to add key (will fail silently if it already exists)
        try {
            $dbman->add_key($sessions_table, $unique_key);
            mtrace('Essays Master: Added unique constraint to sessions table');
        } catch (Exception $e) {
            mtrace('Essays Master: Unique constraint already exists or could not be added: ' . $e->getMessage());
        }
        
        // Step 3: Update feedback table structure if needed
        $feedback_table = new xmldb_table('local_essaysmaster_feedback');
        
        // Add attempt_id field if it doesn't exist
        $attempt_field = new xmldb_field('attempt_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'version_id');
        if (!$dbman->field_exists($feedback_table, $attempt_field)) {
            $dbman->add_field($feedback_table, $attempt_field);
            
            // Populate attempt_id from version_id (our current workaround)
            $DB->execute("UPDATE {local_essaysmaster_feedback} SET attempt_id = version_id WHERE attempt_id = 0");
            mtrace('Essays Master: Added attempt_id field to feedback table');
        }
        
        // Add question_attempt_id field if it doesn't exist  
        $qa_field = new xmldb_field('question_attempt_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'attempt_id');
        if (!$dbman->field_exists($feedback_table, $qa_field)) {
            $dbman->add_field($feedback_table, $qa_field);
            mtrace('Essays Master: Added question_attempt_id field to feedback table');
        }
        
        // Add round_number field if it doesn't exist
        $round_field = new xmldb_field('round_number', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'question_attempt_id');
        if (!$dbman->field_exists($feedback_table, $round_field)) {
            $dbman->add_field($feedback_table, $round_field);
            
            // Extract round number from level_type
            $feedback_records = $DB->get_records('local_essaysmaster_feedback');
            foreach ($feedback_records as $record) {
                if (preg_match('/round_(\d+)/', $record->level_type, $matches)) {
                    $record->round_number = intval($matches[1]);
                    $DB->update_record('local_essaysmaster_feedback', $record);
                }
            }
            mtrace('Essays Master: Added round_number field and populated from level_type');
        }
        
        // Step 4: Add unique constraint to feedback table
        $feedback_unique_key = new xmldb_key('unique_feedback_round', XMLDB_KEY_UNIQUE, ['attempt_id', 'question_attempt_id', 'round_number']);
        
        // Clean up any potential duplicates first
        $duplicate_feedback = $DB->get_records_sql("
            SELECT attempt_id, question_attempt_id, round_number, COUNT(*) as cnt, MIN(id) as keep_id
            FROM {local_essaysmaster_feedback} 
            WHERE attempt_id > 0 
            GROUP BY attempt_id, question_attempt_id, round_number 
            HAVING COUNT(*) > 1
        ");
        
        $feedback_cleaned = 0;
        foreach ($duplicate_feedback as $dup) {
            // Keep the first one, delete the rest
            $DB->execute("
                DELETE FROM {local_essaysmaster_feedback} 
                WHERE attempt_id = ? AND question_attempt_id = ? AND round_number = ? AND id != ?
            ", [$dup->attempt_id, $dup->question_attempt_id, $dup->round_number, $dup->keep_id]);
            $feedback_cleaned++;
        }
        
        if ($feedback_cleaned > 0) {
            mtrace("Essays Master: Cleaned up {$feedback_cleaned} duplicate feedback records");
        }
        
        // Try to add unique constraint
        try {
            $dbman->add_key($feedback_table, $feedback_unique_key);
            mtrace('Essays Master: Added unique constraint to feedback table');
        } catch (Exception $e) {
            mtrace('Essays Master: Feedback unique constraint already exists or could not be added: ' . $e->getMessage());
        }
        
        mtrace('Essays Master: Database cleanup and constraint addition complete');
        
        // Essays Master savepoint reached.
        upgrade_plugin_savepoint(true, 2024092002, 'local', 'essaysmaster');
    }

    // ðŸ“Š NEW UPGRADE STEP: Add dashboard quiz configuration table
    if ($oldversion < 2024092501) {
        
        // Define table local_essaysmaster_quiz_config to be created.
        $table = new xmldb_table('local_essaysmaster_quiz_config');
        
        // Adding fields to table local_essaysmaster_quiz_config.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quiz_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('is_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('validation_thresholds', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('max_attempts_per_round', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '3');
        $table->add_field('created_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('modified_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        
        // Adding keys to table local_essaysmaster_quiz_config.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('unique_quiz', XMLDB_KEY_UNIQUE, ['quiz_id']);
        
        // Adding indexes to table local_essaysmaster_quiz_config.
        $table->add_index('idx_course_enabled', XMLDB_INDEX_NOTUNIQUE, ['course_id', 'is_enabled']);
        
        // Conditionally launch create table for local_essaysmaster_quiz_config.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
            
            // ðŸŽ¯ DEFAULT BEHAVIOR: Auto-enable Essays Master for all existing essay quizzes
            mtrace('Essays Master Dashboard: Creating quiz configuration table...');
            
            // Get admin user ID safely
            $admin_user = $DB->get_record('user', array('username' => 'admin'), 'id', IGNORE_MISSING);
            $admin_id = $admin_user ? $admin_user->id : 2; // fallback to user ID 2
            
            // Find all quizzes that contain essay questions - simplified query to avoid complex joins
            $essay_quizzes = array();
            
            try {
                // First, get all quiz IDs
                $all_quizzes = $DB->get_records('quiz', null, 'course, name', 'id, course');
                
                foreach ($all_quizzes as $quiz) {
                    // Check if this quiz has essay questions
                    $has_essay = $DB->record_exists_sql("
                        SELECT 1 
                        FROM {quiz_slots} qs 
                        JOIN {question} q ON q.id = qs.questionid 
                        WHERE qs.quizid = ? AND q.qtype = 'essay'
                    ", [$quiz->id]);
                    
                    if ($has_essay) {
                        $quiz_data = new stdClass();
                        $quiz_data->quiz_id = $quiz->id;
                        $quiz_data->course = $quiz->course;
                        $essay_quizzes[] = $quiz_data;
                    }
                }
            } catch (Exception $e) {
                mtrace('Essays Master: Could not query for essay quizzes: ' . $e->getMessage());
                mtrace('Essays Master: Skipping auto-enabling for existing quizzes');
                $essay_quizzes = array();
            }
            
            $enabled_count = 0;
            foreach ($essay_quizzes as $quiz) {
                $config = new stdClass();
                $config->quiz_id = $quiz->quiz_id;
                $config->course_id = $quiz->course;
                $config->is_enabled = 1; // DEFAULT: ENABLED
                $config->validation_thresholds = json_encode([
                    'round_2' => 50, // Grammar/Spelling threshold
                    'round_4' => 50, // Vocabulary threshold  
                    'round_6' => 50  // Structure threshold
                ]);
                $config->max_attempts_per_round = 3;
                $config->created_by = $admin_id;
                $config->modified_by = null;
                $config->timecreated = time();
                $config->timemodified = time();
                
                try {
                    $DB->insert_record('local_essaysmaster_quiz_config', $config);
                    $enabled_count++;
                } catch (Exception $e) {
                    mtrace('Warning: Could not enable Essays Master for quiz ' . $quiz->quiz_id . ': ' . $e->getMessage());
                }
            }
            
            mtrace("Essays Master Dashboard: Auto-enabled for {$enabled_count} essay quizzes (default behavior)");
        }
        
        // Essays Master savepoint reached.
        upgrade_plugin_savepoint(true, 2024092501, 'local', 'essaysmaster');
    }

    return true;
}