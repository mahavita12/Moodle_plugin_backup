<?php
require_once('../../config.php');

require_login();
$context = context_system::instance();

// Capability check.
if (!has_capability('local/quizdashboard:view', $context)) {
    print_error('noaccess', 'local_quizdashboard');
}

$PAGE->set_url('/local/quizdashboard/questions.php');
$PAGE->set_context($context);
$PAGE->set_title('Questions Dashboard');
$PAGE->set_heading('Questions Dashboard');
$PAGE->set_pagelayout('admin');

// Add CSS for styling
$PAGE->requires->css('/local/quizdashboard/styles.css');

// Add blocks toggle functionality directly
$PAGE->requires->js_init_code('
document.addEventListener("DOMContentLoaded", function() {
    console.log("Questions Dashboard: Blocks toggle DOM ready");
    
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
    
    console.log("Questions Dashboard: Blocks found:", hasBlocks);
    
    if (hasBlocks && !document.querySelector(".global-blocks-toggle")) {
        console.log("Questions Dashboard: Creating blocks toggle button");
        
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
            
            console.log("Questions Dashboard: Blocks toggle:", newState ? "hidden" : "visible");
        });
        
        console.log("Questions Dashboard: Blocks toggle button created successfully");
    }
});
');

// Add custom CSS for Questions Dashboard
echo '<style>
/* Table styling with sticky user details columns */
.questions-dashboard-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin: 20px 0;
}

.questions-dashboard-table th,
.questions-dashboard-table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: center;
    vertical-align: middle;
}

.questions-dashboard-table th {
    background: #5a6c7d !important; /* Match Quiz Dashboard gray header */
    color: #fff;
    font-weight: bold;
    position: sticky;
    top: 0;
    z-index: 20; /* Higher z-index to ensure headers show above everything */
}

/* Sticky columns for essential user details (first 4 columns only) */
.questions-dashboard-table th:nth-child(1),
.questions-dashboard-table th:nth-child(2),
.questions-dashboard-table th:nth-child(3),
.questions-dashboard-table th:nth-child(4) {
    position: sticky;
    background: #5a6c7d !important; /* Match Quiz Dashboard gray headers */
    z-index: 25; /* Highest priority for sticky header columns */
    border-right: 2px solid #dee2e6; /* Visual separator */
    top: 0; /* Ensure headers stick to top */
}

.questions-dashboard-table td:nth-child(1),
.questions-dashboard-table td:nth-child(2),
.questions-dashboard-table td:nth-child(3),
.questions-dashboard-table td:nth-child(4) {
    position: sticky;
    background: #fff !important;
    z-index: 18; /* Higher than non-sticky content */
    border-right: 2px solid #dee2e6; /* Visual separator */
}

/* Sticky positioning for essential columns only (Checkbox, User ID, User Name, Attempt) */
.questions-dashboard-table th:nth-child(1),
.questions-dashboard-table td:nth-child(1) { 
    left: 0px; 
    width: 40px; 
    min-width: 40px; 
    max-width: 40px; 
}

.questions-dashboard-table th:nth-child(2),
.questions-dashboard-table td:nth-child(2) { 
    left: 40px; 
    width: 60px; 
    min-width: 60px; 
    max-width: 60px; 
}

.questions-dashboard-table th:nth-child(3),
.questions-dashboard-table td:nth-child(3) { 
    left: 100px; 
    width: 120px; 
    min-width: 120px; 
    max-width: 120px; 
}

.questions-dashboard-table th:nth-child(4),
.questions-dashboard-table td:nth-child(4) { 
    left: 220px; 
    width: 60px; 
    min-width: 60px; 
    max-width: 60px; 
}

/* Non-sticky columns (Score, Date, Duration) - normal flow */
.questions-dashboard-table th:nth-child(5),
.questions-dashboard-table td:nth-child(5) { 
    width: 100px; 
    min-width: 100px; 
    max-width: 100px; 
}

