<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Personal Course';
$string['dashboard'] = 'Personal Course Dashboard';
$string['nav_dashboard'] = 'Personal Course Dashboard';

$string['col_user'] = 'Student';
$string['col_userid'] = 'User ID';
$string['col_personalcourse'] = 'Personal Course';
$string['col_quizzes'] = 'Quizzes';
$string['col_questions'] = 'Questions';
$string['col_actions'] = 'Actions';

$string['view_course'] = 'View course';
$string['no_records'] = 'No personal courses found.';

// Dashboard (extended view).
$string['col_enrolledcourses'] = 'Enrolled';
$string['col_courses'] = 'Courses';
$string['col_sections'] = 'Course sections';
$string['no_enrolments'] = 'No enrolments';
$string['no_personalcourse'] = 'Not created';
$string['no_sections'] = 'No sections';
$string['no_quizzes'] = 'No quizzes';
$string['action_forcecreate'] = 'Create Personal Course';
$string['forcecreate_success'] = 'Personal course created or already existed.';
$string['forcecreate_error'] = 'Error creating personal course: {$a}';
$string['action_rename'] = 'Rename to new format';
$string['rename_success'] = 'Personal course name updated.';
$string['rename_error'] = 'Error renaming personal course: {$a}';

// Admin-created personal quiz actions.
$string['action_createquiz'] = 'Create Personal Quiz';
$string['createquiz_success'] = 'Personal quiz created.';
$string['createquiz_error'] = 'Error creating personal quiz: {$a}';

// Create quiz page helper strings.
$string['for_user'] = 'For user: {$a}';
$string['select_source_course'] = 'Select source course';
$string['select_source_quiz'] = 'Select source quiz';

// Notifications shown after quiz attempt submissions.
$string['notify_pq_created_short'] = 'Your Personal Quiz is Live! Head to your Personal Course and start practicing.';
$string['notify_pq_exists_short'] = 'Your Personal Quiz is Waiting! Continue practicing in your Personal Course.';
$string['notify_pq_not_created_first_short'] = 'Almost there! Score 80%+ on your first attempt to unlock your personalized practice quiz.';
$string['notify_pq_not_created_next_short'] = 'Keep going! Score 40%+ to unlock your personal quiz and boost your learning.';

// Background reconcile notice.
$string['task_reconcile_scheduled'] = 'Personal Quiz updates are being applied in the background. Please refresh shortly.';
