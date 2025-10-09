/**
 * Quiz uploader form JavaScript
 * @module local_quiz_uploader/upload
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    return {
        init: function() {
            console.log('Quiz uploader form initialized');

            var courseSelect = $('#id_course');
            var sectionSelect = $('#id_section');

            // Handle course selection change
            courseSelect.on('change', function() {
                var courseid = $(this).val();
                console.log('Course selected:', courseid);

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
                    success: function(data) {
                        console.log('Sections loaded:', data);

                        var options = '<option value="">-- Select a section --</option>';
                        data.forEach(function(section) {
                            options += '<option value="' + section.id + '">' + section.name + '</option>';
                        });

                        sectionSelect.html(options);
                        sectionSelect.prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
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
            $('form').on('submit', function() {
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
