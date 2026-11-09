<?php

return [
    'path_not_found' => 'File path not found.',
    'store_failed' => 'Failed to store the uploaded file on the server.',
    'store_failed_log' => 'File storage failed for path: :path. Check permissions and disk space.',
    'move' => [
        'unknown_folder' => '":folder" is not a private folder. Private folders: :folders.',
        'same_disk' => '":folder" is already on the public disk\'s own storage (disk ":disk"); nothing to move.',
        'dry_run' => 'Dry run: nothing will be copied or deleted.',
        'would_copy' => 'Would copy :path',
        'copied' => 'Copied :path',
        'already_there' => 'Already on the private disk: :path',
        'would_delete' => 'Would delete the public copy of :path',
        'deleted' => 'Deleted the public copy of :path',
        'conflict' => 'Not moved: a different file is already at :path on the private disk.',
        'mismatch' => 'Not moved: the private copy of :path does not match the original.',
        'failed' => 'Not moved: :path (:error)',
        'done' => ':folder: :copied copied, :present already there, :deleted public copies deleted, :failed not moved.',
    ],
    'relocate' => [
        'unknown_kind' => '":kind" cannot be relocated. Kinds: :kinds.',
        'would_move' => 'Would move :what: :from -> :to',
        'moved' => 'Moved :what: :from -> :to',
        'missing' => 'Left alone, its file is not on disk: :what :path',
        'done' => ':kind: :moved moved, :missing missing, :failed not moved.',
        'what' => [
            'partner_document' => 'partner document #:id',
            'credit_report' => 'credit report #:id',
            'credit_profile' => 'credit profile #:id',
            'provider_logo' => 'logo of provider #:ids',
        ],
    ],
];
