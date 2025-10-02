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
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');

// Allow longer execution for upstream AI calls
@set_time_limit(60);

require_login();

// Check for special action to resolve slot to question ID
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'resolve_question_id') {
    $slot = required_param('slot', PARAM_INT);
    $attemptid = required_param('attemptid', PARAM_INT);

    // Get the real question ID for this slot
    $questionid = get_question_id_from_slot($attemptid, $slot);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'questionid' => $questionid,
        'slot' => $slot
    ]);
    exit;
}

// Get parameters
$questiontext = required_param('questiontext', PARAM_RAW);
$options = required_param('options', PARAM_RAW);
$attemptid = required_param('attemptid', PARAM_INT);
$mode = optional_param('mode', 'help', PARAM_ALPHA);
$questionid = optional_param('questionid', 0, PARAM_INT); // Add question ID for caching

// Debug: Log all challenge requests
if ($mode === 'challenge') {
    error_log("CHALLENGE DEBUG - Request received: mode=$mode, questionid=$questionid, attemptid=$attemptid");
}

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
    // ROLLING GLOBAL STRATEGY: Prefer the newest between personal and global; sync personal to newer global
    if ($questionid > 0) {
        global $DB, $USER;

        // Load existing personal and global records
        $personal_record = $DB->get_record('local_qh_saved_help', [
            'userid' => $USER->id,
            'questionid' => $questionid,
            'variant' => $mode,
            'is_global' => 0
        ]);

        $global_record = $DB->get_record('local_qh_saved_help', [
            'questionid' => $questionid,
            'variant' => $mode,
            'is_global' => 1
        ]);

        if ($personal_record) {
            $personal_ts = (int)($personal_record->timemodified ?? 0);
            $global_ts = (int)($global_record->timemodified ?? 0);

            // If global exists and is newer, sync personal to global and return global
            if ($global_record && $global_ts > $personal_ts) {
                $personal_record->practice_question = $global_record->practice_question;
                $personal_record->optionsjson = $global_record->optionsjson;
                $personal_record->correct_answer = $global_record->correct_answer;
                $personal_record->explanation = $global_record->explanation;
                $personal_record->concept_explanation = $global_record->concept_explanation;
                $personal_record->timemodified = $global_ts;
                $DB->update_record('local_qh_saved_help', $personal_record);

                $response = [
                    'success' => true,
                    'practice_question' => (string)$global_record->practice_question,
                    'options' => json_decode((string)$global_record->optionsjson, true),
                    'correct_answer' => (string)$global_record->correct_answer,
                    'explanation' => (string)$global_record->explanation,
                    'concept_explanation' => (string)$global_record->concept_explanation,
                    'is_cached' => true,
                    'is_global' => true,
                    'user_type' => 'returning-synced'
                ];

                header('Content-Type: application/json');
                header('X-AI-Provider: cached-global');
                header('X-User-Type: returning-synced');
                $response['provider'] = 'cached-global';
                echo json_encode($response);
                exit;
            }

            // Otherwise return user's personal copy
            $response = [
                'success' => true,
                'practice_question' => (string)$personal_record->practice_question,
                'options' => json_decode((string)$personal_record->optionsjson, true),
                'correct_answer' => (string)$personal_record->correct_answer,
                'explanation' => (string)$personal_record->explanation,
                'concept_explanation' => (string)$personal_record->concept_explanation,
                'is_cached' => true,
                'is_global' => false,
                'user_type' => 'returning'
            ];

            header('Content-Type: application/json');
            header('X-AI-Provider: cached-personal');
            header('X-User-Type: returning');
            $response['provider'] = 'cached-personal';
            echo json_encode($response);
            exit;
        }
    }

    // This is a new user for this question - make API call and update global
    $provider = get_config('local_questionhelper', 'provider');
    if ($provider === 'anthropic') {
        $response = call_anthropic($questiontext, $options, $mode);
    } else {
        $response = call_openai($questiontext, $options, $mode);
    }

    // Save/update the global record and create personal copy
    if ($questionid > 0 && isset($response['success']) && $response['success']) {
        global $DB, $USER;

        $now = time();

        // Create record object
        $record_data = [
            'questionid' => $questionid,
            'variant' => $mode,
            'practice_question' => $response['practice_question'] ?? '',
            'optionsjson' => json_encode($response['options'] ?? []),
            'correct_answer' => $response['correct_answer'] ?? '',
            'explanation' => $response['explanation'] ?? '',
            'concept_explanation' => $response['concept_explanation'] ?? '',
            'timemodified' => $now
        ];

        // Update or create global record
        $global_record = $DB->get_record('local_qh_saved_help', [
            'questionid' => $questionid,
            'variant' => $mode,
            'is_global' => 1
        ]);

        if ($global_record) {
            // Update existing global record
            foreach ($record_data as $field => $value) {
                $global_record->$field = $value;
            }
            $DB->update_record('local_qh_saved_help', $global_record);
        } else {
            // Create new global record
            $global_record = new stdClass();
            foreach ($record_data as $field => $value) {
                $global_record->$field = $value;
            }
            $global_record->userid = null;
            $global_record->is_global = 1;
            $global_record->timecreated = $now;
            $DB->insert_record('local_qh_saved_help', $global_record);
        }

        // Create personal copy for this user
        $personal_record = new stdClass();
        foreach ($record_data as $field => $value) {
            $personal_record->$field = $value;
        }
        $personal_record->userid = $USER->id;
        $personal_record->is_global = 0;
        $personal_record->timecreated = $now;
        $DB->insert_record('local_qh_saved_help', $personal_record);

        $response['is_global'] = false;
        $response['user_type'] = 'new';
        header('X-User-Type: new');
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
				'content' => 'You only output a single valid JSON object. No explanations, no markdown, no code fences, no extra text. Keys must be exactly: practice_question, options (with keys A,B,C,D), correct_answer (A|B|C|D), explanation, concept. Ensure strict JSON without trailing commas.'
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

	$normalized = local_questionhelper_parse_and_normalize_response($content);
	if ($normalized['success']) {
		return $normalized;
	}

	// If not properly formatted JSON, return error (with debug for challenge)
	if ($mode === 'challenge') {
		error_log("CHALLENGE DEBUG - JSON parsing failed. Content: " . substr($content, 0, 200));
		error_log("CHALLENGE DEBUG - JSON error: " . json_last_error_msg());
	}

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
    // Normalise model; map to official Claude 4 Sonnet model identifier
    if (empty($model)) { $model = 'claude-sonnet-4-20250514'; }
    if (stripos($model, 'sonnet-4') === 0 || stripos($model, 'sonnet4') === 0 || stripos($model, 'claude-4') === 0) {
        $model = 'claude-sonnet-4-20250514';
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
				'content' => [ [ 'type' => 'text', 'text' => "Respond with ONLY a single valid JSON object. No markdown, no code fences, no explanations. Keys: practice_question, options (A,B,C,D), correct_answer (A|B|C|D), explanation, concept.\n\n" . $prompt ] ]
            ]
        ]
    ];

    // Log the actual model being used
    error_log("🤖 Question Helper (Anthropic): Using model: " . $model);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

    // Debug: Log the raw response content for challenge questions
    if ($mode === 'challenge') {
        error_log("CHALLENGE DEBUG - Raw AI Response: " . substr($content, 0, 500));
    }

	$normalized = local_questionhelper_parse_and_normalize_response($content);
	if ($normalized['success']) {
		return $normalized;
	}

	// Debug: Log parsing failure for challenge questions
	if ($mode === 'challenge') {
		error_log("CHALLENGE DEBUG - JSON parsing failed. Content: " . substr($content, 0, 200));
		error_log("CHALLENGE DEBUG - JSON error: " . json_last_error_msg());
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
	$prompt .= "- Format response as JSON with this exact structure ONLY (no extra text):\n";
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

	$prompt .= "\nReturn only the JSON object. Do not include markdown or code fences.\n";
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
	$prompt .= "- Format response as JSON with this exact structure ONLY (no extra text):\n";
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

	$prompt .= "\nReturn only the JSON object. Do not include markdown or code fences.\n";
	return $prompt;
	}

	/**
	 * Extract, parse, and normalize possibly messy AI content into the expected structure.
	 * Ensures keys: practice_question (string), options (A..D => string), correct_answer (A|B|C|D),
	 * explanation (string), concept_explanation (string)
	 *
	 * @param string $content
	 * @return array ['success'=>bool, ...]
	 */
	function local_questionhelper_parse_and_normalize_response($content) {
		$raw = trim((string)$content);
		$clean = local_questionhelper_extract_json_block($raw);
		$parsed = json_decode($clean, true);
		if (!is_array($parsed)) {
			// Try a second pass removing common wrappers like ```json ... ```
			$clean2 = preg_replace("/```[a-zA-Z]*\n?|```/", "", $raw);
			$clean2 = local_questionhelper_extract_json_block($clean2);
			$parsed = json_decode($clean2, true);
		}

		if (!is_array($parsed)) {
			return ['success' => false];
		}

		$normalized = local_questionhelper_normalize_ai_structure($parsed);
		if (!$normalized) {
			return ['success' => false];
		}

		return array_merge(['success' => true], $normalized);
	}

	/**
	 * Attempt to extract the JSON object substring from a text blob.
	 */
	function local_questionhelper_extract_json_block($text) {
		$text = trim($text);
		// If fenced code with JSON is present, prefer inner block
		if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $text, $m)) {
			return trim($m[1]);
		}
		// Fallback: get substring from first '{' to last '}'
		$first = strpos($text, '{');
		$last = strrpos($text, '}');
		if ($first !== false && $last !== false && $last > $first) {
			return trim(substr($text, $first, $last - $first + 1));
		}
		return $text;
	}

	/**
	 * Normalize AI JSON into expected fields and validate.
	 * @param array $data
	 * @return array|null
	 */
	function local_questionhelper_normalize_ai_structure($data) {
		$practice = isset($data['practice_question']) ? (string)$data['practice_question'] : '';
		$options = isset($data['options']) ? $data['options'] : null;
		$answer  = isset($data['correct_answer']) ? (string)$data['correct_answer'] : '';
		$explain = isset($data['explanation']) ? (string)$data['explanation'] : '';
		$concept = isset($data['concept']) ? (string)$data['concept'] : (isset($data['concept_explanation']) ? (string)$data['concept_explanation'] : '');

		if ($practice === '' || empty($options)) {
			return null;
		}

		// Normalize options to associative array with A..D
		$normOptions = local_questionhelper_normalize_options($options);
		if (!$normOptions) {
			return null;
		}

		$normAnswer = local_questionhelper_normalize_answer($answer, $normOptions);
		if (!$normAnswer) {
			return null;
		}

		if ($explain === '') { $explain = 'Answer explanation not provided.'; }
		if ($concept === '') { $concept = 'Concept explanation not provided.'; }

		return [
			'practice_question' => $practice,
			'options' => $normOptions,
			'correct_answer' => $normAnswer,
			'explanation' => $explain,
			'concept_explanation' => $concept
		];
	}

	/**
	 * Coerce options to {A: str, B: str, C: str, D: str}
	 */
	function local_questionhelper_normalize_options($options) {
		// If options is already an associative array with letter keys
		if (is_array($options)) {
			// Convert stdClass to array if needed
			if (is_object($options)) { $options = (array)$options; }
			// If numeric array length 4
			if (array_values($options) === $options && count($options) === 4) {
				$letters = ['A','B','C','D'];
				$out = [];
				foreach ($letters as $idx => $letter) {
					$out[$letter] = trim((string)$options[$idx]);
				}
				return $out;
			}
			// Map various keys to A..D
			$map = [];
			foreach ($options as $key => $val) {
				$k = strtoupper(trim((string)$key));
				if ($k === '1') { $k = 'A'; }
				if ($k === '2') { $k = 'B'; }
				if ($k === '3') { $k = 'C'; }
				if ($k === '4') { $k = 'D'; }
				if (in_array($k, ['A','B','C','D'], true)) {
					$map[$k] = trim((string)$val);
				}
			}
			if (count($map) === 4) { return $map; }
		}
		return null;
	}

	/**
	 * Normalize correct answer to one of A|B|C|D. Accepts index, letter, or full option text.
	 */
	function local_questionhelper_normalize_answer($answer, $options) {
		$ans = strtoupper(trim((string)$answer));
		if (in_array($ans, ['A','B','C','D'], true)) { return $ans; }
		// Numeric index 1..4
		if (preg_match('/^[1-4]$/', $ans)) {
			return ['1'=>'A','2'=>'B','3'=>'C','4'=>'D'][$ans];
		}
		// Sometimes returned as like "Option A" or "Answer: C"
		if (preg_match('/([ABCD])/i', $ans, $m)) {
			$letter = strtoupper($m[1]);
			if (isset($options[$letter])) { return $letter; }
		}
		// Match by option text equality
		$normAnsText = strtolower(preg_replace('/\s+/', ' ', $ans));
		foreach (['A','B','C','D'] as $letter) {
			$optText = strtolower(preg_replace('/\s+/', ' ', (string)$options[$letter]));
			if ($optText === $normAnsText) { return $letter; }
		}
		return null;
}

/**
 * Get the real question ID from slot number and attempt ID
 * This matches the logic used in quiz_manager.php
 */
function get_question_id_from_slot($attemptid, $slot) {
    global $DB;

    try {
        // Get the quiz attempt to find the question usage ID
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'uniqueid');
        if (!$attempt) {
            return 0;
        }

        // Get the question ID for this slot using the same logic as quiz_manager.php
        $sql = "SELECT qn.id
                FROM {question_attempts} qa_inner
                JOIN {question} qn ON qn.id = qa_inner.questionid
                WHERE qa_inner.questionusageid = ? AND qa_inner.slot = ?
                LIMIT 1";

        $questionid = $DB->get_field_sql($sql, [$attempt->uniqueid, $slot]);

        return $questionid ? (int)$questionid : 0;

    } catch (Exception $e) {
        error_log('Error getting question ID from slot: ' . $e->getMessage());
        return 0;
    }
}