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
 * Language strings for Essays Master plugin.
 *
 * @package    local_essaysmaster
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Essays Master';
$string['privacy:metadata'] = 'The Essays Master plugin stores essay versions and AI feedback to provide iterative writing improvement.';

// General strings
$string['level'] = 'level';
$string['levels'] = 'levels';
$string['enabled'] = 'Enable Essays Master';
$string['enabled_desc'] = 'Enable the Essays Master plugin globally across the site.';

// Settings page strings
$string['default_threshold'] = 'Default completion threshold';
$string['default_threshold_desc'] = 'Default percentage threshold for level completion (0-100).';
$string['default_max_levels'] = 'Default maximum levels';
$string['default_max_levels_desc'] = 'Default number of feedback levels students must complete.';
$string['default_max_revisions'] = 'Default maximum revisions per level';
$string['default_max_revisions_desc'] = 'Default maximum number of revisions allowed per level.';
$string['copy_paste_prevention'] = 'Enable copy/paste prevention';
$string['copy_paste_prevention_desc'] = 'Prevent students from copying and pasting text in Essays Master interface.';
$string['default_time_limit'] = 'Default time limit per level (minutes)';
$string['default_time_limit_desc'] = 'Optional time limit for each level. Set to 0 for no limit.';

// AI Feedback Settings
$string['ai_feedback_settings'] = 'AI Feedback Settings';
$string['ai_feedback_settings_desc'] = 'Configure AI-powered feedback generation.';
$string['ai_feedback_enabled'] = 'Enable AI feedback';
$string['ai_feedback_enabled_desc'] = 'Enable AI-powered feedback generation using the Quiz Dashboard integration.';
$string['ai_feedback_delay'] = 'AI feedback delay (seconds)';
$string['ai_feedback_delay_desc'] = 'Minimum delay between AI feedback requests to prevent spam.';

// Level-specific settings
$string['level1_settings'] = 'Level 1: Grammar & Mechanics';
$string['level1_settings_desc'] = 'Configuration for Level 1 feedback focusing on grammar, spelling, and punctuation.';
$string['level1_prompt'] = 'Level 1 AI prompt';
$string['level1_prompt_desc'] = 'Custom prompt for Level 1 AI feedback generation.';
$string['level1_default_prompt'] = 'Focus on basic grammar, spelling, and punctuation errors. Highlight specific mistakes and provide simple corrections. Identify areas where the student can improve basic writing mechanics. Format your response with specific text references using highlight tags around problematic text.';

$string['level2_settings'] = 'Level 2: Language & Style';
$string['level2_settings_desc'] = 'Configuration for Level 2 feedback focusing on vocabulary and sentence variety.';
$string['level2_prompt'] = 'Level 2 AI prompt';
$string['level2_prompt_desc'] = 'Custom prompt for Level 2 AI feedback generation.';
$string['level2_default_prompt'] = 'Analyze language sophistication, word choice, and sentence variety. Suggest more advanced vocabulary and sentence structures. Look for opportunities to improve flow, clarity, and style. Use highlight tags around areas that need vocabulary or structural improvements.';

$string['level3_settings'] = 'Level 3: Structure & Content';
$string['level3_settings_desc'] = 'Configuration for Level 3 feedback focusing on organization and content depth.';
$string['level3_prompt'] = 'Level 3 AI prompt';
$string['level3_prompt_desc'] = 'Custom prompt for Level 3 AI feedback generation.';
$string['level3_default_prompt'] = 'Evaluate overall structure, argument development, and content depth. Provide high-level organizational and analytical feedback. Assess thesis strength, evidence quality, and logical flow. Highlight structural issues using highlight tags.';

// Progress tracking
$string['progress_settings'] = 'Progress Tracking';
$string['progress_settings_desc'] = 'Configure how student progress is tracked and displayed.';
$string['detailed_progress'] = 'Enable detailed progress tracking';
$string['detailed_progress_desc'] = 'Track detailed completion requirements for each level.';
$string['show_progress_to_students'] = 'Show progress to students';
$string['show_progress_to_students_desc'] = 'Allow students to see their completion progress for each level.';

// Debug settings
$string['debug_settings'] = 'Debug & Logging';
$string['debug_settings_desc'] = 'Configure debugging and logging options.';
$string['debug_logging'] = 'Enable debug logging';
$string['debug_logging_desc'] = 'Log detailed debugging information for troubleshooting.';
$string['log_ai_interactions'] = 'Log AI interactions';
$string['log_ai_interactions_desc'] = 'Log all AI feedback requests and responses for analytics.';

