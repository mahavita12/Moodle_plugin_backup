<?php
$files = [
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/essay_grader.php',
    '/home/master/applications/srfshmcmyg/public_html/local/quizdashboard/classes/resubmission_grader.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    $search1 = "<br><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span>\\n" .
"        <br><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span>\\n" .
"        </li>";

    $replace1 = "<ul>\\n" .
"        <li><span style=\\\"color:#808080;\\\">Original: [student's mistake in grey]</span></li>\\n" .
"        <li><span style=\\\"color:#3399cc;\\\">Improved: [corrected version in blue]</span></li>\\n" .
"        </ul>\\n" .
"        <br><strong>IMPORTANT:</strong> You must supply EXACTLY TWO examples per priority. If you cannot find two, provide one or a hypothetical one. Do NOT supply more or less. Each example MUST be inside an `<li>` tag as shown.\\n" .
"        </li>";

    $new_content = str_replace($search1, $replace1, $content);
    
    if ($new_content !== $content) {
        file_put_contents($file, $new_content);
        echo "SUCCESS $file\\n";
    } else {
        echo "FAILED_TO_REPLACE $file\\n";
    }
}
