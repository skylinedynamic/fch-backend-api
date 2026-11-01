<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agreement images
    |--------------------------------------------------------------------------
    |
    | Images inside agreement templates and signed agreements (logo, signature)
    | are kept on this disk, one file per distinct image, instead of as base64
    | inside the HTML/JSON in the database (see AgreementImageStore). The
    | default "local" disk is private (storage/app/private). Switching to S3
    | later is a matter of setting AGREEMENT_IMAGE_DISK=s3 and copying the
    | directory across.
    |
    */

    'image_disk' => env('AGREEMENT_IMAGE_DISK', 'local'),

    'image_directory' => 'agreement-images',

];
