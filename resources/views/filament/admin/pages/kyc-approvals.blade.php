<x-filament-panels::page>
    <!-- Top Summary Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-2">
        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Pending Stores</p>
                <p class="text-2xl font-black text-amber-600 mt-1">{{ $pendingMerchantsCount }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Factory applications awaiting KYC</p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                <x-heroicon-o-building-storefront class="w-6 h-6" />
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Pending B2B Buyers</p>
                <p class="text-2xl font-black text-amber-600 mt-1">{{ $pendingWholesaleCount }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Wholesale tier upgrade requests</p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                <x-heroicon-o-briefcase class="w-6 h-6" />
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Approved Factories</p>
                <p class="text-2xl font-black text-emerald-600 mt-1">{{ $approvedMerchantsCount }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Active verified storefronts</p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                <x-heroicon-o-check-badge class="w-6 h-6" />
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-white border border-slate-200 shadow-xs flex items-center justify-between">
            <div>
                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider">Approved B2B Accounts</p>
                <p class="text-2xl font-black text-indigo-600 mt-1">{{ $approvedWholesaleCount }}</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Authorized bulk buyers</p>
            </div>
            <div class="w-11 h-11 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                <x-heroicon-o-users class="w-6 h-6" />
            </div>
        </div>
    </div>

    <!-- Main KYC Hub Container -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <!-- Navigation Tab Strip -->
        <div class="border-b border-slate-200 px-6 pt-4 flex flex-wrap items-center justify-between gap-4 bg-slate-50/50">
            <div class="flex items-center gap-2">
                <button
                    wire:click="setActiveTab('merchants')"
                    class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'merchants' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800' }}"
                >
                    <x-heroicon-o-building-storefront class="w-4 h-4" />
                    <span>Factory Merchant Applications</span>
                    @if ($pendingMerchantsCount > 0)
                        <span class="px-1.5 py-0.5 text-[10px] font-black rounded-full bg-amber-100 text-amber-700">
                            {{ $pendingMerchantsCount }}
                        </span>
                    @endif
                </button>

                <button
                    wire:click="setActiveTab('wholesale')"
                    class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'wholesale' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800' }}"
                >
                    <x-heroicon-o-briefcase class="w-4 h-4" />
                    <span>Wholesale Buyer Verifications</span>
                    @if ($pendingWholesaleCount > 0)
                        <span class="px-1.5 py-0.5 text-[10px] font-black rounded-full bg-amber-100 text-amber-700">
                            {{ $pendingWholesaleCount }}
                        </span>
                    @endif
                </button>

                <button
                    wire:click="setActiveTab('audit')"
                    class="px-4 py-3 text-xs font-bold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'audit' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800' }}"
                >
                    <x-heroicon-o-clipboard-document-check class="w-4 h-4" />
                    <span>Decision Audit History</span>
                </button>
            </div>

            <!-- Sub Filter Pills -->
            @if ($activeTab === 'merchants')
                <div class="flex items-center gap-1.5 pb-2">
                    @foreach (['pending' => 'Pending Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All Stores'] as $key => $label)
                        <button
                            wire:click="setMerchantFilter('{{ $key }}')"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold transition cursor-pointer {{ $merchantFilter === $key ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @elseif ($activeTab === 'wholesale')
                <div class="flex items-center gap-1.5 pb-2">
                    @foreach (['pending' => 'Pending Review', 'approved' => 'Approved B2B', 'rejected' => 'Rejected', 'all' => 'All Applicants'] as $key => $label)
                        <button
                            wire:click="setWholesaleFilter('{{ $key }}')"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold transition cursor-pointer {{ $wholesaleFilter === $key ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Content Area -->
        <div class="p-6">
            <!-- Tab 1: Factory Merchants -->
            @if ($activeTab === 'merchants')
                @if ($merchants->isEmpty())
                    <div class="py-12 text-center">
                        <x-heroicon-o-check-circle class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                        <h4 class="text-sm font-bold text-slate-800">No Merchant Applications in this View</h4>
                        <p class="text-xs text-slate-500 mt-1">There are currently no factory applications matching this filter.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 px-4">Store & Manufacturer</th>
                                    <th class="py-3 px-4">Trade License</th>
                                    <th class="py-3 px-4">TIN Certificate</th>
                                    <th class="py-3 px-4">Commission</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4">Applied Date</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                @foreach ($merchants as $m)
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-slate-900">{{ $m->store_name }}</div>
                                            <div class="text-[11px] text-slate-500">{{ $m->user?->name }} &bull; {{ $m->user?->phone }}</div>
                                        </td>
                                        <td class="py-3.5 px-4 font-mono text-slate-600">
                                            {{ $m->trade_license_number ?? 'Not Provided' }}
                                        </td>
                                        <td class="py-3.5 px-4 font-mono text-slate-600">
                                            {{ $m->tax_id_number ?? 'Not Provided' }}
                                        </td>
                                        <td class="py-3.5 px-4 font-bold text-slate-800">
                                            {{ number_format((float) $m->commission_rate_percentage, 1) }}%
                                        </td>
                                        <td class="py-3.5 px-4">
                                            @if ($m->is_approved)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                    Approved
                                                </span>
                                            @elseif ($m->rejected_at)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800" title="{{ $m->rejection_reason }}">
                                                    Rejected
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                                    Pending Review
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-500 text-[11px]">
                                            {{ $m->created_at->format('M d, Y') }}
                                        </td>
                                        <td class="py-3.5 px-4 text-right space-x-1.5 whitespace-nowrap">
                                            <button
                                                wire:click="viewMerchantDossier({{ $m->id }})"
                                                class="px-2.5 py-1 rounded-md text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition cursor-pointer"
                                            >
                                                Inspect
                                            </button>

                                            @if (! $m->is_approved)
                                                <button
                                                    wire:click="openApproveMerchant({{ $m->id }})"
                                                    class="px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700 transition cursor-pointer"
                                                >
                                                    Approve
                                                </button>
                                            @endif

                                            @if (! $m->is_approved && ! $m->rejected_at)
                                                <button
                                                    wire:click="openRejectMerchant({{ $m->id }})"
                                                    class="px-2.5 py-1 rounded-md text-xs font-semibold bg-rose-600 text-white hover:bg-rose-700 transition cursor-pointer"
                                                >
                                                    Reject
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            <!-- Tab 2: Wholesale Buyers -->
            @if ($activeTab === 'wholesale')
                @if ($wholesaleUsers->isEmpty())
                    <div class="py-12 text-center">
                        <x-heroicon-o-check-circle class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                        <h4 class="text-sm font-bold text-slate-800">No Wholesale Verifications in this View</h4>
                        <p class="text-xs text-slate-500 mt-1">There are no pending business accounts matching this filter.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 px-4">Business / Company</th>
                                    <th class="py-3 px-4">Representative</th>
                                    <th class="py-3 px-4">Trade License / NID</th>
                                    <th class="py-3 px-4">TIN Certificate</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4">Applied Date</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                @foreach ($wholesaleUsers as $u)
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-3.5 px-4 font-bold text-slate-900">
                                            {{ $u->business_name ?? 'Individual Business' }}
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <div class="font-semibold text-slate-800">{{ $u->name }}</div>
                                            <div class="text-[11px] text-slate-500">{{ $u->email }} &bull; {{ $u->phone }}</div>
                                        </td>
                                        <td class="py-3.5 px-4 font-mono text-slate-600">
                                            {{ $u->trade_license_number ?? 'Not Provided' }}
                                        </td>
                                        <td class="py-3.5 px-4 font-mono text-slate-600">
                                            {{ $u->tax_id_number ?? 'Not Provided' }}
                                        </td>
                                        <td class="py-3.5 px-4">
                                            @if ($u->is_wholesale_approved)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                    Approved B2B
                                                </span>
                                            @elseif ($u->wholesale_rejected_at)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800" title="{{ $u->wholesale_rejection_reason }}">
                                                    Rejected
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                                    Pending Review
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-500 text-[11px]">
                                            {{ $u->created_at->format('M d, Y') }}
                                        </td>
                                        <td class="py-3.5 px-4 text-right space-x-1.5 whitespace-nowrap">
                                            <button
                                                wire:click="viewWholesaleDossier({{ $u->id }})"
                                                class="px-2.5 py-1 rounded-md text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200 transition cursor-pointer"
                                            >
                                                Inspect
                                            </button>

                                            @if (! $u->is_wholesale_approved)
                                                <button
                                                    wire:click="openApproveWholesale({{ $u->id }})"
                                                    class="px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700 transition cursor-pointer"
                                                >
                                                    Approve B2B
                                                </button>
                                            @endif

                                            @if (! $u->is_wholesale_approved && ! $u->wholesale_rejected_at)
                                                <button
                                                    wire:click="openRejectWholesale({{ $u->id }})"
                                                    class="px-2.5 py-1 rounded-md text-xs font-semibold bg-rose-600 text-white hover:bg-rose-700 transition cursor-pointer"
                                                >
                                                    Reject
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            <!-- Tab 3: Decision Audit History -->
            @if ($activeTab === 'audit')
                @if ($auditLog->isEmpty())
                    <div class="py-12 text-center">
                        <x-heroicon-o-clipboard-document-check class="w-12 h-12 text-slate-300 mx-auto mb-3" />
                        <h4 class="text-sm font-bold text-slate-800">No Decision History Logged Yet</h4>
                        <p class="text-xs text-slate-500 mt-1">Approvals and rejections will appear here chronologically for compliance auditing.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50 text-slate-500 font-bold uppercase tracking-wider border-b border-slate-200">
                                <tr>
                                    <th class="py-3 px-4">Entity Type</th>
                                    <th class="py-3 px-4">Store / Business Name</th>
                                    <th class="py-3 px-4">Representative</th>
                                    <th class="py-3 px-4">Outcome</th>
                                    <th class="py-3 px-4">Decision Notes</th>
                                    <th class="py-3 px-4">Timestamp</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                                @foreach ($auditLog as $log)
                                    <tr class="hover:bg-slate-50/60 transition">
                                        <td class="py-3.5 px-4 font-bold text-slate-700">
                                            {{ $log['type'] }}
                                        </td>
                                        <td class="py-3.5 px-4 font-bold text-slate-900">
                                            {{ $log['name'] }}
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-600">
                                            {{ $log['owner'] ?? 'N/A' }}
                                        </td>
                                        <td class="py-3.5 px-4">
                                            @if ($log['action'] === 'Approved')
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                    Approved
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">
                                                    Rejected
                                                </span>
                                            @endif
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-600 max-w-xs truncate" title="{{ $log['notes'] }}">
                                            {{ $log['notes'] ?? 'No notes recorded' }}
                                        </td>
                                        <td class="py-3.5 px-4 text-slate-500 text-[11px] whitespace-nowrap">
                                            {{ $log['date'] ? \Carbon\Carbon::parse($log['date'])->format('M d, Y H:i') : 'N/A' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>

    <!-- Modal: Approve Merchant -->
    @if ($showApproveMerchantModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="font-bold text-slate-900 text-base">Approve Merchant Store</h3>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <p class="text-xs text-slate-600">
                    Authorize this merchant to open their vendor storefront, publish catalog products, and receive orders on B2B Haat.
                </p>

                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-700">Platform Commission Rate (%)</label>
                    <input
                        type="number"
                        step="0.01"
                        wire:model="customCommissionRate"
                        class="w-full rounded-xl border-slate-200 text-xs py-2 px-3 focus:ring-indigo-500 focus:border-indigo-500"
                    />
                    <p class="text-[11px] text-slate-400">Default standard platform commission is 10.0%.</p>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button
                        wire:click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="confirmApproveMerchant"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 transition cursor-pointer"
                    >
                        Confirm Approval
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal: Reject Merchant -->
    @if ($showRejectMerchantModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="font-bold text-slate-900 text-base">Reject Merchant Application</h3>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <p class="text-xs text-slate-600">
                    Provide the reason for rejecting this vendor's application. This will be recorded for audit and merchant re-submission.
                </p>

                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-700">Deficiency / Rejection Reason</label>
                    <textarea
                        wire:model="rejectionReason"
                        rows="3"
                        placeholder="e.g. Trade license expired or could not be validated with City Corporation registry; invalid TIN certificate."
                        class="w-full rounded-xl border-slate-200 text-xs p-3 focus:ring-indigo-500 focus:border-indigo-500"
                    ></textarea>
                    @error('rejectionReason')
                        <p class="text-[11px] text-rose-600 font-bold mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button
                        wire:click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="confirmRejectMerchant"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 transition cursor-pointer"
                    >
                        Reject Application
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal: Approve Wholesale -->
    @if ($showApproveWholesaleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="font-bold text-slate-900 text-base">Approve Wholesale Business Account</h3>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <p class="text-xs text-slate-600">
                    Upgrade this customer to an authorized **B2B Wholesale Buyer**. This will immediately unlock factory wholesale MOQ pricing, bulk matrix orders, and custom RFQ capabilities.
                </p>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button
                        wire:click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="confirmApproveWholesale"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 transition cursor-pointer"
                    >
                        Confirm B2B Approval
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal: Reject Wholesale -->
    @if ($showRejectWholesaleModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="font-bold text-slate-900 text-base">Reject Wholesale Verification</h3>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <p class="text-xs text-slate-600">
                    State the deficiency reason for declining this wholesale account request.
                </p>

                <div class="space-y-1">
                    <label class="block text-xs font-bold text-slate-700">Deficiency / Rejection Reason</label>
                    <textarea
                        wire:model="rejectionReason"
                        rows="3"
                        placeholder="e.g. Incomplete business documentation; invalid Trade License registration number."
                        class="w-full rounded-xl border-slate-200 text-xs p-3 focus:ring-indigo-500 focus:border-indigo-500"
                    ></textarea>
                    @error('rejectionReason')
                        <p class="text-[11px] text-rose-600 font-bold mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                    <button
                        wire:click="closeModal"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        wire:click="confirmRejectWholesale"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 transition cursor-pointer"
                    >
                        Reject Verification
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal: View Dossier -->
    @if ($showDossierModal && !empty($dossierData))
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-xl border border-slate-200 space-y-5">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="font-bold text-slate-900 text-base">{{ $dossierData['title'] }}</h3>
                        <p class="text-[11px] text-slate-400 font-medium">{{ $dossierData['subtitle'] }}</p>
                    </div>
                    <button wire:click="closeModal" class="text-slate-400 hover:text-slate-600 cursor-pointer">
                        <x-heroicon-m-x-mark class="w-5 h-5" />
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                        <span class="block text-slate-400 font-bold uppercase text-[10px]">Contact Person</span>
                        <span class="font-bold text-slate-800">{{ $dossierData['owner_name'] }}</span>
                    </div>

                    <div>
                        <span class="block text-slate-400 font-bold uppercase text-[10px]">Contact Number</span>
                        <span class="font-mono text-slate-800">{{ $dossierData['phone'] }}</span>
                    </div>

                    <div>
                        <span class="block text-slate-400 font-bold uppercase text-[10px]">Email Address</span>
                        <span class="text-slate-800">{{ $dossierData['email'] }}</span>
                    </div>

                    <div>
                        <span class="block text-slate-400 font-bold uppercase text-[10px]">Current Status</span>
                        <span class="font-bold text-slate-900">{{ $dossierData['status'] }}</span>
                    </div>

                    <div class="col-span-2 p-3 rounded-xl bg-slate-50 border border-slate-100 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-slate-500 font-medium">Govt. Trade License:</span>
                            <span class="font-mono font-bold text-slate-800">{{ $dossierData['trade_license'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500 font-medium">TIN / Tax Identification:</span>
                            <span class="font-mono font-bold text-slate-800">{{ $dossierData['tin'] }}</span>
                        </div>
                        @if (isset($dossierData['commission']))
                            <div class="flex justify-between">
                                <span class="text-slate-500 font-medium">Platform Commission:</span>
                                <span class="font-bold text-slate-800">{{ $dossierData['commission'] }}</span>
                            </div>
                        @endif
                    </div>

                    @if (!empty($dossierData['rejection_reason']))
                        <div class="col-span-2 p-3 rounded-xl bg-rose-50 border border-rose-100 text-rose-800">
                            <span class="block text-[10px] font-bold uppercase text-rose-600">Logged Deficiency / Rejection Reason:</span>
                            <p class="text-xs mt-1">{{ $dossierData['rejection_reason'] }}</p>
                        </div>
                    @endif
                </div>

                <div class="flex justify-end pt-2 border-t border-slate-100">
                    <button
                        wire:click="closeModal"
                        class="px-5 py-2 rounded-xl text-xs font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
                    >
                        Close Dossier
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
