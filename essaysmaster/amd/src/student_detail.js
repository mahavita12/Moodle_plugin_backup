define(['jquery'], function($) {
    'use strict';

    var StudentDetail = {
        init: function() {
            this.bindEvents();
            this.initFilters();
        },

        bindEvents: function() {
            var self = this;
            
            // Filter change events
            $('#quiz-filter, #round-filter, #date-range').on('change', function() {
                self.applyFilters();
            });
            
            // Reset filters button
            $('#reset-filters').on('click', function() {
                self.resetFilters();
            });
        },

        initFilters: function() {
            // Apply any URL parameters as initial filters
            var urlParams = new URLSearchParams(window.location.search);
            
            if (urlParams.has('quiz')) {
                $('#quiz-filter').val(urlParams.get('quiz'));
            }
            if (urlParams.has('round')) {
                $('#round-filter').val(urlParams.get('round'));
            }
            if (urlParams.has('days')) {
                $('#date-range').val(urlParams.get('days'));
            }
            
            // Apply initial filters
            this.applyFilters();
        },

        applyFilters: function() {
            var quizFilter = $('#quiz-filter').val();
            var roundFilter = $('#round-filter').val();
            var dateFilter = $('#date-range').val();
            
            // Filter table rows
            $('#attempts-table tbody tr').each(function() {
                var row = $(this);
                var show = true;
                
                // Quiz filter
                if (quizFilter && quizFilter !== '') {
                    var quizName = row.find('td:first').text().toLowerCase();
                    if (quizName.indexOf(quizFilter.toLowerCase()) === -1) {
                        show = false;
                    }
                }
                
                // Round filter
                if (roundFilter && roundFilter !== '') {
                    var roundText = row.find('td:nth-child(3)').text();
                    var roundNum = roundText.match(/\d+/);
                    if (!roundNum || roundNum[0] !== roundFilter) {
                        show = false;
                    }
                }
                
                // Date filter (simplified - would need server-side implementation for full functionality)
                if (dateFilter && dateFilter !== '') {
                    var attemptDate = new Date(row.find('td:nth-child(2)').text());
                    var now = new Date();
                    var daysAgo = parseInt(dateFilter);
                    var cutoffDate = new Date(now.getTime() - (daysAgo * 24 * 60 * 60 * 1000));
                    
                    if (attemptDate < cutoffDate) {
                        show = false;
                    }
                }
                
                if (show) {
                    row.show();
                } else {
                    row.hide();
                }
            });
            
            // Update visible count
            this.updateVisibleCount();
        },

        resetFilters: function() {
            $('#quiz-filter').val('');
            $('#round-filter').val('');
            $('#date-range').val('');
            
            // Show all rows
            $('#attempts-table tbody tr').show();
            
            this.updateVisibleCount();
        },

        updateVisibleCount: function() {
            var totalRows = $('#attempts-table tbody tr').length;
            var visibleRows = $('#attempts-table tbody tr:visible').length;
            
            // Update count display if element exists
            if ($('#visible-count').length) {
                $('#visible-count').text(visibleRows + ' of ' + totalRows);
            }
        }
    };

    return StudentDetail;
});