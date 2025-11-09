<?php
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/classes/essay_grader.php');

$attemptid = required_param('id', PARAM_INT);
$print_view = optional_param('print', 0, PARAM_INT);
$clean_view = optional_param('clean', 0, PARAM_INT);

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

try {
    $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
    $context = context_course::instance($course->id);

    if ($USER->id != $attempt->userid && !has_capability('mod/quiz:viewreports', $context)) {
        print_error('noaccess', 'local_quizdashboard');
    }

    $is_admin_view = has_capability('mod/quiz:viewreports', $context) && ($USER->id != $attempt->userid);

    $PAGE->set_url('/local/quizdashboard/viewfeedback.php', ['id' => $attemptid]);
    $PAGE->set_context($context);
    $PAGE->set_title('Essay Feedback');
    $PAGE->set_heading('Essay Feedback');
    
    // FIXED: Always use standard layout for admins, popup only for students
    if ($print_view) {
        $PAGE->set_pagelayout('print');
    } else if ($clean_view) {
        // Minimal, chrome-free layout without auto print
        $PAGE->set_pagelayout('print');
    } else if ($is_admin_view) {
        $PAGE->set_pagelayout('standard'); // Changed from 'admin' to 'standard'
    } else {
        $PAGE->set_pagelayout('popup');
    }

    // Add JavaScript for print functionality
    if ($print_view) {
        $PAGE->requires->js_init_code('window.onload = function() { window.print(); };');
    } else if (!$clean_view) {
        $PAGE->requires->js_init_code('
            function printFeedback() { 
                window.print(); 
            }
            function printInNewWindow() { 
                var url = window.location.href + (window.location.href.includes("?") ? "&" : "?") + "print=1";
                var printWindow = window.open(url, "print_feedback", "width=900,height=700,scrollbars=yes,resizable=yes");
                if (printWindow) {
                    printWindow.focus();
                } else {
                    alert("Please allow popups for this site to use the print in new window feature.");
                }
            }
        ');
    }

    $grader = new \local_quizdashboard\essay_grader();
    $grading_result = $grader->get_grading_result($attemptid);

    echo $OUTPUT->header();

    // Add print-specific top/bottom padding for popup window
    echo '<style>
        @media print {
            .popup-feedback-view,
            .clean-feedback-view {
                padding-top: 0.5in !important;
                padding-bottom: 0.5in !important;
            }
            .ld-essay-feedback {
                margin-top: 0 !important;
            }
        }
    </style>';

    // IMPROVED: Better print button layout and homework injection
    if (!$print_view && !$clean_view && $grading_result && !empty($grading_result->feedback_html)) {
        echo '<div class="print-buttons screen-only">';
        echo '<div class="btn-group float-right" role="group">';
        echo '<button type="button" class="btn btn-secondary" onclick="printFeedback()" title="Print this page">';
        echo '<i class="fa fa-print"></i> Print Page';
        echo '</button>';
        echo '<button type="button" class="btn btn-outline-secondary" onclick="printInNewWindow()" title="Open in new window for printing">';
        echo '<i class="fa fa-external-link"></i> Print Window';
        echo '</button>';
        if ($is_admin_view) {
            echo '<button type="button" id="qd-inject-homework" class="btn btn-primary ml-2" title="Create Homework in Personal Course">';
            echo '<i class="fa fa-upload"></i> Inject Homework';
            echo '</button>';
        }
        echo '</div>';
        echo '<div class="clearfix"></div>';
        echo '</div>';
        if ($is_admin_view) {
            echo '<div id="qd-inject-result" class="alert mt-2" style="display:none"></div>';
        }
    }

    // Add wrapper for admin view
    if ($clean_view) {
        echo '<div class="clean-feedback-view">';
    } else if ($is_admin_view) {
        echo '<div class="admin-feedback-view">';
    } else {
        echo '<div class="popup-feedback-view">';
    }

    if ($grading_result && !empty($grading_result->feedback_html)) {
        echo $grading_result->feedback_html;
    } else {
        echo '<div class="alert alert-info text-center">';
        echo '<h4><i class="fa fa-info-circle"></i> No Feedback Available</h4>';
        echo '<p>Automated feedback has not been generated for this essay yet.</p>';
        echo '<p><a href="' . new moodle_url('/local/quizdashboard/essays.php') . '" class="btn btn-primary">Return to Essay Dashboard</a></p>';
        echo '</div>';
    }

    echo '</div>'; // Close wrapper div

    if (!$print_view && !$clean_view && $is_admin_view) {
        $ajaxurl = (new moodle_url('/local/quizdashboard/ajax.php'))->out(false);
        $sess = sesskey();
        $defaultlabel = 'Homework – ' . format_string($quiz->name);
        $useridjs = (int)$attempt->userid;
        $attemptidjs = (int)$attemptid;
        $ajaxurljs = json_encode($ajaxurl);
        $sessjs = json_encode($sess);
        $labeljs = json_encode($defaultlabel);
        $js = <<<JS
(function(){
  var btn = document.getElementById('qd-inject-homework');
  if(!btn) return;
  var ajax = {$ajaxurljs};
  var sess = {$sessjs};
  var userid = {$useridjs};
  var attemptid = {$attemptidjs};
  var label = {$labeljs};
  function collectItems(){
    var items = [];
    var scripts = document.querySelectorAll('script[type="application/json"][data-qdhw-items]');
    if(scripts.length){
      try {
        var j = JSON.parse(scripts[0].textContent||'[]');
        if(Array.isArray(j)){
          j.forEach(function(it){ if(it && it.original){ items.push({original:String(it.original), suggested:String(it.suggested||'')}); } });
        }
      } catch(e){}
    }
    if(!items.length){
      var candidates = Array.from(document.querySelectorAll('p,li,div,strong'));
      var seen = {};
      candidates.forEach(function(el){
        var t=(el.textContent||'').replace(/\n/g,' ').trim();
        var m=t.match(/^\s*(\d+)\s*\.?\s*Original\s*:?(.*)$/i);
        if(m){ var idx=m[1]; var rest=m[2]||''; if(!seen[idx]){ items.push({original:rest.trim(), suggested:''}); seen[idx]=true; } }
      });
    }
    return items;
  }
  function show(msg, ok, url){
    var box=document.getElementById('qd-inject-result'); if(!box) return;
    box.className = 'alert mt-2 ' + (ok? 'alert-success':'alert-danger');
    box.style.display='block';
    box.innerHTML = ok && url ? ('Homework created: <a href="'+url+'" target="_blank">Open quiz</a>') : msg;
  }
  btn.addEventListener('click', function(){
    try{ var items=collectItems(); if(!items.length){ show('No homework items found on this page.', false); return; } }
    catch(e){ show('Failed to collect items.', false); return; }
    var fd=new FormData();
    fd.append('action','inject_homework');
    fd.append('sesskey', sess);
    fd.append('userid', String(userid));
    fd.append('label', label);
    fd.append('items', JSON.stringify(items));
    fd.append('attemptid', String(attemptid));
    fetch(ajax, { method:'POST', body: fd, credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return {success:false,message:'Invalid response'}; }); })
      .then(function(j){ if(j && j.success){ show('', true, j.url||''); } else { show((j&&j.message)||'Failed to inject', false); } })
      .catch(function(){ show('Request failed.', false); });
  });
})();
JS;
        $PAGE->requires->js_init_code($js);
    }

} catch (Exception $e) {
    echo $OUTPUT->header();
    echo '<div class="alert alert-danger">';
    echo '<h4>Error Loading Feedback</h4>';
    echo '<p>There was an error loading the feedback for this essay attempt.</p>';
    echo '</div>';
    error_log('Error in viewfeedback.php: ' . $e->getMessage());
}

echo $OUTPUT->footer();
?>