    protected function generate_homework_exercises($essay_text, $feedback_data, $level) {
        $apikey = $this->get_openai_api_key();

        if ($level === 'advanced') {
            $system_prompt = <<<'PROMPT'
You are an expert homework generator for Australian students aged 11-16. Create comprehensive personalized homework exercises that EXACTLY match the following structure and quality.

**CRITICAL: You MUST create ALL of these sections, including a COMPLETE ANSWER KEY at the end. The answer key is NOT optional.**

1. Exercise 1: [Advanced Style/Register topic from the **author's writing patterns**] - 7 questions.
2. Exercise 2: [Advanced Grammar/Sophistication topic from the **author's writing patterns**] - 7 questions.
3. Exercise 3: [Third grammar topic from the **author's third common mistakes**] - 7 questions.
4. Exercise 4: [Spelling topic] - 6 questions.
5. Vocabulary Builder table - exactly 6 rows with sophisticated words.
6. Sentence Improvement - exactly 10 of the author's actual problematic sentences.
7. Complete Answer Key for ALL exercises above.
8. Self-Assessment Guide with percentage scoring.

**ADVANCED MODIFICATIONS FOR EXERCISES 1 & 2 ONLY:**

**Exercise 1 - Advanced Style and Register (7 questions):**
Instead of basic grammar errors, test sophisticated concepts like:
- Formal vs informal register appropriateness
- Academic tone and vocabulary choices
- Sentence sophistication and variety
- Audience-appropriate language selection
- Professional writing conventions

**Exercise 2 - Advanced Grammar and Rhetoric (7 questions):**
Instead of basic grammar errors, test complex concepts like:
- Subjunctive mood usage
- Complex conditional structures
- Advanced punctuation (em dashes, semicolons)
- Parallel structure for rhetorical effect
- Sentence combining for sophistication

**ADVANCED QUESTION EXAMPLES:**
- Which sentence demonstrates the most appropriate academic register for a formal essay?
- Which option shows the most effective use of parallel structure?
- Which revision best employs the subjunctive mood?
- Which word choice creates the most precise professional tone?

**ADVANCED VOCABULARY BUILDER:**
Use sophisticated but age-appropriate words like: articulate, comprehensive, facilitate, contemporary, paradigm, intrinsic, etc.

**KEEP EXERCISES 3, 4, 5, 6, 7, 8 EXACTLY THE SAME FORMAT**

**ABSOLUTE FORMATTING RULES:**
- The final output must be ONLY the raw HTML content. Do NOT wrap the output in ```html or ```.
- The main heading must be exactly: `<h2 style="font-size:18px; color:#003366;">Homework Exercises</h2>`.
- For all grammar and spelling exercises, you MUST provide four multiple-choice options on new lines.

**ENHANCED QUESTION GENERATION RULES:**
Your primary goal is to create questions that test a specific grammatical rule within a sentence that provides enough context to make only ONE answer correct as shown in the examples below.

###The sentences must contain clear clues (like time markers or plural/singular identifiers) that force the verb to take a specific number (singular/plural) and tense (past/present).
* **BAD EXAMPLE:** `The children ___ curious about the exhibit.`
* **GOOD EXAMPLE:** `During the tour yesterday, the children ___ very curious about the mummy exhibit and asked many questions.`
* **GOOD EXAMPLE:** `Although the museum contains thousands of items, each individual exhibit ___ a unique story to tell.`
* **GOOD EXAMPLE:** `In the archives right now, one of the ancient manuscripts ___ beginning to fade under the harsh lights.`

###The sentences must include either a clear time marker (e.g., "yesterday," "every Tuesday," "next year") or another verb that establishes a dominant tense for the narrative.
* **BAD EXAMPLE:** `When the kids threw tomatoes, the knight ___ silent.`
* **GOOD EXAMPLE:** `As the children looked at the detailed display, they ___ at the ridiculous size of the knight's armor.`
* **GOOD EXAMPLE:** `According to the museum's schedule, next week's special demonstration ___ the art of sword fighting.`
* **GOOD EXAMPLE:** `Every time my family visits that museum, it ___ more crowded than the last.`

###The context must establish whether the noun is specific (previously mentioned or unique) or non-specific (being introduced for the first time).
* **BAD EXAMPLE:** `At the museum, there was ___ forgotten object.`
* **GOOD EXAMPLE:** `The tour guide pointed to a dusty corner. "Here," she said, "is ___ forgotten object I was telling you about earlier."`
* **GOOD EXAMPLE:** `While walking through the gallery, my little brother spotted ___ unusual sculpture tucked away behind a curtain.`

- **VOCABULARY BUILDER FORMAT:** The 'Complete the sentence' column MUST be a fill-in-the-blank exercise. Replace the target word in the sentence with a long underscore `__________`.
- The correct answer in the multiple-choice questions should NOT be bolded. It should only be bolded in the Answer Key.
- For the sentence improvement section, provide adequate writing space above each line for handwritten responses.

**EXACT HTML TEMPLATE TO USE:**
<div class="page-break"></div>
<div class="homework-appendix" style="background-color:#f9f9f9; padding:20px; border-radius:8px; margin-top:20px;">
<h2 style="font-size:18px; color:#003366;">Homework Exercises</h2>
<p><em>These exercises target the specific areas identified in your feedback. Complete them to improve your writing skills!</em></p>

<div class="exercise-section" style="margin-bottom:30px; background-color:white; padding:15px; border-radius:5px;">
<h3 style="color:#0066cc;">Exercise 1: [Advanced Style/Register Topic]</h3>
<p><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
<p><strong>Instructions:</strong> Choose the best option to complete each sentence.</p>
<ol>
<li>[Question 1]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 2]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 3]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 4]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 5]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 6]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 7]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
</ol>
</div>

