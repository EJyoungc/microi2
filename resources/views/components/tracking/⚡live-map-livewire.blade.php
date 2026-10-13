<?php

use App\Models\Vehicle;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $selectedVehicleId = null;

    #[Computed]
    public function vehiclesWithPositions()
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
            ->whereHas('currentAssignment.device', function ($q) {
                $q->whereNotNull('last_latitude')->whereNotNull('last_longitude');
            })
            ->with(['currentAssignment.device'])
            ->get()
            ->map(function ($vehicle) {
                $device = $vehicle->currentAssignment->device;

                return [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'category' => $vehicle->category,
                    'latitude' => (float) $device->last_latitude,
                    'longitude' => (float) $device->last_longitude,
                    'speed' => $device->last_speed ?? 0,
                    'heading' => $device->last_heading ?? 0,
                    'battery' => $device->battery_level ?? null,
                    'is_online' => $device->isOnline(),
                    'last_seen' => $device->last_seen_at ? $device->last_seen_at->diffForHumans() : 'Unknown',
                    'last_seen_time' => $device->last_seen_at ? $device->last_seen_at->format('H:i:s, d M') : '',
                ];
            });
    }

    public function selectVehicle(int $id): void
    {
        $this->selectedVehicleId = $id;
        $this->dispatch('focusVehicle', vehicleId: $id);
    }
}; ?>

