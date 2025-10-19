<?php
/**
 * Quiz upload page
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/upload_form.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/quiz_uploader/upload.php');
$PAGE->set_title('Upload Quiz from XML File');
$PAGE->set_heading('Upload Quiz from XML File');
$PAGE->set_pagelayout('admin');

// Ensure filepicker JavaScript is loaded
$PAGE->requires->js_init_call('M.util.init_maximised_embed', null, true);

// Check capability
require_capability('local/quiz_uploader:uploadquiz', $context);

// Initialize form
$mform = new \local_quiz_uploader\upload_form();

// Form processing
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/'));
} else if ($data = $mform->get_data()) {
    // Debug: Log form data
    error_log('Quiz Uploader - Form data received: ' . print_r($data, true));
    error_log('Quiz Uploader - $_FILES: ' . print_r($_FILES, true));
    error_log('Quiz Uploader - Section value: ' . ($data->section ?? 'NOT SET'));

    // Process the upload
    $result = process_upload($data);

    // Log the result
    error_log('Quiz Uploader - Result: ' . print_r($result, true));

    echo $OUTPUT->header();

    if ($result['success']) {
        echo $OUTPUT->notification('Quiz uploaded successfully!', 'success');
        echo html_writer::tag('p', 'Quiz: ' . $result['quizname']);
        echo html_writer::tag('p', 'Questions: ' . $result['questioncount']);
    } else {
        echo $OUTPUT->notification($result['message'], 'error');
        // Also show detailed error for debugging
        if (!empty($result['debug'])) {
            echo html_writer::tag('pre', $result['debug'], ['style' => 'background:#f8d7da;padding:10px;']);
        }
    }

    echo html_writer::link(new moodle_url('/local/quiz_uploader/upload.php'), 'Upload Another Quiz', ['class' => 'btn btn-primary']);
    echo $OUTPUT->footer();
    exit;
}

// Display form
echo $OUTPUT->header();

// Add JavaScript for section loading
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Quiz uploader: Initializing...');

    // ===== COURSE/SECTION DROPDOWNS FOR 3 QUIZ DESTINATIONS =====

    // Helper function to setup course/section pair
    function setupCourseSectionPair(courseId, sectionId, hiddenId, courseNum) {
        var courseSelect = document.getElementById(courseId);
        var sectionSelect = document.getElementById(sectionId);
        var sectionHidden = document.querySelector('input[name="' + hiddenId + '"]');

        if (!courseSelect || !sectionSelect) {
            console.log('Course/Section ' + courseNum + ' not found (may be intentional)');
            return;
        }

        console.log('Setting up Course/Section ' + courseNum);

        // Update hidden field when section changes
        sectionSelect.addEventListener('change', function() {
            if (sectionHidden) {
                sectionHidden.value = this.value;
                console.log('Section ' + courseNum + ' changed to:', this.value);
            }
        });

        // Load sections when course changes
        courseSelect.addEventListener('change', function() {
            var courseid = this.value;
            console.log('Course ' + courseNum + ' selected:', courseid);

            if (!courseid) {
                sectionSelect.innerHTML = '<option value="">Please select a course first...</option>';
                return;
            }

            sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
            sectionSelect.disabled = true;

            fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + courseid + '&sesskey=' + M.cfg.sesskey)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    console.log('Sections loaded for Course ' + courseNum + ':', data);
                    var options = '<option value="">-- Select a section --</option>';
                    if (data && data.length > 0) {
                        data.forEach(function(section) {
                            options += '<option value="' + section.id + '">' + section.name + '</option>';
                        });
                    }
                    sectionSelect.innerHTML = options;
                    sectionSelect.disabled = false;
                })
                .catch(function(error) {
                    console.error('Failed to load sections for Course ' + courseNum + ':', error);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                    sectionSelect.disabled = false;
                });
        });

        // Trigger change if course is already selected
        if (courseSelect.value) {
            courseSelect.dispatchEvent(new Event('change'));
        }
    }

    // Setup Course 2 and 3 (Course 1 handled separately below since it's a hidden field)
    setupCourseSectionPair('id_course2', 'id_section2', 'section2_hidden', 2);
    setupCourseSectionPair('id_course3', 'id_section3', 'section3_hidden', 3);

    // Setup Course 1 (Central Question Bank) - Special handling since course is hidden field
    var course1Input = document.querySelector('input[name="course1"]');
    var section1Select = document.getElementById('id_section1');
    var section1Hidden = document.querySelector('input[name="section1_hidden"]');

    if (section1Select && section1Hidden) {
        // Update hidden field when section changes
        section1Select.addEventListener('change', function() {
            section1Hidden.value = this.value;
            console.log('Section 1 changed to:', this.value);
        });
    }

    // Load sections for Course 1 (Central Question Bank) automatically
    if (course1Input && course1Input.value && section1Select) {
        console.log('Loading sections for Central Question Bank (Course 1)...');
        fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + course1Input.value + '&sesskey=' + M.cfg.sesskey)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                console.log('Sections loaded for Course 1:', data);
                var options = '<option value="">-- Select a section --</option>';
                if (data && data.length > 0) {
                    data.forEach(function(section) {
                        options += '<option value="' + section.id + '">' + section.name + '</option>';
                    });
                }
                section1Select.innerHTML = options;
                section1Select.disabled = false;
            })
            .catch(function(error) {
                console.error('Failed to load sections for Course 1:', error);
                section1Select.innerHTML = '<option value="">Error loading sections</option>';
            });
    }

    console.log('Quiz uploader: Initialized successfully');

    // ===== CASCADING CATEGORY DROPDOWNS =====
    // Layer 1 is fixed as "System Category", so we start from Layer 2
    var catLayer2 = document.getElementById('id_cat_layer2');
    var catLayer2Hidden = document.querySelector('input[name="cat_layer2_hidden"]');
    var catLayer3 = document.getElementById('id_cat_layer3');
    var catLayer3Hidden = document.querySelector('input[name="cat_layer3_hidden"]');
    var catLayer4 = document.getElementById('id_cat_layer4');
    var catLayer4Hidden = document.querySelector('input[name="cat_layer4_hidden"]');
    var catLayer4New = document.getElementById('id_cat_layer4_new');

    // Load Layer 2 (Subject) on page load - find "System Category" first
    if (catLayer2) {
        console.log('Finding System Category to load Layer 2...');
        // First find the System Category ID
        fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_categories.php?parentid=0&level=1&sesskey=' + M.cfg.sesskey)
            .then(r => r.json())
            .then(data => {
                console.log('Top level categories:', data);
                // Find "System Category" or "System" in the results
                var systemCat = data.find(function(cat) {
                    return cat.name === 'System Category' || cat.name === 'System';
                });
                if (systemCat) {
                    console.log('Found System Category with ID:', systemCat.id);
                    loadCategories(systemCat.id, catLayer2, 2, catLayer2Hidden);
                } else {
                    console.error('System Category not found');
                }
            })
            .catch(err => console.error('Failed to find System Category:', err));
    }

    // Layer 2 change -> Update hidden field and load Layer 3
    if (catLayer2) {
        catLayer2.addEventListener('change', function() {
            var parentId = this.value;
            console.log('Layer 2 change event fired. Selected:', parentId);

            // Update hidden field
            if (catLayer2Hidden) {
                catLayer2Hidden.value = parentId;
                console.log('Layer 2 hidden field updated to:', parentId);
            }

            // Load Layer 3 if selection made
            if (parentId) {
                loadCategories(parentId, catLayer3, 3, catLayer3Hidden);
                // Reset lower layers
                catLayer4.innerHTML = '<option value="">-- Select or create new --</option>';
                if (catLayer4Hidden) catLayer4Hidden.value = '';
            }
        });
    }

    // Layer 3 change -> Update hidden field and load Layer 4
    if (catLayer3) {
        catLayer3.addEventListener('change', function() {
            var parentId = this.value;
            console.log('Layer 3 change event fired. Selected:', parentId);

            // Update hidden field
            if (catLayer3Hidden) {
                catLayer3Hidden.value = parentId;
                console.log('Layer 3 hidden field updated to:', parentId);
            }

            // Load Layer 4 if selection made
            if (parentId) {
                loadCategories(parentId, catLayer4, 4, catLayer4Hidden);
            }
        });
    }

    // Layer 4 change -> Update hidden field and clear "create new"
    if (catLayer4) {
        catLayer4.addEventListener('change', function() {
            var parentId = this.value;
            console.log('Layer 4 change event fired. Selected:', parentId);

            // Update hidden field
            if (catLayer4Hidden) {
                catLayer4Hidden.value = parentId;
                console.log('Layer 4 hidden field updated to:', parentId);
            }

            // Clear "create new" field
            if (catLayer4New) catLayer4New.value = '';
        });
    }

    // Function to load categories via AJAX
    function loadCategories(parentId, targetSelect, level, hiddenField) {
        console.log('loadCategories called: parentId=' + parentId + ', level=' + level);

        targetSelect.innerHTML = '<option value="">Loading...</option>';
        targetSelect.disabled = true;

        fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_categories.php?parentid=' + parentId + '&level=' + level + '&sesskey=' + M.cfg.sesskey)
            .then(r => {
                if (!r.ok) {
                    throw new Error('HTTP error ' + r.status);
                }
                return r.json();
            })
            .then(data => {
                console.log('Categories loaded for level ' + level + ':', data);

                if (!data || !Array.isArray(data)) {
                    throw new Error('Invalid response data');
                }

                var options = '<option value="">-- Select or create new --</option>';
                if (data.length > 0) {
                    data.forEach(function(cat) {
                        options += '<option value="' + cat.id + '">' + cat.name + '</option>';
                    });
                    console.log('Added ' + data.length + ' options to level ' + level);
                } else {
                    console.log('No categories found for level ' + level);
                }

                targetSelect.innerHTML = options;
                targetSelect.disabled = false;

                console.log('Level ' + level + ' dropdown populated successfully');
            })
            .catch(err => {
                console.error('Failed to load categories for level ' + level + ':', err);
                targetSelect.innerHTML = '<option value="">Error loading categories</option>';
                targetSelect.disabled = false;
            });
    }

    // Handle file upload via AJAX
    var fileInput = document.getElementById('xmlfile_upload');
    var fileStatus = document.getElementById('file_status');
    var draftItemIdField = document.querySelector('input[name="draftitemid"]');
    var form = document.querySelector('form');

    if (fileInput && form) {
        // Upload file when selected
        fileInput.addEventListener('change', function() {
            if (this.files.length === 0) {
                draftItemIdField.value = '';
                fileStatus.innerHTML = '';
                return;
            }

            var file = this.files[0];
            fileStatus.innerHTML = 'Uploading ' + file.name + '...';
            fileStatus.style.background = '#fff3cd';
            fileStatus.style.color = '#856404';
            fileStatus.style.padding = '8px';
            fileStatus.style.borderRadius = '4px';

            // Auto-fill Layer 5 (Topic Name) and Quiz Name from filename
            var filename = file.name.replace(/\.xml$/i, ''); // Remove .xml extension

            // Auto-fill Layer 5 (Topic Name)
            var layer5Field = document.getElementById('id_cat_layer5');
            if (layer5Field && !layer5Field.value) {
                layer5Field.value = filename;
                console.log('Auto-filled topic name from filename:', filename);
            }

            // Auto-fill all 3 Quiz Name fields
            var quizName1Field = document.getElementById('id_quizname1');
            if (quizName1Field && !quizName1Field.value) {
                quizName1Field.value = filename;
                console.log('Auto-filled quiz name 1 from filename:', filename);
            }

            var quizName2Field = document.getElementById('id_quizname2');
            if (quizName2Field && !quizName2Field.value) {
                quizName2Field.value = filename;
                console.log('Auto-filled quiz name 2 from filename:', filename);
            }

            var quizName3Field = document.getElementById('id_quizname3');
            if (quizName3Field && !quizName3Field.value) {
                quizName3Field.value = filename;
                console.log('Auto-filled quiz name 3 from filename:', filename);
            }

            // Upload file via AJAX endpoint
            var formData = new FormData();
            formData.append('xmlfile', file);
            formData.append('sesskey', M.cfg.sesskey);

            fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_upload_file.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    console.log('File uploaded successfully:', result);
                    draftItemIdField.value = result.draftitemid;
                    fileStatus.innerHTML = '✓ Uploaded: ' + result.filename + ' (' + (result.filesize/1024).toFixed(1) + ' KB)';
                    fileStatus.style.background = '#d4edda';
                    fileStatus.style.color = '#155724';
                } else {
                    console.error('Upload failed:', result.error);
                    draftItemIdField.value = '';
                    fileStatus.innerHTML = '✗ Upload failed: ' + result.error;
                    fileStatus.style.background = '#f8d7da';
                    fileStatus.style.color = '#721c24';
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                draftItemIdField.value = '';
                fileStatus.innerHTML = '✗ Upload error: ' + err.message;
                fileStatus.style.background = '#f8d7da';
                fileStatus.style.color = '#721c24';
            });
        });

        // Validate file on submit
        form.addEventListener('submit', function(e) {
            if (!draftItemIdField.value || draftItemIdField.value === '0') {
                e.preventDefault();
                alert('Please select an XML file first');
                return false;
            }
        });
    }
});
</script>
<?php

$mform->display();

echo $OUTPUT->footer();

/**
 * Process the file upload and create quiz
 */
