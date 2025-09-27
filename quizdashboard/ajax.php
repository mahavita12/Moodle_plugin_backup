<?php
// MUST be the very first bytes in the file (no BOM, no spaces above)
if (!defined('AJAX_SCRIPT'))          define('AJAX_SCRIPT', true);
if (!defined('NO_OUTPUT_BUFFERING'))  define('NO_OUTPUT_BUFFERING', true);
if (!defined('NO_DEBUG_DISPLAY'))     define('NO_DEBUG_DISPLAY', true);

// Start a buffer immediately to capture any accidental output
ob_start();

require_once(__DIR__ . '/../../config.php');
require_login();
require_sesskey();
$PAGE->set_context(context_system::instance());
\core\session\manager::write_close();

// Now that we're in control, send the JSON header
header('Content-Type: application/json; charset=utf-8');

// Convert any uncaught exceptions into clean JSON (no HTML)
set_exception_handler(function(Throwable $e) {
    // Discard anything that slipped into the buffer
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'server_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Route
$action = required_param('action', PARAM_ALPHAEXT);

try {
    global $DB;

    // Utility: resolve course and check capability
    $require_for = function(int $attemptid, string $cap) use ($DB) : context {
        $courseid = $DB->get_field_sql(
            'SELECT c.id
               FROM {course} c
               JOIN {quiz} q  ON q.course = c.id
               JOIN {quiz_attempts} qa ON qa.quiz = q.id
              WHERE qa.id = :attemptid',
            ['attemptid' => $attemptid]
        );
        if (!$courseid) {
            throw new moodle_exception('invalidrecord', 'error', '', 'quiz_attempts');
        }
        $ctx = context_course::instance($courseid);
        require_capability($cap, $ctx);
        return $ctx;
    };

    switch ($action) {
        case 'show_key_source':
            $k1 = get_config('local_quizdashboard', 'openai_api_key');
            $k2 = get_config('openai_api_key');
            global $CFG;
            $k3 = isset($CFG->openai_api_key) ? $CFG->openai_api_key : '';

            $mask = function($k) {
                // Handle cases where get_config returns an object instead of a string
                if (is_object($k) && isset($k->value)) {
                    $k = $k->value;
                } elseif (is_object($k)) {
                    $k = '[object: ' . get_class($k) . ']';
                }
                
                $k = trim((string)$k);
                if ($k === '' || $k === '[object: stdClass]') return '[empty]';
                
                return substr($k,0,4) . '…(' . strlen($k) . ' chars)';
            };

            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'plugin_scoped' => $mask($k1),
                'global_cfg'    => $mask($k2),
                'cfg_php'       => $mask($k3),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'poke':
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode(['success' => true, 'message' => 'ajax.php OK'], JSON_UNESCAPED_UNICODE);
            exit;

        case 'delete_question_attempts':
            // Handle delete requests from Questions Dashboard
            $attemptids_param = required_param('attemptids', PARAM_TEXT);
            $attemptids = array_filter(array_map('trim', explode(',', $attemptids_param)));
            
            if (empty($attemptids)) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'No attempts specified.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Load questions manager
            if (!class_exists('\\local_quizdashboard\\questions_manager')) {
                require_once(__DIR__ . '/classes/questions_manager.php');
            }
            $questions_manager = new \local_quizdashboard\questions_manager();
            
            $result = $questions_manager->delete_quiz_attempts($attemptids);
            
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'deleted' => $result['success_count'],
                'errors' => $result['error_count']
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'delete_attempts':
            $attemptids_param = required_param('attemptids', PARAM_TEXT);
            $attemptids = array_filter(array_map('trim', explode(',', $attemptids_param)));
            
            if (empty($attemptids)) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'No attempts specified.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Load quiz manager
            if (!class_exists('\\local_quizdashboard\\quiz_manager')) {
                require_once(__DIR__ . '/classes/quiz_manager.php');
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
                    $require_for($attemptid, 'mod/quiz:deleteattempts');
                    
                    if ($quiz_manager->delete_quiz_attempt($attemptid)) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                } catch (Exception $e) {
                    error_log("Error deleting attempt {$attemptid}: " . $e->getMessage());
                    $error_count++;
                }
            }
            
            $message = "Successfully deleted {$success_count} attempt(s).";
            if ($error_count > 0) {
                $message .= " {$error_count} deletion(s) failed.";
            }
            
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'message' => $message,
                'deleted' => $success_count,
                'errors' => $error_count
            ], JSON_UNESCAPED_UNICODE);
            exit;





        case 'trash_attempts':
            $attemptids_param = required_param('attemptids', PARAM_TEXT);
            $attemptids = array_filter(array_map('trim', explode(',', $attemptids_param)));
            
            if (empty($attemptids)) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'No attempts specified.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $success_count = 0;
            $error_count = 0;
            
            foreach ($attemptids as $attemptid) {
                $attemptid = (int)$attemptid;
                if ($attemptid <= 0) {
                    $error_count++;
                    continue;
                }
                
                try {
                    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, state, timefinish, quiz, userid');
                    if (!$attempt) {
                        $error_count++;
                        continue;
                    }
                    
                    if ($attempt->state === 'abandoned') {
                        $success_count++;
                        continue;
                    }
                    
                    $courseid = $DB->get_field_sql(
                        'SELECT c.id FROM {course} c JOIN {quiz} q ON q.course = c.id WHERE q.id = :quizid',
                        ['quizid' => $attempt->quiz]
                    );
                    
                    if (!$courseid) {
                        $error_count++;
                        continue;
                    }
                    
                    $context = context_course::instance($courseid);
                    if (!has_capability('mod/quiz:grade', $context)) {
                        $error_count++;
                        continue;
                    }
                    
                    $transaction = $DB->start_delegated_transaction();
                    
                    try {
                        // Create backup record if it doesn't exist
                        $existing_backup = $DB->get_record('local_quizdashboard_trash_backup', ['attempt_id' => $attemptid]);
                        
                        if (!$existing_backup) {
                            $backup_record = new \stdClass();
                            $backup_record->attempt_id = $attemptid;
                            $backup_record->original_state = substr($attempt->state, 0, 50);
                            $backup_record->original_timefinish = $attempt->timefinish;
                            $backup_record->trashed_time = time();
                            
                            $DB->insert_record('local_quizdashboard_trash_backup', $backup_record);
                        }
                        
                        $DB->set_field('quiz_attempts', 'state', 'abandoned', ['id' => $attemptid]);
                        $transaction->allow_commit();
                        $success_count++;
                        
                    } catch (Exception $db_error) {
                        $transaction->rollback($db_error);
                        $error_count++;
                    }
                    
                } catch (Exception $e) {
                    $error_count++;
                }
            }
            
            $message = "Successfully moved {$success_count} attempt(s) to trash.";
            if ($error_count > 0) {
                $message .= " {$error_count} operation(s) failed.";
            }
            
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'message' => $message,
                'trashed' => $success_count,
                'errors' => $error_count
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'restore_attempts':
            $attemptids_param = required_param('attemptids', PARAM_TEXT);
            $attemptids = array_filter(array_map('trim', explode(',', $attemptids_param)));
            
            if (empty($attemptids)) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'No attempts specified.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $success_count = 0;
            $error_count = 0;
            
            foreach ($attemptids as $attemptid) {
                $attemptid = (int)$attemptid;
                if ($attemptid <= 0) {
                    $error_count++;
                    continue;
                }
                
                try {
                    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, state, quiz, userid, timestart');
                    if (!$attempt || $attempt->state !== 'abandoned') {
                        $error_count++;
                        continue;
                    }
                    
                    $courseid = $DB->get_field_sql(
                        'SELECT c.id FROM {course} c JOIN {quiz} q ON q.course = c.id WHERE q.id = :quizid',
                        ['quizid' => $attempt->quiz]
                    );
                    
                    if (!$courseid) {
                        $error_count++;
                        continue;
                    }
                    
                    $context = context_course::instance($courseid);
                    if (!has_capability('mod/quiz:grade', $context)) {
                        $error_count++;
                        continue;
                    }
                    
                    $transaction = $DB->start_delegated_transaction();
                    
                    try {
                        $backup = $DB->get_record('local_quizdashboard_trash_backup', ['attempt_id' => $attemptid]);
                        
                        if ($backup) {
                            $DB->execute(
                                "UPDATE {quiz_attempts} SET state = ?, timefinish = ? WHERE id = ?",
                                [$backup->original_state, $backup->original_timefinish, $attemptid]
                            );
                            $DB->delete_records('local_quizdashboard_trash_backup', ['attempt_id' => $attemptid]);
                        } else {
                            $estimated_finish = $attempt->timestart ? $attempt->timestart + 3600 : time();
                            $DB->execute(
                                "UPDATE {quiz_attempts} SET state = 'finished', timefinish = ? WHERE id = ?",
                                [$estimated_finish, $attemptid]
                            );
                        }
                        
                        $transaction->allow_commit();
                        $success_count++;
                        
                    } catch (Exception $db_error) {
                        $transaction->rollback($db_error);
                        $error_count++;
                    }
                    
                } catch (Exception $e) {
                    $error_count++;
                }
            }
            
            $message = "Successfully restored {$success_count} attempt(s) from trash.";
            if ($error_count > 0) {
                $message .= " {$error_count} operation(s) failed.";
            }
            
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'message' => $message,
                'restored' => $success_count,
                'errors' => $error_count
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'export_attempts':
            $attemptids_param = required_param('attemptids', PARAM_TEXT);
            $attemptids = array_filter(array_map('trim', explode(',', $attemptids_param)));
            
            if (empty($attemptids)) {
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'No attempts specified.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            try {
                $attemptid = (int)$attemptids[0];
                $require_for($attemptid, 'mod/quiz:viewreports');
                
                if (!class_exists('\\local_quizdashboard\\quiz_manager')) {
                    require_once(__DIR__ . '/classes/quiz_manager.php');
                }
                $quiz_manager = new \local_quizdashboard\quiz_manager();
                
                $export_data = $quiz_manager->export_attempts_data($attemptids);
                
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode([
                    'success' => true,
                    'message' => 'Export completed successfully. ' . $export_data['record_count'] . ' records exported.',
                    'export_url' => $export_data['download_url'],
                    'filename' => $export_data['filename']
                ], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                error_log("Error exporting attempts: " . $e->getMessage());
                while (ob_get_level() > 0) { @ob_end_clean(); }
                echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;

        // Individual attempt actions (require attemptid parameter)
        case 'get_ai_likelihood':
            $attemptid = required_param('attemptid', PARAM_INT);
            $require_for($attemptid, 'mod/quiz:grade');
            if (!class_exists('\\local_quizdashboard\\essay_grader')) {
                require_once(__DIR__ . '/classes/essay_grader.php');
            }
            $grader = new \local_quizdashboard\essay_grader();
            $likelihood = $grader->get_or_detect_ai_likelihood($attemptid);
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode(['success' => true, 'likelihood' => $likelihood], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;

        case 'auto_grade':
            $attemptid = required_param('attemptid', PARAM_INT);
            $level = optional_param('level', 'general', PARAM_ALPHA);
            $require_for($attemptid, 'mod/quiz:grade');
            if (!class_exists('\\local_quizdashboard\\essay_grader')) {
                require_once(__DIR__ . '/classes/essay_grader.php');
            }
            $grader = new \local_quizdashboard\essay_grader();
            error_log('[quizdashboard ajax] enter auto_grade attemptid=' . $attemptid . ' level=' . $level);
            try {
                $result = $grader->auto_grade_attempt($attemptid, $level);
                while (ob_get_level() > 0) { @ob_end_clean(); }
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                error_log('[quizdashboard ajax] auto_grade result bytes=' . strlen((string)$json));
                echo $json;
            } catch (Throwable $ex) {
                error_log('[quizdashboard ajax] auto_grade exception: ' . $ex->getMessage());
                while (ob_get_level() > 0) { @ob_end_clean(); }
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'exception', 'message' => $ex->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            exit;

        case 'view_feedback':
            $attemptid = required_param('attemptid', PARAM_INT);
            $require_for($attemptid, 'mod/quiz:viewreports');
            if (!class_exists('\\local_quizdashboard\\essay_grader')) {
                require_once(__DIR__ . '/classes/essay_grader.php');
            }
            $grader = new \local_quizdashboard\essay_grader();
            $grading = $grader->get_grading_result($attemptid);
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!empty($grading) && !empty($grading->feedback_html)) {
                header('Content-Type: text/html; charset=utf-8');
                echo $grading->feedback_html;
            } else {
                echo json_encode(['success' => false, 'message' => 'No feedback found'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            exit;

        case 'generate_homework':
            $attemptid = required_param('attemptid', PARAM_INT);
            $level = optional_param('level', 'general', PARAM_ALPHA);
            $require_for($attemptid, 'mod/quiz:grade');
            if (!class_exists('\\local_quizdashboard\\essay_grader')) {
                require_once(__DIR__ . '/classes/essay_grader.php');
            }
            $grader = new \local_quizdashboard\essay_grader();
            $result = $grader->generate_homework_for_attempt($attemptid, $level);
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
            $level = optional_param('level', 'general', PARAM_ALPHA);
            $require_for($attemptid, 'mod/quiz:grade');
            if (!class_exists('\\local_quizdashboard\\resubmission_grader')) {
                require_once(__DIR__ . '/classes/resubmission_grader.php');
            }
            $grader = new \local_quizdashboard\resubmission_grader();
            error_log('[quizdashboard ajax] enter grade_resubmission attemptid=' . $attemptid . ' level=' . $level);
            try {
                $result = $grader->process_resubmission($attemptid, $level);
                if (is_array($result) && !empty($result['success'])) {
                    $result['message'] = isset($result['is_penalty']) ? 'Copy penalty applied for resubmission.' : 'Resubmission graded successfully using comparing feedback.';
                    $result['is_resubmission'] = true;
                }
                while (ob_get_level() > 0) { @ob_end_clean(); }
                $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                error_log('[quizdashboard ajax] grade_resubmission result bytes=' . strlen((string)$json));
                echo $json;
            } catch (Throwable $ex) {
                error_log('[quizdashboard ajax] grade_resubmission exception: ' . $ex->getMessage());
                while (ob_get_level() > 0) { @ob_end_clean(); }
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'exception', 'message' => $ex->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            exit;

        default:
            throw new moodle_exception('invalidparameter', 'error', '', 'Unknown action: ' . $action);
    }
} catch (Throwable $e) {
    error_log('[quizdashboard ajax] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'bad_request',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}