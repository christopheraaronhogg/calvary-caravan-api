<script lang="ts">
  import { onMount } from 'svelte';
  import 'leaflet/dist/leaflet.css';
  import { api } from '$lib/api';

  type Tab = 'map' | 'waypoints' | 'chat' | 'alert' | 'profile';
  type ComposerMode = 'chat' | 'prayer';
  type JoinMode = 'join' | 'signin';
  type ParticipantStripFilter = 'all' | 'leaders';

  type PlaceLabel = {
    label: string;
    name: string;
    relation: 'at' | 'near';
    distance_m: number | null;
    confidence: 'high' | 'medium' | 'low';
    source: 'nominatim';
  };

  type JoinResponse = {
    data: {
      participant_id: number;
      device_token: string;
      retreat: {
        id: number;
        name: string;
        destination: { name: string; lat: number; lng: number } | null;
        starts_at: string;
        ends_at: string;
      };
    };
  };

  type RetreatStatusResponse = {
    data: {
      participant: {
        id: number;
        name: string;
        phone_display: string | null;
        is_leader: boolean;
        location_sharing_enabled: boolean;
        avatar_url: string | null;
      };
      retreat: {
        id: number;
        name: string;
        destination: { name: string; lat: number; lng: number } | null;
        starts_at: string;
        ends_at: string;
        participant_count: number;
      };
    };
  };

  type RetreatLocation = {
    lat: number;
    lng: number;
    accuracy: number | null;
    speed: number | null;
    heading: number | null;
    recorded_at: string;
    place?: PlaceLabel | null;
  };

  type ParticipantLocationRow = {
    participant_id: number;
    name: string;
    gender: string | null;
    avatar_url: string | null;
    vehicle_color: string | null;
    vehicle_description: string | null;
    is_leader: boolean;
    is_current_user: boolean;
    location_sharing_enabled: boolean;
    location: RetreatLocation | null;
    last_seen_seconds_ago: number | null;
  };

  type LocationsResponse = {
    data: ParticipantLocationRow[];
    meta: {
      total_participants: number;
      online_count: number;
      server_time: string;
    };
  };

  type StopPhase = 'moving' | 'candidate' | 'stopped' | 'offline';

  type StopTracker = {
    participant_id: number;
    phase: Exclude<StopPhase, 'offline'>;
    anchor_lat: number;
    anchor_lng: number;
    anchor_place_label: string;
    started_at_ms: number;
    last_recorded_at_ms: number;
  };

  type ParticipantStopInsight = {
    participant_id: number;
    phase: StopPhase;
    place_label: string;
    place_phrase: string;
    stopped_for_seconds: number;
    candidate_for_seconds: number;
    started_at_iso: string | null;
  };

  type StopEvent = {
    id: number;
    participant_id: number;
    kind: 'stopped' | 'moving';
    text: string;
    created_at: string;
  };

  type WaypointRow = {
    id: number;
    name: string;
    description: string | null;
    lat: number;
    lng: number;
    order: number;
    eta: string | null;
  };

  type WaypointsResponse = { data: WaypointRow[] };

  type MessageRow = {
    id: number;
    message_type: string;
    content: string;
    sender: {
      id: number;
      name: string;
      is_leader: boolean;
      gender: string | null;
      avatar_url: string | null;
    };
    location: {
      lat: number;
      lng: number;
    } | null;
    created_at: string;
  };

  type MessagesResponse = {
    data: MessageRow[];
    meta: {
      latest_id: number | null;
      count: number;
    };
  };

  type QueueItem = {
    id: string;
    content: string;
    mode: ComposerMode;
    createdAt: number;
  };

  const TOKEN_KEY = 'caravan_device_token';

  let appReady = false;
  let inRetreat = false;
  let joining = false;
  let loadingData = false;
  let refreshing = false;
  let leaving = false;
  let deleteAccountBusy = false;
  let uploadBusy = false;

  let activeTab: Tab = 'map';
  let composerMode: ComposerMode = 'chat';
  let online = true;

  let joinMode: JoinMode = 'join';
  let joinCode = '';
  let joinName = '';
  let joinPhoneNumber = '';
  let joinVehicleColor = '';
  let joinVehicleDescription = '';

  let deviceToken = '';
  let myParticipant: RetreatStatusResponse['data']['participant'] | null = null;
  let retreatInfo: RetreatStatusResponse['data']['retreat'] | null = null;

  let participants: ParticipantLocationRow[] = [];
  let waypoints: WaypointRow[] = [];
  let messages: MessageRow[] = [];
  let queuedMessages: QueueItem[] = [];

  let chatDraft = '';
  let alertDraft = '';
  let alertSeverity: 'low' | 'medium' | 'high' | 'critical' = 'high';
  let showAlertConfirm = false;

  let selectedParticipant: ParticipantLocationRow | null = null;
  let focusedParticipantId: number | null = null;
  let participantStripFilter: ParticipantStripFilter = 'all';
  let participantRowElement: HTMLDivElement | null = null;

  let profileVehicleColor = '';
  let profileVehicleDescription = '';

  let errorMessage = '';
  let statusMessage = '';
  let queueStatus = '';

  let refreshTimer: ReturnType<typeof setInterval> | null = null;
  let locationWatchId: number | null = null;
  let locationErrorShown = false;
  let locationPostBusy = false;
  let locationSharingEnabled = true;
  let locationSharingBusy = false;
  let lastLocationPostedAt = 0;

  let mapElement: HTMLDivElement | null = null;
  let mapLibrary: any = null;
  let mapInstance: any = null;
  let mapLayer: any = null;
  let mapAutoFramed = false;
  let previousLocationCount = 0;

  let latestLocationsServerTimeIso: string | null = null;
  let stopTrackersById: Record<number, StopTracker> = {};
  let stopInsightsById: Record<number, ParticipantStopInsight> = {};
  let stopEvents: StopEvent[] = [];
  let nextStopEventId = 0;

  const STOP_DETECTION_RADIUS_METERS = 130;
  const STOP_MIN_SECONDS = 6 * 60;
  const MOVING_SPEED_MPS = 2.2;
  const STOP_EVENT_MAX = 8;
  const STOP_OFFLINE_SECONDS = 10 * 60;

  const inAppTabs: Array<{ id: Tab; label: string; icon: string }> = [
    { id: 'map', label: 'Map', icon: '🗺️' },
    { id: 'waypoints', label: 'Plan', icon: '📍' },
    { id: 'chat', label: 'Chat', icon: '💬' },
    { id: 'alert', label: 'Alert', icon: '🚨' },
    { id: 'profile', label: 'Profile', icon: '👤' }
  ];

  $: onlineCount = participants.filter((p) => (p.last_seen_seconds_ago ?? 9999) < 300).length;
  $: mapRows = participantLocationRows(participants);
  $: participantStripRows = participantStripFilter === 'leaders'
    ? participants.filter((p) => p.is_leader)
    : participants;
  $: canSendAlert = myParticipant?.is_leader === true;
  $: queuedCount = queuedMessages.length;
  $: stoppedParticipantRows = participants
    .map((row) => {
      const insight = stopInsightsById[row.participant_id];
      if (!insight || insight.phase !== 'stopped') return null;
      return {
        row,
        insight,
      };
    })
    .filter((item): item is { row: ParticipantLocationRow; insight: ParticipantStopInsight } => item !== null)
    .sort((a, b) => b.insight.stopped_for_seconds - a.insight.stopped_for_seconds);
  $: movingParticipantCount = participants.filter((row) => {
    const insight = stopInsightsById[row.participant_id];
    return insight && (insight.phase === 'moving' || insight.phase === 'candidate');
  }).length;
  $: recentStopEvents = stopEvents.slice(0, 4);

  $: if (typeof document !== 'undefined') {
    document.body.classList.add('theme-neo');
  }

  $: if (inRetreat && activeTab === 'map' && mapElement) {
    void ensureMapReady();
  }

  $: if (mapInstance && inRetreat && activeTab === 'map') {
    // Keep map markers in sync with live participant refreshes.
    // (Svelte only tracks vars referenced directly in this block.)
    mapRows;
    stopInsightsById;
    retreatInfo?.destination?.lat;
    retreatInfo?.destination?.lng;
    renderLiveMap();
  }

  $: if (inRetreat && deviceToken && locationSharingEnabled) {
    startLocationWatch();
  } else {
    stopLocationWatch();
  }

  function normalizeCode(code: string): string {
    return code.trim().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
  }

  function formatTime(iso: string | null | undefined): string {
    if (!iso) return 'TBD';
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return 'TBD';
    return dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function formatAgo(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return 'offline';
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    return `${Math.floor(seconds / 3600)}h ago`;
  }

  function formatDistanceMeters(distance: number | null | undefined): string {
    if (distance === null || distance === undefined || !Number.isFinite(distance)) return '';
    if (distance < 1000) return `${Math.round(distance)}m`;
    return `${(distance / 1000).toFixed(1)}km`;
  }

  function formatDurationCompact(totalSeconds: number | null | undefined): string {
    if (totalSeconds === null || totalSeconds === undefined || !Number.isFinite(totalSeconds)) return '0m';
    const seconds = Math.max(0, Math.floor(totalSeconds));
    if (seconds < 60) return `${seconds}s`;
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (!hours) return `${minutes}m`;
    if (!minutes) return `${hours}h`;
    return `${hours}h ${minutes}m`;
  }

  function formatDurationWords(totalSeconds: number | null | undefined): string {
    if (totalSeconds === null || totalSeconds === undefined || !Number.isFinite(totalSeconds)) return '0 minutes';
    const seconds = Math.max(0, Math.floor(totalSeconds));
    if (seconds < 60) return `${seconds} second${seconds === 1 ? '' : 's'}`;
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (!hours) return `${minutes} minute${minutes === 1 ? '' : 's'}`;
    if (!minutes) return `${hours} hour${hours === 1 ? '' : 's'}`;
    return `${hours} hour${hours === 1 ? '' : 's'} ${minutes} minute${minutes === 1 ? '' : 's'}`;
  }

  function parseIsoMs(iso: string | null | undefined): number | null {
    if (!iso) return null;
    const value = Date.parse(iso);
    return Number.isFinite(value) ? value : null;
  }

  function distanceBetweenMeters(aLat: number, aLng: number, bLat: number, bLng: number): number {
    const toRad = (deg: number) => (deg * Math.PI) / 180;
    const dLat = toRad(bLat - aLat);
    const dLng = toRad(bLng - aLng);
    const lat1 = toRad(aLat);
    const lat2 = toRad(bLat);

    const a = Math.sin(dLat / 2) ** 2
      + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;

    return 6371000 * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
  }

  function cleanPlaceLabel(label: string | null | undefined): string {
    if (!label) return '';
    return label.trim().replace(/^(at|near)\s+/i, '').trim();
  }

  function participantPlaceLabel(row: ParticipantLocationRow): string {
    const placeLabel = cleanPlaceLabel(row.location?.place?.label ?? null);
    if (placeLabel) return placeLabel;

    if (hasValidCoords(row.location?.lat, row.location?.lng)) {
      return `${Number(row.location!.lat).toFixed(3)}, ${Number(row.location!.lng).toFixed(3)}`;
    }

    return 'unknown location';
  }

  function participantPlacePhrase(row: ParticipantLocationRow): string {
    const placeLabel = participantPlaceLabel(row);
    const relation = row.location?.place?.relation;
    if (relation === 'near') return `near ${placeLabel}`;
    return `at ${placeLabel}`;
  }

  function participantNearLabel(row: ParticipantLocationRow): string {
    const label = row.location?.place?.label?.trim();
    if (label) return label;

    if (hasValidCoords(row.location?.lat, row.location?.lng)) {
      return `Near ${Number(row.location!.lat).toFixed(3)}, ${Number(row.location!.lng).toFixed(3)}`;
    }

    return 'Location unavailable';
  }

  function escapeHtml(value: string): string {
    return value
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function hasValidCoords(lat: number | null | undefined, lng: number | null | undefined): boolean {
    if (lat === null || lat === undefined || lng === null || lng === undefined) return false;
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return false;
    return !(lat === 0 && lng === 0);
  }

  function destinationCoords(): { lat: number; lng: number } | null {
    const lat = retreatInfo?.destination?.lat;
    const lng = retreatInfo?.destination?.lng;
    if (!hasValidCoords(lat, lng)) return null;
    return { lat: Number(lat), lng: Number(lng) };
  }

  function participantLocationRows(rows: ParticipantLocationRow[]): ParticipantLocationRow[] {
    return rows.filter((row) => hasValidCoords(row.location?.lat, row.location?.lng));
  }

  function stopInsightForParticipant(participantId: number): ParticipantStopInsight | null {
    return stopInsightsById[participantId] ?? null;
  }

  function stopBadgeForRow(row: ParticipantLocationRow): string | null {
    const insight = stopInsightForParticipant(row.participant_id);
    if (!insight) return null;
    if (insight.phase === 'stopped') return `🛑 ${formatDurationCompact(insight.stopped_for_seconds)}`;
    if (insight.phase === 'candidate' && insight.candidate_for_seconds >= 90) {
      return `⏳ ${formatDurationCompact(insight.candidate_for_seconds)}`;
    }
    return null;
  }

  function syncStopIntelligence(rows: ParticipantLocationRow[], serverTimeIso: string | null): void {
    const observedAtMs = parseIsoMs(serverTimeIso) ?? Date.now();
    const nextTrackers: Record<number, StopTracker> = {};
    const nextInsights: Record<number, ParticipantStopInsight> = {};
    const emittedEvents: StopEvent[] = [];

    for (const row of rows) {
      const id = row.participant_id;
      const location = row.location;

      if (!location || !hasValidCoords(location.lat, location.lng)) {
        nextInsights[id] = {
          participant_id: id,
          phase: 'offline',
          place_label: 'Location unavailable',
          place_phrase: 'at an unknown location',
          stopped_for_seconds: 0,
          candidate_for_seconds: 0,
          started_at_iso: null,
        };
        continue;
      }

      const lat = Number(location.lat);
      const lng = Number(location.lng);
      const recordedAtMs = parseIsoMs(location.recorded_at) ?? observedAtMs;
      const speedMps = location.speed ?? null;
      const stale = (row.last_seen_seconds_ago ?? 0) >= STOP_OFFLINE_SECONDS;

      const placeLabel = participantPlaceLabel(row);
      const placePhrase = participantPlacePhrase(row);

      const previous = stopTrackersById[id];
      let tracker: StopTracker = previous
        ? { ...previous }
        : {
            participant_id: id,
            phase: 'moving',
            anchor_lat: lat,
            anchor_lng: lng,
            anchor_place_label: placeLabel,
            started_at_ms: recordedAtMs,
            last_recorded_at_ms: recordedAtMs,
          };

      let candidateForSeconds = 0;
      let stoppedForSeconds = 0;
      let insightPhase: StopPhase = 'moving';

      if (stale) {
        insightPhase = 'offline';
      } else {
        const driftMeters = distanceBetweenMeters(tracker.anchor_lat, tracker.anchor_lng, lat, lng);
        const movingByDistance = driftMeters > STOP_DETECTION_RADIUS_METERS;
        const movingBySpeed = speedMps !== null && Number.isFinite(speedMps) && speedMps > MOVING_SPEED_MPS;

        if (movingByDistance || movingBySpeed) {
          if (tracker.phase === 'stopped') {
            const stoppedDuration = Math.max(0, Math.floor((recordedAtMs - tracker.started_at_ms) / 1000));
            const resumePlace = tracker.anchor_place_label ? ` near ${tracker.anchor_place_label}` : '';
            emittedEvents.push({
              id: ++nextStopEventId,
              participant_id: id,
              kind: 'moving',
              text: `${row.name} started moving again after ${formatDurationWords(stoppedDuration)}${resumePlace}.`,
              created_at: new Date(observedAtMs).toISOString(),
            });
          }

          tracker = {
            participant_id: id,
            phase: 'moving',
            anchor_lat: lat,
            anchor_lng: lng,
            anchor_place_label: placeLabel,
            started_at_ms: recordedAtMs,
            last_recorded_at_ms: recordedAtMs,
          };

          insightPhase = 'moving';
        } else {
          if (tracker.phase === 'moving') {
            tracker.phase = 'candidate';
            tracker.started_at_ms = tracker.last_recorded_at_ms;
          }

          tracker.last_recorded_at_ms = recordedAtMs;
          tracker.anchor_place_label = placeLabel;

          candidateForSeconds = Math.max(0, Math.floor((recordedAtMs - tracker.started_at_ms) / 1000));

          if (candidateForSeconds >= STOP_MIN_SECONDS) {
            if (tracker.phase !== 'stopped') {
              tracker.phase = 'stopped';
              emittedEvents.push({
                id: ++nextStopEventId,
                participant_id: id,
                kind: 'stopped',
                text: `${row.name} looks stopped ${placePhrase}.`,
                created_at: new Date(observedAtMs).toISOString(),
              });
            }

            insightPhase = 'stopped';
            stoppedForSeconds = Math.max(0, Math.floor((Math.max(observedAtMs, recordedAtMs) - tracker.started_at_ms) / 1000));
          } else {
            insightPhase = 'candidate';
          }
        }
      }

      nextTrackers[id] = tracker;

      nextInsights[id] = {
        participant_id: id,
        phase: insightPhase,
        place_label: placeLabel,
        place_phrase: placePhrase,
        stopped_for_seconds: stoppedForSeconds,
        candidate_for_seconds: candidateForSeconds,
        started_at_iso: new Date(tracker.started_at_ms).toISOString(),
      };
    }

    stopTrackersById = nextTrackers;
    stopInsightsById = nextInsights;

    if (emittedEvents.length > 0) {
      stopEvents = [...emittedEvents.reverse(), ...stopEvents].slice(0, STOP_EVENT_MAX);
    }
  }

  async function ensureMapReady(): Promise<void> {
    if (typeof window === 'undefined') return;
    if (!inRetreat || activeTab !== 'map') return;
    if (!mapElement) return;

    if (!mapLibrary) {
      const imported = await import('leaflet');
      mapLibrary = imported.default ?? imported;
    }

    // Switching tabs unmounts/remounts the map div, so Leaflet can hold a stale container.
    // If that happens, rebuild the map instance against the current element.
    if (mapInstance && typeof mapInstance.getContainer === 'function') {
      const existingContainer = mapInstance.getContainer();
      if (!existingContainer || existingContainer !== mapElement) {
        mapInstance.remove();
        mapInstance = null;
        mapLayer = null;
        mapAutoFramed = false;
        previousLocationCount = 0;
      }
    }

    if (!mapInstance) {
      mapInstance = mapLibrary.map(mapElement, {
        zoomControl: true,
        attributionControl: false
      });

      mapLibrary
        .tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        })
        .addTo(mapInstance);

      mapLayer = mapLibrary.layerGroup().addTo(mapInstance);
      mapAutoFramed = false;
      previousLocationCount = 0;
    }

    renderLiveMap();

    window.requestAnimationFrame(() => {
      mapInstance?.invalidateSize();
    });
  }

  function renderLiveMap(): void {
    if (!mapInstance || !mapLayer || !mapLibrary) return;

    mapLayer.clearLayers();

    const locationRows = mapRows;
    const dest = destinationCoords();
    const points: Array<[number, number]> = [];

    for (const row of locationRows) {
      const lat = Number(row.location!.lat);
      const lng = Number(row.location!.lng);

      const stopInsight = stopInsightForParticipant(row.participant_id);
      const isStopped = stopInsight?.phase === 'stopped';
      const isCandidate = stopInsight?.phase === 'candidate';

      const baseColor = row.is_current_user
        ? '#1f9e59'
        : row.is_leader
          ? '#8f0030'
          : '#2458c6';

      const color = isStopped ? '#cc1f2f' : baseColor;

      const marker = mapLibrary.circleMarker([lat, lng], {
        radius: isStopped ? 10 : row.is_current_user ? 9 : 7,
        color,
        weight: isStopped ? 3 : row.is_current_user ? 3 : 2,
        fillColor: color,
        fillOpacity: isStopped ? 0.96 : 0.9
      });

      if (isStopped) {
        mapLibrary.circle([lat, lng], {
          radius: STOP_DETECTION_RADIUS_METERS,
          color: '#cc1f2f',
          weight: 1,
          dashArray: '5 4',
          fillColor: '#ff8893',
          fillOpacity: 0.08
        }).addTo(mapLayer);
      }

      const placeLabel = participantNearLabel(row);
      const stopLine = isStopped
        ? `<br>🛑 Stopped ${escapeHtml(formatDurationCompact(stopInsight?.stopped_for_seconds))}`
        : isCandidate && stopInsight
          ? `<br>⏳ Holding ${escapeHtml(formatDurationCompact(stopInsight.candidate_for_seconds))}`
          : '';

      marker.bindPopup(
        `<strong>${escapeHtml(row.name)}</strong><br>${escapeHtml(placeLabel)}<br>${escapeHtml(formatAgo(row.last_seen_seconds_ago))}${stopLine}`
      );
      marker.addTo(mapLayer);
      points.push([lat, lng]);
    }

    if (dest) {
      const destinationMarker = mapLibrary.circleMarker([dest.lat, dest.lng], {
        radius: 8,
        color: '#b36a00',
        weight: 2,
        fillColor: '#f59e0b',
        fillOpacity: 0.85
      });

      destinationMarker.bindPopup(
        `<strong>Destination</strong><br>${retreatInfo?.destination?.name ?? 'Retreat destination'}`
      );
      destinationMarker.addTo(mapLayer);
      points.push([dest.lat, dest.lng]);
    }

    const hadNoLocationsBefore = previousLocationCount === 0;
    previousLocationCount = locationRows.length;

    if (!points.length) {
      if (!mapAutoFramed) {
        mapInstance.setView([35.1495, -90.049], 5);
        mapAutoFramed = true;
      }
      return;
    }

    if (points.length === 1) {
      if (!mapAutoFramed || hadNoLocationsBefore) {
        mapInstance.setView(points[0], 11);
        mapAutoFramed = true;
      }
      return;
    }

    if (!mapAutoFramed || hadNoLocationsBefore) {
      mapInstance.fitBounds(points, {
        padding: [32, 32],
        maxZoom: 12
      });
      mapAutoFramed = true;
    }
  }

  async function postCurrentLocation(position: GeolocationPosition): Promise<void> {
    if (!deviceToken || !online || locationPostBusy || !locationSharingEnabled) return;

    const nowMs = Date.now();
    if (nowMs - lastLocationPostedAt < 25000) return;

    locationPostBusy = true;

    try {
      const coords = position.coords;
      await api('/location', {
        method: 'POST',
        body: JSON.stringify({
          latitude: coords.latitude,
          longitude: coords.longitude,
          accuracy: Number.isFinite(coords.accuracy) ? coords.accuracy : null,
          speed: coords.speed !== null && Number.isFinite(coords.speed) && coords.speed >= 0 ? coords.speed : null,
          heading: coords.heading !== null && Number.isFinite(coords.heading) ? coords.heading : null,
          altitude: coords.altitude !== null && Number.isFinite(coords.altitude) ? coords.altitude : null,
          recorded_at: new Date(position.timestamp).toISOString()
        })
      }, deviceToken);

      lastLocationPostedAt = nowMs;

      participants = participants.map((row) => {
        if (!row.is_current_user) return row;
        return {
          ...row,
          location: {
            lat: coords.latitude,
            lng: coords.longitude,
            accuracy: Number.isFinite(coords.accuracy) ? coords.accuracy : null,
            speed: coords.speed !== null && Number.isFinite(coords.speed) && coords.speed >= 0 ? coords.speed : null,
            heading: coords.heading !== null && Number.isFinite(coords.heading) ? coords.heading : null,
            recorded_at: new Date(position.timestamp).toISOString()
          },
          last_seen_seconds_ago: 0
        };
      });
    } catch {
      // location posting failures should not spam blocking toasts
    } finally {
      locationPostBusy = false;
    }
  }

  function stopLocationWatch(): void {
    if (typeof navigator === 'undefined') return;
    if (!navigator.geolocation) return;
    if (locationWatchId === null) return;

    navigator.geolocation.clearWatch(locationWatchId);
    locationWatchId = null;
  }

  function startLocationWatch(): void {
    if (typeof navigator === 'undefined') return;
    if (!navigator.geolocation) return;
    if (!inRetreat || !deviceToken || !locationSharingEnabled) return;
    if (locationWatchId !== null) return;

    locationWatchId = navigator.geolocation.watchPosition(
      (position) => {
        void postCurrentLocation(position);
      },
      (error) => {
        if (error.code === error.PERMISSION_DENIED && !locationErrorShown) {
          setError('Location permission is disabled. Enable it to share live map markers.');
          locationErrorShown = true;
        }
      },
      {
        enableHighAccuracy: true,
        maximumAge: 15000,
        timeout: 15000
      }
    );
  }

  function showStatus(message: string): void {
    statusMessage = message;
    setTimeout(() => {
      if (statusMessage === message) statusMessage = '';
    }, 2600);
  }

  function setError(message: string): void {
    errorMessage = message;
    setTimeout(() => {
      if (errorMessage === message) errorMessage = '';
    }, 4200);
  }

  async function joinRetreat(): Promise<void> {
    joining = true;
    errorMessage = '';

    try {
      const payload = await api<JoinResponse>('/join', {
        method: 'POST',
        body: JSON.stringify({
          auth_mode: joinMode,
          code: normalizeCode(joinCode),
          name: joinMode === 'join' ? joinName.trim() : undefined,
          phone_number: joinPhoneNumber.trim(),
          vehicle_color: joinMode === 'join' ? (joinVehicleColor.trim() || null) : undefined,
          vehicle_description: joinMode === 'join' ? (joinVehicleDescription.trim() || null) : undefined
        })
      });

      deviceToken = payload.data.device_token;
      localStorage.setItem(TOKEN_KEY, deviceToken);

      await hydrateFromToken(deviceToken);
      showStatus('Joined convoy successfully.');
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to join retreat.');
    } finally {
      joining = false;
    }
  }

  async function hydrateFromToken(token: string): Promise<void> {
    loadingData = true;

    try {
      const [statusPayload, locationsPayload, waypointsPayload, messagesPayload] = await Promise.all([
        api<RetreatStatusResponse>('/status', {}, token),
        api<LocationsResponse>('/locations', {}, token),
        api<WaypointsResponse>('/waypoints', {}, token),
        api<MessagesResponse>('/messages?limit=100', {}, token)
      ]);

      myParticipant = statusPayload.data.participant;
      retreatInfo = statusPayload.data.retreat;

      participants = locationsPayload.data;
      latestLocationsServerTimeIso = locationsPayload.meta.server_time ?? new Date().toISOString();
      syncStopIntelligence(participants, latestLocationsServerTimeIso);
      waypoints = [...waypointsPayload.data].sort((a, b) => a.order - b.order);
      messages = messagesPayload.data;

      const me = participants.find((p) => p.is_current_user);
      locationSharingEnabled = me?.location_sharing_enabled ?? statusPayload.data.participant.location_sharing_enabled ?? true;
      if (me) {
        profileVehicleColor = me.vehicle_color ?? profileVehicleColor;
        profileVehicleDescription = me.vehicle_description ?? profileVehicleDescription;
      }

      inRetreat = true;
      mapAutoFramed = false;
      previousLocationCount = 0;
      appReady = true;
    } finally {
      loadingData = false;
    }
  }

  async function refreshData(): Promise<void> {
    if (!deviceToken || !inRetreat) return;

    refreshing = true;
    try {
      const [locationsPayload, waypointsPayload, messagesPayload] = await Promise.all([
        api<LocationsResponse>('/locations', {}, deviceToken),
        api<WaypointsResponse>('/waypoints', {}, deviceToken),
        api<MessagesResponse>('/messages?limit=100', {}, deviceToken)
      ]);

      participants = locationsPayload.data;
      latestLocationsServerTimeIso = locationsPayload.meta.server_time ?? new Date().toISOString();
      syncStopIntelligence(participants, latestLocationsServerTimeIso);
      waypoints = [...waypointsPayload.data].sort((a, b) => a.order - b.order);
      messages = messagesPayload.data;

      const me = participants.find((p) => p.is_current_user);
      if (me) {
        locationSharingEnabled = me.location_sharing_enabled ?? locationSharingEnabled;
        profileVehicleColor = me.vehicle_color ?? profileVehicleColor;
        profileVehicleDescription = me.vehicle_description ?? profileVehicleDescription;
      }
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to refresh retreat data.');
    } finally {
      refreshing = false;
    }
  }

  function queueCurrentMessage(content: string, mode: ComposerMode): void {
    queuedMessages = [
      ...queuedMessages,
      {
        id: `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`,
        content,
        mode,
        createdAt: Date.now()
      }
    ];
    queueStatus = `${queuedMessages.length} message${queuedMessages.length === 1 ? '' : 's'} queued while offline.`;
  }

  async function sendChat(): Promise<void> {
    const content = chatDraft.trim();
    if (!content) return;

    if (!online) {
      queueCurrentMessage(content, composerMode);
      chatDraft = '';
      return;
    }

    await submitMessage(content, composerMode);
    chatDraft = '';
  }

  async function submitMessage(content: string, mode: ComposerMode): Promise<void> {
    const me = participants.find((p) => p.is_current_user);

    const payload = {
      content: mode === 'prayer' ? `🙏 Prayer Request: ${content}` : content,
      message_type: 'chat',
      latitude: me?.location?.lat,
      longitude: me?.location?.lng
    };

    try {
      await api('/messages', {
        method: 'POST',
        body: JSON.stringify(payload)
      }, deviceToken);

      await refreshData();
      showStatus(mode === 'prayer' ? 'Prayer request sent.' : 'Message sent.');
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to send message.';
      setError(message);
      queueCurrentMessage(content, mode);
    }
  }

  async function flushQueue(): Promise<void> {
    if (!queuedMessages.length || !online) return;

    const batch = [...queuedMessages];
    queuedMessages = [];

    for (const item of batch) {
      await submitMessage(item.content, item.mode);
    }

    queueStatus = 'Queued messages flushed.';
    setTimeout(() => {
      if (queueStatus === 'Queued messages flushed.') queueStatus = '';
    }, 2500);
  }

  async function sendEmergencyAlert(): Promise<void> {
    if (!canSendAlert) {
      setError('Only retreat leaders can send emergency alerts.');
      return;
    }

    const text = alertDraft.trim();
    if (!text) {
      setError('Please enter the emergency message before sending.');
      return;
    }

    try {
      await api('/messages', {
        method: 'POST',
        body: JSON.stringify({
          message_type: 'alert',
          content: `[${alertSeverity.toUpperCase()}] ${text}`
        })
      }, deviceToken);

      showStatus(`Emergency alert sent (${alertSeverity}).`);
      alertDraft = '';
      showAlertConfirm = false;
      activeTab = 'chat';
      await refreshData();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to send emergency alert.');
    }
  }

  function scrollParticipantRow(direction: -1 | 1): void {
    if (!participantRowElement) return;

    participantRowElement.scrollBy({
      left: direction * 188,
      behavior: 'smooth'
    });
  }

  function focusParticipantOnMap(row: ParticipantLocationRow): void {
    focusedParticipantId = row.participant_id;

    if (activeTab !== 'map') {
      activeTab = 'map';
    }

    if (!hasValidCoords(row.location?.lat, row.location?.lng)) {
      setError(`${row.name} does not have a live location yet.`);
      return;
    }

    const lat = Number(row.location!.lat);
    const lng = Number(row.location!.lng);
    const placeLabel = participantNearLabel(row);
    const seenAgo = formatAgo(row.last_seen_seconds_ago);
    const stopInsight = stopInsightForParticipant(row.participant_id);

    const applyFocus = () => {
      if (!mapInstance) return;

      const currentZoom = typeof mapInstance.getZoom === 'function'
        ? Number(mapInstance.getZoom() ?? 9)
        : 9;
      const nextZoom = currentZoom < 8 ? 8 : currentZoom;

      if (typeof mapInstance.stop === 'function') {
        mapInstance.stop();
      }

      if (typeof mapInstance.getZoom === 'function' && typeof mapInstance.setZoom === 'function' && currentZoom !== nextZoom) {
        mapInstance.setZoom(nextZoom, { animate: false });
      }

      if (typeof mapInstance.panTo === 'function') {
        mapInstance.panTo([lat, lng], {
          animate: true,
          duration: 0.28,
          easeLinearity: 0.3
        });
      } else {
        mapInstance.setView([lat, lng], nextZoom, {
          animate: false
        });
      }

      const openFocusPopup = () => {
        if (!mapLibrary?.popup || !mapInstance) return;

        mapLibrary
          .popup({ closeButton: false, offset: [0, -10] })
          .setLatLng([lat, lng])
          .setContent(
            `<strong>${escapeHtml(row.name)}</strong><br>${escapeHtml(placeLabel)}<br>${escapeHtml(seenAgo)}${stopInsight?.phase === 'stopped' ? `<br>🛑 Stopped ${escapeHtml(formatDurationCompact(stopInsight.stopped_for_seconds))}` : ''}`
          )
          .openOn(mapInstance);
      };

      openFocusPopup();
    };

    if (!mapInstance) {
      void ensureMapReady().then(() => {
        applyFocus();
      });
      return;
    }

    applyFocus();
  }

  function openParticipant(row: ParticipantLocationRow): void {
    selectedParticipant = row;
  }

  function closeParticipantSheet(): void {
    selectedParticipant = null;
  }

  function actionMessageParticipant(row: ParticipantLocationRow): void {
    activeTab = 'chat';
    composerMode = 'chat';
    chatDraft = `@${row.name} `;
    selectedParticipant = null;
  }

  function actionPrayForParticipant(row: ParticipantLocationRow): void {
    activeTab = 'chat';
    composerMode = 'prayer';
    chatDraft = `Praying for ${row.name} — `;
    selectedParticipant = null;
  }

  function actionAlertLeader(row: ParticipantLocationRow): void {
    activeTab = 'alert';
    alertSeverity = 'high';
    alertDraft = `Need leader support near ${row.name}: `;
    selectedParticipant = null;
  }

  function actionCallParticipant(_row: ParticipantLocationRow): void {
    showStatus('Call shortcut wired. Number binding comes next.');
  }

  async function uploadProfilePhoto(file: File | null): Promise<void> {
    if (!file) return;
    if (!deviceToken) return;

    uploadBusy = true;
    try {
      const base64 = await toDataUrl(file);
      await api('/profile-photo', {
        method: 'POST',
        body: JSON.stringify({ avatar_base64: base64 })
      }, deviceToken);
      showStatus('Profile photo updated.');
      await refreshData();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not upload profile photo.');
    } finally {
      uploadBusy = false;
    }
  }

  async function removeProfilePhoto(): Promise<void> {
    if (!deviceToken) return;

    uploadBusy = true;
    try {
      await api('/profile-photo', { method: 'DELETE' }, deviceToken);
      showStatus('Profile photo removed.');
      await refreshData();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not remove profile photo.');
    } finally {
      uploadBusy = false;
    }
  }

  async function setLocationSharing(enabled: boolean): Promise<void> {
    if (!deviceToken) return;
    if (locationSharingBusy) return;
    if (enabled === locationSharingEnabled) return;

    locationSharingBusy = true;

    try {
      const payload = await api<{ data: { location_sharing_enabled: boolean } }>('/location-sharing', {
        method: 'POST',
        body: JSON.stringify({ enabled })
      }, deviceToken);

      locationSharingEnabled = payload.data.location_sharing_enabled;
      if (myParticipant) {
        myParticipant = {
          ...myParticipant,
          location_sharing_enabled: locationSharingEnabled
        };
      }

      participants = participants.map((row) => {
        if (!row.is_current_user) return row;
        return {
          ...row,
          location_sharing_enabled: locationSharingEnabled,
          location: locationSharingEnabled ? row.location : null
        };
      });

      if (!locationSharingEnabled) {
        stopLocationWatch();
        showStatus('Location sharing paused. Your marker is hidden from the map.');
      } else {
        locationErrorShown = false;
        showStatus('Location sharing re-enabled.');
      }

      await refreshData();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not update location sharing.');
    } finally {
      locationSharingBusy = false;
    }
  }

  function resetRetreatSession(): void {
    localStorage.removeItem(TOKEN_KEY);
    inRetreat = false;
    deviceToken = '';
    myParticipant = null;
    retreatInfo = null;
    participants = [];
    waypoints = [];
    messages = [];
    queuedMessages = [];
    locationSharingEnabled = true;
    focusedParticipantId = null;
    latestLocationsServerTimeIso = null;
    stopTrackersById = {};
    stopInsightsById = {};
    stopEvents = [];
    nextStopEventId = 0;
    stopLocationWatch();

    if (mapInstance) {
      mapInstance.remove();
      mapInstance = null;
      mapLayer = null;
    }

    mapAutoFramed = false;
    previousLocationCount = 0;
  }

  async function leaveRetreat(): Promise<void> {
    if (!deviceToken) return;
    leaving = true;

    try {
      await api('/leave', { method: 'POST' }, deviceToken);
      resetRetreatSession();
      showStatus('You have left the retreat.');
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not leave retreat.');
    } finally {
      leaving = false;
    }
  }

  async function deleteAccountAndData(): Promise<void> {
    if (!deviceToken) return;
    if (deleteAccountBusy) return;

    const confirmed = typeof window !== 'undefined'
      ? window.confirm('Delete your account and retreat data now? This cannot be undone.')
      : false;

    if (!confirmed) {
      return;
    }

    deleteAccountBusy = true;

    try {
      await api('/account', {
        method: 'DELETE',
        body: JSON.stringify({ confirm_delete: true })
      }, deviceToken);

      resetRetreatSession();
      showStatus('Your account and retreat data were deleted.');
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not delete account data.');
    } finally {
      deleteAccountBusy = false;
    }
  }

  async function onRetryQueue(): Promise<void> {
    if (!online) {
      setError('Still offline. Messages remain queued.');
      return;
    }

    await flushQueue();
  }

  function toDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.onerror = () => reject(new Error('Failed to read file'));
      reader.readAsDataURL(file);
    });
  }

  function enableDemoMode(): void {
    const nowIso = new Date().toISOString();
    const nowMs = Date.now();

    myParticipant = {
      id: 101,
      name: 'Chris Hogg',
      phone_display: '+1••••••5761',
      is_leader: true,
      location_sharing_enabled: true,
      avatar_url: null
    };

    retreatInfo = {
      id: 501,
      name: 'Spring Retreat Convoy',
      destination: {
        name: 'Branson Camp Grounds',
        lat: 36.6406,
        lng: -93.2185
      },
      starts_at: new Date(nowMs - 60 * 60 * 1000).toISOString(),
      ends_at: new Date(nowMs + 18 * 60 * 60 * 1000).toISOString(),
      participant_count: 4
    };

    participants = [
      {
        participant_id: 101,
        name: 'Chris Hogg',
        gender: null,
        avatar_url: null,
        vehicle_color: 'Silver',
        vehicle_description: 'Ford F-150',
        is_leader: true,
        is_current_user: true,
        location_sharing_enabled: true,
        location: {
          lat: 36.612,
          lng: -93.287,
          accuracy: 9,
          speed: 17,
          heading: 112,
          recorded_at: nowIso,
          place: {
            label: 'At Trumann Community Health Club',
            name: 'Trumann Community Health Club',
            relation: 'at',
            distance_m: 12,
            confidence: 'high',
            source: 'nominatim'
          }
        },
        last_seen_seconds_ago: 5
      },
      {
        participant_id: 102,
        name: 'Sarah Jenkins',
        gender: null,
        avatar_url: null,
        vehicle_color: 'White',
        vehicle_description: 'Honda CR-V',
        is_leader: false,
        is_current_user: false,
        location_sharing_enabled: true,
        location: {
          lat: 36.618,
          lng: -93.272,
          accuracy: 13,
          speed: 18,
          heading: 108,
          recorded_at: nowIso
        },
        last_seen_seconds_ago: 28
      },
      {
        participant_id: 103,
        name: 'Micah Davis',
        gender: null,
        avatar_url: null,
        vehicle_color: 'Blue',
        vehicle_description: 'Chevy Traverse',
        is_leader: false,
        is_current_user: false,
        location_sharing_enabled: true,
        location: {
          lat: 36.604,
          lng: -93.248,
          accuracy: 11,
          speed: 21,
          heading: 96,
          recorded_at: nowIso
        },
        last_seen_seconds_ago: 13
      },
      {
        participant_id: 104,
        name: 'Linda Perez',
        gender: null,
        avatar_url: null,
        vehicle_color: 'Black',
        vehicle_description: 'Kia Telluride',
        is_leader: false,
        is_current_user: false,
        location_sharing_enabled: true,
        location: {
          lat: 36.597,
          lng: -93.214,
          accuracy: 14,
          speed: 16,
          heading: 92,
          recorded_at: nowIso
        },
        last_seen_seconds_ago: 36
      }
    ];

    latestLocationsServerTimeIso = nowIso;
    syncStopIntelligence(participants, latestLocationsServerTimeIso);

    waypoints = [
      {
        id: 1,
        name: 'Fuel + regroup',
        description: 'Top off and verify all vans are accounted for.',
        lat: 36.621,
        lng: -93.295,
        order: 1,
        eta: new Date(nowMs + 25 * 60 * 1000).toISOString()
      },
      {
        id: 2,
        name: 'Prayer circle',
        description: '10-minute devotion and route update.',
        lat: 36.634,
        lng: -93.252,
        order: 2,
        eta: new Date(nowMs + 55 * 60 * 1000).toISOString()
      },
      {
        id: 3,
        name: 'Arrive at retreat camp',
        description: 'Unload and check in by group name.',
        lat: 36.641,
        lng: -93.219,
        order: 3,
        eta: new Date(nowMs + 90 * 60 * 1000).toISOString()
      }
    ];

    messages = [
      {
        id: 1,
        message_type: 'chat',
        content: 'All vehicles are rolling. Next checkpoint in ~25 minutes.',
        sender: {
          id: 101,
          name: 'Chris Hogg',
          is_leader: true,
          gender: null,
          avatar_url: null
        },
        location: null,
        created_at: new Date(nowMs - 8 * 60 * 1000).toISOString()
      },
      {
        id: 2,
        message_type: 'chat',
        content: '🙏 Prayer Request: please pray for safe travel and calm weather.',
        sender: {
          id: 102,
          name: 'Sarah Jenkins',
          is_leader: false,
          gender: null,
          avatar_url: null
        },
        location: null,
        created_at: new Date(nowMs - 4 * 60 * 1000).toISOString()
      },
      {
        id: 3,
        message_type: 'alert',
        content: '[HIGH] Keep hazard lights on for reduced visibility zone ahead.',
        sender: {
          id: 101,
          name: 'Chris Hogg',
          is_leader: true,
          gender: null,
          avatar_url: null
        },
        location: null,
        created_at: new Date(nowMs - 2 * 60 * 1000).toISOString()
      }
    ];

    profileVehicleColor = 'Silver';
    profileVehicleDescription = 'Ford F-150';

    inRetreat = true;
    appReady = true;
    queueStatus = 'Demo mode active from ?demo=1 for visual sharing.';
  }

  onMount(() => {
    online = navigator.onLine;

    void (async () => {
      const params = new URLSearchParams(window.location.search);
      const useDemo = params.get('demo') === '1';
      const forceSignIn = params.get('signin') === '1' || params.get('auth') === 'signin';
      const forceAuthScreen = forceSignIn || params.get('reauth') === '1' || params.get('reset') === '1';

      if (useDemo) {
        enableDemoMode();
        return;
      }

      if (forceSignIn) {
        joinMode = 'signin';
      }

      if (forceAuthScreen) {
        localStorage.removeItem(TOKEN_KEY);
      }

      const existingToken = localStorage.getItem(TOKEN_KEY);
      if (existingToken) {
        deviceToken = existingToken;
        try {
          await hydrateFromToken(existingToken);
        } catch {
          localStorage.removeItem(TOKEN_KEY);
          deviceToken = '';
          appReady = true;
        }
      } else {
        appReady = true;
      }
    })();

    const onlineHandler = async () => {
      online = true;
      await flushQueue();
      await refreshData();
    };

    const offlineHandler = () => {
      online = false;
      queueStatus = 'You are offline. New messages will be queued.';
    };

    window.addEventListener('online', onlineHandler);
    window.addEventListener('offline', offlineHandler);

    refreshTimer = setInterval(() => {
      if (inRetreat && online) {
        void refreshData();
      }
    }, 20000);

    return () => {
      window.removeEventListener('online', onlineHandler);
      window.removeEventListener('offline', offlineHandler);
      if (refreshTimer) clearInterval(refreshTimer);

      stopLocationWatch();

      if (mapInstance) {
        mapInstance.remove();
        mapInstance = null;
        mapLayer = null;
      }
    };
  });
