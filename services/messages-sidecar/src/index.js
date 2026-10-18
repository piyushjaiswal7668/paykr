import { settings, server, post } from '../../shared/http.js';
import { Messages } from './service.js';

process.umask(0o077);
const config = settings('messages', 6102);
const service = new Messages({ ...config, enabled: process.env.MESSAGES_SIDECAR_ENABLED === 'true', forward: data => post(config, '/internal/observations', data, true) });
const http = server(config.token, (...args) => service.route(...args));
http.listen(config.port, '127.0.0.1', () => console.log(`Messages sidecar listening on 127.0.0.1:${config.port}`));
const monitor = setInterval(() => { for (const p of service.profiles.values()) void service.sweep(p, false); }, 2000);
const extract = setInterval(() => { for (const p of service.profiles.values()) void service.sweep(p); }, 3000);
void service.restore();
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, async () => {
  clearInterval(monitor); clearInterval(extract); http.close(); await service.stop(); process.exit(0);
});
