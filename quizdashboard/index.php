<?php
require_once('../../config.php');
require_once(__DIR__.'/classes/quiz_manager.php');

require_login();
$context = context_system::instance();

// Capability check.
if (!has_capability('local/quizdashboard:view', $context)) {
    print_error('noaccess', 'local_quizdashboard');
}

$PAGE->set_url('/local/quizdashboard/index.php');
$PAGE->set_context($context);
$PAGE->set_title('Quiz Dashboard');
$PAGE->set_heading('Quiz Dashboard');
$PAGE->set_pagelayout('admin');


// Add CSS for styling
$PAGE->requires->css('/local/quizdashboard/styles.css');

// Add blocks toggle functionality directly
$PAGE->requires->js_init_code('
document.addEventListener("DOMContentLoaded", function() {
    console.log("Quiz Dashboard: Blocks toggle DOM ready");
    
    // Check for blocks
    var blockSelectors = [
        "[data-region=\\"blocks-column\\"]",
        ".region_post", 
        ".region-post", 
        "#region-post",
        "[data-region=\\"post\\"]",
        ".block-region-post",
        ".block-region-side-post"
    ];
    
    var hasBlocks = blockSelectors.some(function(sel) {
        var elements = document.querySelectorAll(sel);
        return elements.length > 0;
    });
    
    console.log("Quiz Dashboard: Blocks found:", hasBlocks);
    
    if (hasBlocks && !document.querySelector(".global-blocks-toggle")) {
        console.log("Quiz Dashboard: Creating blocks toggle button");
        
        // Get stored state
        var isHidden = false;
        try {
            isHidden = localStorage.getItem("moodle_blocks_hidden") === "true";
        } catch(e) {}
        
        // Create toggle button
        var toggleHtml = `
            <div class="global-blocks-toggle" style="position:fixed;top:130px;right:15px;z-index:9998;font-family:system-ui">
                <button type="button" class="blocks-toggle-btn" 
                        style="background:${isHidden ? "#007cba" : "#f8f9fa"};border:1px solid ${isHidden ? "#005a87" : "#dee2e6"};color:${isHidden ? "#fff" : "#495057"};padding:8px 12px;cursor:pointer;font-size:12px;border-radius:20px;box-shadow:0 2px 8px rgba(0,0,0,0.1);display:flex;align-items:center;gap:6px;min-width:110px;justify-content:center;font-weight:500;transition:all 0.3s ease"
                        title="Toggle blocks panel visibility"
                        onmouseover="this.style.transform=\'translateY(-1px)\'"
                        onmouseout="this.style.transform=\'translateY(0)\'">
                    <span class="toggle-icon">${isHidden ? "👁️" : "🔳"}</span>
                    <span class="toggle-text">${isHidden ? "Show Blocks" : "Hide Blocks"}</span>
                </button>
            </div>
        `;
        
        document.body.insertAdjacentHTML("beforeend", toggleHtml);
        
        var button = document.querySelector(".blocks-toggle-btn");
        var toggleClass = "blocks-hidden-mode";
        
        // Apply initial state
        if (isHidden) {
            document.body.classList.add(toggleClass);
        }
        
        // Add click handler
        button.addEventListener("click", function() {
            var currentlyHidden = document.body.classList.contains(toggleClass);
            var newState = !currentlyHidden;
            
            if (newState) {
                document.body.classList.add(toggleClass);
                button.style.background = "#007cba";
                button.style.color = "#fff"; 
                button.style.borderColor = "#005a87";
                button.querySelector(".toggle-text").textContent = "Show Blocks";
                button.querySelector(".toggle-icon").textContent = "👁️";
            } else {
                document.body.classList.remove(toggleClass);
                button.style.background = "#f8f9fa";
                button.style.color = "#495057";
                button.style.borderColor = "#dee2e6";
                button.querySelector(".toggle-text").textContent = "Hide Blocks";
                button.querySelector(".toggle-icon").textContent = "🔳";
            }
            
            // Save state
            try {
                localStorage.setItem("moodle_blocks_hidden", newState.toString());
            } catch(e) {}
            
            console.log("Quiz Dashboard: Blocks toggle:", newState ? "hidden" : "visible");
        });
        
        console.log("Quiz Dashboard: Blocks toggle button created successfully");
    }
});
');

// Initialize quiz manager BEFORE AJAX handling
$quizmanager = new \local_quizdashboard\quiz_manager();

// AJAX handlers for save operations
if (optional_param('action', '', PARAM_ALPHANUMEXT)) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    
    if ($action === 'bulk_action') {
        require_sesskey(); // Moodle security check
        $bulk_action = required_param('bulk_action', PARAM_ALPHA);
        $attempt_ids = required_param('attempt_ids', PARAM_TEXT);
        $attempt_ids = explode(',', $attempt_ids);
        
        $success_count = 0;
        $message = '';
        
        try {
            switch ($bulk_action) {

                    
                case 'delete':
                    $success_count = 0;
                    foreach ($attempt_ids as $attemptid) {
                        $attemptid = (int)trim($attemptid);
                        if ($attemptid > 0 && $quizmanager->delete_quiz_attempt($attemptid)) {
                            $success_count++;
                        }
                    }
                    $message = "Permanently deleted $success_count attempt(s)";
                    break;
                    
                case 'export':
                    try {
                        $export_result = $quizmanager->export_attempts_data($attempt_ids);
                        ob_clean();
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true, 
                            'message' => "Export ready: {$export_result['record_count']} records",
                            'download_url' => $export_result['download_url'],
                            'filename' => $export_result['filename']
                        ]);
                        exit;
                    } catch (Exception $e) {
                        ob_clean();
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
                        exit;
                    }
                    break;
                    
                default:
                    // Fallback for unknown actions
                    foreach ($attempt_ids as $attemptid) {
                        if (is_numeric($attemptid)) {
                            $success_count++;
                        }
                    }
                    $message = "Action '$bulk_action' applied to $success_count items";
            }
        } catch (Exception $e) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit;
        }
        
        ob_clean(); // Clear any previous output
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }
}

