<?php

namespace Database\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeletePartnersAndBorrowersSeeder extends Seeder
{
    /**
     * Names of partners to delete.
     * Matches case-insensitive against: full_name or company_name.
     *
     * @var array<string>
     */
    protected array $partnerNames = [
        'Fisher and Pratt Co',
        'Byers and Marks Associates',
        'Good and Richmond Traders',
        'Mcmahon and Harrington Trading',
        'Jenkins and Mejia Associates',
        'Ortiz Whitaker Associates',
        'Brock Burt Co',
        'Giles Ellis Associates',
        'Cannon and Gay Associates',
        'The partner',
        'Tasty Treat',
        'Twistee Treat',
        'Exodiass',
        'teast, inc',
        'Hardin and Holloway Associates',
        'Bullock Ward Associates',
        'Dean and Roberson Plc',
        'Thornton Slater Traders',
        'Nguyen Romero Co',
        'Testing Co Name',
        'Exodias',
        'Arafat Group Limited',
        'The fake one, llc',
        'Fake Partners Co.',
    ];

    /**
     * Names of borrowers to delete.
     * Matches case-insensitive against: CONCAT(first_name, ' ', last_name) or business_name.
     *
     * @var array<string>
     */
    protected array $borrowerNames = [
        'Kirsten Carter',
        'Lunea Conner',
        'Amy Davenport',
        'WL Client Client',
        'The lead',
        'The test',
        'Nicole Haas',
        'Yvette Mcgee',
        'Philip - NOT ACTIVE Berenbaum NOT ACTIVE',
        'Jimmy Salisbury',
        'JJ Bomb Test',
        'Jason Bomb',
        'Demo Client 100',
        'Awlad Hossain',
        'Testing Fake',
        'Dave the Fake',
        'Test Partner Client',
        'John Doe',
        'Sandy Fake',
        'George Fake',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partnerNames = array_values(array_unique(array_filter(array_map('trim', $this->partnerNames))));
        $borrowerNames = array_values(array_unique(array_filter(array_map('trim', $this->borrowerNames))));

        if (empty($partnerNames) && empty($borrowerNames)) {
            $this->command?->warn('⚠️ Both $partnerNames and $borrowerNames arrays are empty. Please add names to the arrays and re-run.');
            return;
        }

        $this->command?->info('🔍 Resolving Partner and Borrower IDs...');

        $resolutionRows = [];
        $notFoundCount = 0;

        // 1. Resolve Partner records (Listed names + all soft-deleted partners)
        $partnerIds = [];
        $partnerNamesLower = array_map('mb_strtolower', $partnerNames);
        
        $foundPartners = DB::table('partners')
            ->select('id', 'full_name', 'company_name', 'deleted_at')
            ->where(function ($query) use ($partnerNamesLower) {
                if (!empty($partnerNamesLower)) {
                    $query->whereIn(DB::raw('LOWER(TRIM(full_name))'), $partnerNamesLower)
                        ->orWhereIn(DB::raw('LOWER(TRIM(company_name))'), $partnerNamesLower);
                }
            })
            ->get();

        // Also fetch all soft-deleted partners (deleted_at IS NOT NULL)
        $softDeletedPartners = DB::table('partners')
            ->select('id', 'full_name', 'company_name', 'deleted_at')
            ->whereNotNull('deleted_at')
            ->get();

        // Group found by lowercase full_name and company_name
        $foundPartnerMap = [];
        foreach ($foundPartners as $p) {
            $lowerFull = mb_strtolower(trim($p->full_name ?? ''));
            $lowerComp = mb_strtolower(trim($p->company_name ?? ''));
            if ($lowerFull) {
                $foundPartnerMap[$lowerFull][] = $p;
            }
            if ($lowerComp && $lowerComp !== $lowerFull) {
                $foundPartnerMap[$lowerComp][] = $p;
            }
            $partnerIds[] = $p->id;
        }

        // Add soft-deleted partner IDs
        foreach ($softDeletedPartners as $sp) {
            $partnerIds[] = $sp->id;
        }
        $partnerIds = array_values(array_unique($partnerIds));

        foreach ($partnerNames as $name) {
            $lower = mb_strtolower($name);
            if (!empty($foundPartnerMap[$lower])) {
                $matched = $foundPartnerMap[$lower];
                $idsStr = implode(', ', array_map(fn($m) => "ID: {$m->id}" . ($m->deleted_at ? ' [Soft-Deleted]' : ''), $matched));
                $namesStr = implode(', ', array_map(fn($m) => $m->full_name ?: $m->company_name, $matched));
                $resolutionRows[] = ['Partner', $name, "✅ Found ({$idsStr})", $namesStr];
            } else {
                $resolutionRows[] = ['Partner', $name, '❌ NOT FOUND', '-'];
                $notFoundCount++;
            }
        }

        // Add any additional soft-deleted partners to table preview if not already in input
        $listedPartnerIds = array_map(fn($p) => $p->id, $foundPartners->all());
        foreach ($softDeletedPartners as $sp) {
            if (!in_array($sp->id, $listedPartnerIds)) {
                $pName = $sp->full_name ?: ($sp->company_name ?: "Partner #{$sp->id}");
                $resolutionRows[] = ['Partner (Soft-Deleted)', $pName, "✅ Found (ID: {$sp->id} [Soft-Deleted])", $pName];
            }
        }

        // 2. Resolve Borrower records (Listed names + all soft-deleted borrowers)
        $borrowerIds = [];
        $borrowerNamesLower = array_map('mb_strtolower', $borrowerNames);

        $foundBorrowers = DB::table('borrowers')
            ->select('id', 'first_name', 'last_name', 'business_name', 'deleted_at', DB::raw("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->where(function ($query) use ($borrowerNamesLower) {
                if (!empty($borrowerNamesLower)) {
                    $query->whereIn(DB::raw("LOWER(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))))"), $borrowerNamesLower)
                        ->orWhereIn(DB::raw("LOWER(TRIM(business_name))"), $borrowerNamesLower);
                }
            })
            ->get();

        // Also fetch all soft-deleted borrowers (deleted_at IS NOT NULL)
        $softDeletedBorrowers = DB::table('borrowers')
            ->select('id', 'first_name', 'last_name', 'business_name', 'deleted_at', DB::raw("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) as full_name"))
            ->whereNotNull('deleted_at')
            ->get();

        // Group found by lowercase full_name and business_name
        $foundBorrowerMap = [];
        foreach ($foundBorrowers as $b) {
            $lowerFull = mb_strtolower(trim($b->full_name ?? ''));
            $lowerBiz = mb_strtolower(trim($b->business_name ?? ''));
            if ($lowerFull) {
                $foundBorrowerMap[$lowerFull][] = $b;
            }
            if ($lowerBiz && $lowerBiz !== $lowerFull) {
                $foundBorrowerMap[$lowerBiz][] = $b;
            }
            $borrowerIds[] = $b->id;
        }

        // Add soft-deleted borrower IDs
        foreach ($softDeletedBorrowers as $sb) {
            $borrowerIds[] = $sb->id;
        }
        $borrowerIds = array_values(array_unique($borrowerIds));

        foreach ($borrowerNames as $name) {
            $lower = mb_strtolower($name);
            if (!empty($foundBorrowerMap[$lower])) {
                $matched = $foundBorrowerMap[$lower];
                $idsStr = implode(', ', array_map(fn($m) => "ID: {$m->id}" . ($m->deleted_at ? ' [Soft-Deleted]' : ''), $matched));
                $namesStr = implode(', ', array_map(fn($m) => $m->full_name ?: $m->business_name, $matched));
                $resolutionRows[] = ['Borrower', $name, "✅ Found ({$idsStr})", $namesStr];
            } else {
                $resolutionRows[] = ['Borrower', $name, '❌ NOT FOUND', '-'];
                $notFoundCount++;
            }
        }

        // Add any additional soft-deleted borrowers to table preview if not already in input
        $listedBorrowerIds = array_map(fn($b) => $b->id, $foundBorrowers->all());
        foreach ($softDeletedBorrowers as $sb) {
            if (!in_array($sb->id, $listedBorrowerIds)) {
                $bName = $sb->full_name ?: ($sb->business_name ?: "Borrower #{$sb->id}");
                $resolutionRows[] = ['Borrower (Soft-Deleted)', $bName, "✅ Found (ID: {$sb->id} [Soft-Deleted])", $bName];
            }
        }

        // Display results table
        if (!empty($resolutionRows)) {
            $this->command?->newLine();
            $this->command?->table(['Type', 'Input / Target Name', 'Status', 'DB Record Name'], $resolutionRows);
            $this->command?->newLine();
        }

        if (empty($partnerIds) && empty($borrowerIds)) {
            $this->command?->error('❌ No matching partner or borrower records found in the database. Aborting.');
            return;
        }

        if ($notFoundCount > 0) {
            $this->command?->warn("⚠️  {$notFoundCount} listed name(s) were NOT found in the database.");
        }

        $totalSoftDeleted = count($softDeletedPartners) + count($softDeletedBorrowers);
        if ($totalSoftDeleted > 0) {
            $this->command?->info("ℹ️  Included {$totalSoftDeleted} existing soft-deleted record(s) (" . count($softDeletedPartners) . " partners, " . count($softDeletedBorrowers) . " borrowers) for full permanent purge.");
        }

        $totalTargets = count($partnerIds) + count($borrowerIds);
        $this->command?->info("Summary: Found {$totalTargets} total target record(s) to delete (" . count($partnerIds) . " partners, " . count($borrowerIds) . " borrowers).");

        // Ask for confirmation before proceeding
        if ($this->command) {
            $confirmed = $this->command->confirm(
                '⚠️  Are you sure you want to permanently delete all found records and their related data from ALL database tables?',
                false
            );

            if (!$confirmed) {
                $this->command->warn('🛑 Operation aborted by user. No data was deleted.');
                return;
            }
        }

        // Polymorphic class variants
        $partnerEntityTypes = array_unique([
            Partner::class,
            'App\Models\Partner\Partner',
            'Partner',
            'partner',
        ]);

        $borrowerEntityTypes = array_unique([
            Borrower::class,
            'App\Models\Borrower\Borrower',
            'Borrower',
            'borrower',
        ]);

        $userEntityTypes = array_unique([
            \App\Models\User\User::class,
            'App\Models\User\User',
            'User',
            'user',
        ]);

        // Find all User IDs associated with these partners and borrowers
        $userIds = DB::table('users')
            ->where(function ($query) use ($partnerIds, $borrowerIds, $partnerEntityTypes, $borrowerEntityTypes) {
                if (!empty($partnerIds)) {
                    $query->whereIn('partner_id', $partnerIds)
                        ->orWhere(function ($q) use ($partnerIds, $partnerEntityTypes) {
                            $q->whereIn('entity_type', $partnerEntityTypes)->whereIn('entity_id', $partnerIds);
                        });
                }
                if (!empty($borrowerIds)) {
                    $query->orWhere(function ($q) use ($borrowerIds, $borrowerEntityTypes) {
                        $q->whereIn('entity_type', $borrowerEntityTypes)->whereIn('entity_id', $borrowerIds);
                    });
                }
            })
            ->pluck('id')
            ->unique()
            ->toArray();

        // Get all tables in database
        $tables = $this->getAllTableNames();

        $this->command?->info("🚀 Scanning " . count($tables) . " tables for deletion...");

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::beginTransaction();

        try {
            $totalDeletedCounts = [];

            foreach ($tables as $table) {
                if (!Schema::hasTable($table)) {
                    continue;
                }

                $columns = Schema::getColumnListing($table);
                $deletedInTable = 0;

                // --- PARTNER CHECKS ---
                if (!empty($partnerIds)) {
                    // Check partner_id
                    if (in_array('partner_id', $columns) && $table !== 'partners' && $table !== 'users') {
                        $count = DB::table($table)->whereIn('partner_id', $partnerIds)->delete();
                        $deletedInTable += $count;
                    }

                    // Check entity_type and entity_id
                    if (in_array('entity_type', $columns) && in_array('entity_id', $columns) && $table !== 'users') {
                        $count = DB::table($table)
                            ->whereIn('entity_type', $partnerEntityTypes)
                            ->whereIn('entity_id', $partnerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    // Check model_type and model_id (e.g. Spatie permissions / roles)
                    if (in_array('model_type', $columns) && in_array('model_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('model_type', $partnerEntityTypes)
                            ->whereIn('model_id', $partnerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    // Check causer / subject (e.g. activity log)
                    if (in_array('causer_type', $columns) && in_array('causer_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('causer_type', $partnerEntityTypes)
                            ->whereIn('causer_id', $partnerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    if (in_array('subject_type', $columns) && in_array('subject_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('subject_type', $partnerEntityTypes)
                            ->whereIn('subject_id', $partnerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }
                }

                // --- BORROWER CHECKS ---
                if (!empty($borrowerIds)) {
                    // Check borrower_id
                    if (in_array('borrower_id', $columns) && $table !== 'borrowers') {
                        $count = DB::table($table)->whereIn('borrower_id', $borrowerIds)->delete();
                        $deletedInTable += $count;
                    }

                    // Check entity_type and entity_id
                    if (in_array('entity_type', $columns) && in_array('entity_id', $columns) && $table !== 'users') {
                        $count = DB::table($table)
                            ->whereIn('entity_type', $borrowerEntityTypes)
                            ->whereIn('entity_id', $borrowerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    // Check model_type and model_id
                    if (in_array('model_type', $columns) && in_array('model_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('model_type', $borrowerEntityTypes)
                            ->whereIn('model_id', $borrowerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    // Check causer / subject
                    if (in_array('causer_type', $columns) && in_array('causer_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('causer_type', $borrowerEntityTypes)
                            ->whereIn('causer_id', $borrowerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    if (in_array('subject_type', $columns) && in_array('subject_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('subject_type', $borrowerEntityTypes)
                            ->whereIn('subject_id', $borrowerIds)
                            ->delete();
                        $deletedInTable += $count;
                    }
                }

                // --- ASSOCIATED USERS CHECKS ---
                if (!empty($userIds)) {
                    // Check user_id in related tables (auth tokens, activities, permissions, etc.)
                    if (in_array('user_id', $columns) && $table !== 'users') {
                        $count = DB::table($table)->whereIn('user_id', $userIds)->delete();
                        $deletedInTable += $count;
                    }

                    if (in_array('model_type', $columns) && in_array('model_id', $columns)) {
                        $count = DB::table($table)
                            ->whereIn('model_type', $userEntityTypes)
                            ->whereIn('model_id', $userIds)
                            ->delete();
                        $deletedInTable += $count;
                    }

                    if (in_array('entity_type', $columns) && in_array('entity_id', $columns) && $table !== 'users') {
                        $count = DB::table($table)
                            ->whereIn('entity_type', $userEntityTypes)
                            ->whereIn('entity_id', $userIds)
                            ->delete();
                        $deletedInTable += $count;
                    }
                }

                if ($deletedInTable > 0) {
                    $totalDeletedCounts[$table] = ($totalDeletedCounts[$table] ?? 0) + $deletedInTable;
                }
            }

            // Finally, delete main records from users, borrowers and partners tables
            if (!empty($userIds) && Schema::hasTable('users')) {
                $count = DB::table('users')->whereIn('id', $userIds)->delete();
                if ($count > 0) {
                    $totalDeletedCounts['users (main)'] = $count;
                }
            }

            if (!empty($borrowerIds) && Schema::hasTable('borrowers')) {
                $count = DB::table('borrowers')->whereIn('id', $borrowerIds)->delete();
                if ($count > 0) {
                    $totalDeletedCounts['borrowers (main)'] = $count;
                }
            }

            if (!empty($partnerIds) && Schema::hasTable('partners')) {
                $count = DB::table('partners')->whereIn('id', $partnerIds)->delete();
                if ($count > 0) {
                    $totalDeletedCounts['partners (main)'] = $count;
                }
            }

            DB::commit();

            $this->command?->info("✅ Cleanup completed successfully!");
            if (!empty($totalDeletedCounts)) {
                $this->command?->table(['Table', 'Deleted Records'], array_map(
                    fn($table, $count) => [$table, $count],
                    array_keys($totalDeletedCounts),
                    array_values($totalDeletedCounts)
                ));
            } else {
                $this->command?->info("No records needed deletion across tables.");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->command?->error("❌ An error occurred during deletion: " . $e->getMessage());
            throw $e;
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * Retrieve all table names in the current database.
     *
     * @return array<string>
     */
    protected function getAllTableNames(): array
    {
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $keyName = "Tables_in_{$dbName}";

        return array_map(function ($table) use ($keyName) {
            $tableArray = (array) $table;
            return $tableArray[$keyName] ?? reset($tableArray);
        }, $tables);
    }
}
