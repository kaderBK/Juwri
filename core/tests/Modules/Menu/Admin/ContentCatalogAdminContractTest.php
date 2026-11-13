<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Modules\Fiscal\Models\Tax;
use Juwri\Modules\Marketing\Models\Subscriber;
use Juwri\Modules\Menu\Models\Allergy;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-08 characterisation: the small admin catalogues (Community area) —
 * allergens, FAQ entries, testimonials, newsletter subscribers.
 * Written against the pre-rewrite controllers; the rewrite must keep it green.
 */
class ContentCatalogAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
    }

    // ── Allergens ──────────────────────────────────────────────────────────

    public function test_allergies_admin_only(): void
    {
        $allergy = Allergy::create(['title' => 'Nuts', 'active' => '1', 'position' => 0]);
        $this->assertAdminOnly('get', route('admin.allergies.index'));
        $this->assertAdminOnly('get', route('admin.allergies.edit', $allergy));
        $this->assertAdminOnly('post', route('admin.allergies.store'), ['title' => 'X', 'active' => 1]);
        $this->assertAdminOnly('post', route('admin.allergies.delete'), ['ids' => [$allergy->id]]);
        $this->assertAdminOnly('post', route('admin.allergies.reorder'), ['position' => [$allergy->id]]);
        $this->assertDatabaseCount('allergies', 1);
    }

    public function test_allergies_list_create_update_reorder_delete(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.allergies.index'))->assertOk()->assertViewIs('admin.allergies.index');
        $this->actingAs($admin)->get(route('admin.allergies.create'))->assertOk()->assertViewIs('admin.allergies.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.allergies.store'), ['title' => '', 'active' => 1]));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.allergies.store'), ['title' => 'Milk', 'active' => 'maybe']));
        $this->assertDatabaseCount('allergies', 0);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.allergies.store'), [
            'title' => 'Milk', 'active' => 1, 'translations' => ['ar' => ['title' => 'حليب']],
        ]));
        $milk = Allergy::where('title', 'Milk')->firstOrFail();
        $this->assertSame('default.png', $milk->image);
        $this->assertSame(999, (int) $milk->position);
        $this->assertSame('حليب', $milk->translations->ar->title);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.allergies.store'), ['title' => 'Eggs', 'active' => 0]));
        $eggs = Allergy::where('title', 'Eggs')->firstOrFail();

        $rows = $this->actingAs($admin)->dataTable(route('admin.allergies.index'), 1, 'asc')->assertOk()
            ->assertJson(['draw' => 3, 'recordsTotal' => 2])->json('data');
        $this->assertStringContainsString('Eggs', $rows[0][1]);
        $this->assertStringContainsString('storage/allergies/default.png', $rows[0][1]);
        $this->assertStringContainsString('data-tone="danger"', $rows[0][2]);
        $this->assertStringContainsString('data-tone="success"', $rows[1][2]);
        $this->assertStringContainsString('app-reorder-icon', $rows[0][0]);
        $this->assertStringContainsString(route('admin.allergies.edit', $eggs->id), $rows[0][3]);
        $this->assertSame($eggs->id, $rows[0]['DT_RowId']);

        $found = $this->actingAs($admin)->dataTable(route('admin.allergies.index'), 1, 'asc', 'Mil')->json('data');
        $this->assertCount(1, $found);

        $this->actingAs($admin)->get(route('admin.allergies.edit', $milk))->assertOk()->assertViewHas('allergy');
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.allergies.update', $milk), ['title' => '', 'active' => 1]));
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.allergies.update', $milk), ['title' => 'Dairy', 'active' => 0]));
        $milk->refresh();
        $this->assertSame('Dairy', $milk->title);
        $this->assertSame('0', (string) $milk->active);
        $this->assertSame('default.png', $milk->image, 'no upload keeps the image');

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.allergies.reorder'), ['position' => [$eggs->id, $milk->id]]));
        $this->assertSame(0, (int) $eggs->fresh()->position);
        $this->assertSame(1, (int) $milk->fresh()->position);

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.allergies.delete'), ['ids' => [$eggs->id]]));
        $this->assertDatabaseMissing('allergies', ['id' => $eggs->id]);
        $this->assertDatabaseHas('allergies', ['id' => $milk->id]);

        $this->actingAs($admin)->get(route('admin.allergies.show', $milk))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.allergies.destroy', $milk))->assertNotFound();
        $this->assertDatabaseHas('allergies', ['id' => $milk->id]);
    }

    // ── FAQ ────────────────────────────────────────────────────────────────

    public function test_faqs_admin_only(): void
    {
        $faq = Faq::create(['faq_title' => 'Q', 'faq_content' => 'A', 'active' => 1]);
        $this->assertAdminOnly('get', route('admin.faqs.index', ['lang' => 'en']));
        $this->assertAdminOnly('post', route('admin.faqs.store'), ['faq_title' => 'Q2', 'faq_content' => 'A', 'active' => 1]);
        $this->assertAdminOnly('delete', route('admin.faqs.destroy', $faq));
        $this->assertDatabaseCount('faq_entries', 1);
    }

    public function test_faqs_index_by_language_create_update_destroy(): void
    {
        $admin = $this->admin();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'active' => 1, 'direction' => 'ltr', 'position' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'active' => 1, 'direction' => 'rtl', 'position' => 2]);
        config(['settings.lang' => 'en']);

        $this->actingAs($admin)->get(route('admin.faqs.index'))->assertRedirect(route('admin.faqs.index').'?lang=en');
        $this->actingAs($admin)->get(route('admin.faqs.index', ['lang' => 'xx']))->assertNotFound();

        $this->actingAs($admin)->get(route('admin.faqs.create'))->assertOk()->assertViewIs('admin.faqs.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.faqs.store'), ['faq_title' => 'Q', 'faq_content' => '', 'active' => 1]));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.faqs.store'), ['faq_title' => 'Q', 'faq_content' => 'A', 'active' => 1, 'translation_lang' => 'zz']));
        $this->assertDatabaseCount('faq_entries', 0);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.faqs.store'), ['faq_title' => 'Arabic Q', 'faq_content' => 'A', 'active' => 1, 'translation_lang' => 'ar']));
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.faqs.store'), ['faq_title' => 'Global Q', 'faq_content' => 'A', 'active' => 0, 'translation_lang' => '']));
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.faqs.store'), ['faq_title' => 'English Q', 'faq_content' => 'A', 'active' => 1, 'translation_lang' => 'en']));
        $this->assertDatabaseHas('faq_entries', ['faq_title' => 'Global Q', 'translation_lang' => null, 'active' => 0]);

        $res = $this->actingAs($admin)->get(route('admin.faqs.index', ['lang' => 'en']));
        $res->assertOk()->assertViewIs('admin.faqs.index')->assertViewHas('current_language', 'en');
        $titles = $res->viewData('faqs')->pluck('faq_title')->sort()->values()->all();
        $this->assertSame(['English Q', 'Global Q'], $titles);

        $faq = Faq::where('faq_title', 'English Q')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.faqs.edit', $faq))->assertOk()->assertViewHas('faq');
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.faqs.update', $faq), ['faq_title' => '', 'faq_content' => 'A', 'active' => 1]));
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.faqs.update', $faq), ['faq_title' => 'Edited', 'faq_content' => '<p>B</p>', 'active' => 0, 'translation_lang' => 'ar']));
        $this->assertDatabaseHas('faq_entries', ['id' => $faq->id, 'faq_title' => 'Edited', 'faq_content' => '<p>B</p>', 'active' => 0, 'translation_lang' => 'ar']);

        $this->actingAs($admin)->get(route('admin.faqs.show', $faq))->assertNotFound();

        $this->actingAs($admin)->from(route('admin.faqs.index', ['lang' => 'en']))
            ->delete(route('admin.faqs.destroy', $faq))
            ->assertRedirect(route('admin.faqs.index', ['lang' => 'en']));
        $this->assertDatabaseMissing('faq_entries', ['id' => $faq->id]);
    }

    // ── Testimonials ───────────────────────────────────────────────────────

    public function test_testimonials_admin_only(): void
    {
        Testimonial::query()->delete(); // the create migration seeds demo rows
        $t = Testimonial::create(['name' => 'N', 'designation' => 'D', 'content' => 'C', 'image' => 'default_user.png']);
        $this->assertAdminOnly('get', route('admin.testimonials.index'));
        $this->assertAdminOnly('post', route('admin.testimonials.store'), ['name' => 'N', 'designation' => 'D', 'content' => 'C']);
        $this->assertAdminOnly('post', route('admin.testimonials.delete'), ['ids' => [$t->id]]);
        $this->assertAdminOnly('delete', route('admin.testimonials.destroy', $t));
        $this->assertDatabaseCount('testimonials', 1);
    }

    public function test_testimonials_list_create_update_delete(): void
    {
        $admin = $this->admin();
        Testimonial::query()->delete(); // the create migration seeds demo rows

        $this->actingAs($admin)->get(route('admin.testimonials.index'))->assertOk()->assertViewIs('admin.testimonials.index');
        $this->actingAs($admin)->get(route('admin.testimonials.create'))->assertOk()->assertViewIs('admin.testimonials.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.testimonials.store'), ['name' => 'Ann', 'designation' => '', 'content' => 'Great']));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.testimonials.store'), ['name' => 'Ann', 'designation' => 'Chef', 'content' => str_repeat('x', 256)]));
        $this->assertDatabaseCount('testimonials', 0);

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.testimonials.store'), [
            'name' => 'Ann', 'designation' => 'Chef', 'content' => 'Great', 'translations' => ['ar' => ['content' => 'رائع']],
        ]));
        $ann = Testimonial::where('name', 'Ann')->firstOrFail();
        $this->assertSame('default_user.png', $ann->image);
        $this->assertSame('رائع', $ann->translations->ar->content);

        Testimonial::create(['name' => 'Bob', 'designation' => 'Owner', 'content' => 'Nice', 'image' => 'default_user.png']);

        $rows = $this->actingAs($admin)->dataTable(route('admin.testimonials.index'), 1, 'asc')->assertOk()
            ->assertJson(['recordsTotal' => 2])->json('data');
        $this->assertStringContainsString('Ann', $rows[0][0]);
        $this->assertStringContainsString('Chef', $rows[0][0]);
        $this->assertStringContainsString('storage/testimonials/default_user.png', $rows[0][0]);
        $this->assertStringContainsString('Great', $rows[0][1]);
        $this->assertStringContainsString(route('admin.testimonials.edit', $ann->id), $rows[0][2]);
        $this->assertStringContainsString('quick-check', $rows[0][3]);
        $this->assertCount(1, $this->actingAs($admin)->dataTable(route('admin.testimonials.index'), 1, 'asc', 'Bo')->json('data'));

        $this->actingAs($admin)->get(route('admin.testimonials.edit', $ann))->assertOk()->assertViewHas('testimonial');
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.testimonials.update', $ann), ['name' => '', 'designation' => 'Chef', 'content' => 'Great']));
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.testimonials.update', $ann), ['name' => 'Anna', 'designation' => 'Head chef', 'content' => 'Superb']));
        $ann->refresh();
        $this->assertSame(['Anna', 'Head chef', 'Superb', 'default_user.png'], [$ann->name, $ann->designation, $ann->content, $ann->image]);

        $this->actingAs($admin)->get(route('admin.testimonials.show', $ann))->assertRedirect(route('admin.testimonials.index'));

        $this->assertPanelOk($this->actingAs($admin)->post(route('admin.testimonials.delete'), ['ids' => [$ann->id]]));
        $this->assertDatabaseMissing('testimonials', ['id' => $ann->id]);

        $bob = Testimonial::where('name', 'Bob')->firstOrFail();
        $this->actingAs($admin)->from(route('admin.testimonials.index'))->delete(route('admin.testimonials.destroy', $bob))
            ->assertRedirect(route('admin.testimonials.index'));
        $this->assertDatabaseCount('testimonials', 0);
    }

    // ── Newsletter subscribers ─────────────────────────────────────────────

    public function test_subscribers_admin_only(): void
    {
        $s = Subscriber::create(['email' => 'a@example.test', 'joined' => now()]);
        $this->assertAdminOnly('get', route('admin.subscriber.index'));
        $this->assertAdminOnly('post', route('admin.subscriber.store'), ['email' => 'b@example.test']);
        $this->assertAdminOnly('put', route('admin.subscriber.update', $s), ['email' => 'c@example.test']);
        $this->assertAdminOnly('delete', route('admin.subscriber.destroy', $s));
        $this->assertDatabaseHas('newsletter_subscribers', ['id' => $s->id, 'email' => 'a@example.test']);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_subscribers_list_create_update_destroy(): void
    {
        $admin = $this->admin();
        $taken = Subscriber::create(['email' => 'taken@example.test', 'joined' => now()]);

        $this->actingAs($admin)->get(route('admin.subscriber.index'))->assertOk()->assertViewHas('subscribers');
        $this->actingAs($admin)->get(route('admin.subscriber.create'))->assertOk()->assertViewIs('admin.subscriber.create');

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.subscriber.store'), ['email' => 'not-an-email']));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.subscriber.store'), ['email' => 'taken@example.test']));
        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.subscriber.store'), ['email' => 'new@example.test']));
        $new = Subscriber::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame(now()->toDateString(), substr((string) $new->joined, 0, 10));

        $this->actingAs($admin)->get(route('admin.subscriber.edit', $new))->assertOk()->assertViewHas('subscriber');
        $this->assertPanelFails($this->actingAs($admin)->panelPut(route('admin.subscriber.update', $new), ['email' => 'taken@example.test']));
        $this->assertPanelOk($this->actingAs($admin)->panelPut(route('admin.subscriber.update', $new), ['email' => 'renamed@example.test']));
        $this->assertSame('renamed@example.test', $new->fresh()->email);

        $this->actingAs($admin)->get(route('admin.subscriber.show', $new))->assertNotFound();

        $this->actingAs($admin)->from(route('admin.subscriber.index'))->delete(route('admin.subscriber.destroy', $taken))
            ->assertRedirect(route('admin.subscriber.index'));
        $this->assertDatabaseMissing('newsletter_subscribers', ['id' => $taken->id]);
    }

    // ── Rewrite guarantees (WI-08) ─────────────────────────────────────────

    public function test_subscriber_can_be_saved_with_its_own_address(): void
    {
        $s = Subscriber::create(['email' => 'same@example.test', 'joined' => now()]);
        $this->assertPanelOk($this->actingAs($this->admin())->panelPut(route('admin.subscriber.update', $s), ['email' => 'same@example.test']));
    }

    public function test_list_cells_escape_stored_text(): void
    {
        Testimonial::query()->delete();
        Testimonial::create(['name' => '<b>Eve</b>', 'designation' => 'D', 'content' => '<script>x</script>', 'image' => 'default_user.png']);

        $row = $this->actingAs($this->admin())->dataTable(route('admin.testimonials.index'))->json('data.0');
        $this->assertStringContainsString('&lt;b&gt;Eve&lt;/b&gt;', $row[0]);
        $this->assertStringNotContainsString('<script>', $row[1]);
    }

    public function test_datatable_ignores_unknown_sort_columns_and_directions(): void
    {
        Allergy::create(['title' => 'Nuts', 'active' => '1', 'position' => 0]);

        $this->actingAs($this->admin())->dataTable(route('admin.allergies.index'), 9, 'sideways')
            ->assertOk()->assertJson(['recordsTotal' => 1, 'recordsFiltered' => 1]);
    }

    public function test_bulk_delete_without_ids_is_refused(): void
    {
        Allergy::create(['title' => 'Nuts', 'active' => '1', 'position' => 0]);
        $this->assertPanelFails($this->actingAs($this->admin())->post(route('admin.allergies.delete'), []));
        $this->assertDatabaseCount('allergies', 1);
    }

    public function test_policies_allow_only_superadmins(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();
        $allergy = Allergy::create(['title' => 'Nuts', 'active' => '1', 'position' => 0]);

        // The platform tax catalogue (admin.taxes) is part of an edition package's Billing: its policy exists with it.
        foreach ([Allergy::class, Faq::class, Subscriber::class, Testimonial::class, ...(Route::has('admin.taxes.index') ? [Tax::class] : [])] as $model) {
            foreach (['viewAny', 'create', 'deleteAny', 'reorder'] as $ability) {
                $this->assertTrue($admin->can($ability, $model), "$ability $model admin");
                $this->assertFalse($user->can($ability, $model), "$ability $model user");
            }
        }
        $this->assertTrue($admin->can('update', $allergy));
        $this->assertFalse($user->can('update', $allergy));
        $this->assertFalse($user->can('delete', $allergy));
    }
}
