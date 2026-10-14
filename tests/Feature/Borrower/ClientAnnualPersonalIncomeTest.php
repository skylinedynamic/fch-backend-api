<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A client's Annual Personal Income on the admin's Business Financials section.
 *
 * The figure is optional. It could be entered but then never changed or removed:
 * the section did not send it on save, and the save did not return it, so the
 * old amount came back on every reload. These pin down the server's half - that
 * it stores a figure, stores a real zero, clears the field when it is emptied,
 * and hands back what it stored.
 */
class ClientAnnualPersonalIncomeTest extends TestCase
{
    use RefreshDatabase;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'michael@example.test',
            'annual_personal_income' => 1000000,
        ]);

        $admin = User::create([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $partner->id,
            'status' => User::STATUS['active'],
        ]);

        Passport::actingAs($admin, [], 'api');
    }

    private function save(array $payload)
    {
        return $this->postJson("/api/v1/common/borrowers/{$this->borrower->id}/business-financials", $payload);
    }

    /**
     * The amount as stored, as a number: how a decimal column hands its value
     * back ("85000.50" or "85000.5") differs between database drivers, and that
     * is not what these tests are about.
     */
    private function stored(): ?float
    {
        $value = $this->borrower->fresh()->annual_personal_income;

        return $value === null ? null : (float) $value;
    }

    #[Test]
    public function a_new_figure_is_stored_and_handed_back_formatted(): void
    {
        // Sent the way the form holds it: with thousands separators.
        $response = $this->save(['annual_personal_income' => '85,000.50']);

        $response->assertOk();
        $this->assertSame(85000.50, $this->stored());
        $this->assertSame('85,000.50', $response->json('data.annual_personal_income'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function zeroAmounts(): iterable
    {
        yield 'the digit' => ['0'];
        yield 'with decimals' => ['0.00'];
        yield 'as a number' => [0];
    }

    #[Test]
    #[DataProvider('zeroAmounts')]
    public function zero_is_a_real_amount_not_an_empty_field(mixed $zero): void
    {
        $response = $this->save(['annual_personal_income' => $zero]);

        $response->assertOk();
        $this->assertSame(0.0, $this->stored());
        // "0.00", not null: the form shows the zero rather than a blank.
        $this->assertSame('0.00', $response->json('data.annual_personal_income'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptiedValues(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
    }

    #[Test]
    #[DataProvider('emptiedValues')]
    public function emptying_the_field_clears_the_stored_amount(mixed $emptied): void
    {
        $response = $this->save(['annual_personal_income' => $emptied]);

        $response->assertOk();
        $this->assertNull($this->stored());
        $this->assertNull($response->json('data.annual_personal_income'));
    }

    #[Test]
    public function it_is_optional_and_untouched_when_a_save_does_not_mention_it(): void
    {
        // Another figure is saved without this one in the payload at all.
        $response = $this->save(['annual_revenue' => '250,000']);

        $response->assertOk();
        $this->assertSame(1000000.0, $this->stored());
        $this->assertSame('1,000,000.00', $response->json('data.annual_personal_income'));
    }

    #[Test]
    public function the_saved_figure_comes_back_when_the_section_is_loaded_again(): void
    {
        $this->save(['annual_personal_income' => '42,500'])->assertOk();

        $this->getJson("/api/v1/common/borrowers/business-financials/{$this->borrower->id}")
            ->assertOk()
            ->assertJsonPath('data.annual_personal_income', '42,500.00');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'a negative amount' => ['-5'];
        yield 'text' => ['abc'];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function an_invalid_amount_is_refused_and_the_stored_one_kept(mixed $invalid): void
    {
        $this->save(['annual_personal_income' => $invalid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('annual_personal_income');

        $this->assertSame(1000000.0, $this->stored());
    }
}
