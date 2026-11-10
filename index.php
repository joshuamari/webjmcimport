<?php

/**
 * XAMPP serves this folder at /webJmcImport/. The Laravel app lives in backend/public.
 * Keep SCRIPT_NAME on this front controller so /webJmcImport/api/* routes as /api/*.
 */
$_SERVER['SCRIPT_NAME'] = '/webJmcImport/index.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require __DIR__.'/backend/public/index.php';
