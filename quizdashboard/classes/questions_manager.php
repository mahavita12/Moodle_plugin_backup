<?php
/**
 * Questions Manager Class for Questions Dashboard
 * File: local/quizdashboard/classes/questions_manager.php
 */

namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Get quiz slot questions in a way that works on both pre-4.0 and 4.0+ schemas.
 */
function qdb_get_quiz_questions_crossver(int $quizid): array {
    global $DB;

    if (!$quizid) {
        error_log('[QDB] qdb_get_quiz_questions_crossver called with empty quizid');
        return [];
    }

    $manager = $DB->get_manager();
    $haslegacyquestionid = $manager->field_exists('quiz_slots', 'questionid');
    
    error_log('[QDB] Schema detection: haslegacyquestionid=' . ($haslegacyquestionid ? 'true' : 'false'));

    if ($haslegacyquestionid) {
        // Legacy path (<= Moodle 3.11): quiz_slots.questionid exists
        error_log('[QDB] Using legacy query path for Moodle <=3.11');
        $sql = "
            SELECT
                q.id,
                q.name,
                q.questiontext,
                q.qtype,
                q.defaultmark,
                qs.slot
            FROM {quiz_slots} qs
            JOIN {question} q
              ON q.id = qs.questionid
            WHERE qs.quizid = :quizid
            ORDER BY qs.slot
        ";
        $params = ['quizid' => $quizid];
        
        try {
            $result = $DB->get_records_sql($sql, $params);
            error_log('[QDB] Legacy query returned ' . count($result) . ' questions');
            return $result;
        } catch (Exception $e) {
            error_log('[QDB] Legacy query failed: ' . $e->getMessage());
            return [];
        }
    }

    // Moodle 4.0+ path - get real question data
    error_log('[QDB] Using Moodle 4.x query path');
    
    try {
        // Try the full 4.x question resolution query
        $sql = "
            SELECT
                q.id,
                q.name,
                q.questiontext,
                q.qtype,
                q.defaultmark,
                qs.slot
            FROM {quiz_slots} qs
            JOIN {question_references} qr
              ON qr.itemid = qs.id
             AND qr.component = :comp
             AND qr.questionarea = :area
            JOIN {question_bank_entries} qbe
              ON qbe.id = qr.questionbankentryid
            JOIN {question_versions} qv
              ON qv.questionbankentryid = qbe.id
             AND qv.version = (
                   SELECT MAX(qv2.version)
                   FROM {question_versions} qv2
                   WHERE qv2.questionbankentryid = qbe.id
               )
            JOIN {question} q
              ON q.id = qv.questionid
            WHERE qs.quizid = :quizid
            ORDER BY qs.slot
        ";
        $params = ['comp' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid];
        $result = $DB->get_records_sql($sql, $params);
        
        error_log('[QDB] Full 4.x query successful, returning ' . count($result) . ' real questions');
        return $result;
        
    } catch (Exception $e) {
        error_log('[QDB] Full 4.x query failed: ' . $e->getMessage());
        
        // Fallback to simple slots with placeholders
        try {
            $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot ASC');
            error_log('[QDB] Fallback: Found ' . count($slots) . ' quiz slots');
            
            if (empty($slots)) {
                return [];
            }
            
            $result = [];
            foreach ($slots as $slot) {
                $result[] = (object)[
                    'id' => 0, // placeholder
                    'name' => 'Question in slot ' . $slot->slot,
                    'questiontext' => 'Question text not available',
                    'qtype' => 'unknown',
                    'defaultmark' => 1.0,
                    'slot' => $slot->slot
                ];
            }
            
            error_log('[QDB] Fallback slots query successful, returning ' . count($result) . ' placeholder questions');
            return $result;
            
        } catch (Exception $e2) {
            error_log('[QDB] Even simple slots query failed: ' . $e2->getMessage());
            return [];
        }
    }
}

class questions_manager {
    
    /**
     * Get unique courses that have quiz attempts
     */
    public function get_unique_courses() {
        global $DB;
        
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname
                FROM {course} c
                JOIN {quiz} q ON q.course = c.id
                JOIN {quiz_attempts} qa ON qa.quiz = q.id
                WHERE c.visible = 1 AND qa.state IN ('finished', 'inprogress')
                ORDER BY c.fullname";
        
        return $DB->get_records_sql($sql);
    }
    
