<?php

use App\Enums\TeamRole;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceRequest;
use App\Models\LocationHistory;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Team member invitation for Org Admins
    public bool $showInviteModal = false;
    public string $inviteEmail = '';
    public string $inviteRole = 'member';

    #[Computed]
    public function user(): User
    {
        return Auth::user();
    }

    #[Computed]
    public function currentTeam(): ?Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function isSuperAdmin(): bool
    {
        return Auth::user()->isSuperAdmin();
    }

    #[Computed]
    public function isStaff(): bool
    {
        return Auth::user()->isMicroMekStaff();
    }

    #[Computed]
    public function isOrgAdmin(): bool
    {
        $team = $this->currentTeam;
        if (! $team || $team->is_personal) {
            return false;
        }

        return Auth::user()->ownsTeam($team) || Auth::user()->teamRole($team) === TeamRole::Admin;
    }

    #[Computed]
    public function isOrgMember(): bool
    {
        $team = $this->currentTeam;
        if (! $team || $team->is_personal) {
            return false;
        }

        return ! $this->isOrgAdmin && Auth::user()->teamRole($team) === TeamRole::Member;
    }

    #[Computed]
    public function isIndividual(): bool
    {
        return ! $this->isStaff && (! $this->currentTeam || $this->currentTeam->is_personal);
    }

    #[Computed]
    public function stats(): array
    {
        $user = Auth::user();

        if ($this->isStaff) {
            // Global MicroMek platform stats
            return [
                'total_users' => User::count(),
                'active_users' => User::where('is_active', true)->count(),
                'suspended_users' => User::where('is_active', false)->count(),
                'total_orgs' => Team::where('is_personal', false)->count(),
                'pending_orgs' => Team::where('is_personal', false)->where('status', 'pending')->count(),
                'approved_orgs' => Team::where('is_personal', false)->where('status', 'approved')->count(),
                'total_vehicles' => Vehicle::count(),
                'total_devices' => Device::count(),
                'active_devices' => Device::where('status', 'active')->count(),
                'online_devices' => Device::where('last_seen_at', '>=', now()->subMinutes(10))->count(),
                'pending_requests' => DeviceRequest::where('status', 'submitted')->count(),
                'total_telemetry_pings' => LocationHistory::count(),
            ];
        }

        if ($this->isOrgAdmin || $this->isOrgMember) {
            $team = $this->currentTeam;
            $vehicleIds = $team->vehicles()->pluck('id');
            $activeAssignedCount = DeviceAssignment::whereIn('vehicle_id', $vehicleIds)->where('status', 'active')->count();
            $deviceIds = DeviceAssignment::whereIn('vehicle_id', $vehicleIds)->where('status', 'active')->pluck('device_id');
            $onlineCount = Device::whereIn('id', $deviceIds)->where('last_seen_at', '>=', now()->subMinutes(10))->count();

            return [
                'total_vehicles' => $vehicleIds->count(),
                'assigned_devices' => $activeAssignedCount,
                'online_devices' => $onlineCount,
                'members_count' => $team->members()->count(),
                'pending_invitations_count' => $team->invitations()->whereNull('accepted_at')->count(),
                'pending_requests' => $team->deviceRequests()->where('status', 'submitted')->count(),
            ];
        }

        // Individual user personal fleet stats
        $vehicleIds = $user->vehicles()->pluck('id');
        $activeAssignedCount = DeviceAssignment::whereIn('vehicle_id', $vehicleIds)->where('status', 'active')->count();
        $deviceIds = DeviceAssignment::whereIn('vehicle_id', $vehicleIds)->where('status', 'active')->pluck('device_id');
        $onlineCount = Device::whereIn('id', $deviceIds)->where('last_seen_at', '>=', now()->subMinutes(10))->count();
        $pendingReqs = DeviceRequest::whereIn('vehicle_id', $vehicleIds)->where('status', 'submitted')->count();

        return [
            'total_vehicles' => $vehicleIds->count(),
            'assigned_devices' => $activeAssignedCount,
            'online_devices' => $onlineCount,
            'pending_requests' => $pendingReqs,
        ];
    }

    #[Computed]
    public function pendingDeviceRequests()
    {
        if (! $this->isStaff) {
            return collect();
        }

        return DeviceRequest::where('status', 'submitted')
            ->with(['vehicle', 'user', 'team'])
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function pendingOrganizations()
    {
        if (! $this->isStaff) {
            return collect();
        }

        return Team::where('is_personal', false)
            ->where('status', 'pending')
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function activeDevicesSummary()
    {
        if (! $this->isStaff) {
            return collect();
        }

        return Device::latest('last_seen_at')
            ->take(5)
            ->get();
    }

    #[Computed]
    public function organizationVehicles()
    {
        if (! $this->currentTeam) {
            return collect();
        }

        return $this->currentTeam->vehicles()
            ->with(['currentAssignment.device'])
            ->latest()
            ->take(6)
            ->get();
    }

    #[Computed]
    public function teamMembers()
    {
        if (! $this->currentTeam || $this->currentTeam->is_personal) {
            return collect();
        }

        return $this->currentTeam->members()
            ->withPivot('role')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function teamInvitations()
    {
        if (! $this->currentTeam || $this->currentTeam->is_personal) {
            return collect();
        }

        return $this->currentTeam->invitations()
            ->whereNull('accepted_at')
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function personalVehicles()
    {
        return Auth::user()->vehicles()
            ->with(['currentAssignment.device'])
            ->latest()
            ->take(6)
            ->get();
    }

    #[Computed]
    public function personalRequests()
    {
        return DeviceRequest::where('user_id', Auth::id())
            ->with(['vehicle'])
            ->latest()
            ->take(4)
            ->get();
    }

    public function openInviteModal(): void
    {
        if (! $this->isOrgAdmin) {
            Flux::toast(variant: 'danger', text: __('Only organization administrators can invite members.'));
            return;
        }

        $this->reset('inviteEmail', 'inviteRole');
        $this->inviteRole = 'member';
        $this->showInviteModal = true;
    }

    public function sendInvitation(): void
    {
        if (! $this->isOrgAdmin) {
            Flux::toast(variant: 'danger', text: __('Unauthorized.'));
            return;
        }

        $team = $this->currentTeam;
        if (! $team || $team->is_personal) {
            Flux::toast(variant: 'danger', text: __('Cannot invite to a personal fleet.'));
            return;
        }

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($team)],
            'inviteRole' => ['required', 'string', Rule::enum(TeamRole::class)],
        ]);

        $invitation = $team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::from($validated['inviteRole']),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteEmail', 'inviteRole');
        $this->showInviteModal = false;

        Flux::toast(variant: 'success', text: __('Invitation sent to :email', ['email' => $invitation->email]));
    }
}; ?>

