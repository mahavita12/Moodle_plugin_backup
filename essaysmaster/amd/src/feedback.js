// AMD module: local_essaysmaster/feedback
// ✅ 6-Round System PROPERLY Restored from Backup!
// Feedback → Validation pattern: Proofreading, Structure, Style

define([], function () {
    "use strict";

    // Page gate: only run on quiz attempt pages
    function onQuizAttemptPage() {
        return location.pathname.indexOf('/mod/quiz/attempt.php') !== -1;
    }

    // Find the "Finish/Submit" button Moodle uses on the attempt page
    function findSubmitButton() {
        // Common id
        let btn = document.getElementById('mod_quiz-next-nav');
        if (btn) return btn;

        // Fallbacks: look for submit inputs that mention "finish" or "submit"
        const inputs = document.querySelectorAll('input[type="submit"], input[type="button"], button');
        for (const el of inputs) {
            const v = (el.value || el.textContent || '').toLowerCase();
            if (v.includes('finish') || v.includes('submit')) return el;
        }
        return null;
    }

    // Create (or reuse) a single feedback panel placed under the button area
    function ensurePanel(afterNode) {
        let panel = document.getElementById('essays-master-feedback');
        if (panel) return panel;

        panel = document.createElement('div');
        panel.id = 'essays-master-feedback';
        panel.style.cssText = [
            'margin-top:10px','padding:15px','border:2px solid #0f6cbf','border-radius:8px',
            'background:#f8f9fa','display:none','font-family:Arial, sans-serif','position:relative','z-index:100',
            'resize:vertical','overflow:auto','min-height:200px','max-height:80vh'
        ].join(';');

        const container = afterNode.closest('.fcontainer') || afterNode.parentElement || document.body;
        container.parentNode.insertBefore(panel, container.nextSibling);
        return panel;
    }

    // 🤖 Essay content storage for validation rounds
    const essayStorage = {
        round1: '',
        round3: '',
        round5: ''
    };

    // Get current essay content from textarea with DEBUG logging
    function getCurrentEssayContent() {
        console.log('🔍 DEBUG: Looking for essay textarea...');
        
        // Try multiple selectors to find the textarea
        let textarea = document.querySelector('textarea[name*="answer"]');
        console.log('🔍 DEBUG: textarea[name*="answer"] found:', !!textarea);
        
        if (!textarea) {
            textarea = document.querySelector('textarea');
            console.log('🔍 DEBUG: any textarea found:', !!textarea);
        }
        
        if (!textarea) {
            const allTextareas = document.querySelectorAll('textarea');
            console.log('🔍 DEBUG: Total textareas on page:', allTextareas.length);
            allTextareas.forEach((ta, index) => {
                console.log(`🔍 DEBUG: Textarea ${index}: name="${ta.name}", id="${ta.id}", value length=${ta.value.length}`);
            });
            
            // Try to get the one with content
            for (let ta of allTextareas) {
                if (ta.value.trim().length > 0) {
                    textarea = ta;
                    console.log('🔍 DEBUG: Using textarea with content:', ta.name || ta.id);
                    break;
                }
            }
        }
        
        const content = textarea ? textarea.value.trim() : '';
        console.log('🔍 DEBUG: Retrieved essay content length:', content.length);
        console.log('🔍 DEBUG: Essay content preview:', content.substring(0, 100) + '...');
        
        return content;
    }





    // Clean AI response from formatting
    function cleanAIResponse(response) {
        return response
            .replace(/\*\*([^*]+)\*\*/g, '$1')  // Remove **bold**
            .replace(/\*([^*]+)\*/g, '$1')      // Remove *italic*
            .replace(/#{1,6}\s?/g, '')          // Remove # headers
            .replace(/`([^`]+)`/g, '$1')        // Remove `code`
            .replace(/^\s*[-*+]\s+/gm, '')      // Remove bullet points
            .replace(/^\s*\d+\.\s+/gm, '')      // Remove numbered lists
            .trim();
    }

    // Parse original => improved pairs with HIGHLIGHT tag cleanup and 9-item limit
    function parseImprovements(text) {
        const improvements = [];
        const lines = text.split('\n');
        
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].includes('=>')) {
                const parts = lines[i].split('=>');
                if (parts.length === 2) {
                    // Clean [HIGHLIGHT] tags from original text
                    const originalClean = parts[0].trim().replace(/\[HIGHLIGHT\]/g, '').replace(/\[\/HIGHLIGHT\]/g, '');
                    const improvedClean = parts[1].trim().replace(/\[HIGHLIGHT\]/g, '').replace(/\[\/HIGHLIGHT\]/g, '');
                    
                    // Relaxed validation - just check if both parts exist
                    if (originalClean && improvedClean) {
                        improvements.push({
                            original: originalClean,
                            improved: improvedClean
                        });
                        console.log('Improvement added:', originalClean, '=>', improvedClean);
                    }
                    
                    // Limit to 9 improvements per round
                    if (improvements.length >= 9) {
                        break;
                    }
                }
            }
        }
        
        return improvements;
    }

    // Format improvements for display
    function formatImprovements(improvements) {
        if (improvements.length === 0) return '';
        
        let html = '<div class="improvements-section">';
        improvements.forEach(item => {
            html += `
                <div class="improvement-item">
                    <div class="original-text">${item.original}</div>
                    <div class="arrow">=> </div>
                    <div class="improved-text">${item.improved}</div>
                </div>
            `;
        });
        html += '</div>';
        
        return html;
    }

    // Format feedback paragraphs for display
    function formatFeedbackParagraphs(text, round) {
        if (!text) {
            return '';
        }

        const paragraphs = text.split(/\n+/).map(p => p.trim()).filter(Boolean);
        const formatted = [];

        paragraphs.forEach((paragraph) => {
            if (round === 1 && /spelling/i.test(paragraph) && /grammar/i.test(paragraph)) {
                const spellingRegex = /(\d+\s+spelling mistakes?)/i;
                const grammarRegex = /(\d+\s+grammar[^-\.]+)/i;

                const spellingMatch = paragraph.match(spellingRegex);
                const grammarMatch = paragraph.match(grammarRegex);

                if (spellingMatch) {
                    formatted.push({
                        text: `We noticed ${spellingMatch[1].trim()}.`,
                        extraClass: ' feedback-line-warning'
                    });
                }

                if (grammarMatch) {
                    let grammarText = grammarMatch[1].trim();
                    grammarText = grammarText.replace(/^and\s+/i, '');
                    if (!/^we\s/i.test(grammarText)) {
                        grammarText = `We also found ${grammarText}`;
                    }
                    grammarText = grammarText.replace(/\.$/, '');
                    formatted.push({
                        text: `${grammarText}.`,
                        extraClass: ' feedback-line-warning'
                    });
                }

                let finalMessage = '';
                const hyphenParts = paragraph.split(' - ');
                if (hyphenParts.length > 1) {
                    finalMessage = hyphenParts.slice(1).join(' - ').trim();
                }
                if (!finalMessage) {
                    finalMessage = paragraph
                        .replace(spellingRegex, '')
                        .replace(grammarRegex, '')
                        .replace(/\s+/g, ' ')
                        .replace(/^and\s+/i, '')
                        .replace(/^I\s+found\s*/i, '')
                        .trim();
                }
                finalMessage = finalMessage.replace(/^[-:,\s]+/, '').trim();
                if (finalMessage) {
                    if (!/[.!?]$/.test(finalMessage)) {
                        finalMessage = `${finalMessage}.`;
                    }
                    formatted.push({
                        text: finalMessage,
                        extraClass: ' feedback-line-emphasis'
                    });
                }
            } else {
                formatted.push({ text: paragraph });
            }
        });

        return formatted.map((item, index) => {
            let extraClass = item.extraClass || '';
            if (!extraClass && round === 1) {
                if (index === 1) {
                    extraClass = ' feedback-line-warning';
                } else if (index === 2) {
                    extraClass = ' feedback-line-emphasis';
                }
            }
            return `<p class="feedback-line${extraClass}">${item.text}</p>`;
        }).join('');
    }

    // Get feedback from real AI backend
    function getFeedback(round, attemptId, callback) {
        const studentText = getCurrentEssayContent();
        const originalText = essayStorage[`round${round - 1}`] || '';
        const questionPrompt = "Sample question prompt"; // This should come from the quiz
        
        // Prepare data for backend API call
        const formData = new FormData();
        formData.append('attemptid', attemptId);
        formData.append('round', round);
        formData.append('sesskey', M.cfg.sesskey);
        formData.append('current_text', studentText);
        formData.append('original_text', originalText);
        formData.append('question_prompt', questionPrompt);
        formData.append('nonce', Date.now() + '_' + Math.random().toString(36).substr(2, 9));
        
        console.log('📚 Essays Master: Making real API call to backend for round', round);
        
        // Make AJAX call to real backend
        fetch(M.cfg.wwwroot + '/local/essaysmaster/get_feedback.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            console.log('📚 Essays Master: Received response from backend:', data);
            
            if (data.success) {
                const cleanedResponse = cleanAIResponse(data.feedback);
                const improvements = parseImprovements(cleanedResponse);
                
                callback(true, {
                    success: true,
                    feedback: cleanedResponse,
                    improvements: improvements,
                    highlighted_text: data.highlighted_text || '',
                    highlights: data.highlights || []
                });
            } else {
                console.error('📚 Essays Master: Backend error:', data.error);
                callback(false, data.error || 'Backend error occurred');
            }
        })
        .catch(error => {
            console.error('📚 Essays Master: Network error:', error);
            callback(false, 'Network error: ' + error.message);
        });
    }

    // 🎨 Start amber delay with custom duration and callback
    function startAmberDelay(btn, amberText, delaySeconds, callback) {
        let timeLeft = delaySeconds;
        btn.disabled = true;
        btn.style.backgroundColor = '#ffc107';
        btn.style.borderColor = '#ffc107';
        btn.value = amberText;
        
        const timer = setInterval(() => {
            timeLeft--;
            
            if (timeLeft < 0) {
                clearInterval(timer);
                btn.disabled = false;
                btn.value = 'Submit'; // Always return to "Submit" in blue state
                btn.style.backgroundColor = '#0f6cbf';
                btn.style.borderColor = '#0a58ca';
                if (callback) callback();
            }
        }, 1000);
    }

    // Add CSS for improvements styling + amber highlighting
    function addImprovementStyles() {
        if (document.getElementById('essays-master-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'essays-master-styles';
        style.textContent = `
            .improvements-section {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                margin: 15px 0;
                border-left: 4px solid #007bff;
            }
            
            .improvement-item {
                margin: 5px 0;
                padding: 8px 12px;
                background: white;
                border-radius: 4px;
                border: 1px solid #e9ecef;
                display: flex;
                flex-direction: row;
                align-items: center;
                gap: 10px;
            }
            
            .original-text {
                color: #6c757d;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding: 4px 8px;
                background: #f8f9fa;
                border-radius: 3px;
                margin: 0;
                border-left: 3px solid #6c757d;
                flex: 1;
                font-size: 14px;
            }
            
            .improved-text {
                color: #007bff;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                padding: 4px 8px;
                background: #e3f2fd;
                border-radius: 3px;
                margin: 0;
                border-left: 3px solid #007bff;
                font-weight: 500;
                flex: 1;
                font-size: 14px;
            }
            
            .arrow {
                text-align: center;
                font-size: 14px;
                color: #28a745;
                font-weight: bold;
                margin: 0;
                flex-shrink: 0;
            }
            
            .ai-feedback-content {
                background: #fff;
                padding: 15px;
                border-radius: 6px;
                margin: 10px 0;
                border-left: 4px solid #17a2b8;
                line-height: 1.6;
            }
            
            .feedback-text {
                color: #495057;
                margin-bottom: 15px;
            }

            .feedback-text .feedback-line {
                margin-bottom: 8px;
                font-size: 15px;
            }

            .feedback-line-warning {
                color: #dc3545;
                font-weight: 600;
            }

            .feedback-line-emphasis {
                background: #e7f3ff;
                border-left: 4px solid #0f6cbf;
                border-radius: 4px;
                padding: 8px 12px;
                color: #0f3c75;
                font-weight: 600;
            }
            
            /* 🟡 AMBER HIGHLIGHTING STYLES */
            .amber-highlight {
                background-color: #ffc107 !important;
                color: #212529 !important;
                font-weight: bold !important;
                border-radius: 3px !important;
                padding: 2px 4px !important;
                margin: 0 1px !important;
                border: 1px solid #e0a800 !important;
                box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3) !important;
            }

            .validation-highlight {
                background-color: #cfe2ff !important;
                color: #084298 !important;
                font-weight: 600 !important;
                border-radius: 3px !important;
                padding: 2px 4px !important;
                margin: 0 1px !important;
                border: 1px solid #9ec5fe !important;
                box-shadow: 0 2px 4px rgba(13, 110, 253, 0.2) !important;
            }
            
            .essay-display-container {
                background: #fff;
                border: 2px solid #007bff;
                border-radius: 8px;
                padding: 20px;
                margin: 15px 0;
                font-family: 'Calibri', 'Segoe UI', Arial, sans-serif;
                font-size: 16px;
                line-height: 1.8;
                max-height: 400px;
                overflow-y: auto;
            }
            
            .essay-display-title {
                color: #007bff;
                font-weight: bold;
                margin-bottom: 15px;
                font-size: 18px;
                text-align: center;
                border-bottom: 2px solid #007bff;
                padding-bottom: 8px;
            }
            
            .highlight-legend {
                background: #e7f3ff;
                border: 1px solid #b6daff;
                border-radius: 4px;
                padding: 10px;
                margin: 10px 0;
                font-size: 14px;
                color: #0f3c75;
            }
            
            .highlight-legend .legend-item {
                display: inline-block;
                margin-right: 15px;
                margin-bottom: 5px;
            }
            
            .highlight-legend .legend-color {
                display: inline-block;
                width: 20px;
                height: 15px;
                border-radius: 3px;
                margin-right: 5px;
                vertical-align: middle;
            }

            .highlight-legend .legend-color.amber-highlight {
                background-color: #ffc107;
                border: 1px solid #e0a800;
            }

            .highlight-legend .legend-color.validation-highlight {
                background-color: #cfe2ff;
                border: 1px solid #9ec5fe;
            }
            
            /* Enhanced Resizable feedback panel */
            #essays-master-feedback {
                resize: vertical !important;
                overflow: auto !important;
                min-height: 200px !important;
                max-height: 80vh !important;
                position: relative !important;
            }
            
            /* Make resize handle more visible */
            #essays-master-feedback::-webkit-resizer {
                background-image: 
                    linear-gradient(-45deg, transparent 2px, #0f6cbf 2px, #0f6cbf 4px, transparent 4px),
                    linear-gradient(-45deg, transparent 6px, #0f6cbf 6px, #0f6cbf 8px, transparent 8px),
                    linear-gradient(-45deg, transparent 10px, #0f6cbf 10px, #0f6cbf 12px, transparent 12px);
                background-size: 8px 8px;
                background-repeat: no-repeat;
                background-position: bottom right;
                border: 1px solid #0f6cbf;
                border-radius: 0 0 8px 0;
            }
            
            /* Firefox resize handle styling */
            #essays-master-feedback {
                resize: vertical;
                overflow: auto;
            }
            
            /* Add subtle visual hint for resize */
            #essays-master-feedback::after {
                content: "â¤¡ Drag to resize";
                position: absolute;
                bottom: 2px;
                right: 20px;
                font-size: 10px;
                color: #6c757d;
                pointer-events: none;
                opacity: 0.7;
                font-family: Arial, sans-serif;
            }
        `;
        document.head.appendChild(style);
    }

    // 🟡 Find and highlight problematic text in essay
    function highlightProblematicText(essayText, improvements, highlightClass = 'amber-highlight') {
        if (!improvements || improvements.length === 0) return essayText;
        
        let highlightedText = essayText;
        
        // Apply amber highlighting to original text that needs fixing
        improvements.forEach(improvement => {
            if (improvement.original && improvement.original.trim()) {
                const originalText = improvement.original.trim();
                // Escape special regex characters
                const escapedOriginal = originalText.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const regex = new RegExp(`\\b${escapedOriginal}\\b`, 'gi');
                
                highlightedText = highlightedText.replace(regex, (match) => {
                    return `<span class="${highlightClass}" title="Suggested improvement: ${improvement.improved}">${match}</span>`;
                });
            }
        });
        
        return highlightedText;
    }

    // 🟡 Create essay display with highlighting
    function createEssayDisplay(essayText, improvements, round) {
        const highlightClass = 'amber-highlight';
        const highlightedText = highlightProblematicText(essayText, improvements, highlightClass);
        const legendLabel = 'Amber highlights show text that needs improvement';

        const roundTitles = {
            1: "Grammar, Spelling & Punctuation Issues",
            3: "Language Enhancement Opportunities", 
            5: "Relevance & Structure Improvements"
        };
        
        return `
            <div class="essay-display-container">
                <div class="essay-display-title">
                    Your Essay - ${roundTitles[round] || 'Review'}
                </div>
                
                ${improvements && improvements.length > 0 ? `
                    <div class="highlight-legend">
                        <span class="legend-item">
                            <span class="legend-color ${highlightClass}"></span>
                            <strong>${legendLabel}</strong>
                        </span>
                        <span class="legend-item">
                            <em>Hover over highlighted text for suggestions</em>
                        </span>
                    </div>
                ` : ''}
                
                <div style="text-align: justify; white-space: pre-wrap;">${highlightedText}</div>
            </div>
        `;
    }

    // 🎯 Render feedback or validation round with amber highlighting
    function renderRound(panel, round, feedback) {
        panel.style.display = 'block';
        addImprovementStyles();
        
        const config = getRoundConfig(round);
        
        let content = `<h3 style="color:#0f6cbf;margin-top:0;font-size:18px;">${config.title}</h3>`;

        if (config.type === 'feedback') {
            // AI Feedback rounds (1, 3, 5) - Show essay with amber highlights
            if (feedback && feedback.feedback) {
                content += `<div class="ai-feedback-content">`;
                
                // Extract main feedback text (everything before improvements)
                const feedbackLines = feedback.feedback.split('\n');
                let mainFeedback = '';
                let improvementSection = '';
                let inImprovements = false;
                
                feedbackLines.forEach(line => {
                    if (line.includes('=>') || inImprovements) {
                        inImprovements = true;
                        improvementSection += line + '\n';
                    } else {
                        mainFeedback += line + '\n';
                    }
                });
                
                // 1. AI Feedback text first
                content += `<div class="feedback-text">${formatFeedbackParagraphs(mainFeedback, round)}</div>`;
                
                // 2. Originalâ†’improved examples second
                if (feedback.improvements && feedback.improvements.length > 0) {
                    content += formatImprovements(feedback.improvements);
                }
                
                content += `</div>`;
                
                // 3. Essay with highlights last (moved to end) - but only for Round 3, NOT rounds 1, 5
                if (round === 3) {
                    const currentEssay = getCurrentEssayContent();
                    if (currentEssay && feedback.improvements && feedback.improvements.length > 0) {
                        content += createEssayDisplay(currentEssay, feedback.improvements, round);
                    }
                }
            } else {
                // Show current essay without highlights while loading - only for round 3, NOT rounds 1 and 5
                const currentEssay = getCurrentEssayContent();
                if (currentEssay && round === 3) {
                    content += createEssayDisplay(currentEssay, [], round);
                }
                
                content += `<div class="ai-feedback-content">
                    <div class="feedback-text">GrowMinds Academy is analyzing your essay for ${config.focus.toLowerCase()}...</div>
                </div>`;
            }
        } else {
            // Validation rounds (2, 4, 6)
            content += `<div class="ai-feedback-content">
                <div class="feedback-text">GrowMinds Academy is validating your improvements...</div>
            </div>`;
        }

        content += `
            <div style="text-align:center;margin-top:15px;padding:10px;background:#e7f3ff;border-radius:4px;">
                <strong>Round ${round} of 6</strong>
            </div>
        `;

        panel.innerHTML = content;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Parse AI validation response with scoring
    function parseAIValidationResponse(aiResponse) {
        const lines = aiResponse.split('\n');
        let score = 0;
        let status = 'FAIL';
        let analysis = '';
        let feedback = '';
        
        lines.forEach(line => {
            if (line.startsWith('Score:')) {
                score = parseInt(line.replace('Score:', '').trim()) || 0;
            } else if (line.startsWith('Status:')) {
                status = line.replace('Status:', '').trim();
            } else if (line.startsWith('Analysis:')) {
                analysis = line.replace('Analysis:', '').trim();
            } else if (line.startsWith('Feedback:')) {
                feedback = lines.slice(lines.indexOf(line)).join('\n').replace('Feedback:', '').trim();
            }
        });
        
        return {
            score: score,
            passed: status === 'PASS' || score >= 50,
            analysis: analysis,
            feedback: feedback,
            improvements: parseImprovements(feedback)
        };
    }

    // Get AI validation with proper scoring - USE BACKEND API
    async function getAIValidationFeedback(round, previousText, currentText, questionPrompt) {
        try {
            console.log(`🔍 DEBUG: Validation round ${round} - previousText length: ${previousText?.length || 0}, currentText length: ${currentText?.length || 0}`);
            
            // Use the same backend API as feedback rounds
            const formData = new FormData();
            formData.append('attemptid', window.essayMasterAttemptId || 1);
            formData.append('round', round);
            formData.append('sesskey', M.cfg.sesskey);
            formData.append('current_text', currentText || '');
            formData.append('original_text', previousText || '');
            formData.append('question_prompt', questionPrompt || '');
            formData.append('nonce', Date.now() + '_' + Math.random().toString(36).substr(2, 9));
            
            console.log(`📚 Essays Master: Making validation API call to backend for round ${round}`);
            
            const response = await fetch(M.cfg.wwwroot + '/local/essaysmaster/get_feedback.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            console.log(`📚 Essays Master: Received validation response from backend:`, data);
            
            if (data.success) {
                // Parse the backend response for validation
                const cleanedResponse = cleanAIResponse(data.feedback);
                const parsed = parseAIValidationResponse(cleanedResponse);
                
                return {
                    success: parsed.passed,
                    score: parsed.score,
                    feedback: parsed.feedback,
                    analysis: parsed.analysis,
                    improvements: parsed.improvements
                };
            } else {
                console.error('📚 Essays Master: Backend validation error:', data.error);
                throw new Error(data.error || 'Backend validation error occurred');
            }
            
        } catch (error) {
            console.error('AI validation error:', error);
            throw error; // Re-throw to be caught by handleValidation
        }
    }

    // Updated validation function (keeping original name for compatibility)
    async function getValidationFeedback(round, previousText, currentText, questionPrompt) {
        return await getAIValidationFeedback(round, previousText, currentText, questionPrompt);
    }

    // 🎯 Handle validation round logic
    function handleValidation(panel, round, previousRound, btn, attemptId) {
        const previousContent = essayStorage[`round${previousRound}`];
        const currentContent = getCurrentEssayContent();
        const questionPrompt = "Sample question prompt"; // This should come from the quiz
        
        // Store attemptId globally for validation calls
        window.essayMasterAttemptId = attemptId;
        
        // DEBUG: Log validation parameters
        console.log(`🔍 DEBUG: Validation round ${round}, previous round ${previousRound}`);
        console.log(`🔍 DEBUG: Previous content length: ${previousContent?.length || 0}`);
        console.log(`🔍 DEBUG: Current content length: ${currentContent?.length || 0}`);
        console.log(`🔍 DEBUG: AttemptId: ${attemptId}`);
        console.log(`🔍 DEBUG: EssayStorage:`, essayStorage);
        
        // Get real AI validation feedback from backend
        getValidationFeedback(round, previousContent, currentContent, questionPrompt)
            .then(result => {
                addImprovementStyles();
                
                const config = getRoundConfig(round);

                let validationContent = `
                    <h3 style="color:#0f6cbf;margin-top:0;font-size:18px;">${config.title}</h3>
                    <div class="ai-feedback-content" style="border-left:4px solid ${result.success ? '#28a745' : '#dc3545'};">
                        <p><strong>Validation Score: ${result.score}/100 ${result.success ? 'Passed' : 'Failed'}</strong></p>
                `;
                
                // Extract main feedback text and improvements for both success and failure
                const feedbackLines = result.feedback.split('\n');
                let mainFeedback = '';
                let improvementSection = '';
                let inImprovements = false;
                
                feedbackLines.forEach(line => {
                    if (line.includes('=>') || inImprovements) {
                        inImprovements = true;
                        improvementSection += line + '\n';
                    } else {
                        mainFeedback += line + '\n';
                    }
                });
                
                if (result.success) {
                    validationContent += `<div class="feedback-text" style="color:#28a745;">${formatFeedbackParagraphs(mainFeedback, round)}</div>`;
                    
                    // Add formatted improvements for success cases too
                    if (result.improvements && result.improvements.length > 0) {
                        validationContent += formatImprovements(result.improvements);
                    }
                    
                    // Success: prepare for next round or final submission
                    btn.disabled = false;
                    btn.value = "Submit"; // Always "Submit" in blue (normal) state
                    if (round === 6) {
                        validationContent += `<p style="font-weight:bold;color:#28a745;">All rounds completed! Ready for final submission.</p>`;
                    }
                } else {
                    validationContent += `<div class="feedback-text" style="color:#dc3545;">${formatFeedbackParagraphs(mainFeedback, round)}</div>`;
                    
                    // Add formatted improvements for failure cases
                    if (result.improvements && result.improvements.length > 0) {
                        validationContent += formatImprovements(result.improvements);
                    }
                }
                
                validationContent += `</div>`;
                
                // Add essay with proper highlighting for validation rounds 2 and 4 only, NOT rounds 5 and 6
                if (round === 2 || round === 4) {
                    const currentEssay = getCurrentEssayContent();
                    if (currentEssay) {
                        // Use createEssayDisplay for consistent formatting and highlighting
                        validationContent += createEssayDisplay(currentEssay, result.improvements || [], round);
                    }
                }
                
                // Add button delay for failed validations only
                if (!result.success) {
                    const buttonTexts = {
                        2: "Proofread",
                        4: "Fix your mistakes", 
                        6: "Polish & Perfect"
                    };
                    
                    startAmberDelay(btn, buttonTexts[round], 5, () => {
                        // Button ready for retry
                    });
                }
                
                validationContent += `
                    <div style="text-align:center;margin-top:15px;padding:10px;background:#e7f3ff;border-radius:4px;">
                        <strong>Round ${round} of 6 - Validation ${result.success ? 'Passed' : 'Failed'}</strong>
                    </div>
                `;
                
                panel.innerHTML = validationContent;
            })
            .catch(error => {
                console.error('Validation error:', error);
                const config = getRoundConfig(round);
                panel.innerHTML = `
                    <h3 style="color:#0f6cbf;margin-top:0;font-size:18px;">${config.title}</h3>
                    <div class="ai-feedback-content" style="border-left:4px solid #dc3545;">
                        <div class="feedback-text" style="color:#dc3545;">Error getting validation feedback: ${error.message || error}. Please try again.</div>
                        <div class="feedback-text" style="color:#6c757d;font-size:12px;margin-top:10px;">Debug info: Round ${round}, Previous round ${previousRound}, AttemptId: ${attemptId}</div>
                    </div>
                `;
                btn.disabled = false;
                btn.value = "Submit";
            });
    }

    // Public entry point
    function init(options) {
        if (!onQuizAttemptPage()) {
            console.log('Essays Master: Not on quiz attempt page');
            return;
        }

        // Global guard so re-renders/iframes don't double-bind
        if (window.__EM_ACTIVE__) {
            console.log('Essays Master: Already active, skipping');
            return;
        }
        window.__EM_ACTIVE__ = true;

        const attemptId = (options && options.attemptId) || extractAttemptIdFromURL() || 1;
        
        // Extract attemptId from URL if not provided
        function extractAttemptIdFromURL() {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('attempt') || urlParams.get('attemptid') || null;
        }

        console.log('Essays Master: Initializing 6-round feedback system');

        // Delay to ensure Moodle DOM is in place
        const boot = () => {
            const btn = findSubmitButton();
            if (!btn) {
                console.log('Essays Master: No submit button found');
                return;
            }

            // Prevent duplicate binding to the same element
            if (btn.dataset.emBound === '1') {
                console.log('Essays Master: Button already bound');
                return;
            }
            btn.dataset.emBound = '1';

            const panel = ensurePanel(btn);

            // Preserve original behavior
            const original = {
                value: btn.value,
                type: btn.type,
                onclick: btn.onclick
            };

            // Intercept until done
            btn.value = 'Submit';
            btn.type = 'button';
            btn.onclick = null;

            let round = 0;
            let processing = false;

            btn.addEventListener('click', async function handle(e) {
                // If 6 rounds completed → final submission
                if (round >= 6) {
                    console.log('Essays Master: 6 rounds complete, submitting...');
                    
                    // Restore original button
                    btn.removeEventListener('click', handle);
                    btn.value = original.value;
                    btn.type = original.type || 'submit';
                    btn.onclick = original.onclick || null;

                    panel.style.display = 'none';

                    // Submit form
                    if (btn.form) {
                        btn.form.submit();
                    } else {
                        btn.click();
                    }
                    return;
                }

                // Prevent double-click
                if (processing) { 
                    e.preventDefault(); 
                    e.stopImmediatePropagation(); 
                    return; 
                }
                
                processing = true;
                e.preventDefault();
                e.stopPropagation();

                // NEXT ROUND
                round += 1;
                console.log('Essays Master: Processing round', round);

                const isValidationRound = [2, 4, 6].includes(round);
                const isFeedbackRound = [1, 3, 5].includes(round);

                if (isFeedbackRound) {
                    // 📝 FEEDBACK ROUNDS (1, 3, 5)
                    renderRound(panel, round, null);
                    
                    // Store essay content for validation
                    essayStorage[`round${round}`] = getCurrentEssayContent();
                    console.log(`📦 Stored essay content for round ${round} validation`);
                    
                    // Get AI feedback
                    getFeedback(round, attemptId, function(success, response) {
                        if (success) {
                            renderRound(panel, round, response);
                        } else {
                            // Show error but continue
                            console.warn('AI feedback failed:', response);
                            renderRound(panel, round, { feedback: 'AI feedback temporarily unavailable. Please continue with your revisions.' });
                        }
                        
                        // 10-second amber delay for reflection
                        const buttonTexts = { 1: "Proofread", 3: "Fix your mistakes", 5: "Polish & Perfect" };
                        startAmberDelay(btn, buttonTexts[round], 10, () => {
                            processing = false;
                        });
                    });
                    
                } else if (isValidationRound) {
                    // ✅ VALIDATION ROUNDS (2, 4, 6)
                    renderRound(panel, round, null);
                    
                    const previousRound = round - 1;
                    handleValidation(panel, round, previousRound, btn, attemptId);
                    
                    processing = false;
                }
            });

            console.log('Essays Master: 6-round system active - Feedback→Validation pattern');
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }

    return { init };
});