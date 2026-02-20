/**
 * Quiz uploader form JavaScript
 * @module local_quiz_uploader/upload
 */
define(['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {

    /**
     * Set up synchronization between a course dropdown and a section dropdown.
     *
     * @param {string} courseSelector The CSS selector for the course dropdown.
     * @param {string} sectionSelector The CSS selector for the section dropdown.
     */
    function setupCourseSectionSync(courseSelector, sectionSelector) {
        var courseSelect = $(courseSelector);
        var sectionSelect = $(sectionSelector);

        if (!courseSelect.length || !sectionSelect.length) {
            return;
        }

        // Handle course selection change
        courseSelect.on('change', function () {
            var courseid = $(this).val();

            if (!courseid) {
                sectionSelect.html('<option value="">Please select a course first...</option>');
                return;
            }

            // Show loading
            sectionSelect.html('<option value="">Loading sections...</option>');
            sectionSelect.prop('disabled', true);

            // Get sections via AJAX
            $.ajax({
                url: M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php',
                method: 'GET',
                data: {
                    courseid: courseid,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                success: function (data) {
                    var options = '<option value="">-- Select a section --</option>';
                    data.forEach(function (section) {
                        options += '<option value="' + section.id + '">' + section.name + '</option>';
                    });

                    sectionSelect.html(options);
                    sectionSelect.prop('disabled', false);

                    // Restore selection from URL if present (for Dry Run support)
                    var urlParams = new URLSearchParams(window.location.search);
                    // Determine parameter name based on selector
                    var paramName = (sectionSelector === '#id_targetsection') ? 'targetsection' : 'section';
                    var savedVal = urlParams.get(paramName);

                    if (savedVal && sectionSelect.find('option[value="' + savedVal + '"]').length > 0) {
                        sectionSelect.val(savedVal);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Failed to load sections:', error);
                    Notification.addNotification({
                        message: 'Failed to load sections. Please try again.',
                        type: 'error'
                    });
                    sectionSelect.html('<option value="">Error loading sections</option>');
                    sectionSelect.prop('disabled', false);
                }
            });
        });

        // Trigger change event if value is already selected (e.g. after form submit/reload)
        if (courseSelect.val()) {
            courseSelect.trigger('change');
        }
    }

    return {
        init: function () {
            // Setup sync for the standard Upload tab (Course -> Section)
            setupCourseSectionSync('#id_course', '#id_section');

            // Setup sync for the Copy from other courses tab (Target Course -> Target Section)
            setupCourseSectionSync('#id_targetcourse', '#id_targetsection');

            // Load saved settings from localStorage
            var savedTimelimit = localStorage.getItem('quiz_uploader_timelimit');
            var savedAttempts = localStorage.getItem('quiz_uploader_completionminattempts');

            if (savedTimelimit) {
                $('#id_timelimit_number').val(savedTimelimit);
            }
            if (savedAttempts) {
                $('#id_completionminattempts').val(savedAttempts);
            }

            // Save settings to localStorage on form submit
            $('form').on('submit', function () {
                var timelimit = $('#id_timelimit_number').val();
                var attempts = $('#id_completionminattempts').val();

                if (timelimit) {
                    localStorage.setItem('quiz_uploader_timelimit', timelimit);
                }
                if (attempts) {
                    localStorage.setItem('quiz_uploader_completionminattempts', attempts);
                }
            });
        }
    };
});
