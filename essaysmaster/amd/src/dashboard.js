define(['jquery', 'core/notification', 'core/templates', 'core/str'], function($, notification, templates, str) {
    'use strict';

    var Dashboard = {
        init: function() {
            this.bindEvents();
            this.refreshData();
            this.startAutoRefresh();
        },

        bindEvents: function() {
            var self = this;
            
            // Quiz toggle switches
            $(document).on('change', '.quiz-toggle', function() {
                var quizId = $(this).data('quiz-id');
                var isEnabled = $(this).prop('checked');
                self.toggleQuiz(quizId, isEnabled);
            });

            // Bulk actions
            $('#bulk-enable').on('click', function() {
                self.bulkAction('enable');
            });

            $('#bulk-disable').on('click', function() {
                self.bulkAction('disable');
            });

            // Select all checkbox
            $('#select-all-quizzes').on('change', function() {
                $('.quiz-checkbox').prop('checked', $(this).prop('checked'));
            });

            // Individual quiz checkboxes
            $(document).on('change', '.quiz-checkbox', function() {
                var totalCheckboxes = $('.quiz-checkbox').length;
                var checkedCheckboxes = $('.quiz-checkbox:checked').length;
                $('#select-all-quizzes').prop('checked', totalCheckboxes === checkedCheckboxes);
            });

            // Tab switching
            $('.nav-link').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                self.switchTab(tab);
            });

            // Refresh button
            $('#refresh-data').on('click', function() {
                self.refreshData();
            });

            // Student detail links
            $(document).on('click', '.student-detail-link', function(e) {
                e.preventDefault();
                var userId = $(this).data('user-id');
                self.showStudentDetail(userId);
            });

            // Search functionality
            $('#student-search').on('keyup', function() {
                var searchTerm = $(this).val().toLowerCase();
                self.filterStudents(searchTerm);
            });

            $('#quiz-search').on('keyup', function() {
                var searchTerm = $(this).val().toLowerCase();
                self.filterQuizzes(searchTerm);
            });
        },

        toggleQuiz: function(quizId, isEnabled) {
            var self = this;
            
            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/toggle_quiz.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey,
                    quiz_id: quizId,
                    enabled: isEnabled ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        var message = isEnabled ? 
                            str.get_string('quiz_enabled', 'local_essaysmaster') :
                            str.get_string('quiz_disabled', 'local_essaysmaster');
                        notification.addNotification({
                            message: message,
                            type: 'success'
                        });
                        self.updateQuizStats();
                    } else {
                        notification.exception(new Error(response.error));
                        // Revert the toggle
                        $('[data-quiz-id="' + quizId + '"]').prop('checked', !isEnabled);
                    }
                },
                error: function(xhr, status, error) {
                    notification.exception(new Error('AJAX request failed: ' + error));
                    // Revert the toggle
                    $('[data-quiz-id="' + quizId + '"]').prop('checked', !isEnabled);
                }
            });
        },

        bulkAction: function(action) {
            var self = this;
            var selectedQuizzes = [];
            
            $('.quiz-checkbox:checked').each(function() {
                selectedQuizzes.push($(this).data('quiz-id'));
            });

            if (selectedQuizzes.length === 0) {
                notification.alert(
                    str.get_string('no_quizzes_selected', 'local_essaysmaster'),
                    str.get_string('select_quizzes_first', 'local_essaysmaster')
                );
                return;
            }

            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/bulk_action.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey,
                    quiz_ids: selectedQuizzes,
                    action: action
                },
                success: function(response) {
                    if (response.success) {
                        var message = action === 'enable' ?
                            str.get_string('quizzes_enabled', 'local_essaysmaster') :
                            str.get_string('quizzes_disabled', 'local_essaysmaster');
                        notification.addNotification({
                            message: message,
                            type: 'success'
                        });
                        
                        // Update toggles
                        selectedQuizzes.forEach(function(quizId) {
                            $('[data-quiz-id="' + quizId + '"]').prop('checked', action === 'enable');
                        });
                        
                        // Clear selections
                        $('.quiz-checkbox').prop('checked', false);
                        $('#select-all-quizzes').prop('checked', false);
                        
                        self.updateQuizStats();
                    } else {
                        notification.exception(new Error(response.error));
                    }
                },
                error: function(xhr, status, error) {
                    notification.exception(new Error('AJAX request failed: ' + error));
                }
            });
        },

        switchTab: function(tab) {
            // Update active tab
            $('.nav-link').removeClass('active');
            $('[data-tab="' + tab + '"]').addClass('active');
            
            // Show/hide content
            $('.tab-content').hide();
            $('#' + tab + '-tab').show();
            
            // Load tab-specific data if needed
            if (tab === 'students') {
                this.loadStudentData();
            } else if (tab === 'quizzes') {
                this.loadQuizData();
            }
        },

        refreshData: function() {
            var activeTab = $('.nav-link.active').data('tab');
            
            if (activeTab === 'overview') {
                this.loadOverviewData();
            } else if (activeTab === 'students') {
                this.loadStudentData();
            } else if (activeTab === 'quizzes') {
                this.loadQuizData();
            }
        },

        loadOverviewData: function() {
            var self = this;
            
            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/get_overview.php',
                type: 'GET',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey
                },
                success: function(response) {
                    if (response.success) {
                        self.updateOverviewStats(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Failed to load overview data: ' + error);
                }
            });
        },

        loadStudentData: function() {
            var self = this;
            
            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/get_students.php',
                type: 'GET',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey
                },
                success: function(response) {
                    if (response.success) {
                        self.updateStudentTable(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Failed to load student data: ' + error);
                }
            });
        },

        loadQuizData: function() {
            var self = this;
            
            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/get_quizzes.php',
                type: 'GET',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey
                },
                success: function(response) {
                    if (response.success) {
                        self.updateQuizTable(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('Failed to load quiz data: ' + error);
                }
            });
        },

        updateOverviewStats: function(data) {
            $('#total-students').text(data.total_students);
            $('#active-students').text(data.active_students);
            $('#total-quizzes').text(data.total_quizzes);
            $('#enabled-quizzes').text(data.enabled_quizzes);
            $('#total-attempts').text(data.total_attempts);
            $('#avg-improvement').text(data.avg_improvement + '%');
        },

        updateStudentTable: function(data) {
            var tbody = $('#students-table tbody');
            tbody.empty();
            
            data.students.forEach(function(student) {
                var row = $('<tr>');
                row.append('<td><a href="#" class="student-detail-link" data-user-id="' + student.id + '">' + student.fullname + '</a></td>');
                row.append('<td>' + student.email + '</td>');
                row.append('<td>' + student.total_attempts + '</td>');
                row.append('<td>' + student.completed_rounds + '</td>');
                row.append('<td>' + student.avg_improvement + '%</td>');
                row.append('<td><span class="badge badge-' + (student.last_activity_days <= 7 ? 'success' : 'secondary') + '">' + student.last_activity + '</span></td>');
                tbody.append(row);
            });
        },

        updateQuizTable: function(data) {
            var tbody = $('#quizzes-table tbody');
            tbody.empty();
            
            data.quizzes.forEach(function(quiz) {
                var row = $('<tr>');
                row.append('<td><input type="checkbox" class="quiz-checkbox" data-quiz-id="' + quiz.id + '"></td>');
                row.append('<td>' + quiz.name + '</td>');
                row.append('<td>' + quiz.course_name + '</td>');
                row.append('<td>' + quiz.attempts_count + '</td>');
                row.append('<td>' + quiz.avg_improvement + '%</td>');
                
                var toggleHtml = '<label class="switch"><input type="checkbox" class="quiz-toggle" data-quiz-id="' + quiz.id + '"' + 
                    (quiz.is_enabled ? ' checked' : '') + '><span class="slider"></span></label>';
                row.append('<td>' + toggleHtml + '</td>');
                
                tbody.append(row);
            });
        },

        updateQuizStats: function() {
            var self = this;
            
            $.ajax({
                url: M.cfg.wwwroot + '/local/essaysmaster/ajax/get_quiz_stats.php',
                type: 'GET',
                dataType: 'json',
                data: {
                    sesskey: M.cfg.sesskey
                },
                success: function(response) {
                    if (response.success) {
                        $('#total-quizzes').text(response.data.total_quizzes);
                        $('#enabled-quizzes').text(response.data.enabled_quizzes);
                    }
                }
            });
        },

        showStudentDetail: function(userId) {
            window.location.href = M.cfg.wwwroot + '/local/essaysmaster/student_detail.php?id=' + userId;
        },

        filterStudents: function(searchTerm) {
            $('#students-table tbody tr').each(function() {
                var studentName = $(this).find('td:first').text().toLowerCase();
                var studentEmail = $(this).find('td:nth-child(2)').text().toLowerCase();
                
                if (studentName.indexOf(searchTerm) !== -1 || studentEmail.indexOf(searchTerm) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        },

        filterQuizzes: function(searchTerm) {
            $('#quizzes-table tbody tr').each(function() {
                var quizName = $(this).find('td:nth-child(2)').text().toLowerCase();
                var courseName = $(this).find('td:nth-child(3)').text().toLowerCase();
                
                if (quizName.indexOf(searchTerm) !== -1 || courseName.indexOf(searchTerm) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        },

        startAutoRefresh: function() {
            var self = this;
            // Refresh overview data every 30 seconds
            setInterval(function() {
                if ($('.nav-link.active').data('tab') === 'overview') {
                    self.loadOverviewData();
                }
            }, 30000);
        }
    };

    return Dashboard;
});