// ---------------- Filters ----------------
$userid      = optional_param('userid', '', PARAM_INT);
$categoryid  = optional_param('categoryid', 0, PARAM_INT);
$filter_userid = optional_param('filter_userid', '', PARAM_INT);
$studentname = optional_param('studentname', '', PARAM_TEXT);
$coursename  = optional_param('coursename', '', PARAM_TEXT);
$filter_coursename = optional_param('filter_coursename', '', PARAM_TEXT);
$quizname    = optional_param('quizname', '', PARAM_TEXT);
$sectionid   = optional_param('sectionid', '', PARAM_INT); // NEW: Section filter
$month       = optional_param('month', '', PARAM_TEXT);
$status      = optional_param('status', '', PARAM_ALPHA);
$quiztype    = optional_param('quiztype', 'Non-Essay', PARAM_TEXT); // Default to Non-Essay
$sort        = optional_param('sort', 'timefinish', PARAM_ALPHA);
$dir         = optional_param('dir', 'DESC', PARAM_ALPHA);

// Filter by clicked user or course
$filter_by_user   = optional_param('filter_user', '', PARAM_TEXT);
$filter_by_course = optional_param('filter_course', '', PARAM_TEXT);

// Handle direct filter parameters
if (!empty($filter_userid)) {
    $userid = $filter_userid;
}
if (!empty($filter_coursename)) {
    $coursename = $filter_coursename;
}
if (!empty($filter_by_user)) {
    $studentname = $filter_by_user;
}
if (!empty($filter_by_course)) {
    $coursename = $filter_by_course;
}

// ---------------- Data ----------------
// Categories (default to 'Category 1' if present)
$categories = [];
try {
    $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
    if (empty($categoryid)) {
        $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id');
        if ($catrow) { $categoryid = (int)$catrow->id; }
    }
} catch (\Throwable $e) { /* ignore */ }

$unique_users    = $quizmanager->get_unique_users();
$unique_courses  = $quizmanager->get_unique_course_names((int)$categoryid);
$unique_quizzes  = $quizmanager->get_unique_quiz_names();
// Determine selected courseid (server-side) from selected coursename option
$selected_courseid = 0;
if (!empty($coursename) && !empty($unique_courses)) {
    foreach ($unique_courses as $cobj) {
        if (isset($cobj->fullname) && $cobj->fullname === $coursename) { $selected_courseid = (int)$cobj->id; break; }
    }
}
$unique_sections = $quizmanager->get_unique_sections((int)$categoryid, (int)$selected_courseid);

