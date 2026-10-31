<?php

/*
|--------------------------------------------------------------------------
| Upload file types (BUG-015)
|--------------------------------------------------------------------------
|
| What the server accepts, whatever the portals let a user pick. Read by
| App\Rules\SafeUpload, which also checks the file's content, so a file named
| .jpg that holds HTML is refused.
|
| SVG is never a document or an attachment: it can carry a <script> that runs
| when the file is opened from /storage. Logos and images may be SVG; those
| are stripped of scripts before they are stored.
*/

return [

    // Documents, file manager uploads and activity attachments. The portals'
    // file manager offers the same list (src/modules/filemanager/config.ts).
    'document_extensions' => [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'tiff', 'tif', 'ico', 'avif',
        // Documents
        'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'ppt', 'pptx',
        // Data
        'xls', 'xlsx', 'csv', 'ods',
        // Archives
        'zip', 'rar', '7z',
        // Videos
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v',
    ],

    // Logos, favicons and other pictures shown in the apps and on the website.
    'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'svg'],

    // Content that a browser would run, detected from the file itself. Refused
    // under any name - except an SVG logo, which is sanitised instead.
    'script_mime_types' => [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'text/x-shellscript',
        'application/x-sh',
    ],

    /*
    | Size limits and picture checks (BUG-014)
    |
    | A picture - any of picture_extensions, uploaded as a document or as an
    | image - may be up to max_kilobytes.picture; anything else up to
    | max_kilobytes.document. Nginx (client_max_body_size) and PHP
    | (upload_max_filesize, post_max_size) must allow at least the document
    | limit, or the request is cut off before this check can answer.
    */
    'max_kilobytes' => [
        'document' => 20 * 1024,
        'picture' => 5 * 1024,
    ],

    'picture_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'tiff', 'tif', 'ico', 'avif', 'svg',
    ],

    // Pictures PHP can open (getimagesize). One that does not open is refused:
    // a PNG signature followed by zeros is not a picture.
    'decodable_picture_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],

    // What a profile picture may be, chosen from the file manager.
    'profile_picture_extensions' => ['jpg', 'jpeg', 'png'],

];
