<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Production-safe base data: plans, and the platform owner when
 * GLAUST_ADMIN_EMAIL / GLAUST_ADMIN_PASSWORD are set. Demo data: `php artisan glaust:demo`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $all = config('glaust.plan_modules');
        foreach ([
            ['code' => 'start', 'name' => 'Start', 'max_users' => 5, 'max_storage_mb' => 1024, 'monthly_price' => 49, 'modules' => ['projects', 'crm', 'contracts', 'reports']],
            ['code' => 'business', 'name' => 'Biznes', 'max_users' => 25, 'max_storage_mb' => 10240, 'monthly_price' => 149, 'modules' => $all],
            ['code' => 'corporate', 'name' => 'Korporativ', 'max_users' => 200, 'max_storage_mb' => 51200, 'monthly_price' => 399, 'modules' => $all],
        ] as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }

        $email = env('GLAUST_ADMIN_EMAIL');
        $password = env('GLAUST_ADMIN_PASSWORD');
        if ($email && $password && ! User::where('email', Str::lower($email))->exists()) {
            $admin = new User(['name' => 'Platforma admini', 'email' => Str::lower($email), 'password' => $password, 'is_active' => true]);
            $admin->is_super_admin = true;
            $admin->email_verified_at = now();
            $admin->save();
            $this->command?->info("Super admin yaradıldı: {$email}");
        }
    }
}
