use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\MenuVariant;
use App\Models\StoreOption;
use Tests\Support\StorefrontApi\ApiFixtures;

$store = Store::where('slug', 'dune-and-co-demo')->first();
if (! $store) { $store = (new ApiFixtures)->duneRetail(); }
User::whereKey($store->user_id)->update(['status' => 1, 'email_verified_at' => now()]);
EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
StoreOption::updateStoreOption($store->id, 'store_template', 'aisle');
StoreSettings::setMany((int) $store->id, ['fiscal_country' => 'SA', 'timezone' => 'Asia/Riyadh', 'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1', 'returns_window_days' => 14]);
foreach (['019e8a90-b580-7d21-8841-c8ec7cc5c1e6' => 0, '019f41d0-5480-70e0-a226-a4286dfc5326' => 20, ApiFixtures::SHIRT_SAND_M => 2, '019ef234-b100-7756-84f0-2bc45f0c4e7e' => 30, '019fe81c-5a00-771a-ae9b-4d2ee36f1b7b' => null, ApiFixtures::TROUSERS_NAVY_M => 12] as $pid => $qty) {
    MenuVariant::query()->where('public_id', $pid)->update(['stock_qty' => $qty]);
}
\Illuminate\Support\Facades\Cache::flush();
echo 'STORE '.$store->id."\n";
