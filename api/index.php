<?php

if (($_SERVER['VERCEL'] ?? getenv('VERCEL')) === '1') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__).'/public/index.php';
}

require __DIR__.'/../public/index.php';
