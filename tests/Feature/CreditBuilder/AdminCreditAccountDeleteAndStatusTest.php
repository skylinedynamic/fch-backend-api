<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder\CreditAccountController;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CreditAccount;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-042: Admin › Credit Builder › Credit Accounts - the host deletes cards
 * from the list (one or several), but only cards no borrower has applied for,
 * directly or through a Capital Access play; and sets a card Active/Inactive
 * from its details page.
 */
#[CoversClass(CreditAccountController::class)]
class AdminCreditAccountDeleteAndStatusTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const BULK = '/api/v1/admin/credit-builder/credit-accounts/multiple-delete';

    private Partner $host;

    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->actingAsUser($this->hostAdmin);
    }

    private function card(string $name): int
    {
        return DB::table('credit_accounts')->insertGetId(['card_name' => $name, 'issuer' => 'Bank', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function borrowerId(): int
    {
        return $this->makeBorrowerUser($this->makeWhiteLabelPartner())->entity_id;
    }

    /** A borrower applied for the card from Credit Accounts. */
    private function appliedDirectly(int $card): void
    {
        DB::table('credit_account_applications')->insert([
            'borrower_id' => $this->borrowerId(), 'credit_account_id' => $card, 'status' => 'Requested',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A borrower applied for the card as a step of a Capital Access play. */
    private function appliedInAPlay(int $card): void
    {
        $play = CapitalAccess::create(['name' => 'Starter Stack', 'status' => CapitalAccess::STATUS_ACTIVE])->id;
        $step = DB::table('capital_access_sequences')->insertGetId([
            'capital_access_id' => $play, 'credit_account_id' => $card, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('capital_access_applications')->insert([
            'borrower_id' => $this->borrowerId(), 'capital_access_id' => $play, 'capital_access_sequence_id' => $step,
            'status' => 'Pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function isDeleted(int $card): bool
    {
        return CreditAccount::withTrashed()->findOrFail($card)->trashed();
    }

    #[Test]
    public function several_cards_no_one_applied_for_are_deleted_together(): void
    {
        $a = $this->card('Card A');
        $b = $this->card('Card B');
        $kept = $this->card('Card C');

        $this->postJson(self::BULK, ['ids' => [$a, $b]])
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertTrue($this->isDeleted($a));
        $this->assertTrue($this->isDeleted($b));
        $this->assertFalse($this->isDeleted($kept));
    }

    #[Test]
    public function a_selection_with_a_card_someone_applied_for_deletes_nothing_and_names_it(): void
    {
        $free = $this->card('Free Card');
        $applied = $this->card('Chase Ink Cash');
        $this->appliedDirectly($applied);

        $this->postJson(self::BULK, ['ids' => [$free, $applied]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.applied.0.name', 'Chase Ink Cash');

        $this->assertFalse($this->isDeleted($free));
        $this->assertFalse($this->isDeleted($applied));
    }

    #[Test]
    public function a_card_applied_for_through_a_play_cannot_be_deleted_either(): void
    {
        $card = $this->card('Play Card');
        $this->appliedInAPlay($card);

        $this->postJson(self::BULK, ['ids' => [$card]])->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/credit-builder/credit-accounts/{$card}")->assertUnprocessable();

        $this->assertFalse($this->isDeleted($card));
    }

    #[Test]
    public function the_single_delete_keeps_the_same_rule(): void
    {
        $free = $this->card('Free Card');
        $applied = $this->card('Applied Card');
        $this->appliedDirectly($applied);

        $this->deleteJson("/api/v1/admin/credit-builder/credit-accounts/{$applied}")->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/credit-builder/credit-accounts/{$free}")->assertOk();

        $this->assertFalse($this->isDeleted($applied));
        $this->assertTrue($this->isDeleted($free));
    }

    #[Test]
    public function the_list_says_which_cards_can_be_deleted(): void
    {
        $free = $this->card('Free Card');
        $direct = $this->card('Direct Card');
        $inPlay = $this->card('Play Card');
        $this->appliedDirectly($direct);
        $this->appliedInAPlay($inPlay);

        $rows = collect($this->postJson('/api/v1/admin/credit-builder/credit-accounts/fetch-accounts', ['per_page' => 50])->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertTrue($rows[$free]['can_delete']);
        $this->assertFalse($rows[$direct]['can_delete']);
        $this->assertFalse($rows[$inPlay]['can_delete']);
    }

    #[Test]
    public function the_selection_must_name_cards(): void
    {
        $this->postJson(self::BULK, [])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson(self::BULK, ['ids' => ['x']])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
    }

    // -- Status on the details page ------------------------------------------

    #[Test]
    public function the_details_page_saves_the_status(): void
    {
        $card = $this->card('Card A');
        $payload = fn (string $status) => [
            'card_name' => 'Card A', 'issuer' => 'Bank', 'credit_category' => 'Business',
            'typical_personal_fico_min' => 650, 'time_in_business_min_months' => 6,
            'reference_url' => 'https://bank.test/card', 'image_url' => 'credit-accounts/card-a.png', 'status' => $status,
        ];

        $this->putJson("/api/v1/admin/credit-builder/credit-accounts/{$card}", $payload('Inactive'))->assertOk();
        $this->assertSame('Inactive', CreditAccount::findOrFail($card)->status);

        $this->putJson("/api/v1/admin/credit-builder/credit-accounts/{$card}", $payload('Whatever'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame('Inactive', CreditAccount::findOrFail($card)->status);
    }
}
