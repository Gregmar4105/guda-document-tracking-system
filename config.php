<?php
// This file is intended for defining constants like BASE_URL for mobile-accessible QR codes.
// It should NOT contain a database connection, as that is handled by db_connect.php.
// The previous database connection code has been removed to prevent errors and redundancy.

// To make QR codes scannable as deep links from mobile devices,
// define a BASE_URL that uses your server's public domain.
if (!defined('BASE_URL')) {
    $env_url = getenv('APP_URL') ?: getenv('BASE_URL');
    if ($env_url) {
        define('BASE_URL', rtrim($env_url, '/'));
    }
}
?>