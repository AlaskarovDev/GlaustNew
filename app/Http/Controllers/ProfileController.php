<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Services\AuthLogger;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class ProfileController extends Controller
{
    public function edit(Request $request, Google2FA $google2fa): View
    {
        $user = $request->user();
        $qr = null;
        if ($user->two_factor_secret && ! $user->two_factor_confirmed_at) {
            $url = $google2fa->getQRCodeUrl('Glaust MS', $user->email, $user->two_factor_secret);
            $qr = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
        }

        $sessions = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn ($s) => (object) [
                'id' => $s->id,
                'ip' => $s->ip_address,
                'device' => AuthLogger::describeAgent($s->user_agent),
                'last' => \Carbon\Carbon::createFromTimestamp($s->last_activity)->setTimezone(config('app.timezone')),
                'current' => $s->id === $request->session()->getId(),
            ]);

        $logins = LoginLog::where('user_id', $user->id)->latest('created_at')->limit(10)->get();

        return view('profile.edit', compact('user', 'qr', 'sessions', 'logins'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'],
            'daily_digest' => ['nullable', 'boolean'],
            'email_reminders' => ['nullable', 'boolean'],
        ], [], ['position' => 'Vəzifə']);

        $request->user()->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'position' => $data['position'] ?? null,
            'daily_digest' => $request->boolean('daily_digest'),
            'email_reminders' => $request->boolean('email_reminders'),
        ]);

        return back()->with('success', 'Profil yeniləndi.');
    }

    public function password(Request $request, AuthLogger $log): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [], ['current_password' => 'Cari şifrə', 'password' => 'Yeni şifrə']);

        $user = $request->user();
        $user->update(['password' => $request->input('password')]);
        $log->passwordChanged($user);
        $closed = $log->forceLogoutEverywhere($user, $request->session()->getId());

        return back()->with('success', 'Şifrə dəyişdirildi.'.($closed ? " Digər {$closed} sessiya bağlandı." : ''));
    }

    public function theme(Request $request): JsonResponse
    {
        $theme = (string) $request->input('theme');
        if (in_array($theme, ['light', 'dark', 'system'], true)) {
            $request->user()->forceFill(['theme' => $theme])->save();
        }

        return response()->json(['ok' => true]);
    }

    public function enableTwoFactor(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            $user->forceFill(['two_factor_secret' => $google2fa->generateSecretKey(32), 'two_factor_confirmed_at' => null])->save();
        }

        return redirect()->route('profile.edit', ['tab' => 'security'])->with('info', 'QR kodu autentifikator tətbiqi ilə skan edin və kodu təsdiqləyin.');
    }

    public function confirmTwoFactor(Request $request, Google2FA $google2fa): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']], [], ['code' => 'Kod']);
        $user = $request->user();
        if (! $user->two_factor_secret || ! $google2fa->verifyKey($user->two_factor_secret, $request->input('code'), 1)) {
            return back()->withErrors(['code' => 'Kod yanlışdır.']);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return redirect()->route('profile.edit', ['tab' => 'security'])->with('success', 'İki mərhələli təsdiq aktivləşdirildi.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']], [], ['current_password' => 'Şifrə']);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        return redirect()->route('profile.edit', ['tab' => 'security'])->with('success', 'İki mərhələli təsdiq söndürüldü.');
    }

    public function logoutOthers(Request $request, AuthLogger $log): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']], [], ['password' => 'Şifrə']);
        $n = $log->forceLogoutEverywhere($request->user(), $request->session()->getId());

        return back()->with('success', "{$n} digər sessiya bağlandı.");
    }
}
