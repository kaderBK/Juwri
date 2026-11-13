use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Settings\StoreSettings;
use App\Models\StoreOption;
use Tests\Support\StorefrontApi\ApiFixtures;

$store = Store::where('slug', 'fresh-mart-demo')->first();
if (! $store) { $store = (new ApiFixtures)->freshMartGrocery(); }
// the KitDemoSeeder Fresh Mart (if that is the store that exists) gets the grocery set-up the API fixture has: weight steps for the
// tomatoes, the "out of stock" choice, hourly delivery slots with an express fee, a delivery fee (all idempotent)
$grocery = app(\Juwri\Modules\Menu\Contracts\GroceryCatalog::class);
$tomatoes = \Juwri\Modules\Menu\Models\Menu::query()->where('store_id', $store->id)->where('name', 'like', 'Tomatoes%')->first();
if ($tomatoes) { $grocery->syncWeightSteps($tomatoes, [250, 500, 1000, 2000]); }
$grocery->saveSubstitutionGroup($store, "If it's out of stock", [
    ['title' => 'Replace with similar', 'action' => 'offer_replacement'],
    ['title' => 'Call me', 'action' => 'call_customer', 'fallback' => 'remove_item'],
    ['title' => 'Remove it', 'action' => 'remove_item'],
]);
if (! \Juwri\Modules\Ordering\Models\DeliverySlotRule::query()->where('store_id', $store->id)->exists()) {
    \Juwri\Modules\Ordering\Models\DeliverySlotRule::create([
        'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => null, 'active' => true,
        'windows' => [['00:00', '23:59']], 'capacity_per_hour' => 8, 'lead_minutes' => 10, 'days_ahead' => 1,
        'express_fee_minor' => 500, 'express_eta_from' => 25, 'express_eta_to' => 35, 'currency_code' => 'SAR',
    ]);
}
StoreSettings::setMany((int) $store->id, ['restaurant_delivery_order' => true, 'restaurant_delivery_charge' => '9']);
// open round the clock so the journey works at any hour
$all = []; foreach (['sun','mon','tue','wed','thu','fri','sat'] as $d) { $all[$d] = ['open' => '00:00', 'close' => '23:59', 'closed' => false]; }
\Juwri\Core\Models\Branch::query()->where('store_id', $store->id)->get()->each(fn ($b) => $b->forceFill(['hours' => $all])->save());
User::whereKey($store->user_id)->update(['status' => 1, 'email_verified_at' => now()]);
EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
StoreOption::updateStoreOption($store->id, 'store_template', 'basket');
StoreSettings::setMany((int) $store->id, ['fiscal_country' => 'SA', 'timezone' => 'Asia/Riyadh', 'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1', 'refunds_weighed_difference' => true]);
\Illuminate\Support\Facades\Cache::flush();
echo 'STORE '.$store->id."\n";
