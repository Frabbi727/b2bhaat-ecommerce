<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'B2B Haat - Premium E-Commerce & Wholesale Marketplace' }}</title>
    <meta name="description" content="{{ $metaDescription ?? 'Bangladesh\'s trusted multi-vendor marketplace for factory-direct electronics, smart TVs, home appliances, and wholesale sourcing with verified escrow protection.' }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <meta name="theme-color" content="#4338ca">

    <!-- Open Graph / Social SEO -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $title ?? 'B2B Haat - Premium E-Commerce & Wholesale Marketplace' }}">
    <meta property="og:description" content="{{ $metaDescription ?? 'Shop authentic electronics, smart TVs, appliances, and factory-direct inventory with nationwide delivery.' }}">
    <meta property="og:image" content="{{ $metaImage ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=1200' }}">

    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title ?? 'B2B Haat - Premium E-Commerce & Wholesale Marketplace' }}">
    <meta name="twitter:description" content="{{ $metaDescription ?? 'Shop authentic electronics, smart TVs, appliances, and factory-direct inventory with nationwide delivery.' }}">
    <meta name="twitter:image" content="{{ $metaImage ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=1200' }}">

    @stack('jsonld')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex flex-col min-h-screen text-slate-800 antialiased font-sans" style="scrollbar-gutter: stable;">
    <!-- Top Announcement Strip (Promotional Banner or Delivery Notice) -->
    @php
        $topStrip = \App\Models\Banner::active()->forPosition('top_strip')->first();
        $storeConfig = app(\App\Services\Settings\StoreConfigurationService::class);
        $identity = $storeConfig->getStoreIdentity();
        $helpline = $identity['helpline'];
        $buyerPaymentMethods = \App\Models\PaymentMethod::availableToBuyers()->where('slug', '!=', \App\Models\PaymentMethod::MOCK)->get();
        $returnWindowHours = $storeConfig->getEscrowHoldingPeriodHours();
    @endphp

    <header class="bg-slate-950 text-slate-300 text-xs py-2 px-4 border-b border-slate-800">
        <div class="max-w-7xl mx-auto flex flex-wrap justify-between items-center gap-2">
            <div class="flex items-center gap-3">
                @if ($topStrip)
                    <a href="{{ $topStrip->link_url }}" class="hover:text-white transition flex items-center gap-1 text-amber-300 font-medium">
                        <span>{{ $topStrip->title }}</span>
                        <span class="text-slate-400 hidden md:inline">— {{ $topStrip->subtitle }}</span>
                    </a>
                @else
                    <span class="text-slate-400">{{ $identity['announcement'] }}</span>
                @endif
            </div>

            <div class="flex items-center gap-4 text-[11px]">
