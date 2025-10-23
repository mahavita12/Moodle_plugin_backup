<?php
require_once(__DIR__ . '/../../config.php');

// Params
$attemptid = required_param('attemptid', PARAM_INT);
$round     = required_param('round', PARAM_INT);
$clean     = optional_param('clean', 1, PARAM_INT); // default to clean/popup view

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

try {
    // Load attempt and context
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
    $quiz    = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
    $course  = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    // Access control: owner or teacher
    if ($USER->id != $attempt->userid && !has_capability('mod/quiz:viewreports', $context)) {
        print_error('noaccess', 'local_essaysmaster');
    }

    $PAGE->set_url('/local/essaysmaster/view_feedback.php', ['attemptid' => $attemptid, 'round' => $round, 'clean' => $clean]);
    $PAGE->set_context($context);
    $PAGE->set_title('EssaysMaster Feedback');
    $PAGE->set_heading('EssaysMaster Feedback');
    $PAGE->set_pagelayout('popup');

    // Fetch stored feedback for this attempt/round
    $record = $DB->get_record('local_essaysmaster_feedback', [
        'attempt_id'   => $attemptid,
        'round_number' => $round
    ]);
    if (!$record) {
        // Fallback to original keys if needed
        $record = $DB->get_record('local_essaysmaster_feedback', [
            'version_id' => $attemptid,
            'level_type' => 'round_' . (int)$round
        ]);
    }

echo $OUTPUT->header();
echo '<style>body{margin:0} .popup-feedback-view{max-width:820px;margin:0 auto;padding:0 0 16px 0}</style>';

    echo '<div class="popup-feedback-view">';

    if ($record && !empty($record->feedback_html)) {
        $raw = (string)$record->feedback_html;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        // Strip span tags and leftover closers to avoid raw markup rendering
        $raw = preg_replace('/<\/?span[^>]*>/i', '', $raw);
        $raw = str_replace('</span>', '', $raw);

        $isvalidation = in_array((int)$round, [2, 4, 6], true);
        $isfeedback   = in_array((int)$round, [1, 3, 5], true);

        // Descriptive titles to match mini-field
        $titlemap = [
            1 => 'Round 1: Grammar, Spelling & Punctuation',
            2 => 'Round 2: Proofreading Validation',
            3 => 'Round 3: Language Enhancement',
            4 => 'Round 4: Improvement Validation',
            5 => 'Round 5: Relevance & Structure',
            6 => 'Round 6: Final Validation',
        ];

        // Helper: clean simple markdown/syntax
        $clean_text = function(string $t): string {
            $t = preg_replace('/\*\*([^*]+)\*\*/', '$1', $t); // **bold**
            $t = preg_replace('/\*([^*]+)\*/', '$1', $t);     // *italic*
            $t = preg_replace('/^\s*#+\s?/m', '', $t);        // # headers
            $t = preg_replace('/`([^`]+)`/', '$1', $t);         // `code`
            return trim($t);
        };

        // Parse main feedback vs improvements
        $lines = explode("\n", $raw);
        $inimpr = false;
        $main   = '';
        $improvements = [];
        foreach ($lines as $ln) {
            $l = trim($ln);
            if ($l === '') { $main .= "\n"; continue; }
            if (strpos($l, '=>') !== false) { $inimpr = true; }
            if ($inimpr && strpos($l, '=>') !== false) {
                [$left, $right] = array_pad(explode('=>', $l, 2), 2, '');
                $left = trim(str_replace(['[HIGHLIGHT]', '[/HIGHLIGHT]'], '', $left));
                $right = trim(str_replace(['[HIGHLIGHT]', '[/HIGHLIGHT]'], '', $right));
                if ($left !== '' && $right !== '') {
                    $improvements[] = ['original' => $left, 'improved' => $right];
                }
            } else {
                $main .= $ln . "\n";
            }
        }

        $main = $clean_text($main);

        // Fetch current essay text for highlight panel (rounds 2,3,4)
        $essaytext = '';
        if (in_array((int)$round, [2,3,4], true)) {
            $sql = "SELECT qa.id AS qaid, q.questiontext
                    FROM {question_attempts} qa
                    JOIN {question} q ON q.id = qa.questionid
                    WHERE qa.questionusageid = ? AND q.qtype = 'essay'";
            $qas = $DB->get_records_sql($sql, [$attempt->uniqueid]);
            foreach ($qas as $qa) {
                $ans = $DB->get_record_sql(
                    "SELECT qasd.value AS answer
                     FROM {question_attempt_steps} qas
                     JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                     WHERE qas.questionattemptid = ? AND qasd.name = 'answer'
                     ORDER BY qas.timecreated DESC LIMIT 1",
                    [$qa->qaid]
                );
                if ($ans && trim($ans->answer) !== '') { $essaytext = $ans->answer; break; }
            }
        }

        // Build HTML similar to AMD
        $content = '';
        $content .= '<h3 class="em-title">' . s($titlemap[(int)$round] ?? ('Round ' . (int)$round)) . '</h3>';
        $content .= '<div id="em-feedback-render">';
        $content .= '<div class="ai-feedback-content" style="font-family: Arial, Helvetica, sans-serif; font-size: 14px;">';

        // Build paragraph block with round-specific formatting
        $content .= '<div class="feedback-text">';

        // Remove nonce/comments if present
        $main = preg_replace('/<!--\s*nonce:[^>]*-->\s*/i', '', $main);

        if ($isfeedback && (int)$round === 1) {
            // Round 1 formatting parity: intro + red counts + emphasis lines
            $paras = array_values(array_filter(array_map('trim', preg_split('/\n+/', $main))));
            if (!empty($paras)) {
                // Intro paragraph (first line) normal
                $content .= '<p class="feedback-line">' . s($paras[0]) . '</p>';
            }

            // Extract counts for spelling/grammar and details sentence
            $spellingCount = null; $grammarLine = '';
            if (preg_match('/(\d+)\s+spelling\s+mistakes?/i', $main, $m)) {
                $spellingCount = (int)$m[1];
                $content .= '<p class="feedback-line feedback-line-warning">' . s('We noticed ' . $spellingCount . ' spelling mistakes.') . '</p>';
            }
            if (preg_match('/\b(\d+)\s+grammar[^\n]*?/i', $main, $g)) {
                $grammarLine = trim($g[0]);
                // Expand to match the full sentence if present
                if (preg_match('/We\s+also\s+found[^\n]+grammar[^\n]+/i', $main, $gs)) {
                    $grammarLine = $gs[0];
                } else if (preg_match('/I\s+found[^\n]+grammar[^\n]+/i', $main, $gs2)) {
                    $grammarLine = 'We also found ' . $gs2[0];
                }
                $content .= '<p class="feedback-line feedback-line-warning">' . s($grammarLine) . '</p>';
            }

            // Light blue emphasis: requirements sentence
            if (preg_match('/these\s+must\s+be\s+fixed[^\n]+/i', $main, $fixm)) {
                $content .= '<div class="feedback-line-emphasis">' . s(trim($fixm[0])) . '</div>';
            }

            // Final emphasis box: strong advice sentence
            // Extract final advice even if <strong> not present
            $advice = '';
            if (preg_match('/<strong>(.*?)<\/strong>/is', $raw, $strongm)) {
                $advice = $strongm[1];
            } else if (preg_match('/Take\s+time\s+to\s+carefully[^\n]+/i', $raw, $am)) {
                $advice = $am[0];
            }
            if ($advice !== '') {
                $content .= '<div class="essay-emphasis-box">' . s($advice) . '</div>';
            }
        } else if ($isvalidation) {
            // Validation formatting parity: parse Score/Analysis/Feedback
            $score = null; $analysis = ''; $fbtext = '';
            if (preg_match('/Score:\s*(\d+)\s*\/\s*100/i', $main, $sm)) { $score = (int)$sm[1]; }
            if (preg_match('/Analysis:\s*(.*?)(?:\n+Feedback:|$)/is', $main, $am)) { $analysis = trim($am[1]); }
            if (preg_match('/Feedback:\s*(.*)$/is', $main, $fm)) { $fbtext = trim($fm[1]); }

            if ($score !== null) {
                $status = ($score >= 50) ? 'Passed' : 'Failed';
                $content .= '<div class="validation-header ' . ($score >= 50 ? 'pass' : 'fail') . '">' . s('Validation Score: ' . $score . '/100 ' . $status) . '</div>';
            }
            if ($analysis !== '') {
                $content .= '<div class="validation-analysis">';
                foreach (array_filter(array_map('trim', preg_split('/\n+/', $analysis))) as $line) {
                    $content .= '<p class="feedback-line">' . s($line) . '</p>';
                }
                $content .= '</div>';
            }
            if ($fbtext !== '') {
                foreach (array_filter(array_map('trim', preg_split('/\n+/', $fbtext))) as $line) {
                    $content .= '<p class="feedback-line">' . s($line) . '</p>';
                }
            }
        } else {
            // Default paragraph rendering (feedback rounds 3/5 etc.)
            foreach (array_filter(array_map('trim', preg_split('/\n+/', $main))) as $p) {
                $content .= '<p class="feedback-line">' . s($p) . '</p>';
            }
        }
        $content .= '</div>'; // .feedback-text

        if (!empty($improvements)) {
            $content .= '<div class="improvements-section">';
            foreach ($improvements as $it) {
                $content .= '<div class="improvement-item">'
                    . '<div class="original-text">' . s($it['original']) . '</div>'
                    . '<div class="arrow">=&gt;</div>'
                    . '<div class="improved-text">' . s($it['improved']) . '</div>'
                    . '</div>';
            }
            $content .= '</div>';
        }
        $content .= '</div>'; // .ai-feedback-content

        // Essay display with amber highlights (rounds 2,3,4)
        if ($essaytext !== '' && !empty($improvements) && in_array((int)$round, [2,3,4], true)) {
            $highlighted = $essaytext;
            foreach ($improvements as $imp) {
                $o = trim($imp['original']);
                $r = trim($imp['improved']);
                if ($o === '') { continue; }
                $pattern = '/\b' . preg_quote($o, '/') . '\b/i';
                $replacement = '<span class="amber-highlight" title="' . s($r) . '">$0</span>';
                $highlighted = preg_replace($pattern, $replacement, $highlighted);
            }
            $content .= '<div class="essay-display-container">'
                     .   '<div class="essay-display-title">Your Essay - ' . (($round==3)?'Language Enhancement Opportunities':'Review') . '</div>'
                     .   '<div class="highlight-legend">'
                     .     '<span class="legend-item"><span class="legend-color amber-highlight"></span><strong>Amber highlights show text that needs improvement</strong></span>'
                     .     '<span class="legend-item"><em>Hover over highlighted text for suggestions</em></span>'
                     .   '</div>'
                     .   '<div style="text-align: justify; white-space: pre-wrap;">' . $highlighted . '</div>'
                     . '</div>';
        }

        $content .= '<div style="text-align:center;margin-top:15px;padding:10px;background:#e7f3ff;border-radius:4px;"><strong>Round ' . (int)$round . ' of 6</strong></div>';
        $content .= '</div>';

        // Styles mirrored from AMD
        echo '<style>'
            . '.improvements-section{background:#f8f9fa;padding:20px;border-radius:8px;margin:15px 0;border-left:4px solid #007bff}'
            . '.improvement-item{margin:5px 0;padding:8px 12px;background:#fff;border-radius:4px;border:1px solid #e9ecef;display:flex;align-items:center;gap:10px}'
            . '.original-text{color:#6c757d;padding:4px 8px;background:#f8f9fa;border-radius:3px;border-left:3px solid #6c757d;flex:1;font-size:14px}'
            . '.improved-text{color:#007bff;padding:4px 8px;background:#e3f2fd;border-radius:3px;border-left:3px solid #007bff;font-weight:500;flex:1;font-size:14px}'
            . '.arrow{text-align:center;font-size:14px;color:#28a745;font-weight:bold;margin:0 4px;}'
            . '.ai-feedback-content{background:#fff;padding:15px;border-radius:6px;margin:10px 0;border-left:4px solid #17a2b8;line-height:1.6}'
            . '.feedback-text{color:#495057;margin-bottom:15px}'
            . '.feedback-text .feedback-line{margin-bottom:8px;font-size:15px}'
            . '.feedback-line-warning{color:#dc3545;font-weight:600}'
            . '.feedback-line-emphasis{background:#e7f3ff;border-left:4px solid #0f6cbf;border-radius:4px;padding:8px 12px;color:#0f3c75;font-weight:600;margin:6px 0}'
            . '.essay-emphasis-box{background:#e7f3ff;border-left:4px solid #0f6cbf;border-radius:4px;padding:10px 12px;color:#0f3c75;font-weight:600;margin-top:8px}'
            . '.amber-highlight{background-color:#ffc107;color:#212529;font-weight:bold;border-radius:3px;padding:2px 4px;margin:0 1px;border:1px solid #e0a800;box-shadow:0 2px 4px rgba(255,193,7,.3)}'
            . '.essay-display-container{background:#fff;border:2px solid #007bff;border-radius:8px;padding:20px;margin:15px 0;font-family:\'Calibri\',\'Segoe UI\',Arial,sans-serif;font-size:16px;line-height:1.8}'
            . '.essay-display-title{color:#007bff;font-weight:bold;margin-bottom:15px;font-size:18px;text-align:center;border-bottom:2px solid #007bff;padding-bottom:8px}'
            . '.highlight-legend{background:#e7f3ff;border:1px solid #b6daff;border-radius:4px;padding:10px;margin:10px 0;font-size:14px;color:#0f3c75}'
            . '.legend-item{display:inline-block;margin-right:15px;margin-bottom:5px}'
            . '.legend-color{display:inline-block;width:20px;height:15px;border-radius:3px;margin-right:5px;vertical-align:middle}'
            . '.legend-color.amber-highlight{background-color:#ffc107;border:1px solid #e0a800}'
            . '.em-title{color:#0f6cbf;margin-top:0;font-size:18px}'
            . '.validation-header{font-weight:700;padding:8px 10px;border-radius:4px;margin-bottom:8px}'
            . '.validation-header.fail{background:#fdecea;color:#b71c1c;border-left:4px solid #b71c1c}'
            . '.validation-header.pass{background:#e9f7ef;color:#1b5e20;border-left:4px solid #1b5e20}'
            . '.validation-analysis{color:#b71c1c}'
            . '</style>';

        echo '<div id="essays-master-feedback" style="border:2px solid #0f6cbf;border-radius:8px;background:#f8f9fa;padding:15px">' . $content . '</div>';
    } else {
        echo '<div class="alert alert-info" role="alert" style="margin:20px 0">';
        echo '<strong>No feedback available.</strong> This round has not generated feedback yet.';
        echo '</div>';
    }

    echo '</div>'; // wrapper

    // postMessage bridge to allow exact clone injection from opener
    echo "\n<script>\n(function(){\n  function injectClone(payload){\n    try{\n      if(!payload||!payload.html){return;}\n      var wrap = document.querySelector('.popup-wrap') || document.body;\n      // Remove existing rendered container\n      var exist = document.getElementById('essays-master-feedback');\n      if(exist && exist.parentNode){ exist.parentNode.removeChild(exist); }\n      // Inject styles first
      if(payload.styles){\n        var s = document.getElementById('essays-master-styles');\n        if(!s){ s = document.createElement('style'); s.id='essays-master-styles'; document.head.appendChild(s); }\n        s.textContent = payload.styles;\n      }\n      // Insert cloned HTML\n      var temp = document.createElement('div');\n      temp.innerHTML = payload.html;\n      var node = temp.firstElementChild;\n      if(node){\n        wrap.appendChild(node);\n      }\n      if(payload.title){ document.title = payload.title; }\n    }catch(e){ console.error('Clone injection failed', e); }\n  }\n  window.addEventListener('message', function(ev){\n    var data = ev && ev.data;\n    if(data && data.type === 'em_feedback_clone'){ injectClone(data); }\n  });\n  // Notify opener that we are ready
  try { if(window.opener){ window.opener.postMessage({type:'em_feedback_ready'}, '*'); } } catch(_){}\n})();\n</script>\n";

} catch (Exception $e) {
    echo $OUTPUT->header();
    echo '<div class="alert alert-danger" role="alert">Error loading feedback: ' . s($e->getMessage()) . '</div>';
}

echo $OUTPUT->footer();
?>


