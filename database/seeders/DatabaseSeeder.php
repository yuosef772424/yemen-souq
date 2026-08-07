<?php

namespace Database\Seeders;

use App\Modules\Shared\Database\Seeders\CategorySeeder;
use App\Modules\Shared\Database\Seeders\CurrencySeeder;
use App\Modules\Shared\Database\Seeders\LocationSeeder;
use Illuminate\Database\Seeder;
use App\Modules\Catalog\Database\Seeders\ListingSeeder;
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            LocationSeeder::class,
            CategorySeeder::class,
            ListingSeeder::class,
        ]);
    }
}
