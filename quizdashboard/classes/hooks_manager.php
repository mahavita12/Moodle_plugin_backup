<?php
/**
 * Global hooks manager for Quiz Dashboard
 */
namespace local_quizdashboard;

class hooks_manager {
    /**
     * Inject dashboard navigation globally - DISABLED
     */
    public static function inject_global_navigation() {
        return; // Disabled: Navigation moved to User Menu
    }

    public static function get_navigation_html() {
        return ''; // Disabled
    }
}
