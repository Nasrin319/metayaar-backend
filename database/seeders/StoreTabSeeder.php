<?php

namespace Database\Seeders;

use App\Enums\StoreTabRule;
use App\Models\StoreTab;
use Illuminate\Database\Seeder;

class StoreTabSeeder extends Seeder
{
    /**
     * The default tabs of the apps page. Tabs that already exist (by slug) are left as the admin set them.
     */
    public function run(): void
    {
        $tabs = [
            ['label' => 'همه', 'slug' => 'all', 'rule' => StoreTabRule::All, 'group' => 1],
            ['label' => 'رایگان', 'slug' => 'free', 'rule' => StoreTabRule::Free, 'group' => 2],
            ['label' => 'به‌زودی', 'slug' => 'early-access', 'rule' => StoreTabRule::EarlyAccess, 'group' => 2],
            ['label' => 'اکشن', 'rule' => StoreTabRule::Category, 'value' => 'action', 'group' => 3],
            ['label' => 'ماجراجویی', 'rule' => StoreTabRule::Category, 'value' => 'adventure', 'group' => 3],
            ['label' => 'پازل', 'rule' => StoreTabRule::Category, 'value' => 'puzzle', 'group' => 3],
            ['label' => 'شبیه‌سازی', 'rule' => StoreTabRule::Category, 'value' => 'simulation', 'group' => 3],
            ['label' => 'ورزشی و تناسب اندام', 'rule' => StoreTabRule::Category, 'value' => 'sports', 'group' => 3],
            ['label' => 'آموزشی', 'rule' => StoreTabRule::Category, 'value' => 'education', 'group' => 3],
            ['label' => 'اجتماعی', 'rule' => StoreTabRule::Category, 'value' => 'social', 'group' => 3],
            ['label' => 'ابزار', 'rule' => StoreTabRule::Category, 'value' => 'tools', 'group' => 3],
        ];

        foreach ($tabs as $position => $tab) {
            StoreTab::firstOrCreate(
                ['slug' => $tab['slug'] ?? $tab['value']],
                [...$tab, 'position' => $position],
            );
        }
    }
}
