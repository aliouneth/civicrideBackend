<?php

return [

    // Where queued report exports are written. Local dev uses the 'local'
    // disk; production points this at 's3' (requires the
    // league/flysystem-aws-s3-v3 composer package and S3_* credentials).
    'export_disk' => env('REPORT_EXPORT_DISK', 'local'),

];
