<?php

namespace Database\Factories;

use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<TwoFaAuthSetting>
 */
class TwoFaAuthSettingFactory extends Factory
{
    protected $model = TwoFaAuthSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'enabled' => false,
            'method' => null,
            'secret' => null,
            'confirmed_at' => null,
            'recovery_codes' => null,
            'attempts' => 0,
            'locked_until' => null,
        ];
    }

    public function totpEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
            'method' => TwoFaAuthSetting::TOTP,
            // Matches TwoFaService's own encryption: Crypt::encryptString() (raw
            // string), not the encrypt() helper (which adds serialization).
            'secret' => Crypt::encryptString('SECRETSECRETSECRETSECRET1234'),
            'confirmed_at' => now(),
            'recovery_codes' => Crypt::encryptString(json_encode(['ABCD1234EF', 'GHIJ5678KL'])),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
            'attempts' => 5,
            'locked_until' => now()->addMinutes(5),
        ]);
    }
}