// Get unique user IDs
$unique_userids = [];
try {
    global $DB;
    $sql = "SELECT DISTINCT u.id, u.id AS userid
              FROM {user} u
              JOIN {quiz_attempts} qa ON qa.userid = u.id
              JOIN {quiz} q ON qa.quiz = q.id
              JOIN {course} c ON q.course = c.id
             WHERE u.deleted = 0 
               AND c.visible = 1
               AND qa.state IN ('finished', 'inprogress')
          ORDER BY u.id";
    $unique_userids = $DB->get_records_sql($sql);
} catch (\Exception $e) {
    error_log("Error fetching unique user IDs: " . $e->getMessage());
}

$records = $quizmanager->get_filtered_quiz_attempts(
    $userid, $studentname, $coursename, $quizname, '', '', $quiztype, $sort, $dir, 0, 0, $status, $sectionid, (int)$categoryid
);

// Apply month filter if set
if (!empty($month)) {
    $records = array_filter($records, function($r) use ($month) {
        if (!empty($r->timefinish)) {
            $record_month = date('Y-m', $r->timefinish);
            return $record_month === $month;
        }
        return false;
    });
}

// Apply status filter
if (!empty($status)) {
    $records = array_filter($records, function($r) use ($status){ 
        return strtolower($r->status) === strtolower($status); 
    });
}

global $DB;
$rows = [];
foreach ($records as $r) {
    $attemptid = (int)$r->attemptid;
    $quizid = $DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
    $cmid = null;
    if ($quizid) {
        if ($cm = get_coursemodule_from_instance('quiz', $quizid)) { $cmid = $cm->id; }
    }
    $reviewurl = new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]);
    $gradeurl  = $cmid ? new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'grading']) : null;

    // Get comment count
    $comment_manager = new \local_quizdashboard\quiz_manager();
    $comment_count = $comment_manager->get_attempt_comment_count($attemptid);

    // Calculate time taken
    $time_taken = '';
    if (!empty($r->timestart) && !empty($r->timefinish)) {
        $duration_seconds = $r->timefinish - $r->timestart;
        $hours = floor($duration_seconds / 3600);
        $minutes = floor(($duration_seconds % 3600) / 60);
        $seconds = $duration_seconds % 60;
        
        if ($hours > 0) {
            $time_taken = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } else if ($minutes > 0) {
            $time_taken = sprintf('%dm %ds', $minutes, $seconds);
        } else {
            $time_taken = sprintf('%ds', $seconds);
        }
    }

    // Generate user profile and activity URLs
    $user_profile_url = new moodle_url('/user/profile.php', ['id' => $r->userid]);
    
    // Try to create user activity URL
    $user_activity_url = null;
    try {
        $user_activity_url = new moodle_url('/report/outline/user.php', ['id' => $r->userid, 'course' => $r->courseid, 'mode' => 'outline']);
    } catch (\Exception $e) {
        $user_activity_url = $user_profile_url;
    }

    // Generate course URL
    $course_url = new moodle_url('/course/view.php', ['id' => $r->courseid]);

    $isessay  = isset($r->quiz_type) && $r->quiz_type === 'Essay';
    $finished = strtolower($r->status) === 'completed' || strtolower($r->status) === 'finished';

    $rows[] = (object) [
        'attemptid'     => $attemptid,
        'userid'        => $r->userid,
        'courseid'      => $r->courseid,
        'studentname'   => $r->studentname,
        'coursename'    => $r->coursename,
        'categoryname'  => $r->categoryname ?? '',
        'categoryid'    => isset($r->categoryid) ? (int)$r->categoryid : 0,
        'quizname'      => $r->quizname,
        'user_profile_url' => $user_profile_url->out(false),
        'user_activity_url' => $user_activity_url->out(false),
        'course_url'    => $course_url->out(false),
        'time_taken'    => $time_taken,
        'attemptno'     => $r->attemptnumber,
        'status'        => $r->status,
        'timestart'     => $r->timestart,
        'timefinish'    => $r->timefinish,
        'score'         => $r->score,
        'maxscore'      => $r->maxscore,
        'percentage'    => $r->percentage,
        'quiz_type'     => $r->quiz_type ?? '',
        'comment_count' => $comment_count,
        'reviewurl'     => $reviewurl->out(false),
        'gradeurl'      => $gradeurl ? $gradeurl->out(false) : '',
    ];
}

