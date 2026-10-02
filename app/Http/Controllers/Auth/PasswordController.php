<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => 'Email']);

        // Same answer whether or not the address exists (no account enumeration).
        Password::broker()->sendResetLink(['email' => Str::lower($request->input('email'))]);

        return back()->with('status', 'Əgər bu email sistemdə qeydiyyatdadırsa, şifrəni yeniləmək üçün link göndərildi.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request, AuthLogger $log): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [], ['password' => 'Şifrə']);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($log) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'failed_attempts' => 0, 'locked_until' => null])->save();
                $log->forceLogoutEverywhere($user);
                $log->passwordChanged($user, 'password_reset');
                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Şifrəniz yeniləndi. Yeni şifrə ilə daxil olun.')
            : back()->withInput($request->only('email'))->withErrors(['email' => 'Link etibarsızdır və ya müddəti bitib.']);
    }
}
