<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\PaletteFromLogo;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-S4: palette-from-logo.
 *
 * The extraction must lead with the logo's dominant brand colour, propose a
 * coherent legible set around it, and the endpoint must be a proposal only —
 * ownership-scoped, never auto-saved.
 */
class ThemePaletteFromLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_a_red_logo_proposes_a_red_primary(): void
    {
        $file = $this->logo([220, 40, 40], [40, 90, 200]);

        $palette = PaletteFromLogo::propose($file);

        $this->assertNotNull($palette);
        // The dominant block is red — it must lead.
        [$r, $g, $b] = Tokens::rgb($palette['primary']);
        $this->assertGreaterThan($g + 60, $r, 'primary should be dominated by red');
        $this->assertGreaterThan($b + 60, $r);
        // The second hue becomes the accent — here, blue.
        [$ar, $ag, $ab] = Tokens::rgb($palette['accent']);
        $this->assertGreaterThan($ar + 40, $ab, 'accent should be the blue secondary');
        // The derived set is legible: text on background clears AA.
        $this->assertGreaterThanOrEqual(4.5, Tokens::contrastRatio($palette['text'], $palette['background']));
    }

    public function test_a_blank_or_greyscale_logo_yields_no_proposal(): void
    {
        // A logo that is only white/black/grey has no brand hue to extract.
        $img = imagecreatetruecolor(48, 48);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 8, 8, 40, 40, imagecolorallocate($img, 60, 60, 60));
        $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($img, $file);
        imagedestroy($img);

        $this->assertNull(PaletteFromLogo::propose($file));
    }

    public function test_the_endpoint_proposes_but_never_saves(): void
    {
        [$owner, $store] = $this->storeFixture();
        // The endpoint reads where StorageService actually writes local uploads:
        // public_path('storage/store/logo') — NOT Laravel's public disk,
        // which never held these files (the original bug this test now pins).
        $dir = public_path('storage/store/logo');
        File::ensureDirectoryExists($dir);
        $target = $dir.'/'.$store->main_image;
        copy($this->logo([30, 160, 90], [230, 180, 40]), $target);
        $this->beforeApplicationDestroyed(function () use ($target) {
            @unlink($target);
        });

        $response = $this->actingAs($owner)
            ->postJson(route('theme.customizer.paletteFromLogo', $store->id))
            ->assertOk()->assertJsonPath('ok', true);

        $values = $response->json('values');
        $this->assertArrayHasKey('colors.primary', $values);
        [$r, $g, $b] = Tokens::rgb($values['colors.primary']);
        $this->assertGreaterThan($r, $g, 'the green logo should propose a green primary');

        // Proposal only — nothing written.
        $this->assertDatabaseCount('theme_settings', 0);
    }

    public function test_a_store_on_the_default_placeholder_logo_gets_a_clean_422(): void
    {
        [$owner, $store] = $this->storeFixture();
        $store->update(['main_image' => 'default.png']);

        $this->actingAs($owner)
            ->postJson(route('theme.customizer.paletteFromLogo', $store->id))
            ->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_palette_extraction_is_owner_scoped(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.paletteFromLogo', $store->id))
            ->assertNotFound();
    }

    /** A tiny two-colour logo PNG on disk; returns its path. */
    private function logo(array $outer, array $inner): string
    {
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, 8, 8, 56, 56, imagecolorallocate($img, ...$outer));
        imagefilledrectangle($img, 22, 22, 42, 42, imagecolorallocate($img, ...$inner));
        $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($img, $file);
        imagedestroy($img);

        return $file;
    }

    /** @return array{0:User,1:Store} */
    private function storeFixture(string $template = 'crave'): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'logo-'.Str::lower(Str::random(6)).'.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }
}
