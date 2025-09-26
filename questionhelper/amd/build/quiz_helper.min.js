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
    var MAX_ATTEMPTS = 3;

    /**
     * Initialize the plugin on quiz attempt pages
     */
    function init() {
        console.log('QuestionHelper: Initializing plugin...');
        console.log('QuestionHelper: Current URL:', window.location.pathname);

        if (isQuizAttemptPage()) {
            console.log('QuestionHelper: On quiz attempt page, loading plugin...');
            addCSSStyles();

            // Add a small delay to ensure page is fully loaded
            setTimeout(function() {
                scanAndAddHelpButtons();
            }, 1000);
        } else {
            console.log('QuestionHelper: Not on quiz attempt page, skipping...');
        }
    }

    /**
     * Add CSS styles to the page
     */
    function addCSSStyles() {
        if ($('#questionhelper-styles').length > 0) {
            return; // Already added
        }

        var css = `
        .question-helper-btn {
            margin-left: 10px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        .question-helper-btn:hover {
            transform: translateY(-1px);
        }
        .question-helper-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .question-helper-container {
            margin: 10px 0;
            text-align: right;
        }
        .question-helper-popup {
            max-width: 700px;
            padding: 20px;
        }
        .question-helper-popup h3 {
            color: #495057;
            font-size: 1.2em;
            margin-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 5px;
        }
        .practice-question-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #17a2b8;
        }
        .practice-question-text {
            margin-bottom: 15px;
            font-weight: 500;
            font-size: 1.1em;
            line-height: 1.4;
        }
        .practice-options {
            list-style-type: none;
            padding-left: 0;
            margin: 0;
        }
        .practice-option {
            background: #fff;
            margin: 8px 0;
            padding: 12px 15px;
            border-radius: 6px;
            border: 2px solid #dee2e6;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .practice-option:hover {
            border-color: #007bff;
            background: #f0f8ff;
        }
        .practice-option.selected {
            border-color: #007bff;
            background: #e7f3ff;
            box-shadow: 0 2px 4px rgba(0,123,255,0.1);
        }
        .practice-option.correct {
            border-color: #28a745;
            background: #d4edda;
            color: #155724;
        }
        .practice-option.incorrect {
            border-color: #dc3545;
            background: #f8d7da;
            color: #721c24;
        }
        .practice-option.correct::after {
            content: "âœ“";
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #28a745;
            font-weight: bold;
            font-size: 1.2em;
        }
        .practice-option.incorrect::after {
            content: "âœ—";
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #dc3545;
            font-weight: bold;
            font-size: 1.2em;
        }
        .practice-option-letter {
            font-weight: bold;
            margin-right: 10px;
            color: #6c757d;
        }
        .submit-answer-btn {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 15px 0;
            transition: background 0.2s ease;
        }
        .submit-answer-btn:hover {
            background: #0056b3;
        }
        .submit-answer-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
        .answer-feedback {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        .answer-feedback.correct {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .answer-feedback.incorrect {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .answer-feedback h4 {
            margin: 0 0 10px 0;
            font-size: 1.1em;
        }
        .answer-feedback p {
            margin: 0;
            line-height: 1.4;
        }
        .concept-explanation-section {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            margin-top: 20px;
        }
        .concept-explanation-section p {
            margin-bottom: 0;
            line-height: 1.5;
            color: #495057;
        }
        .popup-footer {
            border-top: 1px solid #dee2e6;
            padding-top: 15px;
            margin-top: 20px;
            text-align: center;
        }
        .try-again-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
        }
        .try-again-btn:hover {
            background: #218838;
        }
        @media (max-width: 768px) {
            .question-helper-popup {
                padding: 15px;
            }
            .practice-question-section {
                padding: 15px;
            }
            .practice-option {
                padding: 10px 12px;
            }
            .question-helper-btn {
                font-size: 12px;
                margin-left: 5px;
            }
            .question-helper-container {
                text-align: center;
            }
        }
        `;

        $('<style id="questionhelper-styles">' + css + '</style>').appendTo('head');
    }

    /**
     * Check if current page is a quiz attempt page
     * @return {boolean}
     */
    function isQuizAttemptPage() {
        return window.location.pathname.includes('/mod/quiz/attempt.php');
    }

    /**
     * Scan for multiple choice questions and add help buttons
     */
    function scanAndAddHelpButtons() {
        console.log('QuestionHelper: Scanning for questions...');

        // Look for multiple choice questions with various selectors
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
                // Skip if already processed
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

        // If no questions found, try a broader search
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

    /**
     * Extract question ID from question element
     * @param {jQuery} questionElement
     * @return {string}
     */
    function extractQuestionId(questionElement) {
        var classes = questionElement.attr('class').split(/\s+/);
        for (var i = 0; i < classes.length; i++) {
            if (classes[i].startsWith('que-')) {
                return classes[i];
            }
        }
        return 'q' + questionElement.index();
    }

    /**
     * Add help button to a question
     * @param {jQuery} questionElement
     * @param {string} questionId
     * @param {number} attempts
     */
    function addHelpButton(questionElement, questionId, attempts) {
        console.log('QuestionHelper: Adding button to question:', questionId);

        // Find the best location for the button - try multiple locations
        var targetContainer = null;
        var locations = [
            '.submitbtns',
            '.mod_quiz-next-nav',
            '.im-controls',
            '.questionflag',
            '.answer',
            '.ablock',
            '.content'
        ];

        for (var i = 0; i < locations.length; i++) {
            targetContainer = questionElement.find(locations[i]).first();
            if (targetContainer.length > 0) {
                console.log('QuestionHelper: Found target container:', locations[i]);
                break;
            }
        }

        // If still no container, create one after question text
        if (targetContainer.length === 0) {
            var qtext = questionElement.find('.qtext').first();
            if (qtext.length > 0) {
                qtext.after('<div class="question-helper-container"></div>');
                targetContainer = questionElement.find('.question-helper-container');
                console.log('QuestionHelper: Created custom container');
            }
        }

        // Last resort - add to the question element itself
        if (targetContainer.length === 0) {
            questionElement.prepend('<div class="question-helper-container"></div>');
            targetContainer = questionElement.find('.question-helper-container');
            console.log('QuestionHelper: Added container to question element');
        }

        if (targetContainer.length === 0) {
            console.log('QuestionHelper: Could not find target container for question:', questionId);
            return; // Can't find suitable location
        }

        var helpButton = createHelpButton(questionId, attempts);

        // Insert button in the right position
        var prevButton = targetContainer.find('.prevpage');
        if (prevButton.length > 0) {
            prevButton.after(helpButton);
        } else {
            targetContainer.prepend(helpButton);
        }

        console.log('QuestionHelper: Button added successfully');
    }

    /**
     * Create help button element
     * @param {string} questionId
     * @param {number} attempts
     * @return {jQuery}
     */
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

    /**
     * Update button state based on attempts
     * @param {jQuery} button
     * @param {number} attempts
     */
    function updateButtonState(button, attempts) {
        if (attempts >= MAX_ATTEMPTS) {
            button.text('Help exhausted')
                  .addClass('btn-secondary')
                  .removeClass('btn-info')
                  .prop('disabled', true);
        } else {
            button.html('ðŸ¤” Get Help')
                  .addClass('btn-info')
                  .removeClass('btn-secondary')
                  .prop('disabled', false);
        }
    }

    /**
     * Handle help button click
     * @param {jQuery} button
     */
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

        // Update attempt count
        attempts = incrementAttempt(questionId);
        updateButtonState(button, attempts);

        // Show loading state
        button.html('Getting help...').prop('disabled', true);

        // Make AJAX request
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

    /**
     * Extract question data from question element
     * @param {jQuery} questionElement
     * @return {object}
     */
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

    /**
     * Make AJAX request for help
     * @param {object} questionData
     * @return {Promise}
     */
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

    /**
     * Show interactive help modal with practice question
     * @param {object} response
     */
    function showInteractiveHelpModal(response) {
        if (!response.success) {
            showError(response.error || 'Unknown error occurred');
            return;
        }

        var modalContent = buildInteractiveModalContent(response);

        ModalFactory.create({
            type: ModalFactory.types.DEFAULT,
            title: 'ðŸ¤” Interactive Practice Question',
            body: modalContent,
            large: true
        }).then(function(modal) {
            modal.show();

            var modalRoot = modal.getRoot();

            // Handle option selection
            modalRoot.on('click', '.practice-option', function() {
                var $this = $(this);
                var $options = modalRoot.find('.practice-option');
                
                // Remove previous selections
                $options.removeClass('selected');
                
                // Add selection to clicked option
                $this.addClass('selected');
                
                // Enable submit button
                modalRoot.find('.submit-answer-btn').prop('disabled', false);
            });

            // Handle answer submission
            modalRoot.on('click', '.submit-answer-btn', function() {
                var selectedOption = modalRoot.find('.practice-option.selected');
                if (selectedOption.length === 0) {
                    return;
                }

                var selectedAnswer = selectedOption.data('answer');
                var correctAnswer = $(this).data('correct-answer');
                
                showAnswerFeedback(modalRoot, selectedAnswer, correctAnswer, response);
            });

            // Handle try again
            modalRoot.on('click', '.try-again-btn', function() {
                resetPracticeQuestion(modalRoot);
            });

            // Handle modal close
            modalRoot.on(ModalEvents.hidden, function() {
                modal.destroy();
            });

            modalRoot.find('.question-helper-close').on('click', function() {
                modal.hide();
            });
        });
    }

    /**
     * Build interactive modal content HTML
     * @param {object} response
     * @return {string}
     */
    function buildInteractiveModalContent(response) {
        var html = '<div class="question-helper-popup">';

        html += '<h3>ðŸŽ¯ Practice Question</h3>';
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
            html += '<h3>ðŸ’¡ Key Concept</h3>';
            html += '<p>' + $('<div>').text(response.concept_explanation).html() + '</p>';
            html += '</div>';
        }

        html += '<div class="popup-footer">';
        html += '<button type="button" class="btn btn-primary question-helper-close">Close</button>';
        html += '</div>';

        html += '</div>';

        return html;
    }

    /**
     * Show answer feedback and explanation
     * @param {jQuery} modalRoot
     * @param {string} selectedAnswer
     * @param {string} correctAnswer
     * @param {object} response
     */
    function showAnswerFeedback(modalRoot, selectedAnswer, correctAnswer, response) {
        var isCorrect = selectedAnswer === correctAnswer;
        var feedbackDiv = modalRoot.find('.answer-feedback');
        var conceptSection = modalRoot.find('.concept-explanation-section');

        // Update option styles
        modalRoot.find('.practice-option').each(function() {
            var $option = $(this);
            var optionAnswer = $option.data('answer');
            
            if (optionAnswer === correctAnswer) {
                $option.addClass('correct');
            } else if (optionAnswer === selectedAnswer && !isCorrect) {
                $option.addClass('incorrect');
            }
            
            // Disable further clicking
            $option.css('pointer-events', 'none');
        });

        // Show feedback
        var feedbackClass = isCorrect ? 'correct' : 'incorrect';
        var feedbackTitle = isCorrect ? 'Correct!' : 'Incorrect';
        var feedbackText = response.explanation || 'No explanation provided.';

        feedbackDiv.removeClass('correct incorrect')
                   .addClass(feedbackClass)
                   .html('<h4>' + feedbackTitle + '</h4><p>' + $('<div>').text(feedbackText).html() + '</p>')
                   .show();

        // Show concept explanation
        conceptSection.show();

        // Disable submit button and show try again option
        modalRoot.find('.submit-answer-btn').prop('disabled', true).hide();
        
        if (!isCorrect) {
            feedbackDiv.append('<br><button type="button" class="try-again-btn">Try Again</button>');
        }
    }

    /**
     * Reset practice question for retry
     * @param {jQuery} modalRoot
     */
    function resetPracticeQuestion(modalRoot) {
        // Reset option styles
        modalRoot.find('.practice-option')
                 .removeClass('selected correct incorrect')
                 .css('pointer-events', 'auto');

        // Hide feedback and concept
        modalRoot.find('.answer-feedback').hide();
        modalRoot.find('.concept-explanation-section').hide();

        // Show and disable submit button
        modalRoot.find('.submit-answer-btn').show().prop('disabled', true);
    }

    /**
     * Show error message
     * @param {string} message
     */
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

    /**
     * Get attempt count for a question
     * @param {string} questionId
     * @return {number}
     */
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

    /**
     * Increment attempt count for a question
     * @param {string} questionId
     * @return {number}
     */
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