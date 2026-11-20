<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Service Maintenance | B2B Haat</title>
    <x-brand.favicons />
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full flex flex-col justify-between text-slate-800 antialiased font-sans">
    <!-- Mini Header -->
    <header class="bg-white border-b border-slate-200 py-4 px-6 shadow-xs">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-2.5 text-xl font-black tracking-tight text-slate-900">
                <img src="{{ asset('images/brand/logo-mark.png') }}" alt="B2B Haat logo" width="40" height="40" class="w-10 h-10 object-contain shrink-0">
                <span>B2B Haat</span>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 text-[11px] font-bold">
                Scheduled Maintenance
            </span>
        </div>
    </header>

    <!-- Main 503 Hero -->
    <main class="flex-1 flex items-center justify-center px-4 py-16 sm:py-24">
        <div class="max-w-xl text-center space-y-6">
            <div class="inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-indigo-50 text-indigo-600 font-black text-4xl shadow-inner border border-indigo-100">
                ⚡
            </div>

            <div class="space-y-2">
                <h1 class="text-2xl sm:text-4xl font-black text-slate-900 tracking-tight">
                    Under Scheduled Upgrade
                </h1>
                <p class="text-sm text-slate-600 max-w-md mx-auto leading-relaxed">
                    We are currently upgrading our platform infrastructure to deliver faster catalog indexing and improved escrow settlement. We will be back online shortly!
                </p>
            </div>

            <!-- Reload Action -->
            <div class="pt-4 flex items-center justify-center">
                <button onclick="window.location.reload()" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition cursor-pointer">
                    Check if Platform is Ready
                </button>
            </div>

            <!-- Customer Care Note -->
            <div class="pt-8 border-t border-slate-200 text-xs text-slate-500">
                @include('errors.partials.contact', ['intro' => 'Urgent questions?'])
            </div>
        </div>
    </main>

    <!-- Mini Footer -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} B2B Haat Marketplace. All rights reserved.
    </footer>
</body>
</html>
