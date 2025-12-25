<?php
defined('MOODLE_INTERNAL') || die();

function local_personalcourse_extend_navigation(\global_navigation $navigation) {
    global $PAGE, $CFG, $DB, $COURSE, $SESSION, $USER;

    // Check if we need to redirect the user after an in-progress attempt was deleted
    // We do this here (early) to prevent accessing broken attempt pages
    if (!empty($SESSION->local_personalcourse_redirect) && isloggedin() && !isguestuser()) {
        $redirect = $SESSION->local_personalcourse_redirect;
        $isRecent = (time() - ($redirect->time ?? 0)) < 600; // Increased to 600s
        $isCorrectUser = ((int)$USER->id === (int)($redirect->userid ?? 0));

        $currentUrl = $PAGE->url->out(false);
        $isQuizPage = (strpos($currentUrl, '/mod/quiz/') !== false);
        $isTargetPage = (strpos($currentUrl, $redirect->url) !== false);

        if ($isRecent && $isCorrectUser && $isQuizPage && !$isTargetPage) {
            unset($SESSION->local_personalcourse_redirect);
            error_log("[local_personalcourse] EARLY Redirecting user to: " . $redirect->url);
            redirect($redirect->url, get_string('personalquiz_updated', 'local_personalcourse'), null, \core\output\notification::NOTIFY_INFO);
        }
         // Clear old
        if (!$isRecent) {
             unset($SESSION->local_personalcourse_redirect);
        }
    }

    // Only for logged-in users.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Add "Dashboard" link for personal courses if accessible
    // Simple check: if student has data or is in the course
    $canviewdash = is_siteadmin() || $DB->record_exists('local_personalcourse_courses', ['courseid' => $COURSE->id]);
    
    if ($canviewdash) {
        $label = get_string('nav_dashboard', 'local_personalcourse');
        $url = new \moodle_url('/local/personalcourse/index.php');
        $node = $navigation->add(
            $label,
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'personalcourse_dashboard',
            new \pix_icon('t/viewdetails', $label)
        );
        $node->showinflatnavigation = true;
        if ($PAGE->url->compare($url, URL_MATCH_BASE)) {
            $node->make_active();
        }
    }

    // Also inject quiz question-count badges for personal course pages.
    // NOTE: Admins/Teachers often already see question counts via standard Moodle/Theme logic.
    // To avoid duplicates, we ONLY inject for users who DO NOT have manage capability (e.g. Students).
    $pagetype = (string)($PAGE->pagetype ?? '');
    $courseid = (!empty($COURSE) && !empty($COURSE->id)) ? (int)$COURSE->id : 0;

    $context = \context_course::instance($courseid);
    if (has_capability('moodle/course:manageactivities', $context)) {
        return; 
    }

    if ($courseid > 0 && strpos($pagetype, 'course-view') === 0) {
        $ispc = $DB->record_exists('local_personalcourse_courses', ['courseid' => $courseid]);
        if ($ispc) {
            // Build question counts server-side to avoid any client-side 404s.
            $rows = $DB->get_records_sql("
               SELECT cm.id AS cmid, COUNT(qs.id) AS cnt
                 FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 JOIN {quiz} q ON q.id = cm.instance
            LEFT JOIN {quiz_slots} qs ON qs.quizid = q.id
                WHERE cm.course = ? AND COALESCE(cm.deletioninprogress,0)=0
             GROUP BY cm.id",
                [$courseid]
            );
            $bycm = [];
            if (!empty($rows)) { foreach ($rows as $r) { $bycm[(int)$r->cmid] = (int)$r->cnt; } }
            $bycmjson = json_encode($bycm);
            $js2 = <<<JS
(function(){
  if (window.__pcqCountsInit) return; window.__pcqCountsInit = true;
  document.addEventListener('DOMContentLoaded', function(){
    try {
      function ensureStyle(){
        if (document.querySelector('style[data-pcq]')) return;
        var st = document.createElement('style'); st.setAttribute('data-pcq','1');
        st.textContent = '.pcq-qcount-badge{display:inline-block;margin-left:.5rem;padding:0 .5rem;font-size:.8rem;line-height:1.4;border-radius:999px;color:#3c4043;background:#eef3f8;border:1px solid #d6e0ea;vertical-align:baseline;white-space:nowrap}';
        document.head.appendChild(st);
      }
      ensureStyle();
      try {
            var bycm = {$bycmjson};
            if (!bycm || Object.keys(bycm).length === 0) return;
            function attach(){
              try {
                Object.keys(bycm).forEach(function(k){
                  var cmid = Number(k); var count = bycm[k];
                  // Prefer container by data-cmid.
                  var container = document.querySelector('.activity[data-cmid="' + cmid + '"] .activityinstance, .activity[data-cmid="' + cmid + '"]');
                  var anchor = null;
                  if (!container) {
                    // Fallback for older Moodle / theme variants
                    var list = document.querySelectorAll('#module-' + cmid + ', #module-' + cmid + ' .activityinstance');
                    if (list.length) {
                       for(var i=0; i<list.length; i++) {
                          var cand = list[i];
                          if (cand.closest('.activity') || cand.closest('.activityinstance') || cand.closest('.modtype_quiz')) { anchor = cand; break; }
                        }
                        if (!anchor) anchor = list[0];
                    }
                  }
                  if (anchor) {
                      var inst = anchor.querySelector('.instancename, .activityname');
                      
                      // Check existence to prevent infinite loop
                      if (anchor.querySelector('.pcq-qcount-badge[data-cmid="' + cmid + '"]')) return;
                      // Also check inside inst just in case
                      if (inst && inst.querySelector('.pcq-qcount-badge[data-cmid="' + cmid + '"]')) return;

                      var b = document.createElement('span'); b.className = 'pcq-qcount-badge';
                      b.setAttribute('data-cmid', String(cmid)); b.textContent = (count === 1 ? '1 question' : (count + ' questions'));

                      if (inst) {
                          // Append INSIDE the name container to keep it inline
                          inst.appendChild(b);
                      } else {
                          // Fallback
                          anchor.appendChild(b);
                      }
                  }
                });
              } catch (e) {}
            }
            attach();
            var tgt = document.querySelector('#page') || document.body;
            if (tgt && window.MutationObserver) {
              var mo = new MutationObserver(function(){ try { attach(); } catch(e){} });
              mo.observe(tgt, {childList:true, subtree:true});
            }
            setTimeout(function(){ try { attach(); } catch(e){} }, 1000);
      } catch (e) {}
    } catch (e) {}
  });
})();
JS;
            $PAGE->requires->js_init_code($js2);
        }
    }
}

