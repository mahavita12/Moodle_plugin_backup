# CORRECTED CONFIG.PHP SETUP

## 🚨 **The Problem Found:**

Your error logs show: `PHP Warning: Undefined property: stdClass::$dirroot`

This is because we're trying to use `$CFG->dirroot` BEFORE Moodle has set it up.

## ✅ **CORRECTED Solution:**

Edit your main Moodle config.php and move the navigation include to **AFTER** the `require_once(__DIR__ . '/lib/setup.php');` line:

**Your config.php should look like this:**

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

require_once(__DIR__ . '/lib/setup.php');

// MOVE THE NAVIGATION INCLUDE TO HERE - AFTER setup.php
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
```

## 🔧 **What to Change:**

1. **Remove this line from BEFORE setup.php:**
```php
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}
```

2. **Add it AFTER setup.php instead:**
```php
require_once(__DIR__ . '/lib/setup.php');

// Add navigation here - after Moodle has loaded
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}
```

This way, `$CFG->dirroot` will be properly defined by the time we try to use it.

After making this change, the navigation should appear on all pages!
