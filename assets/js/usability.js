(() => {
    const refreshTables = () => document.querySelectorAll('.table-wrap').forEach(table => {
        let hint = table.nextElementSibling;
        if (!hint?.classList.contains('table-scroll-hint')) {
            hint = document.createElement('p'); hint.className = 'hint table-scroll-hint'; table.after(hint);
        }
        const horizontal = table.scrollWidth > table.clientWidth + 1;
        const vertical = table.scrollHeight > table.clientHeight + 1;
        hint.hidden = !horizontal && !vertical;
        hint.textContent = horizontal ? '↔ Desplazá la tabla para ver más columnas.' : '↕ Desplazá la tabla para ver más filas.';
        if (horizontal || vertical) {
            table.tabIndex = 0;
            if (!table.hasAttribute('aria-label')) table.setAttribute('aria-label','Tabla de ' + (document.querySelector('h1')?.textContent || 'resultados'));
        }
    });
    refreshTables();
    window.addEventListener('resize', refreshTables);
    document.addEventListener('sendmails:tables', refreshTables);
    document.addEventListener('toggle', event => { if (event.target.tagName === 'DETAILS') refreshTables(); }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') document.querySelectorAll('.account-menu[open], .compose-drafts[open]').forEach(menu => { menu.open=false; menu.querySelector('summary').focus(); });
    });
})();
