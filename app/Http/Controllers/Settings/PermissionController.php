<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * İcazələrin idarə olunması: every role side by side (module × action), and per user what the role gives, the user's own
 * exceptions (grant / deny) and the result — what they can see, create, edit, delete, export, import.
 */
class PermissionController extends Controller
{
    public function index(Request $request): View
    {
        $roles = Role::withCount('users')->orderByDesc('is_admin')->orderBy('id')->get();
        $users = User::forTenant()->with('role')->orderBy('name')->get();

        return view('settings.permissions.index', [
            'roles' => $roles, 'users' => $users, 'modules' => Permissions::modules(), 'actions' => array_map('__', config('glaust.actions')),
            'tab' => $request->query('tab') === 'users' ? 'users' : 'roles',
        ]);
    }

    /** The matrix of every role at once. */
    public function updateRoles(Request $request): RedirectResponse
    {
        $this->authorize('users.update');
        $all = Permissions::all();
        $data = $request->validate(['roles' => ['array'], 'roles.*' => ['array'], 'roles.*.*' => [Rule::in($all)]]);
        $changed = 0;
        foreach (Role::where('is_admin', false)->get() as $role) {
            $perms = Permissions::withView($data['roles'][$role->id] ?? []);
            sort($perms);
            $old = $role->permissions ?? [];
            sort($old);
            if ($perms !== $old) {
                $role->update(['permissions' => $perms]);
                $changed++;
            }
        }

        return redirect()->route('settings.permissions.index')->with('success', $changed ? __(':v1 rolun icazələri yeniləndi.', ['v1' => $changed]) : __('Dəyişiklik yoxdur.'));
    }

    public function user(User $user): View
    {
        abort_unless($user->company_id === tenant()?->id, 404);
        $user->load('role');

        return view('settings.permissions.user', [
            'user' => $user, 'modules' => Permissions::modules(), 'actions' => array_map('__', config('glaust.actions')), 'effective' => Permissions::effective($user),
            'users' => User::forTenant()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** A user's own exceptions: each ability «role» (as the role says), «grant» or «deny». */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.update');
        abort_unless($user->company_id === tenant()?->id, 404);
        if ($user->role?->is_admin) {
            return back()->with('error', __('Admin rolunun icazələri məhdudlaşdırılmır.'));
        }
        if ($user->id === $request->user()->id) {
            return back()->with('error', __('Öz icazələrinizi dəyişə bilməzsiniz.'));
        }
        $data = $request->validate(['p' => ['array'], 'p.*' => [Rule::in(['role', 'grant', 'deny'])]]);
        $grant = $deny = [];
        foreach ($data['p'] ?? [] as $ability => $choice) {
            if (! in_array($ability, Permissions::all(), true)) {
                continue;
            }
            $choice === 'grant' ? $grant[] = $ability : ($choice === 'deny' ? $deny[] = $ability : null);
        }
        $grant = Permissions::withView($grant);
        $deny = array_values(array_diff($deny, $grant));
        $user->forceFill(['permission_overrides' => $grant || $deny ? ['grant' => $grant, 'deny' => $deny] : null])->save();

        return redirect()->route('settings.permissions.user', $user)->with('success', __(':v1 üçün icazələr yeniləndi.', ['v1' => $user->name]));
    }
}
