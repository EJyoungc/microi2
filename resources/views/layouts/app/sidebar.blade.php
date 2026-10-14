<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-50/80 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased font-sans selection:bg-emerald-500 selection:text-white">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-slate-200/80 bg-white/95 dark:border-zinc-800 dark:bg-zinc-900/95 backdrop-blur-md">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Fleet & Tracking')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Overview') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="map" :href="route('tracking')" :current="request()->routeIs('tracking')" wire:navigate>
                        {{ __('Live Tracking Map') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clock" :href="route('history')" :current="request()->routeIs('history')" wire:navigate>
                        {{ __('History Playback') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="truck" :href="route('vehicles')" :current="request()->routeIs('vehicles')" wire:navigate>
                        {{ __('Vehicles & Fleet') }}
                    </flux:sidebar.item>
                    @php
                        $userCurrentTeam = auth()->user()?->currentTeam;
                        $canManageTeam = $userCurrentTeam && ! $userCurrentTeam->is_personal && (
                            auth()->user()?->ownsTeam($userCurrentTeam) || 
                            auth()->user()?->teamRole($userCurrentTeam) === \App\Enums\TeamRole::Admin ||
                            auth()->user()?->isSuperAdmin()
                        );
                    @endphp
                    @if ($canManageTeam)
                        <flux:sidebar.item icon="user-group" :href="route('teams.edit', ['team' => $userCurrentTeam->slug])" :current="request()->routeIs('teams.edit')" wire:navigate>
                            {{ __('Team Members & Invites') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                @if (auth()->user()?->isMicroMekStaff())
                    <flux:sidebar.group :heading="__('MicroMek Admin')" class="grid mt-4">
                        <flux:sidebar.item icon="cpu-chip" :href="route('admin.devices')" :current="request()->routeIs('admin.devices')" wire:navigate>
                            {{ __('GPS Hardware') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="inbox-arrow-down" :href="route('admin.requests')" :current="request()->routeIs('admin.requests')" wire:navigate>
                            {{ __('Device Requests') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office-2" :href="route('admin.organizations')" :current="request()->routeIs('admin.organizations')" wire:navigate>
                            {{ __('Organizations') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                            {{ __('User Directory') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <div class="px-3 py-2 border-t border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-xs text-zinc-500">
                <span class="in-data-flux-sidebar-collapsed-desktop:hidden font-medium">Theme Mode</span>
                <flux:button 
                    size="sm" 
                    variant="ghost" 
                    icon="moon" 
                    x-data 
                    @click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'" 
                    title="Toggle Dark / Light Mode"
                />
            </div>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
        @stack('scripts')
    </body>
</html>
