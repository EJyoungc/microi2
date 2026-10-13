<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $phoneNumber = '';
    public string $physicalAddress = '';

    // Organization details (if current team is an organization)
    public bool $isOrganizationTeam = false;
    public string $organizationName = '';
    public string $registrationNumber = '';
    public string $businessAddress = '';
    public string $contactPhone = '';
    public string $contactEmail = '';
    public string $orgType = 'commercial';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phoneNumber = $user->phone_number ?? '';
        $this->physicalAddress = $user->physical_address ?? '';

        $currentTeam = $user->currentTeam;
        if ($currentTeam && ! $currentTeam->is_personal) {
            $this->isOrganizationTeam = true;
            $this->organizationName = $currentTeam->name;
            $this->registrationNumber = $currentTeam->registration_number ?? '';
            $this->businessAddress = $currentTeam->business_address ?? '';
            $this->contactPhone = $currentTeam->contact_phone ?? '';
            $this->contactEmail = $currentTeam->contact_email ?? '';
            $this->orgType = $currentTeam->org_type ?? 'commercial';
        }
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => $this->emailRules($user->id),
            'phoneNumber' => ['nullable', 'string', 'max:30'],
            'physicalAddress' => ['nullable', 'string', 'max:255'],
        ]);

        $user->name = $this->name;
        $user->email = $this->email;
        $user->phone_number = $this->phoneNumber ?: null;
        $user->physical_address = $this->physicalAddress ?: null;

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // If organization settings are editable
        if ($this->isOrganizationTeam && $user->currentTeam) {
            $team = $user->currentTeam;
            $this->validate([
                'organizationName' => ['required', 'string', 'max:255'],
                'registrationNumber' => ['nullable', 'string', 'max:64'],
                'businessAddress' => ['nullable', 'string', 'max:255'],
                'contactPhone' => ['nullable', 'string', 'max:30'],
                'contactEmail' => ['nullable', 'email', 'max:255'],
            ]);

            $team->update([
                'name' => $this->organizationName,
                'registration_number' => $this->registrationNumber ?: null,
                'business_address' => $this->businessAddress ?: null,
                'contact_phone' => $this->contactPhone ?: null,
                'contact_email' => $this->contactEmail ?: null,
                'org_type' => $this->orgType,
            ]);
        }

        Flux::toast(variant: 'success', text: __('Profile and settings updated successfully.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile & Account Details')" :subheading="__('Update your personal details, verified contact information, and fleet organization configuration.')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <!-- User Information Section -->
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Personal Contact Information') }}</flux:heading>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input wire:model="name" :label="__('Full Name')" type="text" required autofocus autocomplete="name" />
                    <flux:input wire:model="phoneNumber" :label="__('Phone Number')" type="text" placeholder="+265 99..." />
                </div>

                <div>
                    <flux:input wire:model="email" :label="__('Email Address')" type="email" required autocomplete="email" />

                    @if ($this->hasUnverifiedEmail)
                        <div>
                            <flux:text class="mt-4">
                                {{ __('Your email address is unverified.') }}

                                <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                    {{ __('Click here to re-send the verification email.') }}
                                </flux:link>
                            </flux:text>

                            @if (session('status') === 'verification-link-sent')
                                <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                    {{ __('A new verification link has been sent to your email address.') }}
                                </flux:text>
                            @endif
                        </div>
                    @endif
                </div>

                <flux:input wire:model="physicalAddress" :label="__('Physical / Residential Location')" placeholder="City, Area, Road" />
            </div>

            <!-- Organization Fleet Details (If applicable) -->
            @if ($isOrganizationTeam)
                <div class="pt-6 border-t border-zinc-200 dark:border-zinc-800 space-y-4">
                    <div>
                        <div class="inline-flex items-center gap-2">
                            <flux:heading size="lg">{{ __('Organization Settings') }}</flux:heading>
                            <flux:badge color="sky" size="sm">Active Organization</flux:badge>
                        </div>
                        <flux:subheading>{{ __('Configure profile and contact details for this fleet organization.') }}</flux:subheading>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:input wire:model="organizationName" :label="__('Organization Name')" required />
                        <flux:input wire:model="registrationNumber" :label="__('Registration Number (e.g. NGO / BRN)')" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:input wire:model="contactEmail" :label="__('Official Contact Email')" type="email" />
                        <flux:input wire:model="contactPhone" :label="__('Official Contact Phone')" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <flux:select wire:model="orgType" :label="__('Organization Type')">
                            <flux:select.option value="commercial">Commercial Transport / Logistics</flux:select.option>
                            <flux:select.option value="ngo">NGO / Relief Organization</flux:select.option>
                            <flux:select.option value="government">Government Agency</flux:select.option>
                            <flux:select.option value="enterprise">Corporate Fleet</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="businessAddress" :label="__('Headquarters / Operating Address')" />
                    </div>
                </div>
            @endif

            <div class="flex items-center gap-4 pt-2">
                <flux:button variant="primary" type="submit" data-test="update-profile-button">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <div class="mt-10 pt-6 border-t border-zinc-200 dark:border-zinc-800">
                <livewire:pages::settings.delete-user-form />
            </div>
        @endif
    </x-pages::settings.layout>
</section>

