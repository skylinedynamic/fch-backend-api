<?php

namespace App\Support\Files;

/**
 * The folders the file manager opens, and who opens each (R3-BUG-002). A
 * folder not listed here is refused for everyone, the host included - the
 * folders their own features write (audit, credit reports, application
 * documents, agreement images, attachments) among them.
 *
 * The root is {entity_type}/{entity_id}, or {entity_type} alone with get_all.
 */
final class FileManagerFolders
{
    /** A record's folder: the host's team opens any; another partner's team only its own records'. */
    public const OWN_RECORD = 'own_record';
    /** A partner's folder: the host's team opens any; another partner's team only its own partner's. */
    public const OWN_PARTNER = 'own_partner';
    /** Only the host's team opens it. */
    public const HOST_ONLY = 'host_only';

    /** Folder => who opens it. */
    public const RULES = [
        Folder::BORROWERS => self::OWN_RECORD,
        Folder::EMPLOYEES => self::OWN_RECORD,
        Folder::PARTNERS => self::OWN_PARTNER,
        Folder::PARTNER_DOCUMENTS => self::HOST_ONLY,
        Folder::USERS => self::HOST_ONLY,
        Folder::RESOURCES => self::HOST_ONLY,
        Folder::VENDORS => self::HOST_ONLY,
        Folder::BLOGS => self::HOST_ONLY,
        Folder::SEO_SETTINGS => self::HOST_ONLY,
        Folder::SITE_SETTINGS => self::HOST_ONLY,
        Folder::CREDIT_ACCOUNTS => self::HOST_ONLY,
    ];

    /**
     * One shared library each, which its screen opens whole (get_all). Any
     * other folder is per record and is never opened whole - that would list
     * every partner's files at once.
     */
    public const SHARED = [
        Folder::VENDORS,
        Folder::BLOGS,
        Folder::SEO_SETTINGS,
        Folder::SITE_SETTINGS,
        Folder::CREDIT_ACCOUNTS,
    ];

    /** Who opens this folder (a RULES value), or null when the file manager never opens it. */
    public static function rule(string $folder): ?string
    {
        return self::RULES[$folder] ?? null;
    }

    public static function isShared(string $folder): bool
    {
        return in_array($folder, self::SHARED, true);
    }

    /**
     * Whether files in this folder are served from /storage without signing
     * in (not listed in config/files.php private_folders): pictures only.
     */
    public static function isPublic(string $folder): bool
    {
        return !array_key_exists($folder, (array) config('files.private_folders', []));
    }
}
