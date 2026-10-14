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

<div class="space-y-4 sm:space-y-6" wire:poll.10s>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 sm:gap-4">
        <div>
            <flux:heading size="xl">{{ __('Live Vehicle Tracking & Telemetry') }}</flux:heading>
            <flux:subheading>{{ __('Real-time interactive tracking with auto-follow mode, user geolocation routing, and instant telemetry inspection.') }}</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="hidden sm:inline">{{ __('Auto-refreshing every 10s') }}</span>
                <span class="sm:hidden">{{ __('Live 10s') }}</span>
            </span>
            <flux:button size="sm" variant="subtle" icon="arrow-path" wire:click="$refresh">
                {{ __('Refresh') }}
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 sm:gap-6"
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
            isRoutingLoading: false,
            isFullScreen: false,
            watchId: null,
            mobileTab: 'map', // 'map' or 'list' for mobile viewports
            vehicleMarkers: {},
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
                        this.vehicleMarkers = {};
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

                    this.renderMarkers(true);
                    this.startUserLocationWatch();

                    setTimeout(() => {
                        if (this.map) this.map.invalidateSize();
                    }, 300);
                });
            },

            startUserLocationWatch() {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition(
                    (pos) => { this.setUserPosition(pos); },
                    (err) => console.warn('Geolocation initial query error:', err.message),
                    { enableHighAccuracy: true, timeout: 8000 }
                );

                if (this.watchId) navigator.geolocation.clearWatch(this.watchId);
                this.watchId = navigator.geolocation.watchPosition(
                    (pos) => { this.setUserPosition(pos); },
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

            buildVehicleIcon(v, isSelected) {
                const markerColor = v.is_online ? '#10b981' : '#64748b';
                const ringStyle = isSelected ? 'box-shadow: 0 0 0 4px #34d399, 0 4px 6px -1px rgba(0,0,0,0.3);' : 'box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);';
                const iconHtml = `
                    <div style='background-color: ${markerColor}; ${ringStyle} width: 32px; height: 32px; border-radius: 50%; border: 2px solid white; display: flex; align-items: center; justify-content: center; font-size: 15px; cursor: pointer; transition: transform 0.2s;'>
                        🚗
                    </div>
                `;
                return window.L.divIcon({
                    html: iconHtml,
                    className: 'custom-vehicle-marker',
                    iconSize: [32, 32],
                    iconAnchor: [16, 16]
                });
            },

            buildPopupContent(v) {
                return `
                    <div style='font-family: inherit; font-size: 12px; padding: 2px; min-width: 170px;'>
                        <strong style='font-size: 14px;'>${v.plate_number}</strong><br>
                        <span style='color: #4b5563;'>${v.name}</span><br>
                        <hr style='margin: 6px 0; border: none; border-top: 1px solid #e5e7eb;'>
                        <b>Velocity:</b> ${v.speed} km/h<br>
                        <b>Device Status:</b> ${v.is_online ? '<span style=\'color: #059669; font-weight: 600;\'>Online</span>' : '<span style=\'color: #64748b;\'>Offline</span>'}<br>
                        <b>Last Telemetry:</b> ${v.last_seen_time || v.last_seen}<br>
                        ${v.battery ? '<b>Battery:</b> ' + v.battery + '%<br>' : ''}
                    </div>
                `;
            },

            // Updates marker coordinates smoothly without destroying layers (prevents popup close flicker)
            renderMarkers(isInitial = false) {
                if (!this.map || !this.markersLayer || !window.L) return;

                const bounds = [];
                const currentVehicleIds = new Set();

                this.vehicles.forEach(v => {
                    currentVehicleIds.add(v.id);
                    const latLng = [v.latitude, v.longitude];
                    bounds.push(latLng);

                    const isSelected = this.selectedVehicle && this.selectedVehicle.id === v.id;
                    const icon = this.buildVehicleIcon(v, isSelected);
                    const popupContent = this.buildPopupContent(v);

                    if (this.vehicleMarkers[v.id]) {
                        // Marker exists: update position, icon, popup content without re-creating
                        const existingMarker = this.vehicleMarkers[v.id];
                        existingMarker.setLatLng(latLng);
                        existingMarker.setIcon(icon);
                        if (existingMarker.getPopup()) {
                            existingMarker.getPopup().setContent(popupContent);
                        }
                    } else {
                        // Create new marker instance
                        const marker = window.L.marker(latLng, { icon: icon }).addTo(this.markersLayer);
                        marker.bindPopup(popupContent, { autoPan: false });

                        marker.on('click', (e) => {
                            this.selectVehicleObj(v, false);
                        });

                        this.vehicleMarkers[v.id] = marker;
                    }

                    // Keep selectedVehicle state fresh with updated telemetry if currently selected
                    if (this.selectedVehicle && this.selectedVehicle.id === v.id) {
                        this.selectedVehicle = v;
                    }
                });

                // Remove stale markers for vehicles no longer present
                Object.keys(this.vehicleMarkers).forEach(idStr => {
                    const id = parseInt(idStr, 10);
                    if (!currentVehicleIds.has(id)) {
                        this.markersLayer.removeLayer(this.vehicleMarkers[id]);
                        delete this.vehicleMarkers[id];
                    }
                });

                // Handle auto-follow or initial fitBounds
                if (this.followingVehicleId) {
                    const tracked = this.vehicles.find(v => v.id === this.followingVehicleId);
                    if (tracked) {
                        this.selectedVehicle = tracked;
                        this.map.panTo([tracked.latitude, tracked.longitude], { animate: true, duration: 0.8 });
                        if (this.userLocation) {
                            this.calculateRoadRoute();
                        }
                    }
                } else if (isInitial && bounds.length > 0 && !this.selectedVehicle) {
                    this.map.fitBounds(bounds, { padding: [50, 50], maxZoom: 15 });
                }
            },

            selectVehicleObj(v, pan = true) {
                this.selectedVehicle = v;

                // Update icon highlight for all existing markers
                this.vehicles.forEach(item => {
                    if (this.vehicleMarkers[item.id]) {
                        const isSel = item.id === v.id;
                        this.vehicleMarkers[item.id].setIcon(this.buildVehicleIcon(item, isSel));
                    }
                });

                if (pan && this.map) {
                    this.map.flyTo([v.latitude, v.longitude], 16, { animate: true, duration: 0.8 });
                }

                // If mobile and in list tab, switch to map tab so user sees the focused vehicle
                if (this.mobileTab !== 'map') {
                    this.mobileTab = 'map';
                    this.$nextTick(() => {
                        if (this.map) this.map.invalidateSize();
                    });
                }

                if (this.userLocation) {
                    this.calculateRoadRoute();
                }
            },

            focus(vehicleId) {
                const found = this.vehicles.find(v => v.id === vehicleId);
                if (found) {
                    this.selectVehicleObj(found, true);
                }
            },

            toggleFollow(vehicleId) {
                if (this.followingVehicleId === vehicleId) {
                    this.followingVehicleId = null;
                } else {
                    this.followingVehicleId = vehicleId;
                    this.focus(vehicleId);
                }
            },

            // Road routing using OpenStreetMap OSRM with haversine fallback
            async calculateRoadRoute() {
                if (!this.userLocation || !this.selectedVehicle || !window.L || !this.routeLayer) return;

                const fromLat = this.userLocation.lat;
                const fromLng = this.userLocation.lng;
                const toLat = this.selectedVehicle.latitude;
                const toLng = this.selectedVehicle.longitude;

                this.isRoutingLoading = true;

                try {
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
                    console.warn('OSRM routing fallback to direct line:', e.message);
                }

                // Fallback: Haversine direct vector
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
                this.routingDurationMin = Math.round((d / 45) * 60);

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

                const polyline = window.L.polyline(coords, {
                    color: '#2563eb',
                    weight: 5,
                    opacity: 0.85,
                    dashArray: isRoadGeometry ? null : '6, 8',
                    lineJoin: 'round',
                    smoothFactor: 1
                }).addTo(this.routeLayer);

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
         x-effect="vehicles = @js($this->vehiclesWithPositions); renderMarkers(false);"
         @focus-vehicle.window="focus($event.detail.vehicleId)">

        <!-- Mobile Segmented View Switcher (Visible only on < lg screens) -->
        <div class="lg:hidden col-span-1 flex items-center bg-zinc-100 dark:bg-zinc-800 p-1 rounded-xl">
            <button type="button"
                    @click="mobileTab = 'map'; $nextTick(() => { if (map) map.invalidateSize(); })"
                    class="flex-1 py-2 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2"
                    :class="mobileTab === 'map' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200'">
                <span>🗺️ Live Map</span>
                <span x-show="selectedVehicle" class="size-2 rounded-full bg-emerald-500"></span>
            </button>
            <button type="button"
                    @click="mobileTab = 'list'"
                    class="flex-1 py-2 text-xs font-bold rounded-lg transition-all flex items-center justify-center gap-2"
                    :class="mobileTab === 'list' ? 'bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 shadow-xs' : 'text-zinc-500 hover:text-zinc-900 dark:hover:text-zinc-200'">
                <span>🚗 Fleet List</span>
                <span class="px-1.5 py-0.5 rounded-full text-[10px] bg-zinc-200 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-300" x-text="vehicles.length"></span>
            </button>
        </div>

        <!-- Vehicle List Sidebar -->
        <div class="space-y-3"
             :class="{
                'hidden': isFullScreen || (mobileTab !== 'list' && window.innerWidth < 1024),
                'block lg:col-span-1': !isFullScreen
             }">
            <div class="flex items-center justify-between">
                <span class="font-semibold text-xs text-zinc-500 uppercase tracking-wider">
                    Vehicles (<span x-text="vehicles.length"></span>)
                </span>
                <button @click="locateUser(true)" 
                        type="button"
                        class="text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1 font-medium cursor-pointer"
                        title="Locate my position">
                    <span>📍 My Position</span>
                </button>
            </div>

            <div class="space-y-2 max-h-[500px] lg:max-h-[640px] overflow-y-auto pr-1">
                <template x-for="item in vehicles" :key="item.id">
                    <div @click="selectVehicleObj(item, true)"
                         class="p-3 rounded-xl border cursor-pointer transition select-none"
                         :class="(selectedVehicle && selectedVehicle.id === item.id) ? 'border-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/30' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-300 dark:hover:border-zinc-600'">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-sm text-zinc-900 dark:text-zinc-100 font-mono" x-text="item.plate_number"></span>
                            <span class="size-2.5 rounded-full" :class="item.is_online ? 'bg-emerald-500' : 'bg-zinc-400'"></span>
                        </div>
                        <div class="text-xs text-zinc-600 dark:text-zinc-400 mt-1" x-text="item.name"></div>
                        <div class="flex items-center justify-between text-xs text-zinc-500 mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                            <span x-text="'🚀 ' + item.speed + ' km/h'"></span>
                            <span x-text="'⏱ ' + item.last_seen"></span>
                        </div>

                        <!-- Follow & Route Quick Actions -->
                        <div class="mt-2.5 pt-2 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between gap-1 text-[11px]" @click.stop>
                            <button @click="toggleFollow(item.id)"
                                    type="button"
                                    class="px-2.5 py-1 rounded-md font-medium transition flex items-center gap-1 cursor-pointer"
                                    :class="followingVehicleId === item.id ? 'bg-emerald-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700'">
                                <span x-text="followingVehicleId === item.id ? '🎯 Following' : '🎯 Follow'"></span>
                            </button>

                            <button @click="selectVehicleObj(item, true); locateUser(false); calculateRoadRoute();"
                                    type="button"
                                    class="px-2.5 py-1 rounded-md font-medium bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900/60 transition flex items-center gap-1 cursor-pointer">
                                <span>🛣️ Route</span>
                            </button>
                        </div>
                    </div>
                </template>

                <div x-show="vehicles.length === 0" class="p-6 text-center text-xs text-zinc-500 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700">
                    No vehicles with active GPS telemetry located yet.
                </div>
            </div>
        </div>

        <!-- Interactive Leaflet Map Container & Non-Blocking Overlay -->
        <div class="transition-all"
             :class="{
                'fixed inset-0 z-[9999] bg-white dark:bg-zinc-950 p-0 h-screen w-screen': isFullScreen,
                'hidden lg:block lg:col-span-3': !isFullScreen && mobileTab !== 'map',
                'block col-span-1 lg:col-span-3': !isFullScreen && mobileTab === 'map'
             }">
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden shadow-sm bg-zinc-100 dark:bg-zinc-900 relative h-full w-full"
                 :class="isFullScreen ? 'rounded-none border-0' : ''">

                <!-- Leaflet Map Div -->
                <div x-ref="mapContainer" wire:ignore 
                     class="w-full relative min-h-[420px] h-[55vh] sm:h-[640px]"
                     :style="isFullScreen ? 'height: 100vh !important; width: 100vw !important; z-index: 10;' : 'z-index: 10;'"></div>

                <!-- Floating Top Toolbar -->
                <div class="absolute top-3 left-12 sm:left-14 z-[400] flex items-center gap-1.5 sm:gap-2 flex-wrap pointer-events-auto">
                    <button @click="locateUser(true)" 
                            type="button"
                            class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1 cursor-pointer">
                        <span>📍 <span class="hidden sm:inline">My Location</span></span>
                    </button>
                    <button @click="renderMarkers(true)" 
                            type="button"
                            class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1 cursor-pointer">
                        <span>🔄 <span class="hidden sm:inline">Reset View</span></span>
                    </button>
                    <button @click="toggleFullScreen()" 
                            type="button"
                            class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-lg bg-white/95 dark:bg-zinc-800/95 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1 cursor-pointer"
                            :title="isFullScreen ? 'Exit Full Screen' : 'Enter Full Screen'">
                        <span x-text="isFullScreen ? '🗗 Exit' : '⛶ Fullscreen'"></span>
                    </button>
                </div>

                <!-- Floating Follow Mode Indicator (Top Right) -->
                <div x-show="followingVehicleId" 
                     x-transition 
                     class="absolute top-3 right-3 sm:top-4 sm:right-4 z-[400] pointer-events-auto"
                     x-cloak>
                    <div class="px-2.5 py-1 sm:px-3 sm:py-1.5 rounded-full bg-emerald-600 text-white font-bold text-xs shadow-lg flex items-center gap-1.5 sm:gap-2">
                        <span class="size-2 rounded-full bg-white animate-ping"></span>
                        <span class="truncate max-w-[120px] sm:max-w-none">Live: <span x-text="selectedVehicle ? selectedVehicle.plate_number : ''"></span></span>
                        <button @click="followingVehicleId = null" class="ml-1 hover:text-emerald-200 text-sm font-black cursor-pointer">✕</button>
                    </div>
                </div>

                <!-- Persistent Bottom Floating Telemetry & Navigation Drawer (Does NOT close on poll) -->
                <div x-show="selectedVehicle" 
                     x-transition 
                     class="absolute bottom-2 inset-x-2 sm:bottom-4 sm:inset-x-6 z-[400] pointer-events-auto max-w-3xl mx-auto bg-white/95 dark:bg-zinc-900/95 backdrop-blur-md rounded-2xl shadow-2xl border border-zinc-200/90 dark:border-zinc-700/90 p-3 sm:p-4 text-xs select-none"
                     x-cloak>
                    <div class="flex items-center justify-between gap-2 sm:gap-3">
                        <!-- Vehicle ID & Status Badge -->
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="size-8 sm:size-9 rounded-xl flex items-center justify-center text-base sm:text-lg shrink-0"
                                 :class="selectedVehicle && selectedVehicle.is_online ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'">
                                🚗
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5 sm:gap-2">
                                    <span class="font-black text-xs sm:text-sm text-zinc-900 dark:text-zinc-100 font-mono truncate" x-text="selectedVehicle ? selectedVehicle.plate_number : ''"></span>
                                    <span class="size-2 rounded-full shrink-0" :class="selectedVehicle && selectedVehicle.is_online ? 'bg-emerald-500 animate-pulse' : 'bg-zinc-400'"></span>
                                </div>
                                <div class="text-[10px] sm:text-[11px] text-zinc-500 truncate" x-text="selectedVehicle ? selectedVehicle.name : ''"></div>
                            </div>
                        </div>

                        <!-- Desktop Telemetry Highlights (Speed & Road Distance) -->
                        <div class="hidden md:flex items-center gap-4 text-zinc-700 dark:text-zinc-300">
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
                        <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                            <!-- Toggle Follow Mode -->
                            <button @click="toggleFollow(selectedVehicle.id)"
                                    type="button"
                                    class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-xl font-bold text-xs transition flex items-center gap-1 shadow-xs cursor-pointer"
                                    :class="followingVehicleId === (selectedVehicle ? selectedVehicle.id : null) ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white'">
                                <span x-text="followingVehicleId === (selectedVehicle ? selectedVehicle.id : null) ? '⏸ Stop' : '🎯 Follow'"></span>
                            </button>

                            <!-- Route Calculation Trigger -->
                            <button @click="locateUser(false); calculateRoadRoute();"
                                    type="button"
                                    class="px-2.5 py-1.5 sm:px-3 sm:py-1.5 rounded-xl font-bold text-xs bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 hover:bg-blue-100 dark:hover:bg-blue-900 transition flex items-center gap-1 cursor-pointer">
                                <span x-text="isRoutingLoading ? '⏳...' : '🛣️ Route'"></span>
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
                    <div class="md:hidden pt-2 mt-2 border-t border-zinc-100 dark:border-zinc-800 flex items-center justify-between text-[11px] text-zinc-600 dark:text-zinc-400">
                        <span>Speed: <strong x-text="(selectedVehicle ? selectedVehicle.speed : 0) + ' km/h'"></strong></span>
                        <span x-show="routingDistanceKm">Route: <strong class="text-blue-600 dark:text-blue-400" x-text="routingDistanceKm + ' km'"></strong></span>
                        <span x-text="selectedVehicle ? selectedVehicle.last_seen : ''"></span>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>