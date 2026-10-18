import { mkdirSync, existsSync, readdirSync, writeFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { Browser, extractMessages } from './browser.js';
import { required } from '../../shared/http.js';

const uuid = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
export class Messages {
  constructor({ dataDir, forward, browser = dir => new Browser(dir), enabled = false }) {
    Object.assign(this, { dataDir, forward, browser, enabled });
    this.profiles = new Map();
  }
  profile(id) {
    required(id, 36, uuid);
    if (!this.profiles.has(id)) {
      if (this.profiles.size >= 50) throw new Error('Profile capacity reached');
      const dir = path.join(this.dataDir, 'profiles', id);
      this.profiles.set(id, { id, dir, browser: this.browser(dir), isPaired: false, mode: 'STANDBY', messagesObserved: 0, lastSeenAt: null, lastMessageAt: null, seen: new Set(), busy: false });
    }
    return this.profiles.get(id);
  }
  status(p) {
    const fresh = p.lastSeenAt && Date.now() - Date.parse(p.lastSeenAt) < 300_000;
    return { isPaired: p.isPaired, status: p.isPaired && p.mode === 'BACKGROUND' && fresh ? 'CONNECTED' : p.mode === 'VISIBLE' ? 'AWAITING_PAIRING' : 'STANDBY', mode: p.mode, messagesObserved: p.messagesObserved, lastSeenAt: p.lastSeenAt, lastMessageAt: p.lastMessageAt };
  }
  async pair(p) {
    if (!this.enabled) return this.status(p);
    if (!p.starting) p.starting = p.browser.launch(false).then(() => { p.mode = 'VISIBLE'; }).finally(() => { p.starting = null; });
    await p.starting;
    return this.status(p);
  }
  async sweep(p, extract = true) {
    if (!this.enabled || p.busy) return this.status(p);
    p.busy = true;
    try {
      const targets = await p.browser.targets();
      const target = targets.find(t => {
        try { const url = new URL(t.url); return t.type === 'page' && url.origin === 'https://messages.google.com' && url.pathname.startsWith('/web'); } catch { return false; }
      });
      if (!target) {
        p.lastSeenAt = null; p.isPaired = false;
        const login = targets.find(t => t.type === 'page' && t.url.startsWith('https://accounts.google.com/'));
        if (login && p.mode === 'BACKGROUND') { await p.browser.foreground(login); p.mode = 'VISIBLE'; }
        return this.status(p);
      }
      if (!new URL(target.url).pathname.startsWith('/web/conversations')) {
        if (p.mode === 'BACKGROUND') await p.browser.foreground(target);
        rmSync(path.join(p.dir, 'paired'), { force: true });
        p.isPaired = false; p.mode = 'VISIBLE'; p.lastSeenAt = null;
        return this.status(p);
      }
      if (p.mode !== 'BACKGROUND') await p.browser.background(target);
      p.isPaired = true; p.mode = 'BACKGROUND';
      mkdirSync(p.dir, { recursive: true, mode: 0o700 });
      if (!existsSync(path.join(p.dir, 'paired'))) writeFileSync(path.join(p.dir, 'paired'), '', { mode: 0o600 });
      if (!extract) return this.status(p);
      const rows = await p.browser.evaluate(target, `(${extractMessages.toString()})()`);
      if (!Array.isArray(rows)) throw new Error('Invalid extraction');
      // Liveness means the extraction actually completed, not just that Chrome exists.
      p.lastSeenAt = new Date().toISOString();
      for (const row of rows.slice(-100)) {
        if (typeof row.sender !== 'string' || !row.sender.trim() || row.sender.length > 255 || typeof row.rawText !== 'string' || !row.rawText.trim() || row.rawText.length > 65536) continue;
        const time = Date.parse(row.observedAt);
        if (!Number.isFinite(time) || time > Date.now() || time < Date.now() - 30 * 60_000) continue;
        const messageHash = createHash('sha256').update(JSON.stringify([p.id, row.sender, row.rawText, row.observedAt])).digest('hex');
        if (p.seen.has(messageHash)) continue;
        await this.forward({ merchantId: p.id, source: 'GOOGLE_MESSAGES', sender: row.sender, rawText: row.rawText, observedAt: row.observedAt, messageHash });
        p.seen.add(messageHash);
        if (p.seen.size > 2000) p.seen.delete(p.seen.values().next().value);
        p.messagesObserved++; p.lastMessageAt = new Date().toISOString();
      }
    } catch {
      p.lastSeenAt = null;
      // Keep failed messages eligible for the next poll; Laravel deduplicates receipts.
    } finally { p.busy = false; }
    return this.status(p);
  }
  async restore() {
    const root = path.join(this.dataDir, 'profiles');
    if (!this.enabled || !existsSync(root)) return;
    for (const id of readdirSync(root).filter(id => uuid.test(id))) {
      if (existsSync(path.join(root, id, 'paired'))) {
        const p = this.profile(id);
        try { await p.browser.launch(true); p.mode = 'BACKGROUND'; } catch { /* Retry via /pair. */ }
      }
    }
  }
  async route(method, route) {
    const match = /^\/profiles\/([^/]+)\/(pair|status|sweep)$/.exec(route);
    if (!match || !((method === 'GET' && match[2] === 'status') || (method === 'POST' && match[2] !== 'status'))) throw Object.assign(new Error(), { status: 404 });
    const p = this.profile(match[1]);
    if (match[2] === 'status') return this.status(p);
    return match[2] === 'pair' ? this.pair(p) : this.sweep(p);
  }
  async stop() { await Promise.all([...this.profiles.values()].map(p => p.browser.stop())); }
}
