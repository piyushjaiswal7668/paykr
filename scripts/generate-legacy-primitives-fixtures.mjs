import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import {pathToFileURL} from 'node:url';

// Run from Paykr: node scripts/generate-legacy-primitives-fixtures.mjs /path/to/YouPI-PG
const legacyRoot = process.argv[2];
if (!legacyRoot) throw new Error('Pass the existing Node project path.');
const legacy = relative => pathToFileURL(path.resolve(legacyRoot, relative)).href;
const {BankSmsParser,extractIntelligentAmount} = await import(legacy('packages/core/src/bank-sms-parser.js'));
const {BankEmailParser} = await import(legacy('packages/core/src/bank-email-parser.js'));
const {generateFingerprintHash} = await import(legacy('packages/core/src/device-security.js'));
const texts=[
'A/C 1234 credited with Rs. 499.00 on 08-Sep-2026. UPI Ref No 512345678901',
'Acct XX9876 credited by Rs.1,250.50 via UPI', 'Rs499 received in a/c 1234','INR 500 credited to a/c 4321','₹1,200.50 credited to your account','Mrs Sharma, a/c 1234 credited with Rs 75.00',
'20.00 Rs received from user@upi.', '20rs received', 'received 20.00', '20.00 received', 'balance 42.50','credited 10000000','credit INR 20 via UPI/CR/REFERENCE99/',
'Payment of ₹1,500.50 received from user@upi via PhonePe.', 'credited INR 10 ref: ABCDEFGH', 'credited INR 10 512345678901', 'credited INR 10 UTR: ABC123DEF',
'credited INR 10 RRN: REF123456', 'credited INR 10', 'Payment paid Rs 20', 'Paid Rs 20 but credited Rs 10', 'received no amount', '', 'Rs 0 credited', 'Rs 999999999999999999999999 credited','received 0 then 20.00', 'UPI Rs 10 debited', 'Deposit 500 inr Ref no: UTR-GMAIL-777'
];
const json=v=>JSON.stringify(v,(_,v)=>typeof v==='bigint'?v.toString():v,2);
fs.writeFileSync('tests/Fixtures/bank-message-parity.json',json(texts.filter(t=>!t.includes('999999999999999')).map(text=>({text,amount:extractIntelligentAmount(text),sms:BankSmsParser.parse({text,timestamp:'2026-09-08T10:00:00Z'}),email:BankEmailParser.parse({subject:'',body:text,date:'2026-09-08T10:00:00Z'})}))));
const signals=[{}, {userAgent:'  Mozilla/5.0   Test  ',platform:' MAC ',language:' EN-IN ',timezone:' Asia/Kolkata ',screenWidth:1920,screenHeight:1080,hardwareConcurrency:'8',deviceMemory:'8',webgl:' APPLE GPU ',canvasSignature:' abc '}, {screenSignature:'100x200x24',deviceMemory:'0.5',hardwareConcurrency:-1,webglRenderer:'GPU',userAgent:'Unicode हिन्दी / browser'}];
const {publicKey,privateKey}=crypto.generateKeyPairSync('ec',{namedCurve:'prime256v1'});
const payload='payment-id:2026-10-18T00:00:00Z';
fs.writeFileSync('tests/Fixtures/device-security-parity.json',json({signals:signals.map(signals=>({signals,hash:generateFingerprintHash(signals)})),payload,pem:publicKey.export({format:'pem',type:'spki'}),spki:publicKey.export({format:'der',type:'spki'}).toString('base64'),jwk:publicKey.export({format:'jwk'}),p1363:crypto.sign('sha256',Buffer.from(payload),{key:privateKey,dsaEncoding:'ieee-p1363'}).toString('base64'),der:crypto.sign('sha256',Buffer.from(payload),privateKey).toString('base64')}));
