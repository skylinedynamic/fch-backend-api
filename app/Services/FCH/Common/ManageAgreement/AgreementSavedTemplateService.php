<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Common\AgreementSavedTemplate;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A partner's saved agreement templates (Agreement Builder › Save as Template).
 * A partner saves its borrower agreement; the host, whose partner agreement
 * every partner signs, also saves that one.
 */
class AgreementSavedTemplateService
{
    /** @return Collection<int, AgreementSavedTemplate> newest first */
    public function list(int $partnerId, string $slug): Collection
    {
        return AgreementSavedTemplate::where('partner_id', $partnerId)
            ->where('slug', $slug)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'partner_id', 'slug', 'name', 'created_at', 'updated_at']);
    }

    /** @param array{slug: string, name: string, template_json: string} $data */
    public function create(array $data, int $partnerId, ?int $userId): AgreementSavedTemplate
    {
        if ($data['slug'] === AgreementTemplate::SLUG_PARTNER && !Partner::whereKey($partnerId)->value('is_host')) {
            throw ValidationException::withMessages(['slug' => __('agreement.saved_template.partner_host_only')]);
        }

        $this->ensureNameFree($partnerId, $data['slug'], $data['name']);

        return AgreementSavedTemplate::create([
            'partner_id' => $partnerId,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'template_json' => $data['template_json'],
            'created_by' => $userId,
        ]);
    }

    /** The owner's template; 404 for anyone else's. */
    public function find(int $partnerId, int $id): AgreementSavedTemplate
    {
        return AgreementSavedTemplate::where('partner_id', $partnerId)->findOrFail($id);
    }

    /**
     * Rename the owner's template, replace its agreement, or both (404 for
     * anyone else's). It may keep its own name, in any case.
     *
     * @param array{name?: string, template_json?: string} $data
     */
    public function update(int $partnerId, int $id, array $data): AgreementSavedTemplate
    {
        $template = $this->find($partnerId, $id);

        if (array_key_exists('name', $data)) {
            $this->ensureNameFree($partnerId, $template->slug, $data['name'], $template->id);
        }

        $template->update(array_intersect_key($data, array_flip(['name', 'template_json'])));

        return $template;
    }

    public function delete(int $partnerId, int $id): void
    {
        $this->find($partnerId, $id)->delete();
    }

    /** A name is used once per owner and agreement type, whatever its case. */
    private function ensureNameFree(int $partnerId, string $slug, string $name, ?int $exceptId = null): void
    {
        $taken = AgreementSavedTemplate::where('partner_id', $partnerId)
            ->where('slug', $slug)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('agreement.saved_template.name_taken')]);
        }
    }
}
