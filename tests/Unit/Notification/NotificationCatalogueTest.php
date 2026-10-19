<?php

namespace Tests\Unit\Notification;

use App\Services\FCH\Common\Notification\Notifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * config/notifications.php is the catalogue of everything the platform tells
 * anyone, and Notifier the only way out. These keep both true as code changes.
 *
 * No database: reads config and source.
 */
class NotificationCatalogueTest extends TestCase
{
    /** The categories the portals' bells colour (config/notifications.ts in both apps). */
    private const CATEGORIES = ['funding', 'document', 'payment', 'reminder', 'system'];

    /** @return array<string, string> path => source with comments stripped */
    private static function appSources(): array
    {
        $root = dirname(__DIR__, 3) . '/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $sources = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            $sources[substr($file->getPathname(), strlen($root) + 1)] = $code;
        }

        return $sources;
    }

    public static function types(): array
    {
        $types = require dirname(__DIR__, 3) . '/config/notifications.php';

        return array_map(fn ($definition) => [$definition], $types['types']);
    }

    #[Test]
    public function no_code_sends_mail_except_through_the_notifier(): void
    {
        $offenders = [];

        foreach (self::appSources() as $path => $code) {
            if (str_ends_with(str_replace('\\', '/', $path), 'Services/FCH/Common/Notification/Notifier.php')) {
                continue;
            }

            if (preg_match('/\bMail::(to|cc|bcc|send|queue|later|raw|html|plain)\s*\(/', $code)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Send mail through App\Services\FCH\Common\Notification\Notifier');
    }

    #[Test]
    public function every_type_the_code_sends_is_in_the_catalogue(): void
    {
        $catalogue = config('notifications.types');
        $used = [];

        foreach (self::appSources() as $path => $code) {
            if (!str_contains($code, 'Notifier')) {
                continue;
            }

            preg_match_all("/->send\(\s*'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/", $code, $matches);

            foreach ($matches[1] as $key) {
                $used[$key][] = $path;
            }
        }

        $this->assertNotEmpty($used, 'the scan found no Notifier calls at all');
        $this->assertSame([], array_diff_key($used, $catalogue), 'Unknown notification types (add them to config/notifications.php)');
    }

    /**
     * SMS is opt-in per type, not part of the bell-and-mail set: every type
     * used to text as well, so turning on a partner's Twilio account would
     * have texted users about everything. A type texts only once it is chosen
     * to - add it here when it is, deliberately.
     */
    private const TEXTED_TYPES = [
        // The white-label partner, through its own Twilio account only.
        'partner.embed_form.submitted_white_label',
    ];

    #[Test]
    public function only_the_chosen_types_send_an_sms(): void
    {
        $texted = array_keys(array_filter(
            config('notifications.types'),
            fn (array $type) => in_array('sms', $type['channels'] ?? [], true)
        ));

        $this->assertSame(self::TEXTED_TYPES, $texted);
    }

    #[Test]
    #[DataProvider('types')]
    public function each_type_is_well_formed(array $type): void
    {
        $known = array_merge(array_keys(config('notifications.channels')), [Notifier::CHANNEL_MAIL]);

        $this->assertNotEmpty($type['name'] ?? null);
        $this->assertContains($type['category'] ?? null, self::CATEGORIES);
        $this->assertNotEmpty($type['channels'] ?? []);
        $this->assertSame([], array_diff($type['channels'], $known), 'unknown channel');

        if (array_diff($type['channels'], [Notifier::CHANNEL_MAIL]) !== []) {
            // Shown in a bell, so it needs words and, if it links, a portal path.
            $this->assertNotSame('', trim($type['title'] ?? ''));
            $this->assertNotSame('', trim($type['body'] ?? ''));
            $url = $type['url'] ?? null;
            $this->assertTrue($url === null || (str_starts_with($url, '/') && !str_starts_with($url, '//')), "bad url {$url}");
        }
    }
}