// Interface strings
$string['essays_master_interface'] = 'Essays Master Interface';
$string['current_level'] = 'Current Level: {$a}';
$string['level_progress'] = 'Level {$a->level} Progress: {$a->percentage}%';
$string['completion_required'] = 'You must achieve {$a}% completion to proceed to the next level.';
$string['get_ai_feedback'] = 'Get AI Feedback';
$string['feedback_loading'] = 'Generating AI feedback...';
$string['save_draft'] = 'Save Draft';
$string['proceed_to_next_level'] = 'Proceed to Level {$a}';
$string['submit_final_essay'] = 'Submit Final Essay';
$string['essay_word_count'] = 'Words: {$a}';
$string['essay_char_count'] = 'Characters: {$a}';

// Progress indicators
$string['requirements_completed'] = 'Requirements Completed';
$string['grammar_fixes'] = 'Grammar fixes';
$string['spelling_fixes'] = 'Spelling corrections';
$string['punctuation_fixes'] = 'Punctuation improvements';
$string['vocabulary_improvements'] = 'Vocabulary enhancements';
$string['sentence_variety'] = 'Sentence variety';
$string['structure_improvements'] = 'Structure improvements';
$string['content_depth'] = 'Content depth';

// Feedback messages
$string['feedback_level1_title'] = 'Level 1 Feedback: Grammar & Mechanics';
$string['feedback_level2_title'] = 'Level 2 Feedback: Language & Style';
$string['feedback_level3_title'] = 'Level 3 Feedback: Structure & Content';
$string['feedback_generated'] = 'AI feedback generated successfully.';
$string['feedback_error'] = 'Error generating AI feedback. Please try again.';
$string['feedback_cached'] = 'Showing cached feedback from previous analysis.';

// Version management
$string['version_saved'] = 'Essay version saved successfully.';
$string['version_error'] = 'Error saving essay version.';
$string['initial_version'] = 'Initial Version';
$string['revision_number'] = 'Revision {$a}';

// Completion messages
$string['level_completed'] = 'Level {$a} completed! You can now proceed to the next level.';
$string['all_levels_completed'] = 'Congratulations! You have completed all Essays Master levels and can now submit your final essay.';
$string['submission_allowed'] = 'Your essay is ready for final submission.';
$string['submission_blocked'] = 'Complete all Essays Master levels before submitting.';

// Error messages
$string['session_not_found'] = 'Essays Master session not found.';
$string['question_not_found'] = 'Essay question not found.';
$string['invalid_attempt'] = 'Invalid quiz attempt.';
$string['access_denied'] = 'Access denied to Essays Master interface.';
$string['configuration_missing'] = 'Essays Master configuration not found for this quiz.';
$string['ai_service_unavailable'] = 'AI feedback service is currently unavailable.';

// Copy/paste prevention
$string['copypaste_blocked'] = 'Copy and paste operations are blocked in Essays Master.';
$string['copypaste_warning'] = 'Please type your content manually to ensure original work.';
$string['typing_too_fast'] = 'Unusually fast typing detected. Please ensure you are typing manually.';
$string['textarea_locked'] = 'This text area has been locked due to multiple copy/paste attempts.';

// Navigation
$string['back_to_quiz'] = 'Back to Quiz';
$string['continue_quiz'] = 'Continue with Quiz';
$string['return_to_attempt'] = 'Return to Quiz Attempt';

// Reports and analytics
$string['essays_master_reports'] = 'Essays Master Reports';
$string['student_progress_report'] = 'Student Progress Report';
$string['usage_analytics'] = 'Usage Analytics';
$string['completion_statistics'] = 'Completion Statistics';

// Capabilities
$string['essaysmaster:use'] = 'Use Essays Master interface';
$string['essaysmaster:configure'] = 'Configure Essays Master for quizzes';
$string['essaysmaster:viewreports'] = 'View Essays Master reports';
$string['essaysmaster:manage'] = 'Manage Essays Master system settings';
$string['essaysmaster:bypass'] = 'Bypass Essays Master requirements';
$string['essaysmaster:viewdashboard'] = 'View Essays Master dashboard';
$string['essaysmaster:configquizzes'] = 'Configure Essays Master quiz settings';
$string['essaysmaster:viewallstudents'] = 'View all student progress data';

