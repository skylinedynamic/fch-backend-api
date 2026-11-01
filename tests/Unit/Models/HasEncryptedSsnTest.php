<?php

namespace Tests\Unit\Models;

use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HasEncryptedSsn's ssn mutator, shared by borrowers and leads.
 *
 * Defect: the client and lead forms send the SSN back on every save, and
 * encrypting the same number again gives a new ciphertext - so the model read
 * as dirty and the activity log recorded an SSN change nobody made.
 */
class HasEncryptedSsnTest extends TestCase
{
    public static function models(): array
    {
        return [
            'borrower' => [Borrower::class],
            'lead' => [Lead::class],
        ];
    }

    #[Test]
    #[DataProvider('models')]
    public function resending_the_same_ssn_keeps_the_stored_ciphertext(string $class): void
    {
        /** @var Model $model */
        $model = new $class();
        $model->ssn = '123-45-6789';
        $stored = $model->ssn_encrypted;

        $model->ssn = '123456789';

        $this->assertSame($stored, $model->ssn_encrypted);
    }

    #[Test]
    #[DataProvider('models')]
    public function a_different_ssn_or_a_blank_one_is_written(string $class): void
    {
        /** @var Model $model */
        $model = new $class();
        $model->ssn = '123-45-6789';

        $model->ssn = '987-65-4321';
        $this->assertSame('987654321', $model->ssn);

        $model->ssn = '';
        $this->assertNull($model->ssn_encrypted);
    }
}