// Generate month options for the past 12 months
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $month_value = date('Y-m', strtotime("-$i months"));
    $month_label = date('F Y', strtotime("-$i months"));
    $month_options[$month_value] = $month_label;
}

// Function to generate sort arrows
function getSortArrows($column, $current_sort, $current_dir) {
    $up_class = '';
    $down_class = '';
    
    if ($current_sort === $column) {
        if ($current_dir === 'ASC') {
            $up_class = ' active';
        } else {
            $down_class = ' active';
        }
    }
    
    return '<span class="sort-arrows">
                <span class="arrow up' . $up_class . '">▲</span>
                <span class="arrow down' . $down_class . '">▼</span>
            </span>';
}

// --------------- Render -----------------
echo $OUTPUT->header();

// Include navigation fallback
require_once(__DIR__ . '/navigation_fallback.php');

// Custom HTML for enhanced dashboard
?>

<div class="essay-dashboard-container">

    <!-- Filters Section -->
    <div class="dashboard-filters">
        <form method="GET" class="filter-form">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="categoryid">Category:</label>
                    <select name="categoryid" id="categoryid">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo (int)$cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="coursename">Course:</label>
                    <select name="coursename" id="coursename">
                        <option value="">All Courses</option>
                        <?php foreach ($unique_courses as $course): ?>
                            <option value="<?php echo htmlspecialchars($course->fullname); ?>" data-courseid="<?php echo (int)$course->id; ?>"
                                <?php echo $coursename === $course->fullname ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sectionid">Section:</label>
                    <select name="sectionid" id="sectionid">
                        <option value="">All Sections</option>
                        <?php foreach ($unique_sections as $section): ?>
                            <option value="<?php echo $section->id; ?>" 
                                <?php echo $sectionid == $section->id ? 'selected' : ''; ?>>
                                <?php 
                                $section_display = !empty($section->name) ? $section->name : "Section {$section->section}";
                                echo htmlspecialchars($section_display . " ({$section->coursename})"); 
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="quizname">Quiz:</label>
                    <select name="quizname" id="quizname">
                        <option value="">All Quizzes</option>
                        <?php foreach ($unique_quizzes as $quiz): ?>
                            <option value="<?php echo htmlspecialchars($quiz->name); ?>" 
                                <?php echo $quizname === $quiz->name ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($quiz->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="quiztype">Quiz Type:</label>
                    <select name="quiztype" id="quiztype">
                        <option value="">All Types</option>
                        <option value="Essay" <?php echo $quiztype === 'Essay' ? 'selected' : ''; ?>>Essay</option>
                        <option value="Non-Essay" <?php echo $quiztype === 'Non-Essay' ? 'selected' : ''; ?>>Non-Essay</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="studentname">User:</label>
                    <select name="studentname" id="studentname">
                        <option value="">All Users</option>
                        <?php foreach ($unique_users as $user): ?>
                            <option value="<?php echo htmlspecialchars($user->fullname); ?>" 
                                <?php echo $studentname === $user->fullname ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="userid">User ID:</label>
                    <select name="userid" id="userid">
                        <option value="">All User IDs</option>
                        <?php foreach ($unique_userids as $user): ?>
                            <option value="<?php echo $user->userid; ?>" 
                                <?php echo $userid == $user->userid ? 'selected' : ''; ?>>
                                <?php echo $user->userid; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="">All</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="finished" <?php echo $status === 'finished' ? 'selected' : ''; ?>>Finished</option>
                        <option value="in progress" <?php echo $status === 'in progress' ? 'selected' : ''; ?>>In Progress</option>

                    </select>
                </div>

                <div class="filter-group">
                    <label for="month">Month:</label>
                    <select name="month" id="month">
                        <option value="">All Months</option>
                        <?php foreach ($month_options as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $month === $value ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Reset</button>
                </div>
            </div>

            <!-- Hidden fields for maintaining other filters -->
            <input type="hidden" name="sort" value="<?php echo $sort; ?>">
            <input type="hidden" name="dir" value="<?php echo $dir; ?>">
        </form>
    </div>

    <!-- Bulk Actions Section -->
    <div class="bulk-actions-container">
        <div class="bulk-actions-row">
            <div class="bulk-actions-group">
                <select id="bulk-action-select" class="bulk-action-dropdown">
                    <option value="">With selected...</option>
                        <option value="export">Export Data</option>
                        <option value="delete">Delete Permanently</option>
                </select>
                <button type="button" class="btn btn-secondary" onclick="executeBulkAction()" disabled id="apply-bulk-action">Apply</button>
            </div>
            <div class="selected-count">
                <span id="selected-count">0 items selected</span>
            </div>
        </div>
    </div>

    <!-- Results Table -->
    <div class="dashboard-table-container">
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th class="bulk-select-header">
                        <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)">
                    </th>
                    <th class="sortable-column" data-sort="userid">
                        ID 
                        <?php echo getSortArrows('userid', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="studentname">
                        Name 
                        <?php echo getSortArrows('studentname', $sort, $dir); ?>
                    </th>
                    <th>Category</th>
                    <th class="sortable-column" data-sort="coursename">
                        Course 
                        <?php echo getSortArrows('coursename', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="quizname">
                        Quiz 
                        <?php echo getSortArrows('quizname', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="attemptno">
                        Attempt 
                        <?php echo getSortArrows('attemptno', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="quiz_type">
                        Quiz Type 
                        <?php echo getSortArrows('quiz_type', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="status">
                        Status 
                        <?php echo getSortArrows('status', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="timefinish">
                        Finished 
                        <?php echo getSortArrows('timefinish', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="time_taken">
                        Duration
                        <?php echo getSortArrows('time_taken', $sort, $dir); ?>
                    </th>
                    <th class="sortable-column" data-sort="score">
                        Score 
                        <?php echo getSortArrows('score', $sort, $dir); ?>
                    </th>
                    <th>
                        Comment
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="12" class="no-data">No quiz submissions found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="bulk-select-cell">
                                <input type="checkbox" class="row-checkbox" value="<?php echo $row->attemptid; ?>" onchange="updateSelectedCount()">
                            </td>
                            <td>
                                <a href="<?php echo $row->user_profile_url; ?>" class="user-id-link" target="_blank">
                                    <?php echo $row->userid; ?>
                                </a>
                            </td>
						<td>
							<a href="<?php echo (new moodle_url('/local/quizdashboard/index.php', ['studentname' => $row->studentname]))->out(false); ?>" class="user-name-link">
								<?php echo htmlspecialchars($row->studentname); ?>
							</a>
						</td>
                            <td>
                                <?php if (!empty($row->categoryid)): ?>
                                    <a href="<?php echo (new moodle_url('/local/quizdashboard/index.php', ['categoryid' => (int)$row->categoryid]))->out(false); ?>">
                                        <?php echo htmlspecialchars($row->categoryname ?? ''); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($row->categoryname ?? ''); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/quizdashboard/index.php', ['coursename' => $row->coursename]))->out(false); ?>" class="course-link">
                                    <?php echo htmlspecialchars($row->coursename); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo (new moodle_url('/local/quizdashboard/index.php', ['quizname' => $row->quizname]))->out(false); ?>" class="quiz-link">
                                    <?php echo htmlspecialchars($row->quizname); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo $row->reviewurl; ?>" class="attempt-link" target="_blank">
                                    <?php echo $row->attemptno; ?>
                                </a>
                            </td>
                            <td>
                                <?php $qt = trim($row->quiz_type) !== '' ? $row->quiz_type : 'Non-Essay'; ?>
                                <a href="<?php echo (new moodle_url('/local/quizdashboard/index.php', ['quiztype' => $qt]))->out(false); ?>">
                                    <span class="quiz-type-badge quiz-type-<?php echo strtolower(str_replace('-', '', $qt)); ?>">
                                        <?php echo htmlspecialchars($qt); ?>
                                    </span>
                                </a>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $row->status)); ?>">
                                    <?php echo htmlspecialchars($row->status); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo !empty($row->timefinish) ? date('Y-m-d H:i', $row->timefinish) : '-'; ?>
                            </td>
                            <td>
                                <?php echo $row->time_taken ?: '-'; ?>
                            </td>
                            <td>
                                <?php 
                                if ($row->score !== null && $row->maxscore !== null) {
                                    echo round($row->score) . ' / ' . round($row->maxscore);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td class="comment-cell-count">
                                <a href="<?php echo $row->reviewurl; ?>" class="comment-link" title="View/Add comments">
                                    <span class="comment-icon">💬</span>
                                    <span class="comment-count"><?php echo $row->comment_count; ?></span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Define Moodle's sesskey for JavaScript
const SESSKEY = "<?php echo sesskey(); ?>";

// Reset filters function
function resetFilters() {
    window.location.href = window.location.pathname;
}

// Interactive filter functions for cascading dropdowns - COMPLETELY REWRITTEN
function initializeInteractiveFilters() {
    console.log('Initializing interactive filters...'); // Debug log
    
    const sectionSelect = document.getElementById('sectionid');
    const courseSelect = document.getElementById('coursename');
    const quizSelect    = document.getElementById('quizname');
    
    if (!sectionSelect || !courseSelect) {
        console.log('Missing select elements:', {sectionSelect, courseSelect}); // Debug log
        return;
    }
    
    // Load sections via the Quiz Uploader AJAX endpoint
    function loadSections(courseId) {
        if (!sectionSelect) return;
        // Reset list
        sectionSelect.innerHTML = '<option value="">All Sections</option>';
        if (!courseId) { return; }
        const url = M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + encodeURIComponent(courseId) + '&sesskey=' + SESSKEY;
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(list){
                const currentSectionId = '<?php echo $sectionid; ?>';
                let html = '<option value="">All Sections</option>';
                (list || []).forEach(function(s){
                    const label = s.name || ('Section ' + s.section);
                    const sel = (currentSectionId && (''+currentSectionId) === (''+s.id)) ? ' selected' : '';
                    html += '<option value="' + s.id + '"' + sel + '>' + label.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
                });
                sectionSelect.innerHTML = html;
                // After sections load, refresh quizzes to reflect combination
                refreshQuizzes();
            })
            .catch(function(e){ console.warn('Section refresh failed', e); });
    }
    
    // Add event listener to course dropdown
    courseSelect.addEventListener('change', function() {
        console.log('Course changed to:', this.value); // Debug log
        const opt = this.options[this.selectedIndex];
        const cid = opt ? opt.getAttribute('data-courseid') : '';
        loadSections(cid);
    });

    // When section changes, refresh quizzes
    if (sectionSelect) {
        sectionSelect.addEventListener('change', function() {
            refreshQuizzes();
        });
    }
    
    // Initialize filters on page load
    setTimeout(function(){
        const opt = courseSelect.options[courseSelect.selectedIndex];
        const cid = opt ? opt.getAttribute('data-courseid') : '';
        loadSections(cid);
        refreshQuizzes();
    }, 100);

    // Fetch quizzes for selected course/section using plugin AJAX
    function refreshQuizzes(){
        if (!quizSelect) return;
        const courseName = courseSelect.value;
        if (!courseName) {
            return; // list already populated with all quizzes (server-rendered) when no course filter
        }
        // Get actual course id from selected option's data attribute
        const selectedOption = courseSelect.options[courseSelect.selectedIndex];
        const courseId = selectedOption ? selectedOption.getAttribute('data-courseid') : '';
        const sectionId = sectionSelect && sectionSelect.value ? sectionSelect.value : '';
        if (!courseId) return;
        const url = M.cfg.wwwroot + '/local/quizdashboard/ajax.php?action=get_quizzes&sesskey=' + SESSKEY + '&courseid=' + encodeURIComponent(courseId) + (sectionId ? ('&sectionid=' + sectionId) : '');
        fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (!data || data.success !== true) return;
            const current = '<?php echo addslashes($quizname); ?>';
            let opts = document.createElement('select');
            let html = '<option value="">All Quizzes</option>';
            data.quizzes.forEach(function(q){
                const sel = (current && current === q.name) ? ' selected' : '';
                html += '<option value="' + q.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '"' + sel + '>' + q.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
            });
            quizSelect.innerHTML = html;
        })
        .catch(function(e){ console.warn('Quiz refresh failed', e); });
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initializeInteractiveFilters);

// Bulk action functions
function toggleAllCheckboxes(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.row-checkbox');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selected-count');
    const applyButton = document.getElementById('apply-bulk-action');
    
    selectedCountSpan.textContent = count + (count === 1 ? ' item selected' : ' items selected');
    applyButton.disabled = count === 0;
    
    // Update master checkbox
    const masterCheckbox = document.getElementById('select-all');
    const allCheckboxes = document.querySelectorAll('.row-checkbox');
    masterCheckbox.checked = count > 0 && count === allCheckboxes.length;
    masterCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
}

function executeBulkAction() {
    const actionSelect = document.getElementById('bulk-action-select');
    const selectedAction = actionSelect.value;
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    
    if (!selectedAction || checkboxes.length === 0) {
        alert('Please select an action and at least one item.');
        return;
    }
    
    const attemptIds = Array.from(checkboxes).map(cb => cb.value);
    let confirmMessage = '';
    let actionType = selectedAction;
    
    // Set confirmation messages for different actions
    if (selectedAction === 'delete') {
        confirmMessage = `⚠️ WARNING: This will PERMANENTLY DELETE ${attemptIds.length} quiz attempt(s).\n\nThis action CANNOT be undone!\n\nProceed?`;
    } else if (selectedAction === 'export') {
        confirmMessage = `Export data for ${attemptIds.length} attempt(s) to CSV?`;
    } else {
        confirmMessage = `Apply ${selectedAction} to ${attemptIds.length} item(s)?`;
    }
    
    if (!confirm(confirmMessage)) {
        return;
    }
    
    const button = document.getElementById('apply-bulk-action');
    const originalText = button.textContent;
    button.textContent = 'Processing...';
    button.disabled = true;
    
    // Build request body
    const body = new URLSearchParams({
        action: 'bulk_action',
        bulk_action: actionType,
        attempt_ids: attemptIds.join(','),
        sesskey: SESSKEY
    });

    // Send AJAX request for bulk action
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.download_url) {
                // Handle export - open download link
                alert(data.message);
                const downloadLink = document.createElement('a');
                downloadLink.href = data.download_url;
                downloadLink.download = data.filename || 'quiz_export.csv';
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
                
                // Reset form without reloading page
                document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
                document.getElementById('select-all').checked = false;
                updateSelectedCount();
                actionSelect.value = '';
            } else {
                alert(data.message);
                window.location.reload();
            }
        } else {
            alert('Error: ' + data.message);
        }
        
        button.textContent = originalText;
        button.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while processing the bulk action.');
        button.textContent = originalText;
        button.disabled = false;
    });
}

