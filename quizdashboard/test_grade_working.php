<?php
/**
 * WORKING Grade Injection - Direct QUBA Manipulation (Moodle 4.4+)
 * Uses the exact working sequence: direct QUBA + quiz_settings factory
 * 
 * Usage: /local/quizdashboard/test_grade_working.php?attempt_id=78&fraction=0.85
 */

require_once(__DIR__ . '/../../config.php');
require_login();

// Set up context
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/quizdashboard/test_grade_working.php');
$PAGE->set_title('🎯 WORKING Grade Injection - Direct QUBA');
$PAGE->set_heading('🎯 WORKING Grade Injection - Direct QUBA');

// Check if user has permission
require_capability('local/quizdashboard:view', context_system::instance());

echo $OUTPUT->header();

$attemptid = optional_param('attempt_id', 79, PARAM_INT);
$slot = optional_param('slot', 1, PARAM_INT);
$fraction = optional_param('fraction', 0.85, PARAM_FLOAT);

echo html_writer::tag('h2', "🎯 WORKING Grade Injection - Direct QUBA Manipulation");
echo html_writer::tag('p', "Attempt ID: {$attemptid} | Slot: {$slot} | Fraction: {$fraction}");

// ---- EXACT REQUIRED LIBS ----
require_once($CFG->dirroot.'/question/engine/lib.php');
require_once($CFG->dirroot.'/mod/quiz/lib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');

echo html_writer::tag('div',
    '<strong>🎯 WORKING MOODLE 4.4+ SEQUENCE:</strong><br>' .
    '✅ Using direct QUBA manipulation (no wrapper methods)<br>' .
    '✅ Using quiz_settings::create() factory for grade calculator<br>' .
    '✅ Proper 4.4+ manual_grade signature: ($comment, $mark, $commentformat)<br>' .
    '✅ Instance methods on grade_calculator (not static)',
    ['style' => 'background:#e6ffe6; padding:15px; border:2px solid #00aa00; border-radius:5px; margin:15px 0;']
);

