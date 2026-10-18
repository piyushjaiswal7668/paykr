import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { mkdtempSync, rmSync, mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { WhatsApp } from '../whatsapp-sidecar/src/service.js';

function fixture(t, testing = false) {
  const dataDir = mkdtempSync(path.join(tmpdir(), 'paykr-wa-'));
  class Client extends EventEmitter {
    constructor() { super(); this.socket = { sock: { user: { id: '919876543210:1@s.whatsapp.net' } } }; }
    async connectWithQr() { this.emit('auth.qr', { value: 'raw-whatsapp-qr' }); }
    async connectWithPairingCode(phoneNumber) { this.emit('auth.pairing_code', { code: '12345678', phoneNumber }); }
    async disconnect() {}
    async logout() { this.loggedOut = true; }
    async sendText(to, text) { this.sent = { to, text }; return { id: 'receipt' }; }
  }
  const service = new WhatsApp({ dataDir, testing, load: async () => ({ BerryProtocol: Client, qrcode: { toDataURL: async raw => 'data:image/png;base64,' + Buffer.from(raw).toString('base64') } }) });
  t.after(async () => { await service.stop(); rmSync(dataDir, { recursive: true, force: true }); });
  return service;
}
test('QR, pairing, connected status, branded OTP and logout use SDK events', async t => {
  const wa = fixture(t);
  assert.equal((await wa.connect()).status, 'QR_READY');
  assert.match(wa.status().qrCode, /^data:image/);
  assert.equal((await wa.pairing('9876543210')).pairingCode, '12345678');
  wa.client.emit('connection.open');
  assert.equal(wa.status().phoneNumber, '919876543210');
  assert.equal(wa.status().qrCode, null);
  const result = await wa.route('POST', '/send-otp', { to: '9876543210', code: '123456', name: 'Alex', appName: 'Store' });
  assert.equal(result.delivered, true);
  assert.deepEqual(wa.client.sent, { to: '919876543210@s.whatsapp.net', text: '🔐 *Store Verification Code*\n\nHello *Alex*! Your one-time login OTP is: *123456*\n\n⏱ _Valid for 5 minutes. Do not share this code with anyone._\n\n— *Store Secure Payments*' });
  const client = wa.client;
  mkdirSync(path.join(wa.dataDir, 'auth'), { recursive: true });
  writeFileSync(path.join(wa.dataDir, 'auth/creds.json'), '{}');
  await wa.disconnect();
  assert.equal(client.loggedOut, true);
  assert.equal(existsSync(path.join(wa.dataDir, 'auth')), false);
  client.emit('connection.open');
  assert.equal(wa.status().status, 'DISCONNECTED');
});
test('disconnected real sends remain undelivered; dummy and testing sends are simulated', async t => {
  const wa = fixture(t);
  assert.equal((await wa.send('9876543210', 'hello')).delivered, false);
  for (const number of ['910000000000', '0000000000', '919999999999']) assert.equal((await wa.send(number, 'test')).simulated, true);
  assert.equal((await fixture(t, true).send('9876543210', 'test')).simulated, true);
  await assert.rejects(wa.send('../escape', 'hello'), { status: 422 });
  await assert.rejects(wa.route('POST', '/send-otp', { to: '9876543210', code: 'bad' }), { status: 422 });
  await assert.rejects(wa.route('GET', '/send', {}), { status: 404 });
});
test('saved credentials trigger reconnection and broken sockets never claim delivery', async t => {
  const wa = fixture(t);
  mkdirSync(path.join(wa.dataDir, 'auth/paykr'), { recursive: true });
  writeFileSync(path.join(wa.dataDir, 'auth/paykr/creds.json'), JSON.stringify({ registered: true }));
  assert.equal((await wa.send('9876543210', 'hello')).delivered, false);
  assert.ok(wa.client);
  wa.client.emit('connection.open');
  wa.client.sendText = async () => { throw new Error('socket closed'); };
  assert.equal((await wa.send('9876543210', 'hello')).delivered, false);
  await wa.initializing;
});
test('status wait times out without leaking listeners', async t => {
  const wa = fixture(t);
  assert.equal((await wa.waitFor(['CONNECTED'], 1)).status, 'DISCONNECTED');
  assert.equal(wa.listenerCount('status'), 0);
});
test('late QR rendering cannot overwrite connected or phone-pairing state', async t => {
  const wa = fixture(t);
  await wa.connect();
  wa.client.emit('auth.qr', { value: 'new-qr' });
  wa.client.emit('auth.pairing_code', { code: '12345678', phoneNumber: '919876543210' });
  await Promise.resolve();
  assert.equal(wa.status().status, 'PAIRING_CODE_READY');
  assert.equal(wa.status().qrCode, null);
  wa.client.emit('auth.qr', { value: 'newer-qr' });
  wa.client.emit('connection.open');
  await Promise.resolve();
  assert.equal(wa.status().status, 'CONNECTED');
  assert.equal(wa.status().qrCode, null);
});

test('incomplete pairing credentials never trigger saved-session reconnect', async t => {
  const wa = fixture(t);
  mkdirSync(path.join(wa.dataDir, 'auth/paykr'), { recursive: true });
  const file = path.join(wa.dataDir, 'auth/paykr/creds.json');
  for (const contents of ['{', '{}', '{"registered":false}']) {
    writeFileSync(file, contents);
    assert.equal(wa.hasCredentials(), false);
    assert.equal((await wa.send('9876543210', 'hello')).delivered, false);
    assert.equal(wa.client, null);
  }
  await wa.connect();
  wa.client.emit('connection.close', { reason: 'logged_out' });
  assert.equal(wa.status().lastDisconnectReason, 'logged_out');
  wa.client.emit('connection.close', { reason: 'raw diagnostic containing a secret' });
  assert.equal(wa.status().lastDisconnectReason, 'connection_closed');
});

test('a closed socket during logout does not block fresh pairing or credential cleanup', async t => {
  const wa = fixture(t);
  await wa.connect();
  const oldClient = wa.client;
  oldClient.logout = async () => { throw new Error('Connection Closed'); };
  mkdirSync(path.join(wa.dataDir, 'auth/paykr'), { recursive: true });
  writeFileSync(path.join(wa.dataDir, 'auth/paykr/creds.json'), '{"registered":true}');
  const state = await wa.pairing('9876543210');
  assert.equal(state.status, 'PAIRING_CODE_READY');
  assert.notEqual(wa.client, oldClient);
  assert.equal(existsSync(path.join(wa.dataDir, 'auth/paykr/creds.json')), false);
  oldClient.emit('connection.open');
  assert.equal(wa.status().status, 'PAIRING_CODE_READY');
});
