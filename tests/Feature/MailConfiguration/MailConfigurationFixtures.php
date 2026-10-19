<?php

namespace Tests\Feature\MailConfiguration;

use App\Models\Common\Permission;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Setting\MailConfiguration;
use App\Models\User\User;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

/**
 * Partners, their users and mail settings, for the mail configuration tests.
 */
trait MailConfigurationFixtures
{
    protected const PERMISSION = 'update email configuration settings';

    protected Partner $host;
    protected Partner $whiteLabel;

    protected function setUpPartners(): void
    {
        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Host Owner',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);

        $this->whiteLabel = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Acme Capital',
            'full_name' => 'Acme Owner',
            'email' => 'acme-' . uniqid() . '@example.test',
        ]);

        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'api'], ['module' => 'settings']);
    }

    /**
     * The partner account itself (entity_type Partner), with the settings
     * permission unless told otherwise.
     */
    protected function owner(Partner $partner, bool $withPermission = true): User
    {
        $user = User::create([
            'name' => $partner->full_name,
            'email' => 'owner-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::PARTNER,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'partner_id' => $partner->id,
        ]);

        if ($withPermission) {
            $user->givePermissionTo(self::PERMISSION);
        }

        return $user;
    }

    /**
     * One of the partner's employees, with the settings permission.
     */
    protected function employee(Partner $partner): User
    {
        $user = User::create([
            'name' => 'Staff Member',
            'email' => 'staff-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'status' => true,
            'user_type' => User::EMPLOYEE,
            'entity_type' => Employee::class,
            'entity_id' => 999,
            'partner_id' => $partner->id,
        ]);
        $user->givePermissionTo(self::PERMISSION);

        return $user;
    }

    protected function actingAsUser(User $user): User
    {
        Passport::actingAs($user, [], 'api');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function smtpConfig(?Partner $partner, array $overrides = []): MailConfiguration
    {
        return MailConfiguration::create(array_merge([
            'partner_id' => $partner?->id,
            'provider' => MailConfiguration::PROVIDER_SMTP,
            'from_address' => 'mail@' . ($partner ? 'partner' . $partner->id : 'fallback') . '.test',
            'credentials' => [
                'smtp_host' => 'smtp.' . ($partner ? 'partner' . $partner->id : 'fallback') . '.test',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_username' => 'mailer',
                'smtp_password' => 'secret',
            ],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function sesConfig(?Partner $partner, array $overrides = []): MailConfiguration
    {
        return MailConfiguration::create(array_merge([
            'partner_id' => $partner?->id,
            'provider' => MailConfiguration::PROVIDER_SES,
            'from_address' => 'ses@' . ($partner ? 'partner' . $partner->id : 'fallback') . '.test',
            'credentials' => [
                'aws_access_key' => 'AKIA' . ($partner?->id ?? 'FALLBACK'),
                'aws_secret_key' => 'ses-secret',
                'aws_region' => 'us-east-1',
            ],
        ], $overrides));
    }

    protected function markVerified(MailConfiguration $config): MailConfiguration
    {
        $config->forceFill([
            'verified_at' => Carbon::now(),
            'verified_fingerprint' => $config->fingerprint(),
        ])->save();

        return $config->refresh();
    }
}
