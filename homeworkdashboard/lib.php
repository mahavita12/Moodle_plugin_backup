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
    global $PAGE, $COURSE, $CFG;
    
    // Only run on course view pages
    if (strpos($PAGE->pagetype, 'course-view') !== 0) {
        return '';
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
                html += '<div class=\"card\"><div class=\"card-header bg-primary text-white\"><strong>' + coursename + ': Intellect Points & Class Level</strong></div>';
                html += '<div class=\"card-body\" style=\"height: 300px; padding: 10px;\"><canvas id=\"chart-alltime-' + uid + '\"></canvas></div></div></div>';
                
                // Chart 2: Intellect Points (Live / 2wk / 4wk) (BOTTOM)
                html += '<div class=\"mb-3\">';
                html += '<div class=\"card\"><div class=\"card-header bg-primary text-white\"><strong>' + coursename + ': Intellect Points (Live / 2wk / 4wk)</strong></div>';
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
                                    
                                    // Leader gets additional star badge
                                    if (index === 0) {
                                        var starX = x + 42;
                                        chartCtx.fillStyle = '#FFD700';
                                        chartCtx.beginPath();
                                        chartCtx.roundRect(starX, y - 10, 25, 20, 3);
                                        chartCtx.fill();
                                        chartCtx.fillStyle = '#dc3545';
                                        chartCtx.font = 'bold 12px Arial';
                                        chartCtx.textAlign = 'center';
                                        chartCtx.fillText('★', starX + 12, y + 5);
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
    
    return $js;
}



