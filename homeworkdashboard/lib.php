<?php
defined('MOODLE_INTERNAL') || die();

use core_calendar\local\event\entities\event_interface;

/**
 * Calendar event hook to show homework status.
 */
function local_homeworkdashboard_calendar_get_event_homework_status(event_interface $event): ?string {
    global $USER, $DB;

    if (empty($USER) || empty($USER->id)) {
        return null;
    }

    if ($event->get_component() !== 'mod_quiz') {
        return null;
    }

    if ($event->get_type() !== 'close') {
        return null;
    }

    $eventid = $event->get_id();
    if ($eventid <= 0) {
        return null;
    }

    $ev = $DB->get_record('event', ['id' => $eventid], 'id, courseid, instance, modulename, eventtype, timestart', IGNORE_MISSING);
    if (!$ev) {
        return null;
    }

    if ($ev->modulename !== 'quiz' || $ev->eventtype !== 'close') {
        return null;
    }

    $courseid = (int)$ev->courseid;
    $quizid = (int)$ev->instance;
    $timeclose = (int)$ev->timestart;

    if ($courseid <= 0 || $quizid <= 0 || $timeclose <= 0) {
        return null;
    }

    $manager = new \local_homeworkdashboard\homework_manager();

    return $manager->get_homework_status_for_user_quiz_event(
        (int)$USER->id,
        $quizid,
        $courseid,
        $timeclose
    );
}

/**
 * Hook to inject course charts on course pages for Category 1, 2, and 3 courses.
 */
