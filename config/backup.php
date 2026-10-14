<?php

return [
    // Any filesystem disk (point this at S3 / R2 in production so backups leave the server).
    'disk' => env('BACKUP_DISK', 'local'),
];
