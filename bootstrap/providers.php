<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
    Opcodes\LogViewer\LogViewerServiceProvider::class,
    Spatie\Backup\BackupServiceProvider::class,
];