function process_upload($data) {
    global $USER, $DB;

    try {
        // Get draft itemid (uploaded via JavaScript/Moodle API)
        if (empty($data->draftitemid)) {
            return [
                'success' => false,
                'message' => 'No file uploaded. Please select an XML file.'
            ];
        }

        $draftitemid = $data->draftitemid;
        error_log('Quiz Uploader - Using draft itemid: ' . $draftitemid);
        error_log('Quiz Uploader - Form data received: ' . print_r($data, true));

        // No quiz settings - removed from form
        $settings = new \stdClass();

        // File already uploaded via JavaScript to draft area
        require_once(__DIR__ . '/classes/external/import_quiz_from_xml.php');

        // Build 5-layer category structure from form data
        require_once(__DIR__ . '/classes/category_manager.php');
        $systemcontext = context_system::instance();

        // Debug: Log all category layer values (both dropdowns and hidden fields)
        error_log('Quiz Uploader - cat_layer1: ' . ($data->cat_layer1 ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer1_hidden: ' . ($data->cat_layer1_hidden ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer2: ' . ($data->cat_layer2 ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer2_hidden: ' . ($data->cat_layer2_hidden ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer2_new: ' . ($data->cat_layer2_new ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer3: ' . ($data->cat_layer3 ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer3_hidden: ' . ($data->cat_layer3_hidden ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer3_new: ' . ($data->cat_layer3_new ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer4: ' . ($data->cat_layer4 ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer4_hidden: ' . ($data->cat_layer4_hidden ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer4_new: ' . ($data->cat_layer4_new ?? 'NOT SET'));
        error_log('Quiz Uploader - cat_layer5: ' . ($data->cat_layer5 ?? 'NOT SET'));

        // Build category path array
        $categorypath = [];

        // Layer 1: System (fixed value)
        if (!empty($data->cat_layer1_fixed)) {
            $categorypath[] = trim($data->cat_layer1_fixed);
        }

        // Layer 2: Subject (select from dropdown only)
        if (!empty($data->cat_layer2_hidden)) {
            $layer2cat = $DB->get_record('question_categories', ['id' => $data->cat_layer2_hidden]);
            if ($layer2cat) {
                $categorypath[] = $layer2cat->name;
            }
        }

        // Layer 3: Type (select from dropdown only)
        if (!empty($data->cat_layer3_hidden)) {
            $layer3cat = $DB->get_record('question_categories', ['id' => $data->cat_layer3_hidden]);
            if ($layer3cat) {
                $categorypath[] = $layer3cat->name;
            }
        }

        // Layer 4: Class Code (use hidden field or new)
        if (!empty($data->cat_layer4_hidden)) {
            $layer4cat = $DB->get_record('question_categories', ['id' => $data->cat_layer4_hidden]);
            if ($layer4cat) {
                $categorypath[] = $layer4cat->name;
            }
        } else if (!empty($data->cat_layer4_new)) {
            $categorypath[] = trim($data->cat_layer4_new);
        }

        // Layer 5: Topic Name (always from text field)
        if (!empty($data->cat_layer5)) {
            $categorypath[] = trim($data->cat_layer5);
        }

        error_log('Quiz Uploader - Category path: ' . print_r($categorypath, true));

        // Check for duplicates BEFORE creating category (if enabled)
        if ($data->checkduplicates) {
            require_once(__DIR__ . '/classes/duplicate_checker.php');

            // Get the topic name (last element of category path - Layer 5)
            $topicname = end($categorypath);

            error_log('Quiz Uploader - Checking for duplicates: topic=' . $topicname . ', context=' . $systemcontext->id);

            $dupcheck = \local_quiz_uploader\duplicate_checker::check_all(
                null, // courseid not needed for category-only check
                null, // quiz name not checked
                $topicname,
                $systemcontext->id
            );

            if ($dupcheck->has_duplicates) {
                return [
                    'success' => false,
                    'message' => "Duplicate found: Topic '{$dupcheck->category_name}' already exists with questions in the question bank.",
                    'error' => 'duplicate_detected'
                ];
            }
        }

        // Create category hierarchy if needed
        $category = \local_quiz_uploader\category_manager::ensure_category_hierarchy($categorypath, $systemcontext->id);
        if (!$category) {
            return [
                'success' => false,
                'message' => 'Failed to create category hierarchy'
            ];
        }

        error_log('Quiz Uploader - Category created/found: ' . $category->id . ' - ' . $category->name);

        // Build array of course/section pairs to create quizzes in
        $quiz_destinations = [];

        // Course 1: Central Question Bank (Required)
        $section1id = null;
        if (!empty($data->section1_hidden)) {
            $section1id = $data->section1_hidden;
        } else if (!empty($data->section1)) {
            $section1id = $data->section1;
        } else {
            // Find first section (section 0) for this course
            $firstsection = $DB->get_record('course_sections', ['course' => $data->course1, 'section' => 0], 'id');
            if ($firstsection) {
                $section1id = $firstsection->id;
            }
        }

        if (!empty($data->course1) && !empty($section1id)) {
            // Validate quiz name exists - check if property exists first
            $quizname1 = isset($data->quizname1) ? trim($data->quizname1) : '';

            if (empty($quizname1)) {
                return [
                    'success' => false,
                    'message' => 'Quiz name 1 is required for Course 1 (Central Question Bank). Please enter a quiz name.'
                ];
            }

            $quiz_destinations[] = [
                'course' => $data->course1,
                'section' => $section1id,
                'quizname' => $quizname1,
                'label' => 'Course 1 (Central Question Bank)',
                'settings' => $data->quizsettings1 ?? 'default',
                'timelimit' => $data->timelimit1 ?? 45
            ];
        }

        // Course 2: Optional
        if (!empty($data->course2)) {
            $section2id = null;
            if (!empty($data->section2_hidden)) {
                $section2id = $data->section2_hidden;
            } else if (!empty($data->section2)) {
                $section2id = $data->section2;
            } else {
                // Find first section for this course
                $firstsection = $DB->get_record('course_sections', ['course' => $data->course2, 'section' => 0], 'id');
                if ($firstsection) {
                    $section2id = $firstsection->id;
                }
            }

            if (!empty($section2id) && !empty($data->quizname2)) {
                $quiz_destinations[] = [
                    'course' => $data->course2,
                    'section' => $section2id,
                    'quizname' => $data->quizname2,
                    'label' => 'Course 2',
                    'settings' => $data->quizsettings2 ?? 'default',
                    'timelimit' => $data->timelimit2 ?? 45
                ];
            }
        }

        // Course 3: Optional
        if (!empty($data->course3)) {
            $section3id = null;
            if (!empty($data->section3_hidden)) {
                $section3id = $data->section3_hidden;
            } else if (!empty($data->section3)) {
                $section3id = $data->section3;
            } else {
                // Find first section for this course
                $firstsection = $DB->get_record('course_sections', ['course' => $data->course3, 'section' => 0], 'id');
                if ($firstsection) {
                    $section3id = $firstsection->id;
                }
            }

            if (!empty($section3id) && !empty($data->quizname3)) {
                $quiz_destinations[] = [
                    'course' => $data->course3,
                    'section' => $section3id,
                    'quizname' => $data->quizname3,
                    'label' => 'Course 3',
                    'settings' => $data->quizsettings3 ?? 'default',
                    'timelimit' => $data->timelimit3 ?? 45
                ];
            }
        }

        error_log('Quiz Uploader - Quiz destinations: ' . print_r($quiz_destinations, true));

        // Import questions ONCE (not 3 times!)
        require_once(__DIR__ . '/classes/question_importer.php');
        require_once(__DIR__ . '/classes/quiz_creator.php');

        // Get XML content from draft area
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id DESC', false);

        if (empty($files)) {
            return [
                'success' => false,
                'message' => 'No file found in draft area'
            ];
        }

        $file = reset($files);
        $xmlcontent = $file->get_content();

        // Import questions to the category (ONCE)
        error_log('Quiz Uploader - Importing questions to category: ' . $category->name);
        $importresult = \local_quiz_uploader\question_importer::import_from_xml($xmlcontent, $category, $quiz_destinations[0]['course']);

        if (!$importresult->success) {
            return [
                'success' => false,
                'message' => 'Failed to import questions: ' . ($importresult->error ?? 'Unknown error')
            ];
        }

        error_log('Quiz Uploader - Imported ' . count($importresult->questionids) . ' questions');

        // Create quizzes in all specified destinations (using same questions)
        $results = [];
        $first_result = null;

        foreach ($quiz_destinations as $destination) {
            error_log('Quiz Uploader - Creating quiz "' . $destination['quizname'] . '" in ' . $destination['label'] . ': course=' . $destination['course'] . ', section=' . $destination['section']);

            // Build settings based on quiz settings mode
            $quizsettingsmode = $destination['settings'] ?? 'default';
            $questioncount = count($importresult->questionids);
            $timelimit = $destination['timelimit'] ?? 45;
            $settings = \local_quiz_uploader\quiz_creator::build_quiz_settings($quizsettingsmode, $questioncount, $timelimit);
            
            error_log('Quiz Uploader - Using quiz settings mode: ' . $quizsettingsmode . ' with ' . $questioncount . ' questions and time limit: ' . $timelimit . ' minutes');

            // Create quiz
            $quizresult = \local_quiz_uploader\quiz_creator::create_quiz(
                $destination['course'],
                $destination['section'],
                $destination['quizname'],
                '',  // intro text
                $settings  // Pass settings object directly
            );

            if (!$quizresult->success) {
                return [
                    'success' => false,
                    'message' => 'Failed to create quiz in ' . $destination['label'] . ': ' . ($quizresult->error ?? 'Unknown error'),
                    'quizname' => $destination['quizname'],
                    'questioncount' => 0
                ];
            }

            // Add questions to quiz (reuse same question IDs)
            $addresult = \local_quiz_uploader\quiz_creator::add_questions_to_quiz($quizresult->quizid, $importresult->questionids);

            if (!$addresult->success) {
                return [
                    'success' => false,
                    'message' => 'Failed to add questions to quiz in ' . $destination['label'] . ': ' . ($addresult->error ?? 'Unknown error'),
                    'quizname' => $destination['quizname'],
                    'questioncount' => 0
                ];
            }

            $results[] = [
                'label' => $destination['label'],
                'success' => true,
                'message' => 'Quiz created with ' . count($importresult->questionids) . ' questions'
            ];

            // Store first result for compatibility
            if ($first_result === null) {
                $first_result = [
                    'success' => true,
                    'questionsimported' => count($importresult->questionids),
                    'quizid' => $quizresult->quizid
                ];
            }
        }

        // All quizzes created successfully - build detailed message
        $quiz_details = [];
        foreach ($quiz_destinations as $dest) {
            $quiz_details[] = '"' . $dest['quizname'] . '" in ' . $dest['label'];
        }
        $success_message = count($results) . ' quiz(es) created successfully: ' . implode(', ', $quiz_details);

        return [
            'success' => true,
            'message' => $success_message,
            'quizname' => $quiz_destinations[0]['quizname'], // Return first quiz name
            'questioncount' => $first_result['questionsimported'] ?? 0,
            'debug' => null,
        ];

    } catch (\Exception $e) {
        error_log('Quiz Uploader - Exception: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
        return [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'debug' => $e->getTraceAsString()
        ];
    }
}
