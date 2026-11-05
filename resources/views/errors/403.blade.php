<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 - Access Denied | B2B Haat</title>
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

    <!-- Main 403 Hero -->
    <main class="flex-1 flex items-center justify-center px-4 py-16 sm:py-24">
        <div class="max-w-xl text-center space-y-6">
            <div class="inline-flex items-center justify-center w-24 h-24 rounded-3xl bg-rose-50 text-rose-600 font-black text-4xl shadow-inner border border-rose-100">
                403
            </div>

            <div class="space-y-2">
                <h1 class="text-2xl sm:text-4xl font-black text-slate-900 tracking-tight">
                    Access Restricted
                </h1>
                <p class="text-sm text-slate-600 max-w-md mx-auto leading-relaxed">
                    {{ $exception->getMessage() ?: 'You do not have the required role or authorization to access this secured portal or invoice.' }}
                </p>
            </div>

            <!-- Helpful Quick Actions -->
            <div class="pt-4 flex flex-wrap items-center justify-center gap-3">
                <a href="/login" class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition">
                    Sign In with Another Account
                </a>
                <a href="/" class="px-5 py-2.5 rounded-xl bg-white border border-slate-300 hover:border-slate-400 text-slate-700 font-bold text-xs shadow-xs transition">
                    Return to Home
                </a>
                <a href="/seller/login" class="px-5 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-xs transition">
                    Seller Portal
                </a>
            </div>

            <!-- Customer Care Note -->
            <div class="pt-8 border-t border-slate-200 text-xs text-slate-500">
                @include('errors.partials.contact', ['intro' => 'Believe this is an error? Contact support.'])
            </div>
        </div>
    </main>

    <!-- Mini Footer -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} B2B Haat Marketplace. All rights reserved.
    </footer>
</body>
</html>
