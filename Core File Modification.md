# Core File Modifications for Homework Badges

**Date:** 2025-11-21

This document records intentional changes made to **Moodle core files** in the local instance to support Homework Dashboard status badges (Completed / Low grade / No attempt) in the **Timeline** block and **Calendar** views.

All homework logic (window, 180-second rule, 30% threshold, snapshot vs live) remains inside the `local_homeworkdashboard` plugin. Core files are only used to expose plugin data to existing UIs and render badges.

---

## 1. `calendar/classes/external/event_exporter_base.php`

**Path:**

- `calendar/classes/external/event_exporter_base.php`

**Purpose of change:**

- Allow the calendar event exporter to attach per-user **homework status** data (from `local_homeworkdashboard`) to each event, and provide pre-computed symbol + CSS class for the UI.

### 1.1 New exported properties

In `protected static function define_other_properties()`, three new optional properties were added:

- `homeworkstatus` – string status from the plugin:
  - `Completed`
  - `Low grade`
  - `No attempt`
- `homeworkstatussymbol` – badge **label** for the UI (symbol + text):
  - `✓ Done` for Completed
  - `? Retry` for Low grade
  - `✖ To do` for No attempt
- `homeworkstatusclass` – CSS class string for styling the badge:
  - `badge rounded-pill bg-success text-white` (Completed)
  - `badge rounded-pill bg-warning text-white` (Low grade)
  - `badge rounded-pill bg-danger text-white` (No attempt)

These were added to the array returned by `define_other_properties()`; no existing entries were removed.

### 1.2 New logic in `get_other_values()`

In `protected function get_other_values(renderer_base $output)`, after exporting the standard `action` field, new logic was added to:

1. Call into the `local_homeworkdashboard` plugin callback `local_homeworkdashboard_calendar_get_event_homework_status(event_interface $event)` using Moodle’s `component_callback`.
2. If a non-empty status string is returned, set the new properties as follows:
   - `homeworkstatus` to the returned string.
   - `homeworkstatussymbol` to the combined symbol + text label (`✓ Done`, `? Retry`, `✖ To do`).
   - `homeworkstatusclass` based on the status value (mapping listed above).

No existing behaviour (permissions, URLs, icons, etc.) was altered; only additional keys are added to the exported event data structure.

---

## 2. `calendar/templates/event_item.mustache`

**Path:**

- `calendar/templates/event_item.mustache`

**Purpose of change:**

- Display a homework badge (symbol + text label) next to the calendar event name when `homeworkstatussymbol` is present.

### 2.1 Header title change

Original line:

- `<h3 class="name d-inline-block">{{{name}}}</h3>`

Updated line (inline form):

- `<h3 class="name d-inline-block">{{{name}}}{{#homeworkstatussymbol}} <span class="{{homeworkstatusclass}} ml-2">{{homeworkstatussymbol}}</span>{{/homeworkstatussymbol}}</h3>`

Effect:

- If `homeworkstatussymbol` is defined in the event context, a badge `<span>` is rendered after the name using `homeworkstatusclass` for styling. The label content is `✓ Done`, `? Retry`, or `✖ To do` depending on the user’s homework status.
- If not defined, the template behaves exactly as before (no badge output).

No other parts of the template were modified.

---

## 3. `blocks/timeline/templates/event-list-item.mustache`

**Path:**

- `blocks/timeline/templates/event-list-item.mustache`

**Purpose of change:**

- Display the same homework badge (symbol + text label) next to the activity name in the **Timeline** block list items.

### 3.1 Activity name change

Original activity anchor:

- `{{{activityname}}}</a>`

Updated anchor + badge (inline form):

- `{{{activityname}}}</a>{{#homeworkstatussymbol}} <span class="{{homeworkstatusclass}} ml-2">{{homeworkstatussymbol}}</span>{{/homeworkstatussymbol}}`

Effect:

- For events where the exporter populated `homeworkstatussymbol` and `homeworkstatusclass`, the Timeline item shows the same badge next to the activity name, using the combined label `✓ Done`, `? Retry`, or `✖ To do`.
- ARIA labels and other accessibility-related attributes remain unchanged.

No other parts of the Timeline template were modified.

---

## 4. Behaviour summary

- Core calendar exporter now **optionally** decorates events with homework status information supplied by `local_homeworkdashboard`.
- Calendar and Timeline templates render a coloured badge showing **symbol + text**:
  - `✓ Done` (green) for Completed
  - `? Retry` (amber) for Low grade
  - `✖ To do` (red) for No attempt
  next to quiz close events where the plugin reports a status for the current user.
- All underlying homework logic (snapshot vs live, 180-second rule, 30% score threshold) remains in the plugin code; core is only responsible for passing through and displaying the data.

---

## 5. Visual refinement – white badge text

**Date:** 2025-11-21 (later update on the same day)

**File touched:**

- `calendar/classes/external/event_exporter_base.php`

**Change:**

