const {chromium}=require(process.env.SENDMAILS_PLAYWRIGHT || 'playwright');
const fs=require('fs'), path=require('path'), assert=require('assert/strict');
const base=process.env.SENDMAILS_REVIEW_URL || (process.argv.includes('--smoke') ? 'http://127.0.0.1:8765/' : 'http://127.0.0.1:8766/');
assert.match(base,/^http:\/\/127\.0\.0\.1:876[56]\/$/);
const stage=process.argv.includes('--before') ? 'before' : 'after';
const root=process.env.SENDMAILS_ARTIFACT_DIR || path.join(__dirname,'../storage/development-artifacts/usability-20260915');
const dir=path.join(root,stage);fs.mkdirSync(dir,{recursive:true});
(async()=>{
    const browser=await chromium.launch({channel:'msedge',headless:true});
    const page=await browser.newPage(), results=[], errors=[];
    await page.route('**/*',r=>r.request().url().startsWith(base)||/^(data:|blob:)/.test(r.request().url())?r.continue():r.abort());
    page.on('pageerror',e=>errors.push(e.message));
    try {
        await page.goto(base+'login.php');await page.locator('#identifier').fill('admin');await page.locator('#password').fill('SmokeAdmin123!');
        await page.locator('button[type=submit]').click();await page.waitForURL(/(?:index|branch_select)\.php/);
        if(page.url().includes('branch_select.php')) {await page.locator('#branch_id').selectOption('1');await page.locator('button[type=submit]').click();await page.waitForURL(/index\.php/);}
        const desktop=['index.php','clients.php','templates.php','template_edit.php?id=1','invoice_templates.php','invoice_template_edit.php?id=1','whatsapp_templates.php','send.php','invoices.php','activity.php','campaigns.php','invoice_sends.php','queue.php','invoice_queue.php','logs.php','users.php','user_edit.php','branches.php','branch_edit.php?id=1','smtp.php','whatsapp.php','config_db.php','purge.php','change_password.php','branch_select.php'];
        for(const width of [1366,390]) {
            await page.setViewportSize({width,height:900});
            for(const url of desktop) {
                const response=await page.goto(base+url);assert.equal(response.status(),200,url);
                if(['send.php','invoices.php'].includes(url))await page.waitForFunction(()=>!document.getElementById('composeForm').hasAttribute('aria-busy')&&document.querySelector('#recipientRows tr'));
                if(url.includes('template_edit.php')) {
                    await page.locator('iframe.preview-frame').scrollIntoViewIfNeeded();
                    try { await page.frameLocator('iframe.preview-frame').locator('body').filter({hasText:/\S/}).waitFor({timeout:5000}); }
                    catch(error) { throw new Error(url+' a '+width+' px: '+error.message); }
                    await page.evaluate(()=>window.scrollTo(0,0));
                }
                assert.equal(await page.locator('.alert.error:visible').count(),0,url);
                const metrics=await page.evaluate(()=>({height:document.documentElement.scrollHeight,width:document.documentElement.scrollWidth,
                    sidebarHeight:document.querySelector('.sidebar')?.getBoundingClientRect().height ?? 0,
                    inputs:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>e.checkVisibility()).length,
                    actions:[...document.querySelectorAll('main button,main a.btn')].filter(e=>e.checkVisibility()).length,
                    firstTable:document.querySelector('main table')?.getBoundingClientRect().top ?? null}));
                results.push({url,viewport:width,...metrics});
                if(process.argv.includes('--verify'))assert.ok(metrics.width<=width+1,url+': sin desborde de pagina');
                if(process.argv.includes('--verify')&&width<980)assert.ok(metrics.sidebarHeight<=100,url+': encabezado compacto con menu cerrado');
                await page.screenshot({path:path.join(dir,width+'-'+url.replace(/[^a-z0-9]/gi,'_')+'.png')});
            }
        }
        assert.deepEqual(errors,[]);
        fs.writeFileSync(path.join(dir,'metrics.json'),JSON.stringify(results,null,2));
        console.log('USABILITY CAPTURE OK: '+results.length+' pantallas, escritorio/movil; sin envios.');
        if(process.argv.includes('--verify')) {
            const flows=await require('./browser_usability_flows.cjs')(page,base);
            assert.deepEqual(errors,[]);
            fs.writeFileSync(path.join(dir,'flows.json'),JSON.stringify(flows,null,2));
        }
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
