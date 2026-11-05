<?php

namespace Database\Seeders;

use App\Models\PropertyCategory;
use Illuminate\Database\Seeder;

class PropertyCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Land',
                'slug' => 'land',
                'description' => 'Residential, commercial and agricultural land.',
            ],
            [
                'name' => 'House',
                'slug' => 'house',
                'description' => 'Residential houses and villas.',
            ],
            [
                'name' => 'Apartment',
                'slug' => 'apartment',
                'description' => 'Residential and furnished apartments.',
            ],
            [
                'name' => 'Commercial Property',
                'slug' => 'commercial-property',
                'description' => 'Offices, shops and warehouses.',
            ],
            [
                'name' => 'Investment Property',
                'slug' => 'investment-property',
                'description' => 'Properties marketed for investment.',
            ],
        ];

        foreach ($categories as $category) {
            PropertyCategory::updateOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }
    }
}
