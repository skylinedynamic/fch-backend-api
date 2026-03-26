<?php

namespace App\Services\FCH\Common\ManageAgreement;

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
    /**
     * Check if the authenticated partner must sign an agreement.
     * returns ['needs_sign' => bool, 'template' => ?array]
     */
    public function checkSignNeed(User $user): array
    {
        try {
            // 1. Validate Entity Type
            if ($user->entity_type !== Partner::class) {
                return $this->noSignNeeded();
            }

            $partner = $user->partner;
            if (!$partner || $partner->is_host || $partner->has_sign) {
                return $this->noSignNeeded();
            }

            // 2. Resolve Host Partner (Platform Owner)
            $hostPartner = $this->getHostPartner();
            if (!$hostPartner) {
                return $this->noSignNeeded();
            }

            // 3. Check Global Signed Feature Flag
            $signingEnabled = AppConfiguration::get('agreement_signing_enabled', '0', Partner::class, $hostPartner->id);
            if (!filter_var($signingEnabled, FILTER_VALIDATE_BOOLEAN)) {
                return $this->noSignNeeded();
            }

            // 4. Fetch Active "Partner" Agreement Template
            $template = $this->getActiveTemplate($hostPartner->id, 'partner');
            if (!$template) {
                return $this->noSignNeeded();
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
            Log::error("AgreementService@checkSignNeed: " . $e->getMessage());
            return $this->noSignNeeded();
        }
    }

    /**
     * Save/Submit the signed agreement snapshot.
     */
    public function submitSign(User $user, array $data, string $ip): bool
    {
        // 1. Pre-Signature Validations
        $this->validateUserForSigning($user);

        $partner = $user->partner;
        $templateId = $data['agreement_template_id'] ?? null;

        $template = AgreementTemplate::where('id', '=', $templateId)
            ->where('is_active', '=', true)
            ->first();

        if (!$template) {
            throw new Exception(__('agreement.not_found_or_inactive'));
        }

        // 2. Atomic Transaction for Signature & Status Update
        return DB::transaction(function () use ($user, $partner, $template, $ip) {
            AgreementSign::create([
                'user_id'               => $user->id,
                'entity_type'           => $user->entity_type, // Use user type for relationship
                'entity_id'             => $user->entity_id,
                'agreement_template_id' => $template->id,
                'ip_address'            => $ip,
                'content_html'          => $template->template_html,
            ]);

            return $partner->update(['has_sign' => true]);
        });
    }

    /**
     * Get All Templates for the specified Partner.
     */
    public function getTemplates(int $partnerId)
    {
        return AgreementTemplate::where('partner_id', '=', $partnerId)
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Store or Update an Agreement Template.
     */
    public function storeTemplate(array $data, int $partnerId, ?int $id = null): AgreementTemplate
    {
        return DB::transaction(function () use ($data, $partnerId, $id) {
            $payload = [
                'partner_id'    => $partnerId,
                'slug'          => $data['slug'],
                'template_html' => $data['template_html'],
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

    /**
     * Show Template by Slug & Partner.
     */
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

    /**
     * Update Template Status (Toggle Activation).
     */
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

    /* ── Internal Helpers ────────────────────────────────────────────────── */

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

    private function noSignNeeded(): array
    {
        return ['needs_sign' => false, 'template' => null];
    }

    private function validateUserForSigning(User $user): void
    {
        if ($user->entity_type !== Partner::class) {
            throw new Exception(__('agreement.not_partner'));
        }

        $partner = $user->partner;
        if (!$partner) {
            throw new Exception(__('agreement.not_found'));
        }

        if ($partner->is_host) {
            throw new Exception(__('agreement.host_forbidden'));
        }

        if ($partner->has_sign) {
            throw new Exception(__('agreement.already_signed'));
        }
    }
}