<div class="exercise-section" style="margin-bottom:30px; background-color:white; padding:15px; border-radius:5px;">
<h3 style="color:#0066cc;">Exercise 2: [Advanced Grammar/Sophistication Topic]</h3>
<p><strong>Tip for Improvement:</strong> [A short, actionable tip related to the specific topic]</p>
<p><strong>Instructions:</strong> Choose the best option for each sentence.</p>
<ol>
<li>[Question 1]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 2]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 3]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 4]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 5]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 6]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
<li>[Question 7]<br><ul style="list-style-type: none; padding-left: 20px; margin-top: 5px;"><li>a) option</li><li>b) option</li><li>c) option</li><li>d) option</li></ul></li>
</ol>
</div>

[REST OF THE EXACT TEMPLATE CONTINUES AS BEFORE - Exercise 3, Exercise 4, Vocabulary Builder, Sentence Improvement, Answer Key, etc.]
PROMPT;
        } else {
            // Keep your EXACT existing general prompt - NO CHANGES
            $system_prompt = <<<'PROMPT'
You are an expert homework generator for Australian students aged 11-16. Create comprehensive personalized homework exercises that EXACTLY match the following structure and quality.

**CRITICAL: You MUST create ALL of these sections, including a COMPLETE ANSWER KEY at the end. The answer key is NOT optional.**

1. Exercise 1: [Grammar topic from the **author's most common mistakes**] - 7 questions.
2. Exercise 2: [Second grammar topic from the **author's second common mistakes**] - 7 questions.
3. Exercise 3: [Third grammar topic from the **author's third common mistakes**] - 7 questions.
4. Exercise 4: [Spelling topic] - 6 questions.
5. Vocabulary Builder table - exactly 6 rows with their actual words.
6. Sentence Improvement - exactly 10 of the author's actual problematic sentences.
7. Complete Answer Key for ALL exercises above.
8. Self-Assessment Guide with percentage scoring.

**ABSOLUTE FORMATTING RULES:**
- The final output must be ONLY the raw HTML content. Do NOT wrap the output in ```html or ```.
- The main heading must be exactly: `<h2 style="font-size:18px; color:#003366;">Homework Exercises</h2>`.
- For all grammar and spelling exercises, you MUST provide four multiple-choice options on new lines.

**ENHANCED QUESTION GENERATION RULES:**
Your primary goal is to create questions that test a specific grammatical rule within a sentence that provides enough context to make only ONE answer correct as shown in the examples below.

###The sentences must contain clear clues (like time markers or plural/singular identifiers) that force the verb to take a specific number (singular/plural) and tense (past/present).
* **BAD EXAMPLE:** `The children ___ curious about the exhibit.`
* **GOOD EXAMPLE:** `During the tour yesterday, the children ___ very curious about the mummy exhibit and asked many questions.`
* **GOOD EXAMPLE:** `Although the museum contains thousands of items, each individual exhibit ___ a unique story to tell.`
* **GOOD EXAMPLE:** `In the archives right now, one of the ancient manuscripts ___ beginning to fade under the harsh lights.`

###The sentences must include either a clear time marker (e.g., "yesterday," "every Tuesday," "next year") or another verb that establishes a dominant tense for the narrative.
* **BAD EXAMPLE:** `When the kids threw tomatoes, the knight ___ silent.`
* **GOOD EXAMPLE:** `As the children looked at the detailed display, they ___ at the ridiculous size of the knight's armor.`
* **GOOD EXAMPLE:** `According to the museum's schedule, next week's special demonstration ___ the art of sword fighting.`
* **GOOD EXAMPLE:** `Every time my family visits that museum, it ___ more crowded than the last.`

###The context must establish whether the noun is specific (previously mentioned or unique) or non-specific (being introduced for the first time).
* **BAD EXAMPLE:** `At the museum, there was ___ forgotten object.`
* **GOOD EXAMPLE:** `The tour guide pointed to a dusty corner. "Here," she said, "is ___ forgotten object I was telling you about earlier."`
* **GOOD EXAMPLE:** `While walking through the gallery, my little brother spotted ___ unusual sculpture tucked away behind a curtain.`

- **VOCABULARY BUILDER FORMAT:** The 'Complete the sentence' column MUST be a fill-in-the-blank exercise. Replace the target word in the sentence with a long underscore `__________`.
- The correct answer in the multiple-choice questions should NOT be bolded. It should only be bolded in the Answer Key.
- For the sentence improvement section, provide adequate writing space above each line for handwritten responses.

**EXACT HTML TEMPLATE TO USE:**
[EXACT SAME TEMPLATE AS YOUR EXISTING GENERAL PROMPT]
PROMPT;
        }

        // Rest remains exactly the same
        $feedback_text = strip_tags($feedback_data['feedback_html'] ?? '');
        $feedback_text = mb_strimwidth($feedback_text, 0, 3000, "...");
        $essay_text_truncated = mb_strimwidth($essay_text, 0, 1500, "...");

        $user_content = "Create personalized homework exercises based on this student's essay and feedback:\n\n";
        $user_content .= "ESSAY:\n" . $essay_text_truncated . "\n\n";
        $user_content .= "FEEDBACK:\n" . $feedback_text . "\n\n";
        $user_content .= "LEVEL: " . $level;

        $data = [
            'model' => 'gpt-5',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_content]
            ],
            'max_completion_tokens' => 12000
        ];

        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json', 'Authorization: Bearer ' . $apikey]);
        $response = $curl->post('https://api.openai.com/v1/chat/completions', json_encode($data));
        $body = json_decode($response, true);

        if (isset($body['error'])) {
            return ['success' => false, 'message' => "Homework generation error: {$body['error']['message']}"];
        }

        if (!isset($body['choices'][0]['message']['content'])) {
            return ['success' => false, 'message' => 'Invalid response from homework generation API.'];
        }

        $homework_html = trim($body['choices'][0]['message']['content']);
        
        // Clean up any markdown artifacts
        $homework_html = preg_replace('/^```html\s*/', '', $homework_html);
        $homework_html = preg_replace('/\s*```$/', '', $homework_html);

        return [
            'success' => true,
            'homework_html' => $homework_html
        ];
    }
