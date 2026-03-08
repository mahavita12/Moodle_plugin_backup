<?php
$file = '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/resubmission_grader.php';
$content = file_get_contents($file);

$search = "        <h2 style=\\\"font-size:18px;\\\">Overall Comments</h2>";

$replace = "        <h2 style=\\\"font-size:18px;\\\">Top 4 Priorities for Improvement</h2>\\n" .
"        <div id=\\\"top-priorities\\\">\\n" .
"        <p>Synthesize the feedback from all categories above and identify the 4 most critical areas the student must focus on improving in their next draft.</p>\\n" .
"        <ol>\\n" .
"        <li><strong>[Name of Priority 1]:</strong> Provide a 1-2 sentence explanation of why this is a priority. Then, provide exactly TWO distinct examples of this issue from the student's essay, formatted like this:\\n" .
"        <br><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span>\\n" .
"        <br><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span>\\n" .
"        </li>\\n" .
"        <li><strong>[Name of Priority 2]:</strong> Provide a 1-2 sentence explanation of why this is a priority. Then, provide exactly TWO distinct examples of this issue from the student's essay, formatted like this:\\n" .
"        <br><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span>\\n" .
"        <br><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span>\\n" .
"        </li>\\n" .
"        <li><strong>[Name of Priority 3]:</strong> Provide a 1-2 sentence explanation of why this is a priority. Then, provide exactly TWO distinct examples of this issue from the student's essay, formatted like this:\\n" .
"        <br><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span>\\n" .
"        <br><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span>\\n" .
"        </li>\\n" .
"        <li><strong>[Name of Priority 4]:</strong> Provide a 1-2 sentence explanation of why this is a priority. Then, provide exactly TWO distinct examples of this issue from the student's essay, formatted like this:\\n" .
"        <br><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span>\\n" .
"        <br><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span>\\n" .
"        </li>\\n" .
"        </ol>\\n" .
"        </div>\\n\\n" .
"        <h2 style=\\\"font-size:18px;\\\">Overall Comments</h2>";

$new_content = str_replace($search, $replace, $content);

if ($new_content !== $content) {
    file_put_contents($file, $new_content);
    echo "SUCCESS";
} else {
    echo "FAILED_TO_FIND";
}
