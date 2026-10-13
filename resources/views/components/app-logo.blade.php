@props([
    'sidebar' => false,
])

@if($sidebar)
    <div class="flex items-center gap-3 px-2 py-1">
        <div class="size-8 rounded-lg bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-xs font-black text-base shrink-0">
            M
        </div>
        <div class="in-data-flux-sidebar-collapsed-desktop:hidden truncate">
            <span class="font-extrabold text-base tracking-tight text-zinc-950 dark:text-white">Micro<span class="text-emerald-600 dark:text-emerald-400">i</span></span>
            <span class="block text-[10px] font-semibold text-zinc-400 dark:text-zinc-500 uppercase tracking-wider">by MicroMek</span>
        </div>
    </div>
@else
    <div class="flex items-center gap-2">
        <div class="size-8 rounded-lg bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-xs font-black text-base shrink-0">
            M
        </div>
        <div>
            <span class="font-extrabold text-base tracking-tight text-zinc-950 dark:text-white">Micro<span class="text-emerald-600 dark:text-emerald-400">i</span></span>
        </div>
    </div>
@endif
