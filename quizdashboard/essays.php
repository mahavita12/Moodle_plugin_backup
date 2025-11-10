<?php
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/quiz_manager.php');
require_once(__DIR__.'/classes/essay_grader.php');

function render_submission_chain($chain, $resubmission_info = null) {
    if (empty($chain)) return '-';
    
    $current_position = 0;
    foreach ($chain as $submission) {
        if ($submission['is_current']) {
            $current_position = $submission['position'];
            break;
        }
    }
    
    $html = "<span class=\"submission-number\">{$current_position}</span>";
    
    if ($current_position > 1) {
        $html .= "<span class=\"resubmission-indicator\" title=\"This is a resubmission\">↻</span>";
        
        if ($resubmission_info && $resubmission_info->is_copy_detected) {
            $html .= "<span class=\"copy-warning\" title=\"Copy detected ({$resubmission_info->similarity_percentage}% similarity)\">⚠</span>";
        }
    }
    
    return $html;
}

require_login();

// The page context is set to system for navigation purposes.
$PAGE->set_context(context_system::instance());

$PAGE->set_url(new moodle_url('/local/quizdashboard/essays.php'));
$PAGE->set_title('Essay Dashboard');
$PAGE->set_heading('Essay Dashboard');
$PAGE->set_pagelayout('admin');

$PAGE->requires->css('/local/quizdashboard/styles.css');
$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');

// Add blocks toggle functionality directly
$PAGE->requires->js_init_code('
document.addEventListener("DOMContentLoaded", function() {
    console.log("Essay Dashboard: Blocks toggle DOM ready");
    
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
    
    console.log("Essay Dashboard: Blocks found:", hasBlocks);
    
    if (hasBlocks && !document.querySelector(".global-blocks-toggle")) {
        console.log("Essay Dashboard: Creating blocks toggle button");
        
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
            
            console.log("Essay Dashboard: Blocks toggle:", newState ? "hidden" : "visible");
        });
        
        console.log("Essay Dashboard: Blocks toggle button created successfully");
    }
});
');

// AJAX handlers are now in ajax.php
if (optional_param('action', '', PARAM_ALPHANUMEXT)) {
    require_once(__DIR__.'/ajax.php');
}

// ---------------- Filters ----------------
$userid      = optional_param('userid', '', PARAM_INT);
$studentname = optional_param('studentname', '', PARAM_TEXT);
$coursename  = optional_param('coursename', '', PARAM_TEXT);
$sectionid   = optional_param('sectionid', '', PARAM_INT);
$quizname    = optional_param('quizname', '', PARAM_TEXT);
$questionname = optional_param('questionname', '', PARAM_TEXT);
$month       = optional_param('month', '', PARAM_TEXT);
$status      = optional_param('status', '', PARAM_ALPHA);
$sort        = optional_param('sort', 'timefinish', PARAM_ALPHA);
$dir         = optional_param('dir', 'DESC', PARAM_ALPHA);


// ---------------- Data ----------------
$quizmanager = new \local_quizdashboard\quiz_manager();

$filterdata = $quizmanager->get_all_filter_data();
$unique_users = $filterdata['users'];
$unique_courses = $filterdata['courses'];
$unique_quizzes = $filterdata['quizzes'];
$unique_questions = $filterdata['questions'];
$unique_userids = $filterdata['userids'];

// FIXED: Pass status parameter to the quiz manager
$records = $quizmanager->get_filtered_quiz_attempts(
    $userid, $studentname, $coursename, $quizname, '', '', 'Essay', $sort, $dir, 0, 0, $status, $sectionid
);




