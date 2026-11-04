<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Live Balance Header Card -->
        <div class="p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <span class="text-xs uppercase tracking-wider text-indigo-300 font-bold">BulkSMSBD Account Status</span>
                <h2 class="text-2xl font-black mt-1">
                    {{ $liveBalance ?? 'Click to check current balance' }}
                </h2>
                <p class="text-xs text-slate-300 mt-1">
                    Live balance pulled directly from BulkSMSBD API endpoint.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <x-filament::button
                    wire:click="checkLiveBalance"
                    color="primary"
                    icon="heroicon-o-arrow-path"
                >
                    Refresh Live Balance
                </x-filament::button>
            </div>
        </div>

        <!-- Configuration Form -->
        <form wire:submit="save" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Gateway Credentials Card -->
                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 border-b pb-2 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-key" class="w-4 h-4 text-indigo-600" />
                        BulkSMSBD API Credentials
                    </h3>

                    <div class="space-y-4">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" wire:model="sms_enabled" class="w-4 h-4 rounded text-indigo-600 focus:ring-indigo-500 border-slate-300"/>
                            <span class="text-xs font-semibold text-slate-700">Enable SMS Dispatch System Globally</span>
                        </label>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Active SMS Driver</label>
                            <select wire:model="sms_driver" class="w-full text-xs rounded-xl border-slate-300 focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="bulksmsbd">BulkSMSBD (Production)</option>
                                <option value="log">Log Driver (Local Development)</option>
                                <option value="mock">Mock Driver (Testing)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">BulkSMSBD API Key</label>
                            <input type="text" wire:model="bulksmsbd_api_key" placeholder="Enter API Key from BulkSMSBD" class="w-full text-xs rounded-xl border-slate-300 font-mono"/>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Approved Sender ID</label>
                            <input type="text" wire:model="bulksmsbd_sender_id" placeholder="e.g. 8809612345678" class="w-full text-xs rounded-xl border-slate-300 font-mono"/>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">API Base URL</label>
                            <input type="text" wire:model="bulksmsbd_base_url" class="w-full text-xs rounded-xl border-slate-300 font-mono text-slate-600"/>
                        </div>
                    </div>
                </div>

                <!-- OTP Policies Card -->
                <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 border-b pb-2 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 text-emerald-600" />
                        OTP Security & Lifecycle Policies
                    </h3>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">OTP Digit Length</label>
                            <input type="number" wire:model="otp_length" min="4" max="8" class="w-full text-xs rounded-xl border-slate-300"/>
                            <span class="text-[11px] text-slate-500">Standard numeric length (default: 6 digits).</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">OTP Expiry Time (Minutes)</label>
                            <input type="number" wire:model="otp_expiry_minutes" min="1" max="60" class="w-full text-xs rounded-xl border-slate-300"/>
                            <span class="text-[11px] text-slate-500">How long an issued OTP remains valid before expiration (default: 5 mins).</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Resend Cooldown (Seconds)</label>
                            <input type="number" wire:model="otp_cooldown_seconds" min="15" max="300" class="w-full text-xs rounded-xl border-slate-300"/>
                            <span class="text-[11px] text-slate-500">Minimum wait time between consecutive OTP requests (default: 60s).</span>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Maximum Verification Attempts</label>
                            <input type="number" wire:model="otp_max_attempts" min="1" max="10" class="w-full text-xs rounded-xl border-slate-300"/>
                            <span class="text-[11px] text-slate-500">Failed attempts before an OTP is locked out against brute-force (default: 5).</span>
                        </div>

                        @if (\App\Filament\Admin\Pages\SmsSettings::fallbackOtpAllowed())
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Testing / Emergency Fallback Default OTP</label>
                            <input type="text" wire:model="default_otp" placeholder="e.g. 123456 (leave empty to disable)" class="w-full text-xs rounded-xl border-slate-300 font-mono text-indigo-700 font-bold"/>
                            <span class="text-[11px] text-amber-600">Temporary fallback code (e.g. 123456) when the SMS provider is down during local testing. Works only in local/testing environments. Clear to disable.</span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <x-filament::button type="submit" color="success" size="lg" icon="heroicon-o-check">
                    Save SMS & OTP Configuration
                </x-filament::button>
            </div>
        </form>

        <!-- Test SMS Card -->
        <div class="p-6 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-4">
            <h3 class="text-sm font-bold text-slate-900 border-b pb-2 flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-paper-airplane" class="w-4 h-4 text-indigo-600" />
                Live SMS Gateway Test Dispatch
            </h3>
            <div class="flex flex-col sm:flex-row items-center gap-3">
                <input
                    type="text"
                    wire:model="testPhone"
                    placeholder="Recipient phone (e.g. 017XXXXXXXX)"
                    class="w-full sm:w-80 text-xs rounded-xl border-slate-300"
                />
                <x-filament::button wire:click="sendTestSms" color="gray" icon="heroicon-o-paper-airplane">
                    Send Diagnostic SMS
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament-panels::page>
