<?php

use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceRequest;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function isStaff(): bool
    {
        return Auth::user()->isMicroMekStaff();
    }

    #[Computed]
    public function stats()
    {
        $user = Auth::user();

        if ($this->isStaff) {
            // Global MicroMek platform stats
            return [
                'total_users' => User::count(),
                'total_orgs' => Team::where('is_personal', false)->count(),
                'pending_orgs' => Team::where('is_personal', false)->where('status', 'pending')->count(),
                'total_vehicles' => Vehicle::count(),
                'total_devices' => Device::count(),
                'active_devices' => Device::where('status', 'active')->count(),
                'online_devices' => Device::where('last_seen_at', '>=', now()->subMinutes(10))->count(),
                'pending_requests' => DeviceRequest::where('status', 'submitted')->count(),
            ];
        }

        // Tenant-scoped stats (Organization or Individual)
        $vehicleQuery = Vehicle::query();
        if ($user->currentTeam && ! $user->currentTeam->is_personal) {
            $vehicleQuery->where('team_id', $user->currentTeam->id);
        } else {
            $vehicleQuery->where('user_id', $user->id);
        }

        $vehicleIds = (clone $vehicleQuery)->pluck('id');
        $activeAssignedCount = DeviceAssignment::whereIn('vehicle_id', $vehicleIds)->where('status', 'active')->count();
        $pendingReqs = DeviceRequest::whereIn('vehicle_id', $vehicleIds)->where('status', 'submitted')->count();

        return [
            'total_vehicles' => $vehicleQuery->count(),
            'assigned_devices' => $activeAssignedCount,
            'pending_requests' => $pendingReqs,
        ];
    }
}; ?>

<div class="space-y-6">
    <!-- Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @if ($this->isStaff)
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Organizations</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_orgs'] }}</div>
                    @if ($this->stats['pending_orgs'] > 0)
                        <flux:badge color="amber" size="sm">{{ $this->stats['pending_orgs'] }} pending</flux:badge>
                    @endif
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Total Fleet Vehicles</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_vehicles'] }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">GPS Units (Online)</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-emerald-600">{{ $this->stats['online_devices'] }} <span class="text-xs text-zinc-400 font-normal">/ {{ $this->stats['total_devices'] }}</span></div>
                    <flux:badge color="green" size="sm">Active</flux:badge>
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Pending Device Requests</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-amber-600">{{ $this->stats['pending_requests'] }}</div>
                    <flux:badge color="amber" size="sm">Needs Review</flux:badge>
                </div>
            </div>
        @else
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">My Registered Vehicles</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_vehicles'] }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Tracked with GPS</div>
                <div class="mt-2 text-2xl font-bold text-emerald-600">{{ $this->stats['assigned_devices'] }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Pending Hardware Requests</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['pending_requests'] }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">Coverage Region</div>
                <div class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Malawi Nationwide 🇲🇼</div>
            </div>
        @endif
    </div>

    <!-- Quick Module Navigation & Platform Links -->
    <div class="space-y-4">
        <div class="flex items-center justify-between pb-2 border-b border-zinc-200 dark:border-zinc-800">
            <div>
                <flux:heading size="lg">{{ __('Fleet Modules & Tracking') }}</flux:heading>
                <flux:subheading>{{ __('Quickly launch dedicated tracking pages and manage your connected fleet hardware.') }}</flux:subheading>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Live Tracking Card -->
            <a href="{{ route('tracking') }}" wire:navigate 
               class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                <div class="size-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                    🗺️
                </div>
                <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                    {{ __('Live Tracking Map') }}
                </div>
                <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                    {{ __('Interactive real-time map, auto-follow vehicle telemetry, and driving road navigation.') }}
                </div>
                <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    <span>{{ __('Open Live Map') }}</span>
                    <span>→</span>
                </div>
            </a>

            <!-- History Playback Card -->
            <a href="{{ route('history') }}" wire:navigate 
               class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                <div class="size-10 rounded-xl bg-teal-50 dark:bg-teal-950/60 text-teal-600 dark:text-teal-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                    ⏱️
                </div>
                <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                    {{ __('Historical Playback') }}
                </div>
                <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                    {{ __('Inspect chronological location ping points, route polylines, journey replay, and speed metrics.') }}
                </div>
                <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    <span>{{ __('Open Journey History') }}</span>
                    <span>→</span>
                </div>
            </a>

            <!-- Vehicles Fleet Card -->
            <a href="{{ route('vehicles') }}" wire:navigate 
               class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                <div class="size-10 rounded-xl bg-cyan-50 dark:bg-cyan-950/60 text-cyan-600 dark:text-cyan-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                    🚗
                </div>
                <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                    {{ __('Vehicles & Fleet') }}
                </div>
                <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                    {{ __('Register new fleet assets, view device binding statuses, and submit GPS hardware requests.') }}
                </div>
                <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                    <span>{{ __('Manage Fleet') }}</span>
                    <span>→</span>
                </div>
            </a>

            @if ($this->isStaff)
                <!-- GPS Hardware Card -->
                <a href="{{ route('admin.devices') }}" wire:navigate 
                   class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                    <div class="size-10 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                        📡
                    </div>
                    <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                        {{ __('GPS Hardware Inventory') }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                        {{ __('Provision MicroMek GPS trackers, rotate credentials, and manage hardware assignments.') }}
                    </div>
                    <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span>{{ __('Manage Hardware') }}</span>
                        <span>→</span>
                    </div>
                </a>

                <!-- Organizations Card -->
                <a href="{{ route('admin.organizations') }}" wire:navigate 
                   class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                    <div class="size-10 rounded-xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                        🏢
                    </div>
                    <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                        {{ __('Organizations Management') }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                        {{ __('Review tenant registrations, approve commercial organizations, and inspect fleets.') }}
                    </div>
                    <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span>{{ __('Manage Organizations') }}</span>
                        <span>→</span>
                    </div>
                </a>

                <!-- User Directory Card -->
                <a href="{{ route('admin.users') }}" wire:navigate 
                   class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                    <div class="size-10 rounded-xl bg-rose-50 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                        👥
                    </div>
                    <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                        {{ __('User & Staff Directory') }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                        {{ __('Search platform users, assign system roles, and manage account suspensions.') }}
                    </div>
                    <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span>{{ __('Manage Users') }}</span>
                        <span>→</span>
                    </div>
                </a>
            @endif
        </div>
    </div>
</div>