global $DB;
$rows = [];
foreach ($records as $r) {
    $attemptid = (int)$r->attemptid;
    $reviewurl = new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]);

    $quizid = $DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
    $cmid = null;
    if ($quizid) {
        if ($cm = get_coursemodule_from_instance('quiz', $quizid)) {
            $cmid = $cm->id;
        }
    }
    $gradeurl  = $cmid ? new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'grading']) : null;
    $comment_count = $quizmanager->get_attempt_comment_count($attemptid);

    $time_taken = '';
    if (!empty($r->timestart) && !empty($r->timefinish)) {
        $duration_seconds = $r->timefinish - $r->timestart;
        $hours = floor($duration_seconds / 3600);
        $minutes = floor(($duration_seconds % 3600) / 60);
        $seconds = $duration_seconds % 60;
        $time_taken = sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
    }

    $question_edit_url = '';
    if (!empty($r->questionid) && $cmid) {
        $returnurl = new moodle_url('/local/quizdashboard/essays.php', $PAGE->url->params());
        $question_edit_url = new moodle_url('/question/bank/editquestion/question.php', [
            'returnurl' => $returnurl->out_as_local_url(false),
            'cmid' => $cmid,
            'id' => $r->questionid
        ]);
    }
    $user_profile_url = new moodle_url('/user/profile.php', ['id' => $r->userid]);
    $user_activity_url = new moodle_url('/report/outline/user.php', ['id' => $r->userid, 'course' => $r->courseid, 'mode' => 'outline']);
    $course_url = new moodle_url('/course/view.php', ['id' => $r->courseid]);

    $grading = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $attemptid]);
    $rows[] = (object) [
        'attemptid'     => $attemptid,
        'userid'        => $r->userid,
        'courseid'      => $r->courseid,
        'studentname'   => $r->studentname,
        'coursename'    => $r->coursename,
        'quizname'      => $r->quizname,
        'questionname'  => $r->questionname ?? 'N/A',
        'questionid'    => $r->questionid ?? null,
        'question_edit_url' => $question_edit_url ? $question_edit_url->out(false) : '',
        'user_profile_url' => $user_profile_url->out(false),
        'user_activity_url' => $user_activity_url->out(false),
        'course_url'    => $course_url->out(false),
        'time_taken'    => $time_taken,
        'attemptno'     => $r->attemptnumber,
        'status'        => $r->status,
        'timefinish'    => $r->timefinish,
        'score_content_ideas' => $r->score_content_ideas ?? null,
        'score_structure_organization' => $r->score_structure_organization ?? null,
        'score_language_use' => $r->score_language_use ?? null,
        'score_creativity_originality' => $r->score_creativity_originality ?? null,
        'score_mechanics' => $r->score_mechanics ?? null,
        'score'         => $r->score,
        'maxscore'      => $r->maxscore,
        'comment_count' => $comment_count,
        'grade'         => isset($r->sumgrades) ? round($r->sumgrades, 2) : (isset($r->grade) ? round($r->grade, 2) : 'N/A'), // FIXED: Check if sumgrades exists
        'reviewurl'     => $reviewurl->out(false),
        'gradeurl'      => $gradeurl ? $gradeurl->out(false) : '',
        'is_graded'     => $r->isgraded ?? false,
        'ai_likelihood' => $r->ai_likelihood ?? null, // This will now come from the database
        'drive_link'    => $r->drive_link ?? null,
        'similarity_percent' => $grading->similarity_percent ?? null,
        'similarity_flag' => $grading->similarity_flag ?? 0
    ];
}

$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $month_value = date('Y-m', strtotime("-$i months"));
    $month_label = date('F Y', strtotime("-$i months"));
    $month_options[$month_value] = $month_label;
}

function getSortArrows($column, $current_sort, $current_dir) {
    $up_class = ($current_sort === $column && $current_dir === 'ASC') ? ' active' : '';
    $down_class = ($current_sort === $column && $current_dir === 'DESC') ? ' active' : '';
    return "<span class=\"sort-arrows\"><span class=\"arrow up{$up_class}\">▲</span><span class=\"arrow down{$down_class}\">▼</span></span>";
}

// --------------- Render -----------------
echo $OUTPUT->header();

// Include navigation fallback
require_once(__DIR__ . '/navigation_fallback.php');
?>

