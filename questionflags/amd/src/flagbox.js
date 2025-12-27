/**
 * Flag box module for local_questionflags.
 *
 * Handles injecting the reason textarea and saving reasons via AJAX.
 *
 * @module local_questionflags/flagbox
 * @copyright 2024 Question Flags Development Team
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

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
        .then(function(response) {
            return response.json();
        })
        .then(function() {
            if (status) {
                status.textContent = 'Saved';
                setTimeout(function() {
                    status.textContent = '';
                }, 2000);
            }
        })
        .catch(function() {
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
        questions.forEach(function(question) {
            var flagContainer = question.querySelector('.question-flag-container');
            if (!flagContainer) {
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
            if (document.getElementById('flag-reason-' + questionId)) {
                return;
            }

            // Get current flag state and reason
            var currentFlag = (window.questionFlagsData && window.questionFlagsData[questionId]) || '';
            var reasonText = (window.questionFlagReasons && window.questionFlagReasons[questionId]) || '';
            var displayStyle = (currentFlag === 'blue' || currentFlag === 'red') ? 'block' : 'none';

            // Create textarea container
            var reasonDiv = document.createElement('div');
            reasonDiv.id = 'reason-container-' + questionId;
            reasonDiv.style.cssText = 'display:' + displayStyle + '; width:100%; margin-top:5px;';

            var textarea = document.createElement('textarea');
            textarea.id = 'flag-reason-' + questionId;
            textarea.className = 'form-control';
            textarea.placeholder = 'Why did you get this wrong?';
            textarea.style.cssText = 'width:100%; height:60px; font-size:12px; resize:vertical;';
            textarea.value = reasonText;
            textarea.onblur = function() {
                submitReason(questionId);
            };

            var statusDiv = document.createElement('div');
            statusDiv.id = 'reason-status-' + questionId;
            statusDiv.style.cssText = 'font-size:10px; color:#888; text-align:right; height:15px;';

            reasonDiv.appendChild(textarea);
            reasonDiv.appendChild(statusDiv);
            flagContainer.appendChild(reasonDiv);
        });
    }

    return {
        init: init
    };
});
