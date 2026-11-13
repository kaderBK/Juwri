<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Storefront\StorefrontOrderingService;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\BrewCart;
use Tests\Support\PinsMiddayClock;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-07 (REVIEW Opus: order data path): the per-line note ("Name on the cup") that a guest types on the brew item sheet travels
 * Order line -> KDS ticket -> kitchen print -> receipts (tracking page, POS receipt, thermal receipt) and is
 * cleaned of control characters on the way in, kept out of every markup sink on the way out, and never doubles the
 * choice lines the checkout folds into the same column.
 */
class BrewLineNoteTest extends TestCase
{
    use PinsMiddayClock;
    use PinsMiddayClock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(KitDemoSeeder::class);
        StoreOption::updateStoreOption((int) Store::where('slug', 'bloom-coffee-demo')->value('id'), 'store_template', 'brew');
    }

    private function scenario(): ViewModelScenario
    {
        return new ViewModelScenario(Store::where('slug', 'bloom-coffee-demo')->firstOrFail(), 'brew');
    }

    /**
     * Place the demo cart (first line: an item with choices, qty 2; second: a plain item) through the storefront checkout with a
     * note on the first line.
     */
    private function place(string $note, array $extra = []): Order
    {
        $s = $this->scenario();
        $cart = [BrewCart::flatWhite($s, 2), BrewCart::plain($s)];
        $cart[0]['note'] = $note;
        $this->postJson(route('store.submitOrder', $s->store), $extra + [
            'lines' => $cart, 'order_type' => 'takeaway', 'name' => 'Sam', 'phone-number' => '+966500000001',
            'pay_via' => 'cash', 'client_order_id' => (string) Str::uuid(),
        ])->assertOk()->assertJson(['success' => true]);

        return Order::where('store_id', $s->store->id)->latest('id')->firstOrFail()->load('items.menu', 'items.itemExtras.extra', 'items.variant');
    }

    private function line(Order $order, int $index = 0): OrderItem
    {
        return $order->items->sortBy('id')->values()[$index];
    }

    public function test_the_note_is_stored_on_the_line_it_was_typed_on_and_only_that_line(): void
    {
        $order = $this->place('Sara');
        $this->assertStringContainsString('Sara', (string) $this->line($order, 0)->order_note);
        $this->assertStringNotContainsString('Sara', (string) $this->line($order, 1)->order_note);
        $this->assertSame('Sara', $this->line($order, 0)->customerNote(), 'the guest note, without the folded choice lines');
    }

    public function test_the_kitchen_sees_the_note_on_the_kds_ticket_and_the_printed_ticket(): void
    {
        $order = $this->place('Sara');
        $order->forceFill(['status' => 'new'])->save();
        $owner = User::findOrFail(Store::findOrFail($order->store_id)->user_id);

        $this->withoutMiddleware();
        $poll = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $order->store_id]))->assertOk()->json();
        $ticket = collect($poll)->firstWhere('id', $order->id);
        $this->assertNotNull($ticket, 'the order is on the KDS board');
        $notes = collect($ticket['items'])->pluck('note')->filter()->implode("\n");
        $this->assertStringContainsString('Sara', $notes, 'the KDS ticket carries the line note');

        $kds = (string) file_get_contents(base_path('resources/views/owner/kitchen/kds.blade.php'));
        $this->assertStringContainsString('x-text="item.note"', $kds, 'the ticket prints the note as text, never as markup');

        $kot = (new EscPosRenderer(48))->renderKot($order);
        $this->assertStringContainsString('! Sara', $kot);

        $ticketHtml = view('staff.pos.ticket', ['order' => $order, 'station' => ''])->render();
        $this->assertStringContainsString('★ ', $ticketHtml);
        $this->assertStringContainsString('Sara', $ticketHtml);
    }

    public function test_the_receipts_carry_the_note_once_without_repeating_the_choices(): void
    {
        $order = $this->place('Sara');
        $line = $this->line($order, 0);
        $this->assertNotSame([], (array) $line->options, 'the first line has choices (folded into order_note for the kitchen)');
        $this->assertStringContainsString("\n", (string) $line->order_note, 'the stored column holds the note and the folded choice lines');

        // thermal receipt
        $escpos = (new EscPosRenderer(48))->renderReceipt($order);
        $this->assertSame(1, substr_count($escpos, '! Sara'), 'the thermal receipt prints the note once');
        $folded = (string) ($line->options[0]['title'] ?? '').': '.(string) ($line->options[0]['selected'][0]['option'] ?? '');
        $this->assertStringContainsString($folded, (string) $line->order_note, 'the kitchen column holds the folded choice line');
        $this->assertStringNotContainsString($folded, $escpos, 'the receipt does not repeat it as note text');
        $firstChoice = (string) ($line->options[0]['selected'][0]['option'] ?? '');

        // POS paper receipt
        $options = store_options((int) $order->store_id);
        $html = view('staff.pos.receipt', ['order' => $order, 'store' => Store::findOrFail($order->store_id), 'options' => $options, 'station' => ''])->render();
        $this->assertSame(1, substr_count($html, 'Sara'));
        $this->assertStringNotContainsString(': '.$firstChoice.' (+', $html, 'no folded choice line under the item');

        // the customer's tracking page (brew pickup ticket): the receipt line shows the note
        $res = $this->get(route('store.orderTrack', ['token' => $order->track_token]))->assertOk();
        $this->assertStringContainsString('data-theme-slug="brew"', $res->getContent());
        $this->assertStringContainsString('<p class="c-line__note">Sara</p>', $res->getContent());
    }

    public function test_the_note_is_escaped_wherever_it_is_shown(): void
    {
        $evil = '<img src=x onerror=alert(1)>"';
        $order = $this->place($evil);
        $track = $this->get(route('store.orderTrack', ['token' => $order->track_token]))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x', $track);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $track);

        $options = store_options((int) $order->store_id);
        $receipt = view('staff.pos.receipt', ['order' => $order, 'store' => Store::findOrFail($order->store_id), 'options' => $options, 'station' => ''])->render();
        $this->assertStringNotContainsString('<img src=x', $receipt);
        $ticket = view('staff.pos.ticket', ['order' => $order, 'station' => ''])->render();
        $this->assertStringNotContainsString('<img src=x', $ticket);
    }

    public function test_control_characters_never_reach_the_database_or_a_printer(): void
    {
        $order = $this->place("Sa\x1B@ra\x00\r\nSECOND\x07\u{202E}LINE");
        $note = $this->line($order, 0)->customerNote();
        $this->assertSame('Sa @ra SECOND LINE', $note, 'control, bidi and line-break characters become one space');
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', (string) $this->line($order, 0)->order_note);

        foreach ([(new EscPosRenderer(48))->renderReceipt($order), (new EscPosRenderer(48))->renderKot($order)] as $bytes) {
            $this->assertStringNotContainsString("Sa\x1B@ra", $bytes, 'a guest cannot send a printer command inside a note');
        }
    }

    public function test_a_note_is_cut_at_255_characters_and_a_blank_one_is_no_note(): void
    {
        $this->assertSame(255, mb_strlen(StorefrontOrderingService::cleanNote(str_repeat('é', 300))));
        $this->assertSame('', StorefrontOrderingService::cleanNote("  \t \n "));
        $this->assertSame('', StorefrontOrderingService::cleanNote(null));
        $this->assertSame('', StorefrontOrderingService::cleanNote(['x']));

        $order = $this->place('   ');
        $this->assertSame('', $this->line($order, 0)->customerNote());
    }

    public function test_a_quote_with_a_note_costs_the_same_as_one_without(): void
    {
        $s = $this->scenario();
        $cart = [BrewCart::flatWhite($s, 2), BrewCart::plain($s)];
        $plain = $this->postJson("/{$s->store->slug}/quote", ['lines' => $cart, 'order_type' => 'takeaway'])->assertOk()->json('quote.total.minor');
        $cart[0]['note'] = 'Sara';
        $noted = $this->postJson("/{$s->store->slug}/quote", ['lines' => $cart, 'order_type' => 'takeaway'])->assertOk()->json('quote.total.minor');
        $this->assertSame($plain, $noted, 'a note is not money');
    }
}
