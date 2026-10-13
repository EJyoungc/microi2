<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    public bool $showModal = false;
    public string $phoneNumber = '';
    public string $physicalAddress = '';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user && ! $user->onboarding_completed) {
            $this->showModal = true;
            $this->phoneNumber = $user->phone_number ?? '';
            $this->physicalAddress = $user->physical_address ?? '';
        }
    }

    public function completeOnboarding(): void
    {
        $this->validate([
            'phoneNumber' => ['required', 'string', 'min:7', 'max:30'],
            'physicalAddress' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $user = Auth::user();
        $user->update([
            'phone_number' => $this->phoneNumber,
            'physical_address' => $this->physicalAddress,
            'onboarding_completed' => true,
        ]);

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Profile onboarding completed! Welcome to Microi.'));
    }
}; ?>

<div>
    @if ($showModal)
        <flux:modal name="onboarding-modal" wire:model="showModal" class="max-w-md" :dismissible="false">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Complete Your Profile') }}</flux:heading>
                    <flux:subheading>{{ __('Before tracking your vehicles, please verify your essential contact information.') }}</flux:subheading>
                </div>

                <form wire:submit="completeOnboarding" class="space-y-4">
                    <flux:input
                        wire:model="phoneNumber"
                        :label="__('Contact Phone Number')"
                        placeholder="+265 999 123 456"
                        required
                    />

                    <flux:input
                        wire:model="physicalAddress"
                        :label="__('Address / Location in Malawi')"
                        placeholder="e.g. Area 10, Lilongwe / Blantyre"
                        required
                    />

                    <div class="flex justify-end pt-2">
                        <flux:button type="submit" variant="primary" class="w-full">
                            {{ __('Complete Onboarding & Proceed') }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>