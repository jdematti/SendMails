        <div class="wait-modal" id="waitModal" aria-hidden="true">
            <div class="wait-dialog" role="status" aria-live="polite">
                <span class="wait-spinner"></span>
                <span>Espere...</span>
            </div>
        </div>
    </main>
</div>
<script>
(() => {
    const modal = document.getElementById('waitModal');
    const showWait = () => {
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    };

    document.querySelectorAll('a[data-wait]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || link.target === '_blank' || link.href === window.location.href) return;
            showWait();
        });
    });

    document.querySelectorAll('form[data-wait-form]').forEach((form) => {
        form.addEventListener('submit', () => showWait());
    });

    const menuToggle = document.querySelector('.menu-toggle');
    const mainNav = document.getElementById('mainNav');
    if (menuToggle && mainNav) {
        const setMenu = (open) => {
            document.body.classList.toggle('menu-open', open);
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuToggle.setAttribute('aria-label', open ? 'Cerrar menu' : 'Abrir menu');
        };

        menuToggle.addEventListener('click', () => {
            setMenu(!document.body.classList.contains('menu-open'));
        });

        mainNav.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setMenu(false));
        });
    }

    document.querySelectorAll('[data-nav-group]').forEach((group) => {
        const toggle = group.querySelector('.nav-group-toggle');
        if (!toggle) return;

        toggle.addEventListener('click', () => {
            const open = !group.classList.contains('open');
            group.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
})();
</script>
</body>
</html>
