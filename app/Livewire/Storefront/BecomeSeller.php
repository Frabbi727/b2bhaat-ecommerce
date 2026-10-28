<?php

namespace App\Livewire\Storefront;

use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class BecomeSeller extends Component
{
    public string $storeName = '';

    public string $ownerName = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $tradeLicense = '';

    public string $tinNumber = '';

    public string $city = 'Dhaka';

    public string $businessAddress = '';

    public bool $isSubmitted = false;

    public string $errorMessage = '';

    public function registerStore(): void
    {
        $this->errorMessage = '';

        $this->validate([
            'storeName' => 'required|string|min:3|max:255',
            'ownerName' => 'required|string|min:3|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/', 'unique:users,phone'],
            'password' => 'required|string|min:8',
            'tradeLicense' => 'nullable|string|max:128',
            'tinNumber' => 'nullable|string|max:128',
            'city' => 'required|string|max:100',
            'businessAddress' => 'nullable|string',
        ], [
            'phone.regex' => 'Please enter a valid Bangladeshi phone number (e.g. 017XXXXXXXX or +88017XXXXXXXX).',
            'storeName.min' => 'Store or factory name must be at least 3 characters.',
            'ownerName.min' => 'Owner or representative name must be at least 3 characters.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        try {
            $sellerRole = Role::where('slug', 'seller')->firstOrFail();

            $user = User::create([
                'role_id' => $sellerRole->id,
                'name' => $this->ownerName,
                'email' => $this->email,
                'phone' => $this->phone,
                'password' => Hash::make($this->password),
                'is_active' => true,
            ]);

            SellerProfile::create([
                'user_id' => $user->id,
                'store_name' => $this->storeName,
                'store_slug' => Str::slug($this->storeName).'-'.Str::random(4),
                'trade_license_number' => $this->tradeLicense ?: null,
                'tax_id_number' => $this->tinNumber ?: null,
                'commission_rate_percentage' => 10.00,
                'is_approved' => false, // Under admin review
            ]);

            SellerWallet::create([
                'seller_id' => $user->id,
                'available_balance' => 0.00,
                'escrow_held_balance' => 0.00,
            ]);

            $this->isSubmitted = true;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.storefront.become-seller');
    }
}
