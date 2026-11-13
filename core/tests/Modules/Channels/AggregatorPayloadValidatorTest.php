<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Tests\TestCase;

/**
 * PARITY-RUN follow-up — pins `aggregator:validate-payload`.
 *
 * The harness only earns trust if it PASSES the shape the driver assumes and
 * FAILS a shape the driver cannot read. Both are asserted, for both providers,
 * plus that a required miss is a non-zero exit (so it can gate a script when a
 * real payload finally arrives).
 */
class AggregatorPayloadValidatorTest extends TestCase
{
    private function write(array $payload): string
    {
        $path = sys_get_temp_dir().'/aggr-'.uniqid().'.json';
        file_put_contents($path, json_encode($payload));

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(sys_get_temp_dir().'/aggr-*.json') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** The shape the Zomato mapper assumes — mirrors ZomatoAggregatorTest::payload(). */
    private function zomatoAssumed(): array
    {
        return [
            'event' => 'order.placed',
            'res_id' => 'ZOM-1',
            'order' => [
                'order_id' => 'Z-1',
                'commission' => 12.0,
                'customer' => ['name' => 'Aarav', 'phone' => '+919876500000', 'address' => '12 MG Road'],
                'dishes' => [['code' => 'zom_10', 'quantity' => 2, 'name' => 'Paneer']],
            ],
        ];
    }

    public function test_the_assumed_zomato_shape_passes(): void
    {
        $file = $this->write($this->zomatoAssumed());

        $this->artisan('aggregator:validate-payload', ['provider' => 'zomato', 'file' => $file])
            ->expectsOutputToContain('All required fields resolved')
            ->assertExitCode(0);
    }

    public function test_a_zomato_payload_with_foreign_field_names_fails_required(): void
    {
        // Different key names for outlet / order / lines — a plausible real schema.
        $file = $this->write([
            'event_type' => 'NEW_ORDER',
            'outlet' => ['zomato_outlet_id' => 'R-1'],
            'order_details' => ['reference_id' => 'ZM-9', 'line_items' => [['merchant_item_id' => 'x', 'count' => 1]]],
        ]);

        $this->artisan('aggregator:validate-payload', ['provider' => 'zomato', 'file' => $file])
            ->expectsOutputToContain('REQUIRED field(s) did not resolve')
            ->assertExitCode(1);
    }

    public function test_the_assumed_swiggy_shape_passes(): void
    {
        // Swiggy's assumed keys DIFFER from Zomato's — restaurant_id not res_id,
        // items not dishes. That the harness distinguishes them is the point.
        $file = $this->write([
            'event_type' => 'NEW_ORDER',
            'restaurant_id' => 'SWG-1',
            'order' => [
                'order_id' => 'S-1',
                'items' => [['item_id' => 'swg_10', 'quantity' => 2, 'name' => 'Dosa']],
                'customer' => ['name' => 'Priya', 'phone' => '+919000000000'],
            ],
        ]);

        $this->artisan('aggregator:validate-payload', ['provider' => 'swiggy', 'file' => $file])
            ->expectsOutputToContain('All required fields resolved')
            ->assertExitCode(0);
    }

    public function test_the_two_providers_do_not_accept_each_others_shape(): void
    {
        // The strongest proof the assumptions are real and distinct: a Zomato
        // payload fails Swiggy validation, because res_id/dishes are not the
        // keys the Swiggy mapper reads.
        $file = $this->write($this->zomatoAssumed());

        $this->artisan('aggregator:validate-payload', ['provider' => 'swiggy', 'file' => $file])
            ->expectsOutputToContain('REQUIRED field(s) did not resolve')
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_unknown_provider(): void
    {
        $file = $this->write($this->zomatoAssumed());

        $this->artisan('aggregator:validate-payload', ['provider' => 'deliveroo', 'file' => $file])
            ->expectsOutputToContain("provider must be")
            ->assertExitCode(2);
    }

    public function test_it_reports_a_missing_file(): void
    {
        $this->artisan('aggregator:validate-payload', ['provider' => 'zomato', 'file' => '/no/such/file.json'])
            ->expectsOutputToContain('File not found')
            ->assertExitCode(1);
    }

    public function test_it_rejects_non_object_json(): void
    {
        $path = sys_get_temp_dir().'/aggr-'.uniqid().'.json';
        file_put_contents($path, '"just a string"');

        $this->artisan('aggregator:validate-payload', ['provider' => 'zomato', 'file' => $path])
            ->expectsOutputToContain('not a JSON object')
            ->assertExitCode(1);
    }
}
