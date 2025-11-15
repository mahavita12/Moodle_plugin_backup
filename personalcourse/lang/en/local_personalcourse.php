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
$string['notify_pq_preparing_short'] = 'Your personal quiz is being prepared and will appear shortly in your Personal Course.';

// Background reconcile notice.
$string['task_reconcile_scheduled'] = 'Personal Quiz updates are being applied in the background. Please refresh shortly.';

// Settings.
$string['settings'] = 'Personal Course settings';
$string['setting_subjectmap'] = 'Subject mapping (regex => label)';
$string['setting_subjectmap_desc'] = 'One mapping per line. Left side is a PCRE regex (delimited) or plain text; right side is the canonical Subject label. Example:\n/\\\bthinking\\\b/i => Thinking\n/\\\bmath(?:ematics)?\\\b/i => Math\n/\\\bread(?:ing)?\\\b/i => Reading\n/\\\bwriting\\\b/i => Writing';
$string['setting_coderegex'] = 'Quiz code extraction regex';
$string['setting_coderegex_desc'] = 'PCRE with one capturing group for the code. Default extracts a trailing bracket code, e.g. "(UTOC11)".';
$string['setting_defer_modinfo_rebuilds'] = 'Defer course cache rebuilds to background task';
$string['setting_defer_modinfo_rebuilds_desc'] = 'When enabled, structural changes queue a per-course rebuild performed by cron instead of during the user\'s request.';
$string['setting_modinfo_rebuild_min_interval'] = 'Rebuild dedupe interval (seconds)';
$string['setting_modinfo_rebuild_min_interval_desc'] = 'Minimum time between queued rebuilds per course (advisory). Duplicate adhoc tasks are deduped automatically.';
$string['setting_show_async_notice_on_submit'] = 'Show “preparing personal quiz” notice after submit';
$string['setting_show_async_notice_on_submit_desc'] = 'When enabled, students see a brief notice that their personal quiz is being prepared in the background.';
$string['setting_defer_view_enforcement'] = 'Defer archive visibility updates on view';
$string['setting_defer_view_enforcement_desc'] = 'When enabled, viewing a quiz will not rename/hide archived copies inline; changes are applied via background tasks.';
$string['setting_limit_cleanup_to_pcourses'] = 'Limit scheduled cleanup to personal courses';
$string['setting_limit_cleanup_to_pcourses_desc'] = 'When enabled, the scheduled sequence cleanup scans only courses created by Personal Course, reducing load.';
