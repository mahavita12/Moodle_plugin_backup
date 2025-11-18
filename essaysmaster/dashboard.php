<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/dashboard_manager.php');

// Security and access control
require_login();
$context = context_system::instance();
require_capability('local/essaysmaster:viewdashboard', $context);

// Page setup
$PAGE->set_url('/local/essaysmaster/dashboard.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboard', 'local_essaysmaster'));
$PAGE->set_heading(get_string('dashboard_title', 'local_essaysmaster'));
$PAGE->set_pagelayout('admin');

// Add CSS styles
$PAGE->requires->css('/local/essaysmaster/styles.css');

// Parameters
$courseid = optional_param('course', 0, PARAM_INT);
$quizid   = optional_param('quizid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$month = optional_param('month', '', PARAM_TEXT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$per_page = optional_param('per_page', 25, PARAM_INT);
$page = optional_param('page', 1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Initialize dashboard manager
$dashboard = new \local_essaysmaster\dashboard_manager();

// Handle AJAX requests
if ($action) {
    require_sesskey();
    
    switch ($action) {
        case 'toggle_quiz':
            $quizid = required_param('quizid', PARAM_INT);
            $enabled = required_param('enabled', PARAM_BOOL);
            $result = $dashboard->toggle_quiz_enabled($quizid, $enabled);
            
            if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;
            }
            
            if ($result['success']) {
                redirect($PAGE->url, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                redirect($PAGE->url, $result['message'], null, \core\output\notification::NOTIFY_ERROR);
            }
            break;
            
        case 'bulk_enable':
            $quizids = required_param('quizids', PARAM_SEQUENCE);
            $result = $dashboard->bulk_toggle_quizzes($quizids, true);
            redirect($PAGE->url, $result['message'], null, 
                $result['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
            break;
            
        case 'bulk_disable':
            $quizids = required_param('quizids', PARAM_SEQUENCE);
            $result = $dashboard->bulk_toggle_quizzes($quizids, false);
            redirect($PAGE->url, $result['message'], null, 
                $result['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
            break;
    }
}

// Default category to "Category 1" if not explicitly provided
if (empty($categoryid)) {
    try {
        $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id');
        if ($catrow) { $categoryid = (int)$catrow->id; }
    } catch (\Throwable $e) { /* ignore */ }
}

// Get dashboard data
$courses = $dashboard->get_accessible_courses();
$students = $dashboard->get_unique_students($courseid);
$student_progress = $dashboard->get_student_progress($courseid, $status, $search, $month, $userid, 25, 1, $quizid, $categoryid);
$quiz_configs = $dashboard->get_quiz_configurations($courseid, $categoryid);
$statistics = $dashboard->get_dashboard_statistics($courseid);

// Generate month options for the past 12 months (matching Quiz Dashboard)
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $month_value = date('Y-m', strtotime("-$i months"));
    $month_label = date('F Y', strtotime("-$i months"));
    $month_options[$month_value] = $month_label;
}

// Page output
echo $OUTPUT->header();

// Dashboard tabs
$tabs = [];
$tabs[] = new tabobject('students', 
    new moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students']), 
    get_string('student_progress', 'local_essaysmaster'));
$tabs[] = new tabobject('quizzes', 
    new moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'quizzes']), 
    get_string('quiz_configuration', 'local_essaysmaster'));

$currenttab = optional_param('tab', 'students', PARAM_ALPHA);
echo $OUTPUT->tabtree($tabs, $currenttab);

// Main content based on active tab
switch ($currenttab) {
    case 'students':
        echo html_writer::start_div('essay-dashboard-container');
        
        // Filters Section - matching Quiz Dashboard layout
        echo html_writer::start_div('dashboard-filters');
        $filter_form = html_writer::start_tag('form', ['method' => 'GET', 'class' => 'filter-form']);
        $filter_form .= html_writer::start_div('filter-row');
        
        // Course category filter
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'Category:', ['for' => 'categoryid']);
        $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
        if (empty($categoryid)) {
            $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id');
            if ($catrow) { $categoryid = (int)$catrow->id; }
        }
        $cat_options = [0 => 'All Categories'];
        foreach ($categories as $cat) { $cat_options[$cat->id] = $cat->name; }
        $filter_form .= html_writer::select($cat_options, 'categoryid', $categoryid, false, ['id' => 'categoryid']);
        $filter_form .= html_writer::end_div();

        // Course filter
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'Course:', ['for' => 'course']);
        $course_options = [0 => 'All Courses'];
        // Use structural list when a category is selected to support Personal Review Courses (no attempts yet)
        if (!empty($categoryid)) {
            try {
                $struct_courses = $DB->get_records('course', ['category' => (int)$categoryid, 'visible' => 1], 'fullname', 'id, fullname');
                foreach ($struct_courses as $c) { $course_options[$c->id] = $c->fullname; }
            } catch (\Throwable $e) { /* ignore and fall back */ }
        }
        // Fallback to accessible courses if structural list is empty or no category chosen
        if (count($course_options) === 1) {
            foreach ($courses as $course) {
                if (!empty($categoryid) && isset($course->category) && (int)$course->category !== (int)$categoryid) { continue; }
                $course_options[$course->id] = $course->fullname;
            }
        }
        $filter_form .= html_writer::select($course_options, 'course', $courseid, false, ['id' => 'course']);
        $filter_form .= html_writer::end_div();
        
        // Status filter
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'Status:', ['for' => 'status']);
        $status_options = [
            '' => 'All Statuses',
            'not_started' => 'Not Started',
            'in_progress' => 'In Progress',
            'completed' => 'Completed'
        ];
        $filter_form .= html_writer::select($status_options, 'status', $status, false, 
            ['id' => 'status']);
        $filter_form .= html_writer::end_div();
        
        // User ID filter (matching Quiz Dashboard)
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'User ID:', ['for' => 'userid']);
        $user_options = [0 => 'All User IDs'];
        foreach ($students as $student) {
            $user_options[$student->id] = $student->id;
        }
        $filter_form .= html_writer::select($user_options, 'userid', $userid, false, 
            ['id' => 'userid']);
        $filter_form .= html_writer::end_div();
        
        // Student dropdown filter
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'Student:', ['for' => 'search']);
        $student_options = ['' => 'All Students'];
        foreach ($students as $student) {
            $student_options[$student->fullname] = $student->fullname;
        }
        $filter_form .= html_writer::select($student_options, 'search', $search, false, 
            ['id' => 'search']);
        $filter_form .= html_writer::end_div();
        
        // Month filter (matching Quiz Dashboard)
        $filter_form .= html_writer::start_div('filter-group');
        $filter_form .= html_writer::tag('label', 'Month:', ['for' => 'month']);
        $month_select_options = ['' => 'All Months'];
        $month_select_options = array_merge($month_select_options, $month_options);
        $filter_form .= html_writer::select($month_select_options, 'month', $month, false, 
            ['id' => 'month']);
        $filter_form .= html_writer::end_div();
        
        // Filter actions
        $filter_form .= html_writer::start_div('filter-actions');
        $filter_form .= html_writer::tag('button', 'Filter', [
            'type' => 'submit',
            'class' => 'btn btn-primary'
        ]);
        $filter_form .= html_writer::link(
            new moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students']),
            'Reset',
            ['class' => 'btn btn-secondary']
        );
        $filter_form .= html_writer::end_div();
        
        $filter_form .= html_writer::end_div(); // filter-row
        
        // Hidden fields
        $filter_form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'students']);
        $filter_form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'page', 'value' => $page]);
        $filter_form .= html_writer::end_tag('form');
        
        echo $filter_form;
        echo html_writer::end_div(); // dashboard-filters
        
        // Bulk Actions Section - matching Quiz Dashboard
        echo html_writer::start_div('bulk-actions-container');
        echo html_writer::start_div('bulk-actions-row');
        echo html_writer::start_div('bulk-actions-group');
        echo html_writer::select([
            '' => 'With selected...',
            'export' => 'Export Data',
            'reset_selected' => 'Reset Selected'
        ], 'bulk_action', '', false, [
            'id' => 'bulk-action-select',
            'class' => 'bulk-action-dropdown'
        ]);
        echo html_writer::tag('button', 'Apply', [
            'type' => 'button',
            'class' => 'btn btn-secondary',
            'onclick' => 'executeBulkAction()',
            'disabled' => 'disabled',
            'id' => 'apply-bulk-action'
        ]);
        echo html_writer::end_div(); // bulk-actions-group
        echo html_writer::start_div('selected-count');
        echo html_writer::tag('span', '0 items selected', ['id' => 'selected-count']);
        echo html_writer::end_div();
        echo html_writer::end_div(); // bulk-actions-row
        echo html_writer::end_div(); // bulk-actions-container
        
        // Student progress table
        echo $dashboard->render_student_progress_table($student_progress, false);
        
        echo html_writer::end_div(); // essay-dashboard-container
        break;
        
    case 'quizzes':
        echo html_writer::start_div('essaysmaster-dashboard');
        
        // Quiz configuration section
        echo html_writer::tag('h3', get_string('quiz_configuration', 'local_essaysmaster'));
        echo html_writer::tag('p', get_string('default_enabled_notice', 'local_essaysmaster'), 
            ['class' => 'alert alert-info']);
        
        // Bulk actions form
        echo html_writer::start_tag('form', [
            'method' => 'post', 
            'id' => 'quiz-config-form',
            'class' => 'quiz-configuration-form'
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        
        // Bulk action buttons
        echo html_writer::start_div('bulk-actions');
        echo html_writer::tag('button', get_string('bulk_enable', 'local_essaysmaster'), [
            'type' => 'button',
            'id' => 'bulk-enable-btn',
            'class' => 'btn btn-secondary',
            'disabled' => 'disabled'
        ]);
        echo html_writer::tag('button', get_string('bulk_disable', 'local_essaysmaster'), [
            'type' => 'button', 
            'id' => 'bulk-disable-btn',
            'class' => 'btn btn-secondary',
            'disabled' => 'disabled'
        ]);
        echo html_writer::tag('span', '', ['id' => 'selected-count', 'class' => 'selected-counter']);
        echo html_writer::end_div(); // bulk-actions
        
        // Quiz configuration table
        echo $dashboard->render_quiz_config_table($quiz_configs);
        
        echo html_writer::end_tag('form');
        echo html_writer::end_div(); // essaysmaster-dashboard
        break;
        
    default:
        // Fallback: redirect to students tab if an unknown tab is passed
        redirect(new moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students']));
        break;
}

// Add JavaScript for Quiz Dashboard-style functionality
if ($currenttab === 'students') {
    $PAGE->requires->js_init_code('
        // Define Moodle sesskey for JavaScript
        const SESSKEY = "' . sesskey() . '";

        // Reset filters function
        function resetFilters() {
            window.location.href = "' . (new moodle_url('/local/essaysmaster/dashboard.php', ['tab' => 'students']))->out(false) . '";
        }

        // Bulk action functions
        function toggleAllCheckboxes(masterCheckbox) {
            const checkboxes = document.querySelectorAll(".row-checkbox");
            checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
            updateSelectedCount();
        }

        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll(".row-checkbox:checked");
            const count = checkboxes.length;
            const selectedCountSpan = document.getElementById("selected-count");
            const applyButton = document.getElementById("apply-bulk-action");
            
            selectedCountSpan.textContent = count + (count === 1 ? " item selected" : " items selected");
            applyButton.disabled = count === 0;
            
            // Update master checkbox
            const masterCheckbox = document.getElementById("select-all");
            const allCheckboxes = document.querySelectorAll(".row-checkbox");
            if (masterCheckbox) {
                masterCheckbox.checked = count > 0 && count === allCheckboxes.length;
                masterCheckbox.indeterminate = count > 0 && count < allCheckboxes.length;
            }
        }

        function executeBulkAction() {
            const actionSelect = document.getElementById("bulk-action-select");
            const selectedAction = actionSelect.value;
            const checkboxes = document.querySelectorAll(".row-checkbox:checked");
            
            if (!selectedAction || checkboxes.length === 0) {
                alert("Please select an action and at least one item.");
                return;
            }
            
            const sessionIds = Array.from(checkboxes).map(cb => cb.value);
            let confirmMessage = "";
            
            if (selectedAction === "export") {
                confirmMessage = `Export data for ${sessionIds.length} session(s) to CSV?`;
            } else if (selectedAction === "reset_selected") {
                confirmMessage = `Reset progress for ${sessionIds.length} session(s)?`;
            } else {
                confirmMessage = `Apply ${selectedAction} to ${sessionIds.length} item(s)?`;
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            alert("Bulk action functionality will be implemented in the next update.");
        }

        // Initialize when DOM is ready
        document.addEventListener("DOMContentLoaded", function() {
            // Add event listeners for checkboxes
            document.querySelectorAll(".row-checkbox").forEach(checkbox => {
                checkbox.addEventListener("change", updateSelectedCount);
            });
        });
    ');
}

// Load dashboard JavaScript
$PAGE->requires->js_call_amd('local_essaysmaster/dashboard', 'init');

echo $OUTPUT->footer();
?>