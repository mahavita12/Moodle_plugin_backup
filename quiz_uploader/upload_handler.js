// Quiz Uploader form handler
(function() {
    'use strict';

    function initializeForm() {
        console.log('Quiz uploader: Initializing form...');

        var courseSelect = document.getElementById('id_course');
        var sectionSelect = document.getElementById('id_section');

        if (!courseSelect || !sectionSelect) {
            console.error('Quiz uploader: Form elements not found');
            return;
        }

        console.log('Quiz uploader: Form elements found');

        courseSelect.addEventListener('change', function() {
            var courseid = this.value;
            console.log('Quiz uploader: Course selected:', courseid);

            if (!courseid) {
                sectionSelect.innerHTML = '<option value="">Please select a course first...</option>';
                return;
            }

            sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
            sectionSelect.disabled = true;

            var url = M.cfg.wwwroot + '/local/quiz_uploader/ajax_get_sections.php?courseid=' +
                      encodeURIComponent(courseid) + '&sesskey=' + M.cfg.sesskey;

            fetch(url)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    console.log('Quiz uploader: Sections loaded:', data);

                    var options = '<option value="">-- Select a section --</option>';
                    if (data && data.length > 0) {
                        data.forEach(function(section) {
                            options += '<option value="' + section.id + '">' +
                                      section.name + '</option>';
                        });
                    }
                    sectionSelect.innerHTML = options;
                    sectionSelect.disabled = false;
                })
                .catch(function(error) {
                    console.error('Quiz uploader: Failed to load sections:', error);
                    sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
                    sectionSelect.disabled = false;
                });
        });

        // Load saved settings from localStorage
        try {
            var savedTimelimit = localStorage.getItem('quiz_uploader_timelimit');
            var savedAttempts = localStorage.getItem('quiz_uploader_completionminattempts');

            if (savedTimelimit) {
                var timelimitField = document.getElementById('id_timelimit_number');
                if (timelimitField) {
                    timelimitField.value = savedTimelimit;
                }
            }
            if (savedAttempts) {
                var attemptsField = document.getElementById('id_completionminattempts');
                if (attemptsField) {
                    attemptsField.value = savedAttempts;
                }
            }

            // Save settings on form submit
            var form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function() {
                    var timelimitField = document.getElementById('id_timelimit_number');
                    var attemptsField = document.getElementById('id_completionminattempts');

                    if (timelimitField && timelimitField.value) {
                        localStorage.setItem('quiz_uploader_timelimit', timelimitField.value);
                    }
                    if (attemptsField && attemptsField.value) {
                        localStorage.setItem('quiz_uploader_completionminattempts', attemptsField.value);
                    }
                });
            }
        } catch(e) {
            console.error('Quiz uploader: localStorage error:', e);
        }

        console.log('Quiz uploader: Form initialized successfully');
    }

    // Wait for DOM and Moodle to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeForm);
    } else {
        initializeForm();
    }
})();