function local_homeworkdashboard_before_standard_html_head() {
    global $PAGE, $COURSE, $CFG, $USER;
    
    $output = '';
    
    // ==========================================
    // PART 1: Gamification Stats Navbar Injection (ALL PAGES)
    // ==========================================
    if (isloggedin() && !isguestuser()) {
        $output .= local_homeworkdashboard_render_gamification_navbar();
    }
    

    
    // ==========================================
    // PART 1B: My Courses Page - Leader Charts Injection
    // ==========================================
    if (strpos($PAGE->pagetype, 'my-') === 0) {
        $output .= local_homeworkdashboard_render_mycourses_charts();
    }
    
    // ==========================================
    // PART 2: Course Charts (COURSE PAGES ONLY)
    // ==========================================
    // Only run on course view pages
    if (strpos($PAGE->pagetype, 'course-view') !== 0) {
        return $output;
    }
    
    // Only for Category 1, 2, or 3 courses
    $categoryid = $COURSE->category ?? 0;
    if ($categoryid == 0 || $categoryid > 3 || $COURSE->id <= 1) {
        return '';
    }
    
    $courseid = $COURSE->id;
    $coursename = format_string($COURSE->fullname);
    $ajaxurl = $CFG->wwwroot . '/local/homeworkdashboard/ajax_get_course_charts.php';
    
    // Inject inline JavaScript
    $js = "
    <script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var courseid = {$courseid};
        var coursename = " . json_encode($coursename) . ";
        var ajaxurl = " . json_encode($ajaxurl) . ";
        
        // Find target container
        var target = document.querySelector('#region-main .course-content') ||
                     document.querySelector('#region-main [data-region=\"course-content\"]') ||
                     document.querySelector('#region-main .topics') ||
                     document.querySelector('#region-main ul.topics');
        
        if (!target) {
            console.log('Homework charts: No target container found');
            return;
        }
        
        // Fetch chart data
        fetch(ajaxurl + '?courseid=' + courseid)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success || !data.data || data.data.length === 0) {
                    console.log('Homework charts: No data available');
                    return;
                }
                
                var chartData = data.data;
                var uid = Date.now();
                
                // Build chart HTML - stacked layout with All Time on top
                var html = '<div id=\"homework-charts-container\" style=\"margin-bottom: 20px;\">';
                
                // Chart 1: Intellect Points & Class Level (TOP)
                html += '<div class=\"mb-3\">';
                html += '<div class=\"card\"><div class=\"card-header bg-primary text-white\"><strong>' + coursename + '</strong></div>';
                html += '<div class=\"card-body\" style=\"height: 300px; padding: 10px;\"><canvas id=\"chart-alltime-' + uid + '\"></canvas></div></div></div>';
                
                // Chart 2: Intellect Points (Live / 2wk / 4wk) (BOTTOM)
                html += '<div class=\"mb-3\">';
                html += '<div class=\"card\"><div class=\"card-header bg-primary text-white\"><strong>' + coursename + ': Intellect Points</strong></div>';
                html += '<div class=\"card-body\" style=\"height: 300px; padding: 10px;\"><canvas id=\"chart-points-' + uid + '\"></canvas></div></div>';
                
                html += '</div>';
                
                // Insert at top of course content
                target.insertAdjacentHTML('afterbegin', html);
                
                // Render charts
                var labels = chartData.map(function(d) { return d.name; });
                var liveData = chartData.map(function(d) { return d.live; });
                var w2Data = chartData.map(function(d) { return d.w2; });
                var w4Data = chartData.map(function(d) { return d.w4; });
                var goalLiveData = chartData.map(function(d) { return d.goal_live; });
                var goal2wData = chartData.map(function(d) { return d.goal_2w; });
                var goal4wData = chartData.map(function(d) { return d.goal_4w; });
                var alltimeData = chartData.map(function(d) { return d.alltime; });
                var goalAllData = chartData.map(function(d) { return d.goal_all; });
                var levelData = chartData.map(function(d) { return d.level; });
                
                // Chart 1
                var ctx1 = document.getElementById('chart-points-' + uid);
                if (ctx1) {
                    new Chart(ctx1, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [
                                { label: 'Live', data: liveData, backgroundColor: 'rgba(25, 135, 84, 0.8)', borderRadius: 2 },
                                { label: '2 Weeks', data: w2Data, backgroundColor: 'rgba(13, 110, 253, 0.8)', borderRadius: 2 },
                                { label: '4 Weeks', data: w4Data, backgroundColor: 'rgba(111, 66, 193, 0.8)', borderRadius: 2 }
                            ]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            layout: { padding: { right: 60 } },
                            scales: { x: { beginAtZero: true, title: { display: false } }, y: { ticks: { font: { size: 11 } } } },
                            plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } }
                        },
                        plugins: []
                    });
                }
                
                // Chart 2
                var ctx2 = document.getElementById('chart-alltime-' + uid);
                if (ctx2) {
                    new Chart(ctx2, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{ 
                                label: 'All Time Points', 
                                data: alltimeData, 
                                backgroundColor: function(context) {
                                    if (context.dataIndex === 0) {
                                        var chart = context.chart;
                                        var ctx = chart.ctx;
                                        var gradient = ctx.createLinearGradient(0, 0, chart.width, 0);
                                        gradient.addColorStop(0, '#FFD700');
                                        gradient.addColorStop(0.5, '#FFA500');
                                        gradient.addColorStop(1, '#FF6B00');
                                        return gradient;
                                    }
                                    return 'rgba(13, 110, 253, 0.8)';
                                },
                                borderRadius: 2 
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            layout: { padding: { right: 60 } },
                            scales: { x: { beginAtZero: true, title: { display: false } }, y: { ticks: { font: { size: 11 } } } },
                            plugins: { legend: { display: false } }
                        },
                        plugins: [{
                            id: 'levelBadges',
                            afterDatasetsDraw: function(chart) {
                                var chartCtx = chart.ctx;
                                var xAxis = chart.scales.x;
                                var yAxis = chart.scales.y;
                                var meta = chart.getDatasetMeta(0);
                                meta.data.forEach(function(bar, index) {
                                    var level = levelData[index];
                                    var x = bar.x + 10;
                                    var y = bar.y;
                                    
                                    // Level badge for everyone
                                    chartCtx.fillStyle = '#17a2b8';
                                    chartCtx.beginPath();
                                    chartCtx.roundRect(x, y - 10, 35, 20, 3);
                                    chartCtx.fill();
                                    chartCtx.fillStyle = 'white';
                                    chartCtx.font = 'bold 10px Arial';
                                    chartCtx.textAlign = 'center';
                                    chartCtx.fillText('Lv.' + level, x + 17, y + 4);
                                    
                                    // Medal badges: Gold for 1st, Silver for 2nd
                                    if (index === 0) {
                                        chartCtx.font = '14px Arial';
                                        chartCtx.textAlign = 'left';
                                        chartCtx.fillText('🥇', x + 42, y + 5);
                                    } else if (index === 1) {
                                        chartCtx.font = '14px Arial';
                                        chartCtx.textAlign = 'left';
                                        chartCtx.fillText('🥈', x + 42, y + 5);
                                    }
                                });

                            }
                        }]
                    });
                }
            })
            .catch(function(error) {
                console.log('Homework charts error:', error);
            });
    });
    </script>
    ";
    
    return $output . $js;
}

/**
 * Render gamification stats for navbar injection.
 */
/**
 * Inject leader charts on My Courses page for Category 1, 2, 3 courses.
 * Uses card-based styling matching the course page charts.
 */