@if ($identity['helpline'])
                <div class="hidden md:flex items-center gap-1 text-slate-400">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    <span>Helpline: <strong class="text-slate-200">{{ $identity['helpline'] }}</strong></span>
                </div>
                @endif
                <span class="hidden sm:inline text-slate-700">|</span>
                <a href="{{ route('tracking.index') }}" class="hover:text-white transition flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Track order
                </a>
                <span class="hidden sm:inline text-slate-700">|</span>
                @auth
                    <a href="{{ route('customer.orders') }}" class="text-indigo-300 hover:text-indigo-200 font-semibold">
                        {{ auth()->user()->name }}
                    </a>
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-rose-400 hover:text-rose-300 underline">Logout</button>
                    </form>
                @else
                    <div class="flex items-center gap-2">
                        <a href="{{ route('login') }}" class="text-white hover:text-indigo-300 font-semibold transition flex items-center gap-1">
                            <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                            Sign In
                        </a>
                        <span class="text-slate-700">/</span>
                        <a href="{{ route('register') }}" class="text-indigo-300 hover:text-white font-semibold transition">
                            Register
                        </a>
                        @if (app()->environment('local', 'testing'))
                            <span class="hidden xl:inline text-slate-700">|</span>
                            <div class="hidden xl:flex items-center gap-1 text-[10px]">
                                <span class="text-slate-500">Demo:</span>
                                <a href="{{ route('demo.login', 'retail') }}" class="px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-700">Buyer</a>
                                <a href="{{ route('demo.login', 'wholesale') }}" class="px-1.5 py-0.5 rounded bg-indigo-950 text-indigo-300 hover:bg-indigo-900 border border-indigo-800">Wholesale</a>
                                <a href="{{ route('demo.login', 'seller') }}" class="px-1.5 py-0.5 rounded bg-emerald-950 text-emerald-300 hover:bg-emerald-900 border border-emerald-800">Seller</a>
                                <a href="{{ route('demo.login', 'admin') }}" class="px-1.5 py-0.5 rounded bg-amber-950 text-amber-300 hover:bg-amber-900 border border-amber-800">Admin</a>
                            </div>
                        @endif
                    </div>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Header with Live Search, Logo & Action Icons -->
    <div class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 sm:py-3.5 flex flex-wrap sm:flex-nowrap items-center justify-between gap-x-4 gap-y-2.5">
            <!-- Brand Logo -->
            <a href="{{ route('catalog.index') }}" class="flex items-center gap-2.5 text-xl font-black tracking-tight text-slate-900 shrink-0">
                <span class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-700 to-indigo-500 text-white flex items-center justify-center font-black text-base shadow-sm">
                    B2B
                </span>
                <span class="hidden sm:inline">{{ $identity['name'] }}</span>
            </a>

            <!-- Live search: its own full-width row on phones -->
            <div class="order-last sm:order-none w-full sm:w-auto sm:flex-1 max-w-2xl sm:mx-2">
                <livewire:storefront.header-search />
            </div>

            <!-- Customer Actions: Wishlist, Cart, Account -->
            <div class="flex items-center gap-3 shrink-0">
                <!-- Wishlist -->
                @php
                    $wishlistCount = auth()->check() ? auth()->user()->wishlists()->count() : 0;
                @endphp
                <a
                    href="{{ route('customer.wishlist') }}"
                    class="relative p-2 rounded-xl text-slate-600 hover:text-rose-600 hover:bg-rose-50 transition flex items-center justify-center"
                    title="My Wishlist"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                    @if ($wishlistCount > 0)
                        <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center">
                            {{ $wishlistCount }}
                        </span>
                    @endif
                </a>

                <!-- Cart Badge -->
                <livewire:storefront.cart-badge />

                <!-- Customer Account Dropdown -->
                @auth
                    <div class="relative" x-data="{ open: false }">
                        <button
                            @click="open = !open"
                            type="button"
                            class="flex items-center gap-1.5 p-1.5 rounded-xl border border-slate-200 hover:border-slate-300 text-xs font-semibold text-slate-800 transition"
                        >
                            <span class="w-7 h-7 rounded-lg bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold">
                                {{ substr(auth()->user()->name, 0, 1) }}
                            </span>
                            <span class="hidden lg:inline max-w-[90px] truncate">{{ auth()->user()->name }}</span>
                            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div
                            x-show="open"
                            @click.outside="open = false"
                            x-cloak
                            class="absolute right-0 mt-2 w-48 bg-white rounded-2xl shadow-xl border border-slate-200 py-1.5 z-50 text-xs divide-y divide-slate-100"
                        >
                            <div class="px-3 py-2 text-slate-500">
                                <div>Signed in as</div>
                                <div class="font-bold text-slate-900 truncate">{{ auth()->user()->email }}</div>
                            </div>
                            <div class="py-1">
                                <a href="{{ route('customer.orders') }}" class="block px-3 py-1.5 text-slate-700 hover:bg-slate-50">My Orders & Receipts</a>
                                <a href="{{ route('customer.addresses') }}" class="block px-3 py-1.5 text-slate-700 hover:bg-slate-50">Saved Delivery Addresses</a>
                                <a href="{{ route('customer.wishlist') }}" class="block px-3 py-1.5 text-slate-700 hover:bg-slate-50">Saved Wishlist</a>
                            </div>
                            <div class="py-1">
                                <form action="{{ route('logout') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="w-full text-left px-3 py-1.5 text-rose-600 hover:bg-rose-50 font-semibold">Sign Out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endauth
            </div>
        </div>

        <!-- Category Navigation Rail -->
        @php
            $navCategories = \App\Models\Category::where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->take(8)->get();
        @endphp
        <div class="border-t border-slate-100 bg-slate-50/70 hidden md:block">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between text-xs font-semibold text-slate-700">
                <div class="flex items-center gap-6 overflow-x-auto py-2.5 no-scrollbar">
                    <a href="{{ route('catalog.index') }}" class="flex items-center gap-1.5 text-indigo-600 font-bold hover:text-indigo-800 transition shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                        All Categories
                    </a>
                    @foreach ($navCategories as $navCat)
                        <a
                            href="{{ route('catalog.index', ['category' => $navCat->id]) }}"
                            class="hover:text-indigo-600 transition shrink-0"
                        >
                            {{ $navCat->name }}
                        </a>
                    @endforeach
                </div>

                <div class="flex items-center gap-4 shrink-0 py-2.5">
                    <a href="{{ route('catalog.index') }}#flash-sales" class="flex items-center gap-1 text-rose-600 font-bold hover:text-rose-700 transition">
                        <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                        ⚡ Flash Deals
                    </a>
                    <span class="text-slate-300">|</span>
                    <a href="{{ route('wholesale.upgrade') }}" class="text-indigo-600 hover:text-indigo-700 transition flex items-center gap-1">
                        Wholesale KYC
                        <span class="px-1.5 py-0.2 rounded bg-indigo-100 text-indigo-700 text-[10px] uppercase font-bold">B2B</span>
                    </a>
                    <span class="text-slate-300">|</span>
                    <a href="{{ route('seller.register') }}" class="text-emerald-700 hover:text-emerald-800 transition">Open Factory Store</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Global Flash Alerts -->
    @if (session('success') || session('status'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-xs">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    <span>{{ session('success') ?? session('status') }}</span>
                </div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2 shadow-xs">
                <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ session('error') }}</span>
            </div>
        </div>
    @endif

    <!-- Main Content -->
    <main class="flex-1 pb-16 md:pb-0">
        {{ $slot }}
    </main>

    <!-- Commercial Retail Footer with Trust Signals & Guarantees -->
    <footer class="bg-slate-900 text-slate-400 text-sm mt-20 border-t border-slate-800 pb-16 md:pb-0">
        <!-- Trust Badges Rail -->
        <div class="border-b border-slate-800 bg-slate-950/60 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-2 md:grid-cols-4 gap-6 text-center md:text-left">
                <div class="flex items-center gap-3 justify-center md:justify-start">
                    <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <div>
                        <div class="text-white text-xs font-bold">Verified Sellers</div>
                        <div class="text-[11px] text-slate-400">Every seller's trade licence is checked</div>
                    </div>
                </div>

                <div class="flex items-center gap-3 justify-center md:justify-start">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div>
                        <div class="text-white text-xs font-bold">Returns Within {{ $returnWindowHours }} Hours</div>
                        <div class="text-[11px] text-slate-400"><a href="{{ route('pages.returns') }}" class="hover:text-white underline">Read the return policy</a></div>
                    </div>
                </div>

                <div class="flex items-center gap-3 justify-center md:justify-start">
                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <div class="text-white text-xs font-bold">Pay Your Way</div>
                        <div class="text-[11px] text-slate-400">{{ $buyerPaymentMethods->pluck('name')->implode(', ') }}</div>
                    </div>
                </div>

                <div class="flex items-center gap-3 justify-center md:justify-start">
                    <div class="w-10 h-10 rounded-xl bg-sky-500/10 border border-sky-500/20 text-sky-400 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div>
                        <div class="text-white text-xs font-bold">Delivery All Over Bangladesh</div>
                        <div class="text-[11px] text-slate-400">Inside Dhaka in {{ $storeConfig->getDeliverySla('inside_dhaka') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Columns -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 grid grid-cols-1 md:grid-cols-4 gap-8">
            <div class="space-y-3">
                <div class="text-white font-bold text-lg flex items-center gap-2">
                    <span class="w-6 h-6 rounded bg-indigo-600 text-white flex items-center justify-center font-bold text-xs">B2B</span>
                    <span>{{ $identity['name'] }}</span>
                </div>
                <p class="text-xs leading-relaxed text-slate-400">
                    The hybrid e-commerce retail & factory-direct bulk wholesale platform connecting manufacturers, verified merchants, and shoppers across Bangladesh.
                </p>
                <div class="text-xs text-slate-400 pt-2">
                    @if ($identity['helpline'])
                        <div>Customer Service: <strong class="text-white">{{ $identity['helpline'] }}</strong></div>
                    @endif
                    @if ($identity['email'])
                        <div>Email: <strong class="text-white">{{ $identity['email'] }}</strong></div>
                    @endif
                    @if ($identity['address'])
                        <div>{{ $identity['address'] }}</div>
                    @endif
                </div>
            </div>

            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Product Categories</h4>
                <ul class="space-y-2 text-xs">
                    @foreach ($navCategories->take(5) as $fc)
                        <li><a href="{{ route('catalog.index', ['category' => $fc->id]) }}" class="hover:text-white transition">{{ $fc->name }}</a></li>
                    @endforeach
                    <li><a href="{{ route('catalog.index') }}" class="hover:text-white transition font-semibold text-indigo-400">View All Categories &rarr;</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Customer Care</h4>
                <ul class="space-y-2 text-xs">
                    <li><a href="{{ route('tracking.index') }}" class="hover:text-white transition">Track your order</a></li>
                    <li><a href="{{ route('customer.orders') }}" class="hover:text-white transition">Order History & Invoices</a></li>
                    <li><a href="{{ route('customer.wishlist') }}" class="hover:text-white transition">My Wishlist</a></li>
                    <li><a href="{{ route('seller.register') }}" class="hover:text-white transition">Merchant / Factory Onboarding</a></li>
                    <li><a href="{{ route('wholesale.upgrade') }}" class="hover:text-white transition">B2B Wholesale KYC Verification</a></li>
                </ul>
            </div>

            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Payment</h4>
                <p class="text-xs text-slate-400 mb-3">We accept:</p>
                <div class="flex items-center gap-2 flex-wrap">
                    @foreach ($buyerPaymentMethods as $buyerPaymentMethod)
                        <span class="px-2.5 py-1 rounded bg-slate-800 text-slate-200 border border-slate-700 text-[11px] font-bold">{{ $buyerPaymentMethod->name }}</span>
                    @endforeach
                </div>
                <h4 class="text-white font-semibold mt-6 mb-3 text-xs uppercase tracking-wider">Policies</h4>
                <ul class="space-y-2 text-xs">
                    <li><a href="{{ route('pages.terms') }}" class="hover:text-white transition">Terms & Conditions</a></li>
                    <li><a href="{{ route('pages.privacy') }}" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="{{ route('pages.returns') }}" class="hover:text-white transition">Returns & Refunds</a></li>
                    <li><a href="{{ route('pages.about') }}" class="hover:text-white transition">About Us</a></li>
                    <li><a href="{{ route('pages.contact') }}" class="hover:text-white transition">Contact Us</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-slate-800/80 py-4 text-center text-xs text-slate-500">
            &copy; {{ date('Y') }} {{ $identity['name'] }}. All rights reserved.
        </div>
    </footer>

    <!-- Mobile App Bottom Navigation Bar -->
    <nav class="md:hidden fixed bottom-0 inset-x-0 bg-white/95 backdrop-blur-md border-t border-slate-200 z-40 py-2 px-3 flex justify-around items-center text-[10px] font-semibold text-slate-600 shadow-lg">
        <a href="{{ route('catalog.index') }}" class="flex flex-col items-center gap-0.5 {{ request()->routeIs('catalog.index') && !request()->hasAny(['search', 'category']) ? 'text-indigo-600 font-bold' : 'hover:text-indigo-600' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            <span>Home</span>
        </a>
        <a href="{{ route('catalog.index') }}#flash-sales" class="flex flex-col items-center gap-0.5 hover:text-rose-600">
            <svg class="w-5 h-5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Deals</span>
        </a>
        <a href="{{ route('cart.index') }}" class="flex flex-col items-center gap-0.5 relative {{ request()->routeIs('cart.index') ? 'text-indigo-600 font-bold' : 'hover:text-indigo-600' }}">
            <div class="relative">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                @php
                    $cartCount = app(\App\Services\Cart\CartSessionService::class)->getItemCount();
                @endphp
                @if ($cartCount > 0)
                    <span class="absolute -top-1 -right-2 px-1 rounded-full bg-indigo-600 text-white text-[9px] font-bold min-w-[14px] text-center">
                        {{ $cartCount }}
                    </span>
                @endif
            </div>
            <span>Cart</span>
        </a>
        <a href="{{ route('tracking.index') }}" class="flex flex-col items-center gap-0.5 {{ request()->routeIs('tracking.index') ? 'text-indigo-600 font-bold' : 'hover:text-indigo-600' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            <span>Track</span>
        </a>
        <a href="{{ auth()->check() ? route('customer.orders') : route('login') }}" class="flex flex-col items-center gap-0.5 {{ request()->routeIs('customer.orders', 'login', 'register') ? 'text-indigo-600 font-bold' : 'hover:text-indigo-600' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span>{{ auth()->check() ? 'Account' : 'Login' }}</span>
        </a>
    </nav>

    @livewireScripts
</body>
</html>
