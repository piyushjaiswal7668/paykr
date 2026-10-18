import Pusher from 'pusher-js';

// Use a single state fetch at a time; collapse bursts without dropping a later update.
export function subscribeToUpdates({channelName, headers = {}, refresh, onConnection = (mode) => { void mode; }, config, intervalMs = 5000}) {
    let disposed = false, subscribed = false, pending = false, running = false, lastRefresh = 0;
    const reload = async () => {
        if (disposed) return;
        if (running) { pending = true; return; }
        running = true;
        try { await refresh(); } finally {
            running = false;
            lastRefresh = Date.now();
            if (pending) { pending = false; void reload(); }
        }
    };
    const fallback = () => { subscribed = false; onConnection('polling'); };
    let socket;
    if (config?.key && config?.host) {
        socket = new Pusher(config.key, {
            cluster: 'mt1', wsHost: config.host, wsPort: Number(config.port), wssPort: Number(config.port),
            forceTLS: Boolean(config.tls), enabledTransports: ['ws', 'wss'], disableStats: true,
            channelAuthorization: {endpoint: '/broadcasting/auth', transport: 'ajax', headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...headers,
            }},
        });
        const channel = socket.subscribe(channelName);
        channel.bind('pusher:subscription_succeeded', () => { subscribed = true; onConnection('live'); void reload(); });
        channel.bind('pusher:subscription_error', fallback);
        socket.connection.bind('state_change', ({current}) => { if (current !== 'connected') fallback(); });
        for (const name of ['PaymentStatusChanged', 'PaymentAccountBlocked', 'LivePaymentUpdated', 'CounterPaymentRequested', 'QueuePromoted']) channel.bind(name, reload);
    }
    fallback();
    void reload();
    const timer = setInterval(() => { if (!subscribed || Date.now() - lastRefresh >= 30000) void reload(); }, intervalMs);
    const visible = () => { if (document.visibilityState === 'visible') void reload(); };
    document.addEventListener('visibilitychange', visible);
    return () => { disposed = true; clearInterval(timer); document.removeEventListener('visibilitychange', visible); socket?.disconnect(); };
}

export async function startCheckout(root, config) {
    const id = root.dataset.checkoutId;
    const storageKey = `paykr.checkout.${id}`;
    let credentials;
    try { credentials = JSON.parse(sessionStorage.getItem(storageKey) || 'null'); } catch { /* Treat invalid saved data as absent. */ }
    credentials ??= {deviceId: crypto.randomUUID()};
    const headers = {Accept: 'application/json', 'X-Device-Id': credentials.deviceId};
    if (credentials.deviceToken) headers['X-Device-Token'] = credentials.deviceToken;
    if (credentials.customerToken) headers.Authorization = `Bearer ${credentials.customerToken}`;
    let stop;
    const refresh = async () => {
        try {
            const response = await fetch(`/api/v1/checkout/${encodeURIComponent(id)}/state`, {headers, cache: 'no-store', credentials: 'same-origin'});
            const state = await response.json();
            if (!response.ok) throw new Error(state.message || 'This payment cannot be opened on this device.');
            if (state.deviceToken) {
                credentials.deviceToken = state.deviceToken;
                headers['X-Device-Token'] = state.deviceToken;
            }
            sessionStorage.setItem(storageKey, JSON.stringify(credentials));
            root.querySelector('[data-payment-status]').textContent = state.status;
            root.querySelector('[data-payment-detail]').textContent = state.status === 'QUEUED' ? `Queue position: ${state.queuePosition}` : (state.isTerminal ? 'This payment session has ended.' : 'Updates appear here automatically.');
            root.querySelector('[data-payment-error]').textContent = '';
            return true;
        } catch (error) {
            root.querySelector('[data-payment-error]').textContent = error.message;
            return false;
        }
    };
    // Establish the device binding before authorizing its private channel.
    await refresh();
    stop = subscribeToUpdates({channelName: `private-payment.${id}`, headers, config, refresh,
        onConnection: (mode) => { root.querySelector('[data-connection-status]').textContent = mode === 'live' ? 'Live updates connected' : 'Checking for updates every 5 seconds'; },
    });
    window.addEventListener('pagehide', stop, {once: true});
}

const configElement = document.getElementById('paykr-realtime-config');
if (configElement) {
    const config = JSON.parse(configElement.textContent);
    const checkout = document.querySelector('[data-checkout-id]');
    if (checkout) void startCheckout(checkout, config);
    const merchant = document.querySelector('[data-merchant-live]');
    if (merchant) {
        const stop = subscribeToUpdates({channelName: `private-merchant.${merchant.dataset.merchantLive}.live`, config,
            refresh: async () => { window.Livewire?.dispatch('paykr-live-update'); },
            onConnection: (mode) => { const badge = document.querySelector('[data-merchant-connection]'); if (badge) badge.textContent = mode === 'live' ? 'Live syncing' : 'Polling every 5 seconds'; },
        });
        window.addEventListener('pagehide', stop, {once: true});
        document.addEventListener('livewire:navigating', stop, {once: true});
    }
}
