-- Essays Master AI Integration Testing Queries
-- These queries help you verify that essays are correctly being sent to AI for all 6 rounds

-- 1. OVERVIEW: Check recent sessions and their completion status
SELECT 
    s.id as session_id,
    s.attempt_id,
    s.user_id,
    u.firstname,
    u.lastname,
    s.current_level,
    s.feedback_rounds_completed,
    s.max_level,
    s.status,
    s.final_submission_allowed,
    FROM_UNIXTIME(s.timecreated) as created,
    FROM_UNIXTIME(s.timemodified) as modified
FROM mdl_local_essaysmaster_sessions s
JOIN mdl_user u ON u.id = s.user_id
ORDER BY s.timecreated DESC
LIMIT 20;

-- 2. ROUND COVERAGE: Check which rounds have feedback for each attempt
SELECT 
    f.version_id as attempt_id,
    COUNT(*) as total_rounds,
    GROUP_CONCAT(REPLACE(f.level_type, 'round_', '') ORDER BY f.level_type) as completed_rounds,
    CASE 
        WHEN COUNT(*) = 6 THEN 'Complete (All 6 rounds)'
        WHEN COUNT(*) >= 3 THEN 'Partial'
        ELSE 'Incomplete'
    END as completion_status
FROM mdl_local_essaysmaster_feedback f
GROUP BY f.version_id
ORDER BY f.version_id DESC;

-- 3. AI INTEGRATION STATUS: Check feedback quality and response times
SELECT 
    REPLACE(level_type, 'round_', '') as round_number,
    COUNT(*) as feedback_count,
    AVG(LENGTH(feedback_html)) as avg_feedback_length,
    AVG(api_response_time) as avg_response_time,
    AVG(completion_score) as avg_score,
    MIN(FROM_UNIXTIME(feedback_generated_time)) as earliest_feedback,
    MAX(FROM_UNIXTIME(feedback_generated_time)) as latest_feedback
FROM mdl_local_essaysmaster_feedback
GROUP BY level_type
ORDER BY round_number;

-- 4. DETAILED ANALYSIS: Show specific attempt with all rounds
-- Replace ATTEMPT_ID with actual attempt ID you want to check
SET @target_attempt = 123; -- CHANGE THIS TO YOUR ATTEMPT ID

SELECT 
    'Session Info' as type,
    CONCAT('Level: ', current_level, ', Completed: ', feedback_rounds_completed, ', Status: ', status) as details,
    FROM_UNIXTIME(timecreated) as timestamp,
    '' as round_info
FROM mdl_local_essaysmaster_sessions 
WHERE attempt_id = @target_attempt

UNION ALL

SELECT 
    'Feedback' as type,
    CONCAT('Round ', REPLACE(level_type, 'round_', ''), ' - ', LENGTH(feedback_html), ' chars') as details,
    FROM_UNIXTIME(feedback_generated_time) as timestamp,
    CASE 
        WHEN REPLACE(level_type, 'round_', '') IN ('1', '3', '5') THEN 'Feedback Round'
        WHEN REPLACE(level_type, 'round_', '') IN ('2', '4', '6') THEN 'Validation Round'
        ELSE 'Unknown'
    END as round_info
FROM mdl_local_essaysmaster_feedback 
WHERE version_id = @target_attempt
ORDER BY timestamp;

-- 5. POTENTIAL ISSUES: Find problems that might indicate AI integration failures
SELECT 
    'Issue Type' as issue,
    'Count' as count,
    'Description' as description

UNION ALL

SELECT 
    'Empty Feedback',
    COUNT(*),
    'Feedback records with no content'
FROM mdl_local_essaysmaster_feedback 
WHERE feedback_html IS NULL OR feedback_html = '' OR LENGTH(feedback_html) < 50

UNION ALL

SELECT 
    'Missing Rounds',
    COUNT(*),
    'Sessions that should be complete but have fewer than 6 feedback records'
FROM mdl_local_essaysmaster_sessions s
LEFT JOIN (
    SELECT version_id, COUNT(*) as feedback_count 
    FROM mdl_local_essaysmaster_feedback 
    GROUP BY version_id
) f ON f.version_id = s.attempt_id
WHERE s.feedback_rounds_completed >= 6 AND (f.feedback_count IS NULL OR f.feedback_count < 6)

UNION ALL

SELECT 
    'Slow Responses',
    COUNT(*),
    'API responses taking longer than 10 seconds'
FROM mdl_local_essaysmaster_feedback 
WHERE api_response_time > 10

UNION ALL

SELECT 
    'Low Scores',
    COUNT(*),
    'Completion scores below 20 (might indicate errors)'
