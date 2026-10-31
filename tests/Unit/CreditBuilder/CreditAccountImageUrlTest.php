<?php

namespace Tests\Unit\CreditBuilder;

use App\Models\CreditBuilder\CreditAccount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defect (BUG-148, CP-CAP-06): a card logo on a Credit Card Stacking play step
 * (Lowe's Commercial Account) did not load. image_url can hold a full logo URL
 * - the admin form takes it as text, and the borrower Credit Accounts page
 * already uses one starting with http as it is - but full_image_url always put
 * the API's storage/ in front of it ("https://api.../storage/https://..."),
 * which never loads. A full URL is now returned as it is; an uploaded file
 * keeps its storage URL.
 */
#[CoversClass(CreditAccount::class)]
class CreditAccountImageUrlTest extends TestCase
{
    #[Test]
    public function an_uploaded_logo_is_served_from_storage(): void
    {
        $account = new CreditAccount(['image_url' => 'credit-accounts/lowes.png']);

        $this->assertSame(asset('storage/credit-accounts/lowes.png'), $account->full_image_url);
    }

    #[Test]
    public function a_logo_given_as_a_full_url_is_used_as_it_is(): void
    {
        $account = new CreditAccount(['image_url' => 'https://cdn.example.test/logos/lowes.png']);

        $this->assertSame('https://cdn.example.test/logos/lowes.png', $account->full_image_url);
    }

    #[Test]
    public function a_card_with_no_logo_has_no_url(): void
    {
        $this->assertNull((new CreditAccount(['image_url' => null]))->full_image_url);
        $this->assertNull((new CreditAccount(['image_url' => '']))->full_image_url);
    }
}