// Dashboard strings
$string['dashboard'] = 'Essays Master Dashboard';
$string['dashboard_title'] = 'EssaysMaster Dashboard';
$string['student_progress'] = 'Student Progress';
$string['quiz_configuration'] = 'Quiz Configuration';
$string['overview'] = 'Overview';
$string['filter_courses'] = 'Filter by course';
$string['filter_status'] = 'Filter by status';
$string['export'] = 'Export';
$string['refresh'] = 'Refresh';
$string['student_name'] = 'Student';
$string['course'] = 'Course';
$string['quiz_name'] = 'Quiz';
$string['current_round'] = 'Round';
$string['status'] = 'Status';
$string['score'] = 'Score';
$string['last_activity'] = 'Last Activity';
$string['actions'] = 'Actions';
$string['view_details'] = 'View Details';
$string['round_of_six'] = '{$a}/6';
$string['validation_passed'] = 'Passed';
$string['validation_failed'] = 'Failed';
$string['not_started'] = 'Not Started';
$string['in_progress'] = 'In Progress';
$string['completed'] = 'Completed';
$string['essays_master_enabled'] = 'Essays Master Enabled';
$string['essays_master_disabled'] = 'Essays Master Disabled';
$string['enable'] = 'Enable';
$string['disable'] = 'Disable';
$string['configure'] = 'Configure';
$string['total_students'] = 'Total Students: {$a}';
$string['active_sessions'] = 'Active Sessions: {$a}';
$string['completion_rate'] = 'Completion Rate: {$a}%';
$string['avg_score'] = 'Average Score: {$a}/100';

// Student detail page
$string['student_detail'] = 'Student Progress Detail';
$string['student_detail_title'] = '{$a->student} - {$a->quiz}';
$string['progress_timeline'] = 'Progress Timeline';
$string['round_details'] = 'Round Details';
$string['essay_evolution'] = 'Essay Evolution';
$string['performance_metrics'] = 'Performance Metrics';
$string['round_feedback'] = 'Round {$a} - Feedback';
$string['round_validation'] = 'Round {$a} - Validation';
$string['ai_feedback'] = 'AI Feedback';
$string['original_improved'] = 'Original â†’ Improved Examples';
$string['essay_text'] = 'Essay Text';
$string['time_spent'] = 'Time Spent: {$a}';
$string['attempts_made'] = 'Attempts: {$a}';
$string['validation_score'] = 'Validation Score: {$a}/100';

// Quiz configuration
$string['quiz_config_title'] = 'Quiz Configuration - {$a}';
$string['validation_thresholds'] = 'Validation Score Thresholds';
$string['round_2_threshold'] = 'Round 2 (Grammar) Threshold';
$string['round_4_threshold'] = 'Round 4 (Vocabulary) Threshold';
$string['round_6_threshold'] = 'Round 6 (Structure) Threshold';
$string['max_attempts_per_round'] = 'Maximum Attempts per Round';
$string['save_configuration'] = 'Save Configuration';
$string['configuration_saved'] = 'Configuration saved successfully';
$string['configuration_error'] = 'Error saving configuration';
$string['default_enabled_notice'] = 'Essays Master is enabled by default for all essay quizzes. Use this dashboard to disable or configure specific quizzes.';

// Bulk actions
$string['bulk_actions'] = 'Bulk Actions';
$string['bulk_enable'] = 'Enable Selected';
$string['bulk_disable'] = 'Disable Selected';
$string['select_all'] = 'Select All';
$string['selected_items'] = '{$a} items selected';
$string['confirm_bulk_enable'] = 'Are you sure you want to enable Essays Master for the selected quizzes?';
$string['confirm_bulk_disable'] = 'Are you sure you want to disable Essays Master for the selected quizzes?';

// Statistics
$string['statistics'] = 'Statistics';
$string['total_essays'] = 'Total Essays';
$string['completed_essays'] = 'Completed Essays';
$string['average_rounds'] = 'Average Rounds Completed';
$string['success_rate'] = 'Success Rate';
$string['most_common_issues'] = 'Most Common Issues';
$string['grammar_issues'] = 'Grammar Issues: {$a}%';
$string['vocabulary_issues'] = 'Vocabulary Issues: {$a}%';
$string['structure_issues'] = 'Structure Issues: {$a}%';

