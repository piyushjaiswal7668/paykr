import { createServer } from 'node:http';
import { timingSafeEqual, createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import path from 'node:path';

export function settings(service, port) {
  const token = process.env.PAYKR_INTERNAL_TOKEN;
  if (!token || token.length < 32) throw new Error('PAYKR_INTERNAL_TOKEN must contain at least 32 characters');
  const dataDir = path.resolve(process.env.SIDECAR_DATA_DIR || path.join(homedir(), '.local/share/paykr', service));
  mkdirSync(dataDir, { recursive: true, mode: 0o700 });
  const baseUrl = process.env.LARAVEL_INTERNAL_URL || 'http://127.0.0.1:8000';
  const url = new URL(baseUrl);
  if (url.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(url.hostname)) throw new Error('Laravel internal URL must use loopback HTTP');
  return { token, dataDir, baseUrl: baseUrl.replace(/\/$/, ''), port: Number(process.env.PORT || port) };
}

export function server(token, handler) {
  return createServer({ requestTimeout: 10_000, headersTimeout: 5000 }, async (req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Cache-Control', 'no-store');
    try {
      const supplied = Buffer.from(req.headers['x-paykr-internal-token'] || '');
      const expected = Buffer.from(token);
      if (!expected.length || supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) {
        res.writeHead(401).end(JSON.stringify({ error: 'UNAUTHORIZED' })); return;
      }
      let raw = '';
      for await (const chunk of req) {
        raw += chunk;
        if (Buffer.byteLength(raw) > 70_000) throw Object.assign(new Error(), { status: 413 });
      }
      let body = {};
      try { body = raw ? JSON.parse(raw) : {}; } catch { throw Object.assign(new Error(), { status: 422 }); }
      if (!body || Array.isArray(body) || typeof body !== 'object') throw Object.assign(new Error(), { status: 422 });
      const result = await handler(req.method, new URL(req.url, 'http://127.0.0.1').pathname, body);
      res.end(JSON.stringify(result));
    } catch (error) {
      const status = error.status || 503;
      if (status === 503) console.error(JSON.stringify({ event: 'sidecar_request_failed', code: typeof error.code === 'string' && /^[A-Z_0-9]+$/.test(error.code) ? error.code : 'SERVICE_ERROR', frames: String(error.stack || '').split('\n').slice(1, 4) }));
      res.writeHead(status).end(JSON.stringify({ error: status === 404 ? 'NOT_FOUND' : status === 422 ? 'INVALID_INPUT' : 'SERVICE_UNAVAILABLE' }));
    }
  });
}

export function required(value, max = 255, pattern = null) {
  if (typeof value !== 'string' || !value.trim() || value.length > max || (pattern && !pattern.test(value))) {
    throw Object.assign(new Error('Invalid input'), { status: 422 });
  }
  return value;
}

export async function post(config, route, data, signed = false) {
  const body = JSON.stringify(data);
  const timestamp = String(Date.now());
  const headers = { 'Content-Type': 'application/json', 'X-Paykr-Internal-Token': config.token };
  if (signed) {
    headers['X-Timestamp'] = timestamp;
    headers['X-Signature'] = createHmac('sha256', config.token).update(`${body}:${timestamp}`).digest('hex');
  }
  const response = await fetch(config.baseUrl + route, { method: 'POST', body, headers, redirect: 'error', signal: AbortSignal.timeout(2000) });
  if (!response.ok) throw new Error('Laravel rejected sidecar update');
  return response.json();
}
