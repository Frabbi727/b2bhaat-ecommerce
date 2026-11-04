<div class="max-w-md mx-auto px-4 py-12 sm:py-16">
    <div class="bg-white rounded-3xl border border-slate-200 shadow-xl overflow-hidden">
        <!-- Brand Header Strip -->
        <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-950 text-white p-6 text-center space-y-2">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-indigo-600 text-white font-black text-xl shadow-lg mb-1">
                B2B
            </div>
            <h1 class="text-xl sm:text-2xl font-black tracking-tight text-white">
                {{ $mode === 'register' ? 'Join B2B Haat Marketplace' : 'Welcome Back' }}
            </h1>
            <p class="text-xs text-slate-300">
                {{ $mode === 'register' ? 'Create an account to track orders, save wishlists, and buy directly.' : 'Sign in to access your orders, saved addresses, and quotes.' }}
            </p>
        </div>

        <!-- Mode Toggle Tabs -->
        <div class="grid grid-cols-2 p-1.5 bg-slate-100/80 border-b border-slate-200 text-xs font-bold text-center">
            <button
                type="button"
                wire:click="switchMode('login')"
                class="py-2.5 rounded-2xl transition cursor-pointer {{ $mode === 'login' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500 hover:text-slate-800' }}"
            >
                Sign In
            </button>
            <button
                type="button"
                wire:click="switchMode('register')"
                class="py-2.5 rounded-2xl transition cursor-pointer {{ $mode === 'register' ? 'bg-white text-indigo-700 shadow-xs' : 'text-slate-500 hover:text-slate-800' }}"
            >
                Create Account
            </button>
        </div>

        <div class="p-6 sm:p-8 space-y-6">
            <!-- Alert Messages -->
            @if ($errorMessage)
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2">
                    <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ $errorMessage }}</span>
                </div>
            @endif

            @if ($successMessage)
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <span>{{ $successMessage }}</span>
                </div>
            @endif

            <!-- SIGN IN FLOW -->
            @if ($mode === 'login')
                <div class="flex items-center justify-center gap-2 p-1 bg-slate-100 rounded-xl text-xs font-medium mb-4">
                    <button
                        type="button"
                        wire:click="switchLoginMethod('password')"
                        class="flex-1 py-1.5 rounded-lg transition {{ $loginMethod === 'password' ? 'bg-white font-bold text-slate-800 shadow-xs' : 'text-slate-500' }}"
                    >
                        Password Login
                    </button>
                    <button
                        type="button"
                        wire:click="switchLoginMethod('otp')"
                        class="flex-1 py-1.5 rounded-lg transition {{ $loginMethod === 'otp' ? 'bg-white font-bold text-indigo-700 shadow-xs' : 'text-slate-500' }}"
                    >
                        Login with OTP
                    </button>
                </div>

                @if ($loginMethod === 'password')
                    <!-- Standard Password Login -->
                    <form wire:submit="login" class="space-y-4">
                        <div>
                            <label for="loginIdentifier" class="block text-xs font-bold text-slate-700 mb-1">
                                Email or Mobile Number <span class="text-rose-500">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"/></svg>
                                </span>
                                <input
                                    id="loginIdentifier"
                                    type="text"
                                    wire:model="loginIdentifier"
                                    placeholder="e.g. 017XXXXXXXX or customer@retail.com"
                                    class="w-full pl-10 pr-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                    autofocus
                                />
                            </div>
                            @error('loginIdentifier') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div x-data="{ showPass: false }">
                            <div class="flex items-center justify-between mb-1">
                                <label for="loginPassword" class="block text-xs font-bold text-slate-700">
                                    Password <span class="text-rose-500">*</span>
                                </label>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                </span>
                                <input
                                    id="loginPassword"
                                    :type="showPass ? 'text' : 'password'"
                                    wire:model="loginPassword"
                                    placeholder="••••••••"
                                    class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                />
                                <button
                                    type="button"
                                    @click="showPass = !showPass"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-slate-400 hover:text-slate-600 cursor-pointer"
                                >
                                    <svg x-show="!showPass" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="showPass" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                                </button>
                            </div>
                            @error('loginPassword') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div class="flex items-center justify-between text-xs pt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="remember" class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"/>
                                <span class="text-slate-600">Remember this device</span>
                            </label>
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold text-xs shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <span wire:loading.remove>Sign In to Account</span>
                            <span wire:loading class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Authenticating...
                            </span>
                        </button>
                    </form>
                @else
                    <!-- Instant Login with OTP -->
                    @if ($loginStep === 'phone')
                        <form wire:submit="sendLoginOtp" class="space-y-4">
                            <div>
                                <label for="otpPhone" class="block text-xs font-bold text-slate-700 mb-1">
                                    Registered Mobile Number <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    id="otpPhone"
                                    type="text"
                                    wire:model="otpPhone"
                                    placeholder="017XXXXXXXX"
                                    class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                    autofocus
                                />
                                @error('otpPhone') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                            </div>

                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="w-full py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span wire:loading.remove>Send Login OTP</span>
                                <span wire:loading class="flex items-center gap-2">
                                    <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    Sending Code...
                                </span>
                            </button>
                        </form>
                    @else
                        <!-- Enter Login OTP -->
                        <form wire:submit="verifyLoginOtp" class="space-y-4">
                            <div class="text-center space-y-1">
                                <p class="text-xs text-slate-600">Enter the 6-digit OTP sent to <strong class="text-slate-900">{{ $otpPhone }}</strong></p>
                            </div>

                            <div>
                                <label for="loginOtpCode" class="block text-xs font-bold text-slate-700 mb-1 text-center">
                                    Enter 6-digit OTP Code <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    id="loginOtpCode"
                                    type="text"
                                    wire:model="otpCode"
                                    placeholder="123456"
                                    maxlength="8"
                                    class="w-full px-3.5 py-3 rounded-xl border border-slate-300 text-center font-mono text-lg tracking-widest focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                    autofocus
                                />
                                @error('otpCode') <span class="text-rose-600 text-[11px] block mt-1 text-center">{{ $message }}</span> @enderror
                            </div>

                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="w-full py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <span wire:loading.remove>Verify & Sign In</span>
                                <span wire:loading class="flex items-center gap-2">
                                    <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    Verifying...
                                </span>
                            </button>

                            <div class="flex items-center justify-between text-xs pt-2">
                                <button
                                    type="button"
                                    wire:click="$set('loginStep', 'phone')"
                                    class="text-slate-500 hover:text-slate-800"
                                >
                                    &larr; Change Number
                                </button>
                                <button
                                    type="button"
                                    wire:click="resendOtp"
                                    class="text-indigo-600 hover:underline font-semibold"
                                >
                                    Resend Code
                                </button>
                            </div>
                        </form>
                    @endif
                @endif
            @else
                <!-- REGISTRATION FLOW -->
                @if ($step === 'form')
                    <form wire:submit="register" class="space-y-4">
                        <div>
                            <label for="registerName" class="block text-xs font-bold text-slate-700 mb-1">
                                Full Name <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="registerName"
                                type="text"
                                wire:model="registerName"
                                placeholder="e.g. Tanvir Ahmed"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                autofocus
                            />
                            @error('registerName') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="registerPhone" class="block text-xs font-bold text-slate-700 mb-1">
                                Bangladeshi Mobile Number <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="registerPhone"
                                type="text"
                                wire:model="registerPhone"
                                placeholder="017XXXXXXXX"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                            />
                            @error('registerPhone') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label for="registerEmail" class="block text-xs font-bold text-slate-700 mb-1">
                                Email Address <span class="text-rose-500">*</span>
                            </label>
                            <input
                                id="registerEmail"
                                type="email"
                                wire:model="registerEmail"
                                placeholder="name@example.com"
                                class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                            />
                            @error('registerEmail') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="registerPassword" class="block text-xs font-bold text-slate-700 mb-1">
                                    Password <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    id="registerPassword"
                                    type="password"
                                    wire:model="registerPassword"
                                    placeholder="Min. 8 characters"
                                    class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                />
                                @error('registerPassword') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label for="registerPasswordConfirmation" class="block text-xs font-bold text-slate-700 mb-1">
                                    Confirm Password <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    id="registerPasswordConfirmation"
                                    type="password"
                                    wire:model="registerPasswordConfirmation"
                                    placeholder="Re-type password"
                                    class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                />
                            </div>
                        </div>

                        <div class="pt-1">
                            <label class="flex items-start gap-2 cursor-pointer text-[11px] text-slate-600">
                                <input type="checkbox" wire:model="agreeTerms" class="mt-0.5 w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"/>
                                <span>I agree to B2B Haat's Customer Terms, Escrow Protection Rules & Privacy Policy.</span>
                            </label>
                            @error('agreeTerms') <span class="text-rose-600 text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold text-xs shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <span wire:loading.remove>Verify Phone & Create Account</span>
                            <span wire:loading class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Sending Verification Code...
                            </span>
                        </button>
                    </form>
                @else
                    <!-- OTP VERIFICATION STEP -->
                    <form wire:submit="completeRegistration" class="space-y-4">
                        <div class="text-center space-y-1">
                            <h2 class="text-sm font-bold text-slate-800">Verify Your Phone Number</h2>
                            <p class="text-xs text-slate-600">
                                Enter 6-digit OTP code sent to <strong class="text-slate-900">{{ $registerPhone }}</strong>
                            </p>
                        </div>

                        <div>
                            <input
                                type="text"
                                wire:model="otpCode"
                                placeholder="123456"
                                maxlength="8"
                                class="w-full px-3.5 py-3 rounded-xl border border-slate-300 text-center font-mono text-xl tracking-widest focus:ring-2 focus:ring-indigo-600 focus:border-indigo-600 transition"
                                autofocus
                            />
                            @error('otpCode') <span class="text-rose-600 text-[11px] block mt-1 text-center">{{ $message }}</span> @enderror
                        </div>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-md transition flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <span wire:loading.remove>Confirm & Activate Account</span>
                            <span wire:loading class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Verifying Code...
                            </span>
                        </button>

                        <div class="flex items-center justify-between text-xs pt-2">
                            <button
                                type="button"
                                wire:click="$set('step', 'form')"
                                class="text-slate-500 hover:text-slate-800"
                            >
                                &larr; Edit Details
                            </button>
                            <button
                                type="button"
                                wire:click="resendOtp"
                                class="text-indigo-600 hover:underline font-semibold"
                            >
                                Resend OTP
                            </button>
                        </div>
                    </form>
                @endif
            @endif

            <!-- Commercial Cross-Links -->
            <div class="pt-4 border-t border-slate-100 space-y-2 text-xs">
                <a href="{{ route('wholesale.upgrade') }}" class="flex items-center justify-between p-3 rounded-xl bg-indigo-50/70 hover:bg-indigo-50 text-indigo-900 font-semibold transition border border-indigo-100">
                    <span class="flex items-center gap-2">
                        <span class="px-1.5 py-0.5 rounded bg-indigo-200 text-indigo-800 text-[10px] font-bold">B2B</span>
                        <span>Register for Wholesale Bulk Sourcing</span>
                    </span>
                    <span class="text-indigo-600">&rarr;</span>
                </a>

                <a href="{{ route('seller.register') }}" class="flex items-center justify-between p-3 rounded-xl bg-emerald-50/70 hover:bg-emerald-50 text-emerald-900 font-semibold transition border border-emerald-100">
                    <span class="flex items-center gap-2">
                        <span class="px-1.5 py-0.5 rounded bg-emerald-200 text-emerald-800 text-[10px] font-bold">Seller</span>
                        <span>Open Factory / Vendor Store</span>
                    </span>
                    <span class="text-emerald-600">&rarr;</span>
                </a>
            </div>

            <!-- Portal Port Links -->
            <div class="text-center text-[11px] text-slate-500 pt-2">
                Merchant Portals:
                <a href="/seller/login" class="text-indigo-600 hover:underline font-semibold ml-1">Seller Center</a>
                <span class="mx-1">•</span>
                <a href="/admin/login" class="text-amber-600 hover:underline font-semibold">Super Admin</a>
            </div>

            <!-- Non-Production Quick Demo Switcher -->
            @if (! $isProduction)
                <div class="mt-6 p-3.5 rounded-2xl bg-slate-50 border border-slate-200 text-slate-600 text-xs space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-[11px] uppercase tracking-wider text-slate-500">Local Testing Personas:</span>
                        <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px] font-mono">dev-mode</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5 text-center text-[11px]">
                        <a href="{{ route('demo.login', 'retail') }}" class="py-1 px-2 rounded-lg bg-white border border-slate-200 hover:border-slate-300 font-medium">Buyer</a>
                        <a href="{{ route('demo.login', 'wholesale') }}" class="py-1 px-2 rounded-lg bg-indigo-50 border border-indigo-200 hover:bg-indigo-100 font-medium text-indigo-700">Wholesale</a>
                        <a href="{{ route('demo.login', 'seller') }}" class="py-1 px-2 rounded-lg bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 font-medium text-emerald-700">Seller</a>
                        <a href="{{ route('demo.login', 'admin') }}" class="py-1 px-2 rounded-lg bg-amber-50 border border-amber-200 hover:bg-amber-100 font-medium text-amber-700">Admin</a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
