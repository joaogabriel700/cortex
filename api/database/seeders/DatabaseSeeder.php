<?php

namespace Database\Seeders;

use Database\Seeders\Finance\FinanceBaseSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Base data only, as app_runtime. Currencies come from the migrations (checked here);
     * default categories are per user, created at sign up. The demo seeder comes later.
     */
    public function run(): void
    {
        $this->call(FinanceBaseSeeder::class);
    }
}
