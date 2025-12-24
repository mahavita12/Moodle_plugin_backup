<?php
defined('MOODLE_INTERNAL') || die();

function local_personalcourse_extend_navigation(\global_navigation $navigation) {
    global $PAGE, $CFG, $DB, $COURSE;

    // Only for logged-in users.
    if (!isloggedin() || isguestuser()) {
        return;
    }
 

    $context = \context_system::instance();
    $canviewdash = has_capability('local/personalcourse:viewdashboard', $context);
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

    // Also inject quiz question-count badges for personal course pages (for all viewers).
    $pagetype = (string)($PAGE->pagetype ?? '');
    $courseid = (!empty($COURSE) && !empty($COURSE->id)) ? (int)$COURSE->id : 0;
    if ($courseid > 0 && strpos($pagetype, 'course-view') === 0) {
        $ispc = $DB->record_exists('local_personalcourse_courses', ['courseid' => $courseid]);
        if ($ispc) {
            // Build question counts server-side to avoid any client-side 404s.
            $rows = $DB->get_records_sql(
                "SELECT cm.id AS cmid, COUNT(qs.id) AS cnt
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
            $js = <<<JS
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    try {
      function ensureStyle(){
        if (document.querySelector('style[data-pcq]')) return;
        var st = document.createElement('style');
        st.setAttribute('data-pcq','1');
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
                  var cmid = Number(k);
                  var count = bycm[k];
                  // Prefer container by data-cmid (robust for student view).
                  var container = document.querySelector('.activity[data-cmid="' + cmid + '"] .activityinstance, .activity[data-cmid="' + cmid + '"]');
                  var anchor = null;
                  if (!container) {
                    var selectors = ['a[href*="id='+cmid+'"]','a[data-action="view-activity"][href*="id='+cmid+'"]'];
                    for (var s=0; s<selectors.length && !anchor; s++){
                      var list = document.querySelectorAll(selectors[s]);
                      if (list && list.length){
                        for (var i=0; i<list.length; i++){
                          var cand = list[i];
                          if (cand.closest('.activity') || cand.closest('.activityinstance') || cand.closest('.modtype_quiz')) { anchor = cand; break; }
                        }
                        if (!anchor) anchor = list[0];
                      }
                    }
                    if (anchor) {
                      var inst = anchor.querySelector('.instancename');
                      if (inst && inst.parentNode === anchor) { container = anchor; }
                      else if (anchor.closest('.activityinstance')) { container = anchor.closest('.activityinstance'); }
                      else if (anchor.parentNode) { container = anchor.parentNode; }
                    }
                  }
                  if (!container) return;
                  if (container.querySelector('.pcq-qcount-badge[data-cmid="'+cmid+'"]')) return;
                  var b = document.createElement('span');
                  b.className = 'pcq-qcount-badge';
                  b.setAttribute('data-cmid', String(cmid));
                  b.textContent = (count === 1 ? '1 question' : (count + ' questions'));
                  container.appendChild(b);
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
            $PAGE->requires->js_init_code($js);
        }
    }
}

function local_personalcourse_before_footer() {
    return; // Disabled: Navigation moved to User Menu
}
