<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('settings.roles.index', ['roles' => Role::withCount('users')->orderByDesc('is_admin')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->authorize('users.create');

        return view('settings.roles.form', ['role' => new Role(['permissions' => ['dashboard.view']])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('users.create');
        Role::create($this->validated($request) + ['is_system' => false, 'is_admin' => false]);

        return redirect()->route('settings.roles.index')->with('success', __('Rol yaradıldı.'));
    }

    public function edit(Role $role): View
    {
        $this->authorize('users.update');

        return view('settings.roles.form', compact('role'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('users.update');
        abort_if($role->is_admin, 403, __('Admin rolunun icazələri dəyişdirilmir.'));
        $role->update($this->validated($request, $role));

        return redirect()->route('settings.roles.index')->with('success', __('Rol yeniləndi.'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('users.delete');
        if ($role->is_system) {
            return back()->with('error', __('Sistem rolları silinmir.'));
        }
        if ($role->users()->exists()) {
            return back()->with('error', __('Bu rolda istifadəçilər var. Əvvəlcə onlara başqa rol verin.'));
        }
        $role->delete();

        return back()->with('success', __('Rol silindi.'));
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $allowed = [];
        foreach (config('glaust.modules') as $module => $def) {
            foreach ($def['actions'] as $action) {
                $allowed[] = "$module.$action";
            }
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles')->where('company_id', tenant()->id)->ignore($role?->id)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in($allowed)],
        ], ['name.unique' => __('Bu adda rol var.')], ['name' => __('Rolun adı')]);

        $perms = array_values(array_unique($data['permissions'] ?? []));
        // Any action on a module implies seeing it.
        foreach ($perms as $p) {
            $module = explode('.', $p)[0];
            if (! in_array("$module.view", $perms, true) && in_array('view', config("glaust.modules.$module.actions"), true)) {
                $perms[] = "$module.view";
            }
        }

        return ['name' => $data['name'], 'permissions' => $perms];
    }
}
