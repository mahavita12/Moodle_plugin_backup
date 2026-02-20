/**
     * Check if Essays Master sessions exist for all essay questions
     * ✅ ENHANCED VERSION WITH BETTER COMPLETION LOGIC
     *
     * @return bool True if all questions have completed sessions
     */
    public function are_all_sessions_complete() {
        global $DB;

        // ✅ CRITICAL: Check if we have any completed session for this attempt
        $completed_session = $DB->get_record('local_essaysmaster_sessions', [
            'attempt_id' => $this->attemptid,
            'user_id' => $this->attempt->userid,
            'final_submission_allowed' => 1
        ]);

        if ($completed_session) {
            error_log("Essays Master: Found completed session for attempt {$this->attemptid}");
            return true;
        }

        // ✅ ENHANCED: Also check by feedback rounds completed
        $rounds_completed_session = $DB->get_record_sql(
            "SELECT * FROM {local_essaysmaster_sessions} 
             WHERE attempt_id = ? AND user_id = ? 
             AND (feedback_rounds_completed >= 3 OR status = 'completed')
             ORDER BY timemodified DESC LIMIT 1",
            [$this->attemptid, $this->attempt->userid]
        );

        if ($rounds_completed_session) {
            error_log("Essays Master: Found session with 3+ rounds completed for attempt {$this->attemptid}");
            
            // ✅ UPDATE THE SESSION TO ENSURE CONSISTENCY
            if ($rounds_completed_session->final_submission_allowed != 1) {
                try {
                    $rounds_completed_session->final_submission_allowed = 1;
                    $rounds_completed_session->status = 'completed';
                    $rounds_completed_session->timemodified = time();
                    $DB->update_record('local_essaysmaster_sessions', $rounds_completed_session);
                } catch (Exception $e) {
                    error_log("Essays Master: Could not update session completion: " . $e->getMessage());
                }
            }
            return true;
        }

        // ✅ FALLBACK: Check individual questions (original logic)
        foreach ($this->essayquestions as $question) {
            $session = $DB->get_record('local_essaysmaster_sessions', [
                'attempt_id' => $this->attemptid,
                'question_attempt_id' => $question->id,
                'user_id' => $this->attempt->userid
            ]);

            if (!$session || 
                (!$session->final_submission_allowed && 
                 $session->status !== 'completed' && 
                 ($session->feedback_rounds_completed ?? 0) < 3)) {
                error_log("Essays Master: Question {$question->id} not complete for attempt {$this->attemptid}");
                return false;
            }
        }

        return true;
    }

    /**
     * Get incomplete essay questions that need Essays Master processing
     * ✅ ENHANCED VERSION WITH BETTER STATE CHECKING
     *
     * @return array Array of question attempts that need processing
     */
    public function get_incomplete_questions() {
        global $DB;

        // ✅ QUICK CHECK: If we have a completed session, return no incomplete questions
        $completed_session = $DB->get_record('local_essaysmaster_sessions', [
            'attempt_id' => $this->attemptid,
            'user_id' => $this->attempt->userid,
            'final_submission_allowed' => 1
        ]);

        if ($completed_session) {
            error_log("Essays Master: All questions complete - found completed session for attempt {$this->attemptid}");
            return [];
        }

        // ✅ CHECK FOR ROUNDS-BASED COMPLETION
        $rounds_completed = $DB->get_record_sql(
            "SELECT * FROM {local_essaysmaster_sessions} 
             WHERE attempt_id = ? AND user_id = ? 
             AND feedback_rounds_completed >= 3
             ORDER BY timemodified DESC LIMIT 1",
            [$this->attemptid, $this->attempt->userid]
        );

        if ($rounds_completed) {
            error_log("Essays Master: All questions complete - 3 rounds done for attempt {$this->attemptid}");
            return [];
        }

        $incomplete = [];

        foreach ($this->essayquestions as $question) {
            $session = $DB->get_record('local_essaysmaster_sessions', [
                'attempt_id' => $this->attemptid,
                'question_attempt_id' => $question->id,
                'user_id' => $this->attempt->userid
            ]);

            if (!$session || 
                (!$session->final_submission_allowed && 
                 $session->status !== 'completed' && 
                 ($session->feedback_rounds_completed ?? 0) < 3)) {
                $incomplete[] = $question;
            }
        }

        error_log("Essays Master: Found " . count($incomplete) . " incomplete questions for attempt {$this->attemptid}");
        return $incomplete;
    }