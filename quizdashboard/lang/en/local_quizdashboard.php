<?php
defined('MOODLE_INTERNAL') || die();

// Plugin identification
$string['pluginname'] = 'Quiz Dashboard';
$string['quizdashboard'] = 'Quiz Dashboard';
$string['essaydashboard'] = 'Essay Dashboard';
$string['questionsdashboard'] = 'Questions Dashboard'; 
$string['mydashboard'] = 'My Quiz Dashboard';
$string['dashboards'] = 'Quiz Dashboards';

// Privacy API
$string['privacy:metadata'] = 'The Quiz Dashboard plugin does not store any personal data.';

// Capabilities
$string['quizdashboard:view'] = 'View quiz dashboards';
$string['quizdashboard:manage'] = 'Manage quiz dashboards';

// Page headings and navigation
$string['dashboard_heading'] = 'Quiz Dashboard';
$string['essay_heading'] = 'Essay Dashboard';
$string['questions_heading'] = 'Questions Dashboard';
$string['navigation_reports'] = 'Reports';

// Existing strings (preserved)
$string['userid'] = 'User ID';
$string['studentname'] = 'Student Name';
$string['coursename'] = 'Course Name';
$string['quizname'] = 'Quiz Name';
$string['attemptnumber'] = 'Attempt Number';
$string['status'] = 'Status';
$string['started'] = 'Started';
$string['finished'] = 'Finished';
$string['score'] = 'Score';
$string['maxscore'] = 'Max Score';
$string['percentage'] = 'Percentage';
$string['totalrecords'] = 'Total record count';
$string['executiontime'] = 'Execution time';
$string['completed'] = 'Completed';
$string['inprogress'] = 'In Progress';
$string['abandoned'] = 'Abandoned';
$string['noaccess'] = 'You do not have permission to access this page.';

// Additional UI strings for enhanced functionality
$string['nodata'] = 'No quiz data found.';
$string['question'] = 'Question';
$string['questionname'] = 'Question Name';
$string['attempt'] = 'Attempt';
$string['course'] = 'Course';
$string['quiz'] = 'Quiz';
$string['user'] = 'User';
$string['comment'] = 'Comment';
$string['grade'] = 'Grade';
$string['duration'] = 'Duration';
$string['timetaken'] = 'Time Taken';

// Filter labels
$string['filter_user'] = 'Filter by user';
$string['filter_course'] = 'Filter by course';
$string['filter_quiz'] = 'Filter by quiz';
$string['filter_status'] = 'Filter by status';
$string['filter_month'] = 'Filter by month';
$string['filter_question'] = 'Filter by question';
$string['filter_userid'] = 'Filter by user ID';
$string['allusers'] = 'All Users';
$string['alluserids'] = 'All User IDs';
$string['allcourses'] = 'All Courses';
$string['allquizzes'] = 'All Quizzes';
$string['allquestions'] = 'All Questions';
$string['alltypes'] = 'All Types';
$string['allmonths'] = 'All Months';
$string['allstatuses'] = 'All';

// Quiz types
$string['essay'] = 'Essay';
$string['nonessay'] = 'Non-Essay';
$string['quiztype'] = 'Quiz Type';

// Actions and buttons
$string['bulkactions'] = 'Bulk actions';
$string['apply'] = 'Apply';
$string['reset'] = 'Reset';
$string['save'] = 'Save';
$string['filter'] = 'Filter';
$string['export'] = 'Export';
$string['approve'] = 'Approve';
$string['reject'] = 'Reject';
$string['delete'] = 'Delete';
$string['gradeselected'] = 'Grade Selected';
$string['approveselected'] = 'Approve Selected';
$string['rejectselected'] = 'Reject Selected';
$string['exportselected'] = 'Export Selected';
$string['deleteselected'] = 'Delete Selected';

// Status messages and feedback
$string['saved'] = 'Saved successfully';
$string['gradesaved'] = 'Grade saved';
$string['commentsaved'] = 'Comment saved';
$string['error_save'] = 'Error saving data';
$string['error_grade'] = 'Error saving grade';
$string['error_comment'] = 'Error saving comment';
$string['confirm_bulk'] = 'Are you sure you want to perform this action on the selected items?';
$string['itemsselected'] = 'items selected';
$string['itemselected'] = 'item selected';

// Table and display
$string['selectall'] = 'Select all';
$string['nosubmissionsfound'] = 'No quiz submissions found';
$string['noessaysubmissionsfound'] = 'No essay submissions found';
$string['noquestionsfound'] = 'No question attempts found';
$string['questiontext'] = 'Question Text';
$string['questiontype'] = 'Question Type';
$string['result'] = 'Result';
$string['correct'] = 'Correct';
$string['incorrect'] = 'Incorrect';
$string['partial'] = 'Partial';
$string['viewquestion'] = 'View Question';
$string['actions'] = 'Actions';
$string['viewcomments'] = 'View/Add comments';
$string['addcomment'] = 'Add comment';
$string['editquestion'] = 'Edit question';
$string['viewprofile'] = 'View user profile';
$string['viewactivity'] = 'View user activity';
$string['viewcourse'] = 'View course';
$string['reviewattempt'] = 'Review attempt';

// Sorting
$string['sortby'] = 'Sort by';
$string['ascending'] = 'Ascending';
$string['descending'] = 'Descending';

// Time and date
$string['timeformat'] = 'Y-m-d H:i';
$string['never'] = 'Never';
$string['notstarted'] = 'Not started';

// Validation and errors
$string['invalidgrade'] = 'Please enter a valid grade';
$string['invalidaction'] = 'Invalid action specified';
$string['selectaction'] = 'Please select an action and at least one item';
$string['processingerror'] = 'An error occurred while processing the request';

// Essasy feedback page
$string['essayfeedback'] = 'Essay Feedback';
$string['nofeedbackfound'] = 'No feedback found';
$string['printfeedback'] = 'Print Feedback';
$string['feedbacknotavailable'] = 'No automated feedback has been generated for this essay attempt yet.';

// Resubmission-related strings
$string['resubmission'] = 'Resubmission';
$string['resubmissiongrading'] = 'Resubmission Grading';
$string['graderesubmission'] = 'Grade Resubmission';
$string['graderesubmissiongeneral'] = 'Grade Resubmission (General)';
$string['graderesubmissionadvanced'] = 'Grade Resubmission (Advanced)';
$string['submissionchain'] = 'Submission Chain';
$string['submissionnumber'] = 'Submission #';
$string['resubmissionindicator'] = 'Resubmission';
$string['copydetected'] = 'Copy Detected';
$string['copypenalty'] = 'Copy Penalty Applied';
$string['similaritypercentage'] = 'Similarity: {$a}%';
$string['notaresubmission'] = 'This is not a resubmission';
$string['previousnotgraded'] = 'Previous submission must be graded first';
$string['resubmissionprocessed'] = 'Resubmission processed successfully';
$string['resubmissionsgraded'] = '{$a} resubmission(s) graded successfully';
$string['copypenaltyapplied'] = 'Copy penalty applied to {$a} submission(s)';
$string['comparativefeedback'] = 'Comparative Feedback';
$string['previoussubmission'] = 'Previous Submission';
$string['progressfeedback'] = 'Progress Feedback';