// Save grade function
function saveGrade(button, attemptId) {
    const input = document.querySelector(`input.grade-input[data-attemptid="${attemptId}"]`);
    const grade = parseFloat(input.value);
    
    if (isNaN(grade) || grade < 0) {
        alert('Please enter a valid grade.');
        return;
    }
    
    const body = new URLSearchParams({
        action: 'save_grade',
        attemptid: attemptId,
        grade: grade,
        sesskey: SESSKEY
    });

    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success feedback
            const originalText = button.textContent;
            button.textContent = 'Saved!';
            button.classList.add('success-animate');
            setTimeout(() => {
                button.textContent = originalText;
                button.classList.remove('success-animate');
            }, 2000);
        } else {
            alert('Error saving grade: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving the grade.');
    });
}

// Sortable columns functionality
document.querySelectorAll('.sortable-column').forEach(function(header) {
    header.addEventListener('click', function() {
        const sortField = this.getAttribute('data-sort');
        const currentSort = '<?php echo $sort; ?>';
        const currentDir = '<?php echo $dir; ?>';
        
        let newDir = 'ASC';
        if (currentSort === sortField && currentDir === 'ASC') {
            newDir = 'DESC';
        }
        
        // Build URL with current filters
        const url = new URL(window.location.href);
        url.searchParams.set('sort', sortField);
        url.searchParams.set('dir', newDir);
        
        window.location.href = url.toString();
    });
});
</script>

<?php
// Fixed footer call with error handling
try {
    echo $OUTPUT->footer();
} catch (Exception $e) {
    // Fallback if footer() fails due to hook issues
    error_log("Footer rendering failed: " . $e->getMessage());
    echo '</div></body></html>'; // Basic HTML closure
}



