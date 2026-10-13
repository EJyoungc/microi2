<?php

use App\Models\AuditLog;
use App\Models\DeviceRequest;
use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    // Create / Edit Modal State
    public bool $showVehicleModal = false;
    public ?int $editingVehicleId = null;
    public string $plateNumber = '';
    public string $name = '';
    public string $make = '';
    public string $model = '';
    public string $year = '';
    public string $category = 'sedan';
    public string $color = '';
    public string $operationalNotes = '';

    // Request GPS Modal
    public bool $showRequestModal = false;
    public ?int $requestVehicleId = null;
    public string $requestReason = '';

    #[Computed]
    public function currentTeam()
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function vehicles()
    {
        $user = Auth::user();

        return Vehicle::query()
            ->when(! $user->isMicroMekStaff(), function ($q) use ($user) {
                if ($user->currentTeam && ! $user->currentTeam->is_personal) {
                    $q->where('team_id', $user->currentTeam->id);
                } else {
                    $q->where('user_id', $user->id);
                }
            })
            ->when($this->search, function ($q) {
                $q->where('plate_number', 'like', "%{$this->search}%")
                    ->orWhere('name', 'like', "%{$this->search}%")
                    ->orWhere('make', 'like', "%{$this->search}%")
                    ->orWhere('model', 'like', "%{$this->search}%");
            })
            ->with(['currentAssignment.device', 'deviceRequests'])
            ->latest()
            ->paginate(10);
    }

    public function openCreateModal(): void
    {
        $this->reset(['editingVehicleId', 'plateNumber', 'name', 'make', 'model', 'year', 'color', 'operationalNotes']);
        $this->category = 'sedan';
        $this->showVehicleModal = true;
    }

    public function openEditModal(int $id): void
    {
        $vehicle = Vehicle::findOrFail($id);
        $this->authorizeAccess($vehicle);

        $this->editingVehicleId = $vehicle->id;
        $this->plateNumber = $vehicle->plate_number;
        $this->name = $vehicle->name;
        $this->make = $vehicle->make;
        $this->model = $vehicle->model;
        $this->year = $vehicle->year ?? '';
        $this->category = $vehicle->category ?? 'sedan';
        $this->color = $vehicle->color ?? '';
        $this->operationalNotes = $vehicle->operational_notes ?? '';

        $this->showVehicleModal = true;
    }

    public function saveVehicle(): void
    {
        $user = Auth::user();
        $teamId = ($user->currentTeam && ! $user->currentTeam->is_personal) ? $user->currentTeam->id : null;

        $rules = [
            'plateNumber' => 'required|string|max:30|unique:vehicles,plate_number,' . ($this->editingVehicleId ?? 'NULL') . ',id',
            'name' => 'required|string|max:100',
            'make' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'year' => 'nullable|string|max:4',
            'category' => 'required|string',
            'color' => 'nullable|string|max:50',
            'operationalNotes' => 'nullable|string|max:500',
        ];

        $this->validate($rules);

        if ($this->editingVehicleId) {
            $vehicle = Vehicle::findOrFail($this->editingVehicleId);
            $this->authorizeAccess($vehicle);

            $vehicle->update([
                'plate_number' => $this->plateNumber,
                'name' => $this->name,
                'make' => $this->make,
                'model' => $this->model,
                'year' => $this->year ?: null,
                'category' => $this->category,
                'color' => $this->color ?: null,
                'operationalNotes' => $this->operationalNotes ?: null,
            ]);

            AuditLog::record('vehicle.updated', $vehicle);
            Flux::toast(variant: 'success', text: __('Vehicle updated successfully.'));
        } else {
            $vehicle = Vehicle::create([
                'team_id' => $teamId,
                'user_id' => $user->id,
                'plate_number' => $this->plateNumber,
                'name' => $this->name,
                'make' => $this->make,
                'model' => $this->model,
                'year' => $this->year ?: null,
                'category' => $this->category,
                'color' => $this->color ?: null,
                'operational_notes' => $this->operationalNotes ?: null,
                'status' => 'active',
            ]);

            AuditLog::record('vehicle.created', $vehicle);
            Flux::toast(variant: 'success', text: __('Vehicle created successfully!'));
        }

        $this->showVehicleModal = false;
    }

    public function openRequestModal(int $vehicleId): void
    {
        $this->requestVehicleId = $vehicleId;
        $this->requestReason = '';
        $this->showRequestModal = true;
    }

    public function submitDeviceRequest(): void
    {
        $this->validate(['requestReason' => 'nullable|string|max:500']);

        $user = Auth::user();
        $vehicle = Vehicle::findOrFail($this->requestVehicleId);
        $this->authorizeAccess($vehicle);

        $teamId = ($user->currentTeam && ! $user->currentTeam->is_personal) ? $user->currentTeam->id : null;

        $request = DeviceRequest::create([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'submitted',
            'reason' => $this->requestReason ?: 'Requesting MicroMek GPS tracking device',
        ]);

        AuditLog::record('device_request.submitted', $request);

        $this->showRequestModal = false;
        Flux::toast(variant: 'success', text: __('GPS device request submitted to MicroMek!'));
    }

    private function authorizeAccess(Vehicle $vehicle): void
    {
        $user = Auth::user();
        if ($user->isMicroMekStaff()) {
            return;
        }

        if ($vehicle->team_id && $user->currentTeam && $vehicle->team_id === $user->currentTeam->id) {
            return;
        }

        if ($vehicle->user_id === $user->id) {
            return;
        }

        abort(403, 'Unauthorized access to vehicle.');
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Vehicles & Fleet') }}</flux:heading>
            <flux:subheading>{{ __('Manage all vehicles in your organization or personal account and inspect tracking status.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
            {{ __('Add Vehicle') }}
        </flux:button>
    </div>

    <!-- Search -->
    <div class="max-w-md">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search plate number, name, make..." icon="magnifying-glass" />
    </div>

    <!-- Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-white dark:bg-zinc-900">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 font-medium">
                <tr>
                    <th class="p-4">Plate & Name</th>
                    <th class="p-4">Make / Model / Year</th>
                    <th class="p-4">Category</th>
                    <th class="p-4">Tracking Hardware</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->vehicles as $veh)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="p-4">
                            <div class="font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                                <span class="bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 rounded border text-xs font-mono">
                                    {{ $veh->plate_number }}
                                </span>
                                <span>{{ $veh->name }}</span>
                            </div>
                            <div class="text-xs text-zinc-500 mt-0.5">{{ $veh->color ? $veh->color . ' • ' : '' }}{{ $veh->operational_notes ?? 'No notes' }}</div>
                        </td>
                        <td class="p-4 text-zinc-600 dark:text-zinc-400">
                            <div>{{ $veh->make }} {{ $veh->model }}</div>
                            <div class="text-xs text-zinc-500">{{ $veh->year ?? 'Year N/A' }}</div>
                        </td>
                        <td class="p-4">
                            <span class="capitalize text-zinc-600 dark:text-zinc-400 text-xs px-2 py-1 rounded bg-zinc-100 dark:bg-zinc-800">
                                {{ $veh->category }}
                            </span>
                        </td>
                        <td class="p-4 text-xs">
                            @if ($veh->currentAssignment && $veh->currentAssignment->device)
                                <div class="text-emerald-600 font-medium flex items-center gap-1">
                                    <span>📡 {{ $veh->currentAssignment->device->device_id }}</span>
                                </div>
                                <div class="text-zinc-400">
                                    {{ $veh->currentAssignment->device->isOnline() ? '● Live' : '○ Offline' }}
                                </div>
                            @elseif ($veh->deviceRequests->where('status', 'submitted')->isNotEmpty())
                                <flux:badge color="amber" size="sm">GPS Requested</flux:badge>
                            @else
                                <flux:button size="xs" variant="subtle" wire:click="openRequestModal({{ $veh->id }})">
                                    Request GPS Device
                                </flux:button>
                            @endif
                        </td>
                        <td class="p-4">
                            <flux:badge color="green" size="sm">{{ ucfirst($veh->status) }}</flux:badge>
                        </td>
                        <td class="p-4 text-right space-x-2">
                            <flux:button size="sm" variant="ghost" wire:click="openEditModal({{ $veh->id }})">
                                Edit
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-zinc-500">
                            No vehicles added yet. Click "Add Vehicle" to register your first vehicle.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $this->vehicles->links() }}
    </div>

    <!-- Create/Edit Modal -->
    <flux:modal wire:model="showVehicleModal" name="vehicle-modal" class="max-w-md">
        <form wire:submit="saveVehicle" class="space-y-4">
            <flux:heading size="lg">{{ $editingVehicleId ? 'Edit Vehicle' : 'Register Vehicle' }}</flux:heading>
            <flux:subheading>Enter vehicle specifications and operational details.</flux:subheading>

            <flux:input wire:model="plateNumber" label="Registration Plate" placeholder="e.g. BW 4492" required />
            <flux:input wire:model="name" label="Display Name" placeholder="e.g. Express Delivery Van" required />

            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="make" label="Make" placeholder="Toyota" required />
                <flux:input wire:model="model" label="Model" placeholder="Hilux / HiAce" required />
            </div>

            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="year" label="Year" placeholder="2022" />
                <flux:input wire:model="color" label="Color" placeholder="White" />
                <flux:select wire:model="category" label="Category">
                    <flux:select.option value="sedan">Sedan</flux:select.option>
                    <flux:select.option value="suv">SUV</flux:select.option>
                    <flux:select.option value="truck">Truck</flux:select.option>
                    <flux:select.option value="van">Van</flux:select.option>
                    <flux:select.option value="motorcycle">Motorcycle</flux:select.option>
                    <flux:select.option value="drone">Drone (UAV)</flux:select.option>
                </flux:select>
            </div>

            <flux:textarea wire:model="operationalNotes" label="Operational Notes (Optional)" placeholder="Depot assignments, courier route details..." />

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showVehicleModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save Vehicle</flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Request GPS Modal -->
    <flux:modal wire:model="showRequestModal" name="request-gps-modal" class="max-w-md">
        <form wire:submit="submitDeviceRequest" class="space-y-4">
            <flux:heading size="lg">Request MicroMek GPS Tracker</flux:heading>
            <flux:subheading>Submit a formal request to MicroMek team to provision and install an IoT tracker on this vehicle.</flux:subheading>

            <flux:textarea wire:model="requestReason" label="Usage Reason / Installation Notes" placeholder="e.g. Urgent need for route telemetry and medical logistics monitoring..." />

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showRequestModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Submit Request</flux:button>
            </div>
        </form>
    </flux:modal>
</div>