<div class="space-y-6" wire:poll.10s>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Live Vehicle Tracking & Telemetry') }}</flux:heading>
            <flux:subheading>{{ __('Real-time interactive tracking with auto-follow mode, user geolocation routing, and instant telemetry inspection.') }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                {{ __('Auto-refreshing every 10s') }}
            </span>
            <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="$refresh">
                {{ __('Refresh') }}
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6"
         x-data="{
            map: null,
            markersLayer: null,
            routeLayer: null,
            userMarker: null,
            userLocation: null,
            followingVehicleId: null,
            selectedVehicle: null,
            routingDistanceKm: null,
            routingDurationMin: null,
            routeGeometry: null,
            isRoutingLoading: false,
            isFullScreen: false,
            watchId: null,
            detailsExpanded: false,
            vehicles: @js($this->vehiclesWithPositions),

            toggleFullScreen() {
                this.isFullScreen = !this.isFullScreen;
                this.$nextTick(() => {
                    setTimeout(() => {
                        if (this.map) {
                            this.map.invalidateSize();
                            if (this.selectedVehicle) {
                                this.map.panTo([this.selectedVehicle.latitude, this.selectedVehicle.longitude]);
                            }
                        }
                    }, 250);
                });
            },

            initMap() {
                this.$nextTick(() => {
                    const container = this.$refs.mapContainer;
                    if (!container) return;
                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                    }

                    const LInstance = window.L;
                    if (!LInstance) return;

                    // Initialize centered on Blantyre, Malawi
                    this.map = LInstance.map(container, {
                        zoomControl: true,
                        tap: false
                    }).setView([-15.786111, 35.005833], 13);

                    LInstance.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors | MicroMek Microi',
                        maxZoom: 19
                    }).addTo(this.map);

                    this.markersLayer = LInstance.layerGroup().addTo(this.map);
                    this.routeLayer = LInstance.layerGroup().addTo(this.map);

                    this.renderMarkers();

                    // Start continuous position tracking
                    this.startUserLocationWatch();

                    setTimeout(() => {
                        if (this.map) this.map.invalidateSize();
                    }, 300);
                });
            },

            startUserLocationWatch() {
                if (!navigator.geolocation) return;

                // Single immediate check
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        this.setUserPosition(pos);
                    },
                    (err) => console.warn('Geolocation initial query error:', err.message),
                    { enableHighAccuracy: true, timeout: 8000 }
                );

                // Continuous watch
                if (this.watchId) navigator.geolocation.clearWatch(this.watchId);
                this.watchId = navigator.geolocation.watchPosition(
                    (pos) => {
                        this.setUserPosition(pos);
                    },
                    (err) => console.warn('Geolocation watch error:', err.message),
                    { enableHighAccuracy: true, maximumAge: 10000, timeout: 15000 }
                );
            },

            setUserPosition(pos) {
                this.userLocation = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: Math.round(pos.coords.accuracy)
                };

                this.updateUserMarker();

                // If following a vehicle, update live path routing to target
                if (this.followingVehicleId && this.selectedVehicle) {
                    this.calculateRoadRoute();
                }
            },

            locateUser(fly = true) {
                if (!this.userLocation) {
                    this.startUserLocationWatch();
                } else if (fly && this.map) {
                    this.map.flyTo([this.userLocation.lat, this.userLocation.lng], 16, { animate: true, duration: 1 });
                }
            },

            updateUserMarker() {
                if (!this.map || !this.userLocation || !window.L) return;

                if (this.userMarker) {
                    this.userMarker.setLatLng([this.userLocation.lat, this.userLocation.lng]);
                } else {
                    const userIconHtml = `<div style='background-color: #2563eb; width: 22px; height: 22px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 10px rgba(37, 99, 235, 0.8); display: flex; align-items: center; justify-content: center; color: white; font-size: 10px;'>📍</div>`;
                    const userIcon = window.L.divIcon({
                        html: userIconHtml,
                        className: 'user-location-marker',
                        iconSize: [22, 22],
                        iconAnchor: [11, 11]
                    });

                    this.userMarker = window.L.marker([this.userLocation.lat, this.userLocation.lng], { icon: userIcon })
                        .addTo(this.map)
                        .bindPopup(`<b>Your Current Position</b><br>Accuracy: ±${this.userLocation.accuracy}m`);
                }
            },

            renderMarkers() {
                if (!this.map || !this.markersLayer || !window.L) return;
                this.markersLayer.clearLayers();
                const bounds = [];

                this.vehicles.forEach(v => {
                    const latLng = [v.latitude, v.longitude];
                    bounds.push(latLng);

                    const isOnline = v.is_online;
                    const isSelected = this.selectedVehicle && this.selectedVehicle.id === v.id;
                    const markerColor = isOnline ? '#10b981' : '#64748b';
                    const ringStyle = isSelected ? 'ring-4 ring-emerald-400 ring-offset-2' : '';

                    const iconHtml = `
                        <div class='${ringStyle}' style='background-color: ${markerColor}; width: 32px; height: 32px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center; font-size: 15px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3); cursor: pointer;'>
                            🚗
                        </div>
                    `;
                    const customIcon = window.L.divIcon({
                        html: iconHtml,
                        className: 'custom-vehicle-marker',
                        iconSize: [32, 32],
                        iconAnchor: [16, 16]
                    });

                    const marker = window.L.marker(latLng, { icon: customIcon }).addTo(this.markersLayer);

                    marker.on('click', () => {
                        this.selectVehicleObj(v);
                    });

                    marker.bindPopup(`
                        <div style='font-family: inherit; font-size: 12px; padding: 2px; min-width: 160px;'>
                            <strong style='font-size: 14px;'>${v.plate_number}</strong><br>
                            <span>${v.name}</span><br>
                            <hr style='margin: 4px 0;'>
                            <b>Velocity:</b> ${v.speed} km/h<br>
                            <b>Device Status:</b> ${v.is_online ? '<span style=\'color: #059669; font-weight: 600;\'>Online</span>' : '<span style=\'color: #64748b;\'>Offline</span>'}<br>
                            <b>Last Telemetry:</b> ${v.last_seen_time}<br>
                            ${v.battery ? '<b>Battery:</b> ' + v.battery + '%<br>' : ''}
                        </div>
                    `);
                });

                // Auto follow target vehicle if follow mode is active
                if (this.followingVehicleId) {
                    const tracked = this.vehicles.find(v => v.id === this.followingVehicleId);
                    if (tracked) {
                        this.selectedVehicle = tracked;
                        this.map.panTo([tracked.latitude, tracked.longitude], { animate: true, duration: 0.8 });
                        if (this.userLocation) {
                            this.calculateRoadRoute();
                        }
                    }
                } else if (bounds.length > 0 && !this.selectedVehicle) {
                    this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
                }
            },

            selectVehicleObj(v) {
                this.selectedVehicle = v;
                this.$wire.selectVehicle(v.id);
                this.focus(v.id);
                if (this.userLocation) {
                    this.calculateRoadRoute();
                }
            },

            focus(vehicleId) {
                const found = this.vehicles.find(v => v.id === vehicleId);
                if (found && this.map) {
                    this.selectedVehicle = found;
                    this.map.flyTo([found.latitude, found.longitude], 16, { animate: true, duration: 1 });
                    if (this.userLocation) {
                        this.calculateRoadRoute();
                    }
                }
            },

            toggleFollow(vehicleId) {
                if (this.followingVehicleId === vehicleId) {
                    this.followingVehicleId = null;
                } else {
                    this.followingVehicleId = vehicleId;
                    this.focus(vehicleId);
                    if (this.userLocation) {
                        this.calculateRoadRoute();
                    }
                }
            },

            // Road routing using OpenStreetMap OSRM Routing Engine with haversine fallback
            async calculateRoadRoute() {
                if (!this.userLocation || !this.selectedVehicle || !window.L || !this.routeLayer) return;

                const fromLat = this.userLocation.lat;
                const fromLng = this.userLocation.lng;
                const toLat = this.selectedVehicle.latitude;
                const toLng = this.selectedVehicle.longitude;

                this.isRoutingLoading = true;

                try {
                    // Query OSRM routing service (standard OpenStreetMap driving profile)
                    const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${fromLng},${fromLat};${toLng},${toLat}?overview=full&geometries=geojson`;
                    const res = await fetch(osrmUrl, { signal: AbortSignal.timeout(3500) });
                    const data = await res.json();

                    if (data && data.routes && data.routes.length > 0) {
                        const route = data.routes[0];
                        this.routingDistanceKm = (route.distance / 1000).toFixed(2);
                        this.routingDurationMin = Math.round(route.duration / 60);

                        const routeCoords = route.geometry.coordinates.map(c => [c[1], c[0]]);
                        this.drawRoadRouteOnMap(routeCoords, true);
                        this.isRoutingLoading = false;
                        return;
                    }
                } catch (e) {
                    console.warn('OSRM routing service fallback to direct path calculation:', e.message);
                }

                // Fallback: Great-Circle Direct Vector
                const R = 6371;
                const dLat = (toLat - fromLat) * Math.PI / 180;
                const dLon = (toLng - fromLng) * Math.PI / 180;
                const a =
                    Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(fromLat * Math.PI / 180) * Math.cos(toLat * Math.PI / 180) *
                    Math.sin(dLon/2) * Math.sin(dLon/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                const d = R * c;

                this.routingDistanceKm = d.toFixed(2);
                this.routingDurationMin = Math.round((d / 45) * 60); // approx 45 km/h driving speed

                const directCoords = [
                    [fromLat, fromLng],
                    [toLat, toLng]
                ];
                this.drawRoadRouteOnMap(directCoords, false);
                this.isRoutingLoading = false;
            },

            drawRoadRouteOnMap(coords, isRoadGeometry = true) {
                if (!this.routeLayer || !window.L) return;
                this.routeLayer.clearLayers();

                // Navigation polyline (Blue road path with soft glow)
                const polyline = window.L.polyline(coords, {
                    color: '#2563eb',
                    weight: 5,
                    opacity: 0.85,
                    dashArray: isRoadGeometry ? null : '6, 8',
                    lineJoin: 'round',
                    smoothFactor: 1
                }).addTo(this.routeLayer);

                // Midpoint distance tag
                const midIndex = Math.floor(coords.length / 2);
                const midPoint = coords[midIndex];

                const tooltipText = isRoadGeometry
                    ? `🛣️ ${this.routingDistanceKm} km (${this.routingDurationMin} min drive)`
                    : `📏 ${this.routingDistanceKm} km direct`;

                const distTooltip = window.L.tooltip({
                    permanent: true,
                    direction: 'center',
                    className: 'distance-badge'
                })
                .setContent(tooltipText)
                .setLatLng(midPoint);

                polyline.bindTooltip(distTooltip);
            },

            clearRouting() {
                if (this.routeLayer) {
                    this.routeLayer.clearLayers();
                }
                this.routingDistanceKm = null;
                this.routingDurationMin = null;
            }
         }"
         x-init="initMap()"
         x-effect="vehicles = @js($this->vehiclesWithPositions); renderMarkers();"
         @focus-vehicle.window="focus($event.detail.vehicleId)">

        <!-- Vehicle List Sidebar -->
        <div class="lg:col-span-1 space-y-3" :class="isFullScreen ? 'hidden' : 'block'">
            <div class="flex items-center justify-between">
                <span class="font-semibold text-xs text-zinc-500 uppercase tracking-wider">
                    Vehicles ({{ count($this->vehiclesWithPositions) }})
                </span>
                <button @click="locateUser(true)" 
                        type="button"
                        class="text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1 font-medium"
                        title="Locate my position">
                    <span>📍 My Position</span>
                </button>
            </div>

            <div class="space-y-2 max-h-[640px] overflow-y-auto pr-1">
                @forelse ($this->vehiclesWithPositions as $item)
                    <div @click="selectVehicleObj({{ json_encode($item) }})"
                         class="p-3 rounded-xl border cursor-pointer transition select-none"
                         :class="(selectedVehicle && selectedVehicle.id === {{ $item['id'] }}) ? 'border-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-300 dark:hover:border-zinc-600'">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-zinc-900 dark:text-zinc-100 font-mono">{{ $item['plate_number'] }}</span>
                            <span class="size-2.5 rounded-full {{ $item['is_online'] ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                        </div>
                        <div class="text-xs text-zinc-600 dark:text-zinc-400 mt-1">{{ $item['name'] }}</div>
                        <div class="flex items-center justify-between text-xs text-zinc-500 mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <span>🚀 {{ $item['speed'] }} km/h</span>
                            <span>⏱ {{ $item['last_seen'] }}</span>
                        </div>

                        <!-- Follow & Route Quick Actions -->
                        <div class="mt-2.5 pt-2 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-1 text-[11px]" @click.stop>
                            <button @click="toggleFollow({{ $item['id'] }})"
                                    type="button"
                                    class="px-2 py-1 rounded-md font-medium transition flex items-center gap-1"
                                    :class="followingVehicleId === {{ $item['id'] }} ? 'bg-emerald-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700'">
                                <span x-text="followingVehicleId === {{ $item['id'] }} ? '🎯 Following' : '🎯 Follow'"></span>
                            </button>

                            <button @click="selectVehicleObj({{ json_encode($item) }}); locateUser(false); calculateRoadRoute();"
                                    type="button"
                                    class="px-2 py-1 rounded-md font-medium bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/60 transition flex items-center gap-1">
                                <span>🛣️ Route</span>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-center text-xs text-zinc-500 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700">
                        No vehicles with active GPS telemetry located yet.
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Interactive Leaflet Map Container & Non-Blocking Overlay -->
        <div class="transition-all"
             :class="isFullScreen ? 'fixed inset-0 z-[9999] bg-white dark:bg-zinc-950 p-0 h-screen w-screen' : 'lg:col-span-3'">
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden shadow-sm bg-zinc-100 dark:bg-zinc-900 relative h-full w-full"
                 :class="isFullScreen ? 'rounded-none border-0' : ''">

                <!-- Leaflet Map Div (Pointer events fully enabled) -->
                <div x-ref="mapContainer" wire:ignore 
                     class="w-full relative"
                     :style="isFullScreen ? 'height: 100vh; width: 100vw; z-index: 10;' : 'height: 640px; width: 100%; z-index: 10;'"></div>

                <!-- Floating Top Toolbar (Above Map, Pointer Events Pass-Through on container) -->
                <div class="absolute top-4 left-14 z-[400] flex items-center gap-2 flex-wrap pointer-events-auto">
                    <button @click="locateUser(true)" 
                            type="button"
                            class="px-3 py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1.5 cursor-pointer">
                        <span>📍 My Location</span>
                    </button>
                    <button @click="renderMarkers()" 
                            type="button"
                            class="px-3 py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1.5 cursor-pointer">
                        <span>🔄 Reset View</span>
                    </button>
                    <button @click="toggleFullScreen()" 
                            type="button"
                            class="px-3 py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1.5 cursor-pointer"
                            :title="isFullScreen ? 'Exit Full Screen' : 'Enter Full Screen'">
                        <span x-text="isFullScreen ? '🗗 Exit Fullscreen' : '⛶ Fullscreen'"></span>
                    </button>
                </div>

                <!-- Floating Follow Mode Indicator (Top Right) -->
                <div x-show="followingVehicleId" 
                     x-transition 
                     class="absolute top-4 right-4 z-[400] pointer-events-auto"
                     x-cloak>
                    <div class="px-3 py-1.5 rounded-full bg-emerald-600 text-white font-bold text-xs shadow-lg flex items-center gap-2">
                        <span class="size-2 rounded-full bg-white animate-ping"></span>
                        <span>Following Live: <span x-text="selectedVehicle ? selectedVehicle.plate_number : ''"></span></span>
                        <button @click="followingVehicleId = null" class="ml-1 hover:text-emerald-200 text-sm font-black">✕</button>
                    </div>
                </div>

                <!-- Compact Bottom Floating Telemetry & Navigation Bar (Does NOT cover the map) -->
                <div x-show="selectedVehicle" 
                     x-transition 
                     class="absolute bottom-4 inset-x-4 sm:inset-x-8 z-[400] pointer-events-auto max-w-3xl mx-auto bg-white/95 dark:bg-zinc-900/95 backdrop-blur-md rounded-2xl shadow-2xl border border-zinc-200/90 dark:border-zinc-700/90 p-3 sm:p-4 text-xs select-none"
                     x-cloak>
                    <div class="flex items-center justify-between gap-3">
                        <!-- Vehicle ID & Status Badge -->
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="size-9 rounded-xl flex items-center justify-center text-lg shrink-0"
                                 :class="selectedVehicle && selectedVehicle.is_online ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'">
                                🚗
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-black text-sm text-zinc-900 dark:text-zinc-100 font-mono truncate" x-text="selectedVehicle ? selectedVehicle.plate_number : ''"></span>
                                    <span class="size-2 rounded-full shrink-0" :class="selectedVehicle && selectedVehicle.is_online ? 'bg-emerald-500 animate-pulse' : 'bg-zinc-400'"></span>
                                </div>
                                <div class="text-[11px] text-zinc-500 truncate" x-text="selectedVehicle ? selectedVehicle.name : ''"></div>
                            </div>
                        </div>

                        <!-- Telemetry Highlights (Speed & Road Distance) -->
                        <div class="hidden sm:flex items-center gap-4 text-zinc-700 dark:text-zinc-300">
                            <div>
                                <span class="text-[10px] text-zinc-400 block font-semibold">SPEED</span>
                                <span class="font-mono font-bold text-sm" x-text="(selectedVehicle ? selectedVehicle.speed : 0) + ' km/h'"></span>
                            </div>
                            <div class="border-l border-zinc-200 dark:border-zinc-700 pl-4" x-show="routingDistanceKm">
                                <span class="text-[10px] text-zinc-400 block font-semibold">NAV ROUTE</span>
                                <span class="font-mono font-bold text-sm text-blue-600 dark:text-blue-400" x-text="routingDistanceKm + ' km (' + routingDurationMin + 'm)'"></span>
                            </div>
                            <div class="border-l border-zinc-200 dark:border-zinc-700 pl-4">
                                <span class="text-[10px] text-zinc-400 block font-semibold">COORDINATES</span>
                                <span class="font-mono text-[11px] text-zinc-500 truncate" x-text="selectedVehicle ? (selectedVehicle.latitude.toFixed(3) + ', ' + selectedVehicle.longitude.toFixed(3)) : ''"></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2 shrink-0">
                            <!-- Toggle Follow Mode -->
                            <button @click="toggleFollow(selectedVehicle.id)"
                                    type="button"
                                    class="px-3 py-1.5 rounded-xl font-bold text-xs transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                                    :class="followingVehicleId === (selectedVehicle ? selectedVehicle.id : null) ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white'">
                                <span x-text="followingVehicleId === (selectedVehicle ? selectedVehicle.id : null) ? '⏸ Stop Follow' : '🎯 Follow'"></span>
                            </button>

                            <!-- Route Calculation Trigger -->
                            <button @click="locateUser(false); calculateRoadRoute();"
                                    type="button"
                                    class="px-3 py-1.5 rounded-xl font-bold text-xs bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900 transition flex items-center gap-1 cursor-pointer">
                                <span x-text="isRoutingLoading ? '⏳ Routing...' : '🛣️ Road Route'"></span>
                            </button>

                            <!-- Dismiss / Clear -->
                            <button @click="selectedVehicle = null; followingVehicleId = null; clearRouting();" 
                                    class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 text-base p-1 cursor-pointer"
                                    title="Close panel">
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Mobile-Only Compact Telemetry Line -->
                    <div class="sm:hidden pt-2 mt-2 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-[11px] text-zinc-600 dark:text-zinc-400">
                        <span>Speed: <strong x-text="(selectedVehicle ? selectedVehicle.speed : 0) + ' km/h'"></strong></span>
                        <span x-show="routingDistanceKm">Route: <strong class="text-blue-600" x-text="routingDistanceKm + ' km'"></strong></span>
                        <span x-text="selectedVehicle ? selectedVehicle.last_seen : ''"></span>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>