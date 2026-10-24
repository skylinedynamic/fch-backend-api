<?php

namespace Tests\Unit\Partner;

use App\Services\FCH\Partner\PartnerConfigurationChangeRecorder as Recorder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PartnerConfigurationChangeRecorder::diff() - the field => {label, from, to}
 * map behind the activity sidebar's "Show changes" table.
 */
class PartnerConfigurationChangeDiffTest extends TestCase
{
    #[Test]
    public function only_the_fields_that_changed_are_listed(): void
    {
        $changes = Recorder::diff(
            ['country' => 'USA', 'from_number' => '+15005550006'],
            ['country' => 'USA', 'from_number' => '+15005550007'],
        );

        $this->assertSame(['from_number' => ['label' => 'From number', 'from' => '+15005550006', 'to' => '+15005550007']], $changes);
    }

    #[Test]
    public function nothing_changed_gives_an_empty_map(): void
    {
        $this->assertSame([], Recorder::diff(['a' => 1, 'b' => true], ['a' => '1', 'b' => true]));
    }

    #[Test]
    public function empty_values_are_null_and_empty_string_equals_null(): void
    {
        $this->assertSame([], Recorder::diff(['site_name' => ''], ['site_name' => null]));
        $this->assertSame(
            ['site_name' => ['label' => 'Site name', 'from' => null, 'to' => 'Acme']],
            Recorder::diff(['site_name' => ''], ['site_name' => 'Acme'])
        );
        $this->assertSame(
            ['site_name' => ['label' => 'Site name', 'from' => 'Acme', 'to' => null]],
            Recorder::diff(['site_name' => 'Acme'], [])
        );
    }

    #[Test]
    public function booleans_read_yes_and_no(): void
    {
        $this->assertSame(
            ['is_enabled' => ['label' => 'Is enabled', 'from' => 'No', 'to' => 'Yes']],
            Recorder::diff(['is_enabled' => false], ['is_enabled' => true])
        );
    }

    #[Test]
    public function labels_keep_acronyms_and_can_be_given(): void
    {
        $changes = Recorder::diff([], ['smtp_host' => 'smtp.acme.test', 'is_enabled' => true], ['is_enabled' => 'Enabled']);

        $this->assertSame('SMTP host', $changes['smtp_host']['label']);
        $this->assertSame('Enabled', $changes['is_enabled']['label']);
    }

    #[Test]
    public function a_credential_is_only_reported_as_changed(): void
    {
        $changes = Recorder::diff(
            ['smtp_password' => 'old', 'auth_token' => 'old', 'api_key' => 'old', 'account_sid' => 'AC1', 'client_secret' => 'old'],
            ['smtp_password' => 'new', 'auth_token' => 'new', 'api_key' => 'new', 'account_sid' => 'AC2', 'client_secret' => 'new'],
        );

        foreach (['smtp_password', 'auth_token', 'api_key', 'account_sid', 'client_secret'] as $field) {
            $this->assertNull($changes[$field]['from'], $field);
            $this->assertSame('Changed', $changes[$field]['to'], $field);
        }
        $this->assertStringNotContainsString('new', json_encode(array_column($changes, 'to')));
    }

    #[Test]
    public function fields_named_as_secrets_are_hidden_too(): void
    {
        $changes = Recorder::diff(['bank_account' => '111'], ['bank_account' => '222'], [], ['bank_account']);

        $this->assertSame(['label' => 'Bank account', 'from' => null, 'to' => 'Changed'], $changes['bank_account']);
    }

    #[Test]
    public function a_word_that_only_contains_a_secret_word_is_not_hidden(): void
    {
        // "keyword" and "monkey" are not "key".
        $changes = Recorder::diff([], ['meta_keywords' => 'loans']);

        $this->assertSame('loans', $changes['meta_keywords']['to']);
    }

    #[Test]
    public function long_values_are_cut_short_and_arrays_written_as_json(): void
    {
        $changes = Recorder::diff([], ['footer_text' => str_repeat('a', 200), 'colors' => ['#fff', '#000']]);

        $this->assertLessThanOrEqual(83, mb_strlen($changes['footer_text']['to']));
        $this->assertSame('["#fff","#000"]', $changes['colors']['to']);
    }
}
