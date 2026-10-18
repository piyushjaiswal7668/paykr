import { AndroidObserverDriver } from '../Fixtures/android/driver.mjs';
let input = '';
for await (const part of process.stdin) input += part;
const options = JSON.parse(input);
const requests = [];
globalThis.fetch = async (url, request) => {
  requests.push({url, ...request});
  return new Response(JSON.stringify({paired: true, deviceId: options.deviceId, signingKey: options.signingKey, merchantId: options.merchantId}), {status: 200});
};
const driver = new AndroidObserverDriver({apiBaseUrl: 'http://localhost', deviceName: 'Pixel 8 Pro', deviceModel: 'Google Pixel'});
await driver.pair(options.enrollmentToken, options.merchantId);
await driver.sendHeartbeat();
await driver.submitObservation({amount: '200', receivingAccountId: options.accountId, observedAt: options.observedAt, timestampOverride: options.timestamp, utr: '123456789012', notificationText: 'Received Rs 200 via UPI UTR: 123456789012'});
process.stdout.write(JSON.stringify(requests));
