<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerDocType extends Model
{
    use HasFactory, SoftDeletes;

    public const DRIVERS_LICENSE = "Driver's License";
    public const VOIDED_BUSINESS_CHECK = 'Voided Business Check';
    public const EIN_CORPORATION_DOCS = 'EIN / Corporation Docs';
    public const BANK_STATEMENTS = 'Business Bank Statements';

    /**
     * The only categories a borrower has to upload a document in for the
     * Getting Started walkthrough; every other category on the Documents page
     * is optional. Matched by name, ignoring case, surrounding spaces and
     * whether the apostrophe is straight or curly (the seeder writes
     * "Driver’s License"). The borrower portal keeps the same list
     * (src/config/documents.ts).
     */
    public const REQUIRED_NAMES = [
        self::DRIVERS_LICENSE,
        self::VOIDED_BUSINESS_CHECK,
        self::EIN_CORPORATION_DOCS,
        self::BANK_STATEMENTS,
    ];

    private const CURLY_APOSTROPHE = '’';

    /** Whether a category with this name is one of REQUIRED_NAMES. */
    public static function isRequiredName(string $name): bool
    {
        return in_array(self::comparableName($name), self::comparableRequiredNames(), true);
    }

    /** The categories every borrower has to upload a document in. */
    public function scopeRequired($query)
    {
        $names = self::comparableRequiredNames();
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        // The same folding as comparableName(), done by the database.
        return $query->whereRaw(
            "LOWER(REPLACE(TRIM(name), ?, ?)) IN ($placeholders)",
            [self::CURLY_APOSTROPHE, "'", ...$names]
        );
    }

    /** A name folded for comparing: trimmed, lower case, straight apostrophes. */
    private static function comparableName(string $name): string
    {
        return strtolower(str_replace(self::CURLY_APOSTROPHE, "'", trim($name)));
    }

    /** @return string[] */
    private static function comparableRequiredNames(): array
    {
        return array_map(self::comparableName(...), self::REQUIRED_NAMES);
    }

    protected $table = 'borrower_doc_types';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
