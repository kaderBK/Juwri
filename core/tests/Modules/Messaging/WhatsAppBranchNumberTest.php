<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WA-S3: stop an undiallable WhatsApp number being stored, and show the merchant
 * where a storefront order will actually go.
 *
 * The rule is deliberately narrower than the spec asked for. Of the 1,722 branch
 * numbers in the real data, 912 contain a `+` and 222 contain a space or dash —
 * all of which the storefront already strips and dials fine. Rejecting those
 * would break saving for most merchants and fix nothing.
 */
class WhatsAppBranchNumberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        $this->owner = User::factory()->create(['status' => 1]);
    }

    private function store(): Store
    {
        return Store::create([
            'user_id' => $this->owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Branch Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '966500000000',
        ]);
    }

    public function test_punctuation_is_accepted_because_the_storefront_strips_it(): void
    {
        foreach (['+44 7700 900201', '(44) 7700-900201', '447700900201'] as $number) {
            $this->assertNull(whatsapp_number_problem($number), $number.' should be accepted');
        }
    }

    public function test_a_national_leading_zero_is_rejected(): void
    {
        // The actual bug this item targets: 634 stored numbers look like this and
        // wa.me cannot dial any of them.
        $problem = whatsapp_number_problem('07700900201');

        $this->assertNotNull($problem);
        // Locale-independent: the country-code guidance message carries the
        // canonical dialable example in every locale (the literal "country code"
        // only survives in English, and the suite runs under DEFAULT_LANGUAGE=ar).
        $this->assertStringContainsString('447700900201', $problem);
    }

    public function test_letters_and_impossible_lengths_are_rejected(): void
    {
        $this->assertNotNull(whatsapp_number_problem('call us'));
        $this->assertNotNull(whatsapp_number_problem('4473'));
        $this->assertNotNull(whatsapp_number_problem('4477009002019999999'));
    }

    public function test_an_empty_number_is_allowed(): void
    {
        // Not every branch has its own number; the storefront falls back.
        $this->assertNull(whatsapp_number_problem(''));
        $this->assertNull(whatsapp_number_problem(null));
    }

    public function test_creating_a_branch_with_a_bad_number_fails(): void
    {
        $store = $this->store();

        $this->actingAs($this->owner)
            ->post(route('branches.store'), [
                'store_id' => $store->id,
                'name' => 'Downtown',
                'whatsapp' => '07700900201',
            ])
            ->assertSessionHasErrors('whatsapp');

        // A store gets a default branch of its own; only "Downtown" must be absent.
        $this->assertFalse(Branch::where('store_id', $store->id)->where('name', 'Downtown')->exists());
    }

    public function test_a_branch_keeps_its_grandfathered_bad_number_while_other_fields_are_edited(): void
    {
        $store = $this->store();
        $branch = Branch::create([
            'store_id' => $store->id,
            'name' => 'Old',
            'whatsapp' => '07700900201',
            'active' => true,
            'position' => 1,
        ]);

        // The merchant is changing the name, not the number. Locking them out of
        // their own branch over a value they did not touch would be wrong.
        $this->actingAs($this->owner)
            ->post(route('branches.update', $branch->id), [
                '_method' => 'PUT',
                'name' => 'Renamed',
                'whatsapp' => '07700900201',
                'active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $branch->fresh()->name);
    }

    public function test_changing_a_grandfathered_number_to_another_bad_one_is_rejected(): void
    {
        $store = $this->store();
        $branch = Branch::create([
            'store_id' => $store->id,
            'name' => 'Old',
            'whatsapp' => '07700900201',
            'active' => true,
            'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->post(route('branches.update', $branch->id), [
                '_method' => 'PUT',
                'name' => 'Old',
                'whatsapp' => '05550001111',
                'active' => 1,
            ])
            ->assertSessionHasErrors('whatsapp');

        $this->assertSame('07700900201', $branch->fresh()->whatsapp);
    }

    public function test_the_preview_names_the_branch_number_and_the_fallback(): void
    {
        $store = $this->store();

        $own = Branch::create([
            'store_id' => $store->id, 'name' => 'Has one',
            'whatsapp' => '+44 7700 900201', 'active' => true, 'position' => 1,
        ]);
        $bare = Branch::create([
            'store_id' => $store->id, 'name' => 'Has none',
            'whatsapp' => null, 'active' => true, 'position' => 2,
        ]);

        $this->assertSame('447700900201', storefront_whatsapp_number($store->id, $own, false));
        // No number of its own, so it falls back to the store phone.
        $this->assertSame('966500000000', storefront_whatsapp_number($store->id, $bare, false));
    }

    public function test_the_preview_does_not_pollute_the_lane_e_read_log(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '447700900201');
        $bare = Branch::create([
            'store_id' => $store->id, 'name' => 'Has none',
            'whatsapp' => null, 'active' => true, 'position' => 1,
        ]);

        $before = (int) PlatformSettings::stored('whatsapp_legacy_read_count');

        // A merchant looking at their branches page is not evidence that a store
        // depends on the legacy option — only a real storefront read is.
        storefront_whatsapp_number($store->id, $bare, false);

        $after = (int) PlatformSettings::stored('whatsapp_legacy_read_count');
        $this->assertSame($before, $after);
    }

    public function test_arabic_indic_numerals_are_normalised_not_deleted(): void
    {
        /* A plain \D strip deletes ٠-٩ outright, so a number typed in Arabic
           numerals resolved to an EMPTY string rather than to itself. Two live
           stores (129, 962) were in that state; only their legacy option, which
           happened to hold ASCII digits, kept them working, and E3 deleting that
           option took them dark. Most merchants here are Arabic-speaking — this is
           a normal way to type a number, not an edge case. */
        $this->assertSame('0546000333', whatsapp_normalize_number('٠٥٤ ٦٠٠ ٠٣٣٣'));
        $this->assertSame('966546000333', whatsapp_normalize_number('٩٦٦٥٤٦٠٠٠٣٣٣'));
        $this->assertSame('447700900201', whatsapp_normalize_number('۴۴۷۷۰۰۹۰۰۲۰۱'));

        $store = $this->store();
        $branch = Branch::create([
            'store_id' => $store->id, 'name' => 'Arabic digits',
            'whatsapp' => '٩٦٦٥٤٦٠٠٠٣٣٣', 'active' => true, 'position' => 1,
        ]);

        $this->assertSame('966546000333', storefront_whatsapp_number($store->id, $branch, false));

        // And the validator treats them as digits, not as junk to reject.
        $this->assertNull(whatsapp_number_problem('٩٦٦٥٤٦٠٠٠٣٣٣'));
    }
}
