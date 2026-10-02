<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $user = $this->find($token);

        return view('auth.invitation', ['user' => $user, 'token' => $token]);
    }

    public function store(Request $request, string $token, AuthLogger $log): RedirectResponse
    {
        $user = $this->find($token);
        $request->validate(['password' => ['required', 'confirmed', Password::defaults()]], [], ['password' => 'Şifrə']);

        $user->forceFill([
            'password' => $request->input('password'),
            'invitation_token' => null,
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        $log->login($user);

        return redirect()->route('dashboard')->with('success', 'Xoş gəldiniz, '.$user->name.'!');
    }

    private function find(string $token): User
    {
        // Tokens are 64 random chars; invitations expire after 7 days.
        return User::where('invitation_token', hash('sha256', $token))
            ->where('updated_at', '>=', now()->subDays(7))
            ->firstOr(fn () => abort(404, 'Dəvət linki etibarsızdır və ya müddəti bitib.'));
    }
}
