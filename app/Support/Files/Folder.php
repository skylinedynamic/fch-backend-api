<?php

namespace App\Support\Files;

/**
 * The top-level folders uploads are kept in (BUG-016). config/files.php lists
 * which of them are private; every other one stays public.
 */
final class Folder
{
    // Private.
    public const AUDIT = 'audit';
    public const BORROWERS = 'borrowers';
    public const CREDIT_REPORTS = 'credit-reports';
    public const FUNDING_APPLICATION = 'funding-application';
    public const PARTNER_DOCUMENTS = 'partner-documents';
    public const EMBED_FORMS = 'embed_forms';
    public const ATTACHMENTS = 'attachments';
    public const AGREEMENT_IMAGES = 'agreement-images';
    public const RESOURCES = 'resources';

    // Public.
    public const PARTNERS = 'partners';
    public const RESOURCE_PROVIDERS = 'resource-providers';
    public const CONTACTS = 'contacts';
    public const EMPLOYEES = 'employees';
    public const BLOGS = 'blogs';
    public const SEO_SETTINGS = 'seo-settings';
    public const SITE_SETTINGS = 'site-settings';
    public const CREDIT_ACCOUNTS = 'credit-accounts';
    /** Profile pictures of users with no partner or staff record (host admins). */
    public const USERS = 'users';
    /** The tier vendors' logos. */
    public const VENDORS = 'vendors';

    // Old public folders, read only by the commands that clean them up.
    public const OLD_FUNDING_APPLICATIONS = 'funding-applications';
    public const OLD_STORAGE = 'storage';

    /** An old application's subfolders: its signed copies and its download copies. */
    public const SIGNED = 'signed';
    public const DOWNLOADS = 'downloads';

    /** A partner's or borrower's old document subfolder. */
    public const DOCUMENTS = 'documents';
}
