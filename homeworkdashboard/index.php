<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/homework_manager.php');

require_login();
$context = context_system::instance();

if (!has_capability('local/homeworkdashboard:view', $context)) {
    print_error('nopermissions', 'error', '', 'local_homeworkdashboard:view');
}

$PAGE->set_url('/local/homeworkdashboard/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_homeworkdashboard'));
$PAGE->set_heading(get_string('heading', 'local_homeworkdashboard'));
$PAGE->set_pagelayout('admin');

$PAGE->requires->css('/local/homeworkdashboard/styles.css');

$categoryid      = optional_param('categoryid', 0, PARAM_INT);
$courseid        = optional_param('courseid', 0, PARAM_INT);
$classfilter     = optional_param('classification', '', PARAM_ALPHA);
$quiztypefilter  = optional_param('quiztype', '', PARAM_TEXT);

// Categories, default to "Category 1" if present.
$categories = [];
try {
    $categories = $DB->get_records('course_categories', null, 'name', 'id,name');
    if (empty($categoryid)) {
        $catrow = $DB->get_record('course_categories', ['name' => 'Category 1'], 'id', IGNORE_MISSING);
        if ($catrow) {
            $categoryid = (int)$catrow->id;
        }
    }
} catch (Throwable $e) {
    // Ignore; page will just show empty filters.
}

$courses = [];
if (!empty($categoryid)) {
    $courses = $DB->get_records('course', ['category' => $categoryid, 'visible' => 1], 'fullname ASC', 'id, fullname');
}

$manager = new \local_homeworkdashboard\homework_manager();
$sessions = [];
if (!empty($courseid)) {
    $sessions = $manager->get_sessions_for_course($courseid, $classfilter, $quiztypefilter);
}

echo $OUTPUT->header();

$baseurl = new moodle_url('/local/homeworkdashboard/index.php');

// Filters UI.
?>
<div class="hw-dashboard-container">
    <form method="get" class="hw-filters">
        <div class="hw-filter-row">
            <div class="hw-filter-group">
                <label for="id_categoryid"><?php echo get_string('filtercategory', 'local_homeworkdashboard'); ?></label>
                <select name="categoryid" id="id_categoryid" onchange="this.form.submit()">
                    <option value="0"><?php echo get_string('all'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo (int)$cat->id; ?>" <?php echo ((int)$categoryid === (int)$cat->id) ? 'selected' : ''; ?>>
                            <?php echo format_string($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="hw-filter-group">
                <label for="id_courseid"><?php echo get_string('filtercourse', 'local_homeworkdashboard'); ?></label>
                <select name="courseid" id="id_courseid" onchange="this.form.submit()">
                    <option value="0"><?php echo get_string('allcourses'); ?></option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo (int)$c->id; ?>" <?php echo ((int)$courseid === (int)$c->id) ? 'selected' : ''; ?>>
                            <?php echo format_string($c->fullname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="hw-filter-group">
                <label for="id_classification"><?php echo get_string('filterclassification', 'local_homeworkdashboard'); ?></label>
                <select name="classification" id="id_classification" onchange="this.form.submit()">
                    <option value=""><?php echo get_string('all'); ?></option>
                    <option value="New" <?php echo $classfilter === 'New' ? 'selected' : ''; ?>><?php echo get_string('classification_new', 'local_homeworkdashboard'); ?></option>
                    <option value="Revision" <?php echo $classfilter === 'Revision' ? 'selected' : ''; ?>><?php echo get_string('classification_revision', 'local_homeworkdashboard'); ?></option>
                </select>
            </div>

            <div class="hw-filter-group">
                <label for="id_quiztype"><?php echo get_string('quiztype', 'quiz'); // reuse core string ?></label>
                <select name="quiztype" id="id_quiztype" onchange="this.form.submit()">
                    <option value=""><?php echo get_string('all'); ?></option>
                    <option value="Essay" <?php echo $quiztypefilter === 'Essay' ? 'selected' : ''; ?>>Essay</option>
                    <option value="Non-Essay" <?php echo $quiztypefilter === 'Non-Essay' ? 'selected' : ''; ?>>Non-Essay</option>
                </select>
            </div>
        </div>
    </form>

    <div class="hw-legend">
        <span class="hw-badge hw-badge-completed"><span class="hw-badge-icon">&#10003;</span><?php echo get_string('badge_completed', 'local_homeworkdashboard'); ?></span>
        <span class="hw-badge hw-badge-lowgrade"><span class="hw-badge-icon">?</span><?php echo get_string('badge_lowgrade', 'local_homeworkdashboard'); ?></span>
        <span class="hw-badge hw-badge-noattempt"><span class="hw-badge-icon">&#8855;</span><?php echo get_string('badge_noattempt', 'local_homeworkdashboard'); ?></span>
    </div>

    <?php if (empty($courseid)): ?>
        <p class="hw-info"><?php echo get_string('selectacourse'); ?></p>
    <?php else: ?>
        <?php if (empty($sessions)): ?>
            <p class="hw-info"><?php echo get_string('noquizzes', 'local_homeworkdashboard'); ?></p>
        <?php else: ?>
            <table class="hw-table">
                <thead>
                    <tr>
                        <th><?php echo get_string('col_quiz', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('col_timeclose', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('col_window', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('filterclassification', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('quiztype', 'quiz'); ?></th>
                        <th><?php echo get_string('col_completed', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('col_lowgrade', 'local_homeworkdashboard'); ?></th>
                        <th><?php echo get_string('col_noattempt', 'local_homeworkdashboard'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td>
                            <a href="<?php echo (new moodle_url('/mod/quiz/view.php', ['id' => $s->cmid]))->out(false); ?>" target="_blank">
                                <?php echo format_string($s->quizname); ?>
                            </a>
                        </td>
                        <td><?php echo userdate($s->timeclose, get_string('strftimedatetime', 'langconfig')); ?></td>
                        <td><?php echo (int)$s->windowdays; ?></td>
                        <td><?php echo s($s->classification); ?></td>
                        <td><?php echo s($s->quiztype); ?></td>
                        <td>
                            <span class="hw-badge hw-badge-completed" title="<?php echo get_string('badge_completed', 'local_homeworkdashboard'); ?>">
                                <span class="hw-badge-icon">&#10003;</span><?php echo (int)$s->completed; ?>
                            </span>
                        </td>
                        <td>
                            <span class="hw-badge hw-badge-lowgrade" title="<?php echo get_string('badge_lowgrade', 'local_homeworkdashboard'); ?>">
                                <span class="hw-badge-icon">?</span><?php echo (int)$s->lowgrade; ?>
                            </span>
                        </td>
                        <td>
                            <span class="hw-badge hw-badge-noattempt" title="<?php echo get_string('badge_noattempt', 'local_homeworkdashboard'); ?>">
                                <span class="hw-badge-icon">&#8855;</span><?php echo (int)$s->noattempt; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
