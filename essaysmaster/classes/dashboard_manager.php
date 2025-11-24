<?php
// This file is part of Moodle - http://moodle.org/

namespace local_essaysmaster;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/tablelib.php');

class dashboard_manager {

    public function get_accessible_courses() {
        global $DB, $USER;
        
        try {
            $context = \context_system::instance();
            
            if (has_capability('local/essaysmaster:viewallstudents', $context)) {
                $sql = "SELECT DISTINCT c.*
                        FROM {course} c
                        JOIN {quiz} q ON q.course = c.id
                        WHERE c.visible = 1
                        ORDER BY c.fullname";
                
                return $DB->get_records_sql($sql);
            } else {
                $enrolled_courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname');
                
                $accessible_courses = [];
                foreach ($enrolled_courses as $course) {
                    $course_context = \context_course::instance($course->id);
                    if (has_capability('local/essaysmaster:viewdashboard', $course_context)) {
                        $accessible_courses[$course->id] = $course;
                    }
                }
                
                return $accessible_courses;
            }
        } catch (\Exception $e) {
            error_log('Error in get_accessible_courses: ' . $e->getMessage());
            return [];
        }
    }

    public function get_student_progress($courseid = 0, $status = '', $search = '', $month = '', $userid = 0, $per_page = 25, $page = 1, $quizid = 0, $categoryid = 0, $excludestaff = false) {
        global $DB;
        
        try {
            // Enhanced implementation - get student progress data with attempt info using named parameters
            $sql = "SELECT DISTINCT 
                        s.id as session_id,
                        s.user_id,
                        s.current_level,
                        s.feedback_rounds_completed,
                        s.status as session_status,
                        s.timemodified,
                        s.attempt_id,
                        qa.attempt as student_attempt_number,
                        u.firstname,
                        u.lastname,
                        u.email,
                        c.id as course_id,
                        c.fullname as course_name,
                        c.category as course_category_id,
                        cat.name as category_name,
                        q.id as quiz_id,
                        q.name as quiz_name
                    FROM {local_essaysmaster_sessions} s
                    JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                    JOIN {quiz} q ON q.id = qa.quiz
                    JOIN {course} c ON c.id = q.course
                    JOIN {course_categories} cat ON cat.id = c.category
                    JOIN {user} u ON u.id = s.user_id
                    WHERE u.deleted = 0 AND c.visible = 1";
            
            $params = [];
            
            // Course filter
            if ($courseid > 0) {
                $sql .= " AND c.id = :courseid";
                $params['courseid'] = $courseid;
            }
            
            // Status filter
            if (!empty($status)) {
                switch ($status) {
                    case 'not_started':
                        $sql .= " AND s.current_level = 1 AND s.feedback_rounds_completed = 0";
                        break;
                    case 'in_progress':
                        $sql .= " AND s.status = 'active' AND s.feedback_rounds_completed < 6";
                        break;
                    case 'completed':
                        $sql .= " AND (s.status = 'completed' OR s.feedback_rounds_completed >= 6)";
                        break;
                }
            }
            
            // User ID filter (matching Quiz Dashboard)
            if ($userid > 0) {
                $sql .= " AND u.id = :userid";
                $params['userid'] = $userid;
            }

            // Category filter
            if ($categoryid > 0) {
                $sql .= " AND c.category = :categoryid";
                $params['categoryid'] = $categoryid;
            }

            // Quiz filter
            if ($quizid > 0) {
                $sql .= " AND q.id = :quizid";
                $params['quizid'] = $quizid;
            }

            // Exclude Staff Filter
            if ($excludestaff) {
                global $CFG;
                // Exclude site admins
                $siteadmins = explode(',', $CFG->siteadmins);
                if (!empty($siteadmins)) {
                    list($adminsql, $adminparams) = $DB->get_in_or_equal($siteadmins, SQL_PARAMS_NAMED, 'admin', false);
                    $sql .= " AND u.id $adminsql";
                    $params = array_merge($params, $adminparams);
                }

                // Exclude users with staff roles in the course context
                $sql .= " AND NOT EXISTS (
                    SELECT 1
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ra.contextid = ctx.id
                    WHERE ra.userid = u.id
                    AND ctx.contextlevel = 50 
                    AND ctx.instanceid = c.id
                    AND ra.roleid IN (1, 2, 3, 4)
                )";
            }
            
            // Search filter - handles exact student name match from dropdown
            if (!empty($search)) {
                $sql .= " AND CONCAT(u.firstname, ' ', u.lastname) = :search";
                $params['search'] = $search;
            }
            
            // Month filter (matching Quiz Dashboard implementation)
            if (!empty($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $start_time = strtotime($month . '-01 00:00:00');
                $end_time = strtotime($month . '-01 00:00:00 +1 month') - 1;
                $sql .= " AND s.timemodified >= :month_start AND s.timemodified <= :month_end";
                $params['month_start'] = $start_time;
                $params['month_end'] = $end_time;
            }
            
            $sql .= " ORDER BY s.timemodified DESC";
            
            // Add pagination with limits
            if ($per_page > 0) {
                $offset = ($page - 1) * $per_page;
                $records = $DB->get_records_sql($sql, $params, $offset, $per_page);
            } else {
                $records = $DB->get_records_sql($sql, $params);
            }
            
            // Process records
            $progress_data = [];
            foreach ($records as $record) {
                $progress_item = new \stdClass();
                $progress_item->session_id = $record->session_id;
                $progress_item->user_id = $record->user_id;
                $progress_item->course_id = $record->course_id;
                $progress_item->attempt_id = $record->attempt_id;
                $progress_item->student_name = $record->firstname . ' ' . $record->lastname;
                $progress_item->student_email = $record->email;
                $progress_item->course_name = $record->course_name;
                $progress_item->student_attempt_number = isset($record->student_attempt_number) ? (int)$record->student_attempt_number : null;
                $progress_item->quiz_name = $record->quiz_name;
                $progress_item->category_name = $record->category_name;
                $progress_item->quiz_id = $record->quiz_id;
                $progress_item->current_round = $record->current_level;
                $progress_item->total_rounds = 6;
                $progress_item->rounds_completed = $record->feedback_rounds_completed;
                
                // Calculate status
                if ($record->feedback_rounds_completed >= 6 || $record->session_status == 'completed') {
                    $progress_item->status = 'completed';
                    $progress_item->status_text = 'Completed';
                } else if ($record->feedback_rounds_completed > 0 || $record->current_level > 1) {
                    $progress_item->status = 'in_progress';
                    $progress_item->status_text = 'In Progress';
                } else {
                    $progress_item->status = 'not_started';
                    $progress_item->status_text = 'Not Started';
                }
                
                // Get round durations and validation results
                $round_data = $this->get_round_details($record->attempt_id);
                $progress_item->round_durations = $round_data['durations'];
                $progress_item->validation_results = $round_data['validation_results'];
                
                $progress_item->latest_score = null; // Will implement later
                $progress_item->last_activity = userdate($record->timemodified);
                
                $progress_data[] = $progress_item;
            }
            
            return $progress_data;
            
        } catch (\Exception $e) {
            error_log('Error in get_student_progress: ' . $e->getMessage());
            return [];
        }
    }