try {
    echo html_writer::tag('h3', '🏗️ Step 1: Create Quiz Attempt and Get QUBA');
    
    $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
    echo html_writer::tag('p', '✅ quiz_attempt::create() completed');
    
    $uniqueid = $attemptobj->get_attempt()->uniqueid;                         // quba id
    $quba     = question_engine::load_questions_usage_by_activity($uniqueid); // ✅ get QUBA we control
    
    echo html_writer::tag('p', '✅ QUBA loaded directly via uniqueid: ' . $uniqueid);
    echo html_writer::tag('p', '<strong>Quiz:</strong> ' . htmlspecialchars($attemptobj->get_quiz_name()));
    echo html_writer::tag('p', '<strong>User ID:</strong> ' . $attemptobj->get_userid());
    echo html_writer::tag('p', '<strong>Attempt ID:</strong> ' . $attemptobj->get_attemptid());
    
    echo html_writer::tag('h3', '📊 Step 2: Get Question Attempt and Current State');
    
    $qa = $quba->get_question_attempt($slot);
    
    $before_mark = $qa->get_mark();
    $before_max = $qa->get_max_mark();
    $before_state = $qa->get_state();
    $behaviour = $qa->get_behaviour_name();
    
    echo html_writer::tag('p', '<strong>BEHAVIOUR:</strong> ' . $behaviour . ' ' . ($behaviour === 'manualgraded' ? '✅' : '❌'));
    echo html_writer::tag('p', 
        '<strong>BEFORE:</strong> mark=' . $before_mark . 
        ' / max=' . $before_max . 
        ' state=' . $before_state
    );
    
    // Get step count before
    $attempt_record = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
    $qa_db_before = $DB->get_record('question_attempts',
        ['questionusageid'=>$attempt_record->uniqueid,'slot'=>$slot],
        'id,maxmark', MUST_EXIST);
    $steps_before = $DB->count_records('question_attempt_steps', ['questionattemptid' => $qa_db_before->id]);
    
    echo html_writer::tag('p', '<strong>Steps in DB before:</strong> ' . $steps_before);
    
    echo html_writer::tag('h3', '🎯 Step 3: Apply Manual Grade (Direct QUBA)');
    
    // 4.4+ signature: (comment, mark, commentformat)
    $mark = $fraction * $qa->get_max_mark();  // e.g., 85 when max=100
    
    echo html_writer::tag('p', 
        '<strong>Calculation:</strong> mark = ' . $fraction . ' × ' . $qa->get_max_mark() . ' = ' . $mark
    );
    
    echo html_writer::tag('div',
        '<strong>🎯 4.4+ SIGNATURE:</strong> manual_grade($comment, $mark, $commentformat)<br>' .
        'CRITICAL: Mark is 2nd parameter (not 3rd or 4th)',
        ['style' => 'background:#fff3cd; padding:10px; border:1px solid #ffc107; border-radius:5px; margin:10px 0;']
    );
    
    $qa->manual_grade('WORKING: Graded via script (0.85)', $mark, FORMAT_HTML);
    echo html_writer::tag('p', '✅ manual_grade() applied with correct 4.4+ signature');
    
    echo html_writer::tag('h3', '💾 Step 4: Persist the QUBA');
    
    // Persist the *same* QUBA instance you just changed:
    question_engine::save_questions_usage_by_activity($quba);                 // ✅ persist
    echo html_writer::tag('p', '✅ QUBA saved directly via question_engine');
    
    echo html_writer::tag('h3', '🔄 Step 5: Verify Grade Applied');
    
    // Reload QUBA to verify
    $quba_fresh = question_engine::load_questions_usage_by_activity($uniqueid);
    $qa_fresh = $quba_fresh->get_question_attempt($slot);
    
    $after_mark = $qa_fresh->get_mark();
    $after_max = $qa_fresh->get_max_mark();
    $after_state = $qa_fresh->get_state();
    
    echo html_writer::tag('p', 
        '<strong>AFTER (fresh QUBA):</strong> mark=' . $after_mark . 
        ' / max=' . $after_max . 
        ' state=' . $after_state
    );
    
    // Get step count after
    $steps_after = $DB->count_records('question_attempt_steps', ['questionattemptid' => $qa_db_before->id]);
    echo html_writer::tag('p', '<strong>Steps in DB after:</strong> ' . $steps_before . ' → ' . $steps_after);
    
    echo html_writer::tag('h3', '📊 Step 6: Recompute via Quiz Settings Factory');
    
    // Build quiz settings (factory) and get the calculator instance:
    echo html_writer::tag('p', '🏭 Creating quiz_settings via factory...');
    $quizobj = \mod_quiz\quiz_settings::create($attemptobj->get_attempt()->quiz); // factory
    echo html_writer::tag('p', '✅ quiz_settings created');
    
    $calc = $quizobj->get_grade_calculator();                                   // ✅ factory, not new
    echo html_writer::tag('p', '✅ grade_calculator obtained via factory');
    
    $original_sum = $attemptobj->get_attempt()->sumgrades;
    echo html_writer::tag('p', '<strong>Original attempt sum:</strong> ' . $original_sum);
    
    // Prefer per-attempt recompute if available
    $recompute_method = 'none';
    
    if (method_exists($calc, 'recompute_quiz_sumgrades_for_attempts')) {
        echo html_writer::tag('p', '🎯 Using per-attempt recompute...');
        $calc->recompute_quiz_sumgrades_for_attempts([$attemptobj->get_attempt()]);
        $recompute_method = 'per-attempt';
        echo html_writer::tag('p', '✅ recompute_quiz_sumgrades_for_attempts() completed');
        
    } elseif (method_exists($calc, 'recompute_quiz_sumgrades')) {
        echo html_writer::tag('p', '🔄 Using full quiz recompute...');
        $calc->recompute_quiz_sumgrades();                                         // fallback (whole quiz)
        $recompute_method = 'full-quiz';
        echo html_writer::tag('p', '✅ recompute_quiz_sumgrades() completed');
        
    } else {
        echo html_writer::tag('p', '⚠️ No recompute methods available');
        $recompute_method = 'unavailable';
    }
    
    echo html_writer::tag('h3', '📈 Step 7: Push to Gradebook');
    
    // Push to gradebook for this user:
    quiz_update_grades($quizobj->get_quiz(), $attemptobj->get_userid());
    echo html_writer::tag('p', '✅ quiz_update_grades() completed');
    
    echo html_writer::tag('h3', '🔍 Step 8: Final Verification');
    
    // Get final state
    $attemptobj_final = \mod_quiz\quiz_attempt::create($attemptid);
    $final_attempt = $attemptobj_final->get_attempt();
    $final_sum = $final_attempt->sumgrades;
    
    // Final QUBA verification
    $quba_final = question_engine::load_questions_usage_by_activity($uniqueid);
    $qa_final = $quba_final->get_question_attempt($slot);
    $final_mark = $qa_final->get_mark();
    
    echo html_writer::tag('p', '<strong>Final question mark:</strong> ' . $before_mark . ' → ' . $final_mark);
    echo html_writer::tag('p', '<strong>Final attempt sum:</strong> ' . $original_sum . ' → ' . $final_sum);
    echo html_writer::tag('p', '<strong>Recompute method:</strong> ' . $recompute_method);
    
    // DB verification
    $final_step = $DB->get_record_sql("
      SELECT state, fraction
        FROM {question_attempt_steps}
       WHERE questionattemptid = ?
    ORDER BY sequencenumber DESC LIMIT 1", [$qa_db_before->id]);
    
    echo html_writer::tag('p', 
        '<strong>DB final step:</strong> fraction=' . ($final_step->fraction ?? 'NULL') . 
        ', state=' . $final_step->state
    );
    
    echo html_writer::tag('h3', '🎯 Step 9: Complete Success Analysis');
    
    // Success verification
    $question_mark_correct = abs($final_mark - $mark) < 0.01;
    $fraction_correct = abs(($final_step->fraction ?? 0) - $fraction) < 0.01;
    $step_added = $steps_after > $steps_before;
    $sum_updated = abs($final_sum - $original_sum) > 0.01;
    
    echo html_writer::tag('div',
        '<strong>🎯 SUCCESS VERIFICATION:</strong><br>' .
        '✅ Question mark correct: ' . ($question_mark_correct ? 'Yes' : 'No') . ' (' . $final_mark . ' vs ' . $mark . ')<br>' .
        '✅ DB fraction correct: ' . ($fraction_correct ? 'Yes' : 'No') . ' (' . ($final_step->fraction ?? 'NULL') . ' vs ' . $fraction . ')<br>' .
        '✅ New step added: ' . ($step_added ? 'Yes' : 'No') . ' (' . $steps_before . ' → ' . $steps_after . ')<br>' .
        '✅ Sum updated: ' . ($sum_updated ? 'Yes' : 'No') . ' (' . $original_sum . ' → ' . $final_sum . ')<br>' .
        '✅ Recompute method: ' . $recompute_method,
        ['style' => 'background:#f8f9fa; padding:15px; border:1px solid #dee2e6; border-radius:5px; margin:15px 0;']
    );
    
    if ($question_mark_correct && $fraction_correct && $step_added) {
        echo html_writer::tag('div',
            '<strong>🎉 COMPLETE END-TO-END SUCCESS!</strong><br><br>' .
            '🏆 Moodle 4.4+ grade injection working perfectly!<br>' .
            '✅ Individual question: ' . $before_mark . '/100 → ' . $final_mark . '/100<br>' .
            '✅ Attempt total: ' . $original_sum . ' → ' . $final_sum . '<br>' .
            '✅ Database persistence: fraction = ' . ($final_step->fraction ?? 'NULL') . '<br>' .
            '✅ Step creation: +' . ($steps_after - $steps_before) . ' new step(s)<br>' .
            '✅ Direct QUBA manipulation: Working<br>' .
            '✅ Quiz settings factory: Working<br>' .
            '✅ Gradebook integration: Complete<br><br>' .
            '<strong>🎯 YOUR GRADE INJECTION IS FULLY FUNCTIONAL!</strong>',
            ['style' => 'background:#e6ffe6; padding:20px; border:3px solid #00aa00; border-radius:10px; margin:20px 0; font-size:16px;']
        );
    } else {
        echo html_writer::tag('div',
            '<strong>⚠️ PARTIAL SUCCESS</strong><br><br>' .
            'Some aspects worked but verification failed.<br>' .
            'Check the success verification details above.',
            ['style' => 'background:#fff3cd; padding:15px; border:2px solid #ffc107; border-radius:5px; margin:15px 0;']
        );
    }
    
    echo html_writer::tag('h3', '📋 Final Production Code');
    
    echo html_writer::tag('div',
        '<strong>🎯 COPY THIS EXACT SEQUENCE TO essay_grader.php:</strong><br><br>' .
        '<pre style="background:#f1f3f4; padding:15px; border-radius:5px; font-family:monospace; font-size:12px;">' .
        '// Get attempt and QUBA' . "\n" .
        '$attemptobj = \mod_quiz\quiz_attempt::create($attemptid);' . "\n" .
        '$uniqueid = $attemptobj->get_attempt()->uniqueid;' . "\n" .
        '$quba = question_engine::load_questions_usage_by_activity($uniqueid);' . "\n" .
        '$qa = $quba->get_question_attempt($slot);' . "\n\n" .
        '// Apply grade (4.4+ signature)' . "\n" .
        '$mark = $fraction * $qa->get_max_mark();' . "\n" .
        '$qa->manual_grade($comment, $mark, FORMAT_HTML);' . "\n\n" .
        '// Save QUBA' . "\n" .
        'question_engine::save_questions_usage_by_activity($quba);' . "\n\n" .
        '// Recompute totals via factory' . "\n" .
        '$quizobj = \mod_quiz\quiz_settings::create($attemptobj->get_attempt()->quiz);' . "\n" .
        '$calc = $quizobj->get_grade_calculator();' . "\n" .
        'if (method_exists($calc, \'recompute_quiz_sumgrades_for_attempts\')) {' . "\n" .
        '    $calc->recompute_quiz_sumgrades_for_attempts([$attemptobj->get_attempt()]);' . "\n" .
        '} else {' . "\n" .
        '    $calc->recompute_quiz_sumgrades();' . "\n" .
        '}' . "\n\n" .
        '// Update gradebook' . "\n" .
        'quiz_update_grades($quizobj->get_quiz(), $attemptobj->get_userid());' .
        '</pre>',
        ['style' => 'background:#f8f9fa; padding:15px; border:1px solid #dee2e6; border-radius:5px; margin:15px 0;']
    );
    
} catch (Exception $e) {
    echo html_writer::tag('div',
        '<strong>❌ EXCEPTION:</strong> ' . $e->getMessage(),
        ['style' => 'background:#ffe6e6; padding:15px; border:2px solid #ff0000; border-radius:5px; margin:15px 0;']
    );
    echo html_writer::tag('p', '<strong>Stack trace:</strong>');
    echo html_writer::tag('pre', $e->getTraceAsString());
}

echo $OUTPUT->footer();
?>
