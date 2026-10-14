<?php

namespace Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every Take Me There destination must be a well-formed same-origin path, and
 * - when the portals' source is checked out beside this repo - a route that
 * portal actually has. A path that leads nowhere would show a button that
 * lands on the 404 page.
 */
class NavigationMapTest extends TestCase
{
    private const PORTALS = [
        'wlp_support' => '../Fch-admin-frontend-react/src/App.jsx',
        'borrower_support' => '../fch-borrower-react/src/App.jsx',
    ];

    /** @return array<string, array{string, string, string, string}> */
    public static function destinations(): array
    {
        $map = require __DIR__ . '/../../../config/assistant.php';
        $rows = [];

        foreach ($map['navigation'] as $assistant => $entries) {
            foreach ($entries as $key => $entry) {
                $rows["$assistant:$key"] = [$assistant, $key, $entry['label'], $entry['path']];
            }
        }

        return $rows;
    }

    #[Test]
    #[DataProvider('destinations')]
    public function every_destination_is_a_labelled_same_origin_path(string $assistant, string $key, string $label, string $path): void
    {
        $this->assertNotSame('', trim($label));
        $this->assertMatchesRegularExpression('#^/[A-Za-z0-9/_-]*$#', $path);
        $this->assertStringNotContainsString('//', $path);
    }

    #[Test]
    #[DataProvider('destinations')]
    public function every_destination_is_a_route_the_portal_has(string $assistant, string $key, string $label, string $path): void
    {
        $router = base_path(self::PORTALS[$assistant]);

        if (! is_file($router)) {
            $this->markTestSkipped("Portal source not found at {$router}");
        }

        $source = file_get_contents($router);
        $last = basename($path);

        $found = $path === '/'
            ? str_contains($source, '<Route index')
            : (bool) preg_match('#path="(?:[^"]*/)?' . preg_quote($last, '#') . '"#', $source);

        $this->assertTrue($found, "{$assistant}:{$key} -> {$path} has no matching route in {$router}");
    }
}
