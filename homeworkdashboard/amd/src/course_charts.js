/**
 * Course chart injector - injects homework progress charts into course page
 */
define(['jquery'], function($) {
    return {
        init: function(courseid, coursename) {
            var self = this;
            
            // Wait for DOM to be ready
            $(document).ready(function() {
                // Find the course content area - look for the first section
                var targetContainer = null;
                
                // Try different selectors for course content
                var selectors = [
                    '#region-main .course-content',
                    '#region-main [data-region="course-content"]',
                    '#region-main .topics',
                    '#region-main ul.weeks',
                    '#region-main ul.topics'
                ];
                
                for (var i = 0; i < selectors.length; i++) {
                    var el = $(selectors[i]);
                    if (el.length > 0) {
                        targetContainer = el;
                        break;
                    }
                }
                
                if (!targetContainer) {
                    console.log('Course chart injector: No target container found');
                    return;
                }
                
                // Fetch chart data
                $.ajax({
                    url: M.cfg.wwwroot + '/local/homeworkdashboard/ajax_get_course_charts.php',
                    data: { courseid: courseid },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.data && response.data.length > 0) {
                            var chartHtml = self.buildChartHtml(response.coursename, response.data);
                            targetContainer.prepend(chartHtml);
                            self.renderCharts(response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('Course chart injector error:', error);
                    }
                });
            });
        },
        
        buildChartHtml: function(coursename, data) {
            var uid = Date.now();
            
            var html = '<div id="homework-charts-container" class="mb-4">';
            html += '<div class="row">';
            
            // Chart 1: Student Points (Live / 2wk / 4wk)
            html += '<div class="col-md-6 mb-3">';
            html += '<div class="card">';
            html += '<div class="card-header bg-primary text-white"><strong>' + coursename + ': Student Points (Live / 2wk / 4wk)</strong></div>';
            html += '<div class="card-body" style="height: 350px; padding: 10px;"><canvas id="injected-chart-points-' + uid + '"></canvas></div>';
            html += '</div></div>';
            
            // Chart 2: All Time Points & Class Level
            html += '<div class="col-md-6 mb-3">';
            html += '<div class="card">';
            html += '<div class="card-header bg-primary text-white"><strong>' + coursename + ': All Time Points & Class Level</strong></div>';
            html += '<div class="card-body" style="height: 350px; padding: 10px;"><canvas id="injected-chart-alltime-' + uid + '"></canvas></div>';
            html += '</div></div>';
            
            html += '</div></div>';
            
            // Store uid for chart rendering
            this.chartUid = uid;
            
            return html;
        },
        
        renderCharts: function(data) {
            var self = this;
            var uid = this.chartUid;
            
            // Load Chart.js if not already loaded
            if (typeof Chart === 'undefined') {
                var script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                script.onload = function() {
                    self.createCharts(data, uid);
                };
                document.head.appendChild(script);
            } else {
                self.createCharts(data, uid);
            }
        },
        
        createCharts: function(data, uid) {
            var labels = data.map(function(d) { return d.name; });
            var liveData = data.map(function(d) { return d.live; });
            var w2Data = data.map(function(d) { return d.w2; });
            var w4Data = data.map(function(d) { return d.w4; });
            var goalLiveData = data.map(function(d) { return d.goal_live; });
            var alltimeData = data.map(function(d) { return d.alltime; });
            var goalAllData = data.map(function(d) { return d.goal_all; });
            var levelData = data.map(function(d) { return d.level; });
            
            // Chart 1: Student Points with stacked bars
            var ctx1 = document.getElementById('injected-chart-points-' + uid);
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
                        scales: {
                            x: { 
                                beginAtZero: true,
                                title: { display: true, text: 'Points' }
                            },
                            y: { ticks: { font: { size: 11 } } }
                        },
                        plugins: {
                            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } }
                        }
                    },
                    plugins: [{
                        id: 'goalDots',
                        afterDatasetsDraw: function(chart) {
                            var chartCtx = chart.ctx;
                            var xAxis = chart.scales.x;
                            var yAxis = chart.scales.y;
                            
                            goalLiveData.forEach(function(goal, index) {
                                var x = xAxis.getPixelForValue(goal);
                                var y = yAxis.getPixelForValue(index);
                                
                                chartCtx.beginPath();
                                chartCtx.arc(x, y, 5, 0, 2 * Math.PI);
                                chartCtx.fillStyle = 'rgba(220, 53, 69, 1)';
                                chartCtx.fill();
                            });
                        }
                    }]
                });
            }
            
            // Chart 2: All Time Points with level badges
            var ctx2 = document.getElementById('injected-chart-alltime-' + uid);
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'All Time Points',
                            data: alltimeData,
                            backgroundColor: 'rgba(255, 159, 64, 0.8)',
                            borderRadius: 2
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { 
                                beginAtZero: true,
                                title: { display: true, text: 'All Time Points' }
                            },
                            y: { ticks: { font: { size: 11 } } }
                        },
                        plugins: {
                            legend: { display: false }
                        }
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
                                
                                // Draw level badge
                                chartCtx.fillStyle = '#17a2b8';
                                chartCtx.beginPath();
                                chartCtx.roundRect(x, y - 10, 35, 20, 3);
                                chartCtx.fill();
                                
                                chartCtx.fillStyle = 'white';
                                chartCtx.font = 'bold 10px Arial';
                                chartCtx.textAlign = 'center';
                                chartCtx.fillText('Lv.' + level, x + 17, y + 4);
                            });
                            
                            // Draw goal dots
                            goalAllData.forEach(function(goal, index) {
                                var x = xAxis.getPixelForValue(goal);
                                var y = yAxis.getPixelForValue(index);
                                
                                chartCtx.beginPath();
                                chartCtx.arc(x, y, 5, 0, 2 * Math.PI);
                                chartCtx.fillStyle = 'rgba(220, 53, 69, 1)';
                                chartCtx.fill();
                            });
                        }
                    }]
                });
            }
        }
    };
});