    /**
     * Get unique sections that have quizzes with attempts
     */
    public function get_unique_sections() {
        global $DB;
        
        $sql = "SELECT DISTINCT cs.id, cs.name, cs.section, c.fullname AS coursename
                FROM {course_sections} cs
                JOIN {course} c ON c.id = cs.course
                JOIN {course_modules} cm ON cm.section = cs.id AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')
                JOIN {quiz} q ON q.id = cm.instance
                JOIN {quiz_attempts} qa ON qa.quiz = q.id
                WHERE c.visible = 1 AND qa.state IN ('finished', 'inprogress')
                ORDER BY c.fullname, cs.section";
        
        return $DB->get_records_sql($sql);
    }
    
    /**
     * Get quizzes by course ID
     */
    public function get_quizzes_by_course($courseid) {
        global $DB;
        
        if (!$courseid) {
            return [];
        }
        
        $sql = "SELECT DISTINCT q.id, q.name, q.course
                FROM {quiz} q
                JOIN {quiz_attempts} qa ON qa.quiz = q.id
                WHERE q.course = ? AND qa.state IN ('finished', 'inprogress')
                ORDER BY q.name";
        
        return $DB->get_records_sql($sql, [$courseid]);
    }
    
    /**
     * Get unique users who have quiz attempts
     */
    public function get_unique_users() {
        global $DB;
        
        $sql = "SELECT DISTINCT u.id, CONCAT(u.firstname, ' ', u.lastname) as fullname
                FROM {user} u
                JOIN {quiz_attempts} qa ON qa.userid = u.id
                WHERE u.deleted = 0 AND qa.state IN ('finished', 'inprogress')
                ORDER BY u.firstname, u.lastname";
        
        return $DB->get_records_sql($sql);
    }
    
    /**
     * Get unique user IDs
     */
    public function get_unique_user_ids() {
        global $DB;
        
        $sql = "SELECT DISTINCT u.id AS userid, u.id
                FROM {user} u
                JOIN {quiz_attempts} qa ON qa.userid = u.id
                WHERE u.deleted = 0 AND qa.state IN ('finished', 'inprogress')
                ORDER BY u.id";
        
        return $DB->get_records_sql($sql);
    }
    
