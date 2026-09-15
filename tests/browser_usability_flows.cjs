const assert=require('assert/strict');
module.exports=async(page,base)=>{
    const passed=[];
    const check=async(name,run)=>{await run();passed.push(name);};
    const ready=()=>page.waitForFunction(()=>!document.getElementById('composeForm').hasAttribute('aria-busy'));
    const open=async(url)=>{await page.goto(base+url);if(['send.php','invoices.php'].includes(url))await ready();};
    const confirmations=[];
    page.on('dialog',async dialog=>{if(dialog.type()==='beforeunload')await dialog.accept();else {confirmations.push(dialog.message());await dialog.dismiss();}});
    await page.setViewportSize({width:1366,height:900});
    await check('Inicio: dos tareas claras y acceso con teclado',async()=>{
        await open('index.php');assert.equal(await page.locator('.task-choice').count(),2);
        await page.keyboard.press('Tab');assert.equal(await page.locator('.skip-link').evaluate(e=>e===document.activeElement),true);
        await page.keyboard.press('Enter');assert.equal(await page.locator('#mainContent').evaluate(e=>e===document.activeElement),true);
    });
    await check('Cuenta: opciones con texto y cierre con Escape',async()=>{
        await page.locator('.account-menu > summary').click();
        assert.equal(await page.locator('.account-actions a[href="logout.php"]').innerText(),'Cerrar sesión');
        await page.keyboard.press('Escape');assert.equal(await page.locator('.account-menu').getAttribute('open'),null);
        await page.locator('.account-menu > summary').click();await page.locator('.account-actions a[href="change_password.php"]').click();await page.waitForURL(/change_password\.php/);
    });
    await check('Campañas: planes con casillas, filtro real y seleccion',async()=>{
        await open('send.php');assert.equal(await page.locator('#extraFilters').getAttribute('open'),null);
        await page.locator('#extraFilters > summary').click();await page.locator('[name="plans[]"][value="Plan A"]').check();
        await page.locator('#searchRows').click();await ready();assert.match(await page.locator('#pageInfo').innerText(),/2\.000/);
        await page.locator('#selectPage').click();assert.match(await page.locator('#selectionCount').innerText(),/50 seleccionados/);
        await page.locator('#saveDraft').click();await ready();
        await page.reload();await ready();assert.equal(await page.locator('[name="plans[]"][value="Plan A"]').isChecked(),true);
        assert.notEqual(await page.locator('#extraFilters').getAttribute('open'),null);
        await page.locator('#clearFilters').click();await ready();assert.match(await page.locator('#pageInfo').innerText(),/4\.000/);
        assert.equal(await page.locator('[name="plans[]"]:checked').count(),0);
    });
    await check('Campañas: emails manuales y borrador recuperable',async()=>{
        await page.locator('#clearSelection').click();await page.locator('#manualRecipients > summary').click();
        await page.locator('#manual_emails').fill('usability@example.invalid');
        await page.locator('#saveDraft').click();await ready();const draft=new URL(page.url()).searchParams.get('draft');
        await open('send.php');await page.locator('.compose-drafts > summary').click();await page.locator('#loadDraft').selectOption(draft);await ready();
        assert.equal(await page.locator('#manual_emails').inputValue(),'usability@example.invalid');
        assert.notEqual(await page.locator('#manualRecipients').getAttribute('open'),null);
        await page.locator('[data-panel="1"] [data-step="2"]').click();await page.locator('#reviewSend').click();await ready();
        assert.equal(await page.locator('[data-panel="3"]').isVisible(),true);assert.match(await page.locator('#reviewCounts').innerText(),/Emails/);
        // Stop at review. Never click confirm or invoke a transport.
    });
    await check('Facturas: filtros adicionales y busqueda por cliente',async()=>{
        await open('invoices.php');assert.equal(await page.locator('#extraFilters').getAttribute('open'),null);
        await page.locator('#due_date').fill('2026-09-30');await page.locator('#extraFilters > summary').click();
        await page.locator('#snb').fill('00001');await page.locator('#searchRows').click();await ready();
        assert.equal(await page.locator('#recipientRows input').count(),1);assert.match(await page.locator('#pageInfo').innerText(),/1 resultados/);
    });
    await check('Seguimiento: resumen y detalle separados, paginacion conservada',async()=>{
        await open('activity.php');assert.equal(await page.locator('#recipientResults').count(),0);
        await page.locator('.bounded-table a').filter({hasText:'Prueba 4000'}).first().click();await page.waitForURL(/batch_id=/);
        assert.equal(await page.locator('#recipientResults tbody tr').count(),50);
        await page.locator('#recipientResults .pagination a').filter({hasText:'Siguiente'}).click();await page.waitForURL(/page=2/);
        assert.match(await page.locator('#recipientResults .pagination').innerText(),/Página 2/);
    });
    await check('Seguimiento: pausa, continuar y cancelacion de detener',async()=>{
        await page.locator('button[value=pause]').click();await page.waitForSelector('button[value=resume]');
        assert.ok(new URL(page.url()).searchParams.get('batch_id'));
        await page.locator('button[value=resume]').click();await page.waitForSelector('button[value=pause]');
        await page.locator('[data-confirm-stop]').click();assert.match(confirmations.at(-1),/Detenerlo/);
        assert.equal(await page.locator('button[value=pause]').isVisible(),true);
    });
    await check('Seguimiento: refresco conserva posicion de lectura',async()=>{
        await page.locator('[data-scroll-key=recipients]').evaluate(e=>e.scrollTop=200);
        await page.locator('h1').click();
        await page.waitForResponse(r=>r.url().includes('fragment=1'),{timeout:16000});
        await page.waitForFunction(()=>document.getElementById('activityRefresh').textContent.startsWith('Actualizado'));
        assert.equal(await page.locator('[data-scroll-key=recipients]').evaluate(e=>e.scrollTop),200);
    });
    await check('Plantilla: vista previa visible y cambios de contenido',async()=>{
        await open('template_edit.php?id=1');
        await page.frameLocator('iframe.preview-frame').locator('body').filter({hasText:/\S/}).waitFor();
        await page.locator('#builder_title').fill('VISTA PREVIA USABILIDAD');
        await page.frameLocator('iframe.preview-frame').getByText('VISTA PREVIA USABILIDAD',{exact:true}).waitFor();
        assert.equal(await page.locator('#campaignAttachments').isVisible(),true);
        await page.locator('button[value=save_draft]').click();await page.waitForURL(/draft=/);
        assert.equal(await page.locator('#builder_title').inputValue(),'VISTA PREVIA USABILIDAD');
    });
    await check('Validacion: un campo invalido se revela en opciones avanzadas',async()=>{
        await open('invoice_template_edit.php?id=1');const details=page.locator('.advanced-html-editor');
        await details.locator('summary').click();const original=await page.locator('#html_body').inputValue();
        await page.locator('#html_body').fill('');await details.locator('summary').click();
        await page.locator('button[value=save]').click();assert.notEqual(await details.getAttribute('open'),null);
        await page.locator('#html_body').fill(original);
    });
    await check('Movil: navegacion, desplegables y tablas accesibles',async()=>{
        for(const width of [390,320]) {
            await page.setViewportSize({width,height:844});await open('send.php');
            await page.locator('.compose-drafts > summary').click();
            const box=await page.locator('#loadDraft').boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width);
            await page.locator('.compose-drafts > summary').click();
            await page.locator('.menu-toggle').click();assert.equal(await page.locator('#mainNav').isVisible(),true);
            await page.locator('.menu-toggle').click();await open('clients.php');
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
            assert.match(await page.locator('.table-scroll-hint:visible').first().innerText(),/columnas/);
        }
    });
    console.log('USABILITY FLOWS OK: '+passed.length+' recorridos intuitivos, sin enviar mensajes.');
    return passed;
};
