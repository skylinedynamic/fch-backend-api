<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementSign;
use App\Models\Common\AgreementTemplate;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * AgreementService
 *
 * Centralized business logic for Agreement Templates and Signing workflows.
 */
class AgreementService
{
    public function checkSignNeed(User $user): array
    {
        try {
            // templateOwnerId is whose AgreementTemplate row governs this signing
            // check - getActiveTemplate() below looks ONLY at that partner_id, no
            // fallback to the host's template. The "partner" agreement is always
            // the host's (a partner signs it WITH the host, so only the host may
            // turn it off); the "borrower" agreement is each borrower's own
            // (non-host) partner's, since that partner builds and maintains it for
            // their own borrowers and must be able to disable it for them - a
            // partner with no template of their own means no signing is required
            // for their borrowers, by design (not "falls back to the host's").
            if ($user->entity_type === Partner::class) {
                $entity = $user->partner;
                if (!$entity) {
                    return $this->noSignNeeded(__('agreement.partner_profile_not_found'));
                }
                if ($entity->is_host) {
                    return $this->noSignNeeded(__('agreement.host_exempt'));
                }
                $slug = 'partner';
                $templateOwnerId = null; // resolved to the host partner below
            } elseif ($user->entity_type === Borrower::class) {
                $entity = $user->borrower;
                if (!$entity) {
                    return $this->noSignNeeded(__('agreement.borrower_profile_not_found'));
                }
                $slug = 'borrower';
                $templateOwnerId = $entity->partner_id;
            } else {
                return $this->noSignNeeded(__('agreement.not_allowed'));
            }

            if ($entity->has_sign) {
                return $this->noSignNeeded(__('agreement.already_signed'));
            }

            $hostPartner = $this->getHostPartner();
            if (!$hostPartner) {
                return $this->noSignNeeded(__('agreement.system_owner_not_found'));
            }

            // 3. Check Global Signed Feature Flag
            $signingEnabled = AppConfiguration::get('agreement_signing_enabled', '0', AppConfiguration::MODULE_AGREEMENT, $hostPartner->id);

            if (!filter_var($signingEnabled, FILTER_VALIDATE_BOOLEAN)) {
                return $this->noSignNeeded(__('agreement.globally_disabled'));
            }

            $template = $this->getActiveTemplate($templateOwnerId ?? $hostPartner->id, $slug);
            if (!$template) {
                return $this->noSignNeeded(__('agreement.no_active_template', ['slug' => $slug]));
            }

            return [
                'needs_sign' => true,
                'template'   => [
                    'id'            => $template->id,
                    'slug'          => $template->slug,
                    'template_html' => $template->template_html,
                ],
            ];
        } catch (Exception $e) {
            Log::error(__('agreement.log_check_sign_need_error', ['error' => $e->getMessage()]));
            return $this->noSignNeeded(__('agreement.verification_error', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Cancel a signed agreement, or Re-sign one whose signed copy is missing
     * (BUG-106): either way the partner or client is no longer marked as
     * signed and is asked to sign again.
     */
    public function cancelSign(int $userId): bool
    {
        $sign = AgreementSign::where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->first();

        $user = User::find($userId);
        if (!$user) {
            throw new Exception(__('user.not_found'));
        }

        $entity = $this->signingEntity($user);
        if (!$entity) {
            throw new Exception(__('agreement.not_found'));
        }

        if (!$sign && !$entity->has_sign) {
            throw new Exception(__('agreement.no_signed_agreement'));
        }

        return DB::transaction(function () use ($user, $entity, $sign) {
            $sign?->delete();
            $entity->update(['has_sign' => false]);
            return $user->update(['is_refresh' => '1']);
        });
    }

    /** Whether the user's partner or client record is marked as signed (has_sign). */
    public function isMarkedSigned(int $userId): bool
    {
        $user = User::find($userId);

        return (bool) ($user ? $this->signingEntity($user)?->has_sign : false);
    }

    /** The record that carries has_sign: the partner for a partner login, else the client. */
    private function signingEntity(User $user): Partner|Borrower|null
    {
        return $user->entity_type === Partner::class ? $user->partner : $user->borrower;
    }

    public function submitSign(User $user, array $data, string $ip): bool
    {
        $this->validateUserForSigning($user);

        $entity = ($user->entity_type === Partner::class) ? $user->partner : $user->borrower;
        $templateId = $data['agreement_template_id'] ?? null;

        // Only the agreement this user is asked to sign (see checkSignNeed()):
        // the host's "partner" agreement for a partner, their own partner's
        // "borrower" agreement for a borrower.
        [$ownerId, $slug] = $user->entity_type === Partner::class
            ? [$this->getHostPartner()?->id, 'partner']
            : [$entity->partner_id, 'borrower'];

        $template = AgreementTemplate::where('id', '=', $templateId)
            ->where('partner_id', '=', $ownerId)
            ->where('slug', '=', $slug)
            ->where('is_active', '=', true)
            ->first();

        if (!$template) {
            throw new Exception(__('agreement.not_found_or_inactive'));
        }

        return DB::transaction(function () use ($user, $entity, $template, $ip, $data) {
            $contentHtml = $data['content_html'] ?? $template->template_html;

            $fieldValues = $data['field_values'] ?? null;
            if (is_string($fieldValues)) {
                $fieldValues = json_decode($fieldValues, true);
            }

            AgreementSign::create([
                'user_id'               => $user->id,
                'entity_type'           => $user->entity_type,
                'entity_id'             => $user->entity_id,
                'agreement_template_id' => $template->id,
                'ip_address'            => $ip,
                'content_html'          => $contentHtml,
                'field_values'          => $fieldValues ? json_encode($fieldValues) : null,
            ]);

            $user->update(['is_refresh' => true]);

            return $entity->update(['has_sign' => true]);
        });
    }

    public function getSignedHtml(int $userId): string
    {
        $sign = AgreementSign::where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->first();

        if (!$sign) {
            return '';
        }

        return $sign->content_html ?? '';
    }

    public function downloadAgreement(int $userId)
    {
        $sign = AgreementSign::where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->first();

        if (!$sign) {
            throw new Exception(__('agreement.no_signed_agreement'));
        }

        $html = $sign->content_html;

        if (!$html) {
            throw new Exception(__('agreement.no_signed_agreement'));
        }

        $pdfCss = '
            <style>
                @page { margin: 30px; }
                body { font-family: "DejaVu Sans", sans-serif !important; line-height: 1.4; color: #333; }
                h1, h2, h3, h4 { color: #405189; margin-bottom: 12px; page-break-after: avoid; }
                p, div { margin-bottom: 6px; }
                .p-field, .agreement-field, .card, section { page-break-inside: avoid !important; margin-bottom: 15px; }
                img { max-width: 100%; height: auto; page-break-inside: avoid; display: block; }
                table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
                tr { page-break-inside: avoid; page-break-after: auto; }
                .text-primary { color: #405189 !important; }
                span[style*="border-bottom"] { border-bottom: none !important; }
                .page-break, .page-sep { page-break-before: always; height: 15px; background: #35434d; display: block; margin: 10px 0; border: none; }
            </style>
        ';

        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($pdfCss . $html);

        return $pdf->download("agreement_" . $userId . ".pdf");
    }

    public function getTemplates(int $partnerId)
    {
        return AgreementTemplate::where('partner_id', '=', $partnerId)
            ->orderBy('id', 'desc')
            ->get();
    }

    public function storeTemplate(array $data, int $partnerId, ?int $id = null): AgreementTemplate
    {
        return DB::transaction(function () use ($data, $partnerId, $id) {
            $payload = [
                'partner_id'    => $partnerId,
                'slug'          => $data['slug'],
                'template_html' => $data['template_html'] ?? '',
                'template_json' => $data['template_json'] ?? null,
                'is_active'     => $data['is_active'] ?? true,
            ];

            if ($id) {
                $agreement = AgreementTemplate::where('id', '=', $id)
                    ->where('partner_id', '=', $partnerId)
                    ->first();

                if (!$agreement) {
                    throw new Exception(__('agreement.not_found'));
                }

                $agreement->update($payload);
                return $agreement;
            }

            return AgreementTemplate::updateOrCreate(
                ['partner_id' => $partnerId, 'slug' => $data['slug']],
                $payload
            );
        });
    }

    public function showTemplate(int $partnerId, string $slug): AgreementTemplate
    {
        $agreement = AgreementTemplate::where('partner_id', '=', $partnerId)
            ->where('slug', '=', $slug)
            ->first();

        if (!$agreement) {
            throw new Exception(__('agreement.not_found'));
        }

        return $agreement;
    }

    public function updateTemplateStatus(int $id, int $partnerId, bool $isActive): AgreementTemplate
    {
        $agreement = AgreementTemplate::where('id', '=', $id)
            ->where('partner_id', '=', $partnerId)
            ->first();

        if (!$agreement) {
            throw new Exception(__('agreement.not_found'));
        }

        $agreement->update(['is_active' => $isActive]);
        return $agreement;
    }

    /**
     * The settings of the Signature block in the Borrower Agreement template
     * that applies to this borrower, so anything else the borrower signs can be
     * styled the same way as the agreement they signed.
     *
     * Their own partner's template is used when it has a signature block, then
     * the host's; null when neither does, and the caller keeps its defaults.
     * Only presentation is taken - never the image a builder may have dropped
     * into the block.
     *
     * @return array<string, mixed>|null
     */
    public function borrowerSignatureBlock(?Borrower $borrower): ?array
    {
        $ownerIds = array_values(array_unique(array_filter([
            $borrower?->partner_id,
            $this->getHostPartner()?->id,
        ])));

        foreach ($ownerIds as $partnerId) {
            $template = $this->getActiveTemplate((int) $partnerId, 'borrower');
            $parsed = $template?->template_json ? json_decode($template->template_json, true) : null;

            if (! is_array($parsed)) {
                continue;
            }

            // Saved as { blocks, pagePad, docBg }; older templates are a bare list.
            $block = $this->findSignatureBlock($parsed['blocks'] ?? $parsed);

            if ($block) {
                return array_intersect_key($block, array_flip(self::SIGNATURE_BLOCK_SETTINGS));
            }
        }

        return null;
    }

    // What the builder's Block Properties panel sets on a Signature block.
    private const SIGNATURE_BLOCK_SETTINGS = [
        'fieldLabel', 'fieldRequired',
        'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'textAlign', 'textDecoration',
        'lineHeight', 'letterSpacing', 'color',
        'bgColor', 'bgOpacity', 'borderRadius', 'padding', 'marginTop', 'marginBottom',
    ];

    /**
     * The first Signature block in a template, including one placed inside a
     * Parties block's columns.
     *
     * @param  mixed  $blocks
     * @return array<string, mixed>|null
     */
    private function findSignatureBlock($blocks): ?array
    {
        if (! is_array($blocks)) {
            return null;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'signature') {
                return $block;
            }

            foreach (['partyA', 'partyB'] as $column) {
                if ($nested = $this->findSignatureBlock($block[$column] ?? null)) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function getHostPartner(): ?Partner
    {
        return Partner::where('is_host', '=', true)->first();
    }

    private function getActiveTemplate(int $partnerId, string $slug): ?AgreementTemplate
    {
        return AgreementTemplate::where('partner_id', '=', $partnerId)
            ->where('slug', '=', $slug)
            ->where('is_active', '=', true)
            ->first();
    }

    private function noSignNeeded(?string $message = null): array
    {
        return [
            'needs_sign' => false,
            'template'   => null,
            'message'    => $message ?? __('agreement.no_sign_needed'),
        ];
    }

    private function validateUserForSigning(User $user): void
    {
        if ($user->entity_type === Partner::class) {
            $entity = $user->partner;
            if (!$entity) {
                throw new Exception(__('agreement.not_found'));
            }
            if ($entity->is_host) {
                throw new Exception(__('agreement.host_forbidden'));
            }
        } elseif ($user->entity_type === Borrower::class) {
            $entity = $user->borrower;
            if (!$entity) {
                throw new Exception(__('agreement.not_found'));
            }
        } else {
            throw new Exception(__('agreement.not_allowed'));
        }

        if ($entity->has_sign) {
            throw new Exception(__('agreement.already_signed'));
        }
    }
}
