define(['core/config'], function(Config) {
    "use strict";

    function createNav() {
        if (document.querySelector('.quiz-dashboard-global-nav')) { return; }
        var nav = document.createElement('div');
        nav.className = 'quiz-dashboard-global-nav';
        nav.style.cssText = 'position:fixed;top:60px;right:60px;z-index:10001;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif';
        var wwwroot = (Config && Config.wwwroot) ? Config.wwwroot : '';
        nav.innerHTML = '' +
            '<div class="nav-dropdown" style="position:relative;display:inline-block">' +
            '  <button class="nav-button" style="background:#007cba;color:#fff;padding:8px 12px;border:1px solid #005a87;cursor:pointer;border-radius:4px;font-size:13px;min-width:100px;text-align:center;box-shadow:0 2px 5px rgba(0,0,0,0.2)">Dashboards</button>' +
            '  <div class="nav-menu" style="display:none;position:absolute;right:0;top:calc(100% + 2px);background:#fff;min-width:160px;box-shadow:0 2px 5px rgba(0,0,0,0.2);border:1px solid #dee2e6;border-radius:4px">' +
            '    <a href="' + wwwroot + '/local/quizdashboard/index.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px">Quiz Dashboard</a>' +
            '    <a href="' + wwwroot + '/local/quizdashboard/essays.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px">Essay Dashboard</a>' +
            '    <a href="' + wwwroot + '/local/quizdashboard/questions.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px">Questions Dashboard</a>' +
            '    <a href="' + wwwroot + '/local/essaysmaster/dashboard.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;border-bottom:1px solid #dee2e6;font-size:13px">EssaysMaster Dashboard</a>' +
            '    <a href="' + wwwroot + '/local/quiz_uploader/upload.php" style="display:block;padding:8px 12px;color:#007cba;text-decoration:none;font-size:13px">Quiz Uploader</a>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(nav);
        var dropdown = nav.querySelector('.nav-dropdown');
        var menu = nav.querySelector('.nav-menu');
        var button = nav.querySelector('.nav-button');
        dropdown.addEventListener('mouseenter', function(){ menu.style.display = 'block'; button.style.background = '#005a87'; });
        dropdown.addEventListener('mouseleave', function(){ menu.style.display = 'none'; button.style.background = '#007cba'; });
    }

    return {
        init: function() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', createNav);
            } else {
                createNav();
            }
        }
    };
});