- Updated the `homeworkstatusclass` values so that **all three badges use white text** for better contrast and consistency:
  - Completed / `✓ Done`: `badge rounded-pill bg-success text-white`
  - Low grade / `? Retry`: `badge rounded-pill bg-warning text-white` (was `text-dark`)
  - No attempt / `✖ To do`: `badge rounded-pill bg-danger text-white` (was `text-dark`)

This affects both Calendar and Timeline, since they use the same `homeworkstatusclass` value supplied by the exporter.

---

## 6. `mod/quiz/lib.php` – keep quiz close events visible in Timeline

**Path:**

- `mod/quiz/lib.php`

**Purpose of change:**

- Prevent quiz **close** events from disappearing from the **Timeline** after a student has attempted/submitted the quiz, so that Homework Dashboard badges can continue to be shown against the original close event.

### 6.1 Change in `mod_quiz_core_calendar_provide_event_action()`

- Function: `mod_quiz_core_calendar_provide_event_action(calendar_event $event, \\core_calendar\\action_factory $factory)`.
- Previously, once the quiz was no longer actionable for the user (e.g. all attempts used, quiz closed), the callback returned **`null`**, which caused the Timeline block to **drop** the close event.
- The logic has been adjusted so that for quiz **close** events we now always return a calendar **action object** for the Timeline, but with:
  - `actionable = false` when the quiz cannot be attempted any more.

**Effect:**

- The quiz close event **remains listed** in the Timeline even after attempts/submission/closure.
- Homework badges supplied by `local_homeworkdashboard` (Done / Retry / To do) continue to be visible against that close event.
- Users cannot click through to start a new attempt when the quiz is closed; the event is displayed as **non-actionable** rather than being hidden.

---

# Core File Modifications for Question Flags (Performance)

**Date:** 2025-12-23

This document records intentional changes made to **Moodle core files** to support server-side rendering of Question Flags (Blue/Red flags) in the Quiz module. This removes the need for client-side DOM injection, significantly improving performance.

All flag logic (persistence, auto-flagging rules, API) remains inside the `local_questionflags` plugin. Core files are only used to consult this API and render CSS classes.

---

## 7. `mod/quiz/classes/output/navigation_question_button.php`

**Path:**
- `mod/quiz/classes/output/navigation_question_button.php`

**Purpose of change:**
- Add a property to store the `questionid` so correct flags can be looked up during rendering.

**Change:**
Added `public $questionid;` to the class definition.

---

## 8. `mod/quiz/classes/output/navigation_panel_base.php`

**Path:**
- `mod/quiz/classes/output/navigation_panel_base.php`

**Purpose of change:**
- Preload all flags for the quiz in a single DB query via `\local_questionflags\api`.
- Populate the `$button->questionid` property during button creation.

**Change:**
1.  In `get_question_buttons()`, called `\local_questionflags\api::preload_flags(...)`.
2.  Inside the button loop, set `$button->questionid = $qa->get_question(false)->id;`.

---

## 9. `mod/quiz/classes/output/renderer.php`

**Path:**
- `mod/quiz/classes/output/renderer.php`

**Purpose of change:**
- Apply the CSS class (e.g., `blue-flagged`) to the navigation button if a flag exists.

**Change:**
In `render_navigation_question_button()`, added a check:
```php
if (class_exists('\local_questionflags\api') && !empty($button->questionid)) {
    if ($cls = \local_questionflags\api::get_flag_class($button->questionid)) {
        $classes[] = $cls;
    }
}
```

---

 
 # #   1 0 .   ` q u e s t i o n / e n g i n e / r e n d e r e r . p h p `  
  
 * * P a t h : * *  
 -   ` q u e s t i o n / e n g i n e / r e n d e r e r . p h p `  
  
 * * P u r p o s e   o f   c h a n g e : * *  
 -   A p p l y   t h e   C S S   c l a s s   ( e . g . ,   ` b l u e - f l a g g e d - r e v i e w ` )   t o   t h e   o u t e r   q u e s t i o n   c o n t a i n e r   d i v   ( ` . q u e ` ) .  
  
 * * C h a n g e : * *  
 I n   ` q u e s t i o n ( ) ` ,   m o d i f i e d   t h e   c l a s s   a r r a y   c o n s t r u c t i o n   t o   i n c l u d e   t h e   f l a g   c l a s s   i f   p r e s e n t .  
  
 ` ` ` p h p  
 / /   O r i g i n a l   l i n e :  
 / /   $ q a - > g e t _ q u e s t i o n ( f a l s e ) - > g e t _ t y p e _ n a m e ( ) ,  
  
 / /   U p d a t e d   l i n e :  
 ( c l a s s _ e x i s t s ( ' \ \ l o c a l _ q u e s t i o n f l a g s \ \ a p i ' )   & &   ( $ c l s   =   \ l o c a l _ q u e s t i o n f l a g s \ a p i : : g e t _ f l a g _ c l a s s ( $ q a - > g e t _ q u e s t i o n ( f a l s e ) - > i d ) ) )   ?   $ c l s   .   ' - r e v i e w '   :   ' ' ,  
 $ q a - > g e t _ q u e s t i o n ( f a l s e ) - > g e t _ t y p e _ n a m e ( ) ,  
 ` ` `  
 