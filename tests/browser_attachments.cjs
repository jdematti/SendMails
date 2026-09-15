const {chromium} = require(process.env.SENDMAILS_PLAYWRIGHT || 'playwright');
const assert = require('assert/strict');
const fs = require('fs');
const path = require('path');
const {execFileSync} = require('child_process');
assert.match(process.env.SENDMAILS_TEST_DATABASE || '', /^SendMails_Agility_Test_[a-f0-9]{12}$/);
const root=path.dirname(__dirname);
const artifacts=process.env.SENDMAILS_ARTIFACT_DIR || path.join(require('os').tmpdir(),'sendmails-ui-artifacts');
fs.mkdirSync(artifacts,{recursive:true});
function fixture(code) {
    return execFileSync('php',['-r', "require 'tests/test_bootstrap.php'; " + code], {cwd:root,env:process.env,encoding:'utf8'});
}
function samplePdf() {
    const stream='BT /F1 24 Tf 40 170 Td (PROMOCION DE CAMPANA) Tj 0 -40 Td /F1 14 Tf (Adjunto de prueba - sin envio) Tj ET';
    const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 480 240] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',`<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
    let pdf='%PDF-1.4\n'; const offsets=[0];
    objects.forEach((object,index)=>{offsets.push(Buffer.byteLength(pdf));pdf+=`${index+1} 0 obj\n${object}\nendobj\n`;});
    const start=Buffer.byteLength(pdf);
    pdf+='xref\n0 6\n0000000000 65535 f \n'+offsets.slice(1).map(offset=>String(offset).padStart(10,'0')+' 00000 n \n').join('');
    return Buffer.from(pdf+`trailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n${start}\n%%EOF\n`);
}
(async()=>{
    const browser=await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page=await browser.newPage({viewport:{width:1366,height:1000}});
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        const attachmentRequests=[];page.on('request',r=>{if(r.url().includes('attachment_preview.php'))attachmentRequests.push(r);});
        const posts=[];page.on('request',r=>{if(r.method()==='POST')posts.push(r);});
        await page.goto('http://127.0.0.1:8765/template_edit.php');
        await page.locator('#name').fill('Adjuntos con vista previa');await page.locator('#subject').fill('Vista previa fixture');
        const files=[{name:'promoción.pdf',mimeType:'application/pdf',buffer:samplePdf()},
            {name:'logo.png',mimeType:'image/png',buffer:fs.readFileSync(path.join(root,'favicon.png'))},
            {name:'notas.txt',mimeType:'text/plain',buffer:Buffer.from('<script>window.previewExecuted=true</script>\nContenido literal')},
            {name:'pagina.html',mimeType:'text/html',buffer:Buffer.from('<html><script>window.previewExecuted=true</script></html>')}];
        await page.locator('#attachments').setInputFiles(files);
        const rows=page.locator('#selectedAttachments .attachment-row');assert.equal(await rows.count(),4);
        await rows.nth(0).getByRole('button',{name:'Vista previa'}).click();
        await page.waitForSelector('#attachmentPreviewContent iframe');
        assert.equal(attachmentRequests.length,0,'Adjunto local sin peticion al servidor');assert.equal(posts.length,0,'Vista previa no guarda ni envia');
        const downloadPromise=page.waitForEvent('download');await page.locator('#downloadAttachment').click();
        const download=await downloadPromise;assert.equal(download.suggestedFilename(),'promoción.pdf');
        assert.deepEqual(fs.readFileSync(await download.path()),files[0].buffer,'Descarga conserva los bytes');
        await page.screenshot({path:path.join(artifacts,'attachment-pdf-desktop.png')});
        await page.locator('#closeAttachmentPreview').click();
        await rows.nth(1).getByRole('button',{name:'Vista previa'}).click();
        await page.waitForFunction(()=>document.querySelector('#attachmentPreviewContent img')?.naturalWidth>0);
        await page.keyboard.press('Escape');assert.equal(await page.locator('#attachmentDialog').isVisible(),false);
        await rows.nth(2).getByRole('button',{name:'Vista previa'}).click();
        await page.waitForSelector('#attachmentPreviewContent pre');
        assert.match(await page.locator('#attachmentPreviewContent pre').innerText(),/<script>/);
        assert.equal(await page.evaluate(()=>window.previewExecuted),undefined,'Texto nunca ejecutado como HTML');
        await page.locator('#closeAttachmentPreview').click();
        await rows.nth(3).getByRole('button',{name:'Vista previa'}).click();
        await page.waitForFunction(()=>document.getElementById('attachmentPreviewStatus').textContent.includes('no tiene vista previa'));
        assert.equal(await page.locator('#attachmentPreviewContent iframe').count(),0,'HTML sin incrustar');
        await page.locator('#closeAttachmentPreview').click();
        await rows.nth(3).getByRole('button',{name:'Quitar pagina.html'}).click();
        assert.equal(await rows.count(),3);assert.equal(await page.locator('#attachments').evaluate(e=>e.files.length),3);
        await page.locator('button[value=save_draft]').click();await page.waitForURL(/draft=/);
        assert.equal(attachmentRequests.length,0,'Los adjuntos guardados no se descargan al abrir el editor');
        const saved=page.locator('[data-saved-attachment]');assert.equal(await saved.count(),3);
        await saved.nth(0).getByRole('button',{name:'Vista previa'}).click();await page.waitForSelector('#attachmentPreviewContent iframe');
        assert.equal(attachmentRequests.length,1,'Carga a demanda desde borrador');
        const url=await saved.nth(0).locator('[data-preview-attachment]').getAttribute('data-url');
        let response=await page.request.get('http://127.0.0.1:8765/'+url);
        assert.equal(response.status(),200);assert.deepEqual(await response.body(),files[0].buffer);
        assert.match(response.headers()['content-disposition'],/^attachment;/);assert.match(response.headers()['cache-control'],/no-store/);
        await page.locator('#closeAttachmentPreview').click();
        const stale=new URL(url,'http://127.0.0.1:8765/');stale.searchParams.set('fingerprint','0'.repeat(64));
        response=await page.request.get(stale.href);assert.equal(response.status(),409,'No mostrar otro archivo tras edicion concurrente');
        const otherBranch=new URL(url,'http://127.0.0.1:8765/');otherBranch.searchParams.set('branch_id','2');
        response=await page.request.get(otherBranch.href);assert.equal(response.status(),409,'Rechazar cambio de sucursal');
        const hidden=JSON.parse(fixture("$_SESSION['branch_id']=2; $id=TemplateRepository::save(null,'Oculta','Oculta','<p>fixture</p>',true,[]); $draft=DraftRepository::save(0,'template','Oculto',['template'=>['attachments_json'=>'[]']],0); echo json_encode(['template'=>$id,'draft'=>$draft['id']]);"));
        for(const source of ['template','draft']) {
            const forbidden=new URL(url,'http://127.0.0.1:8765/');forbidden.searchParams.set('draft_id',source==='draft'?hidden.draft:0);forbidden.searchParams.set('template_id',hidden.template);
            response=await page.request.get(forbidden.href);assert.equal(response.status(),404,'Sin acceso a adjuntos de otra sucursal');
        }
        await saved.nth(1).locator('input[type=checkbox]').check();assert.ok(await saved.nth(1).getByRole('button',{name:'Vista previa'}).isDisabled());
        assert.match(await saved.nth(1).innerText(),/Se quitará al guardar/);
        await saved.nth(1).locator('input[type=checkbox]').uncheck();
        await page.locator('button[value=save]').click();await page.waitForURL(/id=/);
        await page.locator('[data-saved-attachment]').nth(2).getByRole('button',{name:'Vista previa'}).click();
        await page.waitForSelector('#attachmentPreviewContent pre');assert.match(await page.locator('#attachmentPreviewContent pre').innerText(),/Contenido literal/);
        await page.setViewportSize({width:390,height:844});
        await page.screenshot({path:path.join(artifacts,'attachment-text-mobile.png')});
        assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Sin desborde horizontal');
        await page.locator('#closeAttachmentPreview').click();
        await page.reload();assert.equal(await page.locator('[data-saved-attachment]').count(),3);
        assert.deepEqual(errors,[]);
        console.log('ATTACHMENT BROWSER OK: PDF, imagen, texto literal, descarga, formatos sin visor, borrador/publicacion, limites de sucursal y version, escritorio/movil. Sin envios.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
