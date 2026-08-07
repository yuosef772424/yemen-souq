<?php

namespace App\Modules\Shared\Database\Seeders;

use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'YER',
                'name_ar' => 'ريال يمني',
                'name_en' => 'Yemeni Rial',
                'symbol' => 'ر.ي',
                'decimals' => 0,
                'is_base' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'SAR',
                'name_ar' => 'ريال سعودي',
                'name_en' => 'Saudi Riyal',
                'symbol' => 'ر.س',
                'decimals' => 2,
                'is_base' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'USD',
                'name_ar' => 'دولار أمريكي',
                'name_en' => 'US Dollar',
                'symbol' => '$',
                'decimals' => 2,
                'is_base' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(['code' => $currency['code']], $currency);
        }

        // أسعار ابتدائية للتطوير فقط — تُحدَّث لاحقاً من مصدر حقيقي.
        // القيمة تعني: كم ريالاً يمنياً تساوي وحدة واحدة من هذه العملة.
        $rates = [
            ['currency_code' => 'YER', 'rate_to_base' => 1],
            ['currency_code' => 'SAR', 'rate_to_base' => 140],
            ['currency_code' => 'USD', 'rate_to_base' => 530],
        ];

        foreach ($rates as $rate) {
            ExchangeRate::updateOrCreate(
                [
                    'currency_code' => $rate['currency_code'],
                    'effective_date' => now()->toDateString(),
                ],
                [
                    'rate_to_base' => $rate['rate_to_base'],
                    'source' => 'seed',
                    'note' => 'سعر ابتدائي للتطوير',
                    'created_at' => now(),
                ]
            );
        }
    }
}
