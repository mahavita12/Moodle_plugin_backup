define(['core/config'], function(Config) {
    "use strict";

    function patch() {
        var wwwroot = (Config && Config.wwwroot) ? Config.wwwroot : '';
        var href = wwwroot + '/local/personalcourse/index.php';
        var menu = document.querySelector('.quiz-dashboard-global-nav .nav-menu');
        if (!menu) { return false; }
        if (menu.querySelector('a[href="' + href + '"]')) { return true; }
        var a = document.createElement('a');
        a.href = href;
        a.textContent = 'Personal Course Dashboard';
        a.setAttribute('style', 'display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px');
        var anchors = menu.querySelectorAll('a');
        var inserted = false;
        for (var i = 0; i < anchors.length; i++) {
            if (anchors[i].href && anchors[i].href.indexOf('/local/quiz_uploader/upload.php') !== -1) {
                menu.insertBefore(a, anchors[i]);
                inserted = true;
                break;
            }
        }
        if (!inserted) { menu.appendChild(a); }
        return true;
    }

    function init() {
        var tries = 0;
        var timer = setInterval(function() {
            tries++;
            if (patch() || tries > 20) { clearInterval(timer); }
        }, 100);
        // Also attempt immediate run for pages where the nav already exists.
        patch();
    }

    return { init: init };
});
