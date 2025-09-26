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
 * Copy/paste blocker module for Essays Master.
 *
 * @module     local_essaysmaster/copy_paste_blocker
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification', 'core/str'], function($, Notification, Str) {

    /**
     * Copy Paste Blocker class
     */
    class CopyPasteBlocker {
        constructor(textareaId, feedbackPanelId, config = {}) {
            this.textareaId = textareaId;
            this.feedbackPanelId = feedbackPanelId;
            this.textarea = $('#' + textareaId);
            this.feedbackPanel = $('#' + feedbackPanelId);

            this.config = {
                enabled: true,
                allowKeyboardShortcuts: false,
                rapidTypingThreshold: 50, // characters
                rapidTypingTimeWindow: 100, // milliseconds
                maxPasteWarnings: 3,
                lockAfterMaxWarnings: false,
                ...config
            };

            this.pasteWarningCount = 0;
            this.lastInputTime = 0;
            this.lastTextLength = 0;
            this.typingPattern = [];
            this.isLocked = false;

            if (this.config.enabled) {
                this.initialize();
            }
        }

        /**
         * Initialize the copy/paste blocker
         */
        initialize() {
            if (this.textarea.length === 0) {
                console.error('CopyPasteBlocker: Textarea not found:', this.textareaId);
                return;
            }

            this.setupEventListeners();
            this.setupUI();
            console.log('CopyPasteBlocker: Initialized for', this.textareaId);
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Block paste events
            this.textarea.on('paste', (e) => {
                if (this.isLocked) {
                    e.preventDefault();
                    this.showLockedWarning();
                    return;
                }

                e.preventDefault();
                this.handlePasteAttempt(e);
            });

            // Block drag and drop from feedback panel
            if (this.feedbackPanel.length > 0) {
                this.feedbackPanel.on('dragstart', (e) => {
                    e.preventDefault();
                });

                // Block text selection in feedback panel
                this.feedbackPanel.on('selectstart', (e) => {
                    e.preventDefault();
                });

                // Disable context menu on feedback panel
                this.feedbackPanel.on('contextmenu', (e) => {
                    e.preventDefault();
                });

                // Prevent copy/cut actions from the feedback panel
                this.feedbackPanel.on('copy cut', (e) => {
                    e.preventDefault();
                });
            }

            // Monitor typing patterns
            this.textarea.on('input', (e) => {
                this.analyzeTypingPattern(e);
            });

            // Block keyboard shortcuts if configured
            if (!this.config.allowKeyboardShortcuts) {
                this.textarea.on('keydown', (e) => {
                    this.handleKeyboardShortcuts(e);
                });
            }

            // Block right-click context menu
            this.textarea.on('contextmenu', (e) => {
                e.preventDefault();
                this.showContextMenuWarning();
            });

            // Block dragging text into textarea
            this.textarea.on('drop', (e) => {
                e.preventDefault();
                this.showDropWarning();
            });

            this.textarea.on('dragover', (e) => {
                e.preventDefault();
            });
        }

        /**
         * Setup UI elements
         */
        setupUI() {
            // Add visual indicator
            this.addVisualIndicator();

            // Add warning modal styles
            this.addWarningStyles();

            // Add no-selection CSS for feedback panel to harden against copying
            if ($('#copy-paste-blocker-noselect-styles').length === 0) {
                const noselect = `
                    <style id="copy-paste-blocker-noselect-styles">
                    #${this.feedbackPanelId},
                    #${this.feedbackPanelId} .card,
                    #${this.feedbackPanelId} .feedback-content,
                    #${this.feedbackPanelId} * {
                        -webkit-user-select: none !important;
                        -ms-user-select: none !important;
                        user-select: none !important;
                    }
                    </style>
                `;
                $('head').append(noselect);
            }
        }

        /**
         * Add visual indicator that copy/paste prevention is active
         */
        addVisualIndicator() {
            const indicator = $(`
                <div class="copy-paste-blocker-indicator">
                    <i class="fa fa-shield"></i>
                    <span>Copy/Paste Protection Active</span>
                </div>
            `);

            this.textarea.before(indicator);
        }

        /**
         * Add CSS styles for warnings
         */
        addWarningStyles() {
            if ($('#copy-paste-blocker-styles').length > 0) {
                return;
            }

            const styles = `
                <style id="copy-paste-blocker-styles">
                .copy-paste-blocker-indicator {
                    display: inline-flex;
                    align-items: center;
                    background-color: #e3f2fd;
                    color: #1976d2;
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    margin-bottom: 5px;
                    border: 1px solid #bbdefb;
                }

                .copy-paste-blocker-indicator i {
                    margin-right: 5px;
                }

                .copy-paste-warning {
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    background: white;
                    border: 2px solid #f44336;
                    border-radius: 8px;
                    padding: 20px;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                    z-index: 9999;
                    max-width: 400px;
                    text-align: center;
                }

                .copy-paste-warning h4 {
                    color: #f44336;
                    margin-top: 0;
                }

                .copy-paste-warning .btn {
                    margin-top: 15px;
                }

                .typing-suspicious {
                    border-color: #ff9800 !important;
                    box-shadow: 0 0 5px rgba(255, 152, 0, 0.5) !important;
                }

                .textarea-locked {
                    border-color: #f44336 !important;
                    box-shadow: 0 0 5px rgba(244, 67, 54, 0.5) !important;
                    background-color: #ffebee !important;
                }
                </style>
            `;

            $('head').append(styles);
        }

        /**
         * Handle paste attempt
         */
        handlePasteAttempt(event) {
            this.pasteWarningCount++;

            // Get paste data for analysis
            const pasteData = this.getPasteData(event);

            // Log the attempt
            this.logPasteAttempt(pasteData);

            // Show warning
            this.showPasteWarning();

            // Check if should lock after max warnings
            if (this.config.lockAfterMaxWarnings &&
                this.pasteWarningCount >= this.config.maxPasteWarnings) {
                this.lockTextarea();
            }
        }

        /**
         * Get paste data from clipboard
         */
        getPasteData(event) {
            let pasteData = {
                text: '',
                length: 0,
                source: 'unknown'
            };

            try {
                const clipboardData = event.originalEvent.clipboardData || window.clipboardData;
                if (clipboardData) {
                    pasteData.text = clipboardData.getData('text/plain') || '';
                    pasteData.length = pasteData.text.length;

                    // Try to determine source
                    if (clipboardData.types) {
                        pasteData.types = Array.from(clipboardData.types);

                        if (pasteData.types.includes('text/html')) {
                            pasteData.source = 'rich_text';
                        } else if (pasteData.types.includes('text/plain')) {
                            pasteData.source = 'plain_text';
                        }
                    }
                }
            } catch (error) {
                console.error('Error accessing clipboard data:', error);
            }

            return pasteData;
        }

        /**
         * Analyze typing pattern for suspicious activity
         */
        analyzeTypingPattern(event) {
            const currentTime = Date.now();
            const textLength = this.textarea.val().length;

            if (this.lastInputTime && this.lastTextLength !== undefined) {
                const timeDiff = currentTime - this.lastInputTime;
                const lengthDiff = textLength - this.lastTextLength;

                // Record typing pattern
                this.typingPattern.push({
                    time: currentTime,
                    timeDiff: timeDiff,
                    lengthDiff: lengthDiff,
                    length: textLength
                });

                // Keep only recent patterns (last 10 inputs)
                if (this.typingPattern.length > 10) {
                    this.typingPattern.shift();
                }

                // Check for suspicious rapid insertion
                if (timeDiff < this.config.rapidTypingTimeWindow &&
                    lengthDiff > this.config.rapidTypingThreshold) {
                    this.flagSuspiciousActivity(timeDiff, lengthDiff);
                }
            }

            this.lastInputTime = currentTime;
            this.lastTextLength = textLength;
        }

        /**
         * Handle keyboard shortcuts
         */
        handleKeyboardShortcuts(event) {
            const key = event.key.toLowerCase();
            const ctrl = event.ctrlKey || event.metaKey;

            // Block common copy/paste shortcuts
            if (ctrl) {
                switch (key) {
                    case 'v': // Paste
                        event.preventDefault();
                        this.handlePasteAttempt(event);
                        break;
                    case 'c': // Copy (from textarea)
                    case 'x': // Cut
                        // Allow copying from own textarea, but warn
                        this.showCopyWarning();
                        break;
                    case 'a': // Select all - allow but monitor
                        // Don't prevent, but note it
                        console.log('CopyPasteBlocker: Select all detected');
                        break;
                }
            }
        }

        /**
         * Flag suspicious rapid typing activity
         */
        flagSuspiciousActivity(timeDiff, lengthDiff) {
            console.warn('CopyPasteBlocker: Suspicious rapid input detected', {
                timeDiff: timeDiff,
                lengthDiff: lengthDiff,
                threshold: this.config.rapidTypingThreshold
            });

            // Add visual indicator
            this.textarea.addClass('typing-suspicious');

            // Remove indicator after delay
            setTimeout(() => {
                this.textarea.removeClass('typing-suspicious');
            }, 2000);

            // Show warning
            this.showRapidTypingWarning(timeDiff, lengthDiff);

            // Log the event
            this.logSuspiciousActivity({
                type: 'rapid_typing',
                timeDiff: timeDiff,
                lengthDiff: lengthDiff,
                timestamp: Date.now()
            });
        }

        /**
         * Show paste warning modal
         */
        showPasteWarning() {
            const warning = $(`
                <div class="copy-paste-warning">
                    <h4><i class="fa fa-exclamation-triangle"></i> Paste Blocked</h4>
                    <p>Copy and paste operations are not allowed in Essays Master.</p>
                    <p>Please type your content manually to ensure original work.</p>
                    <p><small>Warning ${this.pasteWarningCount} of ${this.config.maxPasteWarnings}</small></p>
                    <button class="btn btn-primary" onclick="$(this).parent().remove()">OK</button>
                </div>
            `);

            $('body').append(warning);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                warning.fadeOut(() => warning.remove());
            }, 5000);
        }

        /**
         * Show rapid typing warning
         */
        showRapidTypingWarning(timeDiff, lengthDiff) {
            const warning = $(`
                <div class="copy-paste-warning">
                    <h4><i class="fa fa-clock-o"></i> Unusual Typing Detected</h4>
                    <p>Very rapid text insertion detected (${lengthDiff} characters in ${timeDiff}ms).</p>
                    <p>Please ensure you are typing manually.</p>
                    <button class="btn btn-warning" onclick="$(this).parent().remove()">I Understand</button>
                </div>
            `);

            $('body').append(warning);

            setTimeout(() => {
                warning.fadeOut(() => warning.remove());
            }, 7000);
        }

        /**
         * Show copy warning
         */
        showCopyWarning() {
            Notification.alert(
                'Copy Detected',
                'You have copied text from your essay. Remember that Essays Master encourages original thinking and writing.',
                'OK'
            );
        }

        /**
         * Show context menu warning
         */
        showContextMenuWarning() {
            const warning = $(`
                <div class="copy-paste-warning">
                    <h4><i class="fa fa-mouse-pointer"></i> Context Menu Disabled</h4>
                    <p>Right-click context menu is disabled to prevent copy/paste operations.</p>
                    <button class="btn btn-info" onclick="$(this).parent().remove()">OK</button>
                </div>
            `);

            $('body').append(warning);

            setTimeout(() => {
                warning.fadeOut(() => warning.remove());
            }, 3000);
        }

        /**
         * Show drop warning
         */
        showDropWarning() {
            const warning = $(`
                <div class="copy-paste-warning">
                    <h4><i class="fa fa-ban"></i> Drop Blocked</h4>
                    <p>Dragging and dropping text is not allowed.</p>
                    <p>Please type your content manually.</p>
                    <button class="btn btn-primary" onclick="$(this).parent().remove()">OK</button>
                </div>
            `);

            $('body').append(warning);

            setTimeout(() => {
                warning.fadeOut(() => warning.remove());
            }, 4000);
        }

        /**
         * Show locked warning
         */
        showLockedWarning() {
            const warning = $(`
                <div class="copy-paste-warning">
                    <h4><i class="fa fa-lock"></i> Textarea Locked</h4>
                    <p>This textarea has been locked due to multiple paste attempts.</p>
                    <p>Please contact your instructor for assistance.</p>
                    <button class="btn btn-danger" onclick="$(this).parent().remove()">OK</button>
                </div>
            `);

            $('body').append(warning);
        }

        /**
         * Lock textarea after too many violations
         */
        lockTextarea() {
            this.isLocked = true;
            this.textarea.addClass('textarea-locked');
            this.textarea.attr('readonly', true);

            // Show permanent lock indicator
            const lockIndicator = $(`
                <div class="alert alert-danger">
                    <i class="fa fa-lock"></i>
                    <strong>Textarea Locked:</strong>
                    Too many paste attempts detected. Contact your instructor.
                </div>
            `);

            this.textarea.before(lockIndicator);

            // Log the lock event
            this.logLockEvent();
        }

        /**
         * Log paste attempt
         */
        logPasteAttempt(pasteData) {
            const logData = {
                type: 'paste_attempt',
                pasteLength: pasteData.length,
                pasteSource: pasteData.source,
                warningCount: this.pasteWarningCount,
                timestamp: Date.now(),
                textareaId: this.textareaId
            };

            console.log('CopyPasteBlocker: Paste attempt logged', logData);

            // Send to server if configured
            this.sendLogToServer(logData);
        }

        /**
         * Log suspicious activity
         */
        logSuspiciousActivity(activityData) {
            const logData = {
                ...activityData,
                textareaId: this.textareaId,
                currentTextLength: this.textarea.val().length
            };

            console.log('CopyPasteBlocker: Suspicious activity logged', logData);

            // Send to server if configured
            this.sendLogToServer(logData);
        }

        /**
         * Log lock event
         */
        logLockEvent() {
            const logData = {
                type: 'textarea_locked',
                totalWarnings: this.pasteWarningCount,
                timestamp: Date.now(),
                textareaId: this.textareaId
            };

            console.log('CopyPasteBlocker: Lock event logged', logData);

            // Send to server
            this.sendLogToServer(logData);
        }

        /**
         * Send log data to server
         */
        sendLogToServer(logData) {
            // This would typically send data to a Moodle web service
            // For now, just store in sessionStorage for debugging
            const logs = JSON.parse(sessionStorage.getItem('copyPasteBlockerLogs') || '[]');
            logs.push(logData);
            sessionStorage.setItem('copyPasteBlockerLogs', JSON.stringify(logs));
        }

        /**
         * Get activity statistics
         */
        getActivityStats() {
            return {
                pasteWarningCount: this.pasteWarningCount,
                isLocked: this.isLocked,
                typingPatternLength: this.typingPattern.length,
                config: this.config
            };
        }

        /**
         * Enable the blocker
         */
        enable() {
            this.config.enabled = true;
            $('.copy-paste-blocker-indicator').show();
        }

        /**
         * Disable the blocker
         */
        disable() {
            this.config.enabled = false;
            $('.copy-paste-blocker-indicator').hide();
        }

        /**
         * Destroy the blocker
         */
        destroy() {
            // Remove event listeners
            this.textarea.off('paste input keydown contextmenu drop dragover');
            if (this.feedbackPanel.length > 0) {
                this.feedbackPanel.off('dragstart selectstart contextmenu');
            }

            // Remove UI elements
            $('.copy-paste-blocker-indicator').remove();
            $('.copy-paste-warning').remove();

            // Unlock textarea if locked
            if (this.isLocked) {
                this.textarea.removeClass('textarea-locked');
                this.textarea.removeAttr('readonly');
            }
        }
    }

    return {
        /**
         * Create new copy/paste blocker instance
         */
        create: function(textareaId, feedbackPanelId, config) {
            return new CopyPasteBlocker(textareaId, feedbackPanelId, config);
        },

        /**
         * Initialize blocker for multiple textareas
         */
        initMultiple: function(textareaSelectors, feedbackPanelId, config) {
            const blockers = [];

            $(textareaSelectors).each(function() {
                const id = $(this).attr('id');
                if (id) {
                    blockers.push(new CopyPasteBlocker(id, feedbackPanelId, config));
                }
            });

            return blockers;
        }
    };
});