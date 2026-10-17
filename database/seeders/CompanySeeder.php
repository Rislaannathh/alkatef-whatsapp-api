<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Default Company',
                'phone' => '9876543210',
                'address' => 'India',
                'status' => 'active',
            ]
        );

        $this->command?->info('Default company seeded successfully.');
    }
}
