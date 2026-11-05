<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - Page Not Found | B2B Haat</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full flex flex-col justify-between text-slate-800 antialiased font-sans">
    <!-- Mini Header -->
    <header class="bg-white border-b border-slate-200 py-4 px-6 shadow-xs">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5 text-xl font-black tracking-tight text-slate-900">
                <span class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-700 to-indigo-500 text-white flex items-center justify-center font-black text-base shadow-sm">
                    B2B
                </span>
                <span>B2B Haat</span>
            </a>
            <a href="/" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition">
                &larr; Back to Marketplace
            </a>
        </div>
    </header>

    <!-- Main 404 Hero -->
    <main class="flex-1 flex items-center justify-center px-4 py-16 sm:py-24">
        <div class="max-w-xl text-center space-y-6">
            <div class="inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-indigo-50 text-indigo-600 font-black text-4xl shadow-inner border border-indigo-100">
                404
            </div>

            <div class="space-y-2">
                <h1 class="text-2xl sm:text-4xl font-black text-slate-900 tracking-tight">
                    Page Not Found
                </h1>
                <p class="text-sm text-slate-600 max-w-md mx-auto leading-relaxed">
                    The consignment, factory listing, or page you were looking for could not be located. It may have been moved, sold out, or expired.
                </p>
            </div>

            <!-- Helpful Quick Actions -->
            <div class="pt-4 flex flex-wrap items-center justify-center gap-3">
                <a href="/" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition">
                    Browse All Categories
                </a>
                <a href="/track" class="px-5 py-2.5 rounded-xl bg-white border border-slate-300 hover:border-slate-400 text-slate-700 font-bold text-xs shadow-xs transition">
                    Track Consignment
                </a>
                <a href="/cart" class="px-5 py-2.5 rounded-xl bg-white border border-slate-300 hover:border-slate-400 text-slate-700 font-bold text-xs shadow-xs transition">
                    View My Cart
                </a>
            </div>

            <!-- Customer Care Note -->
            <div class="pt-8 border-t border-slate-200 text-xs text-slate-500">
                @include('errors.partials.contact', ['intro' => 'Need help?'])
            </div>
        </div>
    </main>

    <!-- Mini Footer -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} B2B Haat Marketplace. All rights reserved.
    </footer>
</body>
</html>
