<?php
define('CLI_SCRIPT', true);
require_once(dirname(__FILE__) . '/../../config.php');

$config = get_config('local_homeworkdashboard');
$apiKey = $config->gemini_api_key;

if (empty($apiKey)) {
    die("Error: API Key not set in settings.\n");
}

$url = "https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

echo "Querying Google Gemini API for available models...\n";
echo "URL: $url\n\n";

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo "Curl Error: " . curl_error($ch) . "\n";
} else {
    $data = json_decode($response, true);
    if (isset($data['models'])) {
        echo "Available Models:\n";
        foreach ($data['models'] as $model) {
            echo "- " . $model['name'] . " (" . $model['displayName'] . ")\n";
        }
    } else {
        echo "Error Response:\n";
        print_r($data);
    }
}

curl_close($ch);
