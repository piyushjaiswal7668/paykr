import { settings, server, post } from '../../shared/http.js';
import pino from 'pino';
import { WhatsApp } from './service.js';

process.umask(0o077);
const config = settings('whatsapp', 6101);
const service = new WhatsApp({ ...config, load: async () => {
  const [{ BerryProtocol }, { default: qrcode }] = await Promise.all([import('berryprotocol'), import('qrcode')]);
  return { BerryProtocol, qrcode, logger: pino({ level: 'silent' }) };
} });
// Serialize pushes so a delayed CONNECTING update cannot overwrite CONNECTED.
let pushing = Promise.resolve();
const publish = () => { pushing = pushing.then(() => post(config, '/internal/whatsapp/status', service.status())).catch(() => {}); };
service.on('status', publish);
const heartbeat = setInterval(publish, 30_000);
const http = server(config.token, (...args) => service.route(...args));
http.listen(config.port, '127.0.0.1', () => console.log(`WhatsApp sidecar listening on 127.0.0.1:${config.port}`));
if (service.hasCredentials()) void service.initialize().catch(() => {});
publish();
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => {
  clearInterval(heartbeat); http.close(); await service.stop(); process.exit(0);
});
