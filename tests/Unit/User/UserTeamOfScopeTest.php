<?php

namespace Tests\Unit\User;

use App\Models\Partner\Partner;
use App\Models\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A partner's team - the users who may own its leads: its own Admin logins
 * for the host, its Partner logins for anyone else. Never a borrower.
 */
#[CoversClass(User::class)]
class UserTeamOfScopeTest extends TestCase
{
    private function partner(int $id, bool $isHost): Partner
    {
        $partner = new Partner(['is_host' => $isHost]);
        $partner->id = $id;

        return $partner;
    }

    private function wheres(Partner $partner): array
    {
        return collect(User::query()->teamOf($partner)->getQuery()->wheres)
            ->mapWithKeys(fn ($where) => [$where['column'] => $where['value']])
            ->all();
    }

    #[Test]
    public function the_hosts_team_is_its_admin_logins(): void
    {
        $this->assertSame(['partner_id' => 1, 'user_type' => User::ADMIN], $this->wheres($this->partner(1, true)));
    }

    #[Test]
    public function another_partners_team_is_its_partner_logins(): void
    {
        $this->assertSame(['partner_id' => 2, 'user_type' => User::PARTNER], $this->wheres($this->partner(2, false)));
    }
}
