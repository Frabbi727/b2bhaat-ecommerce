<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\Role;
use App\Models\User;
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

    // Login Form State
    public string $loginIdentifier = ''; // Email or Mobile Number

    public string $loginPassword = '';

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

        $credentials = [
            $isEmail ? 'email' : 'phone' => $identifier,
            'password' => $this->loginPassword,
        ];

        // Ensure user is active
        $user = $isEmail
            ? User::where('email', $identifier)->first()
            : User::where('phone', $identifier)->first();

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

    public function register(): void
    {
        $this->errorMessage = '';
        $this->successMessage = '';

        $this->validate([
            'registerName' => 'required|string|min:3|max:255',
            'registerPhone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/', 'unique:users,phone'],
            'registerEmail' => 'required|email|max:255|unique:users,email',
            'registerPassword' => 'required|string|min:8|same:registerPasswordConfirmation',
            'agreeTerms' => 'accepted',
        ], [
            'registerName.required' => 'Please enter your full name.',
            'registerName.min' => 'Name must be at least 3 characters.',
            'registerPhone.required' => 'Please provide a valid Bangladeshi phone number.',
            'registerPhone.regex' => 'Please enter a valid BD phone number (e.g., 017XXXXXXXX or +88017XXXXXXXX).',
            'registerPhone.unique' => 'An account with this phone number already exists.',
            'registerEmail.required' => 'Please enter your email address.',
            'registerEmail.unique' => 'An account with this email address already exists.',
            'registerPassword.min' => 'Password must be at least 8 characters long.',
            'registerPassword.same' => 'Password confirmation does not match.',
            'agreeTerms.accepted' => 'You must agree to the Terms of Service & Privacy Policy.',
        ]);

        $retailRole = Role::where('slug', 'retail_buyer')->first()
            ?? Role::where('slug', 'retail')->firstOrFail();

        $user = User::create([
            'role_id' => $retailRole->id,
            'name' => trim($this->registerName),
            'phone' => trim($this->registerPhone),
            'email' => strtolower(trim($this->registerEmail)),
            'password' => Hash::make($this->registerPassword),
            'is_active' => true,
        ]);

        Auth::login($user, true);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        session()->flash('success', 'Your buyer account has been created successfully!');

        $this->redirectIntended(route('customer.orders'));
    }

    public function render()
    {
        return view('livewire.storefront.customer-auth', [
            'isProduction' => app()->isProduction(),
        ])->title($this->mode === 'register' ? 'Create Account - B2B Haat' : 'Sign In - B2B Haat');
    }
}
