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
