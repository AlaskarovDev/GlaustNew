<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function __construct(private AuthLogger $log) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], ['email' => 'Email', 'password' => 'Şifrə']);

        $email = Str::lower(trim($data['email']));
        $user = User::where('email', $email)->first();
        $fail = fn (string $message) => back()->withInput($request->only('email', 'remember'))->withErrors(['email' => $message]);

        if ($user?->isLocked()) {
            $this->log->failed($email, $user, 'locked');

            return $fail('Çoxlu uğursuz cəhd səbəbindən hesab müvəqqəti bağlanıb. '
                .$user->locked_until->diffForHumans(['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]).' sonra yenidən cəhd edin.');
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            if ($user) {
                $attempts = $user->failed_attempts + 1;
                $user->forceFill([
                    'failed_attempts' => $attempts >= self::MAX_ATTEMPTS ? 0 : $attempts,
                    'locked_until' => $attempts >= self::MAX_ATTEMPTS ? now()->addMinutes(self::LOCK_MINUTES) : null,
                ])->saveQuietly();
                $this->log->failed($email, $user, $attempts >= self::MAX_ATTEMPTS ? 'locked' : 'failed');
                if ($attempts >= self::MAX_ATTEMPTS) {
                    return $fail('Şifrə '.self::MAX_ATTEMPTS.' dəfə səhv daxil edildi. Hesab '.self::LOCK_MINUTES.' dəqiqəlik bağlandı.');
                }
            } else {
                $this->log->failed($email, null);
            }

            return $fail('Email və ya şifrə yanlışdır.');
        }

        if (! $user->is_active || $user->invitation_token) {
            $this->log->failed($email, $user, 'failed');

            return $fail($user->invitation_token ? 'Dəvəti hələ qəbul etməmisiniz. Mailinizdəki linkdən şifrə təyin edin.' : 'Hesabınız deaktiv edilib.');
        }

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => time()]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->complete($request, $user, $request->boolean('remember'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->session()->has('login.2fa') ? view('auth.two-factor') : redirect()->route('login');
    }

    public function verifyChallenge(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $pending = $request->session()->get('login.2fa');
        if (! $pending || time() - $pending['at'] > 300) {
            $request->session()->forget('login.2fa');

            return redirect()->route('login')->withErrors(['email' => 'Təsdiq müddəti bitdi. Yenidən daxil olun.']);
        }

        $request->validate(['code' => ['required', 'digits:6']], [], ['code' => 'Kod']);
        $user = User::findOrFail($pending['id']);

        if (! $google2fa->verifyKey($user->two_factor_secret, $request->input('code'), 1)) {
            $this->log->failed($user->email, $user, 'two_factor_failed');

            return back()->withErrors(['code' => 'Kod yanlışdır.']);
        }

        $request->session()->forget('login.2fa');

        return $this->complete($request, $user, $pending['remember']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->log->logout($user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Sistemdən çıxdınız.');
    }

    private function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->log->login($user);

        if ($user->is_super_admin && ! $user->company_id) {
            return redirect()->route('admin.dashboard');
        }

        return redirect()->intended(route('dashboard'));
    }
}
