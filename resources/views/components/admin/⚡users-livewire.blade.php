<?php

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Notifications\PlatformUserInvitation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $roleFilter = '';
    public string $statusFilter = '';

    // Edit Role / Status Modal
    public bool $showEditModal = false;
    public ?int $editingUserId = null;
    public string $name = '';
    public string $email = '';
    public string $systemRole = 'customer';
    public bool $isActive = true;

    // Create / Invite User Modal
    public bool $showCreateModal = false;
    public string $newUserName = '';
    public string $newUserEmail = '';
    public string $newUserPhone = '';
    public string $newUserRole = 'customer';
    public string $newUserAccountType = 'individual';
    public ?int $newUserTeamId = null;
    public string $newUserTeamRole = 'member';
    public string $newUserPassword = '';
    public bool $sendInviteEmail = true;

    public function boot(): void
    {
        if (! Auth::user()?->isMicroMekStaff()) {
            abort(403, 'Unauthorized access to user management.');
        }
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->when($this->search, function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('phone_number', 'like', "%{$this->search}%");
            })
            ->when($this->roleFilter, fn ($q) => $q->where('system_role', $this->roleFilter))
            ->when($this->statusFilter !== '', function ($q) {
                $q->where('is_active', $this->statusFilter === 'active');
            })
            ->with(['currentTeam'])
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function organizations()
    {
        return Team::where('is_personal', false)->orderBy('name')->get();
    }

    public function openCreateUser(): void
    {
        $this->reset([
            'newUserName',
            'newUserEmail',
            'newUserPhone',
            'newUserRole',
            'newUserAccountType',
            'newUserTeamId',
            'newUserTeamRole',
            'newUserPassword',
        ]);
        $this->newUserRole = 'customer';
        $this->newUserAccountType = 'individual';
        $this->newUserTeamRole = 'member';
        $this->sendInviteEmail = true;
        $this->showCreateModal = true;
    }

    public function createUser(): void
    {
        if (! Auth::user()->isSuperAdmin() && $this->newUserRole === 'super_admin') {
            Flux::toast(variant: 'danger', text: __('Only super administrators can assign the super admin role.'));
            return;
        }

        $validated = $this->validate([
            'newUserName' => ['required', 'string', 'max:255'],
            'newUserEmail' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'newUserPhone' => ['nullable', 'string', 'max:50'],
            'newUserRole' => ['required', 'string', Rule::in(['customer', 'micromek_staff', 'super_admin'])],
            'newUserAccountType' => ['required', 'string', Rule::in(['individual', 'organization'])],
            'newUserTeamId' => ['nullable', 'integer', 'exists:teams,id'],
            'newUserTeamRole' => ['required', 'string', Rule::in(['admin', 'member'])],
            'newUserPassword' => ['nullable', 'string', 'min:8'],
        ]);

        $plainPassword = ! empty($validated['newUserPassword'])
            ? $validated['newUserPassword']
            : Str::password(12, letters: true, numbers: true, symbols: false);

        DB::transaction(function () use ($validated, $plainPassword) {
            $user = User::create([
                'name' => $validated['newUserName'],
                'email' => $validated['newUserEmail'],
                'password' => $plainPassword,
                'phone_number' => $validated['newUserPhone'] ?: null,
                'system_role' => $validated['newUserRole'],
                'account_type' => $validated['newUserAccountType'],
                'onboarding_completed' => true,
                'is_active' => true,
            ]);

            $user->email_verified_at = now();
            $user->save();

            $teamName = null;
            if (! empty($validated['newUserTeamId'])) {
                $team = Team::find($validated['newUserTeamId']);
                if ($team) {
                    $team->members()->attach($user->id, ['role' => $validated['newUserTeamRole']]);
                    $user->update(['current_team_id' => $team->id]);
                    $teamName = $team->name;
                }
            } else {
                $personalTeam = Team::create([
                    'name' => $user->name . "'s Fleet",
                    'is_personal' => true,
                    'status' => 'approved',
                    'created_by' => $user->id,
                ]);
                $personalTeam->members()->attach($user->id, ['role' => \App\Enums\TeamRole::Owner->value]);
                $user->update(['current_team_id' => $personalTeam->id]);
                $teamName = $personalTeam->name;
            }

            if ($this->sendInviteEmail) {
                $user->notify(new PlatformUserInvitation(
                    temporaryPassword: $plainPassword,
                    assignedTeam: $teamName,
                    invitedByName: Auth::user()->name,
                ));
            }

            AuditLog::record('admin.user_created', $user, [
                'system_role' => $validated['newUserRole'],
                'account_type' => $validated['newUserAccountType'],
                'team_id' => $validated['newUserTeamId'],
            ]);
        });

        $this->showCreateModal = false;
        Flux::toast(variant: 'success', text: __('User account created and invited successfully.'));
    }

    public function openEditUser(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->systemRole = $user->system_role ?? 'customer';
        $this->isActive = (bool) $user->is_active;
        $this->showEditModal = true;
    }

    public function updateUser(): void
    {
        if (! Auth::user()->isSuperAdmin() && $this->systemRole === 'super_admin') {
            Flux::toast(variant: 'danger', text: __('Only super admins can assign the super admin role.'));
            return;
        }

        $user = User::findOrFail($this->editingUserId);

        // Prevent suspending oneself
        if ($user->id === Auth::id() && ! $this->isActive) {
            Flux::toast(variant: 'danger', text: __('You cannot suspend your own account.'));
            return;
        }

        $user->update([
            'system_role' => $this->systemRole,
            'is_active' => $this->isActive,
        ]);

        AuditLog::record('admin.user_updated', $user, [
            'system_role' => $this->systemRole,
            'is_active' => $this->isActive,
        ]);

        $this->showEditModal = false;
        Flux::toast(variant: 'success', text: __('User updated successfully.'));
    }

    public function toggleUserStatus(int $id): void
    {
        if ($id === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('You cannot toggle your own active status.'));
            return;
        }

        $user = User::findOrFail($id);
        $newStatus = ! $user->is_active;
        $user->update(['is_active' => $newStatus]);

        AuditLog::record($newStatus ? 'admin.user_activated' : 'admin.user_suspended', $user);
        Flux::toast(variant: $newStatus ? 'success' : 'warning', text: $newStatus ? __('User activated.') : __('User suspended.'));
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('User & Account Directory') }}</flux:heading>
            <flux:subheading>{{ __('Oversee registered individuals, organization personnel, and MicroMek platform staff.') }}</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="user-plus" wire:click="openCreateUser">
                {{ __('Add / Invite User') }}
            </flux:button>
        </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row gap-4">
        <div class="flex-1">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search by name, email, or phone number..." icon="magnifying-glass" />
        </div>
        <div class="w-full sm:w-48">
            <flux:select wire:model.live="roleFilter">
                <flux:select.option value="">All System Roles</flux:select.option>
                <flux:select.option value="super_admin">Super Admin</flux:select.option>
                <flux:select.option value="micromek_staff">MicroMek Staff</flux:select.option>
                <flux:select.option value="customer">Customer / Fleet Member</flux:select.option>
            </flux:select>
        </div>
        <div class="w-full sm:w-40">
            <flux:select wire:model.live="statusFilter">
                <flux:select.option value="">All Statuses</flux:select.option>
                <flux:select.option value="active">Active</flux:select.option>
                <flux:select.option value="inactive">Suspended</flux:select.option>
            </flux:select>
        </div>
    </div>

    <!-- Users Table -->
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden bg-white dark:bg-zinc-900 shadow-xs">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 text-zinc-500 font-medium">
                <tr>
                    <th class="p-4">User</th>
                    <th class="p-4">System Role</th>
                    <th class="p-4">Account Type</th>
                    <th class="p-4">Current Fleet / Team</th>
                    <th class="p-4">Status</th>
                    <th class="p-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->users as $u)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <div class="size-9 rounded-full bg-emerald-100 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-300 font-bold flex items-center justify-center text-xs">
                                    {{ $u->initials() }}
                                </div>
                                <div>
                                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $u->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $u->email }}</div>
                                    @if ($u->phone_number)
                                        <div class="text-xs text-zinc-400 font-mono">{{ $u->phone_number }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="p-4">
                            @if ($u->system_role === 'super_admin')
                                <flux:badge color="red" size="sm">Super Admin</flux:badge>
                            @elseif ($u->system_role === 'micromek_staff')
                                <flux:badge color="indigo" size="sm">MicroMek Staff</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">Customer</flux:badge>
                            @endif
                        </td>
                        <td class="p-4 capitalize text-xs text-zinc-600 dark:text-zinc-400">
                            {{ $u->account_type ?? 'Individual' }}
                        </td>
                        <td class="p-4 text-xs">
                            @if ($u->currentTeam)
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $u->currentTeam->name }}</span>
                                @if (! $u->currentTeam->is_personal)
                                    <flux:badge color="sky" size="sm" class="ml-1">Org</flux:badge>
                                @endif
                            @else
                                <span class="text-zinc-400 italic">No Active Team</span>
                            @endif
                        </td>
                        <td class="p-4">
                            @if ($u->is_active)
                                <flux:badge color="green" size="sm">Active</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm">Suspended</flux:badge>
                            @endif
                        </td>
                        <td class="p-4 text-right space-x-2">
                            <flux:button size="sm" variant="subtle" wire:click="openEditUser({{ $u->id }})">
                                Edit
                            </flux:button>
                            @if ($u->id !== auth()->id())
                                <flux:button size="sm" variant="{{ $u->is_active ? 'ghost' : 'primary' }}" wire:click="toggleUserStatus({{ $u->id }})">
                                    {{ $u->is_active ? 'Suspend' : 'Activate' }}
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-8 text-center text-zinc-500">No users found matching query.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $this->users->links() }}
    </div>

    <!-- Create / Invite User Modal -->
    <flux:modal wire:model="showCreateModal" name="create-user-modal" class="max-w-lg">
        <form wire:submit="createUser" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Add / Invite New User') }}</flux:heading>
                <flux:subheading>{{ __('Register an individual user, fleet member, or MicroMek team staff.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input wire:model="newUserName" label="Full Name" placeholder="e.g. Kondwani Phiri" required />
                <flux:input wire:model="newUserEmail" type="email" label="Email Address" placeholder="user@example.com" required />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input wire:model="newUserPhone" label="Phone Number (Optional)" placeholder="+265 999 123 456" />
                <flux:select wire:model="newUserRole" label="Platform System Role" required>
                    <flux:select.option value="customer">Customer / Member</flux:select.option>
                    <flux:select.option value="micromek_staff">MicroMek Operations Staff</flux:select.option>
                    @if (auth()->user()->isSuperAdmin())
                        <flux:select.option value="super_admin">MicroMek Super Admin</flux:select.option>
                    @endif
                </flux:select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:select wire:model.live="newUserAccountType" label="Account Type" required>
                    <flux:select.option value="individual">Individual</flux:select.option>
                    <flux:select.option value="organization">Organization</flux:select.option>
                </flux:select>

                <flux:select wire:model="newUserTeamRole" label="Team Role">
                    <flux:select.option value="member">Member / Driver</flux:select.option>
                    <flux:select.option value="admin">Administrator</flux:select.option>
                </flux:select>
            </div>

            <div>
                <flux:select wire:model="newUserTeamId" label="Assign to Commercial Organization (Optional)">
                    <flux:select.option value="">None (Personal Fleet)</flux:select.option>
                    @foreach ($this->organizations as $org)
                        <flux:select.option value="{{ $org->id }}">{{ $org->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="newUserPassword" type="password" label="Temporary Password (Optional)" placeholder="Leave blank to auto-generate secure password" />

            <div class="pt-1">
                <flux:checkbox wire:model="sendInviteEmail" label="Send invitation email with login credentials" />
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Create & Invite User</flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- Edit User Modal -->
    <flux:modal wire:model="showEditModal" name="edit-user-modal" class="max-w-md">
        <form wire:submit="updateUser" class="space-y-4">
            <flux:heading size="lg">Manage User Privileges</flux:heading>
            <flux:subheading>Update system role and access state for {{ $name }}.</flux:subheading>

            <flux:input wire:model="email" label="Email Address" disabled />

            <flux:select wire:model="systemRole" label="System Role" required>
                <flux:select.option value="customer">Customer (Standard)</flux:select.option>
                <flux:select.option value="micromek_staff">MicroMek Staff</flux:select.option>
                @if (auth()->user()->isSuperAdmin())
                    <flux:select.option value="super_admin">Super Administrator</flux:select.option>
                @endif
            </flux:select>

            <flux:switch wire:model="isActive" label="Account Active" description="Suspended accounts cannot log in or submit telemetry." />

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showEditModal', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save Privileges</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
