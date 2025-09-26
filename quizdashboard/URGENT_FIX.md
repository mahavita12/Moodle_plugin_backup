# URGENT FIX - Layout Issue

## 🚨 **CRITICAL ISSUE DETECTED**

The global config injector is causing major layout problems! 

## ⚡ **IMMEDIATE FIX - Remove from config.php**

**IMMEDIATELY remove this line from your main Moodle config.php:**

```php
// REMOVE THIS LINE COMPLETELY
if (file_exists($CFG->dirroot . '/local/quizdashboard/global_config_injector.php')) {
    require_once($CFG->dirroot . '/local/quizdashboard/global_config_injector.php');
}
```

**Your config.php should go back to just:**
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

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
```

## 🎯 **Alternative Solution**

I'll create a safer method that doesn't interfere with Moodle's core rendering.

**Remove the config.php line FIRST to fix your site, then I'll implement a safer approach.**
