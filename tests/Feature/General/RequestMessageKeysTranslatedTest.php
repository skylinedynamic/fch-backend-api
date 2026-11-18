<?php

namespace Tests\Feature\General;

use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * R3-BUG-018: a validation message whose key is missing from lang/en is
 * answered as the raw key ("contact.mobile_number_invalid"). Every
 * __('file.key') a form request uses must be translated.
 */
class RequestMessageKeysTranslatedTest extends TestCase
{
    #[Test]
    public function every_message_key_a_form_request_uses_is_translated(): void
    {
        $missing = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Requests')));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/__\(\s*'([a-z0-9_]+\.[A-Za-z0-9_.]+)'/", file_get_contents($file->getPathname()), $matches);
            foreach (array_unique($matches[1]) as $key) {
                if (! Lang::has($key, 'en')) {
                    $missing[] = $key . ' (' . str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ')';
                }
            }
        }

        $this->assertSame([], $missing, "Untranslated message keys:\n" . implode("\n", $missing));
    }
}
