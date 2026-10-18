import {chromium, expect} from '@playwright/test';
import {createHmac} from 'node:crypto';
import {mkdir} from 'node:fs/promises';
let input='';for await(const chunk of process.stdin)input+=chunk;
const fixture=JSON.parse(input), base='http://127.0.0.1:8020';
const browser=await chromium.launch({headless:true,...(process.env.PAYKR_BROWSER_PATH?{executablePath:process.env.PAYKR_BROWSER_PATH}:{})});
const evidence='storage/app/private/evidence/phase8';await mkdir(evidence,{recursive:true});
function totp(){
    const alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits=[...'JBSWY3DPEHPK3PXP'].map(c=>alphabet.indexOf(c).toString(2).padStart(5,'0')).join('');
    const key=Buffer.from(bits.match(/.{8}/g).map(b=>parseInt(b,2)));
    const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));
    const mac=createHmac('sha1',key).update(counter).digest(),offset=mac[19]&15;
    return ((mac.readUInt32BE(offset)&0x7fffffff)%1000000).toString().padStart(6,'0');
}
async function login(page,role){
    page.setDefaultTimeout(15000);
    const panel=role==='admin'?'admin':'merchant';
    const loginResponse = await page.goto(`${base}/${panel}/login`,{waitUntil:'domcontentloaded'});
    expect(loginResponse.status()).toBe(200);
    await page.screenshot({path:`${evidence}/login-${role}.png`});
    await page.getByLabel('Email address').fill(`${role}@panel-test.example`);
    await page.locator('input[type=password]').fill(fixture.password);
    await page.getByRole('button',{name:'Sign in',exact:true}).click();
    if(role==='admin'){
        await page.locator('input[autocomplete=one-time-code]').fill(totp());
    }
    await expect(page).not.toHaveURL(/\/login$/,{timeout:15000});
}
try{
    const owner=await browser.newContext({viewport:{width:1440,height:1000}}),page=await owner.newPage(),errors=[];
    page.setDefaultTimeout(15000);
    page.on('pageerror',e=>errors.push(e.message));
    await login(page,'owner');
    const pages=['live-monitor','counter-desk','transactions','receiving-accounts','brand-identity','payment-methods','android-sms','gmail-connection','observers','webhooks','api-keys','payment-simulator','customers','support-tickets','team-members','credit-reviews'];
    for(const path of pages){
        const response=await page.goto(`${base}/merchant/${fixture.merchant}/${path}`,{waitUntil:'domcontentloaded'});expect(response.status()).toBe(200);
        await expect(page.locator('main')).toBeVisible();
        if(['live-monitor','brand-identity','counter-desk'].includes(path))await page.screenshot({path:`${evidence}/${path}.png`,fullPage:true});
    }
    await page.goto(`${base}/merchant/${fixture.merchant}/brand-identity`,{waitUntil:'domcontentloaded'});
    await page.getByLabel('App name').fill('Browser Verified Store');
    await page.getByLabel('App name').blur();
    await expect(page.locator('main').getByText('Browser Verified Store',{exact:true})).toHaveCount(1);
    const saved=page.waitForResponse(r=>{if(r.request().method()!=='POST')return false;try{return r.request().postDataJSON()?.components?.some(c=>c.calls?.some(call=>call.method==='save'));}catch{return false;}});
    await page.getByRole('button',{name:'Save brand identity',exact:true}).click();
    expect((await saved).status()).toBe(200);
    await page.reload({waitUntil:'domcontentloaded'});await expect(page.getByLabel('App name')).toHaveValue('Browser Verified Store');
    await page.goto(`${base}/merchant/${fixture.merchant}/counter-desk`,{waitUntil:'domcontentloaded'});
    await page.getByRole('button',{name:'Confirm',exact:true}).first().click();
    await expect(page.getByText('Confirm that the full payment was received at the counter.',{exact:true})).toBeVisible();
    await page.screenshot({path:`${evidence}/counter-confirm.png`});
    await page.getByRole('button',{name:'Confirm',exact:true}).last().click();
    await expect(page.getByText('COUNTER-BROWSER-TEST',{exact:true})).toHaveCount(0);
    await page.goto(`${base}/merchant/${fixture.merchant}/transactions`,{waitUntil:'domcontentloaded'});
    await expect(page.getByRole('table').getByText('SUCCESS',{exact:true})).toBeVisible();
    expect(errors).toEqual([]);
    console.log('merchant: PASS — all pages render; branding persists; counter confirmation succeeds');
    await owner.close();
    const restricted=await browser.newContext(),cashier=await restricted.newPage();await login(cashier,'cashier');
    for(const path of ['brand-identity','webhooks','team-members'])expect((await cashier.goto(`${base}/merchant/${fixture.merchant}/${path}`,{waitUntil:'domcontentloaded'})).status()).toBe(403);
    console.log('cashier: PASS — restricted pages denied');
    await restricted.close();
    const adminContext=await browser.newContext(),admin=await adminContext.newPage(),adminErrors=[];admin.on('pageerror',e=>adminErrors.push(e.message));await login(admin,'admin');
    for(const path of ['','/merchants','/customers','/payments','/security','/webhook-deliveries','/audit-history','/system','/whats-app-sender','/platform-settings','/api-keys'])expect((await admin.goto(`${base}/admin${path}`,{waitUntil:'domcontentloaded'})).status()).toBe(200);
    await admin.goto(`${base}/admin`,{waitUntil:'domcontentloaded'});await expect(admin.getByText('Volume today',{exact:true})).toBeVisible({timeout:15000});await admin.screenshot({path:`${evidence}/admin-dashboard.png`,fullPage:true});expect(adminErrors).toEqual([]);
    console.log('admin: PASS — TOTP login and all platform pages render');
}finally{await browser.close();}
