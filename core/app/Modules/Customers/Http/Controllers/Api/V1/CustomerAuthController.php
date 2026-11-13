<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * Token-issuing auth for the standalone customer app. Mirrors the session-based
 * User\CustomerAuthController but returns a bearer token instead of starting a
 * session. Customers are scoped per restaurant (store_id in the path).
 */
class CustomerAuthController extends Controller
{
    /** POST /stores/{id}/customer/register */
    public function register(Request $request, int $storeId)
    {
        $this->assertRestaurant($storeId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => ['required', 'string', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'birthday' => 'nullable|date',
            'device' => 'nullable|string|max:80',
        ]);

        $exists = Customer::where('store_id', $storeId)->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['email']))])->exists();
        if ($exists) {
            return response()->json(['error' => 'Email already registered for this restaurant.'], 409);
        }

        $customer = Customer::create([
            'store_id' => $storeId,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'marketing_consent' => $request->boolean('marketing_consent'),
            'sms_consent' => $request->boolean('sms_consent'),
            'birthday' => $data['birthday'] ?? null,
            'source' => 'customer_app',
        ]);

        return $this->tokenResponse($customer, $data['device'] ?? null, 201);
    }

    /** POST /stores/{id}/customer/login */
    public function login(Request $request, int $storeId)
    {
        $this->assertRestaurant($storeId);

        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:80',
        ]);

        $customer = Customer::where('store_id', $storeId)->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['email']))])->first();

        if (! $customer || ! $customer->password || ! Hash::check($data['password'], $customer->password)) {
            return response()->json(['error' => 'Invalid credentials.'], 401);
        }

        return $this->tokenResponse($customer, $data['device'] ?? null);
    }

    /** POST /stores/{id}/customer/otp/request */
    public function otpRequest(Request $request, int $storeId)
    {
        $this->assertRestaurant($storeId);
        // Scope = the store in the URL (resolved above), so a code from another store never logs in here.
        $request->validate(['phone' => 'required|string|max:20']);

        [$ok, $message] = app(SmsOtp::class)->request($request->phone, OtpScope::store($storeId));

        return response()->json(['success' => $ok, 'message' => $message], $ok ? 200 : 429);
    }

    /** POST /stores/{id}/customer/otp/verify — verify code, find/create, issue token. */
    public function otpVerify(Request $request, int $storeId)
    {
        $this->assertRestaurant($storeId);
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'code' => 'required|string',
            'device' => 'nullable|string|max:80',
        ]);

        if (! app(SmsOtp::class)->verify($data['phone'], $data['code'], OtpScope::store($storeId))) {
            return response()->json(['error' => 'Invalid or expired code.'], 401);
        }

        $phone = app(Sms::class)->normalizePhone($data['phone']);
        $customer = Customer::firstOrCreate(
            ['store_id' => $storeId, 'phone' => $phone],
            ['name' => 'Guest', 'source' => 'customer_app_otp']
        );
        $customer->forceFill(['phone_verified_at' => now()])->save();

        return $this->tokenResponse($customer, $data['device'] ?? null);
    }

    private function assertRestaurant(int $storeId): void
    {
        Store::findOrFail($storeId);
    }

    private function tokenResponse(Customer $customer, ?string $device, int $status = 200)
    {
        $raw = CustomerToken::issue($customer, $device);

        return response()->json([
            'token' => $raw,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'store_id' => $customer->store_id,
            ],
        ], $status);
    }
}
