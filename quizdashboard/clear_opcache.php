<?php
// Clear OPcache for web server
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully!";
} else {
    echo "OPcache not available or not enabled.";
}