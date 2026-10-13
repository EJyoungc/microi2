<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-50/80 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased font-sans selection:bg-emerald-500 selection:text-white">
        <flux:header container class="border-b border-slate-200/80 bg-white/95 dark:border-zinc-800 dark:bg-zinc-900/95 backdrop-blur-md">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('dashboard') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    {{ __('Overview') }}
                </flux:navbar.item>
                <flux:navbar.item icon="map" :href="route('tracking')" :current="request()->routeIs('tracking')" wire:navigate>
                    {{ __('Live Map') }}
                </flux:navbar.item>
                <flux:navbar.item icon="clock" :href="route('history')" :current="request()->routeIs('history')" wire:navigate>
                    {{ __('History') }}
                </flux:navbar.item>
                <flux:navbar.item icon="truck" :href="route('vehicles')" :current="request()->routeIs('vehicles')" wire:navigate>
                    {{ __('Vehicles') }}
                </flux:navbar.item>
                @if (auth()->user()?->isMicroMekStaff())
                    <flux:navbar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                        {{ __('Users') }}
                    </flux:navbar.item>
                @endif
            </flux:navbar>

            <flux:spacer />

            <div class="mr-2">
                <flux:button 
                    size="sm" 
                    variant="ghost" 
                    icon="moon" 
                    x-data 
                    @click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'" 
                    title="Toggle Dark / Light Mode"
                />
            </div>

            <x-desktop-user-menu :showTeam="false" />

            <div class="max-lg:hidden">
                <livewire:team-switcher />
            </div>
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
            </flux:sidebar.header>

            <livewire:team-switcher />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')">
                    <flux:sidebar.item icon="layout-grid" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard')  }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{ $slot }}

        <livewire:create-team-modal />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
