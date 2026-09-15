const {chromium} = require(process.env.SENDMAILS_PLAYWRIGHT || 'playwright');
const assert = require('assert/strict');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const os = require('os');
const database=process.env.SENDMAILS_TEST_DATABASE || '';
assert.match(database,/^SendMails_Agility_Test_[a-f0-9]{12}$/);
const fixture=JSON.parse(fs.readFileSync(path.join(os.tmpdir(),database,'smoke-fixture.json'),'utf8'));
const artifacts=process.env.SENDMAILS_ARTIFACT_DIR || path.join(os.tmpdir(),'sendmails-ui-artifacts');
fs.mkdirSync(artifacts,{recursive:true});
const base='http://127.0.0.1:8765/';
const results=[], jsErrors=[], serverErrors=[], blocked=[];
async function step(name,run) {
    const start=Date.now();
    try { await run(); results.push({name,ok:true,ms:Date.now()-start}); }
    catch(error) { results.push({name,ok:false,ms:Date.now()-start,error:error.message}); console.error('SMOKE FAIL '+name+': '+error.message); }
}
async function context(browser) {
    // A fresh context has no registered workers; routing blocks external resources.
    // Playwright's serviceWorkers:block script itself throws inside sandboxed previews.
    const ctx=await browser.newContext({viewport:{width:1440,height:1000}});
    await ctx.route('**/*',route=>{
        const url=route.request().url();
        if(url.startsWith(base) || /^(blob:|data:)/.test(url)) return route.continue();
        blocked.push(url);return route.abort();
    });
    ctx.on('page',page=>{
        page.on('pageerror',error=>jsErrors.push({page:page.url(),error:error.message}));
        page.on('response',r=>{if(r.status()>=500)serverErrors.push({url:r.url(),status:r.status()});});
    });
    return ctx;
}
async function open(page,url,allowAlert=false) {
    const response=await page.goto(base+url,{waitUntil:'load'});
    assert.equal(response.status(),200,url);
    assert.equal(await page.evaluate(()=>getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()),'#14b8a6',url+': estilos cargados');
    assert.ok(await page.locator('h1').isVisible(),url+': titulo');
    assert.doesNotMatch(await page.locator('body').innerText(),/SQLSTATE|Fatal error|Uncaught|PHP Warning|Deprecated:/);
    if(!allowAlert) assert.equal(await page.locator('.alert.error:visible').count(),0,url+': alertas');
}
async function login(page,user,password) {
    await page.goto(base+'login.php');await page.locator('#identifier').fill(user);await page.locator('#password').fill(password);
    await page.locator('button[type=submit]').click();await page.waitForURL(/(?:index|branch_select|change_password)\.php/);
    if(page.url().includes('branch_select.php')) {
        await page.locator('#branch_id').selectOption('1');await page.locator('button[type=submit]').click();await page.waitForURL(/index\.php/);
    }
}
(async()=>{
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const anonymous=await context(browser);const publicPage=await anonymous.newPage();
        await step('Acceso anonimo: pantalla privada redirige al login',async()=>{
            await publicPage.goto(base+'index.php');assert.ok(publicPage.url().endsWith('login.php'));
            const r=await anonymous.request.get(base+'compose_api.php',{headers:{Accept:'application/json'}});assert.equal(r.status(),401);
        });
        for(const url of ['login.php','forgot_password.php','reset_password.php?token=invalido','unsubscribe.php?token=invalido']) {
            await step('Pantalla publica: '+url,()=>open(publicPage,url,true));
        }
        await step('Login: clave incorrecta rechazada',async()=>{
            await publicPage.goto(base+'login.php');await publicPage.locator('#identifier').fill('admin');await publicPage.locator('#password').fill('wrong-fixture');
            await publicPage.locator('button[type=submit]').click();assert.match(await publicPage.locator('.alert.error').innerText(),/incorrectos/);
        });
        await step('Recuperar contraseña con token ficticio sin correo',async()=>{
            await publicPage.goto(base+'reset_password.php?token='+fixture.reset_token);
            await publicPage.locator('#password').fill('SmokeNewReset123!');await publicPage.locator('#password_confirm').fill('SmokeNewReset123!');
            await publicPage.locator('button[type=submit]').click();await publicPage.waitForURL(/login\.php/);
            await login(publicPage,'smoke_reset','SmokeNewReset123!');await publicPage.goto(base+'logout.php');
            await publicPage.goto(base+'reset_password.php?token='+fixture.reset_token);assert.match(await publicPage.locator('.alert.error').innerText(),/no es valido|vencio/);
        });
        await step('Baja de email ficticio con token y confirmacion repetida',async()=>{
            for(let repeat=0;repeat<2;repeat++) {
                await open(publicPage,'unsubscribe.php?token='+encodeURIComponent(fixture.unsubscribe_token));
                await publicPage.getByRole('button',{name:'Cancelar suscripción'}).click();
                assert.match(await publicPage.locator('.alert.success').innerText(),repeat ? /ya estaba dada de baja/ : /fue cancelada/);
            }
        });
        const admin=await context(browser);const page=await admin.newPage();
        await login(page,'admin','SmokeAdmin123!');results.push({name:'Login administrador y seleccion de sucursal',ok:true});
        const pages=['index.php','clients.php','clients.php?q=00001&limit=50','templates.php','template_edit.php','template_edit.php?id=1',
            'invoice_templates.php','invoice_template_edit.php','invoice_template_edit.php?id=1','whatsapp_templates.php',
            'send.php','campaigns.php','invoices.php','invoice_sends.php','activity.php','activity.php?kind=campaign',
            'activity.php?kind=invoice','queue.php','invoice_queue.php','logs.php','users.php','user_edit.php','user_edit.php?id=1',
            'branches.php','branch_edit.php','branch_edit.php?id=1','smtp.php','whatsapp.php','config_db.php','purge.php','change_password.php','branch_select.php'];
        for(const url of pages) await step('Pantalla administrador: '+url,async()=>{
            await open(page,url);
            if(['send.php','invoices.php'].includes(url)) await page.waitForFunction(()=>!document.getElementById('composeForm').hasAttribute('aria-busy'));
        });
        await step('Clientes: excluir y rehabilitar destinatario ficticio',async()=>{
            await open(page,'clients.php?q=00001');const toggle=page.locator('[name=excluded]').first();
            const original=await toggle.isChecked();
            await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),toggle.setChecked(!original)]);
            assert.equal(await page.locator('[name=excluded]').first().isChecked(),!original);
            await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('[name=excluded]').first().setChecked(original)]);
            assert.equal(await page.locator('[name=excluded]').first().isChecked(),original);
        });
        await step('SMTP: guardar configuracion ficticia sin enviar',async()=>{
            await open(page,'smtp.php');await page.locator('#from_name').fill('Smoke sin transporte');
            await page.locator('button[type=submit]').filter({hasText:'Guardar'}).click();await page.waitForURL(/smtp\.php/);
            assert.match(await page.locator('.alert.success').innerText(),/guardada/);
        });
        await step('Usuario: alta con contraseña temporal y sucursal',async()=>{
            await open(page,'user_edit.php');
            for(const [id,value] of Object.entries({username:'smoke_staff',full_name:'Usuario de smoke',email:'staff@example.invalid',temporary_password:'SmokeStaff123!'}))await page.locator('#'+id).fill(value);
            await page.locator('[name="branch_ids[]"][value="1"]').check();await page.getByRole('button',{name:'Guardar usuario'}).click();await page.waitForURL(/users\.php/);
            assert.match(await page.locator('body').innerText(),/Usuario de smoke/);
        });
        await step('Proteccion CSRF y endpoints antiguos de procesamiento',async()=>{
            let r=await admin.request.post(base+'clients.php',{form:{action:'toggle_exclusion',email:'fixture@example.invalid'}});assert.equal(r.status(),419);
            for(const endpoint of ['queue_process_step.php','invoice_process_step.php']) { r=await admin.request.post(base+endpoint,{headers:{Accept:'application/json'}});assert.equal(r.status(),409); }
        });
        await step('Envios de prueba y sincronizacion bloqueados en el entorno',async()=>{
            for(const [endpoint,action] of [['smtp.php','send_test'],['whatsapp.php','send_test'],['template_edit.php','send_preview'],['whatsapp_templates.php','sync']]) {
                const r=await admin.request.post(base+endpoint,{form:{action}});assert.equal(r.status(),403);assert.match(await r.text(),/External transport disabled/);
            }
        });
        await step('Webhook WhatsApp: verificacion, firma y eventos ficticios repetidos',async()=>{
            let r=await anonymous.request.get(base+'whatsapp_webhook.php?hub.mode=subscribe&hub.verify_token=smoke-verify&hub.challenge=fixture');assert.equal(r.status(),200);assert.equal(await r.text(),'fixture');
            const payload=JSON.stringify({entry:[{id:'smoke-waba',changes:[{value:{metadata:{phone_number_id:'smoke-phone'},statuses:[{id:'wamid.smoke',status:'delivered',timestamp:String(Math.floor(Date.now()/1000))}]}}]}]});
            r=await anonymous.request.post(base+'whatsapp_webhook.php',{data:payload,headers:{'Content-Type':'application/json','X-Hub-Signature-256':'sha256=invalid'}});assert.equal(r.status(),403);
            const signature='sha256='+crypto.createHmac('sha256','smoke-secret').update(payload).digest('hex');
            r=await anonymous.request.post(base+'whatsapp_webhook.php',{data:payload,headers:{'Content-Type':'application/json','X-Hub-Signature-256':signature}});assert.equal(r.status(),200);assert.equal(await r.text(),'EVENT_RECEIVED');
            r=await anonymous.request.post(base+'whatsapp_webhook.php',{data:payload,headers:{'Content-Type':'application/json','X-Hub-Signature-256':signature}});assert.equal(r.status(),200);assert.equal(await r.text(),'EVENT_RECEIVED');
            const optOut=JSON.stringify({entry:[{id:'smoke-waba',changes:[{value:{metadata:{phone_number_id:'smoke-phone'},messages:[{id:'wamid.smoke-optout',from:'549110000000001',text:{body:'BAJA'},timestamp:String(Math.floor(Date.now()/1000))}]}}]}]});
            for(let repeat=0;repeat<2;repeat++) {
                r=await anonymous.request.post(base+'whatsapp_webhook.php',{data:optOut,headers:{'Content-Type':'application/json','X-Hub-Signature-256':'sha256='+crypto.createHmac('sha256','smoke-secret').update(optOut).digest('hex')}});
                assert.equal(r.status(),200);assert.equal(await r.text(),'EVENT_RECEIVED');
            }
        });
        const staff=await context(browser);const userPage=await staff.newPage();
        await step('Usuario: cambio obligatorio de contraseña',async()=>{
            await login(userPage,'smoke_staff','SmokeStaff123!');assert.match(userPage.url(),/change_password\.php/);
            await userPage.locator('#current_password').fill('SmokeStaff123!');await userPage.locator('#new_password').fill('SmokeStaff456!');await userPage.locator('#new_password_confirm').fill('SmokeStaff456!');
            await userPage.getByRole('button',{name:'Guardar contraseña'}).click();await userPage.waitForURL(/index\.php/);
        });
        for(const url of ['index.php','clients.php','templates.php','send.php','invoices.php','activity.php']) await step('Pantalla usuario: '+url,()=>open(userPage,url));
        await step('Usuario: administracion y otra sucursal denegadas',async()=>{
            for(const url of ['users.php','branches.php','smtp.php','whatsapp.php','config_db.php','purge.php']) {
                const r=await staff.request.get(base+url,{headers:{Accept:'application/json'}});assert.equal(r.status(),403,url);
            }
            await userPage.goto(base+'branch_select.php');const csrf=await userPage.locator('[name=csrf_token]').inputValue();
            const r=await staff.request.post(base+'branch_select.php',{form:{csrf_token:csrf,branch_id:'999999'}});assert.equal(r.status(),200);assert.match(await r.text(),/No tienes permiso/);
        });
        await step('Movil: inicio, clientes y seguimiento sin desborde',async()=>{
            for(const width of [390,320]) {
                await page.setViewportSize({width,height:844});
                for(const url of ['index.php','clients.php','activity.php']) {
                    await open(page,url);
                    await page.screenshot({path:path.join(artifacts,'smoke-mobile-'+width+'-'+url+'.png'),fullPage:true});
                    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),url+' a '+width+' px');
                }
            }
        });
        await step('Cerrar sesion invalida acceso privado',async()=>{
            await userPage.goto(base+'logout.php');const r=await staff.request.get(base+'worker_control.php?branch_id=1',{headers:{Accept:'application/json'}});assert.equal(r.status(),401);
        });
        await step('Sin errores JavaScript ni HTTP 500',async()=>{assert.deepEqual(jsErrors,[]);assert.deepEqual(serverErrors,[]);});
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(artifacts,'smoke-results.json'),JSON.stringify({at:new Date().toISOString(),database,results,jsErrors,serverErrors,blockedExternalRequests:blocked.length},null,2));
    }
    const failures=results.filter(r=>!r.ok);console.log(`SMOKE: ${results.length-failures.length}/${results.length} verificaciones OK; ${blocked.length} recursos externos bloqueados; envios deshabilitados.`);
    if(failures.length)process.exitCode=1;
})().catch(error=>{console.error(error);process.exit(1);});