.questions-dashboard-table th:nth-child(6),
.questions-dashboard-table td:nth-child(6) { 
    width: 110px; 
    min-width: 110px; 
    max-width: 110px; 
}

.questions-dashboard-table th:nth-child(7),
.questions-dashboard-table td:nth-child(7) { 
    width: 80px; 
    min-width: 80px; 
    max-width: 80px; 
}

/* Visual separation after Attempt column (4th column) */
.questions-dashboard-table th:nth-child(4),
.questions-dashboard-table td:nth-child(4) {
    border-right: 3px solid #5a6c7d !important; /* Gray separator to match headers */
    box-shadow: 2px 0 5px rgba(90, 108, 125, 0.2); /* Gray-tinted shadow for separation */
}

.questions-dashboard-table tr:nth-child(even) {
    background-color: #f9f9f9;
}

.questions-dashboard-table tr:hover {
    background-color: #f5f5f5;
}

/* Column widths */
.col-checkbox { width: 50px; min-width: 50px; }
.col-userid { width: 80px; min-width: 80px; }
.col-username { width: 150px; min-width: 150px; }
.col-attempt { width: 70px; min-width: 70px; }
.col-score { width: 100px; min-width: 100px; }
.col-date { width: 120px; min-width: 120px; }
.col-duration { width: 80px; min-width: 80px; }
.col-question { width: 80px; min-width: 80px; }

