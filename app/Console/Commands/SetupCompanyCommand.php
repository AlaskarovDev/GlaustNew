<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a company (active, no demo data) if it does not exist yet and adds an Admin user to it;
 * --super also gives that user the platform admin area (/admin: companies and their users).
 * Idempotent: an existing company is reused; an existing account without a company (the platform
 * admin) is attached to it with the Admin role and the given password; another company's user is refused.
 */
class SetupCompanyCommand extends Command
{
    protected $signature = 'glaust:setup-company {name} {--admin-email=} {--admin-name=} {--admin-password=} {--phone=} {--position=} {--super}';

    protected $description = 'Şirkət yaradır (yoxdursa) və ona Admin istifadəçi əlavə edir';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $name = trim((string) $this->argument('name'));
        $company = Company::where('name', $name)->first() ?? $provisioner->createCompany(['name' => $name], null, 'active');
        $this->info("Şirkət: #{$company->id} {$company->name}");

        $email = mb_strtolower(trim((string) $this->option('admin-email')));
        if ($email === '') {
            return self::SUCCESS;
        }
        // An existing account without a company (e.g. the platform admin) is attached to this company.
        $existing = User::where('email', $email)->first();
        if ($existing && $existing->company_id !== null && $existing->company_id !== $company->id) {
            $this->error('Bu email başqa şirkətin istifadəçisidir.');

            return self::FAILURE;
        }
        if ($existing) {
            $v = Validator::make(['password' => (string) $this->option('admin-password')], ['password' => ['required', Password::defaults()]]);
            if ($v->fails()) {
                $this->error($v->errors()->first());

                return self::FAILURE;
            }
            $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'admin')->firstOrFail();
            $existing->company_id = $company->id;
            $existing->role_id = $role->id;
            $existing->password = $this->option('admin-password');
            $existing->is_active = true;
            $existing->name = $this->option('admin-name') ?: $existing->name;
            $existing->is_super_admin = $existing->is_super_admin || $this->option('super');
            $existing->save();
            $this->info("Mövcud hesab şirkətə bağlandı: {$existing->email} (Admin".($existing->is_super_admin ? ', platforma idarəetməsi də' : '').')');

            return self::SUCCESS;
        }
        $v = Validator::make(['email' => $email, 'password' => (string) $this->option('admin-password'), 'name' => (string) $this->option('admin-name')], [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'name' => ['required', 'string', 'max:190'],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }
        $role = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('key', 'admin')->firstOrFail();
        $user = $provisioner->addUser($company, [
            'name' => $this->option('admin-name'), 'email' => $email, 'password' => $this->option('admin-password'),
            'phone' => $this->option('phone'), 'position' => $this->option('position'),
        ], $role->id);
        if ($this->option('super')) {
            User::whereKey($user->id)->update(['is_super_admin' => true]);
        }
        $this->info("Admin: {$user->email}".($this->option('super') ? ' (platforma idarəetməsi də)' : ''));

        return self::SUCCESS;
    }
}