// Events
$string['event_session_started'] = 'Essays Master session started';
$string['event_level_completed'] = 'Essays Master level completed';
$string['event_feedback_generated'] = 'AI feedback generated';
$string['event_essay_submitted'] = 'Final essay submitted through Essays Master';

// Help strings
$string['help_level1'] = 'Level 1 focuses on fixing basic grammar, spelling, and punctuation errors. Make sure your sentences are properly constructed and error-free.';
$string['help_level2'] = 'Level 2 emphasizes improving your vocabulary choices and sentence variety. Try to use more sophisticated language and varied sentence structures.';
$string['help_level3'] = 'Level 3 examines your essay\'s overall structure, argument development, and content depth. Focus on organization, evidence, and analytical thinking.';
$string['help_copy_paste'] = 'Copy and paste operations are disabled to encourage original thinking and writing. Please type your content manually.';
$string['help_ai_feedback'] = 'Click "Get AI Feedback" to receive personalized suggestions for improving your essay at the current level.';

// Time and date formatting
$string['time_remaining'] = 'Time remaining: {$a}';
$string['time_expired'] = 'Time limit expired for this level';
$string['started_at'] = 'Started at: {$a}';
$string['completed_at'] = 'Completed at: {$a}';

// Mobile-specific strings
$string['mobile_not_supported'] = 'Essays Master interface is optimized for desktop browsers. Please use a computer for the best experience.';
$string['mobile_limited_features'] = 'Limited features available on mobile devices.';

// Additional dashboard strings
$string['no_attempts_found'] = 'No attempts found for this student';
$string['back_to_dashboard'] = 'Back to Dashboard';
$string['attempt_history'] = 'Attempt History';
$string['filter_attempts'] = 'Filter Attempts';
$string['quiz_filter'] = 'Quiz Filter';
$string['round_filter'] = 'Round Filter';
$string['date_range'] = 'Date Range';
$string['all_quizzes'] = 'All Quizzes';
$string['all_rounds'] = 'All Rounds';
$string['round_1'] = 'Round 1: Initial Feedback';
$string['round_2'] = 'Round 2: Validation';
$string['round_3'] = 'Round 3: Further Feedback';
$string['round_4'] = 'Round 4: Validation';
$string['round_5'] = 'Round 5: Final Feedback';
$string['round_6'] = 'Round 6: Final Validation';
$string['round_num'] = 'Round {$a}';
$string['all_time'] = 'All Time';
$string['last_7_days'] = 'Last 7 Days';
$string['last_30_days'] = 'Last 30 Days';
$string['last_90_days'] = 'Last 90 Days';
$string['attempt_date'] = 'Attempt Date';
$string['round'] = 'Round';
$string['improvement_score'] = 'Improvement Score';

// AJAX response messages
$string['quiz_enabled'] = 'Quiz Essays Master Enabled';
$string['quiz_disabled'] = 'Quiz Essays Master Disabled';
$string['quiz_enabled_success'] = 'Essays Master successfully enabled for this quiz';
$string['quiz_disabled_success'] = 'Essays Master successfully disabled for this quiz';
$string['quiz_toggle_failed'] = 'Failed to toggle quiz status';
$string['quizzes_enabled'] = 'Selected quizzes have been enabled';
$string['quizzes_disabled'] = 'Selected quizzes have been disabled';
$string['no_quizzes_selected'] = 'No Quizzes Selected';
$string['select_quizzes_first'] = 'Please select one or more quizzes first';
$string['bulk_action_success'] = '{$a->count} quizzes have been {$a->action}d successfully';
$string['bulk_action_partial'] = '{$a->success} quizzes {$a->action}d successfully, {$a->failed} failed';
$string['bulk_action_failed'] = 'Bulk action failed';
$string['invalid_quiz_ids'] = 'Invalid quiz IDs provided';
$string['invalid_action'] = 'Invalid action specified';

// Additional filters and stats
$string['recent_activity'] = 'Recent Activity';
$string['total_attempts'] = 'Total Attempts';
$string['completed_rounds'] = 'Completed Rounds';
$string['avg_improvement'] = 'Average Improvement';

// View attempt page
$string['attempt_details'] = 'Attempt Details';
$string['attemptnotfound'] = 'Attempt not found';
$string['back_to_student'] = 'Back to Student Details';