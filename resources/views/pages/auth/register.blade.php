<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Register')" />
        @endif

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <div x-data="{ accountType: '{{ old('account_type', 'individual') }}' }" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Register As') }}</flux:label>
                    <div class="grid grid-cols-2 gap-3 mt-1">
                        <label class="flex items-center justify-center p-3 rounded-lg border cursor-pointer text-sm font-medium transition"
                               :class="accountType === 'individual' ? 'border-primary-600 bg-primary-50 dark:bg-primary-950/40 text-primary-700 dark:text-primary-300' : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400'">
                            <input type="radio" name="account_type" value="individual" x-model="accountType" class="sr-only">
                            <span>🚗 {{ __('Individual Owner') }}</span>
                        </label>
                        <label class="flex items-center justify-center p-3 rounded-lg border cursor-pointer text-sm font-medium transition"
                               :class="accountType === 'organization' ? 'border-primary-600 bg-primary-50 dark:bg-primary-950/40 text-primary-700 dark:text-primary-300' : 'border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400'">
                            <input type="radio" name="account_type" value="organization" x-model="accountType" class="sr-only">
                            <span>🏢 {{ __('Organization / Fleet') }}</span>
                        </label>
                    </div>
                </flux:field>

                <div x-show="accountType === 'organization'" x-cloak class="space-y-4 pt-2 border-t border-zinc-200 dark:border-zinc-800">
                    <flux:input
                        name="organization_name"
                        :label="__('Organization Name')"
                        :value="old('organization_name')"
                        type="text"
                        :placeholder="__('e.g. Apex Logistics or Health NGO')"
                    />

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <flux:input
                            name="registration_number"
                            :label="__('Business Reg / Tax ID (Optional)')"
                            :value="old('registration_number')"
                            type="text"
                            :placeholder="__('e.g. REG-2024-001')"
                        />
                        <flux:input
                            name="phone_number"
                            :label="__('Contact Phone')"
                            :value="old('phone_number')"
                            type="tel"
                            :placeholder="__('+265 999 000 000')"
                        />
                    </div>

                    <flux:input
                        name="business_address"
                        :label="__('Business Address')"
                        :value="old('business_address')"
                        type="text"
                        :placeholder="__('e.g. Area 4, Lilongwe, Malawi')"
                    />
                </div>
            </div>

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link
                :href="$teamInvitation ? route('login', ['invitation' => $teamInvitation['code']]) : route('login')"
                data-test="team-invitation-login-link"
                wire:navigate
            >
                {{ __('Log in') }}
            </flux:link>
        </div>
    </div>
</x-layouts::auth>
