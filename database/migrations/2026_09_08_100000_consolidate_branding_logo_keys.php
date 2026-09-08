<?php

use App\Services\FCH\Common\Setting\AppConfigurationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Collapse the branding module's ten logo keys down to the five real surfaces.
 *
 * WHY
 * The settings form only ever offered five uploads - sidebar, document, mail,
 * mobile and the favicon - but on save it also wrote five derived mirrors
 * (logo_on_dark, logo_on_light, primary_logo, secondary_logo, logo_mark) for
 * consumers that had not moved off the older names. Half the stored keys were
 * copies of the other half, so no one could tell which key a surface actually
 * read, and a partial upload could leave two keys disagreeing.
 *
 * BACKFILL FIRST
 * Only 2 of 12 partners had ever been saved through the new form; the other 10
 * have their logos ONLY under the legacy names. Dropping those rows without
 * backfilling would erase ten partners' branding. The mapping below is the exact
 * inverse of what the form wrote, and matches the chain the form already uses
 * when it loads existing values.
 *
 * The `website` module keeps its own primary_logo/secondary_logo/favicon/
 * header_logo/footer_logo/website_favicon - same names, different module, the
 * public marketing site's CMS. Nothing here touches it.
 */
return new class extends Migration
{
    private const MODULE = 'branding';

    /** surface key => legacy keys to inherit from, in priority order */
    private const BACKFILL = [
        'logo_sidebar'  => ['logo_on_dark', 'primary_logo'],
        'logo_document' => ['logo_on_light', 'secondary_logo', 'primary_logo'],
        'logo_mail'     => ['logo_on_dark', 'primary_logo'],
        'logo_mobile'   => ['logo_on_light', 'secondary_logo', 'primary_logo'],
        'favicon'       => ['logo_mark'],
    ];

    private const DROP = [
        'logo_on_dark',
        'logo_on_light',
        'primary_logo',
        'secondary_logo',
        'logo_mark',
    ];

    public function up(): void
    {
        $rows = DB::table('app_configurations')->where('module', self::MODULE)->get();

        // partner_id => [key => value]; null partner_id is its own bucket.
        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[$row->partner_id === null ? 'null' : $row->partner_id][$row->key] = $row->value;
        }

        foreach ($byPartner as $bucket => $values) {
            $partnerId = $bucket === 'null' ? null : (int) $bucket;

            foreach (self::BACKFILL as $target => $sources) {
                if ($this->filled($values[$target] ?? null)) {
                    continue; // Already set through the new form - never overwrite.
                }

                foreach ($sources as $source) {
                    if (!$this->filled($values[$source] ?? null)) {
                        continue;
                    }

                    DB::table('app_configurations')->updateOrInsert(
                        ['key' => $target, 'module' => self::MODULE, 'partner_id' => $partnerId],
                        ['value' => $values[$source], 'updated_at' => now(), 'created_at' => now()]
                    );
                    break;
                }
            }
        }

        DB::table('app_configurations')
            ->where('module', self::MODULE)
            ->whereIn('key', self::DROP)
            ->delete();

        AppConfigurationService::bustBrandingCache();
    }

    public function down(): void
    {
        // Re-derive the mirrors from the surface keys they were copies of. The
        // values are recoverable because every mirror was, by construction, a
        // duplicate - nothing unique to them was ever stored.
        $rows = DB::table('app_configurations')->where('module', self::MODULE)->get();

        $byPartner = [];
        foreach ($rows as $row) {
            $byPartner[$row->partner_id === null ? 'null' : $row->partner_id][$row->key] = $row->value;
        }

        $mirrors = [
            'logo_on_dark'   => ['logo_sidebar', 'logo_mail'],
            'logo_on_light'  => ['logo_document', 'logo_mobile'],
            'primary_logo'   => ['logo_sidebar', 'logo_mail'],
            'secondary_logo' => ['logo_document', 'logo_mobile'],
            'logo_mark'      => ['favicon'],
        ];

        foreach ($byPartner as $bucket => $values) {
            $partnerId = $bucket === 'null' ? null : (int) $bucket;

            foreach ($mirrors as $target => $sources) {
                foreach ($sources as $source) {
                    if (!$this->filled($values[$source] ?? null)) {
                        continue;
                    }

                    DB::table('app_configurations')->updateOrInsert(
                        ['key' => $target, 'module' => self::MODULE, 'partner_id' => $partnerId],
                        ['value' => $values[$source], 'updated_at' => now(), 'created_at' => now()]
                    );
                    break;
                }
            }
        }

        AppConfigurationService::bustBrandingCache();
    }

    private function filled($value): bool
    {
        return $value !== null && $value !== '' && $value !== 'null';
    }
};
