<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    @if ($isSubmitted)
        <div class="bg-white rounded-3xl border border-slate-200 p-8 sm:p-12 text-center space-y-6 shadow-sm">
            <div class="w-20 h-20 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>

            <div class="space-y-2">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">Application Submitted Successfully!</h2>
                <p class="text-sm text-slate-600 max-w-lg mx-auto leading-relaxed">
                    Thank you for applying to sell on <strong class="text-indigo-600">B2B Haat</strong>. Your factory / merchant profile for <strong class="text-slate-900">{{ $storeName }}</strong> has been queued for Super Admin KYC verification.
                </p>
            </div>

            <div class="p-6 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-left max-w-md mx-auto space-y-2 text-slate-600">
                <div class="font-bold text-slate-900 uppercase tracking-wider text-[11px] mb-2">Next Steps for Onboarding:</div>
                <div class="flex items-start gap-2">
                    <span class="text-indigo-600 font-bold">1.</span>
                    <span>Admin reviews your trade license & TIN details within 24 business hours.</span>
                </div>
                <div class="flex items-start gap-2">
                    <span class="text-indigo-600 font-bold">2.</span>
                    <span>Once approved, log into your dedicated vendor workspace at <code class="font-mono bg-slate-200 px-1 rounded">/seller/login</code>.</span>
                </div>
                <div class="flex items-start gap-2">
                    <span class="text-indigo-600 font-bold">3.</span>
                    <span>Start listing dual-channel products in Bangladeshi Taka (Tk) and fulfilling sub-orders with courier integration.</span>
                </div>
            </div>

            <div class="pt-4">
                <a href="{{ route('catalog.index') }}" class="inline-block py-3 px-6 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs transition shadow-sm">
                    Return to Marketplace
                </a>
            </div>
        </div>
    @else
        <div class="space-y-8">
            <!-- Header -->
            <div class="text-center space-y-3 max-w-2xl mx-auto">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 border border-emerald-200">
                    Merchant & Factory Onboarding
                </span>
                <h1 class="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight">Open Your Factory Store on B2B Haat</h1>
                <p class="text-sm text-slate-600 leading-relaxed">
                    Liquidate wholesale bulk lots to verified shopkeepers across all 64 districts while earning premium margins on single-unit retail sales.
                </p>
            </div>

            <!-- Perks Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-sm">৳</div>
                    <h3 class="font-bold text-slate-900 text-sm">Dual Sourcing Channels</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">Offer bulk MOQ tiers (e.g. 50+ pcs) to boutiques and retail rates to consumers from one listing.</p>
                </div>
                <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-sm">🛡️</div>
                    <h3 class="font-bold text-slate-900 text-sm">48-Hour Escrow Protection</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">Customer payments are locked in escrow and released automatically 48h after delivery handover.</p>
                </div>
                <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-sm">🚚</div>
                    <h3 class="font-bold text-slate-900 text-sm">Courier Integration</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">1-click consignment creation with Steadfast, Pathao, RedX, and Paperfly with automated milestone logs.</p>
                </div>
            </div>

            @if ($errorMessage)
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
                    <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errorMessage }}</span>
                </div>
            @endif

            <!-- Registration Form -->
            <form wire:submit="registerStore" class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-10 shadow-xs space-y-6">
                <div class="border-b border-slate-100 pb-4">
                    <h2 class="text-base font-bold text-slate-900">1. Store & Business Profile</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Enter your shop, factory, or company details as registered.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Store / Factory Name *</label>
                        <input 
                            type="text" 
                            wire:model="storeName" 
                            placeholder="e.g. Dhaka Garments Direct Ltd" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('storeName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Owner / Authorized Representative *</label>
                        <input 
                            type="text" 
                            wire:model="ownerName" 
                            placeholder="e.g. Shahidul Alam" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('ownerName') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Trade License Number (Optional / Recommended)</label>
                        <input 
                            type="text" 
                            wire:model="tradeLicense" 
                            placeholder="e.g. TRAD/DNCC/2026/XXXXX" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono"
                        />
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Tax Identification Number (TIN)</label>
                        <input 
                            type="text" 
                            wire:model="tinNumber" 
                            placeholder="e.g. TIN-XXXXXXXXXX" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 font-mono"
                        />
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">District / Hub City *</label>
                        <input 
                            type="text" 
                            wire:model="city" 
                            placeholder="e.g. Dhaka / Chittagong / Narayanganj" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Factory / Warehouse Address</label>
                        <input 
                            type="text" 
                            wire:model="businessAddress" 
                            placeholder="e.g. Plot 12, Tongi Industrial Area" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                    </div>
                </div>

                <div class="border-b border-slate-100 pt-4 pb-4">
                    <h2 class="text-base font-bold text-slate-900">2. Login Credentials</h2>
                    <p class="text-xs text-slate-500 mt-0.5">You will use this email and password to log into `/seller`.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Email Address *</label>
                        <input 
                            type="email" 
                            wire:model="email" 
                            placeholder="vendor@company.com" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('email') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Contact Mobile Number *</label>
                        <input 
                            type="text" 
                            wire:model="phone" 
                            placeholder="+88017XXXXXXXX" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('phone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block font-semibold text-slate-700 mb-1">Password (Min. 8 chars) *</label>
                        <input 
                            type="password" 
                            wire:model="password" 
                            placeholder="••••••••" 
                            class="w-full py-2.5 px-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500"
                        />
                        @error('password') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="pt-4">
                    <button 
                        type="submit" 
                        class="w-full py-4 px-6 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Submit Merchant Application & Open Store
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
