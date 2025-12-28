/**
 * Flag box module for local_questionflags.
 *
 * Handles injecting the reason textarea and saving reasons via AJAX.
 *
 * @module local_questionflags/flagbox
 * @copyright 2024 Question Flags Development Team
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function ($) {

    /**
     * Submit a reason via AJAX without page reload.
     * @param {number} questionId - The question ID.
     */
    function submitReason(questionId) {
        var reasonBox = document.getElementById('flag-reason-' + questionId);
        if (!reasonBox) {
            return;
        }

        var formData = new FormData();
        formData.append('save_reason', '1');
        formData.append('questionid', questionId);
        formData.append('reason', reasonBox.value);
        formData.append('sesskey', window.qfSesskey);

        var status = document.getElementById('reason-status-' + questionId);
        if (status) {
            status.textContent = 'Saving...';
        }

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function () {
                if (status) {
                    status.textContent = 'Saved';
                    setTimeout(function () {
                        status.textContent = '';
                    }, 2000);
                }
            })
            .catch(function () {
                if (status) {
                    status.textContent = 'Error';
                }
            });
    }

    /**
     * Initialize the flag box functionality.
     * Injects textareas into flagged questions.
     */
    function init() {
        // Expose submitReason globally for onblur handler
        window.submitReason = submitReason;

        var questions = document.querySelectorAll('.que');
        questions.forEach(function (question) {
            // Find the content area to inject below question text
            var contentArea = question.querySelector('.content');
            if (!contentArea) {
                return;
            }

            // Get question ID from the question element
            var questionId = null;
            var idStr = question.id || '';
            if (idStr.indexOf('question-') !== -1) {
                var parts = idStr.split('-');
                var slotNumber = parts[parts.length - 1];
                if (window.questionMapping && window.questionMapping[slotNumber]) {
                    questionId = window.questionMapping[slotNumber];
                }
            }

            if (!questionId) {
                return;
            }

            // Check if textarea already exists
            if (document.getElementById('reason-wrapper-' + questionId)) {
                return;
            }

            // Get current flag state and reason
            var currentFlag = (window.questionFlagsData && window.questionFlagsData[questionId]) || '';
            var reasonText = (window.questionFlagReasons && window.questionFlagReasons[questionId]) || '';
            var isVisible = (currentFlag === 'blue' || currentFlag === 'red');

            // Create wrapper for the notes section
            var wrapperDiv = document.createElement('div');
            wrapperDiv.id = 'reason-wrapper-' + questionId;
            wrapperDiv.className = 'flag-notes-area';
            wrapperDiv.style.cssText = 'margin-top: 15px; margin-bottom: 15px; padding: 10px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; display: ' + (isVisible ? 'block' : 'none') + ';';

            // Add Label "My Notes"
            var label = document.createElement('label');
            label.htmlFor = 'flag-reason-' + questionId;
            label.textContent = 'My Notes';
            label.style.cssText = 'font-weight: bold; display: block; margin-bottom: 5px; color: #333;';
            wrapperDiv.appendChild(label);

            // Create textarea
            var textarea = document.createElement('textarea');
            textarea.id = 'flag-reason-' + questionId;
            textarea.className = 'form-control';
            textarea.placeholder = 'Add why you flagged this question or why your answer was incorrect (misreading the question, not understanding the key concept, calculation mistake etc.)';
            textarea.style.cssText = 'width:100%; height:80px; font-size:14px; resize:vertical; margin-bottom: 5px;';
            textarea.value = reasonText;
            textarea.onblur = function () {
                submitReason(questionId);
            };
            wrapperDiv.appendChild(textarea);

            // Status indicator
            var statusDiv = document.createElement('div');
            statusDiv.id = 'reason-status-' + questionId;
            statusDiv.style.cssText = 'font-size:11px; color:#666; text-align:right; min-height:16px;';
            wrapperDiv.appendChild(statusDiv);

            // Inject after .qtext if it exists
            var qtext = contentArea.querySelector('.qtext');
            if (qtext && qtext.parentNode === contentArea) {
                if (qtext.nextSibling) {
                    contentArea.insertBefore(wrapperDiv, qtext.nextSibling);
                } else {
                    contentArea.appendChild(wrapperDiv); // Last child, so append is effectively after
                }
            } else {
                // Fallback: append to content area to ensure it's at the end if .qtext is missing/nested
                contentArea.appendChild(wrapperDiv);
            }
        });

        // Hook into the global submitFlag function to toggle visibility
        // We need to wrap the original function or use an event if possible.
        // Since submitFlag is defined in global scope by PHP, we can wrap it.
        if (typeof window.originalSubmitFlag === 'undefined' && typeof window.submitFlag === 'function') {
            window.originalSubmitFlag = window.submitFlag;
            window.submitFlag = function (questionId, flagColor, currentCmd) {
                // Call original logic
                window.originalSubmitFlag(questionId, flagColor, currentCmd);

                // Update visibility based on action
                // If currentCmd is 'flag', we are flagging -> show
                // If currentCmd is 'unflag', we are unflagging -> hide
                // Wait small delay for DOM to update or just use logic

                // Logic based on toggle behavior:
                // If we clicked Blue and it was NOT Blue, we are flagging Blue.
                // If we clicked Blue and it WAS Blue, we are unflagging.

                var wrapper = document.getElementById('reason-wrapper-' + questionId);
                if (wrapper) {
                    // This logic is tricky because we don't know the exact outcome state synchronously 
                    // without duplicating the toggle logic.
                    // However, the original submitFlag updates the UI classes.
                    // Let's polling check the UI state or rely on the fact that if we click, we toggle.

                    // Simple heuristic: check immediately after execution (sync) or small timeout
                    setTimeout(function () {
                        var btn = document.querySelector('#question-' + questionId + ' .flag-btn.active'); // Assuming active class added? 
                        // Actually the PHP inline script reloads or updates UI. 
                        // The inline script provided earlier updates classes.
                        // Let's assume we want to show if ANY flag button is active/disabled?
                        // Actually the previous script reloaded the page often unless AJAX.
                        // With AJAX, we need to know the new state.

                        // Let's look at the implementation of submitFlag in the PHP file.
                        // It does: ... fetch ... then update UI.

                        // Better approach: Observe DOM changes or listen to custom event if we emitted one.
                        // We did NOT emit a custom JS event in the PHP script.

                        // Quick fix: The PHP script updates window.questionFlagsData[questionId].
                        // We can check that.
                        var newState = window.questionFlagsData && window.questionFlagsData[questionId];
                        if (newState === 'blue' || newState === 'red') {
                            wrapper.style.display = 'block';
                        } else {
                            wrapper.style.display = 'none';
                        }
                    }, 500); // 500ms delay to allow AJAX to return and update global state
                }
            };
        }
    }

    return {
        init: init
    };
});
