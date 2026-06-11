<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Lender\Lender;

class LenderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $lenders = [
            [
                'id' => 1,
                'name' => 'Jaden Cook',
                'legal_name' => 'Jakeem Price',
                'product_categories' => json_encode(['LOC']),
                'contact_name' => 'Tamara Long',
                'contact_title' => 'Cum sapiente non qui',
                'contact_email' => 'wekyqutud@mailinator.com',
                'contact_phone' => '(135) 810-9323',
                'website' => 'https://www.nyvuwa.in',
                'submission_method' => 'Portal',
                'submission_email' => 'wirynah@mailinator.com',
                'submission_portal_link' => 'https://www.segedykagih.co.uk',
                'iso_status' => 'Active',
                'status' => 'Active',
                'internal_notes' => null,
                'last_verified_date' => '2026-06-11',
                'created_at' => '2026-06-11 03:09:25',
                'updated_at' => '2026-06-11 03:09:33',
            ],
            [
                'id' => 2,
                'name' => 'Graham Gilmore',
                'legal_name' => 'Mariko Kramer',
                'product_categories' => json_encode(['MCA']),
                'contact_name' => 'Tarik Berry',
                'contact_title' => 'Soluta magna lorem r',
                'contact_email' => 'tolaxyb@mailinator.com',
                'contact_phone' => '(177) 768-8401',
                'website' => 'https://www.vohewisyj.org.au',
                'submission_method' => null,
                'submission_email' => 'xudyfo@mailinator.com',
                'submission_portal_link' => 'https://www.sulezopojyj.co',
                'iso_status' => null,
                'status' => 'Active',
                'internal_notes' => null,
                'last_verified_date' => '2026-06-11',
                'created_at' => '2026-06-11 03:12:13',
                'updated_at' => '2026-06-11 03:13:44',
            ],
            [
                'id' => 3,
                'name' => 'aaaaa',
                'legal_name' => 'David Montoya',
                'product_categories' => json_encode(['MCA', 'Term Loan']),
                'contact_name' => 'Ingrid Duncan',
                'contact_title' => 'Cupidatat cupidatat',
                'contact_email' => 'tucixisyj@mailinator.com',
                'contact_phone' => '(179) 431-9155',
                'website' => 'https://www.jovylybegudyf.com.au',
                'submission_method' => null,
                'submission_email' => 'waludof@mailinator.com',
                'submission_portal_link' => 'https://www.qipanuqopu.net',
                'iso_status' => null,
                'status' => 'Active',
                'internal_notes' => null,
                'last_verified_date' => '2026-06-02',
                'created_at' => '2026-06-11 03:14:59',
                'updated_at' => '2026-06-11 03:15:15',
            ]
        ];

        foreach ($lenders as $lender) {
            Lender::updateOrCreate(['id' => $lender['id']], $lender);
        }

        $this->command->info('✅ Lenders seeded accurately.');
    }
}
