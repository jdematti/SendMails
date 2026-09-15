const {chromium} = require(process.env.SENDMAILS_PLAYWRIGHT || 'playwright');
const assert = require('assert/strict');
const {execFileSync} = require('child_process');
const path = require('path');
assert.match(process.env.SENDMAILS_TEST_DATABASE || '', /^SendMails_Agility_Test_[a-f0-9]{12}$/);
function fixture(sql) {
    // Only the explicit local test database; never load production bootstrap/config.
    execFileSync('php', ['-r', `require 'tests/test_bootstrap.php'; Database::pdo()->exec(${JSON.stringify(sql)});`],
        {cwd:path.dirname(__dirname), env:process.env, stdio:'pipe'});
}
(async () => {
    const browser = await chromium.launch({headless:true,channel:'msedge'});
    try {
        const page = await browser.newPage();
        await page.goto('http://127.0.0.1:8765/purge.php');
        await page.locator('#previewPurge').click();
        await page.waitForSelector('#purgeConfirm');
        fixture("CREATE TRIGGER dbo.fixture_ui_slow_purge ON dbo.SendMail_Queue AFTER DELETE AS WAITFOR DELAY '00:00:20';");
        try {
            await page.locator('#purgeConfirm input[type=checkbox]').check();
            const responsePromise = page.waitForResponse(r => r.url().endsWith('/purge.php') && r.request().method()==='POST');
            await page.locator('#confirmPurge').click();
            const response = await responsePromise;
            assert.equal(response.status(),200,'Timeout controlado, sin pagina HTTP 500');
            await page.waitForSelector('.alert.error:visible');
            assert.match(await page.locator('.alert.error:visible').innerText(),/purga se revirtió/);
            assert.equal(await page.locator('#purgeConfirm').count(),0,'Requiere una nueva vista previa');
            assert.equal(await page.locator('.alert.success').count(),0);
        } finally { fixture('DROP TRIGGER dbo.fixture_ui_slow_purge'); }
        const key='purge_receipt:'+'e'.repeat(32);
        fixture(`INSERT INTO dbo.SendMail_Settings(setting_key,setting_value,updated_at) VALUES('${key}','{',DATEADD(day,1,SYSDATETIME()))`);
        try {
            const response=await page.goto('http://127.0.0.1:8765/purge.php');
            assert.equal(response.status(),200,'Error de comprobantes no derriba la pagina');
            assert.match(await page.locator('.alert.error:visible').innerText(),/No se pudieron consultar las últimas purgas/);
            assert.ok(!(await page.locator('body').innerText()).includes('Todavía no se realizaron purgas'));
        } finally { fixture(`DELETE FROM dbo.SendMail_Settings WHERE setting_key='${key}'`); }
        console.log('PURGE ERRORS BROWSER OK: timeout con rollback y mensaje; error al leer comprobantes sin HTTP 500. Solo fixtures.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
