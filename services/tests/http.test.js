import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { server, post } from '../shared/http.js';

const token = 'test-token-with-at-least-32-characters';
test('loopback HTTP authenticates, limits input and signs exact observation bytes', async t => {
  let handled = 0;
  const http = server(token, async (method, route, body) => { handled++; return { body, route }; });
  http.listen(0, '127.0.0.1'); await once(http, 'listening');
  t.after(() => { http.closeAllConnections(); http.close(); });
  const baseUrl = `http://127.0.0.1:${http.address().port}`;
  assert.equal((await fetch(baseUrl + '/status')).status, 401);
  assert.equal(handled, 0);
  assert.equal((await fetch(baseUrl, { method: 'POST', headers: { 'X-Paykr-Internal-Token': token }, body: '{' })).status, 422);
  assert.equal((await fetch(baseUrl, { method: 'POST', headers: { 'X-Paykr-Internal-Token': token }, body: JSON.stringify({ text: 'x'.repeat(71000) }) })).status, 413);
  const data = { merchantId: 'merchant', sender: 'BANK', rawText: '₹100 credited' };
  assert.deepEqual((await post({ token, baseUrl }, '/internal/observations', data, true)).body, data);
  let signed = false;
  http.on('request', req => {
    if (req.url !== '/signed' || req.headers['x-paykr-internal-token'] !== token) return;
    const chunks = [];
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', () => {
      assert.match(req.headers['x-timestamp'], /^\d{13}$/);
      assert.equal(req.headers['x-signature'], createHmac('sha256', token).update(Buffer.concat(chunks).toString() + ':' + req.headers['x-timestamp']).digest('hex'));
      signed = true;
    });
  });
  await post({ token, baseUrl }, '/signed', data, true);
  assert.equal(signed, true);
  await assert.rejects(post({ token: 'incorrect', baseUrl }, '/signed', data));
});
