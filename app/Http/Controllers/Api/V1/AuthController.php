<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerRetail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:32|unique:users,phone',
            'password' => 'required|string|min:8',
        ]);

        $retailRole = Role::where('slug', 'retail_buyer')->firstOrFail();

        $user = User::create([
            'role_id' => $retailRole->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Retail buyer account registered successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'retail_buyer',
            ],
            'token' => $token,
        ], 201);
    }

    public function registerWholesale(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:32|unique:users,phone',
            'password' => 'required|string|min:8',
            'business_name' => 'nullable|string|max:255',
            'trade_license_number' => 'nullable|string|max:128',
            'tax_id_number' => 'nullable|string|max:128',
        ]);

        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->firstOrFail();

        $user = User::create([
            'role_id' => $wholesaleRole->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Wholesale business partner account registered successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'wholesale_buyer',
            ],
            'token' => $token,
        ], 201);
    }

    public function registerSeller(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:32|unique:users,phone',
            'password' => 'required|string|min:8',
            'store_name' => 'required|string|max:255',
            'trade_license_number' => 'nullable|string|max:128',
            'tax_id_number' => 'nullable|string|max:128',
        ]);

        $sellerRole = Role::where('slug', 'seller')->firstOrFail();

        $user = User::create([
            'role_id' => $sellerRole->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => $validated['store_name'],
            'store_slug' => Str::slug($validated['store_name']).'-'.Str::random(4),
            'trade_license_number' => $validated['trade_license_number'] ?? null,
            'tax_id_number' => $validated['tax_id_number'] ?? null,
            'commission_rate_percentage' => 10.00,
            'is_approved' => false, // Requires admin review
        ]);

        SellerWallet::create([
            'seller_id' => $user->id,
            'available_balance' => 0.00,
            'escrow_held_balance' => 0.00,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Seller merchant account registered. Pending KYC verification.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'seller',
                'is_approved' => false,
            ],
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role', 'sellerProfile')->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Authenticated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug,
            ],
            'token' => $token,
        ]);
    }
}
