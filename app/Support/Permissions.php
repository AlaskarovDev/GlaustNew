<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * The permission list (config glaust.modules × glaust.actions) and what a user may do: the role's grants, then the user's
 * own exceptions — «deny» wins over the role, «grant» adds to it; an admin role is never narrowed.
 */
class Permissions
{
    /** @return array<string, array{label: string, actions: list<string>}> module => its label and actions */
    public static function modules(): array
    {
        return array_map(fn ($d) => ['label' => __($d['label']), 'actions' => $d['actions']], config('glaust.modules'));
    }

    /** @return list<string> every «module.action» there is */
    public static function all(): array
    {
        $out = [];
        foreach (config('glaust.modules') as $module => $def) {
            foreach ($def['actions'] as $action) {
                $out[] = "{$module}.{$action}";
            }
        }

        return $out;
    }

    /** Any action on a module implies seeing it. */
    public static function withView(array $abilities): array
    {
        $abilities = array_values(array_unique($abilities));
        foreach ($abilities as $p) {
            $module = explode('.', $p)[0];
            if (! in_array("{$module}.view", $abilities, true) && in_array('view', config("glaust.modules.{$module}.actions", []), true)) {
                $abilities[] = "{$module}.view";
            }
        }

        return $abilities;
    }

    /** Whether a list holds the ability, as «module.action», «module.*» or «*». */
    public static function listed(array $list, string $ability): bool
    {
        $module = explode('.', $ability, 2)[0];

        return in_array('*', $list, true) || in_array($ability, $list, true) || in_array($module.'.*', $list, true);
    }

    /** A denial of the ability itself — or of seeing its module, which takes every action of it. */
    public static function denied(array $deny, string $ability): bool
    {
        return self::listed($deny, $ability) || self::listed($deny, explode('.', $ability, 2)[0].'.view');
    }

    /**
     * @return array<string, array{role: bool, override: ?string, final: bool}> ability => from the role, the user's own (grant | deny | null), the result
     */
    public static function effective(User $user): array
    {
        $o = $user->permission_overrides ?? [];
        $out = [];
        foreach (self::all() as $ability) {
            $role = (bool) $user->role?->allows($ability);
            $override = $user->role?->is_admin ? null : (self::denied($o['deny'] ?? [], $ability) ? 'deny' : (self::listed($o['grant'] ?? [], $ability) ? 'grant' : null));
            $out[$ability] = ['role' => $role, 'override' => $override, 'final' => $override === 'deny' ? false : ($override === 'grant' ? true : $role)];
        }

        return $out;
    }

    public static function roleLabel(?Role $role): string
    {
        return $role ? __($role->name) : '—';
    }
}
