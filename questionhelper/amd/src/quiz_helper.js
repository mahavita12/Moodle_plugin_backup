// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * JavaScript module for the question helper plugin with interactive practice questions
 *
 * @package    local_questionhelper
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/modal_factory', 'core/modal_events', 'core/str'],
function($, Ajax, ModalFactory, ModalEvents, Str) {

    var ATTEMPT_KEY_PREFIX = 'questionhelper_q';
    var MAX_ATTEMPTS = 2;

    function init() {
        console.log('QuestionHelper: Initializing plugin...');
        console.log('QuestionHelper: Current URL:', window.location.pathname);

        if (isQuizAttemptPage()) {
            console.log('QuestionHelper: On quiz attempt page, loading plugin...');
            addCSSStyles();
            setTimeout(function() {
                scanAndAddHelpButtons();
            }, 1000);
        } else {
            console.log('QuestionHelper: Not on quiz attempt page, skipping...');
        }
    }

    function addCSSStyles() {
        if ($('#questionhelper-styles').length > 0) {
            return;
        }

        var css = '.question-helper-btn{margin-left:10px;font-size:13px;transition:all 0.3s ease;background:#fff3cd;color:#856404;border:1px solid #ffeaa7}.question-helper-btn:hover{transform:translateY(-1px);background:#ffecb5;border-color:#ffdd57}.question-helper-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none;background:#f8f9fa;color:#6c757d}.question-helper-container{margin:10px 0;text-align:right}.question-helper-popup{max-width:700px;padding:20px}.question-helper-popup h3{color:#495057;font-size:1.2em;margin-bottom:15px;border-bottom:2px solid #e9ecef;padding-bottom:5px}.practice-question-section{background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:20px;border-left:4px solid #17a2b8}.practice-question-text{margin-bottom:15px;font-weight:500;font-size:1.1em;line-height:1.4}.practice-options{list-style-type:none;padding-left:0;margin:0}.practice-option{background:#fff;margin:8px 0;padding:12px 15px;border-radius:6px;border:2px solid #dee2e6;cursor:pointer;transition:all 0.2s ease;position:relative}.practice-option:hover{border-color:#007bff;background:#f0f8ff}.practice-option.selected{border-color:#007bff;background:#e7f3ff;box-shadow:0 2px 4px rgba(0,123,255,0.1)}.practice-option.correct{border-color:#28a745;background:#d4edda;color:#155724}.practice-option.incorrect{border-color:#dc3545;background:#f8d7da;color:#721c24}.practice-option.correct::after{content:"V";position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#28a745;font-weight:bold;font-size:1.2em}.practice-option.incorrect::after{content:"X";position:absolute;right:15px;top:50%;transform:translateY(-50%);color:#dc3545;font-weight:bold;font-size:1.2em}.practice-option-letter{font-weight:bold;margin-right:10px;color:#6c757d}.submit-answer-btn{background:#007bff;color:white;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;margin:15px 0;transition:background 0.2s ease}.submit-answer-btn:hover{background:#0056b3}.submit-answer-btn:disabled{background:#6c757d;cursor:not-allowed}.answer-feedback{margin-top:20px;padding:15px;border-radius:8px;display:none}.answer-feedback.correct{background:#d4edda;border:1px solid #c3e6cb;color:#155724}.answer-feedback.incorrect{background:#f8d7da;border:1px solid #f5c6cb;color:#721c24}.answer-feedback h4{margin:0 0 10px 0;font-size:1.1em}.answer-feedback p{margin:0;line-height:1.4}.concept-explanation-section{background:#e7f3ff;padding:15px;border-radius:8px;border-left:4px solid #007bff;margin-top:20px}.concept-explanation-section p{margin-bottom:0;line-height:1.5;color:#495057}.popup-footer{border-top:1px solid #dee2e6;padding-top:15px;margin-top:20px;text-align:center}.try-again-btn{background:#28a745;color:white;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;margin-right:10px}.try-again-btn:hover{background:#218838}@media (max-width:768px){.question-helper-popup{padding:15px}.practice-question-section{padding:15px}.practice-option{padding:10px 12px}.question-helper-btn{font-size:12px;margin-left:5px}.question-helper-container{text-align:center}}';
        $('<style id="questionhelper-styles">' + css + '</style>').appendTo('head');
    }

    function isQuizAttemptPage() {
        return window.location.pathname.includes('/mod/quiz/attempt.php');
    }

    function scanAndAddHelpButtons() {
        console.log('QuestionHelper: Scanning for questions...');
        
        var questionSelectors = [
            '.que.multichoice',
            '.que[data-qtype="multichoice"]',
            '.question.multichoice',
            '.que:has(.answer input[type="radio"])',
            '.que:contains("Choose one")'
        ];

        var questionsFound = 0;

        questionSelectors.forEach(function(selector) {
            $(selector).each(function(index) {
                var questionElement = $(this);
                if (questionElement.find('.question-helper-btn').length > 0) {
                    return;
                }

                var questionId = extractQuestionId(questionElement);
                var attempts = getAttemptCount(questionId);
                console.log('QuestionHelper: Found question with ID:', questionId);
                addHelpButton(questionElement, questionId, attempts);
                questionsFound++;
            });
        });

        console.log('QuestionHelper: Total questions found:', questionsFound);

        if (questionsFound === 0) {
            console.log('QuestionHelper: No multichoice questions found, searching all questions...');
            $('.que').each(function(index) {
                var questionElement = $(this);
                if (questionElement.find('input[type="radio"]').length > 0) {
                    var questionId = extractQuestionId(questionElement);
                    var attempts = getAttemptCount(questionId);
                    console.log('QuestionHelper: Found radio question with ID:', questionId);
                    addHelpButton(questionElement, questionId, attempts);
                    questionsFound++;
                }
            });
        }

        console.log('QuestionHelper: Final count:', questionsFound);
    }

    function extractQuestionId(questionElement) {
        // First try to get the question slot ID from data attributes or input names
        var questionSlot = questionElement.find('input[name*="q"][name*=":"]').first().attr('name');
        if (questionSlot) {
            var match = questionSlot.match(/q(\d+):/);
            if (match) {
                return 'question_' + match[1];
            }
        }
        
        // Fallback: try to get from question classes
        var classes = questionElement.attr('class').split(/\s+/);
        for (var i = 0; i < classes.length; i++) {
            if (classes[i].startsWith('que-')) {
                return classes[i];
            }
        }
        
        // Last resort: use a unique identifier based on question text
        var questionText = questionElement.find('.qtext').text().trim();
        if (questionText) {
            return 'q_' + questionText.substring(0, 50).replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
        }
        
        return 'q' + questionElement.index();
    }

    function addHelpButton(questionElement, questionId, attempts) {
        console.log('QuestionHelper: Adding button to question:', questionId);

        // Find the question text area
        var qtext = questionElement.find('.qtext').first();
        if (qtext.length === 0) {
            console.log('QuestionHelper: Could not find question text for question:', questionId);
            return;
        }

        // Create a container div below the question text
        var containerDiv = $('<div class="question-helper-container" style="margin-top: 10px; text-align: left;"></div>');
        
        // Try to find the Previous page button in various locations
        var prevButton = null;
        var buttonSearchSelectors = [
            '.submitbtns .prevpage',
            '.mod_quiz-next-nav .prevpage', 
            '.im-controls .prevpage',
            '.prevpage'
        ];
        
        for (var i = 0; i < buttonSearchSelectors.length; i++) {
            var foundButton = $(buttonSearchSelectors[i]);
            if (foundButton.length > 0) {
                prevButton = foundButton.first();
                console.log('QuestionHelper: Found Previous page button with selector:', buttonSearchSelectors[i]);
                break;
            }
        }

        var helpButton = createHelpButton(questionId, attempts);
        
        if (prevButton && prevButton.length > 0) {
            // Clone the Previous page button to our container and add Help button next to it
            var prevClone = prevButton.clone(true);
            containerDiv.append(prevClone);
            containerDiv.append(helpButton);
            console.log('QuestionHelper: Added button next to Previous page button');
        } else {
            // No Previous page button found, just add Help button
            containerDiv.append(helpButton);
            console.log('QuestionHelper: Added button without Previous page button');
        }

        // Insert the container below the question text
        qtext.after(containerDiv);
        console.log('QuestionHelper: Button positioned below question text');
    }

    function createHelpButton(questionId, attempts) {
        var button = $('<button type="button" class="btn question-helper-btn"></button>');
        button.data('question-id', questionId);
        updateButtonState(button, attempts);
        button.on('click', function(e) {
            e.preventDefault();
            handleHelpClick($(this));
        });
        return button;
    }

    function updateButtonState(button, attempts) {
        if (attempts >= MAX_ATTEMPTS) {
            button.text('Help exhausted')
                  .addClass('btn-secondary')
                  .removeClass('btn question-helper-btn')
                  .prop('disabled', true);
        } else {
            button.text('Get Help')
                  .addClass('btn question-helper-btn')
                  .removeClass('btn-secondary')
                  .prop('disabled', false);
        }
    }

    function handleHelpClick(button) {
        var questionId = button.data('question-id');
        var attempts = getAttemptCount(questionId);

        if (attempts >= MAX_ATTEMPTS) {
            return;
        }

        var questionElement = button.closest('.que');
        var questionData = extractQuestionData(questionElement);

        if (!questionData.text || !questionData.options) {
            showError('Question data could not be extracted');
            return;
        }

        attempts = incrementAttempt(questionId);
        updateButtonState(button, attempts);
        button.html('Getting help...').prop('disabled', true);

        makeHelpRequest(questionData)
            .then(function(response) {
                showInteractiveHelpModal(response);
            })
            .catch(function(error) {
                showError('Unable to get help. Please try again.');
                console.error('Help request failed:', error);
            })
            .always(function() {
                updateButtonState(button, attempts);
            });
    }

    function extractQuestionData(questionElement) {
        var questionText = questionElement.find('.qtext').text().trim();
        var options = [];

        questionElement.find('.answer .r0, .answer .r1').each(function() {
            var optionText = $(this).text().trim();
            if (optionText) {
                options.push(optionText);
            }
        });

        return {
            text: questionText,
            options: options.join('\n'),
            id: extractQuestionId(questionElement)
        };
    }

    function makeHelpRequest(questionData) {
        var urlParams = new URLSearchParams(window.location.search);
        var attemptId = urlParams.get('attempt');

        return $.ajax({
            url: M.cfg.wwwroot + '/local/questionhelper/get_help.php',
            method: 'POST',
            data: {
                questiontext: questionData.text,
                options: questionData.options,
                attemptid: attemptId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json',
            timeout: 15000
        });
    }

    function showInteractiveHelpModal(response) {
        if (!response.success) {
            showError(response.error || 'Unknown error occurred');
            return;
        }

        var modalContent = buildInteractiveModalContent(response);

        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: 'Interactive Practice Question',
            body: modalContent,
            large: true
        }).then(function(modal) {
            modal.show();
            var modalRoot = modal.getRoot();

            modalRoot.on('click', '.practice-option', function() {
                var $this = $(this);
                var $options = modalRoot.find('.practice-option');
                $options.removeClass('selected');
                $this.addClass('selected');
                modalRoot.find('.submit-answer-btn').prop('disabled', false);
            });

            modalRoot.on('click', '.submit-answer-btn', function() {
                var selectedOption = modalRoot.find('.practice-option.selected');
                if (selectedOption.length === 0) {
                    return;
                }
                var selectedAnswer = selectedOption.data('answer');
                var correctAnswer = $(this).data('correct-answer');
                showAnswerFeedback(modalRoot, selectedAnswer, correctAnswer, response);
            });

            modalRoot.on('click', '.try-again-btn', function() {
                resetPracticeQuestion(modalRoot);
            });

            modalRoot.on(ModalEvents.hidden, function() {
                modal.destroy();
            });

            modalRoot.find('.question-helper-close').on('click', function() {
                modal.hide();
            });
        });
    }

    function buildInteractiveModalContent(response) {
        var html = '<div class="question-helper-popup">';
        html += '<h3>Practice Question</h3>';
        html += '<div class="practice-question-section">';
        html += '<div class="practice-question-text">' + $('<div>').text(response.practice_question).html() + '</div>';

        if (response.options && typeof response.options === 'object') {
            html += '<ul class="practice-options">';
            Object.keys(response.options).forEach(function(letter) {
                html += '<li class="practice-option" data-answer="' + letter + '">';
                html += '<span class="practice-option-letter">' + letter + '.</span>';
                html += $('<div>').text(response.options[letter]).html();
                html += '</li>';
            });
            html += '</ul>';
            html += '<button type="button" class="submit-answer-btn" data-correct-answer="' + 
                    response.correct_answer + '" disabled>Submit Answer</button>';
        }

        html += '<div class="answer-feedback"></div>';
        html += '</div>';

        if (response.concept_explanation) {
            html += '<div class="concept-explanation-section" style="display: none;">';
            html += '<h3>Key Concept</h3>';
            html += '<p>' + $('<div>').text(response.concept_explanation).html() + '</p>';
            html += '</div>';
        }

        html += '<div class="popup-footer">';
        html += '<button type="button" class="btn btn-primary question-helper-close">Close</button>';
        html += '</div>';
        html += '</div>';

        return html;
    }

    function showAnswerFeedback(modalRoot, selectedAnswer, correctAnswer, response) {
        var isCorrect = selectedAnswer === correctAnswer;
        var feedbackDiv = modalRoot.find('.answer-feedback');
        var conceptSection = modalRoot.find('.concept-explanation-section');

        modalRoot.find('.practice-option').each(function() {
            var $option = $(this);
            var optionAnswer = $option.data('answer');
            
            if (optionAnswer === correctAnswer) {
                $option.addClass('correct');
            } else if (optionAnswer === selectedAnswer && !isCorrect) {
                $option.addClass('incorrect');
            }
            $option.css('pointer-events', 'none');
        });

        var feedbackClass = isCorrect ? 'correct' : 'incorrect';
        var feedbackTitle = isCorrect ? 'Correct!' : 'Incorrect';
        var feedbackText = response.explanation || 'No explanation provided.';

        feedbackDiv.removeClass('correct incorrect')
                   .addClass(feedbackClass)
                   .html('<h4>' + feedbackTitle + '</h4><p>' + $('<div>').text(feedbackText).html() + '</p>')
                   .show();

        conceptSection.show();
        modalRoot.find('.submit-answer-btn').prop('disabled', true).hide();
        
        if (!isCorrect) {
            feedbackDiv.append('<br><button type="button" class="try-again-btn">Try Again</button>');
        }
    }

    function resetPracticeQuestion(modalRoot) {
        modalRoot.find('.practice-option')
                 .removeClass('selected correct incorrect')
                 .css('pointer-events', 'auto');
        modalRoot.find('.answer-feedback').hide();
        modalRoot.find('.concept-explanation-section').hide();
        modalRoot.find('.submit-answer-btn').show().prop('disabled', true);
    }

    function showError(message) {
        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: 'Error',
            body: '<div class="alert alert-danger">' + message + '</div>'
        }).then(function(modal) {
            modal.show();
            setTimeout(function() {
                modal.hide();
            }, 3000);
        });
    }

    function getAttemptCount(questionId) {
        var key = ATTEMPT_KEY_PREFIX + questionId;
        var data = sessionStorage.getItem(key);
        if (data) {
            try {
                return JSON.parse(data).attempts || 0;
            } catch (e) {
                return 0;
            }
        }
        return 0;
    }

    function incrementAttempt(questionId) {
        var key = ATTEMPT_KEY_PREFIX + questionId;
        var attempts = getAttemptCount(questionId) + 1;
        sessionStorage.setItem(key, JSON.stringify({
            attempts: attempts,
            timestamp: new Date().toISOString()
        }));
        return attempts;
    }

    return {
        init: init
    };
});