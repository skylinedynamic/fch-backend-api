<?php

namespace App\Enums\Attributes;

use App\Enums\PermissionModule;
use Attribute;

/**
 * Describes one App\Enums\Permission case: the module it is listed under, and
 * which partners may be given it.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class PermissionInfo
{
    /**
     * @param  bool  $certified  A Certified Partner's roles may hold it.
     * @param  bool  $host  Only the host's roles may hold it.
     * @param  string|null  $key  The config key a seeder looks it up by
     *                            (config('permissions.<module>.permissions.<key>')).
     * @param  bool  $developer  Only the reserved Developer role may hold it.
     */
    public function __construct(
        public readonly PermissionModule $module,
        public readonly bool $certified,
        public readonly bool $host,
        public readonly ?string $key = null,
        public readonly bool $developer = false,
    ) {
    }
}