<div class="essay-dashboard-container">
    <?php if (has_capability('local/quizdashboard:manage', context_system::instance())): ?>
    <div style="text-align: right; margin-bottom: 10px;">
        <a href="<?php echo new moodle_url('/local/quizdashboard/config.php'); ?>" class="btn btn-secondary btn-sm">
            ⚙️ Auto-Grading Settings
        </a>
    </div>
    <?php endif; ?>

    <div class="dashboard-filters<?php echo $status === 'abandoned' ? ' trash-mode' : ''; ?>">
        <form method="GET" class="filter-form">
            <div class="filter-row">

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
                    <label for="quizname">Quiz:</label>
                    <select name="quizname" id="quizname">
                        <option value="">All Quizzes</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="questionname">Question:</label>
                    <select name="questionname" id="questionname">
                        <option value="">All Questions</option>
                        <?php foreach ($unique_questions as $question): ?>
                            <option value="<?php echo htmlspecialchars($question->name); ?>" 
                                <?php echo optional_param('questionname', '', PARAM_TEXT) === $question->name ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($question->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- FIXED: Single Status Filter with Trashed option -->
                <div class="filter-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="">All</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="finished" <?php echo $status === 'finished' ? 'selected' : ''; ?>>Finished</option>
                        <option value="in progress" <?php echo $status === 'in progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="abandoned" <?php echo $status === 'abandoned' ? 'selected' : ''; ?>>🗑️ Trashed</option>
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
            <input type="hidden" name="sort" value="<?php echo $sort; ?>">
            <input type="hidden" name="dir" value="<?php echo $dir; ?>">
        </form>
    </div>

    <!-- MODIFIED: Bulk Actions section for Auto-Grading -->

    <div class="bulk-actions-container">
        <div class="bulk-actions-row">
            <div class="bulk-actions-group">
                <label for="bulk-action-select" class="sr-only">Bulk actions</label>
                <select id="bulk-action-select" class="custom-select">
                    <option value="">With selected...</option>
                    <?php if ($status === 'abandoned'): ?>
                        <option value="restore">♻️ Restore from Trash</option>
                        <option value="delete">🗑️ Delete Permanently</option>
                    <?php else: ?>
                        <optgroup label="Auto-Grading">
                            <option value="grade-general">Auto-Grade (General)</option>
                            <option value="grade-advanced">Auto-Grade (Advanced)</option>
                        </optgroup>
                        <optgroup label="Homework Generation">
                            <option value="homework-general">Generate Homework (General)</option>
                            <option value="homework-advanced">Generate Homework (Advanced)</option>
                        </optgroup>
                        <optgroup label="Homework Injection">
                            <option value="inject-json-general">Inject Homework (General)</option>
                            <option value="inject-json-advanced">Inject Homework (Advanced)</option>
                        </optgroup>
                        <optgroup label="Resubmission Grading">
                            <option value="grade-resubmission-general">Grade Resubmission (General)</option>
                            <option value="grade-resubmission-advanced">Grade Resubmission (Advanced)</option>
                        </optgroup>
                        <optgroup label="Other Actions">
                            <option value="move-to-trash">🗑️ Move to Trash</option>
                            <option value="delete">🗑️ Delete Permanently</option>
                            <option value="export">Export Data</option>
                        </optgroup>
                    <?php endif; ?>
                </select>
                <button type="button" class="btn btn-secondary" onclick="executeBulkAction()" disabled id="apply-bulk-action">Apply</button>
            </div>
            <div class="selected-count">
                <span id="selected-count">0 items selected</span>
            </div>
        </div>
    </div>

    <div class="dashboard-table-container<?php echo $status === 'abandoned' ? ' trash-mode' : ''; ?>">
        <table class="dashboard-table">
            <thead>
                <tr>
                    <th class="bulk-select-header"><input type="checkbox" id="select-all" onchange="toggleAllCheckboxes(this)"></th>
                    <th class="sortable-column" data-sort="quizname" style="width: 22%;">Quiz</th>
                    <th class="sortable-column" data-sort="questionname" style="width: 30%;">Question</th>
                    <th class="sortable-column" data-sort="attemptno" style="width: 7%;">Attempt #</th>
                    <th class="sortable-column" data-sort="status">Status</th>
                    <th class="sortable-column" data-sort="userid">ID</th>
                    <th class="sortable-column" data-sort="studentname">Name</th>
                    <th class="sortable-column" data-sort="coursename">Course</th>
                    <th class="sortable-column" data-sort="timefinish">Finished</th>
                    <th class="sortable-column" data-sort="time_taken">Duration</th>
                    <th class="sortable-column" data-sort="score_content_ideas" title="Content & Ideas (25)" style="width: 4%;">C&I</th>
                    <th class="sortable-column" data-sort="score_structure_organization" title="Structure & Organization (25)" style="width: 4%;">Structure</th>
                    <th class="sortable-column" data-sort="score_language_use" title="Language Use (20)" style="width: 4%;">Language</th>
                    <th class="sortable-column" data-sort="score_creativity_originality" title="Creativity & Originality (20)" style="width: 4%;">Creativity</th>
                    <th class="sortable-column" data-sort="score_mechanics" title="Mechanics (10)" style="width: 4%;">Mechanics</th>
                    <th class="sortable-column" data-sort="score">Score</th>
                    <th>Comment</th>
                    <!-- Grade column removed - keeping only Score column -->
                    <th>AI %</th>
                    <th>Similarity</th>
                    <th>Auto Grade</th>
                    <th>Homework</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="21" class="no-data<?php echo $status === 'abandoned' ? ' trash-mode' : ''; ?>">
                        <?php if ($status === 'abandoned'): ?>
                            No items in trash
                        <?php else: ?>
                            No essay submissions found
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <!-- FIXED: Add trashed-row class for abandoned items -->
                        <tr<?php echo $row->status === 'Abandoned' ? ' class="trashed-row"' : ''; ?>>
                            <td class="bulk-select-cell"><input type="checkbox" class="row-checkbox" value="<?php echo $row->attemptid; ?>" data-userid="<?php echo $row->userid; ?>" data-label="<?php echo htmlspecialchars($row->quizname . ' – Attempt ' . $row->attemptno, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" onchange="updateSelectedCount()"></td>
                            <td><a href="<?php echo $row->reviewurl; ?>" class="quiz-link"><?php echo htmlspecialchars($row->quizname); ?></a></td>
                            <td>
                                <?php if (!empty($row->question_edit_url)): ?>
                                    <a href="<?php echo $row->question_edit_url; ?>" class="question-link" target="_blank"><?php echo htmlspecialchars($row->questionname); ?></a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($row->questionname); ?>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?php echo $row->reviewurl; ?>" class="attempt-link" target="_blank"><?php echo $row->attemptno; ?></a></td>
                            <!-- Removed Sub # column to widen key columns -->
                            <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $row->status)); ?>"><?php echo htmlspecialchars($row->status); ?></span></td>
                            <td><a href="<?php echo $row->user_profile_url; ?>" class="user-id-link" target="_blank"><?php echo $row->userid; ?></a></td>
					<td><a href="<?php echo (new moodle_url('/local/quizdashboard/essays.php', ['studentname' => $row->studentname]))->out(false); ?>" class="user-name-link"><?php echo htmlspecialchars($row->studentname); ?></a></td>
                            <td><a href="<?php echo $row->course_url; ?>" class="course-link" target="_blank"><?php echo htmlspecialchars($row->coursename); ?></a></td>
                            <td><?php echo !empty($row->timefinish) ? date('Y-m-d H:i', $row->timefinish) : '-'; ?></td>
                            <td><?php echo $row->time_taken ?: '-'; ?></td>
                            <td><?php echo $row->score_content_ideas !== null ? ((int)$row->score_content_ideas) . ' / 25' : '-'; ?></td>
                            <td><?php echo $row->score_structure_organization !== null ? ((int)$row->score_structure_organization) . ' / 25' : '-'; ?></td>
                            <td><?php echo $row->score_language_use !== null ? ((int)$row->score_language_use) . ' / 20' : '-'; ?></td>
                            <td><?php echo $row->score_creativity_originality !== null ? ((int)$row->score_creativity_originality) . ' / 20' : '-'; ?></td>
                            <td><?php echo $row->score_mechanics !== null ? ((int)$row->score_mechanics) . ' / 10' : '-'; ?></td>
                            <td><?php echo ($row->score !== null && $row->maxscore !== null) ? round($row->score) . ' / ' . round($row->maxscore) : '-'; ?></td>
                            <td class="comment-cell-count"><a href="<?php echo $row->reviewurl; ?>" class="comment-link" title="View/Add comments"><span class="comment-icon">💬</span><span class="comment-count"><?php echo $row->comment_count; ?></span></a></td>
                            <!-- Grade cell removed - keeping only Score column -->
                            <td class="ai-likelihood-cell" style="text-align: center;">
                                <?php if ($row->ai_likelihood): ?>
                                    <span class="ai-likelihood-display"><?php echo htmlspecialchars($row->ai_likelihood); ?></span>
                                <?php elseif ($row->is_graded): ?>
                                    <!-- If graded but no AI likelihood (shouldn't happen with new code) -->
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <!-- Only show Check button for ungraded essays -->
                                    <button type="button" class="btn btn-sm btn-info check-ai-btn" onclick="checkAILikelihood(<?php echo $row->attemptid; ?>, this)">Check</button>
                                <?php endif; ?>
                            </td>
                            <td class="similarity-cell" style="text-align: center;">
                                <?php if ($row->similarity_percent !== null): ?>
                                    <?php if ($row->similarity_flag): ?>
                                        <span class="badge bg-danger" title="Similarity violation - penalty applied">
                                            <?php echo (int)$row->similarity_percent; ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            <?php echo (int)$row->similarity_percent; ?>%
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="auto-grade-cell" style="text-align: center;">
                                <?php if ($row->is_graded): ?>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="viewFeedback(<?php echo $row->attemptid; ?>)">View</button>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openFeedbackWindow(<?php echo $row->attemptid; ?>)" title="Open in new window">🗗</button>
                                    </div>
                                <?php else: ?>
                                    <span class="status-badge status-secondary">Not Graded</span>
                                <?php endif; ?>
                            </td>
                            <td class="homework-cell" style="text-align: center;">
                                <?php 
                                // Check if homework exists
                                $grading_result = $DB->get_record('local_quizdashboard_gradings', ['attempt_id' => $row->attemptid]);
                                if ($grading_result && !empty($grading_result->homework_html)): ?>
                                    <span class="homework-status homework-yes">Yes</span>
                                <?php elseif ($row->is_graded): ?>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="generateHomework(<?php echo $row->attemptid; ?>, this)">Generate</button>
                                    </div>
                                <?php else: ?>
                                    <span class="homework-status homework-no">Grade First</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<div class="modal fade" id="feedbackModal" tabindex="-1" role="dialog" aria-labelledby="feedbackModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="feedbackModalLabel">Essay Feedback</h5>
                <button type="button" class="close" onclick="closeFeedbackModal()" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="feedbackContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeFeedbackModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Moodle session key (PHP → JS)
const SESSKEY = "<?php echo sesskey(); ?>";

// Central AJAX endpoint for this plugin
const AJAXURL = "<?php echo (new moodle_url('/local/quizdashboard/essays.php'))->out(false); ?>";

// Reset filters to defaults
function resetFilters() {
    window.location.href = window.location.pathname;
}
// --- START: MODIFIED JavaScript for Bulk Actions ---
function toggleAllCheckboxes(masterCheckbox) {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = masterCheckbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.row-checkbox:checked').length;
    document.getElementById('selected-count').textContent = `${count} item(s) selected`;
    document.getElementById('apply-bulk-action').disabled = count === 0;
}

function executeBulkAction() {
    const actionSelect = document.getElementById('bulk-action-select');
    const selectedAction = actionSelect.value;
    const checkboxes = document.querySelectorAll('.row-checkbox:checked');
    
    if (!selectedAction || checkboxes.length === 0) {
        alert('Please select an action and at least one essay.');
        return;
    }

    const attemptIds = Array.from(checkboxes).map(cb => cb.value);
    let confirmMessage = '';
    let actionType = '';
    let level = 'general';

    // Map UI actions to backend actions
    if (selectedAction === 'grade-general') {
        actionType = 'auto_grade';
        confirmMessage = `Auto-grade ${attemptIds.length} essay(s) using general level?`;
    } else if (selectedAction === 'grade-advanced') {
        actionType = 'auto_grade';
        level = 'advanced';
        confirmMessage = `Auto-grade ${attemptIds.length} essay(s) using advanced level?`;
    } else if (selectedAction === 'inject-json-general') {
        actionType = 'inject_homework_json';
        confirmMessage = `Inject homework (General) for ${attemptIds.length} essay(s)?`;
    } else if (selectedAction === 'inject-json-advanced') {
        actionType = 'inject_homework_json';
        level = 'advanced';
        confirmMessage = `Inject homework (Advanced) for ${attemptIds.length} essay(s)?`;
    } else if (selectedAction === 'grade-resubmission-general') {
        actionType = 'grade_resubmission';
        level = 'general';
        confirmMessage = `Grade ${attemptIds.length} resubmission(s) using general level?\n\nNote: Only attempts that are actual resubmissions will be processed.`;
    } else if (selectedAction === 'grade-resubmission-advanced') {
        actionType = 'grade_resubmission';
        level = 'advanced';
        confirmMessage = `Grade ${attemptIds.length} resubmission(s) using advanced level?\n\nNote: Only attempts that are actual resubmissions will be processed.`;
    } else if (selectedAction === 'homework-general') {
        actionType = 'generate_homework';
        confirmMessage = `Generate homework exercises for ${attemptIds.length} essay(s) using general level?`;
    } else if (selectedAction === 'homework-advanced') {
        actionType = 'generate_homework';
        level = 'advanced';
        confirmMessage = `Generate homework exercises for ${attemptIds.length} essay(s) using advanced level?`;
    } else if (selectedAction === 'delete') {
        actionType = 'delete_attempts';
        confirmMessage = `⚠️ WARNING: This will PERMANENTLY DELETE ${attemptIds.length} quiz attempt(s).\n\nThis action CANNOT be undone!\n\nProceed?`;
    } else if (selectedAction === 'move-to-trash') {
        actionType = 'trash_attempts';
        confirmMessage = `Move ${attemptIds.length} attempt(s) to trash?`;
    } else if (selectedAction === 'delete') {
        actionType = 'delete_attempts';
        confirmMessage = `⚠️ WARNING: This will PERMANENTLY DELETE ${attemptIds.length} quiz attempt(s).\n\nThis action CANNOT be undone!\n\nProceed?`;
    } else if (selectedAction === 'restore') {
        actionType = 'restore_attempts';
        confirmMessage = `Restore ${attemptIds.length} attempt(s) from trash?`;
    } else if (selectedAction === 'export') {
        actionType = 'export_attempts';
        confirmMessage = `Export data for ${attemptIds.length} attempt(s) to CSV?`;
    }

    if (!confirm(confirmMessage)) {
        return;
    }

    const button = document.getElementById('apply-bulk-action');
    const originalText = button.textContent;
    button.textContent = 'Processing...';
    button.disabled = true;

    // For grading actions (including resubmissions), process individually
    if (actionType === 'auto_grade' || actionType === 'grade_resubmission' || actionType === 'generate_homework' || actionType === 'inject_homework_json') {
        const rows = Array.from(checkboxes).map(cb => ({
            attemptid: cb.value,
            userid: cb.dataset.userid || '',
            label: cb.dataset.label || ''
        }));

        let promises = rows.map(row => {
            const params = new URLSearchParams({
                action: actionType,
                attemptid: row.attemptid,
                level: level,
                sesskey: SESSKEY
            });
            if (actionType === 'inject_homework_json') {
                params.set('userid', row.userid);
                params.set('label', row.label);
            }

            return fetch(AJAXURL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(async res => {
                const text = await res.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON for attempt', row.attemptid, 'raw:', text);
                    return { success: false, message: 'Server returned invalid JSON. Response: ' + text.substring(0, 500) };
                }
            }).catch(err => {
                console.error('Request failed for attempt', row.attemptid, ':', err);
                return {success: false, message: err.message};
            });
        });

        Promise.all(promises)
            .then(results => {
                const successes = results.filter(r => r && r.success === true).length;
                const failures = results.length - successes;
                
                let message = '';
                if (actionType === 'auto_grade') {
                    message = `${successes} essay(s) graded successfully.`;
                } else if (actionType === 'grade_resubmission') {
                    message = `${successes} resubmission(s) graded successfully.`;
                    
                    // Show specific resubmission info
                    const resubmissionResults = results.filter(r => r && r.success && r.submission_number);
                    if (resubmissionResults.length > 0) {
                        const submissions = resubmissionResults.map(r => `#${r.submission_number}`).join(', ');
                        message += `\nProcessed submissions: ${submissions}`;
                    }
                    
                    const penalties = results.filter(r => r && r.success && r.is_penalty);
                    if (penalties.length > 0) {
                        message += `\n⚠️ ${penalties.length} submission(s) had copy penalties applied.`;
                    }
                } else if (actionType === 'generate_homework') {
                    message = `${successes} homework exercise(s) generated successfully.`;
                } else if (actionType === 'inject_homework_json') {
                    message = `${successes} quiz(es) injected successfully.`;
                }
                
                if (failures > 0) {
                    message += `\n❌ ${failures} failed. Check the server logs for details.`;
                    
                    const failedResults = results.filter(r => !r || r.success !== true);
                    if (failedResults.length > 0) {
                        console.log('Failed results:', failedResults);
                        const errorMessages = failedResults.map(r => r?.message || 'Unknown error').join(', ');
                        message += `\n\nError details: ${errorMessages}`;
                    }
                }
                
                alert(message);
                window.location.reload();
            })
            .catch(err => {
                console.error('Bulk operation failed:', err);
                alert('A critical error occurred: ' + err.message);
                button.textContent = originalText;
                button.disabled = false;
            });
    } else {
        // For other bulk actions, send all IDs at once
        const params = new URLSearchParams({
            action: actionType,
            attemptids: attemptIds.join(','),
            sesskey: SESSKEY
        });

        fetch(AJAXURL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Server returned invalid JSON. Response: ' + text.substring(0, 200));
            }
        })
        .then(data => {
            if (data.success) {
                alert(data.message);
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
                button.textContent = originalText;
                button.disabled = false;
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            alert('A critical error occurred: ' + err.message);
            button.textContent = originalText;
            button.disabled = false;
        });
    }
}