FROM mdl_local_essaysmaster_feedback 
WHERE completion_score < 20;

-- 6. ROUND-BY-ROUND ANALYSIS: Check if all round types are working
SELECT 
    CASE 
        WHEN REPLACE(level_type, 'round_', '') = '1' THEN 'Round 1 (Grammar/Spelling Feedback)'
        WHEN REPLACE(level_type, 'round_', '') = '2' THEN 'Round 2 (Grammar/Spelling Validation)'
        WHEN REPLACE(level_type, 'round_', '') = '3' THEN 'Round 3 (Vocabulary Feedback)'
        WHEN REPLACE(level_type, 'round_', '') = '4' THEN 'Round 4 (Vocabulary Validation)'
        WHEN REPLACE(level_type, 'round_', '') = '5' THEN 'Round 5 (Structure/Relevance Feedback)'
        WHEN REPLACE(level_type, 'round_', '') = '6' THEN 'Round 6 (Final Validation)'
        ELSE level_type
    END as round_description,
    COUNT(*) as total_attempts,
    COUNT(CASE WHEN LENGTH(feedback_html) > 100 THEN 1 END) as substantial_feedback,
    COUNT(CASE WHEN api_response_time > 0 THEN 1 END) as ai_responses,
    ROUND(AVG(completion_score), 2) as avg_score
FROM mdl_local_essaysmaster_feedback
GROUP BY level_type
ORDER BY CAST(REPLACE(level_type, 'round_', '') AS UNSIGNED);

-- 7. RECENT AI ACTIVITY: Show latest AI interactions
SELECT 
    f.version_id as attempt_id,
    REPLACE(f.level_type, 'round_', '') as round,
    LENGTH(f.feedback_html) as feedback_length,
    f.api_response_time,
    f.completion_score,
    FROM_UNIXTIME(f.feedback_generated_time) as generated_time,
    CASE 
        WHEN f.feedback_html LIKE '%nonce:%' THEN 'Has Nonce (Re-attempt)'
        ELSE 'Normal'
    END as request_type
FROM mdl_local_essaysmaster_feedback f
ORDER BY f.feedback_generated_time DESC
LIMIT 50;

-- 8. SUCCESS RATE BY ROUND: Check if certain rounds are failing more often
SELECT 
    REPLACE(level_type, 'round_', '') as round,
    COUNT(*) as total_attempts,
    COUNT(CASE WHEN LENGTH(feedback_html) > 50 THEN 1 END) as successful_feedback,
    COUNT(CASE WHEN feedback_html LIKE '%error%' OR feedback_html LIKE '%failed%' THEN 1 END) as error_responses,
    ROUND(
        (COUNT(CASE WHEN LENGTH(feedback_html) > 50 THEN 1 END) * 100.0 / COUNT(*)), 
        2
    ) as success_rate_percent
FROM mdl_local_essaysmaster_feedback
GROUP BY level_type
ORDER BY CAST(REPLACE(level_type, 'round_', '') AS UNSIGNED);

-- 9. TEXT LENGTH ANALYSIS: Verify essays are being processed (not empty)
-- This helps identify if empty essays are being sent to AI
SELECT 
    REPLACE(level_type, 'round_', '') as round,
    COUNT(*) as feedback_count,
    AVG(LENGTH(feedback_html)) as avg_feedback_length,
    MIN(LENGTH(feedback_html)) as min_feedback_length,
    MAX(LENGTH(feedback_html)) as max_feedback_length,
    COUNT(CASE WHEN LENGTH(feedback_html) < 50 THEN 1 END) as short_responses
FROM mdl_local_essaysmaster_feedback
GROUP BY level_type
ORDER BY CAST(REPLACE(level_type, 'round_', '') AS UNSIGNED);

-- 10. STUDENT PROGRESS VERIFICATION: Check if students are progressing through all rounds
SELECT 
    s.attempt_id,
    s.user_id,
    s.feedback_rounds_completed,
    COUNT(f.id) as actual_feedback_records,
    CASE 
        WHEN s.feedback_rounds_completed = COUNT(f.id) THEN 'Consistent'
        WHEN s.feedback_rounds_completed > COUNT(f.id) THEN 'Session ahead of feedback'
        ELSE 'Feedback ahead of session'
    END as consistency_check,
    s.status,
    s.final_submission_allowed
FROM mdl_local_essaysmaster_sessions s
LEFT JOIN mdl_local_essaysmaster_feedback f ON f.version_id = s.attempt_id
GROUP BY s.id
HAVING s.feedback_rounds_completed >= 3 -- Only show sessions with some progress
ORDER BY s.timecreated DESC;
