// DEPRECATED: This file has been replaced by amd/src/feedback.js
// 
// The old quiz_interceptor.js has been consolidated with debug_buttons.js
// into a single AMD module: local_essaysmaster/feedback
//
// This legacy loader will redirect to the new module for backward compatibility

console.warn('Essays Master: quiz_interceptor.js is deprecated. Use local_essaysmaster/feedback instead.');

// Legacy compatibility - redirect to new module
if (typeof require !== 'undefined') {
    require(['local_essaysmaster/feedback'], function(feedback) {
        console.log('Essays Master: Loaded via legacy redirect to new feedback module');
        
        // Try to auto-initialize with default settings
        feedback.init({
            maxRounds: 3,
            attemptId: (function() {
                const urlParams = new URLSearchParams(window.location.search);
                return urlParams.get('attempt') || null;
            })()
        });
    });
} else {
    // Fallback for non-AMD environments
    console.error('Essays Master: AMD module system not available. Cannot load feedback module.');
}
