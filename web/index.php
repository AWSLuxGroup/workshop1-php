<?php

/*
Stub file to run & test locally.
Run with php -S localhost:8000 -t web web/index.php 
*/
/*
$filename = __DIR__.preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI']);
if (php_sapi_name() === 'cli-server' && is_file($filename)) {
    return false;
}
app = require __DIR__ . '/../src/app.php';
*/

require __DIR__ . '/../src/app.php';

