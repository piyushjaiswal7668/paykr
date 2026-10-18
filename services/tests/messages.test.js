import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { Messages } from '../messages-sidecar/src/service.js';
import { extractMessages } from '../messages-sidecar/src/browser.js';

const merchant = '11111111-1111-4111-8111-111111111111';
const other = '22222222-2222-4222-8222-222222222222';
function fixture(t, enabled = true) {
  const dataDir = mkdtempSync(path.join(tmpdir(), 'paykr-msg-'));
  const forwarded = [];
  const browsers = [];
  const service = new Messages({ dataDir, enabled, forward: async data => forwarded.push(data), browser: dir => {
    const browser = { dir, launched: 0, rows: [], url: 'https://messages.google.com/web/authentication', launch: async () => { browser.launched++; }, targets: async () => [{ type: 'page', url: browser.url, id: 'tab', webSocketDebuggerUrl: 'ws://127.0.0.1' }], background: async () => {}, foreground: async () => {}, evaluate: async () => browser.rows, stop: async () => {} };
    browsers.push(browser); return browser;
  } });
  t.after(async () => { await service.stop(); rmSync(dataDir, { recursive: true, force: true }); });
  return { service, forwarded, browsers };
}
test('profiles isolate merchants, pairing state and signed observation payloads', async t => {
  const { service, browsers, forwarded } = fixture(t);
  assert.equal((await service.route('POST', `/profiles/${merchant}/pair`)).status, 'AWAITING_PAIRING');
  await service.route('POST', `/profiles/${other}/pair`);
  assert.notEqual(browsers[0].dir, browsers[1].dir);
  const row = { sender: 'VM-HDFCBK', rawText: 'INR 100 credited. UTR 123456789012', observedAt: new Date().toISOString() };
  browsers[0].url = 'https://messages.google.com/web/conversations/1';
  browsers[0].rows = [row, { rawText: '100' }, { ...row, observedAt: '2000-01-01' }];
  const state = await service.route('POST', `/profiles/${merchant}/sweep`);
  assert.equal(state.status, 'CONNECTED'); assert.equal(state.messagesObserved, 1);
  assert.equal(forwarded[0].sender, row.sender); assert.equal(forwarded[0].rawText, row.rawText);
  assert.equal(forwarded[0].merchantId, merchant); assert.equal(forwarded[0].source, 'GOOGLE_MESSAGES');
  assert.match(forwarded[0].messageHash, /^[a-f0-9]{64}$/);
  await service.route('POST', `/profiles/${merchant}/sweep`);
  assert.equal(forwarded.length, 1);
  assert.equal((await service.route('GET', `/profiles/${other}/status`)).isPaired, false);
  browsers[0].targets = async () => { throw new Error('down'); };
  assert.equal((await service.route('POST', `/profiles/${merchant}/sweep`)).status, 'STANDBY');
});
test('failed forwards retry and hostile targets, unknown senders, stale messages fail closed', async t => {
  const { service, browsers } = fixture(t);
  const p = service.profile(merchant);
  const row = { sender: 'BANK', rawText: 'credited INR 100 UTR 123456789012', observedAt: new Date().toISOString() };
  let calls = 0;
  service.forward = async () => { calls++; if (calls === 1) throw new Error('down'); };
  browsers[0].url = 'https://messages.google.com.evil.test/web';
  browsers[0].rows = [row];
  await service.sweep(p); assert.equal(calls, 0);
  browsers[0].url = 'https://messages.google.com/web/conversations/1';
  await service.sweep(p); assert.equal(p.seen.size, 0);
  await service.sweep(p); assert.equal(p.seen.size, 1); assert.equal(calls, 2);
  p.lastSeenAt = '2000-01-01'; assert.equal(service.status(p).status, 'STANDBY');
  await assert.rejects(service.route('POST', '/profiles/../pair'), { status: 422 });
  await assert.rejects(service.route('GET', `/profiles/${merchant}/pair`), { status: 404 });
});
test('disabled service never launches Chrome or forwards messages', async t => {
  const { service, browsers, forwarded } = fixture(t, false);
  assert.equal((await service.route('POST', `/profiles/${merchant}/pair`)).status, 'STANDBY');
  await service.route('POST', `/profiles/${merchant}/sweep`);
  assert.equal(browsers[0].launched, 0); assert.equal(forwarded.length, 0);
});
test('DOM extraction preserves actual sender and full text, omitting missing time/sender', async () => {
  const row = { matches: () => false, getAttribute: name => ({ 'data-sender': 'VM-BANK', 'data-timestamp': '1792300000000' })[name], querySelector: selector => selector === '.outgoing' ? null : { textContent: 'INR 100 credited UTR 123456789012', getAttribute: () => null } };
  globalThis.document = { querySelector: () => null, querySelectorAll: selector => selector === 'mws-message-wrapper' ? [row] : [] };
  try {
    assert.equal((await extractMessages())[0].sender, 'VM-BANK');
    row.getAttribute = () => null;
    assert.deepEqual(await extractMessages(), []);
  } finally { delete globalThis.document; }
});

test('welcome and authentication pages never report a paired inbox', async t => {
  const { service, browsers } = fixture(t);
  const p = service.profile(merchant);
  let foreground = 0;
  browsers[0].foreground = async () => { foreground++; };
  for (const url of ['https://messages.google.com/web/welcome', 'https://messages.google.com/web/authentication', 'https://accounts.google.com/signin']) {
    browsers[0].url = url;
    p.mode = 'BACKGROUND'; p.isPaired = true;
    const state = await service.sweep(p);
    assert.equal(state.isPaired, false);
    assert.equal(state.status, 'AWAITING_PAIRING');
  }
  assert.equal(foreground, 3);
});
