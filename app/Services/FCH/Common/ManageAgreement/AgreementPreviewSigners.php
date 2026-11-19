<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Models\Borrower\Borrower;
use App\Models\Common\AgreementTemplate;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agreement Builder › Preview as Partner / Preview as Borrower: the partners
 * and clients the preview can be shown as, so it reads as one of them would
 * see it.
 *
 * What a viewer may list is what they may see elsewhere:
 *   the host's team    every partner (their own login) and every client, of any partner
 *   a partner's team   no partners, and only its own clients
 *   a borrower         nobody
 *
 * Only logins are listed: a partner or client without one has nothing to sign
 * yet. Each row carries what the list shows - name, email, business name, the
 * client's partner, enrollment type and whether they signed - nothing more.
 */
class AgreementPreviewSigners
{
    /** The most rows the picker shows at once; a search narrows it down. */
    public const LIMIT = 50;

    /** @return list<array<string, mixed>> */
    public function list(User $viewer, string $type, ?string $search = null): array
    {
        $query = $this->query($viewer, $type);
        if (!$query) {
            return [];
        }

        if ($search = trim((string) $search)) {
            $this->search($query, $type, $search);
        }

        $users = $query
            ->with($type === AgreementTemplate::SLUG_PARTNER ? 'partner' : 'borrower.partner')
            ->orderBy('users.id', 'desc')
            ->limit(self::LIMIT)
            ->get();

        // One query for every row's enrollment type, not one per row.
        $enrollmentTypes = (new AgreementEnrollmentFields())->enrollmentTypes($type, $users->pluck('entity_id')->filter()->map(fn ($id) => (int) $id)->all());

        return $users
            ->map(fn (User $user) => $this->row($user, $type) + [
                'enrollment_type' => $enrollmentTypes[(int) $user->entity_id] ?? null,
                'has_sign' => (bool) ($type === AgreementTemplate::SLUG_PARTNER ? $user->partner?->has_sign : $user->borrower?->has_sign),
            ])
            ->values()
            ->all();
    }

    /** The partner or client with this login, when the viewer may list them. */
    public function find(User $viewer, string $type, int $userId): ?User
    {
        // A partner for the partner agreement, a client for the borrower one - never the other way round.
        return $this->query($viewer, $type)?->whereKey($userId)->first();
    }

    /**
     * Whose agreement the signer sees, for its {{owner.*}} fields: the host's
     * partner agreement for a partner, their own partner's borrower agreement
     * for a client - whichever builder the preview is opened from.
     */
    public function ownerFor(User $signer, string $type): ?Partner
    {
        return $type === AgreementTemplate::SLUG_BORROWER
            ? $signer->borrower?->partner
            : Partner::where('is_host', true)->first();
    }

    /** The host's admins and staff - never a non-host partner's team, nor a borrower. */
    private function isHostTeam(User $viewer): bool
    {
        return (bool) $viewer->partner?->is_host && $viewer->entity_type !== Borrower::class;
    }

    private function query(User $viewer, string $type): ?Builder
    {
        if ($viewer->entity_type === Borrower::class || !$viewer->partner_id) {
            return null;
        }
        $hostTeam = $this->isHostTeam($viewer);

        if ($type === AgreementTemplate::SLUG_PARTNER) {
            // Other partners are the host's to see.
            if (!$hostTeam) {
                return null;
            }

            return User::query()
                ->where('entity_type', Partner::class)
                // The partner's own login - not its staff, who never sign.
                ->whereColumn('users.entity_id', 'users.partner_id')
                ->whereHas('partner', fn (Builder $q) => $q->where('is_host', false));
        }

        if ($type === AgreementTemplate::SLUG_BORROWER) {
            $query = User::query()->where('entity_type', Borrower::class);

            // The host's team: every partner's clients. A partner's team: its own only.
            return $hostTeam
                ? $query->whereHas('borrower')
                : $query->whereHas('borrower', fn (Builder $q) => $q->where('partner_id', $viewer->partner_id));
        }

        return null;
    }

    private function search(Builder $query, string $type, string $search): void
    {
        $like = '%' . addcslashes($search, '%_\\') . '%';

        $query->where(function (Builder $q) use ($type, $like) {
            $q->where('users.email', 'like', $like)->orWhere('users.name', 'like', $like);

            if ($type === AgreementTemplate::SLUG_PARTNER) {
                $q->orWhereHas('partner', fn (Builder $p) => $p
                    ->where('full_name', 'like', $like)
                    ->orWhere('company_name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            } else {
                $q->orWhereHas('borrower', fn (Builder $b) => $b
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('business_name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            }
        });
    }

    /** @return array{user_id: int, name: string, email: string, business_name: string} */
    private function row(User $user, string $type): array
    {
        if ($type === AgreementTemplate::SLUG_PARTNER) {
            $partner = $user->partner;

            return [
                'user_id' => $user->id,
                'name' => (string) ($partner?->full_name ?: $user->name),
                'email' => (string) ($partner?->email ?: $user->email),
                'business_name' => (string) ($partner?->company_name ?? ''),
            ];
        }

        $borrower = $user->borrower;
        $name = trim(implode(' ', array_filter([$borrower?->first_name, $borrower?->last_name])));

        return [
            'user_id' => $user->id,
            'name' => $name !== '' ? $name : (string) $user->name,
            'email' => (string) ($borrower?->email ?: $user->email),
            'business_name' => (string) ($borrower?->business_name ?? ''),
            // Whose client: the host's team sees every partner's.
            'partner_name' => (string) ($borrower?->partner?->company_name ?: $borrower?->partner?->full_name ?: ''),
        ];
    }
}
