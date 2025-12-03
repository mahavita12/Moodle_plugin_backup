// AMD module: local_essaysmaster/feedback
// ✅ 6-Round System PROPERLY Restored from Backup!
// Feedback → Validation pattern: Proofreading, Structure, Style

define([], function () {
    "use strict";

    // Store attempt id for popup usage
    let __EM_ATTEMPT_ID__ = null;

    // Create a small external-link icon button and open a popup that mirrors the panel
    function addOpenWindowButton(panel, round, attemptId) {
        try {
            if (!panel || !round) return;
            // Avoid duplicates
            if (panel.querySelector('.em-openwin')) return;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'em-openwin';
            btn.setAttribute('aria-label', 'Open in new window');
            btn.style.cssText = [
                'position:absolute','top:8px','right:8px','width:18px','height:18px',
                'border:0','background:rgba(255,255,255,0.0)','cursor:pointer','opacity:0.85',
                'padding:0','line-height:0'
            ].join(';');
            // Minimal external-link SVG icon (overlay)
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f6cbf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>';

            // New window button (shown to the left)
            const popupBtn = document.createElement('button');
            popupBtn.type = 'button';
            popupBtn.className = 'em-openpopup';
            popupBtn.setAttribute('aria-label', 'Open in new window');
            popupBtn.style.cssText = [
                'position:absolute','top:8px','right:30px','width:18px','height:18px',
                'border:0','background:rgba(255,255,255,0.0)','cursor:pointer','opacity:0.85',
                'padding:0','line-height:0'
            ].join(';');
            // Two windows icon
            popupBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0f6cbf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="12" height="12" rx="2"/><rect x="9" y="3" width="12" height="12" rx="2"/></svg>';

            btn.addEventListener('click', function(ev){
                ev.preventDefault();
                ev.stopPropagation();

				// Create an in-page floating panel (overlay) that stays above
				try { if (window.__EM_FLOAT_PANEL__ && window.__EM_FLOAT_PANEL__.parentNode) { window.__EM_FLOAT_PANEL__.parentNode.removeChild(window.__EM_FLOAT_PANEL__); } } catch(_) {}
				const rect = panel.getBoundingClientRect();
				const width = Math.max(480, Math.round(rect.width * 0.8));
				const container = document.createElement('div');
				container.id = 'em-floating-panel';
				container.style.cssText = [
					'position:fixed','top:10px','right:10px','width:'+width+'px','max-height:85vh','background:#fff',
					'border:2px solid #0f6cbf','border-radius:8px','box-shadow:0 8px 24px rgba(0,0,0,0.2)','z-index:999999',
					'display:flex','flex-direction:column','overflow:hidden','font-family: Arial, Helvetica, sans-serif','font-size:14px'
				].join(';');
				const header = document.createElement('div');
				header.style.cssText = 'cursor:move;background:#0f6cbf;color:#fff;padding:6px 10px;display:flex;justify-content:space-between;align-items:center;';
				header.innerHTML = '<span>Feedback Round ' + round + '</span>';
				const closeBtn = document.createElement('button');
				closeBtn.type = 'button';
				closeBtn.textContent = '×';
				closeBtn.style.cssText = 'background:transparent;border:0;color:#fff;font-size:18px;line-height:1;cursor:pointer';
				header.appendChild(closeBtn);
				const tools = document.createElement('div');
				tools.style.cssText = 'margin-left:auto;display:flex;gap:6px;align-items:center;';
				const maxBtn = document.createElement('button');
				maxBtn.type='button'; maxBtn.textContent='▢';
				maxBtn.title='Maximize/Restore';
				maxBtn.style.cssText='background:transparent;border:1px solid rgba(255,255,255,.7);color:#fff;font-size:12px;line-height:1;padding:2px 6px;border-radius:3px;cursor:pointer';
				header.insertBefore(tools, closeBtn);
				tools.appendChild(maxBtn);

				const body = document.createElement('div');
				body.style.cssText = 'padding:10px;overflow:auto;';
				const clone = panel.cloneNode(true);
				const existingBtn = clone.querySelector('.em-openwin'); if (existingBtn) existingBtn.remove();
				body.appendChild(clone);
				container.appendChild(header);
				container.appendChild(body);
				document.body.appendChild(container);
				window.__EM_FLOAT_PANEL__ = container;

				// Restore persisted size/position
				const skey = 'em_fp_' + (__EM_ATTEMPT_ID__ || attemptId || '0');
				(function restore(){
					try {
						const raw = sessionStorage.getItem(skey);
						if (!raw) return;
						const st = JSON.parse(raw);
						if (st && typeof st === 'object') {
							if (st.width) container.style.width = Math.max(360, parseInt(st.width)) + 'px';
							if (st.height) container.style.height = Math.max(240, parseInt(st.height)) + 'px';
							if (typeof st.top === 'number') container.style.top = st.top + 'px';
							if (typeof st.right === 'number') container.style.right = st.right + 'px';
						}
					} catch(_) {}
				})();

				function persist() {
					try {
						const st = {
							top: container.offsetTop,
							right: parseInt(container.style.right) || 10,
							width: container.offsetWidth,
							height: container.offsetHeight
						};
						sessionStorage.setItem(skey, JSON.stringify(st));
					} catch(_) {}
				}
				// Drag handling
				(function(){
					let dragging=false, sx=0, sy=0, startTop=0, startRight=0;
					header.addEventListener('mousedown', function(e){ dragging=true; sx=e.clientX; sy=e.clientY; startTop=container.offsetTop; startRight=parseInt(container.style.right)||10; e.preventDefault(); });
					document.addEventListener('mousemove', function(e){ if(!dragging) return; const dy=e.clientY-sy; const dx=e.clientX-sx; container.style.top=(startTop+dy)+'px'; container.style.right=(Math.max(0,startRight-dx))+'px'; });
					document.addEventListener('mouseup', function(){ if(dragging) persist(); dragging=false; });
				})();
				// Multi-side resize handles (corners + edges)
				function addHandle(dir, css, cursor){
					const h=document.createElement('div');
					h.style.cssText='position:absolute;'+css+';cursor:'+cursor+';';
					container.appendChild(h);

					let resizing=false, sx=0, sy=0, startW=0, startH=0, startTop=0, startRight=0;
					h.addEventListener('mousedown', function(e){
						resizing=true; sx=e.clientX; sy=e.clientY; startW=container.offsetWidth; startH=container.offsetHeight; startTop=container.offsetTop; startRight=parseInt(container.style.right)||10; e.preventDefault();
					});
					document.addEventListener('mousemove', function(e){
						if(!resizing) return;
						const dx=e.clientX-sx; const dy=e.clientY-sy;
						let w=startW, h=startH, t=startTop, r=startRight;
						if(dir.indexOf('e')!==-1){ w=Math.max(360, startW+dx); r=Math.max(0, startRight-dx); }
						if(dir.indexOf('s')!==-1){ h=Math.max(240, startH+dy); }
						if(dir.indexOf('w')!==-1){ w=Math.max(360, startW-dx); r=Math.max(0, startRight+dx); }
						if(dir.indexOf('n')!==-1){ h=Math.max(240, startH-dy); t=startTop+dy; }
						container.style.width=w+'px'; container.style.height=h+'px'; container.style.top=t+'px'; container.style.right=r+'px';
					});
					document.addEventListener('mouseup', function(){ if(resizing) persist(); resizing=false; });
				}
				// corners
				addHandle('se','width:14px;height:14px;right:2px;bottom:2px;background:linear-gradient(135deg, transparent 0 50%, rgba(15,108,191,.6) 50% 100%);border-radius:2px','nwse-resize');
				addHandle('ne','width:14px;height:14px;right:2px;top:2px;background:linear-gradient(225deg, transparent 0 50%, rgba(15,108,191,.6) 50% 100%);border-radius:2px','nesw-resize');
				addHandle('sw','width:14px;height:14px;left:2px;bottom:2px;background:linear-gradient(45deg, transparent 0 50%, rgba(15,108,191,.6) 50% 100%);border-radius:2px','nesw-resize');
				addHandle('nw','width:14px;height:14px;left:2px;top:2px;background:linear-gradient(315deg, transparent 0 50%, rgba(15,108,191,.6) 50% 100%);border-radius:2px','nwse-resize');
				// edges
				addHandle('e','width:6px;height:40px;right:-2px;top:50%;transform:translateY(-50%);background:transparent','ew-resize');
				addHandle('w','width:6px;height:40px;left:-2px;top:50%;transform:translateY(-50%);background:transparent','ew-resize');
				addHandle('n','width:40px;height:6px;left:50%;top:-2px;transform:translateX(-50%);background:transparent','ns-resize');
				addHandle('s','width:40px;height:6px;left:50%;bottom:-2px;transform:translateX(-50%);background:transparent','ns-resize');

				// Maximize/restore
				let maximized=false, prev={ top:0, right:0, width:0, height:0 };
				maxBtn.addEventListener('click', function(){
					if(!maximized){ prev.top=container.offsetTop; prev.right=parseInt(container.style.right)||10; prev.width=container.offsetWidth; prev.height=container.offsetHeight; container.style.top='10px'; container.style.right='10px'; container.style.width=Math.min(window.innerWidth-20, 1000)+'px'; container.style.height=Math.min(window.innerHeight-20, 700)+'px'; maximized=true; maxBtn.textContent='❐'; }
					else { container.style.top=prev.top+'px'; container.style.right=prev.right+'px'; container.style.width=prev.width+'px'; container.style.height=prev.height+'px'; maximized=false; maxBtn.textContent='▢'; }
					persist();
				});

				closeBtn.addEventListener('click', function(){ if (container.parentNode) container.parentNode.removeChild(container); window.__EM_FLOAT_PANEL__ = null; });
				document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && window.__EM_FLOAT_PANEL__) { try { window.__EM_FLOAT_PANEL__.parentNode.removeChild(window.__EM_FLOAT_PANEL__); } catch(_) {} window.__EM_FLOAT_PANEL__ = null; } }, { once: true });
            });

            // Open in separate window (split-screen)
            popupBtn.addEventListener('click', function(ev){
                ev.preventDefault(); ev.stopPropagation();
                const rect = panel.getBoundingClientRect();
                const width = Math.max(480, Math.round(rect.width * 0.8));
                const availH = (window.screen && (window.screen.availHeight || window.screen.height)) || 800;
                const height = Math.max(400, Math.min(availH - 20, (window.innerHeight || availH) - 10));
                const availW = (window.screen && (window.screen.availWidth || window.screen.width)) || 1200;
                const left = Math.max(0, Math.round(availW - width - 10));
                const top = 10;
                const name = 'em_fb_' + (attemptId || __EM_ATTEMPT_ID__ || 'x') + '_' + round;
                const features = 'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',scrollbars=yes,resizable=yes,noopener';
                const base = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = base + '/local/essaysmaster/view_feedback.php?attemptid=' + encodeURIComponent(attemptId || __EM_ATTEMPT_ID__ || '') + '&round=' + encodeURIComponent(round) + '&clean=1';
                const w = window.open(url, name, features);
                if (w) {
                    try { w.focus(); } catch(_) {}
                    try { window.__EM_FEEDBACK_WIN__ = w; } catch(_) {}
                    const styles = (function(){ const el = document.getElementById('essays-master-styles'); return el ? el.textContent : ''; })();
                    const clone = panel.cloneNode(true);
                    const existingBtn = clone.querySelector('.em-openwin'); if (existingBtn) existingBtn.remove();
                    const html = clone.outerHTML;
                    const payload = { type: 'em_feedback_clone', title: 'Feedback Round ' + round, styles: styles, html: html };
                    const send = () => { try { w.postMessage(payload, '*'); } catch(_){} };
                    setTimeout(send, 250);
                    const onReady = function(e){ if (e && e.data && e.data.type === 'em_feedback_ready') { send(); window.removeEventListener('message', onReady); } };
                    window.addEventListener('message', onReady);
                }
            });

            // Attach button to panel
            panel.style.position = panel.style.position || 'relative';
            panel.appendChild(popupBtn);
            panel.appendChild(btn);
        } catch (e) {
            // Non-fatal
            console.warn('Essays Master: open window button failed', e);
        }
    }

    // Page gate: only run on quiz attempt pages
    function onQuizAttemptPage() {
        return location.pathname.indexOf('/mod/quiz/attempt.php') !== -1;
    }

    // Provide titles/focus per round
    function getRoundConfig(round) {
        const map = {
            1: { type: 'feedback', title: 'Round 1: Grammar, Spelling & Punctuation', focus: 'Grammar & Spelling' },
            2: { type: 'validation', title: 'Round 2: Proofreading Validation', focus: 'Proofreading' },
            3: { type: 'feedback', title: 'Round 3: Language Enhancement', focus: 'Language Enhancement' },
            4: { type: 'validation', title: 'Round 4: Improvement Validation', focus: 'Improvements' },
            5: { type: 'feedback', title: 'Round 5: Relevance & Structure', focus: 'Relevance & Structure' },
            6: { type: 'validation', title: 'Round 6: Final Validation', focus: 'Final validation' }
        };
        return map[round] || { type: 'feedback', title: `Round ${round}`, focus: 'Essay feedback' };
    }

    // Find the "Finish/Submit" button Moodle uses on the attempt page
    function findSubmitButton() {
        // Common id
        let btn = document.getElementById('mod_quiz-next-nav');
        if (btn) {
            console.log('Essays Master: Found submit button by ID:', btn);
            // Ensure button is visible and clickable
            btn.style.display = '';
            btn.style.visibility = 'visible';
            btn.style.pointerEvents = 'auto';
            return btn;
        }

        // Fallbacks: look for submit inputs that mention "finish" or "submit"
        const inputs = document.querySelectorAll('input[type="submit"], input[type="button"], button');
        console.log('Essays Master: Searching through', inputs.length, 'potential buttons');
        for (const el of inputs) {
            const v = (el.value || el.textContent || '').toLowerCase();
            console.log('Essays Master: Checking button:', el, 'value/text:', v);
            if (v.includes('finish') || v.includes('submit')) {
                console.log('Essays Master: Found submit button by content:', el);
                // Ensure button is visible and clickable
                el.style.display = '';
                el.style.visibility = 'visible';
                el.style.pointerEvents = 'auto';
                return el;
            }
        }
        console.log('Essays Master: No submit button found');
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

    // Enforce no-copy context and disable selection on a given element
    function hardenReadOnlyContainer(element) {
        if (!element) return;
        element.setAttribute('oncopy', 'return false');
        element.setAttribute('oncut', 'return false');
        element.setAttribute('onpaste', 'return false');
        element.setAttribute('oncontextmenu', 'return false');
        element.style.userSelect = 'none';
        element.style.webkitUserSelect = 'none';
        element.style.msUserSelect = 'none';

        ['copy', 'cut', 'paste', 'contextmenu', 'dragstart', 'selectstart'].forEach(evt => {
            element.addEventListener(evt, function(e) { e.preventDefault(); e.stopPropagation(); }, { passive: false });
        });
    }

    // ENHANCED: Comprehensive spellcheck and auto-features disabling
    function disableSpellcheckEverywhere() {
        console.log('🚫 Essays Master: Disabling spellcheck and auto-features...');
        
        // 1. Raw textareas - Enhanced targeting
        const textareaSelectors = [
            'textarea',
            'textarea[name*="answer"]', 
            'textarea[name*="response"]',
            'textarea[name*="essay"]',
            '#essay-text',
            '.que.essay textarea',
            '.formulation textarea',
            'div[data-fieldtype="textarea"] textarea'
        ];
        
        textareaSelectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(ta => {
                try {
                    // Core spellcheck attributes
                    ta.setAttribute('spellcheck', 'false');
                    ta.setAttribute('data-spellcheck', 'false');
                    ta.spellcheck = false;
                    
                    // Autocomplete/autocorrect
                    ta.setAttribute('autocomplete', 'off');
                    ta.setAttribute('autocorrect', 'off');
                    ta.setAttribute('autocapitalize', 'off');
                    ta.setAttribute('data-autocorrect', 'off');
                    ta.setAttribute('data-autocomplete', 'off');
                    
                    // Grammar checkers
                    ta.setAttribute('data-gramm', 'false');
                    ta.setAttribute('data-gramm_editor', 'false');
                    ta.setAttribute('data-enable-grammarly', 'false');
                    
                    // Browser-specific
                    ta.style.caretColor = '#000';
                    ta.style.webkitUserModify = 'read-write-plaintext-only';
                    
                    console.log('✅ Disabled spellcheck on textarea:', ta.name || ta.id || 'unnamed');
                } catch (e) {
                    console.warn('⚠️ Failed to disable spellcheck on textarea:', e);
                }
            });
        });

        // 2. TinyMCE - ULTRA AGGRESSIVE for student attempts
        const disableTinyMCE = () => {
            // Method 1: Direct iframe targeting (student attempts use iframes)
            const disableInIframes = () => {
                const iframeSelectors = [
                    'iframe',
                    'iframe[id*="answer"]',
                    'iframe[id*="essay"]',
                    'iframe.tox-edit-area__iframe',
                    '.editor_tinymce iframe',
                    '.que.essay iframe',
                    'div[id*="answer"] iframe',
                    'div[id*="essay"] iframe'
                ];
                
                iframeSelectors.forEach(selector => {
                    document.querySelectorAll(selector).forEach(iframe => {
                        try {
                            const iframeDoc = iframe.contentDocument || iframe.contentWindow?.document;
                            if (iframeDoc && iframeDoc.body) {
                                // Aggressive spellcheck disabling
                                iframeDoc.body.setAttribute('spellcheck', 'false');
                                iframeDoc.body.setAttribute('data-gramm', 'false');
                                iframeDoc.body.setAttribute('data-enable-grammarly', 'false');
                                iframeDoc.body.spellcheck = false;
                                iframeDoc.body.style.spellcheck = 'false';
                                
                                // Add CSS to iframe to hide spellcheck underlines
                                if (!iframeDoc.getElementById('em-no-spellcheck')) {
                                    const style = iframeDoc.createElement('style');
                                    style.id = 'em-no-spellcheck';
                                    style.textContent = `
                                        body {
                                            -webkit-spellcheck: false !important;
                                            -moz-spellcheck: false !important;
                                            spellcheck: false !important;
                                        }
                                        body * {
                                            -webkit-spellcheck: false !important;
                                            -moz-spellcheck: false !important;
                                            spellcheck: false !important;
                                        }
                                        /* Hide any red underlines */
                                        body, body * {
                                            text-decoration: none !important;
                                            text-decoration-line: none !important;
                                            text-decoration-color: transparent !important;
                                        }
                                    `;
                                    iframeDoc.head.appendChild(style);
                                }
                                
                                // Inject JavaScript into iframe to continuously disable spellcheck
                                if (!iframeDoc.getElementById('em-no-spellcheck-script')) {
                                    const script = iframeDoc.createElement('script');
                                    script.id = 'em-no-spellcheck-script';
                                    script.textContent = `
                                        (function() {
                                            // Override setAttribute to prevent re-enabling spellcheck
                                            const originalSetAttribute = Element.prototype.setAttribute;
                                            Element.prototype.setAttribute = function(name, value) {
                                                if (name === 'spellcheck' && value !== 'false') {
                                                    return originalSetAttribute.call(this, 'spellcheck', 'false');
                                                }
                                                return originalSetAttribute.call(this, name, value);
                                            };
                                            
                                            // Continuously enforce spellcheck off
                                            setInterval(() => {
                                                document.body.setAttribute('spellcheck', 'false');
                                                document.body.spellcheck = false;
                                                document.querySelectorAll('*').forEach(el => {
                                                    if (el.hasAttribute('spellcheck') && el.getAttribute('spellcheck') !== 'false') {
                                                        el.setAttribute('spellcheck', 'false');
                                                    }
                                                });
                                            }, 100);
                                        })();
                                    `;
                                    iframeDoc.head.appendChild(script);
                                }
                                
                                // Also disable on all paragraphs/divs in the iframe
                                iframeDoc.querySelectorAll('p, div, span').forEach(el => {
                                    el.setAttribute('spellcheck', 'false');
                                    el.spellcheck = false;
                                });
                                
                                console.log('✅ Aggressively disabled spellcheck in iframe:', iframe.id || 'unnamed');
                            }
                        } catch (e) {
                            console.warn('⚠️ Cannot access iframe:', e);
                        }
                    });
                });
            };
            
            // Run immediately and repeatedly
            disableInIframes();
            
            // Method 2: TinyMCE API (if available)
            if (window.tinyMCE || window.tinymce) {
                const tmce = window.tinyMCE || window.tinymce;
                try {
                    // Method 2a: Existing editors
                    tmce.editors?.forEach?.(ed => {
                        if (!ed) return;
                        
                        try {
                            // Disable spellcheck on editor body
                            if (typeof ed.getBody === 'function') {
                                const body = ed.getBody();
                                if (body) {
                                    body.setAttribute('spellcheck', 'false');
                                    body.setAttribute('data-gramm', 'false');
                                    body.setAttribute('data-enable-grammarly', 'false');
                                    body.spellcheck = false;
                                    console.log('✅ Disabled spellcheck on TinyMCE editor:', ed.id);
                                }
                            }
                            
                            // Disable spellcheck plugin
                            if (ed.plugins && ed.plugins.spellchecker) {
                                ed.plugins.spellchecker.disable?.();
                            }
                            
                            // Set editor settings
                            if (ed.settings) {
                                ed.settings.browser_spellcheck = false;
                                ed.settings.gecko_spellcheck = false;
                            }
                        } catch (e) {
                            console.warn('⚠️ Failed to disable spellcheck on TinyMCE editor:', e);
                        }
                    });
                    
                    // Method 2b: Global TinyMCE settings for future editors
                    if (tmce.settings) {
                        tmce.settings.browser_spellcheck = false;
                        tmce.settings.gecko_spellcheck = false;
                    }
                    
                } catch (e) {
                    console.warn('⚠️ TinyMCE spellcheck disable failed:', e);
                }

                // Enforcement loop: student editors may (re)enable after init
                try {
                    if (!window.__EM_TINYMCE_ENFORCER__) {
                        let attempts = 0;
                        const enforce = () => {
                            attempts++;
                            try {
                                // Re-run iframe disabling
                                disableInIframes();
                                
                                // Also use TinyMCE API
                                tmce.editors?.forEach?.(ed => {
                                    try {
                                        const body = typeof ed.getBody === 'function' ? ed.getBody() : null;
                                        if (body) {
                                            body.setAttribute('spellcheck', 'false');
                                            body.setAttribute('data-gramm', 'false');
                                            body.setAttribute('data-enable-grammarly', 'false');
                                            body.spellcheck = false;
                                        }
                                    } catch (_) {}
                                });
                            } catch (_) {}
                            if (attempts >= 30) { // ~15 seconds at 500ms
                                clearInterval(window.__EM_TINYMCE_ENFORCER__);
                                window.__EM_TINYMCE_ENFORCER__ = null;
                            }
                        };
                        window.__EM_TINYMCE_ENFORCER__ = setInterval(enforce, 500);
                    }
                } catch (_) {}
            } else {
                // Even if TinyMCE API not available, still run iframe targeting
                if (!window.__EM_IFRAME_ENFORCER__) {
                    let attempts = 0;
                    const enforceIframes = () => {
                        attempts++;
                        disableInIframes();
                        if (attempts >= 30) {
                            clearInterval(window.__EM_IFRAME_ENFORCER__);
                            window.__EM_IFRAME_ENFORCER__ = null;
                        }
                    };
                    window.__EM_IFRAME_ENFORCER__ = setInterval(enforceIframes, 500);
                }
            }
        };
        
        disableTinyMCE();
        // Re-run after delay for dynamically loaded editors
        setTimeout(disableTinyMCE, 1000);
        setTimeout(disableTinyMCE, 3000);

        // 3. Atto editor - Enhanced
        const disableAtto = () => {
            try {
                const attoSelectors = [
                    'iframe[id*="atto"]',
                    '.editor_atto iframe',
                    'div[data-fieldtype="editor"] iframe'
                ];
                
                attoSelectors.forEach(selector => {
                    document.querySelectorAll(selector).forEach(ifr => {
                        try {
                            const doc = ifr.contentDocument || ifr.contentWindow?.document;
                            const body = doc?.body;
                            if (body) {
                                body.setAttribute('spellcheck', 'false');
                                body.setAttribute('data-gramm', 'false');
                                body.setAttribute('data-enable-grammarly', 'false');
                                body.spellcheck = false;
                                console.log('✅ Disabled spellcheck on Atto editor iframe');
                            }
                        } catch (e) {
                            console.warn('⚠️ Failed to access Atto iframe:', e);
                        }
                    });
                });
            } catch (e) {
                console.warn('⚠️ Atto spellcheck disable failed:', e);
            }
        };
        
        disableAtto();
        setTimeout(disableAtto, 1000);

        // 4. ContentEditable elements
        document.querySelectorAll('[contenteditable="true"], [contenteditable=""]').forEach(el => {
            try {
                el.setAttribute('spellcheck', 'false');
                el.setAttribute('data-gramm', 'false');
                el.spellcheck = false;
                console.log('✅ Disabled spellcheck on contenteditable element');
            } catch (e) {}
        });

        // 5. Add global CSS to override any remaining spellcheck
        if (!document.getElementById('essays-master-spellcheck-override')) {
            const style = document.createElement('style');
            style.id = 'essays-master-spellcheck-override';
            style.textContent = `
                /* Force disable spellcheck on all form elements */
                textarea, input[type="text"], [contenteditable] {
                    -webkit-spellcheck: false !important;
                    -moz-spellcheck: false !important;
                    spellcheck: false !important;
                }
                
                /* Hide browser spellcheck underlines */
                textarea::-webkit-input-placeholder,
                input::-webkit-input-placeholder,
                [contenteditable]::-webkit-input-placeholder {
                    -webkit-text-decoration-line: none !important;
                }
                
                /* Disable Grammarly and other extensions */
                textarea[data-gramm="false"],
                [contenteditable][data-gramm="false"] {
                    background-image: none !important;
                }
            `;
            document.head.appendChild(style);
        }
        
        console.log('✅ Essays Master: Spellcheck disabling complete');

        // Resiliency: re-apply when late editors load (student attempts often load editors later)
        if (!window.__EM_SPELLCHECK_OBSERVER__) {
            const reapply = () => {
                try {
                    // Re-run textareas
                    textareaSelectors.forEach(selector => {
                        document.querySelectorAll(selector).forEach(ta => {
                            try {
                                ta.setAttribute('spellcheck', 'false');
                                ta.setAttribute('data-spellcheck', 'false');
                                ta.spellcheck = false;
                                ta.setAttribute('autocomplete', 'off');
                                ta.setAttribute('autocorrect', 'off');
                                ta.setAttribute('autocapitalize', 'off');
                                ta.setAttribute('data-autocorrect', 'off');
                                ta.setAttribute('data-autocomplete', 'off');
                                ta.setAttribute('data-gramm', 'false');
                                ta.setAttribute('data-gramm_editor', 'false');
                                ta.setAttribute('data-enable-grammarly', 'false');
                            } catch (_) {}
                        });
                    });
                    // Re-run editors
                    disableTinyMCE();
                    disableAtto();
                } catch (_) {}
            };

            // Observe DOM changes for dynamically injected editors
            try {
                const observer = new MutationObserver(() => reapply());
                observer.observe(document.body, { childList: true, subtree: true });
                window.__EM_SPELLCHECK_OBSERVER__ = observer;
            } catch (e) {}

            // Re-apply on focus and after short delays
            document.addEventListener('focusin', (e) => {
                if (e.target && (e.target.matches('textarea, [contenteditable]') || e.target.closest('iframe'))) {
                    reapply();
                }
            }, true);

            [1000, 2000, 4000, 8000].forEach(t => setTimeout(reapply, t));
        }
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

        // SYNC EDITORS FIRST
        // 1. Atto
        if (typeof Y !== 'undefined' && Y.M && Y.M.editor_atto) {
            try {
                Y.all('.editor_atto_content').each(function(node) {
                    var elementid = node.getAttribute('id');
                    if (elementid && Y.M.editor_atto.get_editor(elementid)) {
                        console.log('🔍 DEBUG: Syncing Atto editor:', elementid);
                        Y.M.editor_atto.get_editor(elementid).save();
                    }
                });
            } catch (e) {
                console.warn('🔍 DEBUG: Atto sync failed:', e);
            }
        }
        
        // 2. TinyMCE
        if (typeof tinyMCE !== 'undefined') {
            try {
                tinyMCE.triggerSave();
                console.log('🔍 DEBUG: Synced TinyMCE editors');
            } catch (e) {
                console.warn('🔍 DEBUG: TinyMCE sync failed:', e);
            }
        }
        
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

        if (round === 1 && formatted.length) {
            const last = formatted[formatted.length - 1];
            last.extraClass = (last.extraClass || '') + ' feedback-line-emphasis';
        }

        return formatted.map((item, index) => {
            let extraClass = item.extraClass || '';
            if (!extraClass && round === 1) {
                if (index === 1) {
                    extraClass = ' feedback-line-warning';
                } else if (index === 2) {
                    extraClass = ' feedback-line-emphasis';
                }
            }
            if (extraClass.includes('feedback-line-emphasis')) {
                return `<div class="essay-emphasis-box">${item.text}</div>`;
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
            /* Blue emphasis box for the last sentence of round 1 */
            .essay-emphasis-box {
                background: #e7f3ff;
                border-left: 4px solid #0f6cbf;
                border-radius: 4px;
                padding: 10px 12px;
                color: #0f3c75;
                font-weight: 600;
                margin-top: 8px;
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

            /* FIXED: Non-selectable rendered feedback - only disable selection, not pointer events */
            #essays-master-feedback,
            #essays-master-feedback .em-nonselectable,
            .ai-feedback-content,
            .improvements-section {
                -webkit-user-select: none !important;
                -moz-user-select: none !important;
                -ms-user-select: none !important;
                user-select: none !important;
                -webkit-touch-callout: none !important;
                -webkit-tap-highlight-color: transparent !important;
                /* REMOVED: pointer-events: none !important; - This was blocking button clicks */
            }
            
            /* Apply to most child elements but EXCLUDE buttons, inputs, and interactive elements */
            #essays-master-feedback *:not(button):not(input):not(select):not(textarea):not(a):not([role="button"]),
            #essays-master-feedback .em-nonselectable *:not(button):not(input):not(select):not(textarea):not(a):not([role="button"]),
            .ai-feedback-content *:not(button):not(input):not(select):not(textarea):not(a):not([role="button"]),
            .improvements-section *:not(button):not(input):not(select):not(textarea):not(a):not([role="button"]) {
                -webkit-user-select: none !important;
                -moz-user-select: none !important;
                -ms-user-select: none !important;
                user-select: none !important;
                -webkit-touch-callout: none !important;
                -webkit-tap-highlight-color: transparent !important;
            }
            
            /* Explicitly ensure buttons and interactive elements work */
            #essays-master-feedback button,
            #essays-master-feedback input,
            #essays-master-feedback select,
            #essays-master-feedback textarea,
            #essays-master-feedback a,
            #essays-master-feedback [role="button"],
            button[id*="quiz"],
            input[type="submit"],
            input[type="button"] {
                pointer-events: auto !important;
                -webkit-user-select: auto !important;
                -moz-user-select: auto !important;
                user-select: auto !important;
            }
            
            /* Keep feedback panel interactive for scrolling and button clicks */
            #essays-master-feedback {
                pointer-events: auto !important;
                -webkit-user-select: none !important;
                -moz-user-select: none !important;
                user-select: none !important;
            }
            
            /* Prevent text highlighting with pseudo-elements */
            #essays-master-feedback::selection,
            #essays-master-feedback *::selection {
                background: transparent !important;
            }
            
            #essays-master-feedback::-moz-selection,
            #essays-master-feedback *::-moz-selection {
                background: transparent !important;
            }
            
            /* Hide cursor when hovering over feedback content */
            .ai-feedback-content,
            .improvements-section {
                cursor: default !important;
            }
            
            /* Prevent drag operations */
            #essays-master-feedback img,
            #essays-master-feedback canvas {
                -webkit-user-drag: none !important;
                -khtml-user-drag: none !important;
                -moz-user-drag: none !important;
                -o-user-drag: none !important;
                user-drag: none !important;
                pointer-events: none !important;
            }
        `;
        document.head.appendChild(style);
    }

    // ENHANCED: Install comprehensive capture-phase guards for copy/context menu within panel
    function installCaptureGuards(panel) {
        if (!panel) return;
        if (window.__EM_CAPTURE_GUARDS__) return; // one-time per page
        window.__EM_CAPTURE_GUARDS__ = true;

        console.log('🛡️ Essays Master: Installing enhanced copy protection...');

        const isInsidePanel = (target) => !!panel && panel.contains(target);

        const blockIfInside = (e) => {
            if (isInsidePanel(e.target)) {
                // Allow harmless events; block only copy-related and contextmenu
                const t = e.type;
                if (t === 'copy' || t === 'cut' || t === 'paste' || t === 'contextmenu' || t === 'dragstart' || t === 'selectstart') {
                    console.log('🚫 Blocked action:', e.type, 'on', e.target.tagName);
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }
            }
        };

        // FIXED: More selective event blocking - allow normal clicks but block copy operations
        const eventsToBlock = [
            'copy', 'cut', 'paste', 'contextmenu', 'dragstart', 'selectstart', 'select'
        ];

        // Capture-phase listeners on document (highest priority)
        eventsToBlock.forEach(evt => {
            document.addEventListener(evt, blockIfInside, true);
        });

        // Selective mouse event blocking - only block right/middle clicks
        const mouseGuard = (e) => {
            if (!isInsidePanel(e.target)) return;
            
            // Block right-click (context menu) and middle-click
            if (e.button === 2 || e.which === 3 || e.button === 1 || e.which === 2) {
                console.log('🚫 Blocked mouse action:', e.type, 'button:', e.button);
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                return false;
            }
            // Allow normal left clicks (button 0) - needed for Submit button
        };

        // Add selective mouse guards
        document.addEventListener('mousedown', mouseGuard, true);
        document.addEventListener('mouseup', mouseGuard, true);
        document.addEventListener('auxclick', mouseGuard, true);

        // Keyboard shortcuts blocking
        const keyboardGuard = (e) => {
            if (!isInsidePanel(e.target)) return;
            
            // Block common copy/paste shortcuts
            const blockedKeys = [
                'KeyC', 'KeyV', 'KeyX', 'KeyA', 'KeyS', 'KeyP', 'F12'
            ];
            
            const isModified = e.ctrlKey || e.metaKey || e.altKey;
            
            if (isModified && blockedKeys.includes(e.code)) {
                console.log('🚫 Blocked keyboard shortcut:', e.code, 'with modifier');
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                return false;
            }
            
            // Block F12 (dev tools)
            if (e.code === 'F12') {
                console.log('🚫 Blocked F12 (dev tools)');
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        };
        
        document.addEventListener('keydown', keyboardGuard, true);
        document.addEventListener('keyup', keyboardGuard, true);

        // Touch event blocking for mobile
        const touchGuard = (e) => {
            if (!isInsidePanel(e.target)) return;
            
            // Block long press (context menu on mobile)
            if (e.touches && e.touches.length > 1) {
                console.log('🚫 Blocked multi-touch gesture');
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        };
        
        document.addEventListener('touchstart', touchGuard, true);
        document.addEventListener('touchend', touchGuard, true);

        // Selection blocking
        const selectionGuard = () => {
            if (window.getSelection) {
                const selection = window.getSelection();
                if (selection.rangeCount > 0) {
                    const range = selection.getRangeAt(0);
                    if (range && panel.contains(range.commonAncestorContainer)) {
                        console.log('🚫 Cleared text selection in feedback panel');
                        selection.removeAllRanges();
                    }
                }
            }
        };
        
        // Clear selection periodically
        setInterval(selectionGuard, 500);
        
        // Clear selection on any interaction
        document.addEventListener('selectionchange', selectionGuard, true);

        console.log('✅ Essays Master: Enhanced copy protection installed');
    }

    // Render feedback inner content as a non-selectable canvas
    function rasterizeFeedbackToCanvas(panel) {
        if (!panel) return;
        const container = panel.querySelector('#em-feedback-render');
        if (!container) return;

        const text = (container.innerText || '').trim();
        if (!text) return;

        // Compute canvas size
        const padding = 16;
        const maxWidth = Math.max(480, Math.min(panel.clientWidth - 20, 900));
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const font = '14px \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif';
        const lineHeight = 22;

        // Create a measurement canvas
        const measure = document.createElement('canvas');
        const mctx = measure.getContext('2d');
        mctx.font = font;

        // Word wrap
        const words = text.split(/\s+/);
        const lines = [];
        let current = '';
        const available = maxWidth - padding * 2;
        words.forEach(word => {
            const test = current ? current + ' ' + word : word;
            const w = mctx.measureText(test).width;
            if (w > available && current) {
                lines.push(current);
                current = word;
            } else {
                current = test;
            }
        });
        if (current) lines.push(current);

        const height = padding * 2 + lines.length * lineHeight;

        // Draw to canvas
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(maxWidth * dpr);
        canvas.height = Math.ceil(height * dpr);
        canvas.style.width = maxWidth + 'px';
        canvas.style.height = height + 'px';

        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, maxWidth, height);
        ctx.strokeStyle = '#e9ecef';
        ctx.strokeRect(0.5, 0.5, maxWidth - 1, height - 1);
        ctx.fillStyle = '#212529';
        ctx.font = font;
        let y = padding + 4;
        lines.forEach(line => {
            y += lineHeight;
            ctx.fillText(line, padding, y);
        });

        // Replace content with canvas (non-selectable)
        container.classList.add('em-nonselectable');
        container.innerHTML = '';
        container.appendChild(canvas);
    }

    // 🟡 Find and highlight problematic text in essay
    function highlightProblematicText(essayText, improvements, highlightClass = 'amber-highlight', round) {
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
                    const tooltip = (round >= 2 && round <= 4)
                        ? `${improvement.improved}`
                        : `Suggested improvement: ${improvement.improved}`;
                    return `<span class="${highlightClass}" title="${tooltip}">${match}</span>`;
                });
            }
        });
        
        return highlightedText;
    }

    // 🟡 Create essay display with highlighting
    function createEssayDisplay(essayText, improvements, round) {
        const highlightClass = 'amber-highlight';
        const highlightedText = highlightProblematicText(essayText, improvements, highlightClass, round);
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
    function renderRound(panel, round, feedback, isLoading = false) {
        panel.style.display = 'block';
        addImprovementStyles();
        
        const config = getRoundConfig(round);
        
        let content = `<h3 style="color:#0f6cbf;margin-top:0;font-size:18px;">${config.title}</h3>`;
        content += `<div id="em-feedback-render">`;

        // ✅ LOADING STATE: Show analyzing message if loading
        if (isLoading) {
            content += `<div class="ai-feedback-content">
                <div class="feedback-text">GrowMinds Academy is analyzing your essay for ${config.focus.toLowerCase()}...</div>
            </div>`;
            content += `</div>`;
            panel.innerHTML = content;
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

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
                
                // 2. Original->improved examples second
                let improvements = feedback.improvements;
                if (!improvements && improvementSection) {
                    // Fallback: Parse improvements from text if not provided in object
                    improvements = parseImprovements(improvementSection);
                }
                
                if (improvements && improvements.length > 0) {
                    content += formatImprovements(improvements);
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
            let parsed = null;
            if (feedback && feedback.feedback) {
                console.log('Essays Master: Rendering validation round', round);
                // Parse validation response
                const cleanedResponse = cleanAIResponse(feedback.feedback);
                parsed = parseAIValidationResponse(cleanedResponse);
                console.log('Essays Master: Parsed validation:', parsed);
            }

            if (parsed) {
                content += `<div class="ai-feedback-content">`;
                
                // Score Section
                const scoreColor = parsed.passed ? '#28a745' : '#dc3545';
                const statusText = parsed.passed ? 'Passed' : 'Failed';
                content += `
                    <div class="validation-score" style="border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:10px;">
                        <strong>Validation Score: <span style="color:${scoreColor}">${parsed.score}/100 ${statusText}</span></strong>
                    </div>
                `;
                
                // Analysis Section
                if (parsed.analysis) {
                    content += `
                        <div class="validation-analysis" style="margin-bottom:10px;font-style:italic;color:#666;">
                            ${parsed.analysis}
                        </div>
                    `;
                }
                
                // Feedback Section
                // Extract main feedback text (everything before improvements)
                const feedbackLines = parsed.feedback.split('\n');
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

                const feedbackColor = parsed.passed ? '#28a745' : '#dc3545';
                content += `<div class="feedback-text" style="color:${feedbackColor};">${formatFeedbackParagraphs(mainFeedback, round)}</div>`;
                
                // Improvements Section
                let improvements = parsed.improvements;
                if ((!improvements || improvements.length === 0) && improvementSection) {
                     improvements = parseImprovements(improvementSection);
                }
                
                if (improvements && improvements.length > 0) {
                    content += formatImprovements(improvements);
                }
                
                content += `</div>`;
            } else {
                // RESUME STATE: No feedback found OR feedback was malformed (parsed is null)
                // This handles both "missing data" and "corrupted data" scenarios gracefully.
                
                content += `<div class="ai-feedback-content">
                    <div class="feedback-text" style="text-align:center; padding: 20px;">
                        <p><strong>Welcome back!</strong></p>
                        <p>To view your feedback for Round ${round}, please click the button above.</p>
                    </div>
                </div>`;
            }
        }

        content += `
            <div style="text-align:center;margin-top:15px;padding:10px;background:#e7f3ff;border-radius:4px;">
                <strong>Round ${round} of 6</strong>
            </div>
        `;
        content += `</div>`;
        panel.innerHTML = content;
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        // Apply capture guards; only rasterize for validation rounds so feedback keeps CSS styling
        installCaptureGuards(panel);
        if (config.type === 'validation') {
            rasterizeFeedbackToCanvas(panel);
        }
        // Show open-in-window only after real feedback exists for feedback rounds
        if (config.type === 'feedback' && feedback && (feedback.feedback || feedback.improvements)) {
            addOpenWindowButton(panel, round, __EM_ATTEMPT_ID__);
        }
    }

    // Parse AI validation response with scoring
    function parseAIValidationResponse(aiResponse) {
        // Normalize text
        const text = aiResponse.trim();
        
        // 1. Extract Score - CRITICAL: If no score, treat as malformed/missing
        // Matches: "Score: 85", "Validation Score: 85/100", "Score: 85/100"
        const scoreMatch = text.match(/(?:Validation )?Score:\s*(\d+)/i);
        if (!scoreMatch) {
            // If we can't even find a score, this is likely garbage or raw text dump.
            // Return null to trigger the "Welcome back" fallback.
            return null;
        }
        
        let score = parseInt(scoreMatch[1]);
        
        // 2. Extract Status
        // Matches: "Status: PASS", "Status: FAIL", or infers from score
        let status = 'FAIL';
        const statusMatch = text.match(/Status:\s*(PASS|FAIL)/i);
        if (statusMatch) {
            status = statusMatch[1].toUpperCase();
        } else {
            // Infer status if not explicit
            status = (score >= 50) ? 'PASS' : 'FAIL';
        }
        
        // 3. Extract Analysis/Feedback
        // If we have "Analysis:" and "Feedback:" sections, use them
        let analysis = '';
        let feedback = '';
        
        const analysisMatch = text.match(/Analysis:\s*([\s\S]*?)(?=Feedback:|$)/i);
        const feedbackMatch = text.match(/Feedback:\s*([\s\S]*)/i);
        
        if (analysisMatch) {
            analysis = analysisMatch[1].trim();
        }
        if (feedbackMatch) {
            feedback = feedbackMatch[1].trim();
        } else if (!analysisMatch) {
            // Fallback: strip headers
            analysis = text.replace(/^(?:Validation )?Score:.*?(?:Failed|Passed)?\s*/i, '')
                           .replace(/^Status:.*?\s*/i, '')
                           .trim();
        }
        
        return {
            score: score,
            passed: status === 'PASS' || score >= 50,
            analysis: analysis,
            feedback: feedback || analysis, // Fallback
            improvements: parseImprovements(feedback || analysis)
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
                
                if (!parsed) {
                    throw new Error('Could not parse AI validation response');
                }
                
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
                    validationContent += `<div class="feedback-text" style="color:#dc3545;">${mainFeedback.replace(/\n/g, '<br>')}</div>`;
                    
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
                        2: "Fix your mistake",
                        4: "Use better expression", 
                        6: "Polish & Perfect"
                    };
                    
                    const buttonDelayValidation = {
                        2: 30,
                        4: 30,
                        6: 10
                    };
                    
                    startAmberDelay(btn, buttonTexts[round], buttonDelayValidation[round] || 30, () => {
                        // Button ready for retry
                    });
                }
                
                validationContent += `
                    <div style="text-align:center;margin-top:15px;padding:10px;background:#e7f3ff;border-radius:4px;">
                        <strong>Round ${round} of 6 - Validation ${result.success ? 'Passed' : 'Failed'}</strong>
                    </div>
                `;
                
                panel.innerHTML = validationContent;
                // After validation content is set, add the open-in-window button
                addOpenWindowButton(panel, round, __EM_ATTEMPT_ID__);
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
        __EM_ATTEMPT_ID__ = attemptId;
        
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
            // Harden panel against copying and right-click
            hardenReadOnlyContainer(panel);

            // Enforce spellcheck off in all editors/inputs relevant to essay
            disableSpellcheckEverywhere();

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

            // RESUME PROGRESS: Fetch current state from server
            try {
                const sesskey = M.cfg.sesskey;
                console.log('Essays Master: About to fetch state. Attempt:', attemptId, 'Sesskey:', sesskey);
                
                // Add timestamp to prevent caching of state response
                const stateUrl = M.cfg.wwwroot + '/local/essaysmaster/get_feedback.php?attemptid=' + attemptId + '&round=0&sesskey=' + sesskey + '&action=get_state&_t=' + new Date().getTime();
                console.log('Essays Master: State URL:', stateUrl);
                
                fetch(stateUrl)
                    .then(r => r.json())
                    .then(data => {
                        console.log('Essays Master: Full resume data:', data);
                        if (data.debug) {
                            console.log('Essays Master: Server Debug Info:', JSON.stringify(data.debug, null, 2));
                        }
                        if (data.success && data.current_level > 0) {
                            console.log('Essays Master: Resuming from round', data.current_level);
                            round = data.current_level;
                            
                            // \u2705 RESUME FIX: Render previous feedback if available
                            // ✅ RESUME FIX: Render previous feedback OR show guidance
                            console.log('Essays Master: Resuming round state');
                            renderRound(panel, round, data.feedback);
                            
                            // Update button text based on round
                            const buttonTexts = { 1: "Proofread", 3: "Use better expression", 5: "Polish & Perfect" };
                            
                            // CRITICAL FIX: If resuming at a validation round (2, 4, 6), we must decrement round
                            // so that the "Next" click triggers the validation for THIS round, not the next one.
                            if ([2, 4, 6].includes(round)) {
                                console.log('Essays Master: Resuming at validation round, rewinding counter to trigger correct action');
                                round = round - 1;
                                // Set button text for the UPCOMING validation action
                                if (buttonTexts[round]) {
                                    btn.value = buttonTexts[round];
                                } else if (round >= 5) {
                                     btn.value = "Polish & Perfect"; // Round 5->6
                                }
                            } else {
                                // Feedback rounds (1, 3, 5)
                                if (buttonTexts[round + 1]) {
                                    btn.value = buttonTexts[round + 1];
                                } else if (round >= 6) {
                                    btn.value = "Submit Final Essay";
                                }
                            }
                            
                            // If completed, maybe show final submission state immediately?
                            if (data.status === 'completed' || round >= 6) {
                                btn.value = "Submit Final Essay";
                            }
                        }
                    })
                    .catch(e => console.warn('Essays Master: Failed to resume progress', e));
            } catch (e) {
                console.warn('Essays Master: Resume logic error', e);
            }

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
                // Close previously opened popup when advancing to the next round
                try { if (window.__EM_FEEDBACK_WIN__ && !window.__EM_FEEDBACK_WIN__.closed) { window.__EM_FEEDBACK_WIN__.close(); } } catch(_) {}
                try { window.__EM_FEEDBACK_WIN__ = null; } catch(_) {}
                e.preventDefault();
                e.stopPropagation();

                // NEXT ROUND
                round += 1;
                console.log('Essays Master: Processing round', round);

                // Close overlay and popup defensively on round change
                try { if (window.__EM_FLOAT_PANEL__ && window.__EM_FLOAT_PANEL__.parentNode) { window.__EM_FLOAT_PANEL__.parentNode.removeChild(window.__EM_FLOAT_PANEL__); } } catch(_) {}
                try { if (window.__EM_FEEDBACK_WIN__ && !window.__EM_FEEDBACK_WIN__.closed) { window.__EM_FEEDBACK_WIN__.close(); } } catch(_) {}

                const isValidationRound = [2, 4, 6].includes(round);
                const isFeedbackRound = [1, 3, 5].includes(round);

                if (isFeedbackRound) {
                    // 📝 FEEDBACK ROUNDS (1, 3, 5)
                    renderRound(panel, round, null, true); // Set isLoading=true
                    
                    // Store essay content for validation
                    essayStorage[`round${round}`] = getCurrentEssayContent();
                    console.log(`📦 Stored essay content for round ${round} validation`);
                    
                    // Re-apply protections in case DOM changed
                    hardenReadOnlyContainer(panel);
                    disableSpellcheckEverywhere();

                    // Get AI feedback
                    getFeedback(round, attemptId, function(success, response) {
                        if (success) {
                            renderRound(panel, round, response);
                        } else {
                            // Show error but continue
                            console.warn('AI feedback failed:', response);
                            renderRound(panel, round, { feedback: 'GrowMinds Academy feedback temporarily unavailable. Please continue with your revisions.' });
                        }
                        
                        // 10-second amber delay for reflection
                        const buttonTexts = { 1: "Proofread", 3: "Use better expression", 5: "Polish & Perfect" };
                        const buttonDelays = { 1: 60, 3: 20, 5: 20 };
                        startAmberDelay(btn, buttonTexts[round], buttonDelays[round] || 10, () => {
                            processing = false;
                            // Re-apply after delay to keep protections
                            hardenReadOnlyContainer(panel);
                            disableSpellcheckEverywhere();
                        });
                    });
                    
                } else if (isValidationRound) {
                    console.log('Essays Master: Starting validation round', round);
                    renderRound(panel, round, null, true); // Set isLoading=true
                    
                    const previousRound = round - 1;
                    handleValidation(panel, round, previousRound, btn, attemptId);
                    hardenReadOnlyContainer(panel);
                    disableSpellcheckEverywhere();
                    
                    processing = false;
                }
            });

            console.log('Essays Master: 6-round system active - Feedback→Validation pattern');
        };

        // Defensive: close overlay/popup when page is unloading (navigation/form submit)
        try {
            window.addEventListener('beforeunload', function(){
                try { if (window.__EM_FLOAT_PANEL__ && window.__EM_FLOAT_PANEL__.parentNode) { window.__EM_FLOAT_PANEL__.parentNode.removeChild(window.__EM_FLOAT_PANEL__); } } catch(_) {}
                try { if (window.__EM_FEEDBACK_WIN__ && !window.__EM_FEEDBACK_WIN__.closed) { window.__EM_FEEDBACK_WIN__.close(); } } catch(_) {}
            });
        } catch(_) {}

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            boot();
        }
    }

    return { init };
});