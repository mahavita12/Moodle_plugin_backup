<?php
/**
 * Essays Master Configuration Page
 * Direct configuration interface since admin settings are problematic
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_essaysmaster_config');

$PAGE->set_url('/local/essaysmaster/config.php');
$PAGE->set_title('Essays Master Configuration');
$PAGE->set_heading('Essays Master Configuration');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // Save settings
    set_config('enabled', $_POST['enabled'] ?? 0, 'local_essaysmaster');
    set_config('min_essay_length', $_POST['min_essay_length'] ?? 50, 'local_essaysmaster');
    set_config('short_sentence_threshold', $_POST['short_sentence_threshold'] ?? 5, 'local_essaysmaster');
    set_config('repetitive_word_threshold', $_POST['repetitive_word_threshold'] ?? 3, 'local_essaysmaster');

    // Provider + API settings
    $provider = isset($_POST['provider']) ? trim((string)$_POST['provider']) : 'anthropic';
    if (!in_array($provider, ['anthropic', 'openai'])) { $provider = 'anthropic'; }
    set_config('provider', $provider, 'local_essaysmaster');

    // OpenAI settings
    if (isset($_POST['openai_apikey']) && $_POST['openai_apikey'] !== '') {
        set_config('openai_apikey', trim((string)$_POST['openai_apikey']), 'local_essaysmaster');
    }
    if (isset($_POST['openai_model'])) {
        set_config('openai_model', trim((string)$_POST['openai_model']), 'local_essaysmaster');
    }

    // Anthropic settings
    if (isset($_POST['anthropic_apikey']) && $_POST['anthropic_apikey'] !== '') {
        set_config('anthropic_apikey', trim((string)$_POST['anthropic_apikey']), 'local_essaysmaster');
    }
    if (isset($_POST['anthropic_model'])) {
        set_config('anthropic_model', trim((string)$_POST['anthropic_model']), 'local_essaysmaster');
    }

    // Save messages
    set_config('msg_short_sentence', $_POST['msg_short_sentence'] ?? '', 'local_essaysmaster');
    set_config('msg_repetitive_word', $_POST['msg_repetitive_word'] ?? '', 'local_essaysmaster');
    set_config('msg_essay_too_short', $_POST['msg_essay_too_short'] ?? '', 'local_essaysmaster');
    set_config('msg_missing_transitions', $_POST['msg_missing_transitions'] ?? '', 'local_essaysmaster');
    set_config('msg_rhetorical_question', $_POST['msg_rhetorical_question'] ?? '', 'local_essaysmaster');
    set_config('msg_weak_opening', $_POST['msg_weak_opening'] ?? '', 'local_essaysmaster');
    set_config('msg_passive_voice', $_POST['msg_passive_voice'] ?? '', 'local_essaysmaster');
    set_config('msg_weak_conclusion', $_POST['msg_weak_conclusion'] ?? '', 'local_essaysmaster');

    redirect($PAGE->url, 'Settings saved successfully!', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Get current settings
$enabled = get_config('local_essaysmaster', 'enabled') ?? 1;
$min_essay_length = get_config('local_essaysmaster', 'min_essay_length') ?? 50;
$short_sentence_threshold = get_config('local_essaysmaster', 'short_sentence_threshold') ?? 5;
$repetitive_word_threshold = get_config('local_essaysmaster', 'repetitive_word_threshold') ?? 3;

$provider = get_config('local_essaysmaster', 'provider') ?? 'anthropic';
$openai_apikey = get_config('local_essaysmaster', 'openai_apikey') ?? '';
$openai_model = get_config('local_essaysmaster', 'openai_model') ?? 'gpt-4o';
$anthropic_apikey = get_config('local_essaysmaster', 'anthropic_apikey') ?? '';
$anthropic_model = get_config('local_essaysmaster', 'anthropic_model') ?? 'sonnet-4';

$msg_short_sentence = get_config('local_essaysmaster', 'msg_short_sentence') ?? 'This sentence is very short. Consider expanding it with more detail.';
$msg_repetitive_word = get_config('local_essaysmaster', 'msg_repetitive_word') ?? 'The word \'{word}\' appears {count} times. Consider using synonyms for variety.';
$msg_essay_too_short = get_config('local_essaysmaster', 'msg_essay_too_short') ?? 'Your essay is only {word_count} words. Consider expanding your ideas with more examples and explanation.';
$msg_missing_transitions = get_config('local_essaysmaster', 'msg_missing_transitions') ?? 'Consider adding transition words like "however", "furthermore", or "for example" to connect your ideas.';
$msg_rhetorical_question = get_config('local_essaysmaster', 'msg_rhetorical_question') ?? 'This question should be followed by your answer or explanation.';
$msg_weak_opening = get_config('local_essaysmaster', 'msg_weak_opening') ?? 'Consider starting with a stronger, more direct statement or an interesting fact.';
$msg_passive_voice = get_config('local_essaysmaster', 'msg_passive_voice') ?? 'Consider rewriting this sentence in active voice for stronger impact.';
$msg_weak_conclusion = get_config('local_essaysmaster', 'msg_weak_conclusion') ?? 'Your conclusion could be stronger. Consider summarizing your main points or stating your final position.';

echo $OUTPUT->header();
echo $OUTPUT->heading('Essays Master Configuration');

?>

<style>
.config-form { max-width: 800px; }
.config-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
.config-section h3 { margin-top: 0; color: #0066cc; }
.form-group { margin: 10px 0; }
.form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
.form-group input, .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; }
.form-group textarea { height: 80px; resize: vertical; }
.form-group small { color: #666; font-style: italic; }
.btn-primary { background: #0066cc; color: white; padding: 10px 20px; border: none; border-radius: 3px; cursor: pointer; }
.btn-primary:hover { background: #0052a3; }
</style>

<div class="config-form">
    <form method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <div class="config-section">
            <h3>General Settings</h3>

            <div class="form-group">
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    Enable Essays Master Plugin
                </label>
            </div>

            <div class="form-group">
                <label for="min_essay_length">Minimum Essay Length (words)</label>
                <input type="number" name="min_essay_length" id="min_essay_length" value="<?php echo $min_essay_length; ?>" min="1">
                <small>Essays shorter than this will trigger the "too short" message</small>
            </div>

            <div class="form-group">
                <label for="short_sentence_threshold">Short Sentence Threshold (words)</label>
                <input type="number" name="short_sentence_threshold" id="short_sentence_threshold" value="<?php echo $short_sentence_threshold; ?>" min="1">
                <small>Sentences shorter than this will be flagged</small>
            </div>

            <div class="form-group">
                <label for="repetitive_word_threshold">Repetitive Word Threshold</label>
                <input type="number" name="repetitive_word_threshold" id="repetitive_word_threshold" value="<?php echo $repetitive_word_threshold; ?>" min="1">
                <small>Words appearing more than this many times will be flagged</small>
            </div>
        </div>

        <div class="config-section">
            <h3>AI Provider Settings</h3>

            <div class="form-group">
                <label for="provider">Provider</label>
                <select name="provider" id="provider">
                    <option value="anthropic" <?php echo ($provider === 'anthropic') ? 'selected' : ''; ?>>Anthropic</option>
                    <option value="openai" <?php echo ($provider === 'openai') ? 'selected' : ''; ?>>OpenAI</option>
                </select>
                <small>Select Anthropic (Sonnet 4) by default while keeping OpenAI available.</small>
            </div>

            <div class="form-group">
                <label for="anthropic_apikey">Anthropic API Key</label>
                <input type="password" name="anthropic_apikey" id="anthropic_apikey" value="" placeholder="Leave blank to keep existing">
                <small>Stored securely in config. Current status: <?php echo $anthropic_apikey ? 'Configured' : 'Not configured'; ?></small>
            </div>

            <div class="form-group">
                <label for="anthropic_model">Anthropic Model</label>
                <input type="text" name="anthropic_model" id="anthropic_model" value="<?php echo htmlspecialchars($anthropic_model); ?>">
                <small>Default: sonnet-4</small>
            </div>

            <hr>

            <div class="form-group">
                <label for="openai_apikey">OpenAI API Key</label>
                <input type="password" name="openai_apikey" id="openai_apikey" value="" placeholder="Leave blank to keep existing">
                <small>Current status: <?php echo $openai_apikey ? 'Configured' : 'Not configured'; ?></small>
            </div>

            <div class="form-group">
                <label for="openai_model">OpenAI Model</label>
                <input type="text" name="openai_model" id="openai_model" value="<?php echo htmlspecialchars($openai_model); ?>">
                <small>Default: gpt-4o</small>
            </div>
        </div>

        <div class="config-section">
            <h3>Round 1 Feedback Messages</h3>

            <div class="form-group">
                <label for="msg_short_sentence">Short Sentence Message</label>
                <textarea name="msg_short_sentence" id="msg_short_sentence"><?php echo htmlspecialchars($msg_short_sentence); ?></textarea>
                <small>Message shown for sentences that are too short</small>
            </div>

            <div class="form-group">
                <label for="msg_repetitive_word">Repetitive Word Message</label>
                <textarea name="msg_repetitive_word" id="msg_repetitive_word"><?php echo htmlspecialchars($msg_repetitive_word); ?></textarea>
                <small>Use {word} and {count} placeholders for the repeated word and count</small>
            </div>

            <div class="form-group">
                <label for="msg_essay_too_short">Essay Too Short Message</label>
                <textarea name="msg_essay_too_short" id="msg_essay_too_short"><?php echo htmlspecialchars($msg_essay_too_short); ?></textarea>
                <small>Use {word_count} placeholder for the current word count</small>
            </div>
        </div>

        <div class="config-section">
            <h3>Round 2 Feedback Messages</h3>

            <div class="form-group">
                <label for="msg_missing_transitions">Missing Transitions Message</label>
                <textarea name="msg_missing_transitions" id="msg_missing_transitions"><?php echo htmlspecialchars($msg_missing_transitions); ?></textarea>
                <small>Message shown when essay lacks transition words</small>
            </div>

            <div class="form-group">
                <label for="msg_rhetorical_question">Rhetorical Question Message</label>
                <textarea name="msg_rhetorical_question" id="msg_rhetorical_question"><?php echo htmlspecialchars($msg_rhetorical_question); ?></textarea>
                <small>Message shown for unanswered questions</small>
            </div>

            <div class="form-group">
                <label for="msg_weak_opening">Weak Opening Message</label>
                <textarea name="msg_weak_opening" id="msg_weak_opening"><?php echo htmlspecialchars($msg_weak_opening); ?></textarea>
                <small>Message shown for weak essay openings</small>
            </div>
        </div>

        <div class="config-section">
            <h3>Round 3 Feedback Messages</h3>

            <div class="form-group">
                <label for="msg_passive_voice">Passive Voice Message</label>
                <textarea name="msg_passive_voice" id="msg_passive_voice"><?php echo htmlspecialchars($msg_passive_voice); ?></textarea>
                <small>Message shown for passive voice sentences</small>
            </div>

            <div class="form-group">
                <label for="msg_weak_conclusion">Weak Conclusion Message</label>
                <textarea name="msg_weak_conclusion" id="msg_weak_conclusion"><?php echo htmlspecialchars($msg_weak_conclusion); ?></textarea>
                <small>Message shown for weak conclusions</small>
            </div>
        </div>

        <div style="margin: 20px 0;">
            <button type="submit" class="btn-primary">Save Configuration</button>
        </div>
    </form>
</div>

<?php
echo $OUTPUT->footer();
?>