function local_personalcourse_before_footer() {
    global $SESSION, $PAGE, $USER;

    // Check if we need to redirect the user after an in-progress attempt was deleted
    if (!empty($SESSION->local_personalcourse_redirect) && isloggedin() && !isguestuser()) {
        $redirect = $SESSION->local_personalcourse_redirect;

        // Only redirect if:
        // 1. The redirect was set recently (within 60 seconds)
        // 2. The user is the one who had their attempt deleted
        // 3. The current page is NOT already the quiz view page
        $isRecent = (time() - ($redirect->time ?? 0)) < 60;
        $isCorrectUser = ((int)$USER->id === (int)($redirect->userid ?? 0));

        // Check if current page is a quiz page that might be invalid now
        $currentUrl = $PAGE->url->out(false);
        // Broaden check: Any quiz page (attempt, summary, review)
        $isQuizPage = (strpos($currentUrl, '/mod/quiz/') !== false);

        // Don't redirect if we are already on the target URL
        // Loose check to avoid loop
        $isTargetPage = (strpos($currentUrl, $redirect->url) !== false);

        if ($isRecent && $isCorrectUser && $isQuizPage && !$isTargetPage) {
            // Clear the session variable
            unset($SESSION->local_personalcourse_redirect);

            // Redirect
            error_log("[local_personalcourse] Redirecting user to: " . $redirect->url);
            redirect($redirect->url, get_string('personalquiz_updated', 'local_personalcourse'), null, \core\output\notification::NOTIFY_INFO);
        }

        // Clear old redirects (older than 60 seconds)
        if (!$isRecent) {
            unset($SESSION->local_personalcourse_redirect);
        }
    }
}
