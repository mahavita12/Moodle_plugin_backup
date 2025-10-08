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
 * Text highlighting module for Essays Master feedback.
 *
 * @module     local_essaysmaster/text_highlighter
 * @copyright  2024 Essays Master Development Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/notification'], function($, Notification) {

    /**
     * Text Highlighter class
     */
    class TextHighlighter {
        constructor(textareaId, config = {}) {
            this.textareaId = textareaId;
            this.textarea = $('#' + textareaId);
            this.highlights = [];
            this.config = {
                highlightClass: 'essay-highlight',
                tooltipDelay: 500,
                highlightTypes: {
                    error: '#ffebee',
                    warning: '#fff3e0',
                    improvement: '#e3f2fd',
                    success: '#e8f5e8'
                },
                ...config
            };

            this.initialize();
        }

        /**
         * Initialize the highlighter
         */
        initialize() {
            if (this.textarea.length === 0) {
                console.error('TextHighlighter: Textarea not found:', this.textareaId);
                return;
            }

            this.createOverlay();
            this.setupEventListeners();
        }

        /**
         * Create highlighting overlay
         */
        createOverlay() {
            // Create container for textarea and overlay
            this.container = $('<div class="essay-highlighter-container"></div>');
            this.textarea.wrap(this.container);

            // Create overlay div
            this.overlay = $('<div class="essay-highlight-overlay"></div>');
            this.textarea.after(this.overlay);

            // Apply CSS styles
            this.applyStyles();
        }

        /**
         * Apply necessary CSS styles
         */
        applyStyles() {
            const styles = `
                <style id="essay-highlighter-styles">
                .essay-highlighter-container {
                    position: relative;
                    display: inline-block;
                    width: 100%;
                }

                .essay-highlight-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    pointer-events: none;
                    font-family: inherit;
                    font-size: inherit;
                    line-height: inherit;
                    white-space: pre-wrap;
                    word-wrap: break-word;
                    border: 1px solid transparent;
                    padding: inherit;
                    margin: 0;
                    background: transparent;
                    z-index: 1;
                    overflow: hidden;
                }

                .essay-highlight-overlay .highlight-span {
                    background-color: rgba(255, 193, 7, 0.3);
                    border-radius: 2px;
                    cursor: pointer;
                    pointer-events: auto;
                    position: relative;
                }

                .essay-highlight-overlay .highlight-span.type-error {
                    background-color: rgba(244, 67, 54, 0.3);
                }

                .essay-highlight-overlay .highlight-span.type-warning {
                    background-color: rgba(255, 152, 0, 0.3);
                }

                .essay-highlight-overlay .highlight-span.type-improvement {
                    background-color: rgba(33, 150, 243, 0.3);
                }

                .essay-highlight-overlay .highlight-span.type-success {
                    background-color: rgba(76, 175, 80, 0.3);
                }

                .essay-highlight-tooltip {
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 8px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    max-width: 300px;
                    z-index: 1000;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                    pointer-events: none;
                }

                .essay-highlight-tooltip::after {
                    content: '';
                    position: absolute;
                    top: 100%;
                    left: 50%;
                    margin-left: -5px;
                    border-width: 5px;
                    border-style: solid;
                    border-color: #333 transparent transparent transparent;
                }
                </style>
            `;

            if ($('#essay-highlighter-styles').length === 0) {
                $('head').append(styles);
            }
        }

        /**
         * Setup event listeners
         */
        setupEventListeners() {
            // Sync scrolling
            this.textarea.on('scroll', () => {
                this.syncScroll();
            });

            // Update highlights on text change
            this.textarea.on('input', () => {
                this.updateHighlightPositions();
            });

            // Handle resize
            $(window).on('resize', () => {
                this.syncOverlay();
            });

            // Tooltip handling
            this.overlay.on('mouseenter', '.highlight-span', (e) => {
                this.showTooltip(e);
            });

            this.overlay.on('mouseleave', '.highlight-span', () => {
                this.hideTooltip();
            });

            // Click handling for highlights
            this.overlay.on('click', '.highlight-span', (e) => {
                this.handleHighlightClick(e);
            });
        }

        /**
         * Sync overlay scroll with textarea
         */
        syncScroll() {
            this.overlay.scrollTop(this.textarea.scrollTop());
            this.overlay.scrollLeft(this.textarea.scrollLeft());
        }

        /**
         * Sync overlay dimensions and position
         */
        syncOverlay() {
            const textareaPos = this.textarea.position();
            const textareaCSS = {
                top: textareaPos.top,
                left: textareaPos.left,
                width: this.textarea.outerWidth(),
                height: this.textarea.outerHeight(),
                padding: this.textarea.css('padding'),
                border: this.textarea.css('border'),
                fontSize: this.textarea.css('fontSize'),
                fontFamily: this.textarea.css('fontFamily'),
                lineHeight: this.textarea.css('lineHeight')
            };

            this.overlay.css(textareaCSS);
            this.syncScroll();
        }

        /**
         * Add highlights to the text
         */
        addHighlights(highlightData) {
            if (!Array.isArray(highlightData)) {
                console.error('TextHighlighter: highlightData must be an array');
                return;
            }

            this.highlights = highlightData;
            this.renderHighlights();
        }

        /**
         * Render highlights in the overlay
         */
        renderHighlights() {
            const text = this.textarea.val();
            let html = '';
            let lastIndex = 0;

            // Sort highlights by start position
            const sortedHighlights = [...this.highlights].sort((a, b) => a.start - b.start);

            sortedHighlights.forEach((highlight, index) => {
                // Add text before highlight
                html += this.escapeHtml(text.substring(lastIndex, highlight.start));

                // Add highlighted text
                const highlightedText = text.substring(highlight.start, highlight.end);
                html += `<span class="highlight-span type-${highlight.type}"
                               data-highlight-id="${highlight.id || index}"
                               data-feedback="${this.escapeHtml(highlight.feedback || '')}"
                               title="${this.escapeHtml(highlight.feedback || '')}">`;
                html += this.escapeHtml(highlightedText);
                html += '</span>';

                lastIndex = highlight.end;
            });

            // Add remaining text
            html += this.escapeHtml(text.substring(lastIndex));

            this.overlay.html(html);
            this.syncOverlay();
        }

        /**
         * Update highlight positions after text change
         */
        updateHighlightPositions() {
            // For now, just re-render all highlights
            // In a more sophisticated implementation, we could track changes
            // and update positions accordingly
            this.renderHighlights();
        }

        /**
         * Show tooltip for highlight
         */
        showTooltip(event) {
            const highlight = $(event.target);
            const feedback = highlight.data('feedback');

            if (!feedback) {
                return;
            }

            this.hideTooltip(); // Hide any existing tooltip

            const tooltip = $(`
                <div class="essay-highlight-tooltip">
                    ${feedback}
                </div>
            `);

            $('body').append(tooltip);

            // Position tooltip
            const highlightOffset = highlight.offset();
            const tooltipWidth = tooltip.outerWidth();
            const tooltipHeight = tooltip.outerHeight();

            let left = highlightOffset.left + (highlight.outerWidth() / 2) - (tooltipWidth / 2);
            let top = highlightOffset.top - tooltipHeight - 10;

            // Adjust if tooltip goes off screen
            if (left < 0) left = 10;
            if (left + tooltipWidth > $(window).width()) {
                left = $(window).width() - tooltipWidth - 10;
            }
            if (top < 0) {
                top = highlightOffset.top + highlight.outerHeight() + 10;
            }

            tooltip.css({
                left: left,
                top: top
            });

            this.tooltip = tooltip;
        }

        /**
         * Hide tooltip
         */
        hideTooltip() {
            if (this.tooltip) {
                this.tooltip.remove();
                this.tooltip = null;
            }
        }

        /**
         * Handle click on highlight
         */
        handleHighlightClick(event) {
            const highlight = $(event.target);
            const highlightId = highlight.data('highlight-id');
            const feedback = highlight.data('feedback');

            // Trigger custom event
            $(document).trigger('essaysmaster:highlight-clicked', {
                highlightId: highlightId,
                feedback: feedback,
                element: highlight
            });
        }

        /**
         * Clear all highlights
         */
        clearHighlights() {
            this.highlights = [];
            this.overlay.html(this.escapeHtml(this.textarea.val()));
            this.hideTooltip();
        }

        /**
         * Remove specific highlight
         */
        removeHighlight(highlightId) {
            this.highlights = this.highlights.filter(h => h.id !== highlightId);
            this.renderHighlights();
        }

        /**
         * Escape HTML for safe display
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Destroy the highlighter
         */
        destroy() {
            if (this.overlay) {
                this.overlay.remove();
            }

            if (this.container) {
                this.textarea.unwrap();
            }

            this.hideTooltip();

            // Remove event listeners
            this.textarea.off('scroll input');
            $(window).off('resize');
        }
    }

    return {
        /**
         * Create new text highlighter instance
         */
        create: function(textareaId, config) {
            return new TextHighlighter(textareaId, config);
        },

        /**
         * Initialize highlighter for multiple textareas
         */
        initMultiple: function(textareaSelectors, config) {
            const highlighters = [];

            $(textareaSelectors).each(function() {
                const id = $(this).attr('id');
                if (id) {
                    highlighters.push(new TextHighlighter(id, config));
                }
            });

            return highlighters;
        }
    };
});