    /**
     * Get question results in matrix format
     */
    public function get_question_results_matrix($courseid = 0, $quizid = 0, $quiztype = '', 
                                              $userid = 0, $status = '', $month = '', 
                                              $sort = 'timecreated', $dir = 'DESC') {
        global $DB;
        
        if (!$quizid) {
            error_log('Questions Dashboard: No quiz ID provided');
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        }
        
        error_log('Questions Dashboard: Getting matrix for quiz ID ' . $quizid);
        
        try {
            // First, check if the quiz exists
            $quiz_exists = $DB->get_record('quiz', ['id' => $quizid], 'id, name');
            if (!$quiz_exists) {
                error_log('Questions Dashboard Error: Quiz with ID ' . $quizid . ' does not exist');
                return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
            }
            
            // Run diagnostics first to understand the database schema
            $this->diagnose_database_schema();
            
            // Get quiz questions with slot numbers using cross-version compatibility
            $quiz_questions = qdb_get_quiz_questions_crossver($quizid);
            
            // Debug: Log quiz questions found
            error_log('Questions Dashboard Debug: Found ' . count($quiz_questions) . ' questions for quiz ID ' . $quizid);
            
            // Add slot numbers for display
            foreach ($quiz_questions as $question) {
                $question->slot_number = $question->slot;
            }
        } catch (\dml_exception $e) {
            error_log('[QDB] SQL failed getting quiz questions: ' . $e->getMessage());
            if (property_exists($e, 'debuginfo') && $e->debuginfo) {
                error_log('[QDB] Debug info: ' . $e->debuginfo);
            }
            error_log('[QDB] Function: qdb_get_quiz_questions_crossver with quizid: ' . $quizid);
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        } catch (\Exception $e) {
            error_log('Questions Dashboard Error getting quiz questions: ' . $e->getMessage());
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        }
        
        // Get user attempts - build conditions
        $params = [$quizid];
        $where_conditions = [];
        
        if ($userid) {
            $where_conditions[] = "qa.userid = ?";
            $params[] = $userid;
        }
        
        if ($month) {
            $start_time = strtotime($month . '-01 00:00:00');
            $end_time = strtotime($month . '-01 00:00:00 +1 month') - 1;
            $where_conditions[] = "qa.timefinish >= ? AND qa.timefinish <= ?";
            $params[] = $start_time;
            $params[] = $end_time;
        }
        
        $where_clause = !empty($where_conditions) ? " AND " . implode(" AND ", $where_conditions) : "";
        
        try {
            $sql_attempts = "SELECT qa.id as attemptid, qa.userid, qa.timefinish, qa.timestart,
                                   qa.attempt as attemptno,
                                   CONCAT(u.firstname, ' ', u.lastname) as username,
                                   qa.sumgrades as total_score,
                                   q.sumgrades as max_score,
                                   CASE 
                                       WHEN qa.timefinish IS NOT NULL AND qa.timestart IS NOT NULL 
                                       THEN qa.timefinish - qa.timestart
                                       ELSE NULL
                                   END as duration_seconds
                            FROM {quiz_attempts} qa
                            JOIN {user} u ON u.id = qa.userid
                            JOIN {quiz} q ON q.id = qa.quiz
                            WHERE qa.quiz = ? AND qa.state IN ('finished', 'inprogress') AND u.deleted = 0" . $where_clause . "
                            ORDER BY u.lastname, u.firstname";
            
            $user_attempts = $DB->get_records_sql($sql_attempts, $params);
            
            // Debug: Log user attempts found
            error_log('Questions Dashboard Debug: Found ' . count($user_attempts) . ' user attempts for quiz ID ' . $quizid);
        } catch (\dml_exception $e) {
            error_log('[QDB] SQL failed getting user attempts: ' . $e->getMessage());
            return ['user_attempts' => [], 'quiz_questions' => $quiz_questions ?? [], 'question_results' => []];
        } catch (\Exception $e) {
            error_log('Questions Dashboard Error getting user attempts: ' . $e->getMessage());
            return ['user_attempts' => [], 'quiz_questions' => $quiz_questions ?? [], 'question_results' => []];
        }
        
        // Get question results using safer approach
        $question_results = [];
        if (!empty($user_attempts)) {
            try {
                $attempt_ids = array_keys($user_attempts);
                if (!empty($attempt_ids)) {
                    list($attempt_sql, $attempt_params) = $DB->get_in_or_equal($attempt_ids, SQL_PARAMS_NAMED);
                    
                    $sql_results = "
                        SELECT
                            qatt.id AS qattid,
                            qatt.questionid,
                            qatt.slot,
                            qatt.maxmark,
                            qas.fraction,
                            CASE
                                WHEN qas.fraction IS NOT NULL AND qatt.maxmark IS NOT NULL
                                    THEN qas.fraction * qatt.maxmark
                                ELSE NULL
                            END AS mark,
                            qa.userid,
                            qa.id AS attemptid
                        FROM {question_attempts} qatt
                        JOIN {quiz_attempts} qa
                          ON qa.uniqueid = qatt.questionusageid
                         AND qa.state = :finished
                        JOIN (
                            SELECT qas1.questionattemptid, MAX(qas1.sequencenumber) AS maxseq
                            FROM {question_attempt_steps} qas1
                            GROUP BY qas1.questionattemptid
                        ) laststep
                          ON laststep.questionattemptid = qatt.id
                        JOIN {question_attempt_steps} qas
                          ON qas.questionattemptid = laststep.questionattemptid
                         AND qas.sequencenumber = laststep.maxseq
                        WHERE qa.id {$attempt_sql}
                        ORDER BY qa.userid, qatt.slot
                    ";
                    
                    $params = ['finished' => 'finished'] + $attempt_params;
                    $results = $DB->get_records_sql($sql_results, $params);
                    
                    foreach ($results as $result) {
                        $key = $result->userid . '_' . $result->questionid;
                        
                        // Ensure fraction values are numeric and valid
                        if (!is_numeric($result->fraction)) {
                            $result->fraction = 0;
                        }
                        
                        $result->maxfraction = 1;
                        $question_results[$key] = $result;
                    }
                }
            } catch (\Exception $e) {
                error_log('Questions Dashboard Error getting question results: ' . $e->getMessage());
            }
        }
        
        error_log('Questions Dashboard Debug: Found ' . count($question_results) . ' question results total');
        
        return [
            'user_attempts' => $user_attempts,
            'quiz_questions' => $quiz_questions,
            'question_results' => $question_results
        ];
    }

