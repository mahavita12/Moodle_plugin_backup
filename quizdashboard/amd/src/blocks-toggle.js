/**
 * Global Blocks Toggle Module
 * Provides a global toggle button to hide/show Moodle's blocks panel site-wide
 *
 * @module     local_quizdashboard/blocks-toggle
 * @copyright  2024 Quiz Dashboard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    var STORAGE_KEY = 'moodle_blocks_hidden';
    var TOGGLE_CLASS = 'blocks-hidden-mode';
    var BUTTON_CLASS = 'blocks-hidden';
    
    /**
     * Check if blocks are present on the current page
     * @returns {boolean} True if blocks are found
     */
    function hasBlocks() {
        var blockSelectors = [
            '[data-region="blocks-column"]',
            '.region_post',
            '.region-post', 
            '#region-post',
            '[data-region="post"]',
            '.block-region-post',
            '.block-region-side-post',
            '.region_main_post',
            '.region-main-post'
        ];
        
        return blockSelectors.some(function(selector) {
            return $(selector).length > 0 && $(selector).is(':visible');
        });
    }
    
    /**
     * Get the current state from localStorage
     * @returns {boolean} True if blocks should be hidden
     */
    function getStoredState() {
        try {
            return localStorage.getItem(STORAGE_KEY) === 'true';
        } catch (e) {
            return false;
        }
    }
    
    /**
     * Save the current state to localStorage
     * @param {boolean} isHidden Whether blocks are hidden
     */
    function saveState(isHidden) {
        try {
            localStorage.setItem(STORAGE_KEY, isHidden.toString());
        } catch (e) {
            console.warn('Could not save blocks toggle state:', e);
        }
    }
    
    /**
     * Apply the blocks hidden state
     * @param {boolean} isHidden Whether to hide blocks
     * @param {jQuery} button The toggle button element
     */
    function applyState(isHidden, button) {
        if (isHidden) {
            $('body').addClass(TOGGLE_CLASS);
            button.addClass(BUTTON_CLASS);
            button.find('.toggle-text').text('Show Blocks');
            button.find('.toggle-icon').html('👁️');
        } else {
            $('body').removeClass(TOGGLE_CLASS);
            button.removeClass(BUTTON_CLASS);
            button.find('.toggle-text').text('Hide Blocks');
            button.find('.toggle-icon').html('🔳');
        }
        
        saveState(isHidden);
    }
    
    /**
     * Create and inject the toggle button
     */
    function createToggleButton() {
        // Check if button already exists
        if ($('.global-blocks-toggle').length > 0) {
            return;
        }
        
        var isHidden = getStoredState();
        var buttonText = isHidden ? 'Show Blocks' : 'Hide Blocks';
        var buttonIcon = isHidden ? '👁️' : '🔳';
        
        var toggleHtml = `
            <div class="global-blocks-toggle">
                <button type="button" class="blocks-toggle-btn ${isHidden ? BUTTON_CLASS : ''}" 
                        title="Toggle blocks panel visibility">
                    <span class="toggle-icon">${buttonIcon}</span>
                    <span class="toggle-text">${buttonText}</span>
                </button>
            </div>
        `;
        
        // Inject the button
        $('body').append(toggleHtml);
        
        var button = $('.blocks-toggle-btn');
        
        // Apply initial state
        if (isHidden) {
            $('body').addClass(TOGGLE_CLASS);
        }
        
        // Add click handler
        button.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var currentlyHidden = $('body').hasClass(TOGGLE_CLASS);
            applyState(!currentlyHidden, button);
        });
        
        // Add keyboard support
        button.on('keypress', function(e) {
            if (e.which === 13 || e.which === 32) { // Enter or Space
                e.preventDefault();
                $(this).click();
            }
        });
        
        console.log('Global blocks toggle initialized');
    }
    
    /**
     * Initialize the blocks toggle functionality
     */
    function init() {
        console.log('Blocks toggle init() called');
        $(document).ready(function() {
            console.log('DOM ready, checking for blocks...');
            
            // Only create toggle if blocks are present
            if (hasBlocks()) {
                console.log('Blocks found! Creating toggle button...');
                // Small delay to ensure page is fully loaded
                setTimeout(createToggleButton, 500);
            } else {
                console.log('No blocks found on this page');
            }
        });
    }
    
    return {
        init: init
    };
});
