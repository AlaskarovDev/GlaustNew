<?php

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Şirkət qrupu: the companies of one owner share their users — a user of one is a user of all, with a role of their own
 * in each (the role of the same kind as where they came from; it can be changed per company). The data stays apart.
 */
class CompanyGroup
{
    /** The companies of the company's group (itself included). */
    public static function of(Company $company): Collection
    {
        return $company->group_id
            ? Company::where('group_id', $company->group_id)->orWhere('id', $company->group_id)->orderBy('id')->get()
            : collect([$company]);
    }

    /** Puts the companies into one group (the group of the first) and makes every user of each a user of all. */
    public static function link(Company ...$companies): void
    {
        $root = $companies[0]->group_id ?? $companies[0]->id;
        foreach ($companies as $c) {
            $old = $c->group_id;
            $c->forceFill(['group_id' => $root])->save();
            if ($old && $old !== $root) {   // the whole old group comes along
                Company::where('group_id', $old)->orWhere('id', $old)->update(['group_id' => $root]);
            }
        }
        self::sync(Company::find($root));
    }

    /** Every user of the group's companies becomes a user of each of them (the memberships already there stay as they are). */
    public static function sync(Company $company): int
    {
        $companies = self::of($company);
        $added = 0;
        foreach (CompanyUser::with('role')->whereIn('company_id', $companies->pluck('id'))->orderBy('id')->get()->unique('user_id') as $m) {
            $added += self::share(User::find($m->user_id), $m->company_id, $companies);
        }

        return $added;
    }

    /** Makes a user of one company a user of the other companies of its group; returns how many memberships were added. */
    public static function share(User $user, int $fromCompanyId, ?Collection $companies = null): int
    {
        $companies ??= self::of(Company::find($fromCompanyId));
        if ($user->is_super_admin) {
            return 0;   // the platform's own account: its companies are its own business
        }
        $role = CompanyUser::with('role')->where('company_id', $fromCompanyId)->where('user_id', $user->id)->first()?->role;
        $added = 0;
        foreach ($companies as $c) {
            if ($c->id === $fromCompanyId || CompanyUser::where('company_id', $c->id)->where('user_id', $user->id)->exists()) {
                continue;
            }
            $roles = Role::withoutGlobalScopes()->where('company_id', $c->id);
            $target = ($role?->key ? (clone $roles)->where('key', $role->key)->first() : null)
                ?? ($role ? (clone $roles)->where('name', $role->name)->first() : null)
                ?? (clone $roles)->where('key', 'employee')->first();
            if ($target) {
                $user->setAccessIn($c->id, $target->id);
                $added++;
            }
        }

        return $added;
    }
}
