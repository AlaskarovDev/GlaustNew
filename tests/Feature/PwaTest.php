<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The app installs as a PWA: a manifest with real icons, a service worker, the head tags and the header's «Yüklə» button. */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_icons_and_service_worker(): void
    {
        $m = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame(['TradeFlow', 'standalone', './'], [$m['short_name'], $m['display'], $m['start_url']]);
        $sizes = [];
        foreach ($m['icons'] as $icon) {
            $info = getimagesize(public_path($icon['src']));
            $this->assertNotFalse($info, $icon['src']);
            $this->assertSame($icon['sizes'], $info[0].'x'.$info[1], $icon['src']);
            $sizes[$icon['purpose']][] = $icon['sizes'];
        }
        $this->assertContains('192x192', $sizes['any']);
        $this->assertContains('512x512', $sizes['any']);
        $this->assertContains('512x512', $sizes['maskable']);
        $this->assertSame([180, 180], array_slice(getimagesize(public_path('icons/apple-touch-icon.png')), 0, 2));

        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('fetch'", $sw);
        $this->assertStringContainsString('offline.html', $sw);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_pages_link_the_manifest_and_offer_install(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('manifest.webmanifest', false)->assertSee('apple-touch-icon', false);

        $admin = $this->makeCompany();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee('data-sw=', false)->assertSee('x-data="pwaInstall"', false)
            ->assertSee('Yüklə')->assertSee('Ana ekrana əlavə et');
    }
}
