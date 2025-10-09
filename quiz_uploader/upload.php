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
echo $OUTPUT->heading('Upload Quiz from XML File');

// Add JavaScript for section loading
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Quiz uploader: Initializing...');

    var courseSelect = document.getElementById('id_course');
    var sectionSelect = document.getElementById('id_section');
    var sectionHidden = document.querySelector('input[name="section_hidden"]');

    if (!courseSelect || !sectionSelect) {
        console.error('Form elements not found');
        return;
    }

    console.log('Quiz uploader: Form elements found');

    // Update hidden field when section changes
    sectionSelect.addEventListener('change', function() {
        if (sectionHidden) {
            sectionHidden.value = this.value;
            console.log('Section changed to:', this.value);
        }
    });

    courseSelect.addEventListener('change', function() {
        var courseid = this.value;
        console.log('Course selected:', courseid);

        if (!courseid) {
            sectionSelect.innerHTML = '<option value="">Please select a course first...</option>';
            return;
        }

        sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
        sectionSelect.disabled = true;

        fetch(M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' + courseid + '&sesskey=' + M.cfg.sesskey)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                console.log('Sections loaded:', data);
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
                console.error('Failed to load sections:', error);
                sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                sectionSelect.disabled = false;
            });
    });

    // Trigger change if course is already selected
    if (courseSelect.value) {
        courseSelect.dispatchEvent(new Event('change'));
    }

    console.log('Quiz uploader: Initialized successfully');

    // ===== CASCADING CATEGORY DROPDOWNS =====
    var catLayer1 = document.getElementById('id_cat_layer1');
    var catLayer1Hidden = document.querySelector('input[name="cat_layer1_hidden"]');
    var catLayer2 = document.getElementById('id_cat_layer2');
    var catLayer2Hidden = document.querySelector('input[name="cat_layer2_hidden"]');
    var catLayer2New = document.getElementById('id_cat_layer2_new');
    var catLayer3 = document.getElementById('id_cat_layer3');
    var catLayer3Hidden = document.querySelector('input[name="cat_layer3_hidden"]');
    var catLayer3New = document.getElementById('id_cat_layer3_new');
    var catLayer4 = document.getElementById('id_cat_layer4');
    var catLayer4Hidden = document.querySelector('input[name="cat_layer4_hidden"]');
    var catLayer4New = document.getElementById('id_cat_layer4_new');

    // Load Layer 1 (System categories) on page load
    if (catLayer1) {
        console.log('Loading Layer 1 categories...');
        loadCategories(0, catLayer1, 1);
    }

    // Layer 1 change -> Update hidden field and load Layer 2
    if (catLayer1) {
        catLayer1.addEventListener('change', function() {
            var parentId = this.value;
            console.log('Layer 1 change event fired. Selected:', parentId);

            // Update hidden field
            if (catLayer1Hidden) {
                catLayer1Hidden.value = parentId;
                console.log('Layer 1 hidden field updated to:', parentId);
            }

            // Load Layer 2 if selection made
            if (parentId) {
                loadCategories(parentId, catLayer2, 2, catLayer2Hidden);
                // Reset lower layers
                catLayer3.innerHTML = '<option value="">-- Select or create new --</option>';
                catLayer4.innerHTML = '<option value="">-- Select or create new --</option>';
                if (catLayer3Hidden) catLayer3Hidden.value = '';
                if (catLayer4Hidden) catLayer4Hidden.value = '';
            }
        });
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

            // Clear "create new" field
            if (catLayer2New) catLayer2New.value = '';

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

            // Clear "create new" field
            if (catLayer3New) catLayer3New.value = '';

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

            // Auto-fill Quiz Name
            var quizNameField = document.getElementById('id_quizname');
            if (quizNameField && !quizNameField.value) {
                quizNameField.value = filename;
                console.log('Auto-filled quiz name from filename:', filename);
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

        // Build quiz settings
        $settings = new \stdClass();
        if (!empty($data->timeclose)) {
            $settings->timeclose = $data->timeclose;
        }
        if (!empty($data->timelimit)) {
            $settings->timelimit = $data->timelimit;
        }
        if (!empty($data->completionminattempts)) {
            $settings->completionminattempts = $data->completionminattempts;
        }

        // File already uploaded via JavaScript to draft area
        // Just call web service with the draft itemid
        require_once(__DIR__ . '/classes/external/import_quiz_from_xml.php');

        // Get section - try hidden field first, then regular field, then default to section 0
        $sectionid = null;
        if (!empty($data->section_hidden)) {
            $sectionid = $data->section_hidden;
        } else if (!empty($data->section)) {
            $sectionid = $data->section;
        } else {
            // Find first section (section 0) for this course
            $firstsection = $DB->get_record('course_sections', ['course' => $data->course, 'section' => 0], 'id');
            if ($firstsection) {
                $sectionid = $firstsection->id;
                error_log('Quiz Uploader - Section not provided, using first section: ' . $sectionid);
            } else {
                return [
                    'success' => false,
                    'message' => 'Could not find a section in the selected course'
                ];
            }
        }

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

        // Layer 1: System (use hidden field)
        if (!empty($data->cat_layer1_hidden)) {
            $layer1cat = $DB->get_record('question_categories', ['id' => $data->cat_layer1_hidden]);
            if ($layer1cat) {
                $categorypath[] = $layer1cat->name;
            }
        }

        // Layer 2: Subject (use hidden field or new)
        if (!empty($data->cat_layer2_hidden)) {
            $layer2cat = $DB->get_record('question_categories', ['id' => $data->cat_layer2_hidden]);
            if ($layer2cat) {
                $categorypath[] = $layer2cat->name;
            }
        } else if (!empty($data->cat_layer2_new)) {
            $categorypath[] = trim($data->cat_layer2_new);
        }

        // Layer 3: Type (use hidden field or new)
        if (!empty($data->cat_layer3_hidden)) {
            $layer3cat = $DB->get_record('question_categories', ['id' => $data->cat_layer3_hidden]);
            if ($layer3cat) {
                $categorypath[] = $layer3cat->name;
            }
        } else if (!empty($data->cat_layer3_new)) {
            $categorypath[] = trim($data->cat_layer3_new);
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

        // Create category hierarchy if needed
        $category = \local_quiz_uploader\category_manager::ensure_category_hierarchy($categorypath, $systemcontext->id);
        if (!$category) {
            return [
                'success' => false,
                'message' => 'Failed to create category hierarchy'
            ];
        }

        error_log('Quiz Uploader - Category created/found: ' . $category->id . ' - ' . $category->name);

        error_log('Quiz Uploader - Calling import_quiz_from_xml with course=' . $data->course . ', section=' . $sectionid . ', draftitemid=' . $draftitemid . ', category=' . $category->id);

        $result = \local_quiz_uploader\external\import_quiz_from_xml::execute(
            $data->course,
            $sectionid,
            $draftitemid,
            $data->quizname,
            $data->checkduplicates ? 1 : 0,
            json_encode($settings)
        );

        error_log('Quiz Uploader - import_quiz_from_xml returned: ' . print_r($result, true));

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'quizname' => $data->quizname,
            'questioncount' => $result['questionsimported'] ?? 0,
            'debug' => isset($result['error']) ? 'Error code: ' . $result['error'] : null,
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
