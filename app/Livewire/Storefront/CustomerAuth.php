<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\Role;
use App\Models\User;
use App\Services\Sms\OtpService;
use App\Services\Sms\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CustomerAuth extends Component
{
    #[Url(as: 'tab')]
    public string $mode = 'login'; // 'login' or 'register'

    // Multi-step Registration
    public string $step = 'form'; // 'form' or 'otp_verification'

    // Login Form State
    public string $loginMethod = 'password'; // 'password' or 'otp'

    public string $loginStep = 'phone'; // 'phone' or 'otp'

    public string $loginIdentifier = ''; // Email or Mobile Number

    public string $loginPassword = '';

    public string $otpPhone = '';

    public string $otpCode = '';

    public bool $remember = true;

    // Registration Form State
    public string $registerName = '';

    public string $registerPhone = '';

    public string $registerEmail = '';

    public string $registerPassword = '';

    public string $registerPasswordConfirmation = '';

    public bool $agreeTerms = true;

    // Feedback
    public string $errorMessage = '';

    public string $successMessage = '';

    public function mount(?string $tab = null): void
    {
        if (Auth::check()) {
            $this->redirectIntended(route('customer.orders'));

            return;
        }

        if (request()->routeIs('register')) {
            $this->mode = 'register';
        } elseif ($tab && in_array($tab, ['login', 'register'], true)) {
            $this->mode = $tab;
        }
    }

    public function switchMode(string $mode): void
    {
        $this->mode = in_array($mode, ['login', 'register'], true) ? $mode : 'login';
        $this->step = 'form';
        $this->loginStep = 'phone';
        $this->otpCode = '';
        $this->errorMessage = '';
        $this->successMessage = '';
    }

    public function switchLoginMethod(string $method): void
    {
        $this->loginMethod = in_array($method, ['password', 'otp'], true) ? $method : 'password';
        $this->loginStep = 'phone';
        $this->otpCode = '';
        $this->errorMessage = '';
        $this->successMessage = '';
    }

    public function login(): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'loginIdentifier' => 'required|string',
            'loginPassword' => 'required|string',
        ], [
            'loginIdentifier.required' => 'Please enter your email or Bangladeshi mobile number.',
            'loginPassword.required' => 'Please enter your account password.',
        ]);

        $throttleKey = Str::transliterate(Str::lower($this->loginIdentifier).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->errorMessage = "Too many failed login attempts. Please try again in {$seconds} seconds.";

            return;
        }

        $identifier = trim($this->loginIdentifier);
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;

        $normalizedPhone = null;
        if (! $isEmail && PhoneNormalizer::isValid($identifier)) {
            $normalizedPhone = PhoneNormalizer::normalize($identifier);
        }

        $user = $isEmail
            ? User::where('email', strtolower($identifier))->first()
            : User::where('phone', $normalizedPhone ?? $identifier)->first();

        if (! $user || ! Hash::check($this->loginPassword, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            $this->errorMessage = 'Invalid credentials. Please verify your email/phone and password.';

            return;
        }

        if (! $user->is_active) {
            $this->errorMessage = 'Your account has been deactivated. Please contact customer helpline.';

            return;
        }

        RateLimiter::clear($throttleKey);
        Auth::login($user, $this->remember);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        session()->flash('success', "Welcome back, {$user->name}!");

        $this->redirectIntended(route('customer.orders'));
    }

    public function sendLoginOtp(OtpService $otpService): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'otpPhone' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! PhoneNormalizer::isValid($value)) {
                    $fail('Please enter a valid Bangladeshi mobile number (e.g., 017XXXXXXXX).');
                }
            }],
        ]);

        $normalized = PhoneNormalizer::normalize($this->otpPhone);
        $user = User::where('phone', $normalized)->first();

        if (! $user) {
            $this->errorMessage = 'No customer account found with this phone number. Please register first.';

            return;
        }

        if (! $user->is_active) {
            $this->errorMessage = 'This account is deactivated. Please contact support.';

            return;
        }

        $result = $otpService->sendOtp($normalized, 'login');

        if (! $result['success']) {
            if ($result['error'] === 'COOLDOWN_ACTIVE') {
                $this->errorMessage = "Please wait {$result['seconds_remaining']} seconds before requesting a new OTP.";
            } else {
                $this->errorMessage = 'Failed to dispatch OTP. Please try again.';
            }

            return;
        }

        $this->loginStep = 'otp';
        $this->successMessage = "We've sent a 6-digit verification code to {$this->otpPhone}.";
    }

    public function verifyLoginOtp(OtpService $otpService): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'otpCode' => 'required|string|min:4|max:8',
        ], [
            'otpCode.required' => 'Please enter the OTP received on your mobile.',
        ]);

        $normalized = PhoneNormalizer::normalize($this->otpPhone);
        $result = $otpService->verify($normalized, trim($this->otpCode), 'login');

        if (! $result['success']) {
            if ($result['error'] === 'EXPIRED') {
                $this->errorMessage = 'The OTP code has expired. Please request a new code.';
            } elseif ($result['error'] === 'MAX_ATTEMPTS_EXCEEDED') {
                $this->errorMessage = 'Maximum attempts reached. Please request a new OTP.';
            } else {
                $this->errorMessage = 'Invalid OTP verification code. Please check and try again.';
            }

            return;
        }

        $user = User::where('phone', $normalized)->firstOrFail();

        Auth::login($user, $this->remember);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        session()->flash('success', "Welcome back, {$user->name}!");

        $this->redirectIntended(route('customer.orders'));
    }

    public function register(OtpService $otpService): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'registerName' => 'required|string|min:3|max:255',
            'registerPhone' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (! PhoneNormalizer::isValid($value)) {
                        $fail('Please provide a valid Bangladeshi mobile number (e.g. 017XXXXXXXX).');

                        return;
                    }
                    $normalized = PhoneNormalizer::normalize($value);
                    if (User::where('phone', $normalized)->exists()) {
                        $fail('An account with this phone number already exists.');
                    }
                },
            ],
            'registerEmail' => 'required|email|max:255|unique:users,email',
            'registerPassword' => 'required|string|min:8|same:registerPasswordConfirmation',
            'agreeTerms' => 'accepted',
        ], [
            'registerName.required' => 'Please enter your full name.',
            'registerName.min' => 'Name must be at least 3 characters.',
            'registerEmail.required' => 'Please enter your email address.',
            'registerEmail.unique' => 'An account with this email address already exists.',
            'registerPassword.min' => 'Password must be at least 8 characters long.',
            'registerPassword.same' => 'Password confirmation does not match.',
            'agreeTerms.accepted' => 'You must agree to the Terms of Service & Privacy Policy.',
        ]);

        // Send registration OTP
        $result = $otpService->sendOtp($this->registerPhone, 'registration');

        if (! $result['success']) {
            if ($result['error'] === 'COOLDOWN_ACTIVE') {
                $this->errorMessage = "Please wait {$result['seconds_remaining']} seconds before requesting a new code.";
            } else {
                $this->errorMessage = 'Unable to send OTP at this moment. Please try again.';
            }

            return;
        }

        $this->step = 'otp_verification';
        $this->successMessage = "We've sent a 6-digit OTP code to {$this->registerPhone}. Please enter it below to verify your account.";
    }

    public function completeRegistration(OtpService $otpService): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'otpCode' => 'required|string|min:4|max:8',
        ], [
            'otpCode.required' => 'Please enter the 6-digit OTP code.',
        ]);

        $normalized = PhoneNormalizer::normalize($this->registerPhone);
        $result = $otpService->verify($normalized, trim($this->otpCode), 'registration');

        if (! $result['success']) {
            if ($result['error'] === 'EXPIRED') {
                $this->errorMessage = 'The OTP has expired. Please request a new code.';
            } elseif ($result['error'] === 'MAX_ATTEMPTS_EXCEEDED') {
                $this->errorMessage = 'Too many failed attempts. Please request a new OTP.';
            } else {
                $this->errorMessage = 'Invalid OTP verification code. Please check and try again.';
            }

            return;
        }

        $retailRole = Role::where('slug', 'retail_buyer')->first()
            ?? Role::where('slug', 'retail')->firstOrFail();

        $user = User::create([
            'role_id' => $retailRole->id,
            'name' => trim($this->registerName),
            'phone' => $normalized,
            'email' => strtolower(trim($this->registerEmail)),
            'password' => Hash::make($this->registerPassword),
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        Auth::login($user, true);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        session()->flash('success', 'Your buyer account has been verified and created successfully!');

        $this->redirectIntended(route('customer.orders'));
    }

    public function resendOtp(OtpService $otpService): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $phone = ($this->mode === 'register') ? $this->registerPhone : $this->otpPhone;
        $purpose = ($this->mode === 'register') ? 'registration' : 'login';

        if (empty($phone)) {
            $this->errorMessage = 'Phone number is missing.';

            return;
        }

        $result = $otpService->sendOtp($phone, $purpose);

        if (! $result['success']) {
            if ($result['error'] === 'COOLDOWN_ACTIVE') {
                $this->errorMessage = "Please wait {$result['seconds_remaining']}s before resending.";
            } else {
                $this->errorMessage = 'Unable to resend OTP. Please try again.';
            }

            return;
        }

        $this->successMessage = 'A new verification code has been dispatched to your mobile.';
    }

    public function render()
    {
        return view('livewire.storefront.customer-auth', [
            'isProduction' => app()->isProduction(),
        ])->title($this->mode === 'register' ? 'Create Account - B2B Haat' : 'Sign In - B2B Haat');
    }
}
