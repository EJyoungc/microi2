<?php

use App\Models\LocationHistory;
use App\Models\Vehicle;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $selectedVehicleId = null;
    public string $selectedDate = '';

    public function mount(): void
    {
        $first = $this->vehicles->first();
        if ($first) {
            $this->selectedVehicleId = $first->id;
            $dates = $this->availableDates;
            if (! empty($dates)) {
                $this->selectedDate = $dates[0]['date'];
            } else {
                $this->selectedDate = now()->format('Y-m-d');
            }
        }
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
            ->where('status', 'active')
            ->get();
    }

    #[Computed]
    public function availableDates(): array
    {
        if (! $this->selectedVehicleId) {
            return [];
        }

        return LocationHistory::query()
            ->where('vehicle_id', $this->selectedVehicleId)
            ->selectRaw('DATE(recorded_at) as record_date, count(*) as pings_count')
            ->groupBy('record_date')
            ->orderByDesc('record_date')
            ->get()
            ->map(function ($row) {
                $dateObj = Carbon::parse($row->record_date);
                $isToday = $dateObj->isToday();
                $isYesterday = $dateObj->isYesterday();
                $prefix = $isToday ? 'Today, ' : ($isYesterday ? 'Yesterday, ' : '');

                return [
                    'date' => $dateObj->format('Y-m-d'),
                    'label' => $prefix . $dateObj->format('M d, Y') . " ({$row->pings_count} pings)",
                    'count' => (int) $row->pings_count,
                ];
            })
            ->toArray();
    }

    #[Computed]
    public function historyPoints()
    {
        if (! $this->selectedVehicleId || empty($this->selectedDate)) {
            return [];
        }

        $date = Carbon::parse($this->selectedDate)->format('Y-m-d');

        return LocationHistory::query()
            ->where('vehicle_id', $this->selectedVehicleId)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at', 'asc')
            ->get()
            ->map(fn ($p) => [
                'latitude' => (float) $p->latitude,
                'longitude' => (float) $p->longitude,
                'speed' => $p->speed ?? 0,
                'heading' => $p->heading ?? 0,
                'recorded_at' => $p->recorded_at->format('H:i:s'),
            ])->toArray();
    }

    public function updatedSelectedVehicleId(): void
    {
        $dates = $this->availableDates;
        if (! empty($dates)) {
            $this->selectedDate = $dates[0]['date'];
        } else {
            $this->selectedDate = '';
        }

        $this->dispatch('historyDataUpdated', points: $this->historyPoints);
    }

    public function updatedSelectedDate(): void
    {
        $this->dispatch('historyDataUpdated', points: $this->historyPoints);
    }

    public function exportCsv()
    {
        if (! $this->selectedVehicleId || empty($this->selectedDate)) {
            Flux::toast(variant: 'warning', text: __('Please select a vehicle and a recorded date to export.'));
            return null;
        }

        $vehicle = Vehicle::find($this->selectedVehicleId);
        $plate = $vehicle ? Str::slug($vehicle->plate_number) : 'vehicle';
        $date = Carbon::parse($this->selectedDate)->format('Y-m-d');

        $records = LocationHistory::query()
            ->where('vehicle_id', $this->selectedVehicleId)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at', 'asc')
            ->with('device')
            ->get();

        if ($records->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('No telemetry records found to export for this date.'));
            return null;
        }

        $fileName = "telemetry_{$plate}_{$this->selectedDate}.csv";

        return response()->streamDownload(function () use ($records) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Ping #',
                'Recorded At',
                'Latitude',
                'Longitude',
                'Speed (km/h)',
                'Heading (deg)',
                'Altitude (m)',
                'Accuracy (m)',
                'Satellites',
                'Battery (%)',
                'Device ID',
            ]);

            foreach ($records as $index => $r) {
                fputcsv($handle, [
                    $index + 1,
                    $r->recorded_at?->toIso8601String() ?? '',
                    $r->latitude,
                    $r->longitude,
                    $r->speed ?? 0,
                    $r->heading ?? 0,
                    $r->altitude ?? '',
                    $r->accuracy ?? '',
                    $r->satellites ?? '',
                    $r->battery ?? '',
                    $r->device?->device_id ?? '',
                ]);
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }

    public function exportJson()
    {
        if (! $this->selectedVehicleId || empty($this->selectedDate)) {
            Flux::toast(variant: 'warning', text: __('Please select a vehicle and a recorded date to export.'));
            return null;
        }

        $vehicle = Vehicle::find($this->selectedVehicleId);
        $plate = $vehicle ? Str::slug($vehicle->plate_number) : 'vehicle';
        $date = Carbon::parse($this->selectedDate)->format('Y-m-d');

        $records = LocationHistory::query()
            ->where('vehicle_id', $this->selectedVehicleId)
            ->whereDate('recorded_at', $date)
            ->orderBy('recorded_at', 'asc')
            ->with('device')
            ->get();

        if ($records->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('No telemetry records found to export for this date.'));
            return null;
        }

        $fileName = "telemetry_{$plate}_{$this->selectedDate}.json";

        $data = [
            'vehicle' => [
                'id' => $vehicle?->id,
                'plate_number' => $vehicle?->plate_number,
                'name' => $vehicle?->name,
                'make' => $vehicle?->make,
                'model' => $vehicle?->model,
                'year' => $vehicle?->year,
            ],
            'query_date' => $this->selectedDate,
            'total_pings' => $records->count(),
            'exported_at' => now()->toIso8601String(),
            'records' => $records->map(fn ($r, $idx) => [
                'ping_number' => $idx + 1,
                'recorded_at' => $r->recorded_at?->toIso8601String(),
                'latitude' => (float) $r->latitude,
                'longitude' => (float) $r->longitude,
                'speed' => (float) ($r->speed ?? 0),
                'heading' => (float) ($r->heading ?? 0),
                'altitude' => $r->altitude !== null ? (float) $r->altitude : null,
                'accuracy' => $r->accuracy !== null ? (float) $r->accuracy : null,
                'satellites' => $r->satellites !== null ? (int) $r->satellites : null,
                'battery' => $r->battery !== null ? (float) $r->battery : null,
                'device_id' => $r->device?->device_id,
            ])->toArray(),
        ];

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $fileName, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Historical Journey Playback') }}</flux:heading>
            <flux:subheading>{{ __('Inspect historical GPS coordinates, movement route polylines, timestamps, and velocity logs.') }}</flux:subheading>
        </div>
    </div>

    <!-- Controls -->
    <div class="flex flex-col md:flex-row items-stretch md:items-end gap-4 p-4 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 shadow-xs">
        <div class="flex-1">
            <flux:select wire:model.live="selectedVehicleId" label="Select Vehicle">
                @foreach ($this->vehicles as $v)
                    <flux:select.option value="{{ $v->id }}">
                        {{ $v->plate_number }} — {{ $v->name }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="w-full md:w-80">
            <flux:select wire:model.live="selectedDate" label="Recorded Journey Date">
                @forelse ($this->availableDates as $item)
                    <flux:select.option value="{{ $item['date'] }}">
                        {{ $item['label'] }}
                    </flux:select.option>
                @empty
                    <flux:select.option value="" disabled>
                        No recorded telemetry dates
                    </flux:select.option>
                @endforelse
            </flux:select>
        </div>
        <div class="flex items-center gap-2 shrink-0 pb-0.5">
            <flux:button variant="subtle" icon="arrow-down-tray" wire:click="exportCsv" :disabled="empty($this->historyPoints)" title="Export telemetry points as CSV spreadsheet">
                {{ __('Export CSV') }}
            </flux:button>
            <flux:button variant="subtle" icon="code-bracket" wire:click="exportJson" :disabled="empty($this->historyPoints)" title="Export telemetry records as JSON">
                {{ __('Export JSON') }}
            </flux:button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6"
         x-data="{
            map: null,
            routeLayer: null,
            pingsLayer: null,
            highlightMarker: null,
            playbackMarker: null,
            selectedPing: null,
            isPlaying: false,
            playbackIndex: 0,
            playbackInterval: null,
            playbackSpeed: 1000,
            showAllPings: true,
            totalDistanceKm: 0,
            maxSpeed: 0,
            avgSpeed: 0,
            isFullScreen: false,
            points: @js($this->historyPoints),
            markerRefs: [],

            toggleFullScreen() {
                this.isFullScreen = !this.isFullScreen;
                this.$nextTick(() => {
                    setTimeout(() => {
                        if (this.map) {
                            this.map.invalidateSize();
                        }
                    }, 200);
                });
            },

            initMap() {
                this.$nextTick(() => {
                    const container = this.$refs.historyMapContainer;
                    if (!container) return;
                    if (this.map) {
                        this.map.remove();
                        this.map = null;
                    }

                    const LInstance = window.L;
                    if (!LInstance) return;

                    this.map = LInstance.map(container, {
                        zoomControl: true
                    }).setView([-15.786111, 35.005833], 13);

                    LInstance.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors | MicroMek History Tracking',
                        maxZoom: 19
                    }).addTo(this.map);

                    this.routeLayer = LInstance.layerGroup().addTo(this.map);
                    this.pingsLayer = LInstance.layerGroup().addTo(this.map);

                    this.drawRoute();

                    setTimeout(() => {
                        if (this.map) this.map.invalidateSize();
                    }, 300);
                });
            },

            calculateStats() {
                if (!this.points || this.points.length === 0) {
                    this.totalDistanceKm = 0;
                    this.maxSpeed = 0;
                    this.avgSpeed = 0;
                    return;
                }

                let totalDist = 0;
                let maxS = 0;
                let sumS = 0;

                for (let i = 0; i < this.points.length; i++) {
                    const speed = Number(this.points[i].speed) || 0;
                    if (speed > maxS) maxS = speed;
                    sumS += speed;

                    if (i > 0) {
                        const prev = this.points[i - 1];
                        const curr = this.points[i];
                        totalDist += this.haversine(prev.latitude, prev.longitude, curr.latitude, curr.longitude);
                    }
                }

                this.totalDistanceKm = totalDist.toFixed(2);
                this.maxSpeed = maxS;
                this.avgSpeed = (sumS / this.points.length).toFixed(1);
            },

            haversine(lat1, lon1, lat2, lon2) {
                const R = 6371;
                const dLat = (lat2 - lat1) * Math.PI / 180;
                const dLon = (lon2 - lon1) * Math.PI / 180;
                const a =
                    Math.sin(dLat/2) * Math.sin(dLat/2) +
                    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                    Math.sin(dLon/2) * Math.sin(dLon/2);
                const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                return R * c;
            },

            drawRoute() {
                if (!this.map || !this.routeLayer || !window.L) return;
                this.routeLayer.clearLayers();
                this.pingsLayer.clearLayers();
                this.stopPlayback();
                this.clearHighlight();

                this.calculateStats();
                this.markerRefs = [];

                if (!this.points || this.points.length === 0) {
                    this.selectedPing = null;
                    return;
                }

                const latLngs = this.points.map(p => [p.latitude, p.longitude]);

                // Continuous journey route line with nice styling
                const polyline = window.L.polyline(latLngs, {
                    color: '#059669',
                    weight: 4,
                    opacity: 0.85,
                    lineJoin: 'round',
                    smoothFactor: 1
                }).addTo(this.routeLayer);

                // Render each individual ping point with interactive click and inspection
                this.points.forEach((p, idx) => {
                    const isStart = idx === 0;
                    const isEnd = idx === this.points.length - 1;

                    let pinColor = '#10b981';
                    if (isStart) pinColor = '#2563eb';
                    else if (isEnd) pinColor = '#dc2626';

                    const dotIcon = window.L.divIcon({
                        html: `<div style='background-color: ${pinColor}; width: ${isStart || isEnd ? 16 : 10}px; height: ${isStart || isEnd ? 16 : 10}px; border-radius: 50%; border: 2px solid white; box-shadow: 0 1px 4px rgba(0,0,0,0.4); cursor: pointer;'></div>`,
                        className: 'ping-history-dot',
                        iconSize: [isStart || isEnd ? 16 : 10, isStart || isEnd ? 16 : 10],
                        iconAnchor: [isStart || isEnd ? 8 : 5, isStart || isEnd ? 8 : 5]
                    });

                    const pingMarker = window.L.marker([p.latitude, p.longitude], { icon: dotIcon })
                        .addTo(this.pingsLayer);

                    pingMarker.on('click', () => {
                        this.inspectPing(p, idx);
                    });

                    pingMarker.bindPopup(`
                        <div style='font-family: inherit; font-size: 12px; padding: 2px;'>
                            <strong style='font-size: 13px;'>Ping #${idx + 1}</strong><br>
                            <span>🕒 Time: ${p.recorded_at}</span><br>
                            <hr style='margin: 4px 0;'>
                            <b>Velocity:</b> ${p.speed} km/h<br>
                            <b>Heading:</b> ${p.heading}°<br>
                            <span style='font-family: monospace; font-size: 10px; color: #64748b;'>${p.latitude.toFixed(5)}, ${p.longitude.toFixed(5)}</span>
                        </div>
                    `);

                    this.markerRefs.push(pingMarker);
                });

                // Start Marker label
                window.L.marker(latLngs[0], { title: 'Start Location' })
                    .addTo(this.routeLayer)
                    .bindPopup(`<b>Journey Start:</b> ${this.points[0].recorded_at}`);

                // End Marker label
                window.L.marker(latLngs[latLngs.length - 1], { title: 'End Location' })
                    .addTo(this.routeLayer)
                    .bindPopup(`<b>Journey End:</b> ${this.points[this.points.length - 1].recorded_at}`);

                this.map.fitBounds(polyline.getBounds(), { padding: [50, 50], maxZoom: 16 });
            },

            inspectPing(p, index) {
                this.selectedPing = { ...p, index: index + 1 };
                this.playbackIndex = index;
                this.highlightPoint(p, index);

                if (this.map) {
                    this.map.panTo([p.latitude, p.longitude], { animate: true, duration: 0.6 });
                }
            },

            highlightPoint(p, index) {
                if (!this.map || !window.L) return;

                // Open the corresponding marker popup if available
                if (this.markerRefs && this.markerRefs[index]) {
                    this.markerRefs[index].openPopup();
                }

                // Create or move animated pulsating ring around selected ping
                if (this.highlightMarker) {
                    this.highlightMarker.setLatLng([p.latitude, p.longitude]);
                } else {
                    const highlightHtml = `
                        <div class='relative flex items-center justify-center' style='width: 36px; height: 36px;'>
                            <span class='absolute animate-ping inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75'></span>
                            <span class='relative inline-flex rounded-full h-5 w-5 bg-emerald-500 border-2 border-white shadow-lg'></span>
                        </div>
                    `;
                    const highlightIcon = window.L.divIcon({
                        html: highlightHtml,
                        className: 'highlight-active-ping',
                        iconSize: [36, 36],
                        iconAnchor: [18, 18]
                    });

                    this.highlightMarker = window.L.marker([p.latitude, p.longitude], {
                        icon: highlightIcon,
                        zIndexOffset: 1000
                    }).addTo(this.map);
                }
            },

            clearHighlight() {
                if (this.highlightMarker && this.map) {
                    this.map.removeLayer(this.highlightMarker);
                    this.highlightMarker = null;
                }
            },

            togglePlayback() {
                if (this.isPlaying) {
                    this.stopPlayback();
                } else {
                    this.startPlayback();
                }
            },

            startPlayback() {
                if (!this.points || this.points.length === 0) return;
                this.isPlaying = true;

                // If already at end or past end, restart from beginning
                if (this.playbackIndex >= this.points.length - 1) {
                    this.playbackIndex = 0;
                }

                // Immediately show the current step
                this.stepPlayback();

                if (this.playbackInterval) clearInterval(this.playbackInterval);
                this.playbackInterval = setInterval(() => {
                    this.playbackIndex++;
                    if (this.playbackIndex >= this.points.length) {
                        this.stopPlayback();
                        return;
                    }
                    this.stepPlayback();
                }, this.playbackSpeed);
            },

            stepPlayback() {
                const curr = this.points[this.playbackIndex];
                if (!curr) return;

                this.selectedPing = { ...curr, index: this.playbackIndex + 1 };
                this.updatePlaybackMarker(curr);
                this.highlightPoint(curr, this.playbackIndex);

                if (this.map) {
                    this.map.panTo([curr.latitude, curr.longitude], { animate: true, duration: 0.4 });
                }

                // Scroll the corresponding ping card into view in the sidebar
                this.$nextTick(() => {
                    const card = document.getElementById('ping-card-' + this.playbackIndex);
                    if (card) {
                        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });
            },

            seekPlayback(newIndex) {
                this.playbackIndex = Math.max(0, Math.min(newIndex, this.points.length - 1));
                this.stepPlayback();
            },

            stopPlayback() {
                this.isPlaying = false;
                if (this.playbackInterval) {
                    clearInterval(this.playbackInterval);
                    this.playbackInterval = null;
                }
            },

            updatePlaybackMarker(point) {
                if (!this.map || !window.L) return;

                const heading = point.heading || 0;
                const carHtml = `
                    <div style='transform: rotate(${heading}deg); transition: transform 0.3s ease; width: 34px; height: 34px; background: #059669; border-radius: 50%; border: 3px solid #ffffff; box-shadow: 0 4px 14px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; font-size: 16px;'>
                        🚗
                    </div>
                `;

                if (this.playbackMarker) {
                    this.playbackMarker.setLatLng([point.latitude, point.longitude]);
                    const newIcon = window.L.divIcon({
                        html: carHtml,
                        className: 'playback-active-car',
                        iconSize: [34, 34],
                        iconAnchor: [17, 17]
                    });
                    this.playbackMarker.setIcon(newIcon);
                } else {
                    const carIcon = window.L.divIcon({
                        html: carHtml,
                        className: 'playback-active-car',
                        iconSize: [34, 34],
                        iconAnchor: [17, 17]
                    });

                    this.playbackMarker = window.L.marker([point.latitude, point.longitude], {
                        icon: carIcon,
                        zIndexOffset: 1200
                    }).addTo(this.map);
                }
            }
         }"
         x-init="initMap()"
         @history-data-updated.window="points = $event.detail.points; drawRoute();"
         @historyDataUpdated.window="points = $event.detail.points; drawRoute();">

        <!-- Telemetry Logs Summary & Ping List -->
        <div class="lg:col-span-1 space-y-3">
            <div class="flex items-center justify-between">
                <span class="font-semibold text-xs text-zinc-500 uppercase tracking-wider">
                    Location Pings ({{ count($this->historyPoints) }})
                </span>
                <span class="text-[11px] font-mono text-emerald-600 dark:text-emerald-400 font-bold" x-text="totalDistanceKm + ' km total'"></span>
            </div>

            <!-- Playback Controls Bar with interactive Scrubber & Progress -->
            <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 block">Replay Journey</span>
                        <span class="text-[10px] text-zinc-400 font-mono" x-show="points && points.length > 0" x-text="'Ping ' + (playbackIndex + 1) + ' of ' + points.length"></span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button @click="playbackIndex = 0; stepPlayback();" 
                                type="button" 
                                title="Reset to Start"
                                class="p-1.5 rounded-lg text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                            ⏮
                        </button>
                        <button @click="togglePlayback()" 
                                type="button" 
                                class="px-3 py-1.5 rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow-xs"
                                :class="isPlaying ? 'bg-amber-600 text-white hover:bg-amber-700' : 'bg-emerald-600 text-white hover:bg-emerald-700'">
                            <span x-text="isPlaying ? '⏸ Pause' : '▶ Play'"></span>
                        </button>
                    </div>
                </div>

                <!-- Interactive Progress Scrubber -->
                <div x-show="points && points.length > 1" class="space-y-1">
                    <input type="range" 
                           min="0" 
                           :max="points ? points.length - 1 : 0" 
                           x-model.number="playbackIndex"
                           @input="stepPlayback()"
                           class="w-full h-1.5 bg-zinc-200 dark:bg-zinc-700 rounded-lg appearance-none cursor-pointer accent-emerald-600" />
                </div>

                <div class="flex items-center justify-between pt-1 text-[11px] text-zinc-500">
                    <div class="flex items-center gap-2">
                        <span>Speed:</span>
                        <button @click="playbackSpeed = 2000; if(isPlaying){ stopPlayback(); startPlayback(); }" :class="playbackSpeed === 2000 ? 'font-bold text-emerald-600' : ''">0.5x</button>
                        <span>•</span>
                        <button @click="playbackSpeed = 1000; if(isPlaying){ stopPlayback(); startPlayback(); }" :class="playbackSpeed === 1000 ? 'font-bold text-emerald-600' : ''">1x</button>
                        <span>•</span>
                        <button @click="playbackSpeed = 400; if(isPlaying){ stopPlayback(); startPlayback(); }" :class="playbackSpeed === 400 ? 'font-bold text-emerald-600' : ''">2.5x</button>
                    </div>
                </div>
            </div>

            <!-- Scrollable Ping Cards -->
            <div class="space-y-2 max-h-[520px] overflow-y-auto pr-1">
                @forelse ($this->historyPoints as $index => $pt)
                    <div id="ping-card-{{ $index }}"
                         @click="inspectPing({{ json_encode($pt) }}, {{ $index }})"
                         class="p-2.5 rounded-lg border cursor-pointer text-xs space-y-1 transition select-none"
                         :class="(selectedPing && selectedPing.index === {{ $index + 1 }}) ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-950/50 shadow-xs ring-1 ring-emerald-400' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 hover:border-zinc-300 dark:hover:border-zinc-600'">
                        <div class="flex justify-between font-mono font-medium">
                            <span class="text-zinc-900 dark:text-zinc-100 font-bold">Ping #{{ $index + 1 }} • {{ $pt['recorded_at'] }}</span>
                            <span class="text-emerald-600 dark:text-emerald-400 font-semibold">{{ $pt['speed'] }} km/h</span>
                        </div>
                        <div class="flex items-center justify-between text-zinc-500 font-mono text-[11px]">
                            <span>{{ number_format($pt['latitude'], 4) }}, {{ number_format($pt['longitude'], 4) }}</span>
                            <span>{{ $pt['heading'] }}°</span>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-center text-xs text-zinc-500 rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700">
                        @if (! empty($selectedDate))
                            {{ __('No recorded telemetry points found for this vehicle on :date.', ['date' => \Illuminate\Support\Carbon::parse($selectedDate)->format('M d, Y')]) }}
                        @else
                            {{ __('No recorded telemetry dates available for this vehicle.') }}
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Leaflet Polyline Route Map & Summary Card Overlay -->
        <div class="space-y-4 transition-all"
             :class="isFullScreen ? 'fixed inset-0 z-[9999] bg-white dark:bg-zinc-950 p-2 sm:p-4 h-screen w-screen space-y-0' : 'lg:col-span-3'">
            <div class="rounded-2xl border border-zinc-200 dark:border-zinc-700 overflow-hidden shadow-sm bg-zinc-100 dark:bg-zinc-900 relative transition-all"
                 :class="isFullScreen ? 'h-full w-full rounded-xl border-0 shadow-none' : ''">
                <!-- Map Div -->
                <div x-ref="historyMapContainer" wire:ignore 
                     class="w-full transition-all"
                     :style="isFullScreen ? 'height: 100%; width: 100%; z-index: 1;' : 'height: 640px; width: 100%; z-index: 1;'"></div>

                <!-- Floating Journey Statistics Strip (Top Left) -->
                <div class="absolute top-4 left-14 z-[400] flex items-center gap-2 flex-wrap">
                    <div class="px-3 py-1.5 rounded-lg bg-white/90 dark:bg-zinc-800/90 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 flex items-center gap-1.5">
                        <span>📏 Total:</span>
                        <span class="font-mono text-emerald-600 dark:text-emerald-400" x-text="totalDistanceKm + ' km'"></span>
                    </div>
                    <div class="px-3 py-1.5 rounded-lg bg-white/90 dark:bg-zinc-800/90 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 flex items-center gap-1.5">
                        <span>⚡ Max Speed:</span>
                        <span class="font-mono text-emerald-600 dark:text-emerald-400" x-text="maxSpeed + ' km/h'"></span>
                    </div>
                    <div class="px-3 py-1.5 rounded-lg bg-white/90 dark:bg-zinc-800/90 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 flex items-center gap-1.5">
                        <span>⏱ Avg Speed:</span>
                        <span class="font-mono text-emerald-600 dark:text-emerald-400" x-text="avgSpeed + ' km/h'"></span>
                    </div>
                    <button @click="toggleFullScreen()" 
                            type="button"
                            class="px-3 py-1.5 rounded-lg bg-white/90 dark:bg-zinc-800/90 backdrop-blur-md shadow-md border border-zinc-200 dark:border-zinc-700 text-xs font-semibold text-zinc-800 dark:text-zinc-200 hover:bg-white flex items-center gap-1.5"
                            :title="isFullScreen ? 'Exit Full Screen' : 'Enter Full Screen'">
                        <span x-text="isFullScreen ? '🗗 Exit Fullscreen' : '⛶ Fullscreen'"></span>
                    </button>
                </div>

                <!-- Floating Active Ping Detail Card (Top Right) -->
                <div x-show="selectedPing" 
                     x-transition 
                     class="absolute top-4 right-4 z-[500] max-w-xs w-full bg-white/95 dark:bg-zinc-900/95 backdrop-blur-md p-4 rounded-2xl shadow-xl border border-zinc-200/80 dark:border-zinc-700/80 space-y-3"
                     x-cloak>
                    <div class="flex items-center justify-between pb-2 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center gap-1.5">
                            <span class="size-2 rounded-full bg-emerald-500"></span>
                            <span class="font-bold text-sm text-zinc-900 dark:text-zinc-100 font-mono" x-text="'Ping #' + (selectedPing ? selectedPing.index : '')"></span>
                        </div>
                        <button @click="selectedPing = null" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 text-sm">
                            ✕
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="p-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-800">
                            <span class="text-zinc-400 text-[10px] uppercase font-bold block">Timestamp</span>
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100" x-text="selectedPing ? selectedPing.recorded_at : ''"></span>
                        </div>
                        <div class="p-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-800">
                            <span class="text-zinc-400 text-[10px] uppercase font-bold block">Recorded Speed</span>
                            <span class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="(selectedPing ? selectedPing.speed : 0) + ' km/h'"></span>
                        </div>
                    </div>

                    <div class="p-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-100 dark:border-zinc-800 text-xs">
                        <span class="text-zinc-400 text-[10px] uppercase font-bold block">Coordinates & Heading</span>
                        <span class="font-mono text-zinc-800 dark:text-zinc-200 text-[11px]" x-text="selectedPing ? (selectedPing.latitude.toFixed(5) + ', ' + selectedPing.longitude.toFixed(5) + ' (' + selectedPing.heading + '°)') : ''"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>