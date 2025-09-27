<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * AJAX endpoint for getting question help from OpenAI
 *
 * @package    local_questionhelper
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once('lib.php');

require_login();

// Get parameters
$questiontext = required_param('questiontext', PARAM_RAW);
$options = required_param('options', PARAM_RAW);
$attemptid = required_param('attemptid', PARAM_INT);
$mode = optional_param('mode', 'help', PARAM_ALPHA);

// Validate user has access to the quiz attempt
try {
    $attempt = quiz_attempt::create($attemptid);
    if ($attempt->get_userid() !== $USER->id) {
        throw new moodle_exception('nopermission', 'local_questionhelper');
    }
} catch (Exception $e) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// Check if plugin is enabled and configured
if (!get_config('local_questionhelper', 'enabled') || !local_questionhelper_is_configured()) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: application/json');
    $prov = get_config('local_questionhelper', 'provider') ?: 'openai';
    header('X-AI-Provider: ' . $prov);
    echo json_encode(['success' => false, 'error' => 'Service not available', 'provider' => $prov]);
    exit;
}

try {
    $provider = get_config('local_questionhelper', 'provider');
    if ($provider === 'anthropic') {
        $response = call_anthropic($questiontext, $options, $mode);
    } else {
        $response = call_openai($questiontext, $options, $mode);
    }
    header('Content-Type: application/json');
    header('X-AI-Provider: ' . ($provider ?: 'openai'));
    $response['provider'] = $provider ?: 'openai';
    echo json_encode($response);
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    $prov = get_config('local_questionhelper', 'provider') ?: 'openai';
    header('X-AI-Provider: ' . $prov);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to generate help content. Please try again.',
        'retry' => true,
        'provider' => $prov
    ]);
}

/**
 * Call OpenAI API to generate help content
 *
 * @param string $questiontext The original question text
 * @param string $options The multiple choice options
 * @return array Response array with success status and content
 */
function call_openai($questiontext, $options, $mode = 'help') {
    $apikey = local_questionhelper_get_api_key();

    if (empty($apikey)) {
        throw new Exception('API key not configured');
    }

    $prompt = ($mode === 'challenge') ? build_challenge_prompt($questiontext, $options) : build_prompt($questiontext, $options);

    $model = get_config('local_questionhelper', 'openai_model');
    if (empty($model)) { $model = 'gpt-3.5-turbo'; }
    $data = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are helping students understand mathematical concepts. Create a SIMILAR but EASIER question that teaches the same key concept, then provide a brief explanation.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 500,
        'temperature' => 0.7
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        throw new Exception('OpenAI API error: ' . $httpcode);
    }

    $result = json_decode($response, true);

    if (!isset($result['choices'][0]['message']['content'])) {
        throw new Exception('Invalid response format');
    }

    $content = $result['choices'][0]['message']['content'];

    // Try to parse as JSON first
    $parsed = json_decode($content, true);
    if ($parsed && isset($parsed['practice_question']) && isset($parsed['options']) && isset($parsed['correct_answer'])) {
        return [
            'success' => true,
            'practice_question' => $parsed['practice_question'],
            'options' => $parsed['options'],
            'correct_answer' => $parsed['correct_answer'],
            'explanation' => $parsed['explanation'] ?? 'Answer explanation not provided.',
            'concept_explanation' => $parsed['concept'] ?? 'Concept explanation not provided.'
        ];
    }

    // If not properly formatted JSON, return error
    return [
        'success' => false,
        'error' => 'AI response was not in the expected format. Please try again.',
        'retry' => true
    ];
}

/**
 * Call Anthropic API (Claude Sonnet) to generate help content
 */
function call_anthropic($questiontext, $options, $mode = 'help') {
    $apikey = get_config('local_questionhelper', 'anthropic_apikey');
    $model = get_config('local_questionhelper', 'anthropic_model');
    // Normalise model; fall back to a valid Claude Sonnet model if a shorthand like "sonnet-4" is configured
    if (empty($model)) { $model = 'claude-3-5-sonnet-20241022'; }
    if (stripos($model, 'sonnet') === 0 || stripos($model, 'sonnet-4') === 0) {
        $model = 'claude-3-5-sonnet-20241022';
    }
    if (empty($apikey)) {
        throw new Exception('Anthropic API key not configured');
    }

    $prompt = ($mode === 'challenge') ? build_challenge_prompt($questiontext, $options) : build_prompt($questiontext, $options);

    $payload = [
        'model' => $model,
        'max_tokens' => 800,
        'temperature' => 0.7,
        'messages' => [
            [
                'role' => 'user',
                'content' => [ [ 'type' => 'text', 'text' => $prompt ] ]
            ]
        ]
    ];

    // Log the actual model being used
    error_log("🤖 Question Helper (Anthropic): Using model: " . $model);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'content-type: application/json',
        'accept: application/json',
        'x-api-key: ' . $apikey,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode !== 200) {
        $snippet = substr((string)$response, 0, 300);
        throw new Exception('Anthropic API error: ' . $httpcode . ' ' . $snippet);
    }

    $result = json_decode($response, true);
    // Expect content as an array of blocks; take the first text
    $content = '';
    if (isset($result['content'][0]['text'])) {
        $content = $result['content'][0]['text'];
    } elseif (isset($result['content']) && is_string($result['content'])) {
        $content = $result['content'];
    }

    if (!$content) {
        throw new Exception('Invalid Anthropic response');
    }

    $parsed = json_decode($content, true);
    if ($parsed && isset($parsed['practice_question']) && isset($parsed['options']) && isset($parsed['correct_answer'])) {
        return [
            'success' => true,
            'practice_question' => $parsed['practice_question'],
            'options' => $parsed['options'],
            'correct_answer' => $parsed['correct_answer'],
            'explanation' => $parsed['explanation'] ?? 'Answer explanation not provided.',
            'concept_explanation' => $parsed['concept'] ?? 'Concept explanation not provided.'
        ];
    }

    return [
        'success' => false,
        'error' => 'AI response was not in the expected format. Please try again.',
        'retry' => true
    ];
}

