<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Microi — Multi-Tenant GPS Vehicle Tracking by MicroMek</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body
    class="bg-slate-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 antialiased font-sans selection:bg-emerald-500 selection:text-white">
    <!-- Navigation Bar -->
    <header
        class="sticky top-0 z-50 backdrop-blur-md bg-white/80 dark:bg-zinc-900/80 border-b border-zinc-200/80 dark:border-zinc-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <a href="/" class="flex items-center gap-3">
                <div
                    class="size-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-500/20 font-black text-xl">
                    M
                </div>
                <div>
                    <span class="font-extrabold text-2xl tracking-tight text-zinc-950 dark:text-white">Micro<span
                            class="text-emerald-600 dark:text-emerald-400">i</span></span>
                    <span
                        class="hidden sm:inline-block ml-2 text-xs font-semibold px-2 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">by
                        MicroMek</span>
                </div>
            </a>

            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                <a href="#features" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition">Features</a>
                <a href="#solutions" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition">Fleet
                    Solutions</a>
                <a href="#how-it-works" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition">How It
                    Works</a>
                <a href="#about" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition">About
                    MicroMek</a>
                <a href="#contact" class="hover:text-emerald-600 dark:hover:text-emerald-400 transition">Contact</a>
            </nav>

            <div class="flex items-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}"
                        class="px-5 py-2.5 rounded-xl font-medium text-sm bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                        class="px-4 py-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200 hover:text-emerald-600 transition">
                        Sign In
                    </a>
                    <a href="{{ route('register') }}"
                        class="px-5 py-2.5 rounded-xl font-semibold text-sm bg-emerald-600 hover:bg-emerald-700 text-white shadow-md shadow-emerald-600/20 transition">
                        Register Fleet
                    </a>
                @endauth
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section
        class="relative overflow-hidden pt-12 pb-24 lg:pt-20 lg:pb-32 bg-radial from-emerald-500/10 via-transparent to-transparent">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                <div class="lg:col-span-7 space-y-6 text-center lg:text-left">
                    <div
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-100/80 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-xs font-semibold">
                        <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        Next-Generation GPS Vehicle & Asset Tracking in Malawi
                    </div>

                    <h1
                        class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight text-zinc-950 dark:text-white leading-[1.15]">
                        Real-Time Intelligence for Every Fleet & Vehicle.
                    </h1>

                    <p
                        class="text-lg sm:text-xl text-zinc-600 dark:text-zinc-400 max-w-2xl mx-auto lg:mx-0 leading-relaxed">
                        Microi empowers businesses, humanitarian organizations, and vehicle owners with precision
                        telemetry, live Leaflet map visualization, instant hardware request fulfillment, and
                        multi-tenant isolation.
                    </p>

                    <div class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-4 pt-2">
                        <a href="{{ route('register') }}"
                            class="w-full sm:w-auto px-8 py-4 rounded-xl font-bold text-base bg-emerald-600 hover:bg-emerald-700 text-white shadow-lg shadow-emerald-600/25 transition text-center">
                            Start Tracking Free
                        </a>
                        <a href="#demo"
                            class="w-full sm:w-auto px-8 py-4 rounded-xl font-bold text-base border border-zinc-300 dark:border-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-900 transition text-center">
                            View Interactive Demo
                        </a>
                    </div>

                    <div
                        class="pt-6 grid grid-cols-3 gap-6 border-t border-zinc-200 dark:border-zinc-800 text-zinc-600 dark:text-zinc-400">
                        <div>
                            <div class="text-2xl font-black text-zinc-900 dark:text-white">99.9%</div>
                            <div class="text-xs">Telemetry Uptime</div>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-zinc-900 dark:text-white">&lt; 10s</div>
                            <div class="text-xs">Live Refresh Latency</div>
                        </div>
                        <div>
                            <div class="text-2xl font-black text-zinc-900 dark:text-white">Malawi</div>
                            <div class="text-xs">Nationwide Coverage</div>
                        </div>
                    </div>
                </div>

                <!-- Live Map Interface Card Preview -->
                <div id="demo" class="lg:col-span-5">
                    <div
                        class="rounded-3xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-4 overflow-hidden relative">
                        <div
                            class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800 px-2">
                            <div class="flex items-center gap-2">
                                <div class="size-3 rounded-full bg-red-400"></div>
                                <div class="size-3 rounded-full bg-amber-400"></div>
                                <div class="size-3 rounded-full bg-emerald-400"></div>
                                <span class="text-xs font-mono text-zinc-400 ml-2">micro.micromek.net/map</span>
                            </div>
                            <span
                                class="text-xs px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400 font-semibold">Live
                                Telemetry</span>
                        </div>

                        <div
                            class="mt-3 relative rounded-2xl overflow-hidden bg-slate-900 h-80 flex flex-col justify-between p-4 text-white">
                            <!-- Mock Map Visual -->
                            <div
                                class="absolute inset-0 opacity-40 bg-[radial-gradient(#10b981_1px,transparent_1px)] [background-size:16px_16px]">
                            </div>

                            <div class="relative z-10 flex justify-between items-start">
                                <div class="bg-black/60 backdrop-blur-md p-3 rounded-xl border border-white/10 text-xs">
                                    <div class="font-bold text-emerald-400 font-mono">BW 4492 • Apex Van 1</div>
                                    <div>Speed: 45.2 km/h • Heading: 120°</div>
                                    <div class="text-zinc-400 text-[10px]">Blantyre - Zomba Highway</div>
                                </div>
                                <div
                                    class="size-8 rounded-full bg-emerald-500/20 border border-emerald-500 text-emerald-400 flex items-center justify-center text-xs animate-ping">
                                </div>
                            </div>

                            <div class="relative z-10 flex justify-between items-end">
                                <div
                                    class="text-[11px] bg-black/60 backdrop-blur-md px-3 py-1.5 rounded-lg border border-white/10">
                                    GPS Unit: MEK-GPS-101 (Battery: 96%)
                                </div>
                                <div class="text-[11px] text-zinc-400">
                                    Updated 3s ago
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                            <div
                                class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-800">
                                <div class="text-zinc-400">Assigned Organization</div>
                                <div class="font-bold text-zinc-800 dark:text-zinc-200 mt-0.5">Apex Logistics MW</div>
                            </div>
                            <div
                                class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-800">
                                <div class="text-zinc-400">Route Replay Available</div>
                                <div class="font-bold text-emerald-600 dark:text-emerald-400 mt-0.5">Full History Saved
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Core Features Grid -->
    <section id="features" class="py-24 bg-white dark:bg-zinc-900/50 border-y border-zinc-200 dark:border-zinc-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto space-y-4">
                <h2 class="text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">Designed
                    for Enterprise Scalability</h2>
                <h3 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-zinc-950 dark:text-white">
                    Engineered for Uncompromised Visibility & Security
                </h3>
                <p class="text-zinc-600 dark:text-zinc-400">
                    Everything your fleet requires: robust data protection, fast mapping, and reliable hardware
                    ingestion.
                </p>
            </div>

            <div class="mt-16 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 flex items-center justify-center text-2xl mb-6">
                        🗺️
                    </div>
                    <h4 class="text-xl font-bold mb-2">Live Leaflet Maps</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Real-time vehicle marker rendering with auto-refresh polling, custom statuses, and interactive
                        zoom & focus.
                    </p>
                </div>

                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-teal-100 dark:bg-teal-950/60 text-teal-600 flex items-center justify-center text-2xl mb-6">
                        ⏱️
                    </div>
                    <h4 class="text-xl font-bold mb-2">Historical Route Playback</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Pick any calendar date and replay exact vehicle movement as continuous polyline routes with
                        detailed time and velocity records.
                    </p>
                </div>

                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-cyan-100 dark:bg-cyan-950/60 text-cyan-600 flex items-center justify-center text-2xl mb-6">
                        🏢
                    </div>
                    <h4 class="text-xl font-bold mb-2">Multi-Tenant Isolation</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Strict boundaries guarantee that organizations, companies, and individuals can only access their
                        authorized fleet data.
                    </p>
                </div>

                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-indigo-100 dark:bg-indigo-950/60 text-indigo-600 flex items-center justify-center text-2xl mb-6">
                        🔒
                    </div>
                    <h4 class="text-xl font-bold mb-2">Versioned Device REST API</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        High-throughput `/api/v1/device/telemetry` ingestion endpoint protected by cryptographic hashed
                        token authentication.
                    </p>
                </div>

                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-amber-100 dark:bg-amber-950/60 text-amber-600 flex items-center justify-center text-2xl mb-6">
                        📡
                    </div>
                    <h4 class="text-xl font-bold mb-2">GPS Device Provisioning</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Seamless customer requests, approval workflows, secret rotation, and hardware binding executed
                        by MicroMek engineers.
                    </p>
                </div>

                <div
                    class="p-8 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:border-emerald-500/50 transition">
                    <div
                        class="size-12 rounded-xl bg-rose-100 dark:bg-rose-950/60 text-rose-600 flex items-center justify-center text-2xl mb-6">
                        🛡️
                    </div>
                    <h4 class="text-xl font-bold mb-2">Comprehensive Audit Trails</h4>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        Every sensitive administrative action, organization review, device assignment, and credential
                        rotation is securely logged.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- About MicroMek Section -->
    <section id="about" class="py-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="space-y-6">
                    <span
                        class="text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-400">About
                        MicroMek</span>
                    <h3 class="text-3xl sm:text-4xl font-extrabold text-zinc-950 dark:text-white leading-tight">
                        Pioneering Autonomous Systems & Telemetry in Malawi
                    </h3>
                    <p class="text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        <strong>MicroMek</strong> is a Malawian hardware and IoT innovator building autonomous drones
                        and intelligent systems for essential healthcare logistics, environmental observation, and fleet
                        monitoring.
                    </p>
                    <p class="text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        With proven international research partnerships including Virginia Tech University's Unmanned
                        Systems Lab, MicroMek develops locally manufactured, low-cost UAVs and connected GPS tracking
                        technology to serve hospitals, logistics companies, and relief organizations across Malawi and
                        Africa.
                    </p>
                    <div class="pt-4 flex flex-wrap gap-4">
                        <div
                            class="p-4 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 text-sm">
                            <span class="font-bold text-zinc-950 dark:text-white block">Founder & CEO</span>
                            <span class="text-zinc-500">Dumisani Kaliati</span>
                        </div>
                        <div
                            class="p-4 rounded-xl bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 text-sm">
                            <span class="font-bold text-zinc-950 dark:text-white block">Headquarters</span>
                            <span class="text-zinc-500">Blantyre, Malawi</span>
                        </div>
                    </div>
                </div>

                <div
                    class="p-8 rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-800 text-white shadow-xl space-y-6">
                    <h4 class="text-2xl font-bold">Why Choose Microi?</h4>
                    <ul class="space-y-4 text-sm text-emerald-50">
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-300 font-bold">✓</span>
                            <span><strong>Locally engineered & supported:</strong> Direct technical assistance and
                                on-the-ground installation in Malawi.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-300 font-bold">✓</span>
                            <span><strong>Zero vendor lock-in:</strong> Open telemetry API ready for standard GPS
                                trackers and EcoTracker units.</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="text-emerald-300 font-bold">✓</span>
                            <span><strong>Enterprise Multi-Tenancy:</strong> Complete administrative separation for
                                commercial transport fleets and NGOs.</span>
                        </li>
                    </ul>
                    <div class="pt-4 border-t border-emerald-500/40">
                        <a href="{{ route('register') }}"
                            class="inline-block px-6 py-3 rounded-xl bg-white text-emerald-800 font-bold hover:bg-emerald-50 transition text-sm">
                            Register Your Organization
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-20 bg-zinc-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">
                <div class="space-y-4">
                    <div class="text-2xl font-extrabold">Micro<span class="text-emerald-400">i</span></div>
                    <p class="text-sm text-zinc-400 leading-relaxed">
                        Official GPS vehicle and asset tracking platform for MicroMek. Operating nationwide in Malawi.
                    </p>
                    <div class="text-xs text-zinc-500">
                        Domain: micro.micromek.net
                    </div>
                </div>

                <div class="space-y-3">
                    <h5 class="text-sm font-bold uppercase tracking-wider text-emerald-400">Verified Contact</h5>
                    <p class="text-sm text-zinc-300">📞 Phone: +265 996 409 210</p>
                    <p class="text-sm text-zinc-300">✉️ Email: info@micromek.net</p>
                    <p class="text-sm text-zinc-300">📍 Blantyre, Malawi</p>
                </div>

                <div class="space-y-3">
                    <h5 class="text-sm font-bold uppercase tracking-wider text-emerald-400">Quick Access</h5>
                    <div class="flex flex-col space-y-2 text-sm text-zinc-400">
                        <a href="{{ route('login') }}" class="hover:text-white transition">Sign In to Dashboard</a>
                        <a href="{{ route('register') }}" class="hover:text-white transition">Create Individual / Org
                            Account</a>
                        <a href="https://micromek.net" target="_blank" class="hover:text-white transition">MicroMek
                            Main Website</a>
                    </div>
                </div>
            </div>

            <div
                class="mt-16 pt-8 border-t border-zinc-800 flex flex-col sm:flex-row items-center justify-between text-xs text-zinc-500 gap-4">
                <p>© {{ date('Y') }} MicroMek. All Rights Reserved.</p>
                <p>Designed and built for Microi vehicle tracking infrastructure.</p>
            </div>
        </div>
    </section>
</body>

</html>
