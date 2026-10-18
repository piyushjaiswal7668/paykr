import { EventEmitter } from 'node:events';
import { readFileSync, mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { required } from '../../shared/http.js';

// Port of whatsapp-berry.service.js; the installed SDK owns reconnects and credentials.
export class WhatsApp extends EventEmitter {
  constructor({ dataDir, load, testing = process.env.APP_ENV === 'testing' }) {
    super();
    this.dataDir = dataDir;
    this.load = load;
    this.testing = testing;
    this.client = null;
    this.initializing = null;
    this.state = { status: 'DISCONNECTED', phoneNumber: null, platform: 'BerryProtocol 0.1.10', sessionId: 'paykr', isConnected: false, qrCode: null, pairingCode: null, pairingPhone: null, lastDisconnectReason: null };
  }
  unbind() { for (const [event, listener] of this.sdkListeners || []) this.client?.off(event, listener); this.sdkListeners = []; }
  status() { return { ...this.state }; }
  update(values) {
    Object.assign(this.state, values);
    this.state.isConnected = this.state.status === 'CONNECTED';
    this.emit('status', this.status());
  }
  hasCredentials() {
    for (const file of ['auth/paykr/creds.json', 'auth/creds.json']) {
      try { if (JSON.parse(readFileSync(path.join(this.dataDir, file), 'utf8')).registered === true) return true; } catch { /* Missing or incomplete credentials are not a linked session. */ }
    }
    return false;
  }
  async initialize(phone = null) {
    if (this.initializing) return this.initializing;
    this.initializing = this.start(phone).finally(() => { this.initializing = null; });
    return this.initializing;
  }
  async start(phone) {
    if (this.client) { this.unbind(); await this.client.disconnect(); }
    mkdirSync(this.dataDir, { recursive: true, mode: 0o700 });
    const { BerryProtocol, qrcode, logger } = await this.load();
    const client = new BerryProtocol({ sessionId: 'paykr', logger, authFolder: path.join(this.dataDir, 'auth'), databasePath: path.join(this.dataDir, 'store.db'), printQrInTerminal: false, reconnectDelayMs: 3000, reconnectMaxAttempts: 15 });
    this.client = client;
    this.update({ status: 'CONNECTING', qrCode: null, pairingCode: null, pairingPhone: phone, lastDisconnectReason: null });
    const qr = async value => {
      try {
        const raw = typeof value === 'string' ? value : value?.value;
        if (!raw || this.client !== client) return;
        const qrCode = await qrcode.toDataURL(raw, { width: 320, margin: 2 });
        if (this.client === client && !this.state.isConnected && !this.state.pairingCode) this.update({ status: 'QR_READY', qrCode });
      } catch { this.update({ status: 'DISCONNECTED' }); }
    };
    const on = (event, listener) => { (this.sdkListeners ||= []).push([event, listener]); client.on(event, listener); };
    on('auth.qr', qr);
    on('qr', qr);
    on('auth.pairing_code', ({ code, phoneNumber }) => this.update({ status: 'PAIRING_CODE_READY', qrCode: null, pairingCode: code, pairingPhone: phoneNumber }));
    on('connection.open', () => {
      const id = client.socket?.sock?.user?.id;
      this.update({ status: 'CONNECTED', phoneNumber: id ? id.split(':')[0].split('@')[0] : null, qrCode: null, pairingCode: null, pairingPhone: null });
    });
    on('connection.close', ({ reason } = {}) => this.update({ status: 'DISCONNECTED', qrCode: null, pairingCode: null, lastDisconnectReason: ['logged_out', 'restart_required', 'connection_closed'].includes(reason) ? reason : 'connection_closed' }));
    on('protocol.error', () => this.update({ status: 'DISCONNECTED' }));
    const connection = phone ? client.connectWithPairingCode(phone) : client.connectWithQr();
    Promise.resolve(connection).catch(() => this.update({ status: 'DISCONNECTED' }));
  }
  waitFor(statuses, timeout) {
    if (statuses.includes(this.state.status)) return Promise.resolve(this.status());
    return new Promise(resolve => {
      const finish = () => { clearTimeout(timer); this.off('status', listener); resolve(this.status()); };
      const listener = state => { if (statuses.includes(state.status)) finish(); };
      const timer = setTimeout(finish, timeout);
      this.on('status', listener);
    });
  }
  async connect() {
    if (!this.client || this.state.status === 'DISCONNECTED') await this.initialize();
    return this.waitFor(['QR_READY', 'CONNECTED', 'PAIRING_CODE_READY'], 8000);
  }
  async pairing(phone) {
    required(phone, 15, /^\d{10,15}$/);
    await this.disconnect();
    await this.initialize(phone.length === 10 ? '91' + phone : phone);
    const state = await this.waitFor(['PAIRING_CODE_READY', 'CONNECTED'], 9000);
    if (state.status === 'CONNECTING') throw new Error('Pairing timeout');
    return state;
  }
  async disconnect() {
    if (this.initializing) await this.initializing;
    const client = this.client;
    if (client) {
      this.unbind();
      try { await client.logout(); } catch {
        // An already-closed socket cannot revoke remotely. Still clear local credentials;
        // the operator can remove any remaining linked device from the sender phone.
      } finally { await client.disconnect(); this.client = null; }
    }
    // Only remove the dedicated auth/store files, never the caller's data directory.
    for (const name of ['auth', 'store.db', 'store.db-wal', 'store.db-shm', 'session.json']) rmSync(path.join(this.dataDir, name), { force: true, recursive: true });
    this.update({ status: 'DISCONNECTED', phoneNumber: null, qrCode: null, pairingCode: null, pairingPhone: null });
    return this.status();
  }
  async stop() {
    if (this.initializing) await this.initializing;
    if (this.client) { this.unbind(); await this.client.disconnect(); this.client = null; }
  }
  async send(to, message) {
    required(to, 15, /^\d{10,15}$/); required(message, 8192);
    if (this.testing || ['910000000000', '0000000000', '919999999999'].some(prefix => to.startsWith(prefix))) {
      return { success: true, delivered: true, simulated: true, id: randomUUID(), status: this.state.status };
    }
    if ((!this.client || !this.state.isConnected) && this.hasCredentials()) await this.initialize();
    if (!this.client || !this.state.isConnected) return { success: false, delivered: false, queued: true, status: this.state.status, message: 'WhatsApp is not connected. Retry after connection.' };
    try {
      const result = await this.client.sendText(`${to.length === 10 ? '91' + to : to}@s.whatsapp.net`, message);
      return { success: true, delivered: true, id: result.id, status: this.state.status };
    } catch {
      this.update({ status: 'DISCONNECTED' });
      void this.initialize().catch(() => {});
      return { success: false, delivered: false, queued: true, status: this.state.status, message: 'Connection interrupted. Retry after connection.' };
    }
  }
  async route(method, route, body) {
    if (method === 'GET' && route === '/status') return this.status();
    if (method === 'POST') {
      if (route === '/connect') return this.connect();
      if (route === '/pairing-code') return this.pairing(body.phoneNumber);
      if (route === '/disconnect') return this.disconnect();
      if (route === '/send') return this.send(body.to, body.message);
      if (route === '/send-otp') {
        const code = required(body.code, 6, /^\d{6}$/);
        const name = required(body.name || 'Customer', 100);
        const appName = required(body.appName || 'Paykr', 100);
        return this.send(body.to, `🔐 *${appName} Verification Code*\n\nHello *${name}*! Your one-time login OTP is: *${code}*\n\n⏱ _Valid for 5 minutes. Do not share this code with anyone._\n\n— *${appName} Secure Payments*`);
      }
    }
    throw Object.assign(new Error(), { status: 404 });
  }
}
