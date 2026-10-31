<?php

namespace App\Casts;

use App\Services\FCH\Common\ManageAgreement\AgreementImageStore;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Agreement HTML/JSON whose images live on disk (see AgreementImageStore):
 * saved with short image references, read back with the images in place.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class AgreementImages implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return app(AgreementImageStore::class)->inline($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return app(AgreementImageStore::class)->extract($value === null ? null : (string) $value);
    }
}