function local_homeworkdashboard_render_mycourses_charts(): string {
    global $CFG;
    $ajaxurl = $CFG->wwwroot . '/local/homeworkdashboard/ajax_get_course_charts.php';
    
    return "
    <script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>
    <style>
    .mycourses-chart-card {
        margin: 15px 0;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .mycourses-chart-card .chart-header {
        background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
        color: white;
        padding: 10px 15px;
        font-weight: bold;
        font-size: 13px;
    }
    .mycourses-chart-card .chart-body {
        background: white;
        padding: 15px;
        height: 180px;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ajaxurl = " . json_encode($ajaxurl) . ";
        var excludeCategories = ['Personal Review Courses'];
        var processedCourses = {};
        
        function injectCharts() {
            console.log('My Courses chart injection starting...');
            var courseItems = document.querySelectorAll('li.list-group-item.course-listitem[data-course-id]');
            console.log('Found course items:', courseItems.length);
            
            courseItems.forEach(function(item) {
                var courseId = item.getAttribute('data-course-id');
                console.log('Processing course:', courseId);
                if (!courseId || processedCourses[courseId]) return;
                
                var categoryEl = item.querySelector('.categoryname');
                var categoryText = categoryEl ? categoryEl.textContent.trim() : '';
                console.log('Category:', categoryText);
                if (excludeCategories.includes(categoryText)) {
                    console.log('Skipping Personal Review');
                    return;
                }
                
                var courseNameEl = item.querySelector('a.coursename');
                // Get only direct text content, not sr-only spans
                var courseName = 'Course';
                if (courseNameEl) {
                    var clone = courseNameEl.cloneNode(true);
                    clone.querySelectorAll('.sr-only, [data-region]').forEach(function(el) { el.remove(); });
                    courseName = clone.textContent.trim();
                }
                
                processedCourses[courseId] = true;
                
                var chartCard = document.createElement('li');
                chartCard.className = 'mycourses-chart-card list-group-item border-0 p-0';
                chartCard.innerHTML = '<div class=\"card mb-3\" style=\"border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);\">' +
                                      '<div class=\"card-header text-white\" style=\"background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%);padding:10px 15px;font-weight:bold;font-size:13px;\">' + courseName + '</div>' +
                                      '<div class=\"card-body\" style=\"height:180px;padding:15px;background:#fff;\"><div style=\"text-align:center;padding-top:60px;\"><small>Loading...</small></div></div></div>';
                
                item.parentNode.insertBefore(chartCard, item.nextSibling);
                
                fetch(ajaxurl + '?courseid=' + courseId)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success || !data.data || data.data.length === 0) {
                            chartCard.remove();
                            return;
                        }
                        
                        var uid = 'mc-' + courseId;
                        var chartData = data.data.slice(0, 5);
                        var labels = chartData.map(function(d) { return d.name; });
                        var values = chartData.map(function(d) { return d.alltime; });
                        var levels = chartData.map(function(d) { return d.level; });
                        
                        chartCard.querySelector('.card-body').innerHTML = '<canvas id=\"' + uid + '\"></canvas>';
                        
                        var ctx = document.getElementById(uid);
                        if (!ctx) return;
                        
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Intellect Points',
                                    data: values,
                                    backgroundColor: function(context) {
                                        if (context.dataIndex === 0) {
                                            var chart = context.chart;
                                            var gradient = chart.ctx.createLinearGradient(0, 0, chart.width * 0.7, 0);
                                            gradient.addColorStop(0, '#FFD700');
                                            gradient.addColorStop(0.5, '#FFA500');
                                            gradient.addColorStop(1, '#FF6B00');
                                            return gradient;
                                        }
                                        return 'rgba(13, 110, 253, 0.85)';
                                    },
                                    borderRadius: 3
                                }]
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: { padding: { right: 60 } },
                                scales: {
                                    x: { beginAtZero: true, grid: { display: true, color: '#eee' } },
                                    y: { ticks: { font: { size: 11 } }, grid: { display: false } }
                                },
                                plugins: { legend: { display: false } }
                            },
                            plugins: [{
                                afterDraw: function(chart) {
                                    var ctx = chart.ctx;
                                    chart.data.labels.forEach(function(label, index) {
                                        var meta = chart.getDatasetMeta(0);
                                        var bar = meta.data[index];
                                        var level = levels[index] || 1;
                                        
                                        // Level badge
                                        ctx.save();
                                        ctx.fillStyle = '#28a745';
                                        ctx.beginPath();
                                        ctx.roundRect(bar.x + 5, bar.y - 10, 35, 20, 4);
                                        ctx.fill();
                                        ctx.fillStyle = '#fff';
                                        ctx.font = 'bold 10px Arial';
                                        ctx.textAlign = 'center';
                                        ctx.textBaseline = 'middle';
                                        ctx.fillText('Lvl ' + level, bar.x + 22, bar.y);
                                        ctx.restore();
                                        
                                        // Medal emoji
                                        if (index === 0) {
                                            ctx.font = '14px Arial';
                                            ctx.fillText('🥇', bar.x + 48, bar.y);
                                        } else if (index === 1) {
                                            ctx.font = '14px Arial';
                                            ctx.fillText('🥈', bar.x + 48, bar.y);
                                        }
                                    });
                                }
                            }]
                        });
                    })
                    .catch(function(e) { chartCard.remove(); });
            });
        }
        
        // Use MutationObserver to wait for courses to load dynamically
        var observer = new MutationObserver(function(mutations, obs) {
            var courseItems = document.querySelectorAll('li.list-group-item.course-listitem[data-course-id]');
            if (courseItems.length > 0) {
                console.log('MutationObserver found courses:', courseItems.length);
                injectCharts();
            }
        });
        
        // Start observing after short delay to let page structure load
        setTimeout(function() {
            var target = document.querySelector('[data-region=\"myoverview\"]') || document.querySelector('#page-content') || document.body;
            if (target) {
                observer.observe(target, { childList: true, subtree: true });
            }
            // Also try immediately in case courses already loaded
            injectCharts();
        }, 500);
    });
    </script>
    ";
}

