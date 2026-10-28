<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'B2B Haat - Hybrid B2B & B2C Marketplace' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex flex-col min-h-screen text-slate-800 antialiased font-sans">
    <!-- Top Bar: Channel Mode & Quick Demo Switcher -->
    <header class="bg-slate-900 text-slate-200 text-xs py-2 px-4 border-b border-slate-800">
        <div class="max-w-7xl mx-auto flex flex-wrap justify-between items-center gap-2">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Verified Factory Direct
                </span>
                <span class="hidden sm:inline text-slate-400">|</span>
                <span class="text-slate-300">Dual-Channel Sourcing Engine (Wholesale MOQ & Retail Available)</span>
            </div>

            <div class="flex items-center gap-4">
                @auth
                    <span class="text-slate-300">
                        Logged in as: <strong class="text-emerald-400">{{ auth()->user()->name }}</strong> 
                        ({{ auth()->user()->role?->name ?? 'User' }})
                    </span>
                    <a href="{{ route('customer.orders') }}" class="text-xs px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-indigo-300 border border-slate-700 font-semibold">
                        My Orders
                    </a>
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-rose-400 hover:text-rose-300 underline text-xs">Logout</button>
                    </form>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-slate-400 hidden sm:inline">Demo Personas:</span>
                        <a href="{{ route('demo.login', 'retail') }}" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-[11px]">Retail Buyer</a>
                        <a href="{{ route('demo.login', 'wholesale') }}" class="px-2 py-0.5 rounded bg-indigo-900/60 hover:bg-indigo-800 text-indigo-200 border border-indigo-700 text-[11px]">Wholesale B2B</a>
                        <a href="{{ route('demo.login', 'seller') }}" class="px-2 py-0.5 rounded bg-emerald-900/60 hover:bg-emerald-800 text-emerald-200 border border-emerald-700 text-[11px]">Seller Portal</a>
                        <a href="{{ route('demo.login', 'admin') }}" class="px-2 py-0.5 rounded bg-amber-900/60 hover:bg-amber-800 text-amber-200 border border-amber-700 text-[11px]">Admin</a>
                    </div>
                @endauth
            </div>
        </div>
    </header>

    <!-- Main Navigation -->
    <nav x-data="{ mobileOpen: false }" class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex justify-between items-center">
            <div class="flex items-center gap-8">
                <a href="{{ route('catalog.index') }}" class="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900">
                    <span class="w-8 h-8 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-black text-lg shadow-sm">B2B</span>
                    <span>B2B <span class="text-indigo-600">Haat</span></span>
                </a>

                <div class="hidden lg:flex items-center gap-6 text-sm font-medium">
                    <a href="{{ route('catalog.index') }}" class="text-slate-700 hover:text-indigo-600 transition">Marketplace Catalog</a>
                    <a href="{{ route('wholesale.upgrade') }}" class="text-indigo-600 hover:text-indigo-700 font-semibold transition flex items-center gap-1">
                        Wholesale KYC
                        <span class="text-[10px] px-1.5 py-0.2 rounded bg-indigo-100 text-indigo-700 font-bold uppercase">B2B</span>
                    </a>
                    <a href="{{ route('tracking.index') }}" class="text-slate-700 hover:text-indigo-600 transition">Track Package</a>
                    <a href="{{ route('seller.register') }}" class="text-emerald-700 hover:text-emerald-800 font-semibold transition">Open Factory Store</a>
                    <a href="/seller" class="text-slate-700 hover:text-indigo-600 transition flex items-center gap-1">
                        Seller Center
                        <span class="text-xs px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-700 font-semibold">Portal</span>
                    </a>
                    <a href="/admin" class="text-slate-700 hover:text-indigo-600 transition flex items-center gap-1">
                        Admin
                        <span class="text-xs px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-semibold">Portal</span>
                    </a>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <livewire:storefront.cart-badge />

                <!-- Mobile Menu Button -->
                <button 
                    @click="mobileOpen = !mobileOpen" 
                    type="button" 
                    class="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 focus:outline-none"
                    aria-label="Toggle navigation"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path x-show="!mobileOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        <path x-show="mobileOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile Menu Dropdown -->
        <div x-show="mobileOpen" x-cloak class="lg:hidden border-t border-slate-200 bg-white px-4 pt-3 pb-5 space-y-3 shadow-lg">
            <div class="space-y-1">
                <a href="{{ route('catalog.index') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-100">Marketplace Catalog</a>
                <a href="{{ route('wholesale.upgrade') }}" class="block px-3 py-2 rounded-lg text-sm font-semibold text-indigo-600 hover:bg-indigo-50">Wholesale KYC Verification (B2B)</a>
                <a href="{{ route('tracking.index') }}" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-100">Track Package / Courier Consignment</a>
                <a href="{{ route('seller.register') }}" class="block px-3 py-2 rounded-lg text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Open Factory Store (Merchant Onboarding)</a>
                <div class="border-t border-slate-100 pt-2 my-2"></div>
                <a href="/seller" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-100">Seller Center Portal (/seller)</a>
                <a href="/admin" class="block px-3 py-2 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-100">Super Admin Portal (/admin)</a>
                @auth
                    <a href="{{ route('customer.orders') }}" class="block px-3 py-2 rounded-lg text-sm font-semibold text-slate-900 hover:bg-slate-100">My Orders & Receipts</a>
                @endauth
            </div>
        </div>
    </nav>

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
    <main class="flex-1">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 text-sm py-12 mt-16 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-1 md:grid-cols-4 gap-8">
            <div class="space-y-3">
                <div class="text-white font-bold text-lg flex items-center gap-2">
                    <span class="w-6 h-6 rounded bg-indigo-600 text-white flex items-center justify-center font-bold text-xs">B2B</span>
                    <span>B2B Haat Platform</span>
                </div>
                <p class="text-xs leading-relaxed text-slate-400">
                    The hybrid e-commerce marketplace engine powering factory-direct bulk wholesale & single-unit consumer retail across all 64 districts in Bangladesh.
                </p>
                <div class="text-xs text-slate-500">
                    Built with Laravel 11, Filament v5, Livewire v3 & PostgreSQL 17.
                </div>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Market Channels</h4>
                <ul class="space-y-2 text-xs">
                    <li><a href="{{ route('catalog.index') }}" class="hover:text-white transition">Wholesale Catalog (MOQ Enforced)</a></li>
                    <li><a href="{{ route('catalog.index') }}" class="hover:text-white transition">Retail Storefront (Single Unit)</a></li>
                    <li><a href="{{ route('wholesale.upgrade') }}" class="hover:text-white transition">Wholesale KYC Registration</a></li>
                    <li><a href="{{ route('tracking.index') }}" class="hover:text-white transition">Courier Tracking Service</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Portals & Workspaces</h4>
                <ul class="space-y-2 text-xs">
                    <li><a href="/seller" class="hover:text-white transition">Seller Center Portal (/seller)</a></li>
                    <li><a href="/admin" class="hover:text-white transition">Platform Super Admin (/admin)</a></li>
                    <li><a href="{{ route('seller.register') }}" class="hover:text-white transition">Open Factory Store Application</a></li>
                    <li><a href="{{ route('customer.orders') }}" class="hover:text-white transition">My Order History & Receipts</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-semibold mb-3 text-xs uppercase tracking-wider">Architecture Guarantees</h4>
                <ul class="space-y-2 text-xs text-slate-400">
                    <li class="flex items-center gap-1.5"><span class="text-emerald-400">✓</span> Server-Side Price & Margin Masking</li>
                    <li class="flex items-center gap-1.5"><span class="text-emerald-400">✓</span> Pessimistic Row Locking Concurrency</li>
                    <li class="flex items-center gap-1.5"><span class="text-emerald-400">✓</span> Multi-Vendor Sub-Order Splitting</li>
                    <li class="flex items-center gap-1.5"><span class="text-emerald-400">✓</span> 48-Hour Escrow Settlement Ledger</li>
                </ul>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
