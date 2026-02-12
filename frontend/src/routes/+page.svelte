<script lang="ts">
  import { onMount } from 'svelte';
  import { api } from '$lib/api';

  type Tab = 'map' | 'waypoints' | 'chat' | 'alert' | 'profile';
  type ComposerMode = 'chat' | 'prayer';
  type ThemeMode = 'day' | 'night';

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
        is_leader: boolean;
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
  const THEME_KEY = 'caravan_theme_mode';

  let appReady = false;
  let inRetreat = false;
  let joining = false;
  let loadingData = false;
  let refreshing = false;
  let leaving = false;
  let uploadBusy = false;

  let activeTab: Tab = 'map';
  let composerMode: ComposerMode = 'chat';
  let themeMode: ThemeMode = 'day';
  let online = true;

  let joinCode = '';
  let joinName = '';
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

  let profileVehicleColor = '';
  let profileVehicleDescription = '';

  let errorMessage = '';
  let statusMessage = '';
  let queueStatus = '';

  let refreshTimer: ReturnType<typeof setInterval> | null = null;

  const inAppTabs: Array<{ id: Tab; label: string; icon: string }> = [
    { id: 'map', label: 'Map', icon: '🗺️' },
    { id: 'waypoints', label: 'Plan', icon: '📍' },
    { id: 'chat', label: 'Chat', icon: '💬' },
    { id: 'alert', label: 'Alert', icon: '🚨' },
    { id: 'profile', label: 'Profile', icon: '👤' }
  ];

  $: onlineCount = participants.filter((p) => (p.last_seen_seconds_ago ?? 9999) < 300).length;
  $: mapRows = participants.filter((p) => p.location !== null);
  $: canSendAlert = myParticipant?.is_leader === true;
  $: queuedCount = queuedMessages.length;

  $: if (themeMode === 'night' && typeof document !== 'undefined') {
    document.body.classList.add('theme-night');
  } else if (typeof document !== 'undefined') {
    document.body.classList.remove('theme-night');
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

  function markerCoords(row: ParticipantLocationRow, index: number): { x: number; y: number } {
    if (!row.location || mapRows.length === 0) {
      return { x: 18 + (index % 4) * 22, y: 25 + Math.floor(index / 4) * 22 };
    }

    const lats = mapRows.map((item) => item.location!.lat);
    const lngs = mapRows.map((item) => item.location!.lng);

    const minLat = Math.min(...lats);
    const maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs);
    const maxLng = Math.max(...lngs);

    const latSpan = Math.max(maxLat - minLat, 0.003);
    const lngSpan = Math.max(maxLng - minLng, 0.003);

    const nx = (row.location.lng - minLng) / lngSpan;
    const ny = (row.location.lat - minLat) / latSpan;

    return {
      x: 10 + nx * 80,
      y: 82 - ny * 66
    };
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
          code: normalizeCode(joinCode),
          name: joinName.trim(),
          vehicle_color: joinVehicleColor.trim() || null,
          vehicle_description: joinVehicleDescription.trim() || null
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
      waypoints = [...waypointsPayload.data].sort((a, b) => a.order - b.order);
      messages = messagesPayload.data;

      const me = participants.find((p) => p.is_current_user);
      if (me) {
        profileVehicleColor = me.vehicle_color ?? profileVehicleColor;
        profileVehicleDescription = me.vehicle_description ?? profileVehicleDescription;
      }

      inRetreat = true;
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
      waypoints = [...waypointsPayload.data].sort((a, b) => a.order - b.order);
      messages = messagesPayload.data;

      const me = participants.find((p) => p.is_current_user);
      if (me) {
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

  async function leaveRetreat(): Promise<void> {
    if (!deviceToken) return;
    leaving = true;

    try {
      await api('/leave', { method: 'POST' }, deviceToken);
      localStorage.removeItem(TOKEN_KEY);
      inRetreat = false;
      deviceToken = '';
      myParticipant = null;
      retreatInfo = null;
      participants = [];
      waypoints = [];
      messages = [];
      queuedMessages = [];
      showStatus('You have left the retreat.');
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Could not leave retreat.');
    } finally {
      leaving = false;
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
      is_leader: true,
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
        location: {
          lat: 36.612,
          lng: -93.287,
          accuracy: 9,
          speed: 17,
          heading: 112,
          recorded_at: nowIso
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

  onMount(async () => {
    online = navigator.onLine;

    const savedTheme = localStorage.getItem(THEME_KEY) as ThemeMode | null;
    if (savedTheme === 'day' || savedTheme === 'night') {
      themeMode = savedTheme;
    }

    const params = new URLSearchParams(window.location.search);
    const useDemo = params.get('demo') === '1';

    if (useDemo) {
      enableDemoMode();
    } else {
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
    }

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
        <h1>Join the Retreat</h1>
        <p>Enter your retreat code and basic details to sync with your group on the road.</p>
      </div>

      <form class="join-form" on:submit|preventDefault={joinRetreat}>
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
          Full name
          <input bind:value={joinName} maxlength="50" placeholder="e.g. Sarah Jenkins" required />
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

        <button type="submit" disabled={joining}>
          {joining ? 'Joining…' : 'Start the Journey'}
        </button>
      </form>

      <div class="join-footer">
        <button type="button" class="theme-toggle" on:click={() => {
          themeMode = themeMode === 'day' ? 'night' : 'day';
          localStorage.setItem(THEME_KEY, themeMode);
        }}>
          {themeMode === 'day' ? '🌙 Night mode' : '☀️ Day mode'}
        </button>
      </div>
    </section>
  </main>
{:else}
  <main class={`app-shell ${themeMode === 'night' ? 'night' : ''}`}>
    <header class="topbar card">
      <div>
        <p class="eyebrow">{retreatInfo?.name ?? 'Calvary Caravan'}</p>
        <h2>Convoy Control</h2>
        <p class="subtle">{onlineCount}/{participants.length} online · {retreatInfo?.participant_count ?? participants.length} total</p>
      </div>

      <div class="topbar-actions">
        <button type="button" class="ghost" on:click={() => {
          themeMode = themeMode === 'day' ? 'night' : 'day';
          localStorage.setItem(THEME_KEY, themeMode);
        }}>
          {themeMode === 'day' ? '🌙' : '☀️'}
        </button>
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
          <div class="map-grid"></div>
          <div class="map-road"></div>

          {#if mapRows.length === 0}
            <div class="map-empty">
              <strong>No live markers yet</strong>
              <p>As participants share location, they will appear here.</p>
            </div>
          {:else}
            {#each participants as row, index}
              {@const coords = markerCoords(row, index)}
              <button
                type="button"
                class={`marker ${row.is_current_user ? 'me' : ''}`}
                style={`left:${coords.x}%; top:${coords.y}%;`}
                on:click={() => openParticipant(row)}
              >
                <span>{row.is_leader ? '⭐' : '🚗'}</span>
                <small>{row.name.split(' ')[0]}</small>
              </button>
            {/each}
          {/if}
        </div>

        <div class="participant-row">
          {#each participants as row}
            <button type="button" class="pill" on:click={() => openParticipant(row)}>
              <span>{row.is_leader ? '⭐' : '👤'}</span>
              <span>{row.name}</span>
              <small>{formatAgo(row.last_seen_seconds_ago)}</small>
            </button>
          {/each}
        </div>
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
              Vehicle color
              <input bind:value={profileVehicleColor} placeholder="e.g. Silver" />
            </label>

            <label>
              Make/model
              <input bind:value={profileVehicleDescription} placeholder="e.g. Honda CR-V" />
            </label>
          </div>
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
          <button type="button" class="danger-outline" on:click={leaveRetreat} disabled={leaving}>{leaving ? 'Leaving…' : 'Leave retreat'}</button>
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
    --accent-night-text: #ffd2e2;

    margin: 0;
    background: #f4f3ef;
    color: #1f2430;
    font-family: 'Instrument Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  :global(body.theme-night) {
    background: #0b1734;
    color: #f5f8ff;
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

  :global(body.theme-night) .card,
  .app-shell.night .card {
    border-color: rgba(117, 150, 220, 0.2);
    background: rgba(11, 26, 58, 0.88);
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.28);
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

  :global(body.theme-night) .eyebrow,
  :global(body.theme-night) .subtle,
  .app-shell.night .eyebrow,
  .app-shell.night .subtle {
    color: #b9c8e8;
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

  :global(body.theme-night) input,
  :global(body.theme-night) textarea,
  .app-shell.night input,
  .app-shell.night textarea {
    background: rgba(10, 26, 59, 0.78);
    border-color: rgba(161, 187, 255, 0.25);
    color: #f2f6ff;
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

  .theme-toggle {
    width: 100%;
    margin-top: 0.5rem;
    background: rgba(44, 61, 103, 0.08);
    color: inherit;
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

  :global(body.theme-night) .tabbar button.active,
  .app-shell.night .tabbar button.active {
    color: var(--accent-night-text);
    background: var(--accent-soft-strong);
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
    background: linear-gradient(160deg, #b8e5f6, #dceff7 55%, #f8e8d8 100%);
  }

  .app-shell.night .map-canvas {
    border-color: rgba(136, 163, 236, 0.2);
    background: linear-gradient(170deg, #07132b, #0d2146 55%, #182f5a 100%);
  }

  .map-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(rgba(255, 255, 255, 0.25) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255, 255, 255, 0.25) 1px, transparent 1px);
    background-size: 28px 28px;
    opacity: 0.5;
  }

  .map-road {
    position: absolute;
    width: 72%;
    height: 280px;
    left: 14%;
    top: 20px;
    border: 4px solid rgba(143, 0, 48, 0.58);
    border-radius: 200px;
    border-left-color: rgba(143, 0, 48, 0.28);
    border-right-color: rgba(143, 0, 48, 0.28);
    transform: rotate(18deg);
    opacity: 0.55;
  }

  .marker {
    position: absolute;
    transform: translate(-50%, -50%);
    min-width: 52px;
    background: rgba(255, 255, 255, 0.95);
    color: #14223e;
    border: 2px solid var(--accent-main);
    border-radius: 14px;
    padding: 0.3rem 0.38rem;
    display: grid;
    justify-items: center;
    gap: 0.07rem;
    font-size: 0.7rem;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.16);
  }

  .app-shell.night .marker {
    background: rgba(11, 24, 54, 0.95);
    color: #e8efff;
    border-color: var(--accent-main-strong);
  }

  .marker.me {
    border-color: #1f9e59;
  }

  .marker small {
    font-size: 0.62rem;
    font-weight: 620;
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
  }

  .map-empty p,
  .empty-state p {
    margin: 0.22rem 0 0;
    font-size: 0.84rem;
  }

  .participant-row {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(160px, 1fr);
    gap: 0.55rem;
    overflow-x: auto;
    padding-bottom: 0.15rem;
  }

  .pill {
    background: rgba(39, 62, 113, 0.08);
    color: inherit;
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 0.35rem;
    text-align: left;
    border-radius: 14px;
  }

  .pill small {
    opacity: 0.75;
    font-size: 0.73rem;
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

  .app-shell.night .timeline-card {
    background: rgba(12, 27, 56, 0.72);
    border-color: rgba(128, 154, 224, 0.24);
  }

  .timeline-card h4 {
    margin: 0.05rem 0 0.2rem;
  }

  .timeline-card p {
    margin: 0;
    font-size: 0.84rem;
    color: #5e6779;
  }

  .app-shell.night .timeline-card p {
    color: #bdcae8;
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

  .app-shell.night .chat-item {
    border-color: rgba(126, 154, 230, 0.25);
    background: rgba(12, 28, 58, 0.75);
  }

  .chat-item.alert {
    border-color: rgba(191, 34, 34, 0.4);
    background: rgba(255, 234, 226, 0.84);
  }

  .app-shell.night .chat-item.alert {
    background: rgba(73, 23, 23, 0.72);
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
    color: var(--accent-night-text);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    font-weight: 650;
  }

  .quick-actions .alert-leader-icon {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 999px;
    display: grid;
    place-items: center;
    background: rgba(255, 210, 226, 0.24);
    color: #fff4f8;
    font-size: 0.78rem;
    line-height: 1;
  }

  :global(body.theme-night) .quick-actions .alert-leader-btn {
    background: var(--accent-main-strong);
    color: #ffe4ee;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
  }

  :global(body.theme-night) .quick-actions .alert-leader-icon {
    background: rgba(255, 228, 238, 0.2);
    color: #fff7fa;
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