function openFeedbackWindow(attemptId) {
    var url = '<?php echo (new moodle_url('/local/quizdashboard/viewfeedback.php'))->out(false); ?>?clean=1&id=' + attemptId;
    var feedbackWindow = window.open(url, 'feedback_' + attemptId, 'width=1000,height=700,scrollbars=yes,resizable=yes,menubar=yes,toolbar=yes');
    if (feedbackWindow) {
        feedbackWindow.focus();
    } else {
        alert('Please allow popups for this site to open feedback in a new window.');
    }
}

// Individual homework generation function
function generateHomework(attemptId, button) {
    const originalText = button.textContent;
    button.textContent = 'Generating...';
    button.disabled = true;

    const params = new URLSearchParams({
        action: 'generate_homework',
        attemptid: attemptId,
        level: 'general',
        sesskey: SESSKEY
    });

    fetch(AJAXURL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            button.parentElement.innerHTML = '<span class="homework-status homework-yes">Yes</span>';
        } else {
            alert('Error generating homework: ' + data.message);
            button.textContent = originalText;
            button.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error generating homework: ' + err.message);
        button.textContent = originalText;
        button.disabled = false;
    });
}


// Individual injection function
function injectHomework(attemptId, userId, label, button) {
    const originalHTML = button.innerHTML;
    button.textContent = 'Injecting...';
    button.disabled = true;

    const params = new URLSearchParams({
        action: 'inject_homework',
        attemptid: String(attemptId),
        userid: String(userId),
        label: label,
        sesskey: SESSKEY
    });

    fetch(AJAXURL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
        credentials: 'same-origin'
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { throw new Error('Invalid JSON: ' + text.substring(0, 300)); }
    })
    .then(data => {
        if (!data || !data.success) { throw new Error(data && data.message ? data.message : 'Injection failed'); }
        const url = data.url || '';
        const parent = button.parentElement;
        if (parent) {
            parent.innerHTML = url ? ('<a class="btn btn-sm btn-success" target="_blank" href="'+url+'">Open</a>') : '<span class="homework-status homework-yes">Injected</span>';
        }
    })
    .catch(err => {
        alert('Injection error: ' + err.message);
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
}

// JSON-based injection function
function injectHomeworkJSON(attemptId, userId, label, level, button) {
    const originalHTML = button.innerHTML;
    button.textContent = 'Injecting...';
    button.disabled = true;

    const params = new URLSearchParams({
        action: 'inject_homework_json',
        attemptid: String(attemptId),
        userid: String(userId),
        label: label,
        level: level || 'general',
        sesskey: SESSKEY
    });

    fetch(AJAXURL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
        credentials: 'same-origin'
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch (e) { throw new Error('Invalid JSON: ' + text.substring(0, 300)); }
    })
    .then(data => {
        if (!data || !data.success) { throw new Error(data && data.message ? data.message : 'Injection failed'); }
        const url = data.url || '';
        const parent = button.parentElement;
        if (parent) {
            parent.innerHTML = url ? ('<a class="btn btn-sm btn-success" target="_blank" href="'+url+'">Open</a>') : '<span class="homework-status homework-yes">Injected</span>';
        }
    })
    .catch(err => {
        alert('Injection error: ' + err.message);
        button.innerHTML = originalHTML;
        button.disabled = false;
    });
}
window.injectHomeworkJSON = injectHomeworkJSON;

