<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']); // real sessions table, as in production
    }

    public function test_login_and_logout_are_logged_with_session_duration(): void
    {
        $user = $this->makeCompany();

        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $login = LoginLog::withoutGlobalScopes()->where('event', 'login')->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame($user->company_id, $login->company_id);
        $this->assertNotEmpty($login->session_id);
        $this->assertNotEmpty($login->device);

        $this->travel(7)->minutes();
        // A browser sends the session cookie back; the test client must be told to.
        $this->withCookie(config('session.cookie'), $login->session_id)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        $login->refresh();
        $this->assertTrue($login->closed);
        $this->assertGreaterThanOrEqual(420, $login->duration_seconds);
        $this->assertSame(1, LoginLog::withoutGlobalScopes()->where('event', 'logout')->count());
    }

    public function test_failed_attempts_are_logged_and_lock_the_account(): void
    {
        $user = $this->makeCompany();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'admin@test.az', 'password' => 'wrong-'.$i])->assertSessionHasErrors('email');
        }
        $this->assertSame(4, LoginLog::withoutGlobalScopes()->where('event', 'failed')->count());
        $this->assertSame(1, LoginLog::withoutGlobalScopes()->where('event', 'locked')->count());
        $this->assertTrue($user->fresh()->isLocked());

        // Even the right password is refused while locked.
        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(16)->minutes();
        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->assertSame(0, $user->fresh()->failed_attempts);
    }

    public function test_unknown_email_is_logged_without_revealing_it(): void
    {
        $this->makeCompany();
        $this->post('/login', ['email' => 'nobody@test.az', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'Email və ya şifrə yanlışdır.']);
        $this->assertSame('nobody@test.az', LoginLog::withoutGlobalScopes()->where('event', 'failed')->value('email'));
    }

    public function test_inactive_user_cannot_log_in_and_is_signed_out_mid_session(): void
    {
        $user = $this->makeCompany();
        $this->actingAs($user)->get('/')->assertOk();
        $user->forceFill(['is_active' => false])->save();
        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123'])->assertSessionHasErrors('email');
    }

    public function test_two_factor_login(): void
    {
        $user = $this->makeCompany();
        $g = app(Google2FA::class);
        $secret = $g->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertSame(1, LoginLog::withoutGlobalScopes()->where('event', 'two_factor_failed')->count());

        $this->post('/two-factor-challenge', ['code' => $g->getCurrentOtp($secret)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_can_force_logout_a_user_everywhere(): void
    {
        $admin = $this->makeCompany();
        $worker = $this->makeUser($admin->company, 'employee', 'worker@test.az');
        DB::table('sessions')->insert([
            ['id' => 'sess-a', 'user_id' => $worker->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'Chrome/1 Windows', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'sess-b', 'user_id' => $worker->id, 'ip_address' => '2.2.2.2', 'user_agent' => 'Safari/1 iPhone', 'payload' => 'x', 'last_activity' => time()],
        ]);
        $token = $worker->remember_token;

        $this->actingAs($admin)->post(route('settings.users.logout', $worker->id))->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $worker->id)->count());
        $this->assertNotSame($token, $worker->fresh()->remember_token, 'remember-me cookies are invalidated');
        $this->assertSame(1, LoginLog::withoutGlobalScopes()->where('user_id', $worker->id)->where('event', 'forced_logout')->count());
    }

    public function test_expired_sessions_are_swept_into_the_log(): void
    {
        $user = $this->makeCompany();
        $this->post('/login', ['email' => 'admin@test.az', 'password' => 'Secret123']);
        $login = LoginLog::withoutGlobalScopes()->where('event', 'login')->firstOrFail();
        DB::table('sessions')->where('id', $login->session_id)->update(['last_activity' => now()->addMinutes(30)->getTimestamp() - 3 * 3600]);

        $this->travel(3)->hours();
        $this->artisan('glaust:sweep-sessions')->assertSuccessful();

        $this->assertTrue($login->fresh()->closed);
        $this->assertSame(1, LoginLog::withoutGlobalScopes()->where('event', 'session_expired')->where('user_id', $user->id)->count());
    }

    public function test_registration_creates_a_company_with_roles_and_admin(): void
    {
        $this->seedPlans();
        $this->post('/register', [
            'company_name' => 'Yeni Şirkət MMC', 'voen' => '1234567891', 'name' => 'Aysel Kərimova', 'email' => 'aysel@yeni.az',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'aysel@yeni.az')->firstOrFail();
        $this->assertTrue($user->isCompanyAdmin());
        $this->assertSame('trial', $user->company->subscription_status);
        $this->assertSame(5, $this->inTenant($user, fn () => $user->company->roles()->count()));
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Aysel');
    }

    public function test_invitation_flow(): void
    {
        $admin = $this->makeCompany();
        $role = $this->inTenant($admin, fn () => $admin->company->roles()->where('key', 'manager')->first());
        $this->actingAs($admin)->post(route('settings.users.store'), ['name' => 'Tural Nəbiyev', 'email' => 'tural@test.az', 'role_id' => $role->id])->assertRedirect();
        $invited = User::where('email', 'tural@test.az')->firstOrFail();
        $this->assertNotNull($invited->invitation_token);
        $this->assertSame(1, \App\Models\MailLog::withoutGlobalScopes()->where('kind', 'invitation')->where('status', 'sent')->count());

        // The token in the mail is the raw one; the DB stores its hash.
        $raw = str_repeat('a', 64);
        $invited->forceFill(['invitation_token' => hash('sha256', $raw)])->save();
        $this->post('/logout');
        $this->get(route('invitation.show', $raw))->assertOk()->assertSee('Tural');
        $this->post(route('invitation.accept', $raw), ['password' => 'Secret123', 'password_confirmation' => 'Secret123'])->assertRedirect(route('dashboard'));
        $this->assertNull($invited->fresh()->invitation_token);
        $this->post('/logout');
        $this->get(route('invitation.show', $raw))->assertNotFound(); // single use
    }
}