<div class="space-y-6">
    <!-- Designation Header Banner -->
    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-6 shadow-xs relative overflow-hidden">
        <div class="absolute -right-12 -top-12 size-48 rounded-full bg-emerald-500/5 dark:bg-emerald-500/10 pointer-events-none blur-2xl"></div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 relative">
            <div class="flex items-start sm:items-center gap-4">
                <div class="size-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-400 font-bold flex items-center justify-center text-xl shrink-0 shadow-inner">
                    {{ $this->user->initials() }}
                </div>
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl sm:text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                            {{ __('Welcome back, :name', ['name' => $this->user->name]) }}
                        </h1>

                        <!-- Designation Role Badge -->
                        @if ($this->isSuperAdmin)
                            <flux:badge color="red" size="sm" icon="shield-check">MicroMek Super Admin</flux:badge>
                        @elseif ($this->isStaff)
                            <flux:badge color="indigo" size="sm" icon="cpu-chip">MicroMek Operations Staff</flux:badge>
                        @elseif ($this->isOrgAdmin)
                            <flux:badge color="sky" size="sm" icon="building-office">Organization Admin</flux:badge>
                        @elseif ($this->isOrgMember)
                            <flux:badge color="teal" size="sm" icon="user">Fleet Member / Driver</flux:badge>
                        @else
                            <flux:badge color="emerald" size="sm" icon="user">Personal Fleet</flux:badge>
                        @endif
                    </div>

                    <!-- Role Scope Details -->
                    <p class="text-sm text-zinc-500 dark:text-zinc-400 leading-relaxed">
                        @if ($this->isSuperAdmin)
                            {{ __('Full system governance, hardware provisioning, user role management, and global Malawi telemetry operations.') }}
                        @elseif ($this->isStaff)
                            {{ __('Managing GPS hardware dispatch, device request fulfillment, and commercial organization oversight.') }}
                        @elseif ($this->isOrgAdmin)
                            {{ __('Managing :team commercial fleet assets, vehicle GPS tracking, and driver team personnel.', ['team' => $this->currentTeam?->name]) }}
                        @elseif ($this->isOrgMember)
                            {{ __('Operating in :team fleet. View active vehicle telemetries, route playback, and tracking status.', ['team' => $this->currentTeam?->name]) }}
                        @else
                            {{ __('Private vehicle management with live MicroMek GPS tracker telemetry and journey histories.') }}
                        @endif
                    </p>
                </div>
            </div>

            <!-- Role-Specific Action Center -->
            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                @if ($this->isStaff)
                    <flux:button variant="primary" icon="user-plus" href="{{ route('admin.users') }}" wire:navigate>
                        {{ __('Manage Users') }}
                    </flux:button>
                    <flux:button variant="subtle" icon="cpu-chip" href="{{ route('admin.devices') }}" wire:navigate>
                        {{ __('GPS Units') }}
                    </flux:button>
                @elseif ($this->isOrgAdmin)
                    <flux:button variant="primary" icon="user-plus" wire:click="openInviteModal">
                        {{ __('Invite Member') }}
                    </flux:button>
                    <flux:button variant="subtle" icon="truck" href="{{ route('vehicles') }}" wire:navigate>
                        {{ __('Fleet Assets') }}
                    </flux:button>
                @elseif ($this->isOrgMember)
                    <flux:button variant="primary" icon="map" href="{{ route('tracking') }}" wire:navigate>
                        {{ __('Live Map') }}
                    </flux:button>
                    <flux:button variant="subtle" icon="clock" href="{{ route('history') }}" wire:navigate>
                        {{ __('Journey History') }}
                    </flux:button>
                @else
                    <flux:button variant="primary" icon="truck" href="{{ route('vehicles') }}" wire:navigate>
                        {{ __('Add Vehicle') }}
                    </flux:button>
                    <flux:button variant="subtle" icon="map" href="{{ route('tracking') }}" wire:navigate>
                        {{ __('Live Map') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </div>

    <!-- Stat Cards According to Role -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @if ($this->isStaff)
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Organizations') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_orgs'] }}</div>
                    @if ($this->stats['pending_orgs'] > 0)
                        <flux:badge color="amber" size="sm">{{ $this->stats['pending_orgs'] }} {{ __('Pending') }}</flux:badge>
                    @else
                        <flux:badge color="green" size="sm">{{ __('All Approved') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ $this->stats['approved_orgs'] }} {{ __('approved commercial fleets') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Platform Users') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_users'] }}</div>
                    <flux:badge color="zinc" size="sm">{{ $this->stats['active_users'] }} {{ __('Active') }}</flux:badge>
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ $this->stats['suspended_users'] }} {{ __('suspended accounts') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('GPS Units Online') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $this->stats['online_devices'] }} <span class="text-xs text-zinc-400 font-normal">/ {{ $this->stats['total_devices'] }}</span>
                    </div>
                    <flux:badge color="green" size="sm">{{ __('Live') }}</flux:badge>
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ number_format($this->stats['total_telemetry_pings']) }} {{ __('telemetry pings recorded') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Device Requests') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold {{ $this->stats['pending_requests'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                        {{ $this->stats['pending_requests'] }}
                    </div>
                    @if ($this->stats['pending_requests'] > 0)
                        <flux:badge color="amber" size="sm">{{ __('Needs Review') }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('Up to Date') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ __('SIM808 Tracker fulfillment queue') }}
                </div>
            </div>
        @elseif ($this->isOrgAdmin)
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Organization Fleet') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_vehicles'] }}</div>
                    <flux:badge color="sky" size="sm">{{ __('Vehicles') }}</flux:badge>
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ $this->stats['assigned_devices'] }} {{ __('equipped with GPS units') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Live GPS Status') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ $this->stats['online_devices'] }} <span class="text-xs text-zinc-400 font-normal">/ {{ $this->stats['assigned_devices'] }}</span>
                    </div>
                    <flux:badge color="green" size="sm">{{ __('Transmitting') }}</flux:badge>
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ __('Pinging within last 10 minutes') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Team Personnel') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['members_count'] }}</div>
                    @if ($this->stats['pending_invitations_count'] > 0)
                        <flux:badge color="amber" size="sm">{{ $this->stats['pending_invitations_count'] }} {{ __('Invited') }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('Active') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ __('Authorized dispatchers & drivers') }}
                </div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Hardware Requests') }}</div>
                <div class="mt-2 flex items-baseline justify-between">
                    <div class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['pending_requests'] }}</div>
                    @if ($this->stats['pending_requests'] > 0)
                        <flux:badge color="amber" size="sm">{{ __('In Review') }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('Fulfilled') }}</flux:badge>
                    @endif
                </div>
                <div class="mt-2 text-xs text-zinc-400">
                    {{ __('Pending tracker assignment') }}
                </div>
            </div>
        @elseif ($this->isOrgMember)
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Fleet Vehicles') }}</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_vehicles'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Belonging to :org', ['org' => $this->currentTeam?->name]) }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('GPS Equipped') }}</div>
                <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->stats['assigned_devices'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Ready for live tracking') }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Online Now') }}</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['online_devices'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Actively transmitting') }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Coverage Zone') }}</div>
                <div class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Malawi Nationwide 🇲🇼</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Cellular & GNSS Enabled') }}</div>
            </div>
        @else
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('My Registered Vehicles') }}</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['total_vehicles'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Personal fleet vehicles') }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Tracked with GPS') }}</div>
                <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $this->stats['assigned_devices'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('Active device bindings') }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Pending Hardware Requests') }}</div>
                <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->stats['pending_requests'] }}</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('In review by MicroMek') }}</div>
            </div>

            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs">
                <div class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Coverage Region') }}</div>
                <div class="mt-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Malawi Nationwide 🇲🇼</div>
                <div class="mt-2 text-xs text-zinc-400">{{ __('SIM808 MicroMek Network') }}</div>
            </div>
        @endif
    </div>

    <!-- Role-Specific Feeds & Detailed Panels -->
    @if ($this->isStaff)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Pending Hardware Requests Section -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="inbox-arrow-down" class="text-amber-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Pending Hardware Requests') }}</h2>
                    </div>
                    <flux:button size="xs" variant="subtle" href="{{ route('admin.requests') }}" wire:navigate>
                        {{ __('View All') }}
                    </flux:button>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($this->pendingDeviceRequests as $req)
                        <div class="py-3 flex items-center justify-between gap-3 text-sm">
                            <div>
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ $req->vehicle?->plate_number ?? 'Unknown Plate' }}
                                    <span class="text-xs text-zinc-400 font-normal">({{ $req->vehicle?->make }} {{ $req->vehicle?->model }})</span>
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ __('Requested by :user', ['user' => $req->user?->name ?? 'User']) }}
                                    @if ($req->team && ! $req->team->is_personal)
                                        • <span class="text-sky-600 dark:text-sky-400 font-medium">{{ $req->team->name }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <flux:badge color="amber" size="sm">{{ __('Pending') }}</flux:badge>
                                <flux:button size="xs" variant="ghost" href="{{ route('admin.requests') }}" wire:navigate>
                                    {{ __('Review') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-400">
                            {{ __('No pending hardware requests requiring review.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Commercial Organizations Awaiting Review -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="building-office-2" class="text-blue-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Pending Organization Registrations') }}</h2>
                    </div>
                    <flux:button size="xs" variant="subtle" href="{{ route('admin.organizations') }}" wire:navigate>
                        {{ __('View All') }}
                    </flux:button>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($this->pendingOrganizations as $org)
                        <div class="py-3 flex items-center justify-between gap-3 text-sm">
                            <div>
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $org->name }}</div>
                                <div class="text-xs text-zinc-500">
                                    <span class="capitalize">{{ $org->org_type ?? 'Commercial' }}</span> • {{ $org->contact_email ?? 'No email' }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <flux:badge color="amber" size="sm">{{ __('Pending Approval') }}</flux:badge>
                                <flux:button size="xs" variant="ghost" href="{{ route('admin.organizations') }}" wire:navigate>
                                    {{ __('Review') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-400">
                            {{ __('All commercial organizations are currently reviewed and approved.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @elseif ($this->isOrgAdmin)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Organization Fleet Vehicles Table -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="truck" class="text-emerald-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Fleet Vehicles & Tracking') }}</h2>
                    </div>
                    <flux:button size="xs" variant="subtle" href="{{ route('vehicles') }}" wire:navigate>
                        {{ __('Manage Fleet') }}
                    </flux:button>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($this->organizationVehicles as $v)
                        <div class="py-3 flex items-center justify-between gap-3 text-sm">
                            <div>
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200 font-mono">
                                    {{ $v->plate_number }}
                                </div>
                                <div class="text-xs text-zinc-500">
                                    {{ $v->make }} {{ $v->model }} ({{ $v->year ?? 'N/A' }})
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($v->currentAssignment && $v->currentAssignment->device)
                                    <flux:badge color="green" size="sm">
                                        {{ $v->currentAssignment->device->device_id }}
                                    </flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('No Tracker') }}</flux:badge>
                                @endif
                                <flux:button size="xs" variant="ghost" href="{{ route('tracking') }}" wire:navigate>
                                    {{ __('Track') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-400">
                            {{ __('No vehicles registered yet in this organization.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Team Roster & Invitations Panel -->
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="user-group" class="text-sky-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Team Roster & Personnel') }}</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="xs" variant="primary" icon="user-plus" wire:click="openInviteModal">
                            {{ __('Invite Member') }}
                        </flux:button>
                        <flux:button size="xs" variant="subtle" href="{{ route('teams.edit', ['team' => $this->currentTeam->slug]) }}" wire:navigate>
                            {{ __('Settings') }}
                        </flux:button>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- Current Members -->
                    <div>
                        <div class="text-xs font-semibold text-zinc-400 uppercase tracking-wider mb-2">{{ __('Active Members (:count)', ['count' => $this->teamMembers->count()]) }}</div>
                        <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60 max-h-48 overflow-y-auto">
                            @foreach ($this->teamMembers as $m)
                                <div class="py-2.5 flex items-center justify-between gap-3 text-sm">
                                    <div class="flex items-center gap-2.5">
                                        <div class="size-7 rounded-full bg-sky-100 dark:bg-sky-950/60 text-sky-800 dark:text-sky-300 font-bold flex items-center justify-center text-xs">
                                            {{ $m->initials() }}
                                        </div>
                                        <div>
                                            <div class="font-medium text-zinc-800 dark:text-zinc-200 leading-none">{{ $m->name }}</div>
                                            <div class="text-xs text-zinc-400 mt-0.5">{{ $m->email }}</div>
                                        </div>
                                    </div>
                                    <flux:badge color="{{ $m->pivot->role->value === 'owner' ? 'purple' : ($m->pivot->role->value === 'admin' ? 'blue' : 'zinc') }}" size="sm">
                                        {{ $m->pivot->role->label() }}
                                    </flux:badge>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- Pending Invitations -->
                    @if ($this->teamInvitations->isNotEmpty())
                        <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <div class="text-xs font-semibold text-amber-500 uppercase tracking-wider mb-2">
                                {{ __('Pending Invitations (:count)', ['count' => $this->teamInvitations->count()]) }}
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                                @foreach ($this->teamInvitations as $inv)
                                    <div class="py-2 flex items-center justify-between gap-3 text-xs">
                                        <div class="text-zinc-700 dark:text-zinc-300 font-medium">
                                            {{ $inv->email }}
                                            <span class="text-zinc-400">({{ $inv->role->label() }})</span>
                                        </div>
                                        <flux:badge color="amber" size="sm">{{ __('Expires :date', ['date' => $inv->expires_at?->diffForHumans() ?? 'soon']) }}</flux:badge>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($this->isOrgMember)
        <!-- Organization Member / Driver Vehicle View -->
        <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <flux:icon name="truck" class="text-teal-500 size-5" />
                    <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Assigned Fleet Vehicles — :team', ['team' => $this->currentTeam?->name]) }}</h2>
                </div>
                <flux:button size="xs" variant="primary" href="{{ route('tracking') }}" wire:navigate>
                    {{ __('Open Live Map') }}
                </flux:button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($this->organizationVehicles as $v)
                    <div class="p-4 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold font-mono text-zinc-900 dark:text-zinc-100">{{ $v->plate_number }}</span>
                            @if ($v->currentAssignment && $v->currentAssignment->device)
                                <flux:badge color="green" size="sm">GPS Active</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Unbound</flux:badge>
                            @endif
                        </div>
                        <div class="text-xs text-zinc-500">
                            {{ $v->make }} {{ $v->model }} • {{ $v->category ?? 'Fleet' }}
                        </div>
                        <div class="flex items-center gap-2 pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60">
                            <flux:button size="xs" variant="subtle" href="{{ route('tracking') }}" wire:navigate class="w-full">
                                {{ __('Track Live') }}
                            </flux:button>
                            <flux:button size="xs" variant="ghost" href="{{ route('history') }}" wire:navigate class="w-full">
                                {{ __('History') }}
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-8 text-center text-sm text-zinc-400">
                        {{ __('No fleet vehicles available in this organization yet.') }}
                    </div>
                @endforelse
            </div>
        </div>
    @else
        <!-- Individual Private Fleet Panels -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="truck" class="text-emerald-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('My Registered Vehicles') }}</h2>
                    </div>
                    <flux:button size="xs" variant="subtle" href="{{ route('vehicles') }}" wire:navigate>
                        {{ __('Add / Manage') }}
                    </flux:button>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($this->personalVehicles as $v)
                        <div class="py-3 flex items-center justify-between gap-3 text-sm">
                            <div>
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200 font-mono">{{ $v->plate_number }}</div>
                                <div class="text-xs text-zinc-500">{{ $v->make }} {{ $v->model }} ({{ $v->year ?? 'N/A' }})</div>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($v->currentAssignment && $v->currentAssignment->device)
                                    <flux:badge color="green" size="sm">GPS Active</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">No Tracker</flux:badge>
                                @endif
                                <flux:button size="xs" variant="ghost" href="{{ route('tracking') }}" wire:navigate>
                                    {{ __('Track') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-400">
                            {{ __('You have not registered any vehicles yet.') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="cpu-chip" class="text-purple-500 size-5" />
                        <h2 class="font-bold text-zinc-900 dark:text-zinc-100 text-base">{{ __('Hardware Request Tracker') }}</h2>
                    </div>
                    <flux:button size="xs" variant="subtle" href="{{ route('vehicles') }}" wire:navigate>
                        {{ __('New Request') }}
                    </flux:button>
                </div>

                <div class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($this->personalRequests as $req)
                        <div class="py-3 flex items-center justify-between gap-3 text-sm">
                            <div>
                                <div class="font-semibold text-zinc-800 dark:text-zinc-200 font-mono">
                                    {{ $req->vehicle?->plate_number ?? 'Vehicle' }}
                                </div>
                                <div class="text-xs text-zinc-400">
                                    {{ $req->created_at->format('M d, Y') }}
                                </div>
                            </div>
                            <flux:badge color="{{ $req->status === 'fulfilled' ? 'green' : ($req->status === 'approved' ? 'blue' : 'amber') }}" size="sm">
                                {{ ucfirst($req->status) }}
                            </flux:badge>
                        </div>
                    @empty
                        <div class="py-8 text-center text-sm text-zinc-400">
                            {{ __('No pending hardware requests.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <!-- Quick Module Navigation & Platform Links -->
    <div class="space-y-4">
        <div class="flex items-center justify-between pb-2 border-b border-zinc-200 dark:border-zinc-800">
            <div>
                <flux:heading size="lg">{{ __('Platform Modules & Navigation') }}</flux:heading>
                <flux:subheading>{{ __('Access real-time telemetry maps, historical playback, fleet inventory, and admin consoles.') }}</flux:subheading>
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
                    {{ __('Chronological location pings, journey replay, speed telemetry, and road breadcrumbs.') }}
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
                    {{ __('Register fleet assets, view device binding statuses, and request MicroMek GPS hardware.') }}
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
                        {{ __('Search platform users, create/invite accounts, assign roles, and manage suspensions.') }}
                    </div>
                    <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span>{{ __('Manage Users') }}</span>
                        <span>→</span>
                    </div>
                </a>
            @elseif ($this->isOrgAdmin)
                <!-- Team Settings Card for Org Admin -->
                <a href="{{ route('teams.edit', ['team' => $this->currentTeam->slug]) }}" wire:navigate 
                   class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/60 dark:hover:border-emerald-500/60 transition group shadow-xs">
                    <div class="size-10 rounded-xl bg-sky-50 dark:bg-sky-950/60 text-sky-600 dark:text-sky-400 flex items-center justify-center text-xl mb-3 group-hover:scale-105 transition">
                        ⚙️
                    </div>
                    <div class="font-bold text-base text-zinc-900 dark:text-zinc-100 group-hover:text-emerald-600 dark:group-hover:text-emerald-400 transition">
                        {{ __('Organization Settings & Team') }}
                    </div>
                    <div class="text-xs text-zinc-500 mt-1 leading-relaxed">
                        {{ __('Manage organization name, team permissions, member roles, and pending invitations.') }}
                    </div>
                    <div class="mt-4 flex items-center gap-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        <span>{{ __('Manage Team') }}</span>
                        <span>→</span>
                    </div>
                </a>
            @endif
        </div>
    </div>

    <!-- Dashboard Invite Member Modal for Org Admins -->
    @if ($this->isOrgAdmin)
        <flux:modal wire:model="showInviteModal" name="dashboard-invite-modal" class="max-w-md">
            <form wire:submit="sendInvitation" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Invite Organization Member') }}</flux:heading>
                    <flux:subheading>{{ __('Send an email invitation to join :team.', ['team' => $this->currentTeam?->name]) }}</flux:subheading>
                </div>

                <flux:input wire:model="inviteEmail" type="email" label="Email Address" placeholder="member@organization.com" required />

                <flux:select wire:model="inviteRole" label="Team Role" required>
                    <flux:select.option value="member">Member / Fleet Driver</flux:select.option>
                    <flux:select.option value="admin">Organization Administrator</flux:select.option>
                </flux:select>

                <div class="text-xs text-zinc-500">
                    {{ __('Invited members will receive an email notification containing instructions to accept the invitation and access the organization fleet.') }}
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <flux:button variant="ghost" wire:click="$set('showInviteModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Send Invitation') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>