// --- END: MODIFIED JavaScript for Bulk Actions ---


function checkAILikelihood(attemptId, button) {
    const originalText = button.textContent;
    button.textContent = 'Checking...';
    button.disabled = true;

    const params = new URLSearchParams({
        action: 'get_ai_likelihood',
        attemptid: attemptId,
        sesskey: SESSKEY
    });

    fetch(AJAXURL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
      if (!data.success) throw new Error(data.message || 'Server error.');
      button.parentElement.innerHTML = `<span class="ai-likelihood-display">${data.likelihood}</span>`;
    })
    .catch(err => {
      console.error(err);
      alert('Error checking AI likelihood: ' + err.message);
      button.textContent = originalText;
      button.disabled = false;
    });
}

function closeFeedbackModal() {
    const modal = document.getElementById('feedbackModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.classList.remove('modal-open');
        const backdrop = document.getElementById('modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
    }
}

function viewFeedback(attemptId) {
    const params = new URLSearchParams({
        action: 'view_feedback',
        attemptid: attemptId,
        sesskey: SESSKEY
    });

    fetch(AJAXURL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
    .then(async (res) => {
      const text = await res.text();
      try {
        const maybe = JSON.parse(text);
        if (maybe && maybe.success === false) {
          throw new Error(maybe.message || 'Server error.');
        }
      } catch (_) { /* not an error, it's HTML */ }
      document.getElementById('feedbackContent').innerHTML = text;
      // Use vanilla JS instead of jQuery
      const modal = document.getElementById('feedbackModal');
      if (modal) {
        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.classList.add('modal-open');
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'modal-backdrop';
        document.body.appendChild(backdrop);
      }
    })
    .catch(err => {
      console.error(err);
      alert('An error occurred while loading feedback: ' + err.message);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sortable-column').forEach(function(header) {
        header.addEventListener('click', function() {
            const sortField = this.getAttribute('data-sort');
            const url = new URL(window.location.href);
            const currentSort = url.searchParams.get('sort');
            const currentDir = url.searchParams.get('dir');

            let newDir = 'ASC';
            if (currentSort === sortField && currentDir === 'ASC') {
                newDir = 'DESC';
            }

            url.searchParams.set('sort', sortField);
            url.searchParams.set('dir', newDir);
            window.location.href = url.toString();
        });
    });
    
    // Add event listeners for checkbox changes to update count
    document.getElementById('select-all').addEventListener('change', updateSelectedCount);
    document.querySelectorAll('.row-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });

    // Dynamic sections/quizzes refresh using existing AJAX endpoints
    (function setupCascadingFilters(){
        const courseSelect = document.getElementById('coursename');
        const sectionSelect = document.getElementById('sectionid');
        const quizSelect    = document.getElementById('quizname');
        if (!courseSelect || !quizSelect) return;

        // Ensure a Section select exists; if not, create a lightweight one before Quiz
        if (!sectionSelect) {
            const quizGroup = document.getElementById('quizname').closest('.filter-group');
            const container = document.createElement('div');
            container.className = 'filter-group';
            container.innerHTML = '<label for="sectionid">Section:</label><select name="sectionid" id="sectionid"><option value="">All Sections</option></select>';
            quizGroup.parentNode.insertBefore(container, quizGroup);
        }

        const sectionSel = document.getElementById('sectionid');

        function loadSections() {
            if (!sectionSel) return;
            sectionSel.innerHTML = '<option value="">All Sections</option>';
            const opt = courseSelect.options[courseSelect.selectedIndex];
            const courseId = opt ? opt.getAttribute('data-courseid') : '';
            if (!courseId) { refreshQuizzes(); return; }
            fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + encodeURIComponent(courseId) + '&sesskey=' + SESSKEY)
                .then(r => r.json())
                .then(list => {
                    let html = '<option value="">All Sections</option>';
                    (list||[]).forEach(s => {
                        const label = s.name || ('Section ' + s.section);
                        html += '<option value="' + s.id + '">' + label.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
                    });
                    sectionSel.innerHTML = html;
                    refreshQuizzes();
                })
                .catch(() => { refreshQuizzes(); });
        }

        function refreshQuizzes() {
            if (!quizSelect) return;
            const opt = courseSelect.options[courseSelect.selectedIndex];
            const courseId = opt ? opt.getAttribute('data-courseid') : '';
            if (!courseId) return;
            const sectionId = sectionSel && sectionSel.value ? sectionSel.value : '';
            const url = M.cfg.wwwroot + '/local/quizdashboard/ajax.php?action=get_quizzes&sesskey=' + SESSKEY + '&courseid=' + encodeURIComponent(courseId) + (sectionId ? ('&sectionid=' + sectionId) : '');
            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (!data || data.success !== true) return;
                    let html = '<option value="">All Quizzes</option>';
                    const current = '<?php echo addslashes($quizname); ?>';
                    data.quizzes.forEach(q => {
                        const sel = (current && current === q.name) ? ' selected' : '';
                        html += '<option value="' + q.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '"' + sel + '>' + q.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
                    });
                    quizSelect.innerHTML = html;
                })
                .catch(()=>{});
        }

        courseSelect.addEventListener('change', loadSections);
        if (sectionSel) sectionSel.addEventListener('change', refreshQuizzes);
        // Initial population
        loadSections();
    })();
});
</script>

<?php
echo $OUTPUT->footer();
?>