</script>

{#if !appReady}
  <main class="boot-screen">
    <h1>Loading Calvary Caravan…</h1>
    <p>Syncing your convoy workspace.</p>
  </main>
{:else if !inRetreat}
  <main class="join-shell">
    <section class="join-card">
      <div class="join-header">
        <span class="eyebrow">Calvary Caravan</span>
        <h1>{joinMode === 'signin' ? 'Welcome Back' : 'Join the Retreat'}</h1>
        <p>
          {#if joinMode === 'signin'}
            Already signed up? Enter your retreat code + phone number to sign in fast.
          {:else}
            Enter your retreat code, phone number, and basic details to sync with your group on the road.
          {/if}
        </p>
      </div>

      <form class="join-form" on:submit|preventDefault={joinRetreat}>
        <div class="join-mode-toggle">
          <button
            type="button"
            class="join-option"
            class:active={joinMode === 'join'}
            aria-pressed={joinMode === 'join'}
            on:click={() => (joinMode = 'join')}
          >
            New Join
          </button>
          <button
            type="button"
            class="signin-option"
            class:active={joinMode === 'signin'}
            aria-pressed={joinMode === 'signin'}
            on:click={() => (joinMode = 'signin')}
          >
            Sign In
          </button>
        </div>

        <label>
          6-character invite code
          <input
            bind:value={joinCode}
            maxlength="6"
            placeholder="TEST26"
            on:input={(event) => {
              const target = event.currentTarget as HTMLInputElement;
              joinCode = normalizeCode(target.value);
            }}
            required
          />
        </label>

        <label>
          Phone number (used for retreat identity)
          <input
            bind:value={joinPhoneNumber}
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            maxlength="24"
            placeholder="e.g. (501) 231-5761"
            required
          />
        </label>

        {#if joinMode === 'join'}
          <label>
            Full name
            <input bind:value={joinName} maxlength="50" placeholder="e.g. Sarah Jenkins" required={joinMode === 'join'} />
          </label>

          <div class="split-fields">
            <label>
              Vehicle color
              <input bind:value={joinVehicleColor} maxlength="30" placeholder="e.g. Silver" />
            </label>

            <label>
              Make/model
              <input bind:value={joinVehicleDescription} maxlength="50" placeholder="e.g. Honda CR-V" />
            </label>
          </div>
        {:else}
          <p class="signin-hint subtle">
            Use the same phone number you originally joined with.
          </p>
        {/if}

        <button type="submit" disabled={joining}>
          {#if joining}
            {joinMode === 'signin' ? 'Signing in…' : 'Joining…'}
          {:else}
            {joinMode === 'signin' ? 'Sign in' : 'Start the Journey'}
          {/if}
        </button>
      </form>

      <aside class="join-notes">
        <p><strong>Permission notes for store review:</strong></p>
        <ul>
          <li>Your phone number is used as your retreat identity (no OTP in this version).</li>
          <li>If you already joined, use <strong>Sign In</strong> with retreat code + the same phone number.</li>
          <li>Location is used only while the app is active so your marker can update on the convoy map.</li>
          <li>You can delete your account data anytime in Profile → Delete account &amp; data.</li>
        </ul>
        <p class="join-links">
          <a href="/privacy" target="_blank" rel="noopener noreferrer">Privacy</a>
          ·
          <a href="/support" target="_blank" rel="noopener noreferrer">Support</a>
          ·
          <a href="/account-deletion" target="_blank" rel="noopener noreferrer">Account deletion</a>
        </p>
      </aside>
    </section>
  </main>
{:else}
  <main class="app-shell">
    <header class="topbar card">
      <div>
        <p class="eyebrow">{retreatInfo?.name ?? 'Calvary Caravan'}</p>
        <h2>Convoy Control</h2>
        <p class="subtle">{onlineCount}/{participants.length} online · {retreatInfo?.participant_count ?? participants.length} total</p>
      </div>

      <div class="topbar-actions">
        <button type="button" class="ghost" on:click={refreshData} disabled={refreshing}>
          {refreshing ? '…' : '↻'}
        </button>
      </div>
    </header>

    {#if !online || queuedCount > 0}
      <section class="status-banner card">
        <div>
          <strong>{online ? 'Queued updates ready' : 'Offline mode active'}</strong>
          <p>
            {#if online}
              {queuedCount} queued message{queuedCount === 1 ? '' : 's'} waiting to send.
            {:else}
              You can keep using the app — new messages will sync when your signal returns.
            {/if}
          </p>
        </div>
        <button type="button" class="small" on:click={onRetryQueue}>Retry now</button>
      </section>
    {/if}

    <nav class="tabbar card">
      {#each inAppTabs as tab}
        <button
          type="button"
          class:active={activeTab === tab.id}
          on:click={() => (activeTab = tab.id)}
        >
          <span>{tab.icon}</span>
          <small>{tab.label}</small>
        </button>
      {/each}
    </nav>

    {#if activeTab === 'map'}
      <section class="map-panel card">
        <div class="panel-head">
          <h3>Live Convoy Map</h3>
          <p>{retreatInfo?.destination?.name ? `Destination: ${retreatInfo.destination.name}` : 'Destination syncing…'}</p>
        </div>

        <div class="map-canvas">
          <div class="map-live" bind:this={mapElement} aria-label="Live map"></div>

          {#if mapRows.length === 0}
            <div class="map-empty">
              <strong>No live markers yet</strong>
              <p>Allow location while using the app so your marker can appear on the live map.</p>
            </div>
          {/if}
        </div>

        <p class="map-attribution" role="note" aria-label="Map attribution">
          <a href="https://leafletjs.com" target="_blank" rel="noopener noreferrer">Leaflet</a>
          <span aria-hidden="true">•</span>
          <span>
            Map data ©
            <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer"
              >OpenStreetMap contributors</a
            >
          </span>
        </p>

        <section class="stop-intel card" aria-live="polite">
          <header class="stop-intel-head">
            <div>
              <h4>Who’s parked right now</h4>
              <p>Quick glance at vehicles that look paused.</p>
            </div>
            <small>
              {stoppedParticipantRows.length} parked · {movingParticipantCount} rolling
              {#if latestLocationsServerTimeIso}
                · updated {formatTime(latestLocationsServerTimeIso)}
              {/if}
            </small>
          </header>

          {#if stoppedParticipantRows.length === 0}
            <div class="stop-intel-empty subtle">Looks like everyone is rolling right now.</div>
          {:else}
            <div class="stop-intel-grid">
              {#each stoppedParticipantRows as item}
                <article class="stop-intel-item">
                  <strong>{item.row.name}</strong>
                  <p>📍 {item.insight.place_phrase}</p>
                  <small>Stopped for about {formatDurationWords(item.insight.stopped_for_seconds)}.</small>
                </article>
              {/each}
            </div>
          {/if}
        </section>

        <div class="participant-strip-tools">
          <div class="participant-strip-filter" role="group" aria-label="Participant filter">
            <button
              type="button"
              class="chip-filter"
              class:active={participantStripFilter === 'all'}
              on:click={() => (participantStripFilter = 'all')}
            >
              All ({participants.length})
            </button>
            <button
              type="button"
              class="chip-filter"
              class:active={participantStripFilter === 'leaders'}
              on:click={() => (participantStripFilter = 'leaders')}
            >
              Leaders ({participants.filter((p) => p.is_leader).length})
            </button>
          </div>

          <div class="participant-strip-scroll-buttons" aria-label="Scroll participants">
            <button type="button" class="chip-scroll-btn" on:click={() => scrollParticipantRow(-1)} aria-label="Scroll left">◀</button>
            <button type="button" class="chip-scroll-btn" on:click={() => scrollParticipantRow(1)} aria-label="Scroll right">▶</button>
          </div>
        </div>

        {#if participantStripRows.length === 0}
          <div class="participant-strip-empty subtle">No participants match this filter yet.</div>
        {:else}
          <div class="participant-row" bind:this={participantRowElement}>
            {#each participantStripRows as row}
              <button
                type="button"
                class="participant-chip"
                class:active={focusedParticipantId === row.participant_id}
                class:stopped={stopInsightForParticipant(row.participant_id)?.phase === 'stopped'}
                on:click={() => focusParticipantOnMap(row)}
                aria-label={`Focus ${row.name} on map`}
              >
                <span
                  class="participant-avatar"
                  class:leader={row.is_leader}
                  class:online={(row.last_seen_seconds_ago ?? 9999) < 300}
                  class:offline={(row.last_seen_seconds_ago ?? 9999) >= 300}
                >
                  {#if row.avatar_url}
                    <img src={row.avatar_url} alt={row.name} />
                  {:else}
                    <span class="participant-avatar-glyph" aria-hidden="true">{row.is_leader ? '⭐' : '👤'}</span>
                  {/if}
                  <span class="participant-status-dot" aria-hidden="true"></span>
                </span>
                <span class="participant-name">{row.name}</span>
                {#if stopBadgeForRow(row)}
                  <small class="participant-stop-badge">{stopBadgeForRow(row)}</small>
                {/if}
              </button>
            {/each}
          </div>
        {/if}

        <section class="status-feed card" aria-live="polite">
          <header class="status-feed-head">
            <h4>Status feed</h4>
            <small>Newest updates</small>
          </header>

          {#if recentStopEvents.length === 0}
            <div class="status-feed-empty subtle">No stop updates yet.</div>
          {:else}
            <div class="stop-feed" role="log" aria-label="Recent stop events">
              {#each recentStopEvents as event}
                <div class={`stop-feed-item ${event.kind}`}>
                  <span aria-hidden="true">{event.kind === 'stopped' ? '🛑' : '✅'}</span>
                  <span>{event.text}</span>
                </div>
              {/each}
            </div>
          {/if}
        </section>
      </section>
    {:else if activeTab === 'waypoints'}
      <section class="panel card">
        <div class="panel-head">
          <h3>Waypoints & Schedule</h3>
          <p>Keep everyone aligned on ETAs and checkpoint progress.</p>
        </div>

        {#if waypoints.length === 0}
          <div class="empty-state">
            <strong>No waypoints loaded</strong>
            <p>Waypoints from the retreat route will show here automatically.</p>
          </div>
        {:else}
          <div class="timeline">
            {#each waypoints as waypoint, idx}
              <article class="timeline-card">
                <div class="timeline-dot {new Date(waypoint.eta ?? '').getTime() < Date.now() ? 'done' : ''}"></div>
                <div>
                  <p class="eyebrow">Stop {idx + 1}</p>
                  <h4>{waypoint.name}</h4>
                  <p>{waypoint.description ?? 'No additional note.'}</p>
                  <small>ETA {formatTime(waypoint.eta)}</small>
                </div>
              </article>
            {/each}
          </div>
        {/if}
      </section>
    {:else if activeTab === 'chat'}
      <section class="panel card">
        <div class="panel-head">
          <h3>Group Chat</h3>
          <p>Use chat for normal updates and prayer mode for care moments.</p>
        </div>

        <div class="chat-list">
          {#if messages.length === 0}
            <div class="empty-state">
              <strong>No messages yet</strong>
              <p>Start with a quick check-in for the group.</p>
            </div>
          {:else}
            {#each messages as msg}
              <article class={`chat-item ${msg.message_type === 'alert' ? 'alert' : ''}`}>
                <header>
                  <strong>{msg.sender.name}</strong>
                  <small>{formatTime(msg.created_at)}</small>
                </header>
                <p>{msg.content}</p>
              </article>
            {/each}
          {/if}
        </div>

        <div class="composer">
          <div class="mode-toggle">
            <button type="button" class:active={composerMode === 'chat'} on:click={() => (composerMode = 'chat')}>Chat</button>
            <button type="button" class:active={composerMode === 'prayer'} on:click={() => (composerMode = 'prayer')}>Prayer Request</button>
          </div>

          <textarea
            bind:value={chatDraft}
            rows="3"
            placeholder={composerMode === 'prayer' ? 'Share a prayer need for the caravan…' : 'Send a convoy update…'}
          ></textarea>
          <button type="button" on:click={sendChat}>Send</button>
          {#if queueStatus}
            <small class="subtle">{queueStatus}</small>
          {/if}
        </div>
      </section>
    {:else if activeTab === 'alert'}
      <section class="panel card">
        <div class="panel-head">
          <h3>Emergency Alert Composer</h3>
          <p>Leader-only high-signal channel with confirmation safeguard.</p>
        </div>

        {#if !canSendAlert}
          <div class="empty-state">
            <strong>Leader access required</strong>
            <p>You can receive alerts here, but only leaders can broadcast emergency notices.</p>
          </div>
        {:else}
          <div class="alert-builder">
            <div class="severity-grid">
              {#each ['low', 'medium', 'high', 'critical'] as severity}
                <button
                  type="button"
                  class:active={alertSeverity === severity}
                  on:click={() => (alertSeverity = severity as typeof alertSeverity)}
                >
                  {severity}
                </button>
              {/each}
            </div>

            <textarea bind:value={alertDraft} rows="4" placeholder="Describe the urgent issue and what the group should do next."></textarea>

            <div class="alert-preview card">
              <p class="eyebrow">Preview</p>
              <p><strong>[{alertSeverity.toUpperCase()}]</strong> {alertDraft || 'No message yet.'}</p>
            </div>

            <button type="button" class="danger" on:click={() => (showAlertConfirm = true)}>
              Send emergency alert
            </button>
          </div>
        {/if}
      </section>
    {:else if activeTab === 'profile'}
      <section class="panel card">
        <div class="panel-head">
          <h3>Profile & Vehicle</h3>
          <p>Keep your details current so others can identify you quickly on the road.</p>
        </div>

        <article class="profile-card card">
          <div class="avatar-wrap">
            {#if myParticipant?.avatar_url}
              <img src={myParticipant.avatar_url} alt="Profile" />
            {:else}
              <div class="avatar-fallback">{myParticipant?.name?.slice(0, 1) ?? 'C'}</div>
            {/if}
          </div>

          <div class="profile-fields">
            <label>
              Name
              <input value={myParticipant?.name ?? ''} readonly />
            </label>

            <label>
              Phone identity
              <input value={myParticipant?.phone_display ?? ''} readonly />
            </label>

            <label>
              Vehicle color
              <input bind:value={profileVehicleColor} placeholder="e.g. Silver" />
            </label>

            <label>
              Make/model
              <input bind:value={profileVehicleDescription} placeholder="e.g. Honda CR-V" />
            </label>
          </div>
        </article>

        <article class="location-sharing card">
          <div>
            <p class="eyebrow">Privacy</p>
            <h4>Location sharing is {locationSharingEnabled ? 'On' : 'Off'}</h4>
            <p>
              Turn this off anytime to remove your marker from the live map. You can still use chat and waypoints.
              For best results, allow location while using the app in your phone settings.
            </p>
          </div>
          <button
            type="button"
            class={locationSharingEnabled ? 'danger-outline' : 'ghost'}
            disabled={locationSharingBusy}
            on:click={() => setLocationSharing(!locationSharingEnabled)}
          >
            {#if locationSharingBusy}
              Saving…
            {:else if locationSharingEnabled}
              Unshare my location
            {:else}
              Share my location again
            {/if}
          </button>
        </article>

        <div class="profile-actions">
          <label class="upload-btn">
            {uploadBusy ? 'Uploading…' : 'Upload profile photo'}
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp"
              disabled={uploadBusy}
              on:change={(event) => {
                const target = event.currentTarget as HTMLInputElement;
                void uploadProfilePhoto(target.files?.[0] ?? null);
                target.value = '';
              }}
            />
          </label>

          <button type="button" class="ghost" disabled={uploadBusy} on:click={removeProfilePhoto}>Remove photo</button>
          <button type="button" class="ghost" on:click={() => showStatus('Vehicle detail save endpoint is next backend step.')}>Save vehicle details</button>
          <button type="button" class="danger-outline" on:click={leaveRetreat} disabled={leaving || deleteAccountBusy}>{leaving ? 'Leaving…' : 'Leave retreat'}</button>
          <button type="button" class="danger" on:click={deleteAccountAndData} disabled={leaving || deleteAccountBusy}>
            {deleteAccountBusy ? 'Deleting…' : 'Delete account & data'}
          </button>
        </div>
      </section>
    {/if}
  </main>
{/if}

{#if selectedParticipant}
  <section class="sheet-backdrop" aria-label="Participant quick actions">
    <button
      type="button"
      class="sheet-hitbox"
      aria-label="Close participant quick actions"
      on:click={closeParticipantSheet}
    ></button>

    <article class="participant-sheet card">
      <header>
        <h4>{selectedParticipant.name}</h4>
        <p>{selectedParticipant.vehicle_color ?? 'Vehicle TBD'} · {selectedParticipant.vehicle_description ?? 'Description TBD'}</p>
        {#if selectedParticipant.location?.place?.label}
          <p class="place-label-line">
            📍 {selectedParticipant.location.place.label}
            {#if selectedParticipant.location.place.distance_m !== null}
              <span>({formatDistanceMeters(selectedParticipant.location.place.distance_m)})</span>
            {/if}
          </p>
        {/if}
      </header>

      <div class="quick-actions">
        <button type="button" on:click={() => actionCallParticipant(selectedParticipant!)}>📞 Call</button>
        <button type="button" on:click={() => actionMessageParticipant(selectedParticipant!)}>💬 Message</button>
        <button type="button" on:click={() => actionPrayForParticipant(selectedParticipant!)}>🙏 Pray</button>
        <button type="button" class="alert-leader-btn" on:click={() => actionAlertLeader(selectedParticipant!)}>
          <span class="alert-leader-icon" aria-hidden="true">🚨</span>
          <span>Alert leader</span>
        </button>
      </div>

      {#if stopInsightForParticipant(selectedParticipant.participant_id)?.phase === 'stopped'}
        <small class="subtle">🛑 Stopped {stopInsightForParticipant(selectedParticipant.participant_id)?.place_phrase} for {formatDurationWords(stopInsightForParticipant(selectedParticipant.participant_id)?.stopped_for_seconds)}</small>
      {/if}
      <small class="subtle">Last seen: {formatAgo(selectedParticipant.last_seen_seconds_ago)}</small>
    </article>
  </section>
{/if}

{#if showAlertConfirm}
  <section class="sheet-backdrop" aria-label="Emergency confirmation">
    <button
      type="button"
      class="sheet-hitbox"
      aria-label="Close emergency confirmation"
      on:click={() => (showAlertConfirm = false)}
    ></button>

    <article class="confirm-modal card">
      <h4>Confirm emergency broadcast</h4>
      <p>
        This sends a <strong>{alertSeverity.toUpperCase()}</strong> alert to the full retreat group.
        Please confirm this message is accurate.
      </p>
      <blockquote>[{alertSeverity.toUpperCase()}] {alertDraft || 'No message entered.'}</blockquote>
      <div class="confirm-actions">
        <button type="button" class="ghost" on:click={() => (showAlertConfirm = false)}>Cancel</button>
        <button type="button" class="danger" on:click={sendEmergencyAlert}>Send now</button>
      </div>
    </article>
  </section>
{/if}

{#if errorMessage}
  <aside class="toast error">⚠️ {errorMessage}</aside>
{/if}

{#if statusMessage}
  <aside class="toast success">✅ {statusMessage}</aside>
{/if}

<style>
  :global(body) {
    --accent-main: #8f0030;
    --accent-main-strong: #b30045;
    --accent-soft: rgba(143, 0, 48, 0.16);
    --accent-soft-strong: rgba(143, 0, 48, 0.22);

    margin: 0;
    background: #f4f3ef;
    color: #1f2430;
    font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  :global(*) {
    box-sizing: border-box;
  }

  .boot-screen,
  .join-shell,
  .app-shell {
    max-width: 420px;
    margin: 0 auto;
    padding: 1.2rem 0.9rem 5.8rem;
  }

  .join-shell {
    min-height: 100dvh;
    display: grid;
    align-content: center;
  }

  .card {
    border-radius: 20px;
    border: 1px solid rgba(23, 34, 59, 0.08);
    background: #ffffff;
    box-shadow: 0 14px 30px rgba(11, 32, 68, 0.08);
  }

  .eyebrow {
    margin: 0;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.72rem;
    color: #6a7284;
  }

  .subtle {
    margin: 0;
    color: #6a7284;
  }

  .join-card {
    padding: 1.2rem;
  }

  .join-header h1 {
    margin: 0.2rem 0 0.45rem;
    font-size: 1.45rem;
  }

  .join-header p {
    margin: 0;
    line-height: 1.45;
    color: #5a6274;
  }

  .join-form {
    display: grid;
    gap: 0.85rem;
    margin-top: 1rem;
  }

  .join-mode-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.4rem;
  }

  .join-mode-toggle button {
    position: relative;
    background: #f5f6f9;
    color: #2a2f3a;
    font-size: 0.83rem;
    letter-spacing: 0.02em;
    border: 2px solid #111;
    box-shadow: 2px 2px 0 #111;
    opacity: 0.78;
    transform: none;
  }

  .join-mode-toggle button.active {
    background: var(--accent-soft-strong);
    color: var(--accent-main);
    border-width: 4px;
    box-shadow: 6px 6px 0 #111;
    opacity: 1;
  }

  .join-mode-toggle button:not(.active):hover {
    transform: translate(1px, 1px);
    box-shadow: 1px 1px 0 #111;
  }

  .signin-hint {
    margin: -0.15rem 0 0;
    font-size: 0.78rem;
  }

  .split-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.65rem;
  }

  label {
    display: grid;
    gap: 0.34rem;
    font-size: 0.84rem;
    color: #4b5364;
  }

  input,
  textarea,
  button {
    font: inherit;
  }

  input,
  textarea {
    border-radius: 13px;
    border: 1px solid rgba(39, 62, 113, 0.18);
    background: #fdfdff;
    color: inherit;
    padding: 0.68rem 0.72rem;
  }

  button {
    border: none;
    border-radius: 14px;
    padding: 0.66rem 0.82rem;
    background: linear-gradient(120deg, var(--accent-main), var(--accent-main-strong));
    color: white;
    font-weight: 650;
    cursor: pointer;
  }

  button:disabled {
    opacity: 0.62;
    cursor: not-allowed;
  }

  .ghost {
    background: rgba(38, 61, 113, 0.09);
    color: inherit;
  }

  .small {
    padding: 0.5rem 0.72rem;
    border-radius: 10px;
    font-size: 0.82rem;
  }

  .danger {
    background: linear-gradient(120deg, #da3b3b, #bf2222);
  }

  .danger-outline {
    background: transparent;
    border: 1px solid rgba(191, 34, 34, 0.52);
    color: #bf2222;
  }

  .join-notes {
    margin-top: 0.9rem;
    border-radius: 14px;
    border: 1px solid rgba(39, 62, 113, 0.16);
    background: rgba(38, 61, 113, 0.06);
    padding: 0.68rem 0.72rem;
    font-size: 0.8rem;
    color: #4b5364;
  }

  .join-notes p {
    margin: 0;
  }

  .join-notes ul {
    margin: 0.45rem 0 0;
    padding-left: 1rem;
    display: grid;
    gap: 0.28rem;
  }

  .join-links {
    margin-top: 0.55rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.32rem;
    align-items: center;
  }

  .join-links a {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.85rem 0.95rem;
  }

  .topbar h2 {
    margin: 0.15rem 0;
    font-size: 1.08rem;
  }

  .topbar-actions {
    display: flex;
    gap: 0.45rem;
  }

  .topbar-actions .ghost {
    min-width: 2.4rem;
  }

  .status-banner {
    margin-top: 0.72rem;
    padding: 0.8rem 0.95rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
  }

  .status-banner p {
    margin: 0.18rem 0 0;
    font-size: 0.84rem;
  }

  .tabbar {
    margin-top: 0.72rem;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.35rem;
    padding: 0.44rem;
  }

  .tabbar button {
    background: transparent;
    color: inherit;
    border-radius: 12px;
    padding: 0.45rem 0.35rem;
    display: grid;
    place-items: center;
    gap: 0.12rem;
    font-weight: 560;
  }

  .tabbar button small {
    font-size: 0.68rem;
  }

  .tabbar button.active {
    background: var(--accent-soft);
    color: var(--accent-main);
  }

  .map-panel,
  .panel {
    margin-top: 0.72rem;
    padding: 0.95rem;
    display: grid;
    gap: 0.9rem;
  }

  .panel-head h3 {
    margin: 0;
    font-size: 1.01rem;
  }

  .panel-head p {
    margin: 0.18rem 0 0;
    font-size: 0.84rem;
    color: #6a7284;
  }

  .map-canvas {
    position: relative;
    border-radius: 16px;
    height: 310px;
    overflow: hidden;
    border: 1px solid rgba(35, 56, 98, 0.15);
    background: #dce5f0;
  }

  .map-live {
    position: absolute;
    inset: 0;
    z-index: 1;
  }

  .map-attribution {
    margin: 0;
    padding: 0 0.2rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.36rem;
    font-size: 0.75rem;
    color: #6a7284;
  }

  .map-attribution a {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  :global(.leaflet-container) {
    width: 100%;
    height: 100%;
    font: inherit;
    background: #dce5f0;
  }

  :global(.leaflet-control-attribution),
  :global(.leaflet-control-zoom a) {
    border-radius: 10px;
  }

  .map-empty,
  .empty-state {
    border-radius: 14px;
    border: 1px dashed rgba(48, 70, 114, 0.28);
    padding: 0.8rem;
    background: rgba(255, 255, 255, 0.62);
    text-align: center;
  }

  .map-empty {
    position: absolute;
    inset: auto 14px 14px;
    z-index: 3;
    pointer-events: none;
    backdrop-filter: blur(1px);
  }

  .map-empty p,
  .empty-state p {
    margin: 0.22rem 0 0;
    font-size: 0.84rem;
  }

  .participant-strip-tools {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
  }

  .stop-intel {
    margin-top: 0.2rem;
    padding: 0.72rem;
    display: grid;
    gap: 0.62rem;
    border-radius: 14px;
    border: 1px solid rgba(143, 0, 48, 0.18);
    background: linear-gradient(180deg, rgba(255, 247, 247, 0.96), rgba(255, 239, 239, 0.88));
  }

  .stop-intel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.65rem;
  }

  .stop-intel-head h4 {
    margin: 0;
    font-size: 0.88rem;
  }

  .stop-intel-head p {
    margin: 0.2rem 0 0;
    font-size: 0.74rem;
    color: #6a7284;
  }

  .stop-intel-head small {
    font-size: 0.69rem;
    font-weight: 700;
    color: #8f0030;
    white-space: nowrap;
  }

  .stop-intel-empty {
    font-size: 0.76rem;
  }

  .stop-intel-grid {
    display: grid;
    gap: 0.5rem;
  }

  .stop-intel-item {
    border: 1px solid rgba(143, 0, 48, 0.18);
    border-radius: 12px;
    padding: 0.48rem 0.56rem;
    background: rgba(255, 255, 255, 0.84);
  }

  .stop-intel-item strong {
    display: block;
    font-size: 0.79rem;
  }

  .stop-intel-item p {
    margin: 0.18rem 0 0;
    font-size: 0.77rem;
  }

  .stop-intel-item small {
    display: block;
    margin-top: 0.16rem;
    color: #6b7384;
    font-size: 0.71rem;
  }

  .status-feed {
    padding: 0.72rem;
    display: grid;
    gap: 0.56rem;
    border-radius: 14px;
    border: 1px solid rgba(64, 83, 125, 0.2);
    background: rgba(255, 255, 255, 0.9);
  }

  .status-feed-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
  }

  .status-feed-head h4 {
    margin: 0;
    font-size: 0.85rem;
  }

  .status-feed-head small {
    font-size: 0.69rem;
    font-weight: 700;
    color: #6a7284;
    white-space: nowrap;
  }

  .status-feed-empty {
    font-size: 0.75rem;
  }

  .stop-feed {
    display: grid;
    gap: 0.34rem;
  }

  .stop-feed-item {
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.74rem;
    line-height: 1.22;
    border-radius: 10px;
    padding: 0.4rem 0.48rem;
    border: 1px dashed rgba(74, 84, 109, 0.35);
    background: rgba(255, 255, 255, 0.78);
  }

  .stop-feed-item.stopped {
    border-color: rgba(204, 31, 47, 0.42);
    background: rgba(255, 232, 234, 0.9);
  }

  .stop-feed-item.moving {
    border-color: rgba(25, 129, 83, 0.4);
    background: rgba(233, 255, 243, 0.9);
  }

  .participant-strip-filter {
    display: inline-grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.32rem;
  }

  .chip-filter {
    background: rgba(39, 62, 113, 0.08);
    color: inherit;
    border-radius: 10px;
    padding: 0.28rem 0.55rem;
    font-size: 0.69rem;
    font-weight: 600;
    line-height: 1;
  }

  .chip-filter.active {
    background: var(--accent-soft-strong);
    color: var(--accent-main);
  }

  .participant-strip-scroll-buttons {
    display: inline-grid;
    grid-auto-flow: column;
    gap: 0.24rem;
  }

  .chip-scroll-btn {
    background: rgba(39, 62, 113, 0.1);
    color: inherit;
    border-radius: 10px;
    padding: 0.28rem 0.45rem;
    min-width: 1.8rem;
    font-size: 0.68rem;
    line-height: 1;
  }

  .participant-strip-empty {
    font-size: 0.76rem;
    padding: 0.35rem 0.1rem;
  }

  .participant-row {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(66px, 76px);
    gap: 0.18rem;
    overflow-x: auto;
    padding: 0.06rem 0.04rem 0.12rem;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
  }

  .participant-row::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }

  .participant-chip {
    background: transparent;
    color: inherit;
    display: grid;
    justify-items: center;
    align-content: start;
    gap: 0.14rem;
    border-radius: 12px;
    border: 1px solid transparent;
    padding: 0.12rem 0.08rem 0.18rem;
  }

  .participant-chip.active {
    background: rgba(39, 62, 113, 0.08);
    border-color: rgba(61, 87, 139, 0.22);
  }

  .participant-chip.stopped {
    border-color: rgba(196, 35, 48, 0.4);
    background: rgba(255, 233, 236, 0.75);
  }

  .participant-stop-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-top: 0.08rem;
    border-radius: 999px;
    border: 1px solid rgba(196, 35, 48, 0.35);
    background: rgba(255, 236, 238, 0.9);
    padding: 0.08rem 0.32rem;
    font-size: 0.61rem;
    line-height: 1;
    font-weight: 700;
    color: #8f0030;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .participant-avatar {
    width: 40px;
    height: 40px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    position: relative;
    overflow: visible;
    border: 1px solid rgba(54, 83, 134, 0.26);
    background: rgba(42, 65, 112, 0.12);
  }

  .participant-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 999px;
  }

  .participant-avatar-glyph {
    font-size: 0.94rem;
    line-height: 1;
  }

  .participant-avatar.leader {
    box-shadow: 0 0 0 2px rgba(198, 62, 112, 0.24);
  }

  .participant-status-dot {
    position: absolute;
    right: -3px;
    bottom: -2px;
    width: 11px;
    height: 11px;
    border-radius: 999px;
    border: 2px solid #f6f7fb;
    background: #8f9bb5;
    z-index: 3;
    box-shadow: 0 0 0 1px rgba(8, 16, 33, 0.1);
  }

  .participant-avatar.online .participant-status-dot {
    background: #24b464;
  }

  .participant-name {
    font-size: 0.68rem;
    line-height: 1.06;
    text-align: center;
    max-width: 100%;
    display: -webkit-box;
    line-clamp: 2;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .timeline {
    display: grid;
    gap: 0.75rem;
  }

  .timeline-card {
    position: relative;
    border-radius: 14px;
    border: 1px solid rgba(37, 60, 108, 0.12);
    background: rgba(255, 255, 255, 0.7);
    padding: 0.72rem 0.72rem 0.72rem 1rem;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.6rem;
  }

  .timeline-card h4 {
    margin: 0.05rem 0 0.2rem;
  }

  .timeline-card p {
    margin: 0;
    font-size: 0.84rem;
    color: #5e6779;
  }

  .timeline-card small {
    display: block;
    margin-top: 0.35rem;
    color: #6b7384;
  }

  .timeline-dot {
    width: 12px;
    height: 12px;
    margin-top: 0.45rem;
    border-radius: 999px;
    background: var(--accent-main);
    box-shadow: 0 0 0 5px rgba(143, 0, 48, 0.2);
  }

  .timeline-dot.done {
    background: #28a35b;
    box-shadow: 0 0 0 5px rgba(40, 163, 91, 0.2);
  }

  .chat-list {
    display: grid;
    gap: 0.6rem;
    max-height: 280px;
    overflow-y: auto;
    padding-right: 0.1rem;
  }

  .chat-item {
    border-radius: 13px;
    border: 1px solid rgba(37, 60, 108, 0.12);
    padding: 0.62rem 0.68rem;
    background: rgba(255, 255, 255, 0.74);
  }

  .chat-item.alert {
    border-color: rgba(191, 34, 34, 0.4);
    background: rgba(255, 234, 226, 0.84);
  }

  .chat-item header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.22rem;
  }

  .chat-item p {
    margin: 0;
    line-height: 1.35;
    font-size: 0.88rem;
  }

  .composer {
    display: grid;
    gap: 0.55rem;
  }

  .mode-toggle {
    display: inline-grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.35rem;
  }

  .mode-toggle button {
    background: rgba(47, 67, 112, 0.1);
    color: inherit;
    font-size: 0.8rem;
  }

  .mode-toggle button.active {
    background: var(--accent-soft-strong);
    color: var(--accent-main);
  }

  .alert-builder {
    display: grid;
    gap: 0.7rem;
  }

  .severity-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.35rem;
  }

  .severity-grid button {
    background: rgba(37, 61, 112, 0.1);
    color: inherit;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .severity-grid button.active {
    background: rgba(191, 34, 34, 0.22);
    color: #8f1414;
  }

  .alert-preview {
    padding: 0.68rem;
    border-radius: 14px;
  }

  .alert-preview p {
    margin: 0.18rem 0 0;
  }

  .profile-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.7rem;
    padding: 0.75rem;
  }

  .avatar-wrap {
    width: 74px;
    height: 74px;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(43, 66, 114, 0.15);
    background: rgba(255, 255, 255, 0.7);
  }

  .avatar-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .avatar-fallback {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent-main);
  }

  .profile-fields {
    display: grid;
    gap: 0.5rem;
  }

  .profile-actions {
    display: grid;
    gap: 0.5rem;
  }

  .location-sharing {
    display: grid;
    gap: 0.65rem;
    padding: 0.75rem;
    border-radius: 16px;
    border: 1px solid rgba(43, 66, 114, 0.14);
    background: rgba(255, 255, 255, 0.72);
  }

  .location-sharing h4 {
    margin: 0.12rem 0 0;
    font-size: 0.98rem;
  }

  .location-sharing p {
    margin: 0.28rem 0 0;
    color: #5b6880;
  }

  .location-sharing button {
    justify-self: start;
  }

  .upload-btn {
    position: relative;
    overflow: hidden;
    background: linear-gradient(120deg, var(--accent-main), var(--accent-main-strong));
    color: white;
    border-radius: 14px;
    padding: 0.68rem 0.72rem;
    font-weight: 650;
    display: grid;
    place-items: center;
    cursor: pointer;
  }

  .upload-btn input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
  }

  .sheet-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(5, 14, 30, 0.5);
    display: grid;
    align-items: end;
    z-index: 30;
  }

  .sheet-hitbox {
    position: absolute;
    inset: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    padding: 0;
  }

  .participant-sheet,
  .confirm-modal {
    position: relative;
    z-index: 1;
    margin: 0.8rem;
    padding: 0.9rem;
  }

  .participant-sheet header h4,
  .confirm-modal h4 {
    margin: 0;
  }

  .participant-sheet header p,
  .confirm-modal p {
    margin: 0.3rem 0 0;
    color: #677084;
  }

  .participant-sheet .place-label-line {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 0.5rem;
    padding: 0.28rem 0.5rem;
    border-radius: 999px;
    border: 1px solid rgba(45, 79, 142, 0.2);
    background: rgba(36, 88, 198, 0.1);
    color: #1f3f86;
    font-size: 0.76rem;
    line-height: 1.2;
  }

  .participant-sheet .place-label-line span {
    color: #5c6f95;
    font-weight: 600;
  }

  .quick-actions {
    margin-top: 0.8rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
  }

  .quick-actions button {
    background: rgba(38, 61, 112, 0.12);
    color: inherit;
  }

  .quick-actions .alert-leader-btn {
    background: var(--accent-main);
    color: #ffe8f1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font-weight: 660;
  }

  .quick-actions .alert-leader-icon {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 999px;
    display: grid;
    place-items: center;
    background: rgba(255, 210, 226, 0.32);
    color: #fff9fc;
    font-size: 0.78rem;
    line-height: 1;
    box-shadow: inset 0 0 0 1px rgba(255, 244, 248, 0.24);
  }

  .confirm-modal blockquote {
    margin: 0.7rem 0;
    border-left: 3px solid var(--accent-main);
    padding-left: 0.6rem;
    color: #33435f;
  }

  .confirm-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.55rem;
  }

  .toast {
    position: fixed;
    left: 50%;
    bottom: 1rem;
    transform: translateX(-50%);
    width: min(92vw, 420px);
    border-radius: 14px;
    padding: 0.62rem 0.75rem;
    z-index: 45;
    font-size: 0.85rem;
    border: 1px solid rgba(42, 63, 102, 0.2);
    background: white;
    box-shadow: 0 10px 30px rgba(9, 19, 44, 0.2);
  }

  .toast.error {
    border-color: rgba(191, 34, 34, 0.4);
    background: #fff1f1;
  }

  .toast.success {
    border-color: rgba(27, 138, 77, 0.35);
    background: #f0fff5;
  }

  :global(body.theme-neo) {
    --neo-blue: #a2d2ff;
    --neo-green: #caffbf;
    --neo-yellow: #fdffb6;
    --neo-orange: #ffd6a5;
    --neo-pink: #ffadad;
    --neo-purple: #bdb2ff;
    --neo-red: #e63946;
    --neo-black: #000;
    --neo-white: #fff;

    background: var(--neo-yellow);
    color: var(--neo-black);
    font-family: 'Inter', 'Work Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  :global(body.theme-neo) .card {
    border: 4px solid var(--neo-black);
    border-radius: 0;
    background: var(--neo-white);
    box-shadow: 8px 8px 0 var(--neo-black);
  }

  :global(body.theme-neo) .join-card,
  :global(body.theme-neo) .map-panel,
  :global(body.theme-neo) .panel {
    background: var(--neo-white);
  }

  :global(body.theme-neo) .join-header h1,
  :global(body.theme-neo) .topbar h2,
  :global(body.theme-neo) .panel-head h3,
  :global(body.theme-neo) .participant-sheet header h4,
  :global(body.theme-neo) .confirm-modal h4 {
    font-family: 'Archivo Black', 'Space Grotesk', 'Arial Black', sans-serif;
    text-transform: uppercase;
    letter-spacing: -0.02em;
    line-height: 0.92;
  }

  :global(body.theme-neo) .eyebrow,
  :global(body.theme-neo) .subtle,
  :global(body.theme-neo) label,
  :global(body.theme-neo) .join-header p,
  :global(body.theme-neo) .panel-head p,
  :global(body.theme-neo) .timeline-card p,
  :global(body.theme-neo) .participant-sheet header p,
  :global(body.theme-neo) .confirm-modal p,
  :global(body.theme-neo) .chat-item p {
    color: #1d1d1d;
    font-weight: 700;
  }

  :global(body.theme-neo) button,
  :global(body.theme-neo) .upload-btn {
    border: 4px solid var(--neo-black);
    border-radius: 0;
    background: var(--neo-red);
    color: var(--neo-white);
    font-family: 'Archivo Black', 'Space Grotesk', 'Arial Black', sans-serif;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.01em;
    box-shadow: 6px 6px 0 var(--neo-black);
    transition: all 0.1s ease;
  }

  :global(body.theme-neo) button:hover,
  :global(body.theme-neo) .upload-btn:hover {
    transform: translate(3px, 3px);
    box-shadow: 3px 3px 0 var(--neo-black);
  }

  :global(body.theme-neo) button:active,
  :global(body.theme-neo) .upload-btn:active {
    transform: translate(6px, 6px);
    box-shadow: none;
  }

  :global(body.theme-neo) button:disabled,
  :global(body.theme-neo) .upload-btn:has(input:disabled) {
    opacity: 0.6;
    transform: none;
    box-shadow: 6px 6px 0 var(--neo-black);
  }

  :global(body.theme-neo) .join-mode-toggle button {
    background: var(--neo-white);
    color: #2b2b2b;
    border: 3px solid var(--neo-black);
    box-shadow: 2px 2px 0 var(--neo-black);
    opacity: 0.68;
    transform: none;
  }

  :global(body.theme-neo) .join-mode-toggle button:not(.active):hover {
    transform: translate(1px, 1px);
    box-shadow: 1px 1px 0 var(--neo-black);
  }

  :global(body.theme-neo) .join-mode-toggle button.active {
    border: 4px solid var(--neo-black);
    box-shadow: 6px 6px 0 var(--neo-black);
    opacity: 1;
  }

  :global(body.theme-neo) .join-mode-toggle button.join-option.active {
    background: var(--neo-red);
    color: var(--neo-white);
  }

  :global(body.theme-neo) .join-mode-toggle button.signin-option.active {
    background: var(--neo-red);
    color: var(--neo-white);
  }

  :global(body.theme-neo) .join-mode-toggle button.active::after {
    content: '✓';
    position: absolute;
    top: 4px;
    right: 7px;
    font-size: 0.7rem;
    line-height: 1;
  }

  :global(body.theme-neo) .tabbar button,
  :global(body.theme-neo) .mode-toggle button {
    background: var(--neo-white);
    color: var(--neo-black);
    border: 3px solid var(--neo-black);
    box-shadow: 2px 2px 0 var(--neo-black);
    opacity: 0.72;
    transform: none;
  }

  :global(body.theme-neo) .tabbar button:not(.active):hover,
  :global(body.theme-neo) .mode-toggle button:not(.active):hover {
    transform: translate(1px, 1px);
    box-shadow: 1px 1px 0 var(--neo-black);
    opacity: 0.9;
  }

  :global(body.theme-neo) .tabbar button.active,
  :global(body.theme-neo) .mode-toggle button.active {
    background: var(--neo-red);
    color: var(--neo-white);
    border: 4px solid var(--neo-black);
    box-shadow: 6px 6px 0 var(--neo-black);
    opacity: 1;
  }

  :global(body.theme-neo) .ghost,
  :global(body.theme-neo) .small,
  :global(body.theme-neo) .chip-filter,
  :global(body.theme-neo) .chip-scroll-btn,
  :global(body.theme-neo) .quick-actions button {
    background: var(--neo-white);
    color: var(--neo-black);
  }

  :global(body.theme-neo) .danger,
  :global(body.theme-neo) .quick-actions .alert-leader-btn {
    background: var(--neo-red);
    color: var(--neo-white);
  }

  :global(body.theme-neo) .danger-outline {
    border: 4px solid var(--neo-black);
    background: var(--neo-orange);
    color: var(--neo-black);
  }

  :global(body.theme-neo) input,
  :global(body.theme-neo) textarea,
  :global(body.theme-neo) .join-notes,
  :global(body.theme-neo) .status-banner,
  :global(body.theme-neo) .timeline-card,
  :global(body.theme-neo) .chat-item,
  :global(body.theme-neo) .location-sharing,
  :global(body.theme-neo) .toast,
  :global(body.theme-neo) .map-empty,
  :global(body.theme-neo) .empty-state,
  :global(body.theme-neo) .participant-avatar,
  :global(body.theme-neo) .participant-chip.active,
  :global(body.theme-neo) .participant-chip.stopped,
  :global(body.theme-neo) .participant-stop-badge,
  :global(body.theme-neo) .stop-intel,
  :global(body.theme-neo) .stop-intel-item,
  :global(body.theme-neo) .status-feed,
  :global(body.theme-neo) .stop-feed-item,
  :global(body.theme-neo) .participant-sheet .place-label-line,
  :global(body.theme-neo) .alert-preview,
  :global(body.theme-neo) .avatar-wrap,
  :global(body.theme-neo) .tabbar button.active,
  :global(body.theme-neo) .mode-toggle button.active,
  :global(body.theme-neo) .severity-grid button.active,
  :global(body.theme-neo) .chip-filter.active,
  :global(body.theme-neo) .timeline-dot,
  :global(body.theme-neo) .timeline-dot.done,
  :global(body.theme-neo) .toast.error,
  :global(body.theme-neo) .toast.success {
    border: 3px solid var(--neo-black);
    border-radius: 0;
    box-shadow: 4px 4px 0 var(--neo-black);
  }

  :global(body.theme-neo) input,
  :global(body.theme-neo) textarea {
    background: var(--neo-white);
    color: var(--neo-black);
    font-weight: 700;
  }

  :global(body.theme-neo) input:focus,
  :global(body.theme-neo) textarea:focus {
    box-shadow: 6px 6px 0 var(--neo-blue);
  }

  :global(body.theme-neo) .join-notes,
  :global(body.theme-neo) .status-banner,
  :global(body.theme-neo) .location-sharing,
  :global(body.theme-neo) .toast,
  :global(body.theme-neo) .participant-sheet,
  :global(body.theme-neo) .confirm-modal {
    background: var(--neo-white);
    color: var(--neo-black);
  }

  :global(body.theme-neo) .join-notes {
    background: var(--neo-purple);
  }

  :global(body.theme-neo) .stop-intel {
    background: #ffe7ea;
  }

  :global(body.theme-neo) .stop-intel-item {
    background: var(--neo-white);
  }

  :global(body.theme-neo) .status-feed {
    background: #fff7d6;
  }

  :global(body.theme-neo) .stop-feed-item.stopped {
    background: #ffd5db;
  }

  :global(body.theme-neo) .stop-feed-item.moving {
    background: #d9ffe1;
  }

  :global(body.theme-neo) .participant-chip.stopped,
  :global(body.theme-neo) .participant-stop-badge {
    background: #ffd5db;
    color: #4a0404;
  }

  :global(body.theme-neo) .topbar {
    background: var(--neo-blue);
  }

  :global(body.theme-neo) .tabbar {
    background: var(--neo-peach, #ffc6ff);
  }

  :global(body.theme-neo) .map-panel,
  :global(body.theme-neo) .panel {
    background: var(--neo-orange);
  }

  :global(body.theme-neo) .map-canvas {
    border: 4px solid var(--neo-black);
    border-radius: 0;
    background: var(--neo-blue);
  }

  :global(body.theme-neo) .participant-row {
    gap: 0.34rem;
  }

  :global(body.theme-neo) .participant-avatar {
    background: var(--neo-white);
    border: 3px solid var(--neo-black);
  }

  :global(body.theme-neo) .participant-avatar.leader {
    box-shadow: 0 0 0 3px var(--neo-red);
  }

  :global(body.theme-neo) .participant-status-dot {
    border: 2px solid var(--neo-black);
    box-shadow: none;
  }

  :global(body.theme-neo) .sheet-backdrop {
    background: rgba(0, 0, 0, 0.45);
  }

  :global(body.theme-neo) .confirm-modal blockquote {
    border-left: 4px solid var(--neo-black);
    background: var(--neo-yellow);
    padding: 0.5rem 0.6rem;
    box-shadow: 4px 4px 0 var(--neo-black);
  }

  :global(body.theme-neo) .toast.error {
    background: #ffd9d9;
  }

  :global(body.theme-neo) .toast.success {
    background: #d9ffe1;
  }

  @media (max-width: 380px) {
    .split-fields,
    .quick-actions,
    .confirm-actions,
    .severity-grid {
      grid-template-columns: 1fr;
    }

    .profile-card {
      grid-template-columns: 1fr;
    }
  }
</style>