function local_homeworkdashboard_render_gamification_navbar(): string {
    global $CFG;
    
    $ajaxurl = $CFG->wwwroot . '/local/homeworkdashboard/ajax_get_gamification_stats.php';
    
    $js = "
    <style>
    .gm-navbar-stats {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-right: 15px;
        padding: 4px 0;
    }
    .gm-navbar-stats .gm-stat-pill {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: white;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        white-space: nowrap;
    }
    .gm-navbar-stats .gm-stat-pill:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0,0,0,0.2);
    }
    .gm-navbar-stats .gm-stat-pill.gm-overall {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    }
    .gm-navbar-stats .gm-stat-pill.gm-course {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .gm-navbar-stats .gm-stat-icon {
        font-size: 14px;
    }
    .gm-navbar-stats .gm-stat-level {
        background: rgba(255,255,255,0.25);
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 11px;
    }
    .gm-navbar-stats .gm-stat-points {
        opacity: 0.9;
        font-size: 11px;
    }
    /* Tooltip for full course name */
    .gm-stat-pill[title] {
        position: relative;
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Find the user menu container
        var navbar = document.querySelector('.usermenu-container') || 
                     document.querySelector('.usermenu') ||
                     document.querySelector('nav.navbar .navbar-nav.d-none.d-md-flex');
        
        if (!navbar) {
            console.log('Gamification: navbar container not found');
            return;
        }
        
        // Create stats container
        var statsContainer = document.createElement('div');
        statsContainer.className = 'gm-navbar-stats';
        statsContainer.innerHTML = '<span style=\"color:#999;font-size:12px;\">Loading stats...</span>';
        
        // Insert before the user menu
        navbar.parentNode.insertBefore(statsContainer, navbar);
        
        // Fetch stats via AJAX
        fetch('{$ajaxurl}')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data.success) {
                    statsContainer.innerHTML = '';
                    return;
                }
                
                var html = '';
                
                // Overall stats pill only (no course stats in navbar) - links to leaderboard
                var leaderboardUrl = M.cfg.wwwroot + '/local/homeworkdashboard/index.php?tab=leaderboard';
                html += '<a href=\"' + leaderboardUrl + '\" target=\"_blank\" class=\"gm-stat-pill gm-overall\" title=\"Total Intellect Points - Click to view Leaderboard\" style=\"text-decoration:none;\">';
                // Icon removed from navbar per user request
                if (data.overall.isLeader) {
                    html += '<span style=\"font-size:14px;margin-right:4px;\">🥇</span>';
                } else if (data.overall.isRunnerup) {
                    html += '<span style=\"font-size:14px;margin-right:4px;\">🥈</span>';
                }
                html += '<span class=\"gm-stat-level\">Lvl ' + data.overall.level + '</span>';
                html += '<span class=\"gm-stat-points\">' + Math.round(data.overall.points).toLocaleString() + ' IP</span>';
                html += '</a>';
                
                statsContainer.innerHTML = html;
            })
            .catch(function(error) {
                console.log('Gamification stats error:', error);
                statsContainer.innerHTML = '';
            });
    });
    </script>
    ";
    
    return $js;
}

