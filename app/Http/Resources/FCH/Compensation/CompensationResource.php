<?php

namespace App\Http\Resources\FCH\Compensation;

use App\Models\Compensation\CompensationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * An entity's compensation setup, shaped exactly like the payload the store
 * and update endpoints accept, so a client reads back what it sends.
 *
 * @mixin Collection<int, CompensationSetting>
 */
class CompensationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Collection<int, CompensationSetting> $rows */
        $rows = $this->resource;

        return [
            // Every row of a setup shares one paid_by, so it's lifted out of
            // the rows rather than repeated in each of them.
            'paid_by' => $rows->first()?->paid_by ?? CompensationSetting::PAID_BY_NONE,
            'compensations' => $rows->map(fn (CompensationSetting $row) => [
                'id' => $row->id,
                'service' => $row->service,
                'payout_type' => $row->payout_type,
                'amount' => $row->amount,
            ])->values(),
        ];
    }
}
