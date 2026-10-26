<?php 

define('PATH_LOG', __DIR__ . '/sync.log');
define('PATH_REPORT', __DIR__ . '/report_manager.csv');

if (file_exists(PATH_LOG)) {
    unlink(PATH_LOG);
}

function logger(string $message, string $level = 'INFO'): void {
    $logEntry = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
    file_put_contents(PATH_LOG, $logEntry, FILE_APPEND);
    echo($logEntry);
}