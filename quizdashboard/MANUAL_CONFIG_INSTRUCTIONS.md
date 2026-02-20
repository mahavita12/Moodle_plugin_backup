# SOLUTION: Global Navigation Setup

The navigation is working on the dashboard pages, but not appearing globally. Here are the steps to fix this:

## 🎯 **STEP 1: Add to Main Moodle Config**

You need to edit your **main Moodle config.php** file (not the plugin's config.php).

**File Location:** `C:\MoodleWindowsInstaller-latest-404\server\moodle\config.php`

**Add this line BEFORE** `require_once(__DIR__ . '/lib/setup.php');`:

```php
// Quiz Dashboard Global Navigation
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}
```

## 🔧 **Your config.php should look like this:**

```php
<?php  // Moodle configuration file

unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'localhost';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'root';
$CFG->dbpass    = '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport' => '',
  'dbsocket' => '',
  'dbcollation' => 'utf8mb4_unicode_ci',
);

$CFG->wwwroot   = 'http://localhost';
$CFG->dataroot  = 'C:\\MoodleWindowsInstaller-latest-404\\server\\moodledata';
$CFG->admin     = 'admin';

$CFG->directorypermissions = 0777;

// ADD THIS LINE HERE - Quiz Dashboard Global Navigation
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
```

## 🧪 **STEP 2: Test the Fix**

1. **Edit the config.php file** as shown above
2. **Visit any Moodle page** (like your courses page)
3. **Look for the "Dashboards" button** in the top-right corner

## 🔍 **STEP 3: Debug if Needed**

If it still doesn't work, visit:
- `http://localhost/local/quizdashboard/hook_test.php`

This page will:
- Show debug information about your setup
- Manually inject navigation to test if it works
- Help identify what's preventing the global injection

## ⚠️ **Important Notes:**

- The file you edited earlier was the **plugin's** config.php, not Moodle's main config.php
- The global injector needs to be loaded at the system level, not plugin level
- After adding the line, the navigation should appear on ALL pages, not just dashboard pages

Let me know if you need help editing the config.php file!
