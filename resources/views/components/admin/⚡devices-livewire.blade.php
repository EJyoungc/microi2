<?php

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';

    // Create / Edit State
    public bool $showCreateModal = false;
    public string $deviceId = '';
    public string $serialNumber = '';
    public string $model = 'MicroMek-T100';

    // Provision Secret Revealed Modal
    public bool $showKeyModal = false;
    public string $generatedKey = '';
    public string $currentDeviceId = '';

    // Assignment Modal State
    public bool $showAssignModal = false;
    public ?int $selectedDeviceId = null;
    public ?int $selectedVehicleId = null;

    #[Computed]
    public function devices()
    {
        return Device::query()
            ->when($this->search, function ($q) {
                $q->where('device_id', 'like', "%{$this->search}%")
                    ->orWhere('serial_number', 'like', "%{$this->search}%")
                    ->orWhere('model', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['currentAssignment.vehicle'])
            ->latest()
            ->paginate(10);
    }

    #[Computed]
    public function unassignedVehicles()
    {
        return Vehicle::query()
            ->whereDoesntHave('currentAssignment')
            ->where('status', 'active')
            ->get();
    }

    public function createDevice(): void
    {
        $this->validate([
            'deviceId' => 'required|string|unique:devices,device_id|max:64',
            'serialNumber' => 'nullable|string|unique:devices,serial_number|max:64',
            'model' => 'required|string|max:100',
        ]);

        $plainSecret = 'MEK_' . Str::upper(Str::random(24));

        $device = Device::create([
            'device_id' => $this->deviceId,
            'serial_number' => $this->serialNumber ?: null,
            'model' => $this->model,
            'api_key_hash' => Hash::make($plainSecret),
            'api_key_prefix' => substr($plainSecret, 0, 8),
            'provisioning_status' => 'provisioned',
            'status' => 'active',
        ]);

        AuditLog::record('device.created', $device, ['device_id' => $device->device_id]);

        $this->showCreateModal = false;
        $this->generatedKey = $plainSecret;
        $this->currentDeviceId = $device->device_id;
        $this->showKeyModal = true;

        $this->reset(['deviceId', 'serialNumber']);
        Flux::toast(variant: 'success', text: __('GPS Device registered successfully!'));
    }

    public function rotateKey(int $id): void
    {
        $device = Device::findOrFail($id);
        $plainSecret = 'MEK_' . Str::upper(Str::random(24));

        $device->update([
            'api_key_hash' => Hash::make($plainSecret),
            'api_key_prefix' => substr($plainSecret, 0, 8),
            'provisioning_status' => 'active',
        ]);

        AuditLog::record('device.key_rotated', $device);

        $this->generatedKey = $plainSecret;
        $this->currentDeviceId = $device->device_id;
        $this->showKeyModal = true;

        Flux::toast(variant: 'warning', text: __('Device secret credentials rotated.'));
    }

    public function openAssignModal(int $id): void
    {
        $this->selectedDeviceId = $id;
        $this->selectedVehicleId = null;
        $this->showAssignModal = true;
    }

    public function assignDevice(): void
    {
        $this->validate([
            'selectedDeviceId' => 'required|exists:devices,id',
            'selectedVehicleId' => 'required|exists:vehicles,id',
        ]);

        // Terminate any previous assignment for vehicle or device
        DeviceAssignment::where('device_id', $this->selectedDeviceId)
            ->where('status', 'active')
            ->update(['status' => 'terminated', 'unassigned_at' => now()]);

        DeviceAssignment::where('vehicle_id', $this->selectedVehicleId)
            ->where('status', 'active')
            ->update(['status' => 'terminated', 'unassigned_at' => now()]);

        // Create new active assignment
        $assignment = DeviceAssignment::create([
            'device_id' => $this->selectedDeviceId,
            'vehicle_id' => $this->selectedVehicleId,
            'assigned_by' => auth()->id(),
            'assigned_at' => now(),
            'status' => 'active',
        ]);

        AuditLog::record('device.assigned', $assignment, [
            'device_id' => $this->selectedDeviceId,
            'vehicle_id' => $this->selectedVehicleId,
        ]);

        $this->showAssignModal = false;
        Flux::toast(variant: 'success', text: __('GPS Device assigned to vehicle successfully.'));
    }

    public function unassignDevice(int $id): void
    {
        $device = Device::findOrFail($id);
        if ($device->currentAssignment) {
            $device->currentAssignment->update([
                'status' => 'terminated',
                'unassigned_at' => now(),
            ]);

            AuditLog::record('device.unassigned', $device);
            Flux::toast(variant: 'info', text: __('Device has been unassigned from vehicle.'));
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('GPS Device Inventory') }}</flux:heading>
            <flux:subheading>{{ __('Provision MicroMek GPS hardware, generate API tokens, and assign to vehicles.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
            {{ __('Register New Device') }}
        </flux:button>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search Device ID or serial number..." icon="magnifying-glass" />
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="statusFilter">
                <flux:select.option value="">All Statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Inactive</flux:select.option>
            </flux:select>
        </div>
    </div>

    <!-- Device List -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-white dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 font-medium">
                <tr>
                    <th class="p-4">Device ID</th>
                    <th class="p-4">Model & Serial</th>
                    <th class="p-4">Assigned Vehicle</th>
                    <th class="p-4">Last Seen / Telemetry</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->devices as $dev)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="p-4">
                            <span class="font-mono font-semibold text-zinc-900 dark:text-zinc-100">{{ $dev->device_id }}</span>
                            <div class="text-xs text-zinc-400">Prefix: {{ $dev->api_key_prefix ?? 'None' }}...</div>
                        </td>
                        <td class="p-4">
                            <div>{{ $dev->model }}</div>
                            <div class="text-xs text-zinc-500 font-mono">{{ $dev->serial_number ?? 'No S/N' }}</div>
                        </td>
                        <td class="p-4">
                            @if ($dev->currentAssignment && $dev->currentAssignment->vehicle)
                                <div class="font-medium text-primary-600 dark:text-primary-400">
                                    🚗 {{ $dev->currentAssignment->vehicle->plate_number }}
                                </div>
                                <div class="text-xs text-zinc-500">{{ $dev->currentAssignment->vehicle->name }}</div>
                            @else
                                <span class="text-xs text-zinc-400 italic">Unassigned (Available)</span>
                            @endif
                        </td>
                        <td class="p-4 text-xs">
                            @if ($dev->last_seen_at)
                                <div>{{ $dev->last_seen_at->diffForHumans() }}</div>
                                <div class="text-zinc-500">{{ $dev->last_latitude }}, {{ $dev->last_longitude }}</div>
                                @if ($dev->battery_level)
                                    <div class="text-emerald-600 font-medium">🔋 {{ $dev->battery_level }}%</div>
                                @endif
                            @else
                                <span class="text-zinc-400">Never connected</span>
                            @endif
                        </td>
                        <td class="p-4">
                            @if ($dev->isOnline())
                                <flux:badge color="green" size="sm">Online</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Offline</flux:badge>
                            @endif
                        </td>
                        <td class="p-4 text-right space-x-2">
                            @if ($dev->currentAssignment)
                                <flux:button size="sm" variant="subtle" wire:click="unassignDevice({{ $dev->id }})">
                                    Unassign
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="primary" wire:click="openAssignModal({{ $dev->id }})">
                                    Assign
                                </flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" wire:click="rotateKey({{ $dev->id }})">
                                Rotate Key
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-zinc-500">No GPS devices found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $this->devices->links() }}
    </div>

    <!-- Create Device Modal -->
    <flux:modal wire:model="showCreateModal" name="create-device-modal" class="max-w-md">
        <form wire:submit="createDevice" class="space-y-4">
            <flux:heading size="lg">Register GPS Device</flux:heading>
            <flux:subheading>Add a new tracking unit to MicroMek hardware inventory.</flux:subheading>

            <flux:input wire:model="deviceId" label="Hardware Device ID" placeholder="e.g. MEK-GPS-201" required />
            <flux:input wire:model="serialNumber" label="Serial Number (Optional)" placeholder="e.g. SN-882910" />
            <flux:input wire:model="model" label="Hardware Model" placeholder="MicroMek-T100" required />

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Register & Provision</flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- API Key Display Modal (Displayed only once upon provisioning or rotation) -->
    <flux:modal wire:model="showKeyModal" name="key-modal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg" class="text-amber-600 dark:text-amber-400">⚠️ Secure Device Secret Key</flux:heading>
            <flux:subheading>
                This API key is only shown once. Store it directly in the GPS device firmware or tracker gateway configuration.
            </flux:subheading>

            <div class="p-3 rounded-lg bg-zinc-100 dark:bg-zinc-800 font-mono text-xs break-all select-all border border-zinc-200 dark:border-zinc-700">
                <strong>Device ID:</strong> {{ $currentDeviceId }}<br>
                <strong>API Key:</strong> {{ $generatedKey }}
            </div>

            <div class="flex justify-end pt-2">
                <flux:button variant="primary" wire:click="$set('showKeyModal', false)">
                    I Have Safely Saved This Key
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Assign Modal -->
    <flux:modal wire:model="showAssignModal" name="assign-modal" class="max-w-md">
        <form wire:submit="assignDevice" class="space-y-4">
            <flux:heading size="lg">Assign Device to Vehicle</flux:heading>
            <flux:subheading>Select an active vehicle to bind with this GPS unit.</flux:subheading>

            <flux:select wire:model="selectedVehicleId" label="Choose Vehicle" required>
                <flux:select.option value="">Select a vehicle...</flux:select.option>
                @foreach ($this->unassignedVehicles as $veh)
                    <flux:select.option value="{{ $veh->id }}">
                        {{ $veh->plate_number }} — {{ $veh->name }} ({{ $veh->team ? $veh->team->name : 'Individual' }})
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showAssignModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Confirm Assignment</flux:button>
            </div>
        </form>
    </flux:modal>
</div>