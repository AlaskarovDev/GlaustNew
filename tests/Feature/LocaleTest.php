<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Interface language: AZ default, RU/EN picked on the login page (session) or in the user menu (saved on the user). */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private function in(string $locale, string $text): string
    {
        return trans($text, [], $locale);
    }

    public function test_dictionaries_cover_the_frame(): void
    {
        foreach (['İdarə paneli', 'Layihələr', 'Bank hesabları', 'Tənzimləmələr', 'Çıxış', 'Yadda saxla', 'Şifrəni unutmusunuz?'] as $az) {
            $this->assertNotSame($az, $this->in('ru', $az), "ru: $az");
            $this->assertNotSame($az, $this->in('en', $az), "en: $az");
        }
        foreach (['ru', 'en'] as $locale) {
            $this->assertNotSame('validation.required', trans('validation.required', [], $locale));
        }
    }

    public function test_guest_picks_language_on_login_page(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('lang="az"', false)->assertSee('Şifrəni unutmusunuz?');

        $this->from(route('login'))->post(route('locale'), ['locale' => 'ru'])->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('lang="ru"', false)->assertSee($this->in('ru', 'Şifrəni unutmusunuz?'));

        $this->post(route('locale'), ['locale' => 'de'])->assertSessionHasErrors('locale');
    }

    public function test_user_choice_is_saved_and_translates_menu_and_labels(): void
    {
        $admin = $this->makeCompany();
        $this->actingAs($admin)->from(route('dashboard'))->post(route('locale'), ['locale' => 'en'])->assertRedirect(route('dashboard'));
        $this->assertSame('en', $admin->fresh()->locale);

        $page = $this->get(route('dashboard'))->assertOk();
        $page->assertSee('lang="en"', false)
            ->assertSee($this->in('en', 'Layihələr'))
            ->assertSee($this->in('en', 'Bank hesabları'))
            ->assertDontSee('>Layihələr<', false);

        // a fresh session (other device) still opens in the saved language
        $this->flushSession();
        $this->actingAs($admin->fresh())->get(route('projects.index'))->assertOk()->assertSee('lang="en"', false);

        // labels kept in config are translated for the request, Azerbaijani stays the default
        $this->get(route('settings.roles.create'))->assertOk()->assertSee($this->in('en', 'Bank əməliyyatları'));
        $admin->forceFill(['locale' => 'az'])->save();
        $this->actingAs($admin->fresh())->get(route('settings.roles.create'))->assertOk()->assertSee('Bank əməliyyatları');
    }
}
