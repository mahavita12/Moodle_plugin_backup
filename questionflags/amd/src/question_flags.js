define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    var QuestionFlags = {
        init: function(cmid) {
            this.cmid = cmid;
            this.setupFlags();
            this.loadExistingFlags();
        },

        setupFlags: function() {
            var self = this;
            
            // Add CSS for flags
            if (!$('#questionflags-css').length) {
                $('<style id="questionflags-css">')
                    .text(`
                        .question-flag-container {
                            margin: 10px 0;
                            display: flex;
                            gap: 10px;
                        }
                        .flag-btn {
                            padding: 5px 10px;
                            border: none;
                            border-radius: 4px;
                            cursor: pointer;
                            font-size: 12px;
                            color: white;
                        }
                        .flag-btn.blue {
                            background-color: #3498db;
                        }
                        .flag-btn.red {
                            background-color: #e74c3c;
                        }
                        .flag-btn.active {
                            box-shadow: 0 0 5px rgba(0,0,0,0.5);
                        }
                        .question-flagged-blue .que {
                            border-left: 5px solid #3498db !important;
                            background-color: rgba(52, 152, 219, 0.1) !important;
                        }
                        .question-flagged-red .que {
                            border-left: 5px solid #e74c3c !important;
                            background-color: rgba(231, 76, 60, 0.1) !important;
                        }
                    `)
                    .appendTo('head');
            }

            // Add flag buttons to each question
            $('.que').each(function() {
                var $question = $(this);
                var questionId = self.getQuestionId($question);
                
                if (questionId && !$question.find('.question-flag-container').length) {
                    var $flagContainer = $('<div class="question-flag-container">');
                    var $blueFlag = $('<button class="flag-btn blue" data-color="blue">🏳️ Blue Flag</button>');
                    var $redFlag = $('<button class="flag-btn red" data-color="red">🚩 Red Flag</button>');
                    
                    $flagContainer.append($blueFlag, $redFlag);
                    $question.find('.content').prepend($flagContainer);
                    
                    // Bind click events
                    $blueFlag.on('click', function(e) {
                        e.preventDefault();
                        self.toggleFlag(questionId, 'blue', $question);
                    });
                    
                    $redFlag.on('click', function(e) {
                        e.preventDefault();
                        self.toggleFlag(questionId, 'red', $question);
                    });
                }
            });
        },

        getQuestionId: function($question) {
            // Try multiple methods to get question ID
            var questionId = $question.attr('id');
            if (questionId) {
                var match = questionId.match(/q(\d+)/);
                if (match) {
                    return parseInt(match[1]);
                }
            }
            
            // Try data attributes
            var dataQuestionId = $question.data('questionid');
            if (dataQuestionId) {
                return parseInt(dataQuestionId);
            }
            
            // Try input fields
            var $input = $question.find('input[name*="q"][name*=":"]').first();
            if ($input.length) {
                var name = $input.attr('name');
                var match = name.match(/q(\d+):/);
                if (match) {
                    return parseInt(match[1]);
                }
            }
            
            return null;
        },

        toggleFlag: function(questionId, color, $question) {
            var self = this;
            var $btn = $question.find('.flag-btn[data-color="' + color + '"]');
            var isCurrentlyFlagged = $btn.hasClass('active');
            var isFlagged = !isCurrentlyFlagged;

            Ajax.call([{
                methodname: 'local_questionflags_flag_question',
                args: {
                    questionid: questionId,
                    flagcolor: color,
                    isflagged: isFlagged,
                    cmid: this.cmid
                }
            }])[0].done(function(response) {
                if (response.success) {
                    self.updateFlagUI(questionId, color, isFlagged, $question);
                } else {
                    Notification.addNotification({
                        message: 'Failed to update flag',
                        type: 'error'
                    });
                }
            }).fail(function(ex) {
                Notification.exception(ex);
            });
        },

        updateFlagUI: function(questionId, color, isFlagged, $question) {
            var $btn = $question.find('.flag-btn[data-color="' + color + '"]');
            var $otherBtn = $question.find('.flag-btn[data-color="' + (color === 'blue' ? 'red' : 'blue') + '"]');
            
            // Remove all flag classes
            $question.removeClass('question-flagged-blue question-flagged-red');
            $question.find('.flag-btn').removeClass('active');
            
            if (isFlagged) {
                $btn.addClass('active');
                $question.addClass('question-flagged-' + color);
                // Remove other color flag if it exists
                $otherBtn.removeClass('active');
            }
        },

        loadExistingFlags: function() {
            var self = this;
            
            // Get existing flags via AJAX call
            Ajax.call([{
                methodname: 'local_questionflags_get_user_flags',
                args: {
                    cmid: this.cmid
                }
            }])[0].done(function(flags) {
                flags.forEach(function(flag) {
                    var $question = $('#q' + flag.questionid).closest('.que');
                    if ($question.length) {
                        self.updateFlagUI(flag.questionid, flag.flagcolor, true, $question);
                    }
                });
            }).fail(function() {
                // Silently fail if service doesn't exist yet
            });
        }
    };

    return QuestionFlags;
});