/* Bulk Actions Styling */
.bulk-actions-container {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 10px;
    margin: 15px 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.bulk-actions-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.bulk-actions-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.bulk-action-dropdown {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: #fff;
    font-size: 14px;
    min-width: 200px;
}

.selected-count {
    font-size: 14px;
    color: #6c757d;
    font-weight: 500;
}

/* Question result symbols */
.question-result-correct { color: #28a745; font-size: 16px; font-weight: bold; }
.question-result-partial { color: #ffc107; font-size: 16px; font-weight: bold; }
.question-result-incorrect { color: #dc3545; font-size: 16px; font-weight: bold; }
.question-result-na { color: #6c757d; font-size: 14px; }

/* User links styling */
.user-id-link, .user-name-link {
    color: #007cba;
    text-decoration: none;
}

.user-id-link:hover, .user-name-link:hover {
    text-decoration: underline;
}

/* Enhanced table container with smooth horizontal scroll */
.table-container {
    overflow-x: auto;
    overflow-y: visible;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    position: relative;
    /* Smooth scrolling */
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

/* Ensure proper scrolling behavior - Fixed columns take up 280px, then scrollable columns start */
.questions-dashboard-table {
    min-width: calc(280px + 290px + 80px * var(--question-count, 10)); /* 280px sticky + 290px scrollable + questions */
    table-layout: auto; /* Allow natural column sizing */
    border-collapse: collapse; /* Standard table borders for sticky headers */
}

/* Question columns (Q1, Q2, Q3, etc.) - these will scroll horizontally */
.questions-dashboard-table th:nth-child(n+8) {
    width: 80px;
    min-width: 80px;
    max-width: 80px;
    background: #5a6c7d !important; /* Match Quiz Dashboard gray header background */
    color: #fff;
    border-left: 1px solid #dee2e6;
    position: sticky;
    top: 0;
    z-index: 20; /* Same as other headers */
}

.questions-dashboard-table td:nth-child(n+8) {
    width: 80px;
    min-width: 80px;
    max-width: 80px;
    background: #fff; /* Ensure white background for scrolling columns */
    border-left: 1px solid #dee2e6;
}

/* Ensure question header links are visible on dark header */
.questions-dashboard-table th.col-question a,
.questions-dashboard-table th.col-question a:visited {
    color: #ffffff !important;
    text-decoration: underline;
}

/* Alternating row colors that respect sticky columns (first 4 only) */
.questions-dashboard-table tr:nth-child(even) td:nth-child(1),
.questions-dashboard-table tr:nth-child(even) td:nth-child(2),
.questions-dashboard-table tr:nth-child(even) td:nth-child(3),
.questions-dashboard-table tr:nth-child(even) td:nth-child(4) {
    background-color: #f9f9f9 !important;
}

/* Scrollbar styling for better UX */
.table-container::-webkit-scrollbar {
    height: 12px;
}

.table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 6px;
}

.table-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 6px;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}


</style>';

// ---------------- Filters ----------------
$courseid       = optional_param('courseid', 0, PARAM_INT);
$categoryid     = optional_param('categoryid', 0, PARAM_INT);
$quizid         = optional_param('quizid', 0, PARAM_INT);
$quiztype       = optional_param('quiztype', '', PARAM_TEXT);
$userid         = optional_param('userid', 0, PARAM_INT);
$user_id        = optional_param('user_id', 0, PARAM_INT); // Alternative param name
$sectionid      = optional_param('sectionid', 0, PARAM_INT); // NEW: Section filter
$status         = optional_param('status', '', PARAM_ALPHA);
$month          = optional_param('month', '', PARAM_TEXT);
$sort           = optional_param('sort', 'timecreated', PARAM_ALPHA);
$dir            = optional_param('dir', 'DESC', PARAM_ALPHA);

// Handle both userid and user_id parameters
if (!empty($user_id) && empty($userid)) {
    $userid = $user_id;
}

// Standardize userid parameter name
if (!empty($userid) && empty($user_id)) {
    $user_id = $userid;
}

// ---------------- Data Manager ----------------
require_once('classes/questions_manager.php');
$questionsmanager = new \local_quizdashboard\questions_manager();

// Get filter options
$categories = [];
try {
    $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
    if (empty($categoryid)) {
        $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id');
        if ($catrow) { $categoryid = (int)$catrow->id; }
    }
} catch (\Throwable $e) { /* ignore */ }

$courses = $questionsmanager->get_unique_courses((int)$categoryid);
$sections = $questionsmanager->get_unique_sections((int)$categoryid); // NEW: Get sections

// Debug: Log sections data
error_log('Questions Dashboard: Sections data count: ' . count($sections));
if (!empty($sections)) {
    error_log('Questions Dashboard: First section: ' . print_r(reset($sections), true));
} else {
    error_log('Questions Dashboard: NO SECTIONS DATA RETRIEVED!');
}

$quizzes = [];
if ($courseid) {
    $quizzes = $questionsmanager->get_quizzes_by_course($courseid);
}
$users = $questionsmanager->get_unique_users();
$user_ids = $questionsmanager->get_unique_user_ids();

// Initialize arrays
$user_attempts = [];
$quiz_questions = [];
$question_results = [];

// Get course and cm info for question links
$cmid = 0;
if ($quizid) {
    try {
        list($course, $cm) = get_course_and_cm_from_instance($quizid, 'quiz');
        $cmid = $cm->id;
    } catch (Exception $e) {
        error_log('Error getting course and cm for quiz ' . $quizid . ': ' . $e->getMessage());
    }
}

// Only get data if we have a valid quiz ID
if ($quizid) {
    // Get question-level data in matrix format
    try {
        $data = $questionsmanager->get_question_results_matrix(
            $courseid, $quizid, $quiztype, $userid, $status, $month, $sort, $dir, (int)$categoryid
        );
        
        $user_attempts = $data['user_attempts'] ?? [];
        $quiz_questions = $data['quiz_questions'] ?? [];
        $question_results = $data['question_results'] ?? [];
        
        // If main method returns empty data but we have a quizid, try the simple fallback
        if (empty($user_attempts) && empty($quiz_questions) && $quizid) {
            error_log('[QDB] Main method returned no data, trying simple fallback for quiz ID: ' . $quizid);
            $fallback_data = $questionsmanager->get_simple_question_matrix($quizid);
            
            $user_attempts = $fallback_data['user_attempts'] ?? [];
            $quiz_questions = $fallback_data['quiz_questions'] ?? [];
            $question_results = $fallback_data['question_results'] ?? [];
            
            if (!empty($user_attempts) || !empty($quiz_questions)) {
                error_log('[QDB] Fallback method succeeded with ' . count($user_attempts) . ' attempts and ' . count($quiz_questions) . ' questions');
            }
        }
        
    } catch (Exception $e) {
        error_log('[QDB] Main method completely failed: ' . $e->getMessage());
        
        // Try fallback method if main method completely fails
        try {
            error_log('[QDB] Trying simple fallback for quiz ID: ' . $quizid);
            $fallback_data = $questionsmanager->get_simple_question_matrix($quizid);
            $user_attempts = $fallback_data['user_attempts'] ?? [];
            $quiz_questions = $fallback_data['quiz_questions'] ?? [];
            $question_results = $fallback_data['question_results'] ?? [];
        } catch (Exception $fe) {
            error_log('[QDB] Fallback method also failed: ' . $fe->getMessage());
            $user_attempts = [];
            $quiz_questions = [];
            $question_results = [];
        }
    }
} else {
    error_log('[QDB] No quiz ID provided - showing selection notice');
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

// Custom HTML for Questions Dashboard
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
                    <label for="courseid">Course:</label>
                    <select name="courseid" id="courseid">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo $course->id; ?>" 
                                <?php echo $courseid == $course->id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="sectionid">Section:</label>
                    <select name="sectionid" id="sectionid">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $section): ?>
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
                    <label for="quizid">Quiz:</label>
                    <select name="quizid" id="quizid">
                        <option value="">Select Quiz</option>
                        <?php if ($courseid && !empty($quizzes)): ?>
                            <?php foreach ($quizzes as $quiz): ?>
                                <option value="<?php echo $quiz->id; ?>" 
                                    <?php echo $quizid == $quiz->id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($quiz->name); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php elseif ($courseid): ?>
                            <option value="">No quizzes found in this course</option>
                        <?php endif; ?>
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
                    <label for="userid">User:</label>
                    <select name="userid" id="userid">
                        <option value="">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user->id; ?>" 
                                <?php echo $userid == $user->id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user->fullname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="user_id">User ID:</label>
                    <select name="user_id" id="user_id">
                        <option value="">All User IDs</option>
                        <?php foreach ($user_ids as $user_id_option): ?>
                            <option value="<?php echo $user_id_option->userid; ?>" 
                                <?php echo $userid == $user_id_option->userid ? 'selected' : ''; ?>>
                                <?php echo $user_id_option->userid; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="">All</option>
                        <option value="correct" <?php echo $status === 'correct' ? 'selected' : ''; ?>>Correct</option>
                        <option value="incorrect" <?php echo $status === 'incorrect' ? 'selected' : ''; ?>>Incorrect</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="month">Month:</label>
                    <select name="month" id="month">
                        <option value="">All Months</option>
                        <?php foreach ($month_options as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $month === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
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
    <div class="table-container">
        <table class="questions-dashboard-table">
            <thead>
                <tr>
                    <th class="col-checkbox">
                        <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)">
                    </th>
                    <th class="col-userid">User ID</th>
                    <th class="col-username">User Name</th>
                    <th class="col-attempt">Attempt</th>
                    <th>Category</th>
                    <th>Course</th>
                    <th class="col-score">Score</th>
                    <th class="col-date">Date</th>
                    <th class="col-duration">Duration</th>
                    <?php if (!empty($quiz_questions)): ?>
                        <?php foreach ($quiz_questions as $question): ?>
                            <?php 
                                // Build edit link to question bank for this question
                                $returnurl = new moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]);
                                $editurl = new moodle_url('/question/bank/editquestion/question.php', [
                                    'returnurl' => $returnurl->out_as_local_url(false),
                                    'cmid' => $cmid,
                                    'id' => $question->id
                                ]);
                            ?>
                            <th class="col-question">
                                <a href="<?php echo $editurl->out(false); ?>" target="_blank" rel="noopener" title="Open question in a new tab">Q<?php echo $question->slot_number; ?></a>
                            </th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                <?php if (!empty($quiz_questions)): ?>
                <tr style="background: #6c757d; color: #fff; font-style: italic;">
                    <th colspan="9"></th>
                    <?php foreach ($quiz_questions as $question): ?>
                        <th class="col-question" style="font-size: 10px;" title="<?php echo htmlspecialchars(strip_tags($question->questiontext)); ?>">
                            <?php 
                                $preview = strip_tags($question->questiontext);
                                $words = explode(' ', trim($preview));
                                $short_preview = implode(' ', array_slice($words, 0, 2));
                                echo htmlspecialchars($short_preview);
                            ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if (empty($user_attempts)): ?>
                    <tr>
                        <td colspan="<?php echo 7 + count($quiz_questions); ?>" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                            <?php if (!$quizid): ?>
                                Please select a course and quiz to view question-level results
                            <?php else: ?>
                                No question attempts found for the selected quiz
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    // Get question timing data for each attempt
                    $question_timings_cache = [];
                    foreach ($user_attempts as $temp_attempt) {
                        if (!empty($temp_attempt->attemptid)) {
                            $question_timings_cache[$temp_attempt->attemptid] = $questionsmanager->get_question_timings($temp_attempt->attemptid);
                        }
                    }
                    ?>
                    <?php foreach ($user_attempts as $attempt): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="row-checkbox" value="<?php echo $attempt->attemptid; ?>" onchange="updateSelectedCount()">
                            </td>
						<td>
							<a href="<?php echo (new moodle_url('/local/quizdashboard/questions.php', ['userid' => $attempt->userid, 'courseid' => $courseid, 'quizid' => $quizid]))->out(false); ?>" class="user-id-link">
								<?php echo $attempt->userid; ?>
							</a>
						</td>
						<td style="text-align: left;">
							<a href="<?php echo (new moodle_url('/local/quizdashboard/questions.php', ['userid' => $attempt->userid, 'courseid' => $courseid, 'quizid' => $quizid]))->out(false); ?>" class="user-name-link">
								<?php echo htmlspecialchars($attempt->username); ?>
							</a>
						</td>
                            <td>
                                <?php echo !empty($attempt->attemptno) ? $attempt->attemptno : '-'; ?>
                            </td>
                            <td>
                                <?php if (!empty($attempt->categoryid)): ?>
                                    <a href="<?php echo (new moodle_url('/local/quizdashboard/questions.php', [
                                        'categoryid' => (int)$attempt->categoryid,
                                        'courseid' => $courseid,
                                        'quizid' => $quizid
                                    ]))->out(false); ?>">
                                        <?php echo htmlspecialchars($attempt->categoryname ?? ''); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($attempt->categoryname ?? ''); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($attempt->coursename ?? ''); ?></td>
                            <td>
                                <?php 
                                if (isset($attempt->total_score) && isset($attempt->max_score)) {
                                    echo round($attempt->total_score, 1) . ' / ' . round($attempt->max_score, 1);
                                    if ($attempt->max_score > 0) {
                                        echo '<br><small>(' . round(($attempt->total_score / $attempt->max_score) * 100, 1) . '%)</small>';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <?php echo !empty($attempt->timefinish) ? date('Y-m-d H:i', $attempt->timefinish) : '-'; ?>
                            </td>
                            <td>
                                <?php 
                                if (!empty($attempt->duration_seconds)) {
                                    $duration = $attempt->duration_seconds;
                                    $hours = floor($duration / 3600);
                                    $minutes = floor(($duration % 3600) / 60);
                                    $seconds = $duration % 60;
                                    
                                    if ($hours > 0) {
                                        echo sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
                                    } else if ($minutes > 0) {
                                        echo sprintf('%d:%02d', $minutes, $seconds);
                                    } else {
                                        echo sprintf('%ds', $seconds);
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            
                            <?php if (!empty($quiz_questions)): ?>
                                <?php foreach ($quiz_questions as $question): ?>
                                    <td>
                                        <?php 
                                        $result_key = $attempt->attemptid . '_' . $question->slot_number;
                                        if (isset($question_results[$result_key])) {
                                            $result = $question_results[$result_key];
                                            if ($result->fraction >= 1.0) {
                                                echo '<span class="question-result-correct" title="Correct">✓</span>';
                                            } elseif ($result->fraction > 0.0) {
                                                echo '<span class="question-result-partial" title="Partial Credit">◐</span>';
                                            } else {
                                                echo '<span class="question-result-incorrect" title="Incorrect">✗</span>';
                                            }
                                            
                                            // Add question timing if available
                                            if (!empty($question_timings_cache[$attempt->attemptid][$question->id]['duration_seconds']) || !empty($question_timings_cache[$attempt->attemptid][$result->questionid]['duration_seconds'])) {
                                                $timing_slot_key = !empty($question_timings_cache[$attempt->attemptid][$result->questionid]) ? $result->questionid : $question->id;
                                                $timing_data = $question_timings_cache[$attempt->attemptid][$timing_slot_key];
                                                $q_duration = $timing_data['duration_seconds'];
                                                $has_meaningful_data = !empty($timing_data['has_meaningful_data']);
                                                
                                                // Only show timing if duration is reasonable (> 0 and < 2 hours)
                                                if ($q_duration > 0 && $q_duration < 7200) {
                                                    $q_hours = floor($q_duration / 3600);
                                                    $q_minutes = floor(($q_duration % 3600) / 60);
                                                    $q_seconds = $q_duration % 60;
                                                    
                                                    $time_display = '';
                                                    if ($q_hours > 0) {
                                                        $time_display = sprintf('%d:%02d:%02d', $q_hours, $q_minutes, $q_seconds);
                                                    } else if ($q_minutes > 0) {
                                                        $time_display = sprintf('%d:%02d', $q_minutes, $q_seconds);
                                                    } else {
                                                        $time_display = sprintf('%ds', $q_seconds);
                                                    }
                                                    
                                                    // Style based on data quality
                                                    if ($has_meaningful_data) {
                                                        $style = 'color: #666; font-size: 10px;';
                                                        $title = 'Time spent on question (based on interactions)';
                                                    } else {
                                                        $style = 'color: #999; font-style: italic; font-size: 10px;';
                                                        $title = 'Estimated time (calculated from step timestamps)';
                                                    }
                                                    
                                                    echo '<br><small style="' . $style . '" title="' . $title . '">' . $time_display . '</small>';
                                                }
                                            }
                                            
                                            // Add flags if available
                                            $flags = $questionsmanager->get_question_flags($attempt->userid, $result->questionid ?? $question->id, $quizid);
                                            if (!empty($flags)) {
                                                echo '<br><span class="question-flags">' . $flags . '</span>';
                                            }
                                        } else {
                                            echo '<span class="question-result-na">-</span>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
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

// Interactive filter functions - COPIED from working Quiz Dashboard
function initializeInteractiveFilters() {
    console.log('Questions Dashboard: Initializing interactive filters...'); // Debug log
    
    const sectionSelect = document.getElementById('sectionid');
    const courseSelect = document.getElementById('courseid');
    const quizSelect   = document.getElementById('quizid');
    
    if (!sectionSelect || !courseSelect) {
        console.log('Missing select elements:', {sectionSelect, courseSelect}); // Debug log
        return;
    }
    
    // Load sections via the Quiz Uploader AJAX endpoint
    function loadSections(courseId) {
        if (!sectionSelect) return;
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
                refreshQuizzes();
            })
            .catch(function(e){ console.warn('Section refresh failed', e); });
    }
    
    // Add event listener to course dropdown
    courseSelect.addEventListener('change', function() {
        console.log('Questions Dashboard course changed to:', this.value);
        loadSections(this.value);
    });
    
    // Initialize filters on page load
    setTimeout(function(){ loadSections(courseSelect.value); refreshQuizzes(); }, 100);

    function refreshQuizzes() {
        if (!quizSelect) return;
        const courseId = courseSelect.value;
        if (!courseId) return;
        const sectionId = sectionSelect && sectionSelect.value ? sectionSelect.value : '';
        const url = M.cfg.wwwroot + '/local/quizdashboard/ajax.php?action=get_quizzes&sesskey=' + SESSKEY + '&courseid=' + encodeURIComponent(courseId) + (sectionId ? ('&sectionid=' + sectionId) : '');
        fetch(url)
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (!data || data.success !== true) return;
                const current = '<?php echo (int)$quizid; ?>';
                let html = '<option value="">Select Quiz</option>';
                data.quizzes.forEach(function(q){
                    const sel = (current && (''+current) === (''+q.id)) ? ' selected' : '';
                    html += '<option value="' + q.id + '"' + sel + '>' + q.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
                });
                quizSelect.innerHTML = html;
            })
            .catch(function(e){ console.warn('Quiz refresh failed', e); });
    }

    if (sectionSelect) {
        sectionSelect.addEventListener('change', function(){ refreshQuizzes(); });
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
    
    const selectedItems = Array.from(checkboxes).map(cb => cb.value);
    let confirmMessage = '';
    
    // Set confirmation messages for different actions
    if (selectedAction === 'delete') {
        confirmMessage = `⚠️ WARNING: This will PERMANENTLY DELETE ${selectedItems.length} question attempt(s).\n\nThis action CANNOT be undone!\n\nProceed?`;
    } else if (selectedAction === 'export') {
        confirmMessage = `Export data for ${selectedItems.length} item(s) to CSV?`;
    } else {
        confirmMessage = `Apply ${selectedAction} to ${selectedItems.length} item(s)?`;
    }
    
    if (!confirm(confirmMessage)) {
        return;
    }
    
    const button = document.getElementById('apply-bulk-action');
    const originalText = button.textContent;
    button.textContent = 'Processing...';
    button.disabled = true;
    
    // selectedItems now contains actual attempt IDs
    const attemptIds = selectedItems;
    
    // Build the AJAX request URL
    let ajaxUrl = '<?php echo $CFG->wwwroot; ?>/local/quizdashboard/ajax.php';
    let body = new URLSearchParams();
    body.append('sesskey', SESSKEY);
    
    if (selectedAction === 'delete') {
        body.append('action', 'delete_question_attempts');
        body.append('attemptids', attemptIds.join(','));
        
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
            
            button.textContent = originalText;
            button.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the delete action.');
            button.textContent = originalText;
            button.disabled = false;
        });
        
    } else if (selectedAction === 'export') {
        body.append('action', 'export_attempts');
        body.append('attemptids', attemptIds.join(','));
        
        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.export_url) {
                alert(data.message);
                const downloadLink = document.createElement('a');
                downloadLink.href = data.export_url;
                downloadLink.download = data.filename || 'question_export.csv';
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
            } else {
                alert('Error: ' + (data.message || 'Export failed'));
            }
            
            button.textContent = originalText;
            button.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the export action.');
            button.textContent = originalText;
            button.disabled = false;
        });
        
    } else {
        alert(`${selectedAction} functionality is not implemented yet.`);
        button.textContent = originalText;
        button.disabled = false;
    }
}
</script>

<?php
echo $OUTPUT->footer();


