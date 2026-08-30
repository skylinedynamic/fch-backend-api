<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BorrowerDocTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Driver’s License',
            'Voided Business Check',
            'EIN / Corporation Docs',
            'Business Bank Statements',
            'Equipment Invoice / Quote',
            'A/R Aging Report',
            'Business Plan',
            'Business Tax Returns',
            'Personal Tax Returns',
            'Miscellaneous Documents',
            'Others',
        ];

        $rows = array_map(function ($name, $index) {
            return [
                'name' => $name,
                'sort_order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $types, array_keys($types));
        // Use upsert to avoid duplicate key issues on repeated seeding
        DB::table('borrower_doc_types')->upsert($rows, ['name']);
    }
}