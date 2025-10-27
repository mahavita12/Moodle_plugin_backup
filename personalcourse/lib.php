<?php
defined('MOODLE_INTERNAL') || die();

function local_personalcourse_extend_navigation(\global_navigation $navigation) {
    global $PAGE;

    // Only for logged-in users.
    if (!isloggedin() || isguestuser()) {
        return;
    }
 

    $context = \context_system::instance();
    if (!has_capability('local/personalcourse:viewdashboard', $context)) {
        return;
    }

    // Add a custom node pointing to the dashboard (left navigation drawer).
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

function local_personalcourse_before_footer() {
    global $PAGE, $CFG;
    // Mirror quizdashboard guards to avoid interfering with print/clean pages.
    $clean = isset($_GET['clean']) ? (int)$_GET['clean'] : 0;
    $printp = isset($_GET['print']) ? (int)$_GET['print'] : 0;
    if ($PAGE->pagelayout === 'print' || $clean === 1 || $printp === 1) { return; }

    $sysctx = \context_system::instance();
    if (!has_capability('local/personalcourse:viewdashboard', $sysctx)) { return; }

    // Inject Personal Course link into floating Dashboards menu.
    $href = $CFG->wwwroot . '/local/personalcourse/index.php';
    $js = <<<JS
(function(){
  function patch(){
    var menu = document.querySelector('.quiz-dashboard-global-nav .nav-menu');
    if (!menu) { return false; }
    var href = '$href';
    if (menu.querySelector("a[href='" + href + "']")) { return true; }
    var a = document.createElement('a');
    a.href = href;
    a.textContent = 'Personal Course Dashboard';
    a.setAttribute('style', 'display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px');
    var anchors = menu.querySelectorAll('a'); var inserted = false;
    for (var i = 0; i < anchors.length; i++) {
      if (anchors[i].href && anchors[i].href.indexOf('/local/quiz_uploader/upload.php') !== -1) {
        menu.insertBefore(a, anchors[i]); inserted = true; break;
      }
    }
    if (!inserted) { menu.appendChild(a); }
    return true;
  }
  var tries = 0, t = setInterval(function(){ tries++; if (patch() || tries > 20) { clearInterval(t); } }, 100);
  patch();
})();
JS;
    $PAGE->requires->js_init_code($js);
}
