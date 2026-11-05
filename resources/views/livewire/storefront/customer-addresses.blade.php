<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('catalog.index') }}" class="hover:underline">Shop</a>
        <span>/</span>
        <a href="{{ route('customer.orders') }}" class="hover:underline">My account</a>
        <span>/</span>
        <span class="text-slate-800 font-semibold">Addresses</span>
    </nav>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                My addresses
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                Save the places you get deliveries, so checkout is faster. Couriers use these to find you.
            </p>
        </div>
        <div>
            <button 
                type="button" 
                wire:click="openCreateModal"
                class="px-4 py-2.5 rounded-xl font-bold text-xs bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm transition flex items-center gap-1.5 cursor-pointer"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Add address</span>
            </button>
        </div>
    </div>

    <!-- Flash Feedback -->
    @if ($feedbackMessage)
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <button type="button" wire:click="$set('feedbackMessage', '')" class="text-emerald-600 hover:text-emerald-800 text-sm font-bold">&times;</button>
        </div>
    @endif

    <!-- Address Cards Grid -->
    @if ($addresses->isEmpty())
        <div class="p-12 text-center rounded-2xl border-2 border-dashed border-slate-200 bg-white">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto mb-3 text-xl">
                📍
            </div>
            <h3 class="font-bold text-slate-800 text-sm">No saved addresses yet</h3>
            <p class="text-xs text-slate-500 max-w-sm mx-auto mt-1 mb-4">
                Add your home, shop or office address to check out faster next time.
            </p>
            <button 
                type="button" 
                wire:click="openCreateModal"
                class="px-4 py-2 rounded-xl text-xs font-bold bg-indigo-600 text-white hover:bg-indigo-700 transition"
            >
                + Add Your First Address
            </button>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach ($addresses as $addr)
                <div class="p-5 rounded-2xl border bg-white shadow-xs space-y-4 flex flex-col justify-between transition relative {{ $addr->is_default ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300' }}">
                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-sm text-slate-900">{{ $addr->address_title }}</span>
                                @if ($addr->is_default)
                                    <span class="px-2 py-0.5 rounded text-[10px] font-black bg-indigo-100 text-indigo-700 uppercase tracking-wider">
                                        Default
                                    </span>
                                @endif
                            </div>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded {{ strtolower($addr->city) === 'dhaka' ? 'bg-emerald-50 text-emerald-700 font-bold' : 'bg-slate-100 text-slate-700' }}">
                                {{ strtolower($addr->city) === 'dhaka' ? 'Inside Dhaka (৳70)' : 'Outside Dhaka (৳130)' }}
                            </span>
                        </div>

                        <div class="text-xs space-y-1">
                            <div class="font-bold text-slate-800">{{ $addr->recipient_name }}</div>
                            <div class="text-slate-500 font-mono">{{ $addr->recipient_phone }}</div>
                            <p class="text-slate-600 leading-relaxed pt-1">
                                {{ $addr->street_address }}<br>
                                <span class="font-semibold">{{ $addr->city }}{{ filled($addr->postal_code) ? ' - '.$addr->postal_code : '' }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-xs">
                        <div>
                            @if (! $addr->is_default)
                                <button 
                                    type="button" 
                                    wire:click="setDefault({{ $addr->id }})"
                                    class="text-slate-500 hover:text-indigo-600 font-medium hover:underline text-[11px]"
                                >
                                    Make default
                                </button>
                            @else
                                <span class="text-indigo-600 font-bold text-[11px] flex items-center gap-1">
                                    ✓ Primary Address
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-3">
                            <button 
                                type="button" 
                                wire:click="editAddress({{ $addr->id }})"
                                class="text-indigo-600 hover:text-indigo-800 font-bold hover:underline cursor-pointer"
                            >
                                Edit
                            </button>
                            <span class="text-slate-300">|</span>
                            <button 
                                type="button" 
                                wire:click="deleteAddress({{ $addr->id }})"
                                wire:confirm="Are you sure you want to delete this address from your address book?"
                                class="text-rose-500 hover:text-rose-700 font-bold hover:underline cursor-pointer"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Create / Edit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-5 shadow-2xl relative">
                <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            {{ $editingAddressId ? 'Edit Delivery Address' : 'Add New Delivery Address' }}
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Couriers use this to find you.
                        </p>
                    </div>
                    <button 
                        type="button" 
                        wire:click="closeModal"
                        class="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 text-lg leading-none"
                    >
                        &times;
                    </button>
                </div>

                <form wire:submit="saveAddress" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Name for this address *</label>
                        <input 
                            type="text" 
                            wire:model="address_title" 
                            placeholder="e.g. Home, Shop, Office" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('address_title') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Full name *</label>
                            <input 
                                type="text" 
                                wire:model="recipient_name" 
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            />
                            @error('recipient_name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Mobile number *</label>
                            <input 
                                type="text" 
                                wire:model="recipient_phone" 
                                placeholder="e.g. 01712345678"
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            />
                            @error('recipient_phone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Address (house, road, area) *</label>
                        <textarea 
                            rows="2" 
                            wire:model="street_address" 
                            placeholder="House, road, area and a nearby landmark" 
                            class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        ></textarea>
                        @error('street_address') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="address-district" class="block font-semibold text-slate-700 mb-1">District *</label>
                            <select 
                                id="address-district"
                                wire:model="city" 
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl bg-white focus:ring-2 focus:ring-indigo-500"
                            >
                                <option value="">Choose your district</option>
                                @if ($city !== '' && ! in_array($city, $districts, true))
                                    <option value="{{ $city }}">{{ $city }}</option>
                                @endif
                                @foreach ($districts as $district)
                                    <option value="{{ $district }}">{{ $district }}</option>
                                @endforeach
                            </select>
                            @error('city') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Postal code (optional)</label>
                            <input 
                                type="text" 
                                wire:model="postal_code" 
                                placeholder="e.g. 1205"
                                class="w-full py-2 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                            />
                            @error('postal_code') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="pt-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input 
                                type="checkbox" 
                                wire:model="is_default" 
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span class="font-bold text-slate-800">Use this as my default address</span>
                        </label>
                    </div>

                    <div class="pt-4 flex items-center justify-end gap-3 border-t border-slate-100">
                        <button 
                            type="button" 
                            wire:click="closeModal" 
                            class="px-4 py-2 rounded-xl border border-slate-300 text-slate-700 font-bold hover:bg-slate-50 transition"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            wire:loading.attr="disabled"
                            class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-sm transition disabled:opacity-50"
                        >
                            <span wire:loading.remove>Save address</span>
                            <span wire:loading>Saving…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