/**
 * Build the prompt for OpenAI
 *
 * @param string $questiontext Original question text
 * @param string $options Multiple choice options
 * @return string The complete prompt
 */
function build_prompt($questiontext, $options) {
    $prompt = "Original question: \"" . strip_tags($questiontext) . "\"\n\n";
    $prompt .= "Multiple choice options: " . strip_tags($options) . "\n\n";
    $prompt .= "Create a SIMILAR but EASIER question that teaches the same key concept.\n\n";
    $prompt .= "Requirements:\n";
    $prompt .= "- Same question type (multiple choice with exactly 4 options)\n";
    $prompt .= "- Easier numbers/complexity but same mathematical concept\n";
    $prompt .= "- Focus on the core mathematical principle\n";
    $prompt .= "- Include the correct answer (A, B, C, or D)\n";
    $prompt .= "- Provide a detailed explanation for why the correct answer is right\n";
    $prompt .= "- Brief concept explanation to help understanding\n";
    $prompt .= "- DO NOT provide the answer to the original question\n";
    $prompt .= "- Format response as JSON with this exact structure:\n";
    $prompt .= "{\n";
    $prompt .= "  \"practice_question\": \"The question text here\",\n";
    $prompt .= "  \"options\": {\n";
    $prompt .= "    \"A\": \"First option text\",\n";
    $prompt .= "    \"B\": \"Second option text\",\n";
    $prompt .= "    \"C\": \"Third option text\",\n";
    $prompt .= "    \"D\": \"Fourth option text\"\n";
    $prompt .= "  },\n";
    $prompt .= "  \"correct_answer\": \"A\",\n";
    $prompt .= "  \"explanation\": \"Detailed explanation of why this answer is correct\",\n";
    $prompt .= "  \"concept\": \"Brief concept explanation (max 100 words)\"\n";
    $prompt .= "}\n";

    return $prompt;
}

/**
 * Build challenge prompt: generate a HARDER question with the same concept
 */
function build_challenge_prompt($questiontext, $options) {
    $prompt = "Original question: \"" . strip_tags($questiontext) . "\"\n\n";
    $prompt .= "Multiple choice options: " . strip_tags($options) . "\n\n";
    $prompt .= "Create a SIMILAR but HARDER question that tests the same key concept but with higher complexity.\n\n";
    $prompt .= "Requirements:\n";
    $prompt .= "- Same question type (multiple choice with exactly 4 options)\n";
    $prompt .= "- Increased difficulty (more complex numbers/steps or trickier reasoning)\n";
    $prompt .= "- Preserve the same underlying mathematical principle\n";
    $prompt .= "- Include the correct answer (A, B, C, or D)\n";
    $prompt .= "- Provide a detailed explanation for why the correct answer is right\n";
    $prompt .= "- Brief concept explanation to help understanding\n";
    $prompt .= "- DO NOT provide the answer to the original question\n";
    $prompt .= "- Format response as JSON with this exact structure:\n";
    $prompt .= "{\n";
    $prompt .= "  \"practice_question\": \"The question text here\",\n";
    $prompt .= "  \"options\": {\n";
    $prompt .= "    \"A\": \"First option text\",\n";
    $prompt .= "    \"B\": \"Second option text\",\n";
    $prompt .= "    \"C\": \"Third option text\",\n";
    $prompt .= "    \"D\": \"Fourth option text\"\n";
    $prompt .= "  },\n";
    $prompt .= "  \"correct_answer\": \"A\",\n";
    $prompt .= "  \"explanation\": \"Detailed explanation of why this answer is correct\",\n";
    $prompt .= "  \"concept\": \"Brief concept explanation (max 100 words)\"\n";
    $prompt .= "}\n";

    return $prompt;
}