    public function get_quiz_configurations($courseid = 0, $categoryid = 0) {
        global $DB;
        
        try {
            // Get only quizzes that have essay-type questions (schema-agnostic approach)
            $essay_quiz_ids = $this->get_quiz_ids_with_essay();
            
            if (empty($essay_quiz_ids)) {
                return []; // No essay quizzes found
            }
            
            // Build the main query for quizzes with essay questions
            list($insql, $inparams) = $DB->get_in_or_equal($essay_quiz_ids, SQL_PARAMS_NAMED);
            
            $sql = "SELECT DISTINCT 
                        q.id as quiz_id,
                        q.name as quiz_name,
                        q.course as course_id,
                        c.fullname as course_name,
                        c.category as course_category_id,
                        cat.name as category_name,
                        qc.is_enabled,
                        qc.validation_thresholds,
                        qc.max_attempts_per_round,
                        qc.timemodified as config_modified
                    FROM {quiz} q
                    JOIN {course} c ON c.id = q.course
                    JOIN {course_categories} cat ON cat.id = c.category
                    LEFT JOIN {local_essaysmaster_quiz_config} qc ON qc.quiz_id = q.id
                    WHERE q.id $insql
                    AND c.visible = 1";
            
            $params = $inparams;
            
            // Course filter
            if ($courseid > 0) {
                $sql .= " AND q.course = :courseid";
                $params['courseid'] = $courseid;
            }
            // Category filter
            if ($categoryid > 0) {
                $sql .= " AND c.category = :categoryid";
                $params['categoryid'] = $categoryid;
            }
            
            $sql .= " ORDER BY c.fullname, q.name LIMIT 100";
            
            $records = $DB->get_records_sql($sql, $params);
            
            // Process records
            $config_data = [];
            if ($records) {
                foreach ($records as $record) {
                    $config_item = new \stdClass();
                    $config_item->quiz_id = $record->quiz_id;
                    $config_item->quiz_name = $record->quiz_name;
                    $config_item->course_name = $record->course_name;
                    $config_item->category_name = $record->category_name ?? null;
                    $config_item->course_category_id = $record->course_category_id ?? null;
                    $config_item->course_id = $record->course_id;
                    
                    // Default enabled if no explicit config
                    $config_item->is_enabled = $record->is_enabled !== null ? (bool)$record->is_enabled : true;
                    $config_item->status_text = $config_item->is_enabled ? 
                        'Essays Master Enabled' : 
                        'Essays Master Disabled';
                    
                    // Parse validation thresholds
                    if ($record->validation_thresholds) {
                        $thresholds = json_decode($record->validation_thresholds, true);
                        $config_item->round_2_threshold = $thresholds['round_2'] ?? 50;
                        $config_item->round_4_threshold = $thresholds['round_4'] ?? 50;
                        $config_item->round_6_threshold = $thresholds['round_6'] ?? 50;
                    } else {
                        $config_item->round_2_threshold = 50;
                        $config_item->round_4_threshold = 50;
                        $config_item->round_6_threshold = 50;
                    }
                    
                    $config_item->max_attempts_per_round = $record->max_attempts_per_round ?? 3;
                    
                    $config_data[] = $config_item;
                }
            }
            
            return $config_data;
            
        } catch (\Exception $e) {
            error_log('Error in get_quiz_configurations: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get round details including durations and validation results for an attempt
     */
    public function get_round_details($attempt_id) {
        global $DB;
        
        try {
            // Get quiz attempt start time for R1 duration calculation
            $attempt_record = $DB->get_record('quiz_attempts', ['id' => $attempt_id], 'timestart');
            $quiz_start_time = $attempt_record ? $attempt_record->timestart : null;
            
            // Get all feedback records for this attempt ordered by round
            $feedback_records = $DB->get_records('local_essaysmaster_feedback', 
                ['attempt_id' => $attempt_id], 
                'round_number ASC', 
                'round_number, completion_score, feedback_generated_time, level_type, feedback_html');
            
            $durations = [];
            $validation_results = [];
            $previous_time = null;
            
            if ($feedback_records) {
                foreach ($feedback_records as $feedback) {
                    $round = $feedback->round_number;
                    
                    // Calculate duration based on round number
                    if ($round == 1 && $quiz_start_time && $feedback->feedback_generated_time) {
                        // R1 Time = Quiz start time to R1 submission time
                        $duration_seconds = $feedback->feedback_generated_time - $quiz_start_time;
                        $durations[$round] = $this->format_duration($duration_seconds);
                    } elseif ($previous_time !== null && $feedback->feedback_generated_time) {
                        // R2-R6 Time = Previous round completion to current round completion
                        $duration_seconds = $feedback->feedback_generated_time - $previous_time;
                        $durations[$round] = $this->format_duration($duration_seconds);
                    } else {
                        $durations[$round] = '-'; // No time data available
                    }
                    
                    // Extract validation results
                    $validation_results[$round] = [
                        'score' => $feedback->completion_score ?? '-',
                        'passed' => $this->is_validation_passed($feedback->feedback_html ?? '', $feedback->completion_score ?? 0, $round)
                    ];
                    
                    $previous_time = $feedback->feedback_generated_time;
                }
            }
            
            // Fill in missing rounds with placeholders
            for ($i = 1; $i <= 6; $i++) {
                if (!isset($durations[$i])) {
                    $durations[$i] = '-';
                }
                if (!isset($validation_results[$i])) {
                    $validation_results[$i] = ['score' => '-', 'passed' => null];
                }
            }
            
            return [
                'durations' => $durations,
                'validation_results' => $validation_results
            ];
            
        } catch (\Exception $e) {
            error_log('Error in get_round_details for attempt ' . $attempt_id . ': ' . $e->getMessage());
            
            // Return default structure on error
            $durations = [];
            $validation_results = [];
            for ($i = 1; $i <= 6; $i++) {
                $durations[$i] = '-';
                $validation_results[$i] = ['score' => '-', 'passed' => null];
            }
            
            return [
                'durations' => $durations,
                'validation_results' => $validation_results
            ];
        }
    }
    
    /**
     * Format duration in seconds to human readable format
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }
    
    /**
     * Check if validation passed based on feedback content and score
     */
    private function is_validation_passed($feedback_html, $score, $round) {
        // Check for validation keywords in feedback
        if (strpos($feedback_html, 'PASSED') !== false || strpos($feedback_html, 'passed') !== false) {
            return true;
        }
        if (strpos($feedback_html, 'FAILED') !== false || strpos($feedback_html, 'failed') !== false) {
            return false;
        }
        
        // For validation rounds (2, 4, 6), check if score meets threshold
        if (in_array($round, [2, 4, 6])) {
            return floatval($score) >= 50; // Unified threshold
        }
        
        // For non-validation rounds, consider passed if score is provided
        return $score !== null && floatval($score) > 0;
    }

    public function get_dashboard_statistics($courseid = 0) {
        global $DB;
        
        try {
            $params = [];
            $where_condition = '';
            
            if ($courseid > 0) {
                $where_condition = " AND q.course = :courseid";
                $params['courseid'] = $courseid;
            }
            
            $stats = [];
            
            // Total students with Essays Master sessions
            $sql = "SELECT COUNT(DISTINCT s.user_id)
                    FROM {local_essaysmaster_sessions} s
                    JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                    JOIN {quiz} q ON q.id = qa.quiz
                    JOIN {course} c ON c.id = q.course
                    JOIN {user} u ON u.id = s.user_id
                    WHERE u.deleted = 0 AND c.visible = 1 $where_condition";
            $stats['total_students'] = $DB->count_records_sql($sql, $params);
            
            // Active sessions (not completed)
            $sql = "SELECT COUNT(DISTINCT s.id)
                    FROM {local_essaysmaster_sessions} s
                    JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                    JOIN {quiz} q ON q.id = qa.quiz
                    JOIN {course} c ON c.id = q.course
                    JOIN {user} u ON u.id = s.user_id
                    WHERE s.status = 'active' 
                    AND s.feedback_rounds_completed < 6
                    AND u.deleted = 0 AND c.visible = 1 $where_condition";
            $stats['active_sessions'] = $DB->count_records_sql($sql, $params);
            
            // Completion rate
            if ($stats['total_students'] > 0) {
                $sql = "SELECT COUNT(DISTINCT s.user_id)
                        FROM {local_essaysmaster_sessions} s
                        JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                        JOIN {quiz} q ON q.id = qa.quiz
                        JOIN {course} c ON c.id = q.course
                        JOIN {user} u ON u.id = s.user_id
                        WHERE (s.status = 'completed' OR s.feedback_rounds_completed >= 6)
                        AND u.deleted = 0 AND c.visible = 1 $where_condition";
                $completed_students = $DB->count_records_sql($sql, $params);
                $stats['completion_rate'] = round(($completed_students / $stats['total_students']) * 100, 1);
            } else {
                $stats['completion_rate'] = 0;
            }
            
            // Average score placeholder
            $stats['avg_score'] = 0;
            
            return $stats;
            
        } catch (\Exception $e) {
            error_log('Error in get_dashboard_statistics: ' . $e->getMessage());
            return [
                'total_students' => 0,
                'active_sessions' => 0,
                'completion_rate' => 0,
                'avg_score' => 0
            ];
        }
    }

    public function toggle_quiz_enabled($quizid, $enabled) {
        global $DB, $USER;
        
        try {
            $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
            
            $config = $DB->get_record('local_essaysmaster_quiz_config', ['quiz_id' => $quizid]);
            
            if ($config) {
                $config->is_enabled = $enabled ? 1 : 0;
                $config->modified_by = $USER->id;
                $config->timemodified = time();
                $DB->update_record('local_essaysmaster_quiz_config', $config);
            } else {
                $config = new \stdClass();
                $config->quiz_id = $quizid;
                $config->course_id = $quiz->course;
                $config->is_enabled = $enabled ? 1 : 0;
                $config->validation_thresholds = json_encode([
                    'round_2' => 40,
                    'round_4' => 40,
                    'round_6' => 30
                ]);
                $config->max_attempts_per_round = 3;
                $config->created_by = $USER->id;
                $config->modified_by = $USER->id;
                $config->timecreated = time();
                $config->timemodified = time();
                $DB->insert_record('local_essaysmaster_quiz_config', $config);
            }
            
            return [
                'success' => true,
                'message' => 'Configuration saved successfully'
            ];
            
        } catch (\Exception $e) {
            error_log('Error in toggle_quiz_enabled: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Configuration error: ' . $e->getMessage()
            ];
        }
    }

    public function bulk_toggle_quizzes($quizids, $enabled) {
        try {
            $quiz_array = explode(',', $quizids);
            $success_count = 0;
            
            foreach ($quiz_array as $quizid) {
                $quizid = (int)trim($quizid);
                if ($quizid > 0) {
                    $result = $this->toggle_quiz_enabled($quizid, $enabled);
                    if ($result['success']) {
                        $success_count++;
                    }
                }
            }
            
            $action_text = $enabled ? 'enabled' : 'disabled';
            return [
                'success' => $success_count > 0,
                'message' => "Essays Master {$action_text} for {$success_count} quizzes."
            ];
            
        } catch (\Exception $e) {
            error_log('Error in bulk_toggle_quizzes: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Bulk toggle failed: ' . $e->getMessage()
            ];
        }
    }

    public function render_student_progress_table($progress_data, $compact = false) {
        try {
            if (empty($progress_data)) {
                return \html_writer::start_div('dashboard-table-container') .
                       \html_writer::start_tag('table', ['class' => 'dashboard-table']) .
                       \html_writer::start_tag('tbody') .
                       \html_writer::start_tag('tr') .
                       \html_writer::tag('td', 'No student progress data available', ['colspan' => '20', 'class' => 'no-data']) .
                       \html_writer::end_tag('tr') .
                       \html_writer::end_tag('tbody') .
                       \html_writer::end_tag('table') .
                       \html_writer::end_div();
            }
            
            // Start dashboard table container to match Quiz Dashboard
            $output = \html_writer::start_div('dashboard-table-container');
            $output .= \html_writer::start_tag('table', ['class' => 'dashboard-table']);
            
            // Table headers matching Quiz Dashboard style
            $output .= \html_writer::start_tag('thead');
            $output .= \html_writer::start_tag('tr');
            
            // Bulk select header
            $output .= \html_writer::tag('th', 
                \html_writer::tag('input', '', [
                    'type' => 'checkbox', 
                    'id' => 'select-all', 
                    'onchange' => 'toggleAllCheckboxes(this)'
                ]), 
                ['class' => 'bulk-select-header']
            );
            
            // Standard headers
            $output .= \html_writer::tag('th', 'Student ID');
            $output .= \html_writer::tag('th', 'Student Name');
            $output .= \html_writer::tag('th', 'Category');
            $output .= \html_writer::tag('th', 'Course');
            $output .= \html_writer::tag('th', 'Quiz Name');
            $output .= \html_writer::tag('th', 'Attempt #');
            $output .= \html_writer::tag('th', 'Round');
            $output .= \html_writer::tag('th', 'Status');
            
            if (!$compact) {
                // Add round duration columns
                for ($i = 1; $i <= 6; $i++) {
                    $output .= \html_writer::tag('th', "R{$i} Time");
                }
                // Add validation result columns - only R2, R4, R6 (real validation rounds)
                $validation_rounds = [2, 4, 6];
                foreach ($validation_rounds as $round) {
                    $output .= \html_writer::tag('th', "R{$round} Valid");
                }
                $output .= \html_writer::tag('th', 'Last Activity');
            }
            
            $output .= \html_writer::end_tag('tr');
            $output .= \html_writer::end_tag('thead');
            
            // Table body
            $output .= \html_writer::start_tag('tbody');
            
            foreach ($progress_data as $progress) {
                $output .= \html_writer::start_tag('tr');
                
                // Bulk select checkbox
                $output .= \html_writer::tag('td',
                    \html_writer::tag('input', '', [
                        'type' => 'checkbox',
                        'class' => 'row-checkbox',
                        'value' => $progress->session_id ?? 0,
                        'onchange' => 'updateSelectedCount()'
                    ]),
                    ['class' => 'bulk-select-cell']
                );
                
                // Student ID - link to user profile
                $user_profile_url = new \moodle_url('/user/profile.php', ['id' => $progress->user_id ?? 0]);
                $output .= \html_writer::tag('td', 
                    \html_writer::link($user_profile_url, $progress->user_id ?? 'Unknown ID', [
                        'class' => 'user-id-link', 
                        'target' => '_blank'
                    ])
                );
                
                // Student Name - act as filter link back to this dashboard (no external profile)
                $filter_url = new \moodle_url('/local/essaysmaster/dashboard.php', [
                    'tab' => 'students',
                    'search' => $progress->student_name ?? ''
                ]);
                $output .= \html_writer::tag('td',
                    \html_writer::link($filter_url, $progress->student_name ?? 'Unknown Student', [
                        'class' => 'user-name-link'
                    ])
                );
                
                // Category name (click to filter)
                $catlink = new \moodle_url('/local/essaysmaster/dashboard.php', [
                    'tab' => 'students',
                    'categoryid' => $progress->category_id ?? 0
                ]);
                if (!empty($progress->category_id)) {
                    $output .= \html_writer::tag('td',
                        \html_writer::link($catlink, $progress->category_name ?? '-', ['class' => 'category-link'])
                    );
                } else {
                    $output .= \html_writer::tag('td', $progress->category_name ?? '-');
                }

                // Course - filter link within dashboard
                $course_filter_url = new \moodle_url('/local/essaysmaster/dashboard.php', [
                    'tab' => 'students',
                    'course' => $progress->course_id ?? 0
                ]);
                $output .= \html_writer::tag('td',
                    \html_writer::link($course_filter_url, $progress->course_name ?? 'Unknown Course', [
                        'class' => 'course-link'
                    ])
                );
                
                // Quiz Name - filter link by quiz id
                $quiz_filter_url = new \moodle_url('/local/essaysmaster/dashboard.php', [
                    'tab' => 'students',
                    'quizid' => $progress->quiz_id ?? 0
                ]);
                $output .= \html_writer::tag('td',
                    \html_writer::link($quiz_filter_url, $progress->quiz_name ?? 'Unknown Quiz', [
                        'class' => 'quiz-link'
                    ])
                );
                $output .= \html_writer::tag('td', $progress->student_attempt_number ?? '-');
                $output .= \html_writer::tag('td', ($progress->current_round ?? 1) . '/6');
                
                // Status badge
                $status_class = 'badge ';
                switch ($progress->status ?? 'not_started') {
                    case 'completed':
                        $status_class .= 'badge-success';
                        break;
                    case 'in_progress':
                        $status_class .= 'badge-warning';
                        break;
                    default:
                        $status_class .= 'badge-secondary';
                }
                
                $output .= \html_writer::tag('td',
                    \html_writer::tag('span', $progress->status_text ?? 'Unknown', ['class' => $status_class])
                );
                
                if (!$compact) {
                    // Add round duration columns
                    for ($i = 1; $i <= 6; $i++) {
                        $duration = isset($progress->round_durations[$i]) ? $progress->round_durations[$i] : '-';
                        $output .= \html_writer::tag('td', $duration);
                    }
                    
                    // Add validation result columns - only R2, R4, R6 (real validation rounds)
                    $validation_rounds = [2, 4, 6];
                    foreach ($validation_rounds as $round) {
                        $validation = isset($progress->validation_results[$round]) ? $progress->validation_results[$round] : ['score' => '-', 'passed' => null];
                        
                        if ($validation['score'] === '-') {
                            $output .= \html_writer::tag('td', '-');
                        } else {
                            $score_text = $validation['score'] . '%';
                            if ($validation['passed'] === true) {
                                $output .= \html_writer::tag('td',
                                    \html_writer::tag('span', $score_text . ' PASS &#10004;', [
                                        'class' => 'badge badge-success', 
                                        'title' => 'Validation Passed - Score: ' . $score_text
                                    ])
                                );
                            } elseif ($validation['passed'] === false) {
                                $output .= \html_writer::tag('td',
                                    \html_writer::tag('span', $score_text . ' FAIL &#10006;', [
                                        'class' => 'badge badge-danger', 
                                        'title' => 'Validation Failed - Score: ' . $score_text
                                    ])
                                );
                            } else {
                                $output .= \html_writer::tag('td',
                                    \html_writer::tag('span', $score_text . ' PENDING', ['class' => 'badge badge-secondary'])
                                );
                            }
                        }
                    }
                    
                    $output .= \html_writer::tag('td', $progress->last_activity ?? 'Unknown');
                }
                
                $output .= \html_writer::end_tag('tr');
            }
            
            $output .= \html_writer::end_tag('tbody');
            $output .= \html_writer::end_tag('table');
            $output .= \html_writer::end_div(); // dashboard-table-container
            
            return $output;
            
        } catch (\Exception $e) {
            error_log('Error in render_student_progress_table: ' . $e->getMessage());
            return \html_writer::tag('p', 'Error rendering table: ' . $e->getMessage(), 
                ['class' => 'alert alert-danger']);
        }
    }

    public function render_quiz_config_table($config_data) {
        try {
            if (empty($config_data)) {
                return \html_writer::tag('p', 'No quiz configuration data available.', 
                    ['class' => 'alert alert-info']);
            }
            
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable quiz-config-table';
            
            // Table headers
            $table->head = [
                'Select',
                'Quiz Name',
                'Category',
                'Course',
                'Status',
                'Actions'
            ];
            
            // Table rows
            foreach ($config_data as $config) {
                $row = [];
                
                // Checkbox
                $row[] = \html_writer::checkbox('quiz_ids[]', $config->quiz_id ?? 0, false, '', 
                    ['class' => 'quiz-checkbox']);
                
                // Quiz name → clicking filters Students tab by this quiz
                $row[] = \html_writer::link(
                    new \moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students', 'quizid' => $config->quiz_id ?? 0]),
                    $config->quiz_name ?? 'Unknown Quiz'
                );
                // Category → clicking filters Students tab by this category
                if (!empty($config->course_category_id)) {
                    $row[] = \html_writer::link(
                        new \moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students', 'categoryid' => $config->course_category_id]),
                        $config->category_name ?? 'Unknown Category'
                    );
                } else {
                    $row[] = $config->category_name ?? 'Unknown Category';
                }

                // Course → clicking filters Students tab by this course
                $row[] = \html_writer::link(
                    new \moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students', 'course' => $config->course_id ?? 0]),
                    $config->course_name ?? 'Unknown Course'
                );
                
                // Status
                $status_html = \html_writer::tag('span', $config->status_text ?? 'Unknown', 
                    ['class' => 'status-indicator ' . (($config->is_enabled ?? false) ? 'enabled' : 'disabled')]);
                
                $row[] = $status_html;
                
                // Actions
                $toggle_text = ($config->is_enabled ?? false) ? 'Disable' : 'Enable';
                $toggle_class = ($config->is_enabled ?? false) ? 'btn-success' : 'btn-secondary';
                
                $actions = \html_writer::tag('button', $toggle_text, [
                    'type' => 'button',
                    'class' => "btn btn-sm {$toggle_class} toggle-quiz-btn",
                    'data-quizid' => $config->quiz_id ?? 0,
                    'data-enabled' => ($config->is_enabled ?? false) ? 0 : 1
                ]);
                
                $row[] = $actions;
                
                $table->data[] = $row;
            }
            
            return \html_writer::table($table);
            
        } catch (\Exception $e) {
            error_log('Error in render_quiz_config_table: ' . $e->getMessage());
            return \html_writer::tag('p', 'Error rendering quiz configuration table: ' . $e->getMessage(), 
                ['class' => 'alert alert-danger']);
        }
    }

    /**
     * Get all quiz IDs that contain at least one essay question (schema-agnostic approach)
     * Based on the quizdashboard plugin's implementation
     */
    private function get_quiz_ids_with_essay() {
        global $DB;
        
        try {
            $slotscols = $DB->get_columns('quiz_slots');

            if (isset($slotscols['questionid'])) {
                // Moodle 3.x schema - direct link
                $sql = "SELECT DISTINCT qs.quizid
                        FROM {quiz_slots} qs
                        JOIN {question} q ON q.id = qs.questionid
                        WHERE q.qtype = 'essay'";
                return $DB->get_fieldset_sql($sql);
            }

            // Moodle 4.x schema - question bank path
            $sql = "SELECT DISTINCT qs.quizid
                    FROM {quiz_slots} qs
                    JOIN {question_references} qr
                      ON qr.itemid = qs.id
                     AND qr.component = 'mod_quiz'
                     AND qr.questionarea = 'slot'
                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                    JOIN (
                          SELECT questionbankentryid, MAX(version) AS maxver
                            FROM {question_versions}
                        GROUP BY questionbankentryid
                         ) vmax ON vmax.questionbankentryid = qbe.id
                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id AND qv.version = vmax.maxver
                    JOIN {question} q ON q.id = qv.questionid
                   WHERE q.qtype = 'essay'";
            return $DB->get_fieldset_sql($sql);
            
        } catch (\Exception $e) {
            error_log('Error in get_quiz_ids_with_essay: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get statistics for students tab (filtered data)
     */
    public function get_student_statistics($courseid = 0, $status = '', $search = '', $month = '', $userid = 0) {
        global $DB;
        
        try {
            $base_sql = "FROM {local_essaysmaster_sessions} s
                         JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                         JOIN {quiz} q ON q.id = qa.quiz
                         JOIN {course} c ON c.id = q.course
                         JOIN {user} u ON u.id = s.user_id
                         WHERE u.deleted = 0 AND c.visible = 1";
            
            $params = [];
            
            // Course filter
            if ($courseid > 0) {
                $base_sql .= " AND c.id = :courseid";
                $params['courseid'] = $courseid;
            }
            
            // Status filter
            if (!empty($status)) {
                switch ($status) {
                    case 'not_started':
                        $base_sql .= " AND s.current_level = 1 AND s.feedback_rounds_completed = 0";
                        break;
                    case 'in_progress':
                        $base_sql .= " AND s.status = 'active' AND s.feedback_rounds_completed < 6";
                        break;
                    case 'completed':
                        $base_sql .= " AND (s.status = 'completed' OR s.feedback_rounds_completed >= 6)";
                        break;
                }
            }
            
            // User ID filter (matching Quiz Dashboard)
            if ($userid > 0) {
                $base_sql .= " AND u.id = :userid";
                $params['userid'] = $userid;
            }
            
            // Search filter - exact student name match from dropdown
            if (!empty($search)) {
                $base_sql .= " AND CONCAT(u.firstname, ' ', u.lastname) = :search";
                $params['search'] = $search;
            }
            
            // Month filter (matching Quiz Dashboard implementation)
            if (!empty($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $start_time = strtotime($month . '-01 00:00:00');
                $end_time = strtotime($month . '-01 00:00:00 +1 month') - 1;
                $base_sql .= " AND s.timemodified >= :month_start AND s.timemodified <= :month_end";
                $params['month_start'] = $start_time;
                $params['month_end'] = $end_time;
            }
            
            $stats = [];
            
            // Total filtered students
            $sql = "SELECT COUNT(DISTINCT s.user_id) " . $base_sql;
            $stats['filtered_students'] = $DB->count_records_sql($sql, $params);
            
            // Total records (for pagination)
            $sql = "SELECT COUNT(DISTINCT s.id) " . $base_sql;
            $stats['total_records'] = $DB->count_records_sql($sql, $params);
            
            // Active sessions
            $active_params = $params;
            $active_sql = $base_sql . " AND s.status = 'active' AND s.feedback_rounds_completed < 6";
            $sql = "SELECT COUNT(DISTINCT s.id) " . $active_sql;
            $stats['active_sessions'] = $DB->count_records_sql($sql, $active_params);
            
            // Completion rate
            if ($stats['filtered_students'] > 0) {
                $completed_params = $params;
                $completed_sql = $base_sql . " AND (s.status = 'completed' OR s.feedback_rounds_completed >= 6)";
                $sql = "SELECT COUNT(DISTINCT s.user_id) " . $completed_sql;
                $completed_students = $DB->count_records_sql($sql, $completed_params);
                $stats['completion_rate'] = round(($completed_students / $stats['filtered_students']) * 100, 1);
            } else {
                $stats['completion_rate'] = 0;
            }
            
            // Average score (placeholder for now)
            $stats['avg_score'] = 0;
            
            return $stats;
            
        } catch (\Exception $e) {
            error_log('Error in get_student_statistics: ' . $e->getMessage());
            return [
                'filtered_students' => 0,
                'total_records' => 0,
                'active_sessions' => 0,
                'completion_rate' => 0,
                'avg_score' => 0
            ];
        }
    }

    /**
     * Enhanced table rendering with pagination, sorting, and action buttons
     */
    public function render_enhanced_student_progress_table($progress_data, $total_records, $current_page, $per_page) {
        global $CFG;
        
        try {
            if (empty($progress_data)) {
                return \html_writer::tag('p', 'No student progress data available.', 
                    ['class' => 'alert alert-info']);
            }
            
            $output = '';
            
            // Table wrapper for responsive scrolling
            $output .= \html_writer::start_div('table-responsive student-progress-wrapper');
            
            $table = new \html_table();
            $table->attributes['class'] = 'generaltable student-progress-table sortable-table';
            $table->id = 'student-progress-table';
            
            // Enhanced table headers with sorting
            $headers = [
                \html_writer::tag('input', '', ['type' => 'checkbox', 'id' => 'select-all-students']),
                $this->get_sortable_header('Student Name', 'student_name'),
                $this->get_sortable_header('Course', 'course_name'),
                $this->get_sortable_header('Quiz', 'quiz_name'),
                $this->get_sortable_header('Round', 'current_round'),
                $this->get_sortable_header('Status', 'status')
            ];
            
            // Add round duration columns (preserving original order)
            for ($i = 1; $i <= 6; $i++) {
                $headers[] = "R{$i} Time";
            }
            
            // Add validation result columns - only R2, R4, R6 (real validation rounds)
            $validation_rounds = [2, 4, 6];
            foreach ($validation_rounds as $round) {
                $headers[] = "R{$round} Valid";
            }
            
            $headers[] = $this->get_sortable_header('Last Activity', 'last_activity');
            $headers[] = 'Actions';
            
            $table->head = $headers;
            
            // Table rows
            foreach ($progress_data as $progress) {
                $row = [];
                
                // Checkbox for bulk selection
                $row[] = \html_writer::tag('input', '', [
                    'type' => 'checkbox',
                    'class' => 'student-checkbox',
                    'data-sessionid' => $progress->session_id ?? '',
                    'data-userid' => $progress->user_id ?? ''
                ]);
                
                // Student name acts as filter link back to dashboard
                $student_url = new \moodle_url('/local/essaysmaster/dashboard.php', [
                    'tab' => 'students',
                    'search' => $progress->student_name ?? ''
                ]);
                $row[] = \html_writer::link($student_url, $progress->student_name ?? 'Unknown Student');
                
                $row[] = $progress->course_name ?? 'Unknown Course';
                $row[] = $progress->quiz_name ?? 'Unknown Quiz';
                $row[] = ($progress->current_round ?? 1) . '/6';
                
                // Status badge
                $status_class = 'badge ';
                switch ($progress->status ?? 'not_started') {
                    case 'completed':
                        $status_class .= 'badge-success';
                        break;
                    case 'in_progress':
                        $status_class .= 'badge-warning';
                        break;
                    default:
                        $status_class .= 'badge-secondary';
                }
                
                $row[] = \html_writer::tag('span', $progress->status_text ?? 'Unknown', ['class' => $status_class]);
                
                // Add round duration columns
                for ($i = 1; $i <= 6; $i++) {
                    $duration = isset($progress->round_durations[$i]) ? $progress->round_durations[$i] : '-';
                    $row[] = $duration;
                }
                
                // Add validation result columns - only R2, R4, R6 (real validation rounds)
                $validation_rounds = [2, 4, 6];
                foreach ($validation_rounds as $round) {
                    $validation = isset($progress->validation_results[$round]) ? $progress->validation_results[$round] : ['score' => '-', 'passed' => null];
                    
                    if ($validation['score'] === '-') {
                        $row[] = '-';
                    } else {
                        $score_text = $validation['score'] . '%';
                        if ($validation['passed'] === true) {
                            $row[] = \html_writer::tag('span', $score_text . ' PASS &#10004;', ['class' => 'badge badge-success', 'title' => 'Validation Passed - Score: ' . $score_text]);
                        } elseif ($validation['passed'] === false) {
                            $row[] = \html_writer::tag('span', $score_text . ' FAIL &#10006;', ['class' => 'badge badge-danger', 'title' => 'Validation Failed - Score: ' . $score_text]);
                        } else {
                            $row[] = \html_writer::tag('span', $score_text . ' PENDING', ['class' => 'badge badge-secondary']);
                        }
                    }
                }
                
                $row[] = $progress->last_activity ?? 'Unknown';
                
                // Action buttons
                $actions = '';
                $actions .= \html_writer::link($student_url, 'View', [
                    'class' => 'btn btn-sm btn-primary',
                    'title' => 'View student details'
                ]);
                $actions .= ' ';
                $actions .= \html_writer::tag('button', 'Reset', [
                    'class' => 'btn btn-sm btn-danger reset-student-btn',
                    'data-sessionid' => $progress->session_id ?? '',
                    'data-studentname' => $progress->student_name ?? 'Unknown',
                    'title' => 'Reset student progress'
                ]);
                
                $row[] = $actions;
                
                $table->data[] = $row;
            }
            
            $output .= \html_writer::table($table);
            $output .= \html_writer::end_div(); // table-responsive
            
            // Pagination controls
            $output .= $this->render_pagination($total_records, $current_page, $per_page);
            
            // Bulk actions section
            $output .= $this->render_bulk_actions();
            
            return $output;
            
        } catch (\Exception $e) {
            error_log('Error in render_enhanced_student_progress_table: ' . $e->getMessage());
            return \html_writer::tag('p', 'Error rendering student progress table: ' . $e->getMessage(), 
                ['class' => 'alert alert-danger']);
        }
    }

    /**
     * Create sortable column header
     */
    private function get_sortable_header($text, $sort_key) {
        try {
            return \html_writer::tag('a', $text, [
                'href' => '#',
                'class' => 'sortable-header',
                'data-sort' => $sort_key
            ]);
        } catch (\Exception $e) {
            error_log('Error in get_sortable_header: ' . $e->getMessage());
            return $text; // Fallback to plain text
        }
    }

    /**
     * Render pagination controls
     */
    private function render_pagination($total_records, $current_page, $per_page) {
        try {
            if ($per_page <= 0) $per_page = 25; // Safety check
            
            $total_pages = ceil($total_records / $per_page);
            
            if ($total_pages <= 1) {
                return '';
            }
            
            $output = \html_writer::start_div('pagination-wrapper');
            $output .= \html_writer::tag('p', "Showing page {$current_page} of {$total_pages} ({$total_records} total records)", 
                ['class' => 'pagination-info']);
            
            $output .= \html_writer::start_tag('nav', ['aria-label' => 'Student pagination']);
            $output .= \html_writer::start_tag('ul', ['class' => 'pagination']);
            
            // Previous page
            if ($current_page > 1) {
                $prev_url = $this->get_pagination_url($current_page - 1, $per_page);
                $output .= \html_writer::tag('li', 
                    \html_writer::link($prev_url, 'Previous', ['class' => 'page-link']),
                    ['class' => 'page-item']
                );
            }
            
            // Page numbers
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++) {
                $page_class = 'page-item' . ($i == $current_page ? ' active' : '');
                $page_url = $this->get_pagination_url($i, $per_page);
                $output .= \html_writer::tag('li',
                    \html_writer::link($page_url, $i, ['class' => 'page-link']),
                    ['class' => $page_class]
                );
            }
            
            // Next page
            if ($current_page < $total_pages) {
                $next_url = $this->get_pagination_url($current_page + 1, $per_page);
                $output .= \html_writer::tag('li',
                    \html_writer::link($next_url, 'Next', ['class' => 'page-link']),
                    ['class' => 'page-item']
                );
            }
            
            $output .= \html_writer::end_tag('ul');
            $output .= \html_writer::end_tag('nav');
            $output .= \html_writer::end_div();
            
            return $output;
            
        } catch (\Exception $e) {
            error_log('Error in render_pagination: ' . $e->getMessage());
            return ''; // Return empty string on error
        }
    }

    /**
     * Get pagination URL with current filters preserved
     */
    private function get_pagination_url($page, $per_page) {
        try {
            $params = $_GET ?? [];
            $params['page'] = $page;
            $params['per_page'] = $per_page;
            return new \moodle_url('/local/essaysmaster/dashboard.php', $params);
        } catch (\Exception $e) {
            error_log('Error in get_pagination_url: ' . $e->getMessage());
            return new \moodle_url('/local/essaysmaster/dashboard.php', ['page' => $page, 'per_page' => $per_page]);
        }
    }

    /**
     * Render bulk actions section
     */
    private function render_bulk_actions() {
        try {
            $output = \html_writer::start_div('bulk-actions-section', ['style' => 'margin-top: 20px;']);
            $output .= \html_writer::tag('h4', 'Bulk Actions');
            
            $output .= \html_writer::start_div('bulk-controls');
            $output .= \html_writer::tag('button', 'Reset Selected', [
                'id' => 'bulk-reset-btn',
                'class' => 'btn btn-danger',
                'disabled' => 'disabled'
            ]);
            $output .= \html_writer::tag('button', 'Export Selected', [
                'id' => 'bulk-export-btn', 
                'class' => 'btn btn-success',
                'disabled' => 'disabled'
            ]);
            $output .= \html_writer::tag('span', '', ['id' => 'selected-students-count', 'class' => 'selected-counter']);
            $output .= \html_writer::end_div();
            
            $output .= \html_writer::end_div();
            
            return $output;
            
        } catch (\Exception $e) {
            error_log('Error in render_bulk_actions: ' . $e->getMessage());
            return ''; // Return empty string on error
        }
    }

    /**
     * Get unique students for dropdown filter
     */
    public function get_unique_students($courseid = 0) {
        global $DB;
        
        try {
            $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, CONCAT(u.firstname, ' ', u.lastname) as fullname
                    FROM {user} u
                    JOIN {local_essaysmaster_sessions} s ON s.user_id = u.id
                    JOIN {quiz_attempts} qa ON qa.id = s.attempt_id
                    JOIN {quiz} q ON q.id = qa.quiz
                    JOIN {course} c ON c.id = q.course
                    WHERE u.deleted = 0 AND c.visible = 1";
            
            $params = [];
            
            // Course filter
            if ($courseid > 0) {
                $sql .= " AND c.id = :courseid";
                $params['courseid'] = $courseid;
            }
            
            $sql .= " ORDER BY u.lastname, u.firstname";
            
            return $DB->get_records_sql($sql, $params);
            
        } catch (\Exception $e) {
            error_log('Error in get_unique_students: ' . $e->getMessage());
            return [];
        }
    }
}