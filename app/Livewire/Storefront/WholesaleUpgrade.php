<?php

namespace App\Livewire\Storefront;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class WholesaleUpgrade extends Component
{
    public string $businessName = '';

    public string $contactPerson = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $tradeLicense = '';

    public string $tinNumber = '';

    public string $businessType = 'Boutique / Reseller';

    public string $city = 'Dhaka';

    public bool $isSuccess = false;

    public string $errorMessage = '';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user) {
            $this->contactPerson = $user->name;
            $this->email = $user->email;
            $this->phone = $user->phone ?? '';
            $this->businessName = $user->business_name ?? '';
            $this->tradeLicense = $user->trade_license_number ?? '';
            $this->tinNumber = $user->tax_id_number ?? '';
        }
    }

    public function applyForWholesale(): void
    {
        $this->errorMessage = '';

        $rules = [
            'businessName' => 'required|string|min:3|max:255',
            'contactPerson' => 'required|string|min:3|max:255',
            'tradeLicense' => 'required|string|max:128',
            'tinNumber' => 'nullable|string|max:128',
            'city' => 'required|string|max:100',
        ];

        if (! Auth::check()) {
            $rules['email'] = 'required|email|unique:users,email';
            $rules['phone'] = ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/', 'unique:users,phone'];
            $rules['password'] = 'required|string|min:8';
        }

        $this->validate($rules, [
            'phone.regex' => 'Please enter a valid Bangladeshi phone number (e.g., 017XXXXXXXX or +88017XXXXXXXX).',
            'businessName.min' => 'Business / company name must be at least 3 characters.',
            'tradeLicense.required' => 'Bangladeshi Trade License or NID number is required for wholesale verification.',
            'password.min' => 'Password must be at least 8 characters.',
        ]);

        try {
            $wholesaleRole = Role::where('slug', 'wholesale_buyer')->firstOrFail();

            if (Auth::check()) {
                /** @var User $user */
                $user = Auth::user();
                $user->update([
                    'business_name' => $this->businessName,
                    'trade_license_number' => $this->tradeLicense,
                    'tax_id_number' => $this->tinNumber ?: null,
                    'role_id' => $wholesaleRole->id,
                    'is_wholesale_approved' => true,
                ]);
            } else {
                $user = User::create([
                    'role_id' => $wholesaleRole->id,
                    'name' => $this->contactPerson,
                    'business_name' => $this->businessName,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'password' => Hash::make($this->password),
                    'trade_license_number' => $this->tradeLicense,
                    'tax_id_number' => $this->tinNumber ?: null,
                    'is_active' => true,
                    'is_wholesale_approved' => true,
                ]);

                Auth::login($user);
            }

            $this->isSuccess = true;
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.storefront.wholesale-upgrade', [
            'currentUser' => Auth::user(),
        ]);
    }
}
