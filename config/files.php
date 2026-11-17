<?php

use App\Support\Files\Disk;
use App\Support\Files\Folder;

/*
 * Where files are kept (BUG-016). Every upload used to sit on the public disk,
 * served by /storage/... with no login. A folder holding client, partner or
 * application data moves to a private disk, one phase at a time, and is handed
 * out only through the API: its owner's access check first, then a short-lived
 * signed URL for that one file.
 *
 * Folder names live here (they are the same everywhere); only the disk changes
 * per environment. A folder not listed in private_folders is still public.
 */
return [
    // The default disk for private files (storage/app/private, never linked
    // under public/). A folder's own *_DISK overrides it, e.g. for S3 later.
    'private_disk' => env('FILES_PRIVATE_DISK', Disk::LOCAL),

    // How long a signed URL for a private file works.
    'signed_url_minutes' => (int) env('FILES_SIGNED_URL_MINUTES', 60),

    // Folder (on the public disk today) => the private disk it moves to.
    // Stay public: logos, site-settings, blogs, seo-settings, vendors,
    // credit-accounts, contacts, users, employees, and partners/ (branding in
    // partners/{id}/settings and /site, profile pictures) - shown on login
    // pages, emails and the website. (public/agreements is an empty leftover;
    // agreement images are in agreement-images below.)
    'private_folders' => [
        Folder::AUDIT => env('AUDIT_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::BORROWERS => env('BORROWER_DOCUMENTS_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::CREDIT_REPORTS => env('CREDIT_REPORT_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::FUNDING_APPLICATION => env('FUNDING_APPLICATION_DOCUMENTS_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::PARTNER_DOCUMENTS => env('PARTNER_DOCUMENTS_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::EMBED_FORMS => env('EMBED_FORM_ATTACHMENTS_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        Folder::ATTACHMENTS => env('ACTIVITY_ATTACHMENTS_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        // Logos and signatures inside agreement templates and signed
        // agreements, one file per distinct image (AgreementImageStore).
        Folder::AGREEMENT_IMAGES => env('AGREEMENT_IMAGE_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
        // The Resources page's files. Its providers' logos are public, in
        // resource-providers/ (ResourceProvider::LOGO_FOLDER).
        Folder::RESOURCES => env('RESOURCES_DISK', env('FILES_PRIVATE_DISK', Disk::LOCAL)),
    ],

    // The name a file is kept under: {readable-name}_{random}.{ext}.
    'name' => [
        'max_slug_length' => 60,
        'suffix_length' => 8,
        'fallback' => 'file',
    ],
];
