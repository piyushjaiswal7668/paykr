import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';

// Port of observer-sdk/browser-launcher.js, using Node 22's native WebSocket.
export class Browser {
  constructor(profileDir) { this.profileDir = profileDir; this.child = null; this.port = null; }
  async launch(background = false) {
    if (this.child) {
      try { if ((await this.targets()).some(target => target.type === 'page')) return; } catch { /* Restart a browser with no usable page. */ }
      await this.stop();
    }
    const executable = [process.env.CHROME_PATH, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].find(p => p && existsSync(p));
    if (!executable) throw new Error('Chrome not installed; set CHROME_PATH');
    mkdirSync(this.profileDir, { recursive: true, mode: 0o700 });
    const child = spawn(executable, [`--user-data-dir=${this.profileDir}`, '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--no-first-run', '--no-default-browser-check', '--disable-background-timer-throttling', '--disable-renderer-backgrounding', background ? '--window-position=-2400,-2400' : '--window-position=100,100', 'https://messages.google.com/web/authentication'], { stdio: 'ignore' });
    this.child = child;
    let failed = false;
    child.on('error', () => { failed = true; this.child = null; this.port = null; });
    child.on('exit', () => { if (this.child === child) { this.child = null; this.port = null; } });
    for (let attempt = 0; attempt < 40; attempt++) {
      if (failed || this.child !== child) break;
      try {
        const [port] = readFileSync(path.join(this.profileDir, 'DevToolsActivePort'), 'utf8').split('\n');
        this.port = Number(port);
        if ((await this.targets()).length) return;
      } catch { /* Chrome creates the port file after startup. */ }
      await delay(200);
    }
    await this.stop();
    throw new Error('Chrome startup timed out');
  }
  async targets() {
    if (!this.port || !this.child) return [];
    const response = await fetch(`http://127.0.0.1:${this.port}/json`, { signal: AbortSignal.timeout(1000) });
    if (!response.ok) throw new Error('Chrome unavailable');
    return response.json();
  }
  async command(wsUrl, method, params = {}) {
    const url = new URL(wsUrl);
    if (url.protocol !== 'ws:' || url.hostname !== '127.0.0.1' || Number(url.port) !== this.port) throw new Error('Invalid Chrome target');
    return new Promise((resolve, reject) => {
      const ws = new WebSocket(wsUrl);
      const finish = (error, result) => { clearTimeout(timer); ws.close(); error ? reject(error) : resolve(result); };
      const timer = setTimeout(() => finish(new Error('Chrome command timed out')), 1500);
      ws.onopen = () => ws.send(JSON.stringify({ id: 1, method, params }));
      ws.onmessage = event => {
        try {
          const data = JSON.parse(event.data);
          if (data.id === 1) finish(data.error ? new Error('Chrome command failed') : null, data.result);
        } catch { finish(new Error('Invalid Chrome response')); }
      };
      ws.onerror = () => finish(new Error('Chrome disconnected'));
    });
  }
  async background(target) { return this.position(target, -2400); }
  async foreground(target) { return this.position(target, 100); }
  async position(target, coordinate) {
    const { windowId } = await this.command(target.webSocketDebuggerUrl, 'Browser.getWindowForTarget', { targetId: target.id });
    await this.command(target.webSocketDebuggerUrl, 'Browser.setWindowBounds', { windowId, bounds: { windowState: 'normal' } });
    await this.command(target.webSocketDebuggerUrl, 'Browser.setWindowBounds', { windowId, bounds: { left: coordinate, top: coordinate } });
  }
  async evaluate(target, expression) {
    const result = await this.command(target.webSocketDebuggerUrl, 'Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error('Message extraction failed');
    return result.result?.value;
  }
  async stop() {
    const child = this.child;
    this.child = null; this.port = null;
    if (child) {
      child.kill('SIGTERM');
      await Promise.race([new Promise(resolve => child.once('exit', resolve)), delay(2000)]);
      if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
    }
  }
}

// Full message bubbles only: conversation previews may be truncated. Never scan body
// text or infer a sender from a bank name in the message. Unknown sender/time fails closed.
export async function extractMessages() {
  // Rotate recent conversations to read complete bubbles instead of truncated previews.
  const conversations = [...document.querySelectorAll('mws-conversation-list-item')].slice(0, 10);
  if (conversations.length) {
    const index = (window.__paykrConversationIndex || 0) % conversations.length;
    window.__paykrConversationIndex = index + 1;
    conversations[index].click();
    await new Promise(resolve => setTimeout(resolve, 350));
  }
  const header = document.querySelector('mws-conversation-header');
  const conversationSender = header?.querySelector('[data-e2e-contact-name], .contact-name, .conversation-title')?.textContent?.trim();
  return [...document.querySelectorAll('mws-message-wrapper')].flatMap(item => {
    if (item.matches('[data-is-outgoing="true"], .outgoing') || item.querySelector('.outgoing')) return [];
    const sender = item.getAttribute('data-sender') || conversationSender;
    const rawText = item.querySelector('.text-msg-content, .text-content, .message-content')?.textContent?.trim();
    const stamp = item.getAttribute('data-timestamp') || item.querySelector('time[datetime]')?.getAttribute('datetime');
    if (!sender || !rawText || !stamp) return [];
    const date = new Date(/^\d+$/.test(stamp) ? Number(stamp) * (stamp.length <= 10 ? 1000 : 1) : stamp);
    if (!Number.isFinite(date.getTime())) return [];
    return [{ sender, rawText, observedAt: date.toISOString() }];
  });
}