    /**
     * Diagnostic function to check database schema
     */
    public function diagnose_database_schema() {
        global $DB;
        
        $manager = $DB->get_manager();
        $diagnosis = [];
        
        // Check quiz_slots table
        $diagnosis['quiz_slots_exists'] = $manager->table_exists('quiz_slots');
        if ($diagnosis['quiz_slots_exists']) {
            $diagnosis['quiz_slots_has_questionid'] = $manager->field_exists('quiz_slots', 'questionid');
        }
        
        // Check Moodle 4.x question bank tables
        $diagnosis['question_references_exists'] = $manager->table_exists('question_references');
        $diagnosis['question_bank_entries_exists'] = $manager->table_exists('question_bank_entries');
        $diagnosis['question_versions_exists'] = $manager->table_exists('question_versions');
        
        // Log detailed information
        error_log('[QDB] Database schema diagnosis: ' . json_encode($diagnosis));
        
        return $diagnosis;
    }

    /**
     * Simple fallback method for getting basic question data when complex queries fail
     */
    public function get_simple_question_matrix($quizid) {
        global $DB;
        
        if (!$quizid) {
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        }
        
        try {
            $quiz_questions = qdb_get_quiz_questions_crossver($quizid);
            error_log('[QDB] Simple method found ' . count($quiz_questions) . ' questions');
            
            $user_attempts = $DB->get_records_sql("
                SELECT qa.id as attemptid, qa.userid, qa.timefinish, qa.timestart,
                       qa.attempt as attemptno,
                       CONCAT(u.firstname, ' ', u.lastname) as username,
                       qa.sumgrades as total_score,
                       q.sumgrades as max_score,
                       CASE 
                           WHEN qa.timefinish IS NOT NULL AND qa.timestart IS NOT NULL 
                           THEN qa.timefinish - qa.timestart
                           ELSE NULL
                       END as duration_seconds
                FROM {quiz_attempts} qa
                JOIN {user} u ON u.id = qa.userid
                JOIN {quiz} q ON q.id = qa.quiz
                WHERE qa.quiz = ? AND qa.state = 'finished' AND u.deleted = 0
                ORDER BY u.lastname, u.firstname
            ", [$quizid]);
            
            error_log('[QDB] Simple method found ' . count($user_attempts) . ' attempts');
            
            $question_results = [];
            if (!empty($user_attempts)) {
                $attempt_ids = array_keys($user_attempts);
                list($in_sql, $in_params) = $DB->get_in_or_equal($attempt_ids);
                
                $results = $DB->get_records_sql("
                    SELECT qatt.questionid, qatt.fraction, qa.userid
                    FROM {question_attempts} qatt
                    JOIN {quiz_attempts} qa ON qa.uniqueid = qatt.questionusageid
                    WHERE qa.id $in_sql
                ", $in_params);
                
                foreach ($results as $result) {
                    $key = $result->userid . '_' . $result->questionid;
                    $question_results[$key] = $result;
                }
            }
            
            error_log('[QDB] Simple method found ' . count($question_results) . ' results');
            
            return [
                'user_attempts' => $user_attempts,
                'quiz_questions' => $quiz_questions,
                'question_results' => $question_results
            ];
            
        } catch (\Exception $e) {
            error_log('[QDB] Simple method also failed: ' . $e->getMessage());
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        }
    }
    
    /**
     * Get question-specific timing data using Moodle's question engine
     * Note: Moodle doesn't have built-in per-question timing, so we calculate from question_attempt_steps
     */
    public function get_question_timings($attemptid, $questionids = []) {
        global $DB;
        
        if (empty($attemptid)) {
            return [];
        }
        
        try {
            // Get the question usage ID for this attempt
            $qa_record = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'uniqueid, timestart, timefinish');
            if (!$qa_record) {
                return [];
            }
            
            $qubaid = $qa_record->uniqueid;
            
            // Moodle stores question interactions in question_attempt_steps
            // We need to calculate time between first user interaction and final submission
            $sql = "
                SELECT 
                    qatt.questionid,
                    qatt.slot,
                    MIN(qas.timecreated) as first_step_time,
                    MAX(qas.timecreated) as last_step_time,
                    COUNT(qas.id) as total_steps,
                    -- Look for first meaningful interaction (not just 'todo' state)
                    MIN(CASE WHEN qas.state NOT IN ('todo', 'complete') 
                             AND (qas.fraction IS NOT NULL OR LENGTH(TRIM(COALESCE(qas.response_summary, ''))) > 0)
                             THEN qas.timecreated 
                        ELSE NULL END) as first_interaction,
                    -- Look for last meaningful interaction  
                    MAX(CASE WHEN qas.state NOT IN ('todo') 
                             AND (qas.fraction IS NOT NULL OR LENGTH(TRIM(COALESCE(qas.response_summary, ''))) > 0)
                             THEN qas.timecreated 
                        ELSE NULL END) as last_interaction
                FROM {question_attempts} qatt
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qatt.id
                WHERE qatt.questionusageid = :qubaid
                GROUP BY qatt.questionid, qatt.slot
                ORDER BY qatt.slot
            ";
            
            $results = $DB->get_records_sql($sql, ['qubaid' => $qubaid]);
            
            $timings = [];
            foreach ($results as $result) {
                $duration_seconds = 0;
                
                // Try to get meaningful interaction duration first
                if ($result->first_interaction && $result->last_interaction) {
                    $duration_seconds = $result->last_interaction - $result->first_interaction;
                } 
                // Fallback to all steps duration
                else if ($result->first_step_time && $result->last_step_time) {
                    $duration_seconds = $result->last_step_time - $result->first_step_time;
                }
                
                // Ensure reasonable bounds (0 seconds to 2 hours)
                $duration_seconds = max(0, min($duration_seconds, 7200));
                
                // If duration is still 0 or very small, don't show timing
                if ($duration_seconds < 1) {
                    continue;
                }
                
                $timings[$result->questionid] = [
                    'slot' => $result->slot,
                    'duration_seconds' => $duration_seconds,
                    'first_step' => $result->first_interaction ?: $result->first_step_time,
                    'last_step' => $result->last_interaction ?: $result->last_step_time,
                    'step_count' => $result->total_steps,
                    'has_meaningful_data' => !empty($result->first_interaction)
                ];
            }
            
            return $timings;
            
        } catch (\Exception $e) {
            error_log('[QDB] Error getting question timings: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if Moodle has any built-in timing functions we can use
     * Answer: No, Moodle doesn't provide direct per-question timing APIs
     */
    private function check_moodle_timing_apis() {
        // Moodle's question engine doesn't provide built-in per-question timing
        // Available timing data:
        // 1. quiz_attempts: timestart, timefinish (total quiz time)
        // 2. question_attempt_steps: timecreated for each interaction step
        // 3. No direct "time spent per question" field exists
        
        // We must calculate from question_attempt_steps timestamps
        return false;
    }

    /**
     * Get question flags for a specific user/question (from questionflags plugin)
     */
    public function get_question_flags(int $userid, int $questionid, int $quizid): string {
        global $DB;
        $flags = '';

        // Only if the table exists
        $manager = $DB->get_manager();
        if (!$manager->table_exists('local_questionflags')) {
            return $flags;
        }

        // Strictly user-specific flags
        $color = $DB->get_field('local_questionflags', 'flagcolor', [
            'userid'     => $userid,
            'questionid' => $questionid,
        ]);

        if ($color === 'blue') {
            $flags .= '<span style="color:#007cba;" title="Blue Flag">🟦</span>';
        } else if ($color === 'red') {
            $flags .= '<span style="color:#dc3545;" title="Red Flag">🟥</span>';
        }

        return $flags;
    }
    
    /**
     * Delete quiz attempts (for questions dashboard)
     */
    public function delete_quiz_attempts($attemptids) {
        if (!class_exists('\\local_quizdashboard\\quiz_manager')) {
            require_once(__DIR__ . '/../classes/quiz_manager.php');
        }
        
        $quiz_manager = new \local_quizdashboard\quiz_manager();
        
        $success_count = 0;
        $error_count = 0;
        
        foreach ($attemptids as $attemptid) {
            $attemptid = (int)$attemptid;
            if ($attemptid <= 0) {
                $error_count++;
                continue;
            }
            
            try {
                if ($quiz_manager->delete_quiz_attempt($attemptid)) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            } catch (\Exception $e) {
                error_log("Error deleting attempt {$attemptid}: " . $e->getMessage());
                $error_count++;
            }
        }
        
        return [
            'success_count' => $success_count,
            'error_count' => $error_count,
            'message' => "Successfully deleted {$success_count} attempt(s)." . 
                        ($error_count > 0 ? " {$error_count} deletion(s) failed." : "")
        ];
    }
}
