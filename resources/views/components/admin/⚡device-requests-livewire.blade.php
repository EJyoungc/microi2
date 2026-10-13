<?php

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceRequest;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $statusFilter = '';

    // Review / Assign Modal
    public bool $showAssignModal = false;
    public ?int $selectedRequestId = null;
    public ?int $selectedDeviceId = null;

    #[Computed]
    public function requests()
    {
        return DeviceRequest::query()
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['team', 'user', 'vehicle', 'reviewer'])
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function availableDevices()
    {
        return Device::query()
            ->whereDoesntHave('currentAssignment')
            ->where('status', 'active')
            ->get();
    }

    public function openAssign(int $requestId): void
    {
        $this->selectedRequestId = $requestId;
        $this->selectedDeviceId = null;
        $this->showAssignModal = true;
    }

    public function fulfillRequest(): void
    {
        $this->validate([
            'selectedRequestId' => 'required|exists:device_requests,id',
            'selectedDeviceId' => 'required|exists:devices,id',
        ]);

        $request = DeviceRequest::findOrFail($this->selectedRequestId);
        $device = Device::findOrFail($this->selectedDeviceId);

        // Terminate any previous assignment on vehicle
        DeviceAssignment::where('vehicle_id', $request->vehicle_id)
            ->where('status', 'active')
            ->update(['status' => 'terminated', 'unassigned_at' => now()]);

        // Create assignment
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'vehicle_id' => $request->vehicle_id,
            'assigned_by' => auth()->id(),
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        $request->update([
            'status' => 'assigned',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('device_request.fulfilled', $request, [
            'device_id' => $device->id,
            'vehicle_id' => $request->vehicle_id,
        ]);

        $this->showAssignModal = false;
        Flux::toast(variant: 'success', text: __('Device assigned and request completed!'));
    }

    public function rejectRequest(int $id): void
    {
        $request = DeviceRequest::findOrFail($id);
        $request->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        AuditLog::record('device_request.rejected', $request);
        Flux::toast(variant: 'warning', text: __('Device request marked as rejected.'));
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('GPS Device Requests') }}</flux:heading>
            <flux:subheading>{{ __('Review tracking hardware requests submitted by individual customers and fleet organizations.') }}</flux:subheading>
        </div>
    </div>

    <!-- Filter -->
    <div class="w-full sm:w-64">
        <flux:select wire:model.live="statusFilter">
            <flux:select.option value="">All Statuses</flux:select.option>
            <flux:select.option value="submitted">Submitted (Pending)</flux:select.option>
            <flux:select.option value="assigned">Assigned</flux:select.option>
            <flux:select.option value="rejected">Rejected</flux:select.option>
        </flux:select>
    </div>

    <!-- Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-white dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 font-medium">
                <tr>
                    <th class="p-4">Vehicle</th>
                    <th class="p-4">Requester / Organization</th>
                    <th class="p-4">Reason / Notes</th>
                    <th class="p-4">Date</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->requests as $req)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="p-4">
                            <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                🚗 {{ $req->vehicle->plate_number }}
                            </div>
                            <div class="text-xs text-zinc-500">{{ $req->vehicle->name }} ({{ $req->vehicle->make }} {{ $req->vehicle->model }})</div>
                        </td>
                        <td class="p-4">
                            <div class="text-zinc-900 dark:text-zinc-100">{{ $req->user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $req->team ? $req->team->name : 'Individual Account' }}</div>
                        </td>
                        <td class="p-4 text-xs text-zinc-600 dark:text-zinc-400 max-w-xs truncate">
                            {{ $req->reason ?? 'Standard tracking device request' }}
                        </td>
                        <td class="p-4 text-xs text-zinc-500">
                            {{ $req->created_at->format('M d, Y') }}
                        </td>
                        <td class="p-4">
                            @if ($req->status === 'assigned')
                                <flux:badge color="green" size="sm">Assigned</flux:badge>
                            @elseif ($req->status === 'submitted')
                                <flux:badge color="amber" size="sm">Submitted</flux:badge>
                            @elseif ($req->status === 'rejected')
                                <flux:badge color="red" size="sm">Rejected</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ ucfirst($req->status) }}</flux:badge>
                            @endif
                        </td>
                        <td class="p-4 text-right space-x-2">
                            @if ($req->status === 'submitted')
                                <flux:button size="sm" variant="primary" wire:click="openAssign({{ $req->id }})">
                                    Assign Device
                                </flux:button>
                                <flux:button size="sm" variant="danger" wire:click="rejectRequest({{ $req->id }})">
                                    Reject
                                </flux:button>
                            @else
                                <span class="text-xs text-zinc-400">Reviewed by {{ $req->reviewer->name ?? 'Staff' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-zinc-500">No device requests found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $this->requests->links() }}
    </div>

    <!-- Fulfillment Modal -->
    <flux:modal wire:model="showAssignModal" name="fulfill-request-modal" class="max-w-md">
        <form wire:submit="fulfillRequest" class="space-y-4">
            <flux:heading size="lg">Fulfill Request with Device</flux:heading>
            <flux:subheading>Select an unassigned GPS unit from available stock to activate tracking for this vehicle.</flux:subheading>

            <flux:select wire:model="selectedDeviceId" label="Available GPS Devices" required>
                <flux:select.option value="">Choose device...</flux:select.option>
                @foreach ($this->availableDevices as $d)
                    <flux:select.option value="{{ $d->id }}">
                        {{ $d->device_id }} ({{ $d->model }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showAssignModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Assign & Activate</flux:button>
            </div>
        </form>
    </flux:modal>
</div>