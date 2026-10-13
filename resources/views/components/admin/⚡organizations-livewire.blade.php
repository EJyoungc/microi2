<?php

use App\Models\AuditLog;
use App\Models\Team;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public ?int $selectedTeamId = null;
    public string $rejectionReason = '';
    public bool $showRejectModal = false;

    #[Computed]
    public function organizations()
    {
        return Team::query()
            ->where('is_personal', false)
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('contact_email', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->withCount(['vehicles', 'members'])
            ->latest()
            ->paginate(10);
    }

    public function approve(int $id): void
    {
        $team = Team::findOrFail($id);
        $team->update(['status' => 'approved', 'rejection_reason' => null]);

        AuditLog::record('organization.approved', $team, ['status' => 'approved']);
        Flux::toast(variant: 'success', text: __("Organization ':name' has been approved.", ['name' => $team->name]));
    }

    public function openRejectModal(int $id): void
    {
        $this->selectedTeamId = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
    }

    public function reject(): void
    {
        $this->validate(['rejectionReason' => 'required|string|min:5']);

        $team = Team::findOrFail($this->selectedTeamId);
        $team->update([
            'status' => 'rejected',
            'rejection_reason' => $this->rejectionReason,
        ]);

        AuditLog::record('organization.rejected', $team, ['status' => 'rejected', 'reason' => $this->rejectionReason]);
        $this->showRejectModal = false;
        Flux::toast(variant: 'danger', text: __("Organization ':name' rejected.", ['name' => $team->name]));
    }

    public function toggleSuspend(int $id): void
    {
        $team = Team::findOrFail($id);
        $newStatus = $team->status === 'suspended' ? 'approved' : 'suspended';
        $team->update(['status' => $newStatus]);

        AuditLog::record('organization.status_changed', $team, ['status' => $newStatus]);
        Flux::toast(variant: 'warning', text: __("Organization ':name' marked as :status.", ['name' => $team->name, 'status' => $newStatus]));
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Organizations & Tenants') }}</flux:heading>
            <flux:subheading>{{ __('Review registrations, verify companies, and manage fleet multi-tenancy.') }}</flux:subheading>
        </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search organization name or email..." icon="magnifying-glass" />
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="statusFilter">
                <flux:select.option value="">All Statuses</flux:select.option>
                <flux:select.option value="pending">Pending Approval</flux:select.option>
                <flux:select.option value="approved">Approved</flux:select.option>
                <flux:select.option value="rejected">Rejected</flux:select.option>
                <flux:select.option value="suspended">Suspended</flux:select.option>
            </flux:select>
        </div>
    </div>

    <!-- Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-white dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 font-medium">
                <tr>
                    <th class="p-4">Organization</th>
                    <th class="p-4">Type / Reg No</th>
                    <th class="p-4">Contact</th>
                    <th class="p-4">Vehicles</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->organizations as $org)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="p-4">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $org->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $org->business_address ?? 'No address provided' }}</div>
                        </td>
                        <td class="p-4 text-zinc-600 dark:text-zinc-400">
                            <div>{{ ucfirst($org->org_type ?? 'corporate') }}</div>
                            <div class="text-xs text-zinc-500">{{ $org->registration_number ?? 'N/A' }}</div>
                        </td>
                        <td class="p-4 text-zinc-600 dark:text-zinc-400">
                            <div>{{ $org->contact_email ?? 'N/A' }}</div>
                            <div class="text-xs text-zinc-500">{{ $org->contact_phone ?? 'N/A' }}</div>
                        </td>
                        <td class="p-4 font-medium">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                                🚗 {{ $org->vehicles_count }} vehicles
                            </span>
                        </td>
                        <td class="p-4">
                            @if ($org->status === 'approved')
                                <flux:badge color="green" size="sm">Approved</flux:badge>
                            @elseif ($org->status === 'pending')
                                <flux:badge color="amber" size="sm">Pending Approval</flux:badge>
                            @elseif ($org->status === 'rejected')
                                <flux:badge color="red" size="sm">Rejected</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Suspended</flux:badge>
                            @endif
                        </td>
                        <td class="p-4 text-right space-x-2">
                            @if ($org->status === 'pending')
                                <flux:button size="sm" variant="primary" wire:click="approve({{ $org->id }})">
                                    Approve
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="openRejectModal({{ $org->id }})">
                                    Reject
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="subtle" wire:click="toggleSuspend({{ $org->id }})">
                                    {{ $org->status === 'suspended' ? 'Restore' : 'Suspend' }}
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-zinc-500">
                            No organizations found matching your criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $this->organizations->links() }}
    </div>

    <!-- Rejection Modal -->
    <flux:modal wire:model="showRejectModal" name="reject-org-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Reject Organization Registration</flux:heading>
            <flux:subheading>Provide the reason for rejecting this organization application.</flux:subheading>

            <flux:textarea wire:model="rejectionReason" placeholder="State reason (e.g. Invalid business registration documents)..." required />

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showRejectModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="reject">Confirm Rejection</flux:button>
            </div>
        </div>
    </flux:modal>
</div>