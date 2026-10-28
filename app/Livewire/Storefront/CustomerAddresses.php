<?php

namespace App\Livewire\Storefront;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CustomerAddresses extends Component
{
    public bool $showModal = false;

    public ?int $editingAddressId = null;

    public string $address_title = '';

    public string $recipient_name = '';

    public string $recipient_phone = '';

    public string $street_address = '';

    public string $city = 'Dhaka';

    public string $postal_code = '1205';

    public bool $is_default = false;

    public string $feedbackMessage = '';

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirectRoute('catalog.index');
        }
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingAddressId = null;
        $this->address_title = '';
        $this->recipient_name = Auth::user()?->name ?? '';
        $this->recipient_phone = Auth::user()?->phone ?? '';
        $this->street_address = '';
        $this->city = 'Dhaka';
        $this->postal_code = '1205';
        $this->is_default = Auth::user()?->addresses()->count() === 0;
        $this->showModal = true;
    }

    public function editAddress(int $id): void
    {
        $this->resetValidation();
        $address = Auth::user()->addresses()->findOrFail($id);

        $this->editingAddressId = $address->id;
        $this->address_title = $address->address_title ?? '';
        $this->recipient_name = $address->recipient_name;
        $this->recipient_phone = $address->recipient_phone;
        $this->street_address = $address->street_address;
        $this->city = $address->city;
        $this->postal_code = $address->postal_code;
        $this->is_default = (bool) $address->is_default;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->editingAddressId = null;
    }

    public function saveAddress(): void
    {
        $this->validate([
            'address_title' => 'required|string|max:100',
            'recipient_name' => 'required|string|min:3|max:255',
            'recipient_phone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
            'street_address' => 'required|string|min:5|max:500',
            'city' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'is_default' => 'boolean',
        ], [
            'recipient_phone.regex' => 'Please enter a valid Bangladeshi phone number.',
        ]);

        $user = Auth::user();

        if ($this->is_default) {
            $user->addresses()->update(['is_default' => false]);
        }

        if ($this->editingAddressId) {
            $address = $user->addresses()->findOrFail($this->editingAddressId);
            $address->update([
                'address_title' => $this->address_title,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'street_address' => $this->street_address,
                'city' => $this->city,
                'postal_code' => $this->postal_code,
                'is_default' => $this->is_default,
            ]);
            $this->feedbackMessage = 'Address updated successfully.';
        } else {
            $user->addresses()->create([
                'address_title' => $this->address_title,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'street_address' => $this->street_address,
                'city' => $this->city,
                'postal_code' => $this->postal_code,
                'is_default' => $this->is_default || ($user->addresses()->count() === 0),
            ]);
            $this->feedbackMessage = 'New address added to your address book.';
        }

        $this->closeModal();
    }

    public function setDefault(int $id): void
    {
        $user = Auth::user();
        $user->addresses()->update(['is_default' => false]);
        $user->addresses()->where('id', $id)->update(['is_default' => true]);

        $this->feedbackMessage = 'Default delivery address updated.';
    }

    public function deleteAddress(int $id): void
    {
        $user = Auth::user();
        $address = $user->addresses()->findOrFail($id);
        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault && $user->addresses()->exists()) {
            $user->addresses()->first()->update(['is_default' => true]);
        }

        $this->feedbackMessage = 'Address removed from your address book.';
    }

    public function render()
    {
        $addresses = Auth::check()
            ? Auth::user()->addresses()->orderByDesc('is_default')->latest()->get()
            : collect();

        return view('livewire.storefront.customer-addresses', [
            'addresses' => $addresses,
        ]);
    }
}
