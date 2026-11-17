<?php

namespace App\Http\Resources\FCH\Common\ManageAgreement;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved agreement template. The list leaves the blocks out (they can be
 * large); the one template picked carries them (withBlocks()).
 */
class AgreementSavedTemplateResource extends JsonResource
{
    private bool $withBlocks = false;

    public function withBlocks(): static
    {
        $this->withBlocks = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'template_json' => $this->when($this->withBlocks, fn () => $this->template_json),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
