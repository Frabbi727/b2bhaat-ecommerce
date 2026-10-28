<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    @if ($isSuccess)
        <div class="bg-white rounded-3xl border border-emerald-200 p-8 sm:p-12 text-center space-y-6 shadow-sm">
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>

            <div class="space-y-2">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">Wholesale Partner Account Unlocked!</h2>
                <p class="text-sm text-slate-600 max-w-lg mx-auto leading-relaxed">
                    Welcome to B2B Haat's Verified Business Network, <strong class="text-slate-900">{{ $businessName }}</strong>. You now have full access to factory-direct bulk tier rates and the multi-variation order matrix.
                </p>
            </div>

            <div class="p-6 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-left max-w-md mx-auto space-y-2 text-slate-600">
                <div class="font-bold text-slate-900 uppercase tracking-wider text-[11px] mb-2">Unlocked B2B Features:</div>
                <div class="flex items-center gap-2">
                    <span class="text-emerald-600 font-bold">✓</span>
                    <span>Factory volume discounts and transparent Minimum Order Quantities.</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-emerald-600 font-bold">✓</span>
                    <span>B2B Multi-variation matrix ordering (order multiple colors & sizes at once).</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-emerald-600 font-bold">✓</span>
                    <span>Formal commercial tax invoices & delivery challans with your trade license.</span>
                </div>
            </div>

            <div class="pt-4">
                <a href="{{ route('catalog.index') }}" class="inline-block py-3.5 px-8 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-sm">
                    Enter Factory Wholesale Catalog &rarr;
                </a>
            </div>
        </div>
    @else
        <div class="space-y-8">
            <div class="text-center space-y-3 max-w-2xl mx-auto">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-indigo-100 text-indigo-800 border border-indigo-200">
                    B2B Business Verification
                </span>
                <h1 class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight">Unlock Factory-Direct Wholesale Sourcing</h1>
                <p class="text-sm text-slate-600 leading-relaxed">
                    Designed for boutique owners, beauty salons, resellers, and merchants across Bangladesh. Register your business to unlock factory wholesale tier discounts and bulk matrix ordering.
                </p>
            </div>

            @if ($errorMessage)
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
                    <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errorMessage }}</span>
                </div>
            @endif

            <form wire:submit="applyForWholesale" class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-10 shadow-xs space-y-6">
                <div class="border-b border-slate-100 pb-4">
                    <h2 class="text-base font-bold text-slate-900">Commercial & Trade License Information</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Please provide your business credentials to enable bulk volume pricing.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Company / Shop / Boutique Name *</label>
                        <input 
                            type="text" 
                            wire:model="businessName" 
                            placeholder="e.g. Chic Boutique or Glamour Salon" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('businessName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Contact Person Name *</label>
                        <input 
                            type="text" 
                            wire:model="contactPerson" 
                            placeholder="e.g. Rahima Begum" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('contactPerson') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Trade License Number *</label>
                        <input 
                            type="text" 
                            wire:model="tradeLicense" 
                            placeholder="e.g. TRAD/DNCC/2026/09912" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono"
                        />
                        @error('tradeLicense') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">TIN (Tax Identification Number)</label>
                        <input 
                            type="text" 
                            wire:model="tinNumber" 
                            placeholder="e.g. TIN-9876543210" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono"
                        />
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Business Category *</label>
                        <select wire:model="businessType" class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 bg-white">
                            <option value="Boutique / Reseller">Boutique / Fashion Reseller</option>
                            <option value="Beauty Parlor / Salon">Beauty Parlor / Salon</option>
                            <option value="Cosmetics & General Store">Cosmetics & General Store</option>
                            <option value="Pharmacy / Healthcare">Pharmacy / Healthcare</option>
                            <option value="District Wholesaler">District Wholesaler / Distributor</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Shop / Business District *</label>
                        <input 
                            type="text" 
                            wire:model="city" 
                            placeholder="e.g. Dhaka, Bogura, Sylhet, Rajshahi" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('city') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                @if (! Auth::check())
                    <div class="border-b border-slate-100 pt-4 pb-4">
                        <h2 class="text-base font-bold text-slate-900">Account Credentials</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Create your buyer login credentials.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Email *</label>
                            <input type="email" wire:model="email" placeholder="owner@boutique.com" class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                            @error('email') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Phone Number *</label>
                            <input type="text" wire:model="phone" placeholder="+88017XXXXXXXX" class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                            @error('phone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-700 mb-1">Password *</label>
                            <input type="password" wire:model="password" placeholder="••••••••" class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"/>
                            @error('password') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif

                <div class="pt-4">
                    <button 
                        type="submit" 
                        class="w-full py-4 px-6 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Verify Trade Details & Unlock Wholesale Rates
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
