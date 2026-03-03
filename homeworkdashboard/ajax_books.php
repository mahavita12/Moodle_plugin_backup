<?php
/**
 * AJAX endpoint for book reading tracker.
 * Actions: add, delete, list, duedates
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_login();
require_sesskey();

// Only admins can manage books
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = required_param('action', PARAM_ALPHA);
$manager = new \local_homeworkdashboard\homework_manager();

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'add':
            $userid     = required_param('userid', PARAM_INT);
            $title      = required_param('title', PARAM_TEXT);
            $author     = optional_param('author', '', PARAM_TEXT);
            $finished   = optional_param('finished', 0, PARAM_INT);
            $week_ending = required_param('week_ending', PARAM_INT);
            $custom_points = optional_param('points', '', PARAM_TEXT);

            if (empty(trim($title))) {
                throw new moodle_exception('Book title is required.');
            }

            // Resolve Category 1 course for this user
            $cat1_courseid = $manager->get_user_cat1_courseid($userid);
            if ($cat1_courseid == 0) {
                throw new moodle_exception('Student has no Category 1 course enrolment.');
            }

            // Use custom points if provided, otherwise use config defaults
            if ($custom_points !== '') {
                $points = (float) $custom_points;
            } else {
                $pts_finished_str = get_config('local_homeworkdashboard', 'book_points_finished');
                $pts_inprogress_str = get_config('local_homeworkdashboard', 'book_points_inprogress');
                
                $pts_finished = ($pts_finished_str !== false && trim((string)$pts_finished_str) !== '') ? (float)$pts_finished_str : 200.0;
                $pts_inprogress = ($pts_inprogress_str !== false && trim((string)$pts_inprogress_str) !== '') ? (float)$pts_inprogress_str : 100.0;
                
                $points = $finished ? $pts_finished : $pts_inprogress;
            }

            // 1. Create the adjustment (tied to Cat 1 course)
            $adj_id = $manager->add_points_adjustment(
                $userid,
                $cat1_courseid,
                $points,
                '📚 Book: ' . $title,
                $week_ending  // timeapplied = due date
            );

            // 2. Create the book record
            $book = new stdClass();
            $book->userid = $userid;
            $book->title = trim($title);
            $book->author = trim($author);
            $book->pages_read = 0;
            $book->finished = $finished ? 1 : 0;
            $book->points_awarded = $points;
            $book->adjustment_id = $adj_id;
            $book->week_ending = $week_ending;
            $book->courseid = $cat1_courseid;
            $book->createdby = $USER->id;
            $book->timecreated = time();

            $bookid = $DB->insert_record('local_hw_books', $book);

            // Return updated list
            $books = $manager->get_user_books($userid);
            $total_pts = array_sum(array_column($books, 'points_awarded'));

            echo json_encode([
                'success' => true,
                'bookid' => $bookid,
                'books' => $books,
                'total_points' => $total_pts,
                'cat1_course' => $manager->get_cat1_course_name($userid)
            ]);
            break;

        case 'delete':
            $bookid = required_param('bookid', PARAM_INT);
            $userid = required_param('userid', PARAM_INT);

            $book = $DB->get_record('local_hw_books', ['id' => $bookid], '*', MUST_EXIST);

            // Delete linked adjustment
            if (!empty($book->adjustment_id)) {
                $manager->delete_adjustment($book->adjustment_id);
            }

            // Delete book
            $DB->delete_records('local_hw_books', ['id' => $bookid]);

            // Return updated list
            $books = $manager->get_user_books($userid);
            $total_pts = array_sum(array_column($books, 'points_awarded'));

            echo json_encode([
                'success' => true,
                'books' => $books,
                'total_points' => $total_pts
            ]);
            break;

        case 'list':
            $userid = required_param('userid', PARAM_INT);
            $week_ending = optional_param('week_ending', 0, PARAM_INT);

            if ($week_ending > 0) {
                $books = $DB->get_records('local_hw_books', [
                    'userid' => $userid, 'week_ending' => $week_ending
                ], 'timecreated DESC');
            } else {
                $books = $manager->get_user_books($userid);
            }

            $total_pts = array_sum(array_column((array)$books, 'points_awarded'));

            echo json_encode([
                'success' => true,
                'books' => array_values((array)$books),
                'total_points' => $total_pts,
                'cat1_course' => $manager->get_cat1_course_name($userid)
            ]);
            break;

        case 'duedates':
            $userid = required_param('userid', PARAM_INT);
            $dates = $manager->get_user_cat1_duedates($userid);

            echo json_encode([
                'success' => true,
                'duedates' => $dates
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
