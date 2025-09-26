<?php
/**
 * FIXED Questions Manager Class for Questions Dashboard
 * File: local/quizdashboard/classes/questions_manager_fixed.php
 * 
 * This is the corrected version - copy the get_question_results_matrix method 
 * to replace the original in questions_manager.php
 */

namespace local_quizdashboard;

defined('MOODLE_INTERNAL') || die();

class questions_manager_fixed {
    
    /**
     * FIXED: Get question results in matrix format
     * This method has been improved with better error handling and SQL validation
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
            // STEP 1: Check if the quiz exists first
            $quiz_exists = $DB->get_record('quiz', ['id' => $quizid], 'id, name');
            if (!$quiz_exists) {
                error_log('Questions Dashboard Error: Quiz with ID ' . $quizid . ' does not exist');
                return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
            }
            
            // STEP 2: Get quiz questions with better error handling
            $sql_questions = "SELECT q.id, q.name, q.questiontext, q.qtype, q.defaultmark, qs.slot
                              FROM {quiz_slots} qs
                              JOIN {question} q ON q.id = qs.questionid
                              WHERE qs.quizid = ?
                              ORDER BY qs.slot";
            
            $quiz_questions = $DB->get_records_sql($sql_questions, [$quizid]);
            
            error_log('Questions Dashboard Debug: Found ' . count($quiz_questions) . ' questions for quiz ID ' . $quizid);
            
            // Validate and prepare question data
            foreach ($quiz_questions as $question) {
                $question->slot_number = isset($question->slot) ? $question->slot : 0;
                // Ensure question text is safe
                if (empty($question->questiontext)) {
                    $question->questiontext = $question->name ?? 'Question ' . $question->slot_number;
                }
            }
            
        } catch (\Exception $e) {
            error_log('Questions Dashboard Error getting quiz questions: ' . $e->getMessage());
            error_log('Questions Dashboard SQL Error: ' . $e->getTraceAsString());
            return ['user_attempts' => [], 'quiz_questions' => [], 'question_results' => []];
        }
        
        // STEP 3: Get user attempts with improved validation
        $params = [$quizid];
        $where_conditions = [];
        
        if ($userid && is_numeric($userid)) {
            $where_conditions[] = "qa.userid = ?";
            $params[] = intval($userid);
        }
        
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start_time = strtotime($month . '-01 00:00:00');
            $end_time = strtotime($month . '-01 00:00:00 +1 month') - 1;
            if ($start_time && $end_time) {
                $where_conditions[] = "qa.timefinish >= ? AND qa.timefinish <= ?";
                $params[] = $start_time;
                $params[] = $end_time;
            }
        }
        
        $where_clause = !empty($where_conditions) ? " AND " . implode(" AND ", $where_conditions) : "";
        
        try {
            // IMPROVED SQL for user attempts
            $sql_attempts = "SELECT qa.id as attemptid, qa.userid, qa.timefinish,
                                   CONCAT(u.firstname, ' ', u.lastname) as username,
                                   qa.sumgrades as total_score,
                                   q.sumgrades as max_score
                            FROM {quiz_attempts} qa
                            JOIN {user} u ON u.id = qa.userid
                            JOIN {quiz} q ON q.id = qa.quiz
                            WHERE qa.quiz = ? AND qa.state IN ('finished', 'inprogress') AND u.deleted = 0" . $where_clause . "
                            ORDER BY u.lastname, u.firstname";
            
            $user_attempts = $DB->get_records_sql($sql_attempts, $params);
            
            error_log('Questions Dashboard Debug: Found ' . count($user_attempts) . ' user attempts for quiz ID ' . $quizid);
            
        } catch (\Exception $e) {
            error_log('Questions Dashboard Error getting user attempts: ' . $e->getMessage());
            error_log('Questions Dashboard SQL: ' . $sql_attempts);
            error_log('Questions Dashboard Params: ' . print_r($params, true));
            return ['user_attempts' => [], 'quiz_questions' => $quiz_questions ?? [], 'question_results' => []];
        }
        
        // STEP 4: Get question results with improved SQL and error handling
        $question_results = [];
        if (!empty($user_attempts)) {
            foreach ($user_attempts as $attempt) {
                try {
                    $attempt_id = $attempt->attemptid;
                    
                    // FIXED: Better SQL query for question results
                    // Get the latest (final) step for each question attempt
                    $sql_results = "SELECT qas.id, qatt.questionid, qas.fraction, qas.maxfraction, qatt.slot,
                                          qa.userid, qa.quiz
                                   FROM {question_attempt_steps} qas
                                   JOIN {question_attempts} qatt ON qatt.id = qas.questionattemptid
                                   JOIN {quiz_attempts} qa ON qa.uniqueid = qatt.questionusageid
                                   WHERE qa.id = ? 
                                   AND qas.sequencenumber = (
                                       SELECT MAX(qas2.sequencenumber) 
                                       FROM {question_attempt_steps} qas2 
                                       WHERE qas2.questionattemptid = qas.questionattemptid
                                   )
                                   ORDER BY qatt.slot";
                    
                    $results = $DB->get_records_sql($sql_results, [$attempt_id]);
                    
                    foreach ($results as $result) {
                        $key = $attempt->userid . '_' . $result->questionid;
                        $result->userid = $attempt->userid;
                        
                        // Ensure fraction values are numeric and valid
                        if (!is_numeric($result->fraction)) {
                            $result->fraction = 0;
                        }
                        if (!is_numeric($result->maxfraction) || $result->maxfraction == 0) {
                            $result->maxfraction = 1;
                        }
                        
                        $question_results[$key] = $result;
                    }
                    
                } catch (\Exception $e) {
                    error_log('Questions Dashboard Error getting question results for attempt ' . $attempt_id . ': ' . $e->getMessage());
                    continue; // Skip this attempt but continue with others
                }
            }
        }
        
        error_log('Questions Dashboard Debug: Found ' . count($question_results) . ' question results total');
        
        return [
            'user_attempts' => $user_attempts,
            'quiz_questions' => $quiz_questions,
            'question_results' => $question_results
        ];
    }
}
