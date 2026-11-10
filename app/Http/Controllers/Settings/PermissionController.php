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
        User::withRolesHere($users);

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
        $user = $this->here($user);

        return view('settings.permissions.user', [
            'user' => $user, 'modules' => Permissions::modules(), 'actions' => array_map('__', config('glaust.actions')), 'effective' => Permissions::effective($user),
            'users' => User::forTenant()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The user as a member of this company: the role and overrides here. A user working in another company right now is
     * a copy carrying this company's ones (never saved — setAccessIn writes the membership).
     */
    private function here(User $user): User
    {
        $m = $user->membershipIn((int) tenant()?->id);
        abort_unless($m !== null, 404);
        if ((int) $user->company_id !== (int) tenant()->id) {
            $user = clone $user;
            $user->setRelation('role', $m->role);
            $user->permission_overrides = $m->permission_overrides;
        } else {
            $user->load('role');
        }

        return $user;
    }

    /** A user's own exceptions: each ability «role» (as the role says), «grant» or «deny». */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->authorize('users.update');
        $user = $this->here($user);
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
        $user->setAccessIn(tenant()->id, (int) $user->role?->id, $grant || $deny ? ['grant' => $grant, 'deny' => $deny] : null);

        return redirect()->route('settings.permissions.user', $user)->with('success', __(':v1 üçün icazələr yeniləndi.', ['v1' => $user->name]));
    }
}
