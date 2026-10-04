<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthLogger;
use App\Services\CompanyProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(): View
    {
        abort_unless(config('glaust.registration_open'), 404);

        return view('auth.register');
    }

    public function store(Request $request, CompanyProvisioner $provisioner, AuthLogger $log): RedirectResponse
    {
        abort_unless(config('glaust.registration_open'), 404);

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'voen' => ['nullable', 'digits:10'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ], [
            'email.unique' => __('Bu email ilə artıq hesab var.'),
            'terms.accepted' => __('Şərtləri qəbul etməlisiniz.'),
        ], [
            'company_name' => __('Şirkətin adı'), 'voen' => __('VÖEN'), 'name' => __('Ad, soyad'), 'password' => __('Şifrə'), 'phone' => __('Telefon'),
        ]);

        $user = $provisioner->create(
            ['name' => $data['company_name'], 'voen' => $data['voen'] ?? null, 'phone' => $data['phone'] ?? null],
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'phone' => $data['phone'] ?? null],
        );

        Auth::login($user);
        $request->session()->regenerate();
        $log->login($user);

        return redirect()->route('dashboard')->with('success', __('Xoş gəldiniz! Şirkətiniz yaradıldı, 14 günlük sınaq müddəti başladı.'));
    }
}
