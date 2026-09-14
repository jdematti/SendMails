<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Campañas';
$pageSubtitle = 'Crear campañas y dejarlas en cola.';
$term = query_string('q');
$selectedPlansValue = $_GET['plan'] ?? [];
if (!is_array($selectedPlansValue)) {
    $selectedPlansValue = $selectedPlansValue === '' ? [] : [$selectedPlansValue];
}
$selectedPlans = array_values(array_filter(array_map(
    static fn ($value): string => trim(is_scalar($value) ? (string) $value : ''),
    $selectedPlansValue
), static fn (string $value): bool => $value !== ''));
$templates = [];
$whatsAppTemplates = [];
$clients = [];
$plans = [];
$error = '';
$channel = post_string('channel', query_string('channel', 'email'));
if (!in_array($channel, ['email', 'whatsapp', 'both'], true)) {
    $channel = 'email';
}
$selectedTemplateId = normalize_int((string) ($_POST['template_id'] ?? query_string('template_id')), 0, 0);
$selectedWhatsAppTemplateId = normalize_int((string) ($_POST['whatsapp_template_id'] ?? query_string('whatsapp_template_id')), 0, 0);
$scheduledAtPosted = $_POST['scheduled_at'] ?? '';
$scheduledAtInput = trim(is_scalar($scheduledAtPosted) ? (string) $scheduledAtPosted : '');
if ($scheduledAtInput === '') {
    $scheduledAtInput = date('Y-m-d\TH:i');
}
$manualEmailsPosted = $_POST['manual_emails'] ?? '';
$manualEmailsInput = trim(is_scalar($manualEmailsPosted) ? (string) $manualEmailsPosted : '');

try {
    Schema::ensure();
    $templates = TemplateRepository::all(true);
    $whatsAppTemplates = WhatsAppRepository::readyTemplates(WhatsAppRepository::SOURCE_CAMPAIGN);
    $plans = ClientRepository::plans();
    $clients = ClientRepository::allReachable();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $usesEmail = in_array($channel, ['email', 'both'], true);
        $usesWhatsApp = in_array($channel, ['whatsapp', 'both'], true);
        $templateId = 0;
        if ($usesEmail) {
            $templateId = normalize_int(post_string('template_id'), 0, 1);
            $template = TemplateRepository::find($templateId);
            if (!$template || !(bool) $template['is_active']) {
                throw new RuntimeException('Selecciona una plantilla de email activa.');
            }
        }
        $whatsAppTemplateId = 0;
        if ($usesWhatsApp) {
            $whatsAppTemplateId = normalize_int(post_string('whatsapp_template_id'), 0, 1);
            $whatsAppTemplate = WhatsAppRepository::findTemplate($whatsAppTemplateId);
            if (!$whatsAppTemplate || empty($whatsAppTemplate['is_ready']) || (string) $whatsAppTemplate['category'] !== 'MARKETING') {
                throw new RuntimeException('Selecciona una plantilla MARKETING de WhatsApp aprobada y configurada.');
            }
        }

        $tokens = $_POST['client_token'] ?? [];
        $selectedClients = ClientRepository::findBySelectionTokens(is_array($tokens) ? $tokens : []);
        if (!$selectedClients) {
            throw new RuntimeException('Selecciona al menos un cliente destinatario.');
        }

        $manualRecipients = [];
        $invalidManualEmails = [];
        if ($usesEmail && $manualEmailsInput !== '') {
            $manualEmails = preg_split('/[\s,;]+/', $manualEmailsInput) ?: [];
            foreach ($manualEmails as $manualEmail) {
                $manualEmail = trim((string) $manualEmail);
                if ($manualEmail === '') {
                    continue;
                }
                if (!filter_var($manualEmail, FILTER_VALIDATE_EMAIL)) {
                    $invalidManualEmails[] = $manualEmail;
                    continue;
                }
                $manualRecipients[] = [
                    'branch_id' => (int) (BranchRepository::currentId() ?? 0),
                    'oid' => 'manual:' . substr(hash('sha1', strtolower($manualEmail)), 0, 16),
                    'codigo_cliente' => 'Manual',
                    'razon_social' => 'Email manual',
                    'plan_contratado' => '',
                    'email' => $manualEmail,
                    'telefono_movil' => '',
                    'localidad' => '',
                    'provincia' => '',
                    'manual_email' => '1',
                ];
            }
        }

        if ($invalidManualEmails) {
            throw new RuntimeException('Emails manuales invalidos: ' . implode(', ', array_unique($invalidManualEmails)));
        }

        $emailClients = array_values(array_filter(array_merge($selectedClients, $manualRecipients), static function (array $client): bool {
            return filter_var((string) ($client['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
        }));
        $emailClients = UnsubscribeRepository::filterSubscribedClients($emailClients);
        $emailClients = EmailExclusionRepository::filterAllowedRecipients($emailClients);
        $uniqueClients = [];
        $seenEmails = [];
        foreach ($emailClients as $client) {
            $email = UnsubscribeRepository::normalizeEmail((string) ($client['email'] ?? ''));
            if ($email === '' || isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            $uniqueClients[] = $client;
        }
        $emailClients = $uniqueClients;

        if ($usesEmail && !$emailClients) {
            throw new RuntimeException('Agrega al menos un destinatario con email valido, suscripcion activa y envios habilitados.');
        }

        $name = post_string('campaign_name');
        if ($name === '') {
            $name = 'Envio individual - ' . date('d/m/Y H:i');
        }

        $scheduledAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', post_string('scheduled_at'));
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$scheduledAt || (is_array($dateErrors) && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))) {
            throw new RuntimeException('Selecciona una fecha y hora de inicio valida.');
        }
        $scheduledAtSql = $scheduledAt->format('Y-m-d H:i:00');

        $messages = [];
        $campaignId = 0;
        if ($usesEmail) {
            $campaignId = QueueRepository::createCampaign($templateId, $name, 'individual', $emailClients, $scheduledAtSql);
            $messages[] = count($emailClients) . ' emails encolados';
        }
        if ($usesWhatsApp) {
            $whatsAppBatch = WhatsAppRepository::createBatch(
                WhatsAppRepository::SOURCE_CAMPAIGN,
                $whatsAppTemplateId,
                $name,
                $selectedClients,
                $scheduledAtSql
            );
            $messages[] = (int) $whatsAppBatch['queued'] . ' WhatsApp encolados';
            if ((int) $whatsAppBatch['invalid'] > 0) {
                $messages[] = (int) $whatsAppBatch['invalid'] . ' celulares invalidos omitidos';
            }
            if ((int) $whatsAppBatch['opted_out'] > 0) {
                $messages[] = (int) $whatsAppBatch['opted_out'] . ' bajas de WhatsApp omitidas';
            }
        }
        flash('success', 'Campaña creada: ' . implode(', ', $messages) . '. Inicio programado: ' . $scheduledAt->format('d/m/Y H:i') . '.');
        $queueChannel = $channel === 'both' ? 'all' : $channel;
        redirect('queue.php?type=campaigns&channel=' . rawurlencode($queueChannel) . ($campaignId > 0 ? '&campaign_id=' . $campaignId : ''));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <p class="hint">La campaña no envía correos al crearla. Solo genera registros pendientes en la cola. El panel derecho define los destinatarios reales del envío.</p>

    <form id="campaignForm" method="post" class="campaign-row" data-wait-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="channel">Canal</label>
            <select id="channel" name="channel" required>
                <option value="email"<?= selected('email', $channel) ?>>Email</option>
                <option value="whatsapp"<?= selected('whatsapp', $channel) ?>>WhatsApp</option>
                <option value="both"<?= selected('both', $channel) ?>>Email + WhatsApp</option>
            </select>
        </div>
        <div class="field">
            <label for="campaign_name">Nombre de campaña</label>
            <input id="campaign_name" name="campaign_name" value="<?= e(post_string('campaign_name')) ?>" placeholder="Ej: Promocion TV Mayo">
        </div>
        <div class="field" id="emailTemplateField">
            <label for="template_id">Plantilla</label>
            <select id="template_id" name="template_id">
                <option value="">Seleccionar</option>
                <?php foreach ($templates as $template): ?>
                    <option value="<?= e((string) $template['id']) ?>"<?= selected((string) $template['id'], (string) $selectedTemplateId) ?>><?= e($template['name']) ?> - <?= e($template['subject']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" id="whatsAppTemplateField">
            <label for="whatsapp_template_id">Plantilla de WhatsApp</label>
            <select id="whatsapp_template_id" name="whatsapp_template_id">
                <option value="">Seleccionar</option>
                <?php foreach ($whatsAppTemplates as $template): ?>
                    <option value="<?= e((string) $template['id']) ?>"<?= selected((string) $template['id'], (string) $selectedWhatsAppTemplateId) ?>><?= e((string) $template['name']) ?> · <?= e((string) $template['language']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$whatsAppTemplates): ?><p class="hint">No hay plantillas MARKETING aprobadas y configuradas.</p><?php endif; ?>
        </div>
        <div class="field">
            <label for="scheduled_at">Iniciar envio</label>
            <input id="scheduled_at" name="scheduled_at" type="datetime-local" value="<?= e($scheduledAtInput) ?>" required>
        </div>
        <div class="field action-field">
            <label>&nbsp;</label>
            <button type="submit">Crear cola</button>
        </div>
        <div class="field full campaign-manual-emails" id="manualEmailsField">
            <label for="manual_emails">Emails manuales</label>
            <textarea id="manual_emails" name="manual_emails" rows="3" placeholder="uno@dominio.com&#10;otro@dominio.com"><?= e($manualEmailsInput) ?></textarea>
            <p class="hint">Se suman a la campaña como destinatarios manuales. Podes separarlos con enter, coma, punto y coma o espacios.</p>
        </div>
    </form>

    <form class="client-filter-bar" data-client-filter>
        <input type="hidden" name="channel" value="<?= e($channel) ?>">
        <div class="field">
            <label for="q">Filtrar clientes</label>
            <input id="q" name="q" value="<?= e($term) ?>" placeholder="Razon social, codigo, email o ID" autocomplete="off">
        </div>
        <div class="field">
            <label id="planFilterLabel">Plan</label>
            <div class="multi-select" data-plan-filter>
                <button class="multi-select-toggle" type="button" aria-expanded="false" aria-labelledby="planFilterLabel" aria-controls="planFilterOptions">
                    <span data-plan-summary>Todos los planes</span>
                </button>
                <div class="multi-select-panel" id="planFilterOptions">
                    <div class="multi-select-search">
                        <input type="search" placeholder="Buscar plan" autocomplete="off" data-plan-search>
                    </div>
                    <?php foreach ($plans as $index => $plan): ?>
                        <?php $planId = 'plan_' . $index; ?>
                        <label class="multi-select-option" for="<?= e($planId) ?>" data-plan-option data-plan-option-text="<?= e($plan) ?>">
                            <input id="<?= e($planId) ?>" type="checkbox" name="plan[]" value="<?= e($plan) ?>" data-plan-check<?= checked(in_array($plan, $selectedPlans, true)) ?>>
                            <span><?= e($plan) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <div class="empty" data-plan-empty-search hidden>No hay planes para esa búsqueda.</div>
                    <?php if (!$plans): ?>
                        <div class="empty">No hay planes disponibles.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="field action-field">
            <label>&nbsp;</label>
            <div class="actions">
                <button type="submit">Buscar</button>
                <button type="button" class="btn secondary" data-clear-client-filter>Limpiar</button>
            </div>
        </div>
    </form>

    <div class="recipient-builder" data-recipient-builder>
        <section class="recipient-panel" aria-labelledby="availableClientsTitle">
            <div class="recipient-panel-header">
                <div>
                    <h3 id="availableClientsTitle">Clientes disponibles</h3>
                    <p class="hint" data-available-count>0 disponibles</p>
                </div>
            </div>
            <div class="recipient-list" data-client-list="available">
                <?php foreach ($clients as $index => $client): ?>
<?php
                    $oid = trim((string) $client['oid']);
                    $selectionToken = ClientRepository::selectionToken($client, $index);
                    $searchText = implode(' ', [
                        (string) $client['codigo_cliente'],
                        (string) $client['razon_social'],
                        (string) $client['plan_contratado'],
                        (string) $client['email'],
                        (string) $client['telefono_movil'],
                    ]);
                    ?>
                    <label
                        class="client-pick-row"
                        data-client-row
                        data-client-id="<?= e($oid) ?>"
                        data-client-token="<?= e($selectionToken) ?>"
                        data-client-plan="<?= e((string) $client['plan_contratado']) ?>"
                        data-client-search="<?= e($searchText) ?>"
                        data-sort-index="<?= e((string) $index) ?>"
                    >
                        <input type="checkbox" data-client-check aria-label="Seleccionar <?= e($client['razon_social']) ?>">
                        <span>
                            <strong><?= e($client['razon_social']) ?></strong><br>
                            <span class="muted"><?= e($client['codigo_cliente']) ?> | <?= e((string) $client['plan_contratado']) ?></span>
                        </span>
                        <span class="email-col">
                            <?= e((string) $client['email']) ?>
                            <?php if (trim((string) $client['telefono_movil']) !== ''): ?><br><span class="muted">WhatsApp: <?= e((string) $client['telefono_movil']) ?></span><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
                <div class="empty" data-empty-available>No hay clientes para seleccionar.</div>
            </div>
        </section>

        <div class="recipient-transfer" aria-label="Mover clientes entre paneles">
            <button type="button" class="btn secondary" data-move-clients="add-selected" aria-label="Agregar seleccionados" title="Agregar seleccionados">&gt;</button>
            <button type="button" class="btn secondary" data-move-clients="add-all" aria-label="Agregar todos" title="Agregar todos">&gt;&gt;</button>
            <button type="button" class="btn secondary" data-move-clients="remove-selected" aria-label="Quitar seleccionados" title="Quitar seleccionados">&lt;</button>
            <button type="button" class="btn secondary" data-move-clients="remove-all" aria-label="Quitar todos" title="Quitar todos">&lt;&lt;</button>
        </div>

        <section class="recipient-panel" aria-labelledby="selectedClientsTitle">
            <div class="recipient-panel-header">
                <div>
                    <h3 id="selectedClientsTitle">Destinatarios de la campaña</h3>
                    <p class="hint" data-selected-count>0 seleccionados</p>
                </div>
            </div>
            <div class="recipient-list" data-client-list="selected">
                <div class="empty" data-empty-selected>Pasa clientes a este panel para incluirlos en la campaña.</div>
            </div>
        </section>
    </div>
</section>

<script>
(() => {
    const channel = document.getElementById('channel');
    const emailTemplateField = document.getElementById('emailTemplateField');
    const whatsAppTemplateField = document.getElementById('whatsAppTemplateField');
    const manualEmailsField = document.getElementById('manualEmailsField');
    const emailTemplate = document.getElementById('template_id');
    const whatsAppTemplate = document.getElementById('whatsapp_template_id');
    const updateChannelFields = () => {
        const value = channel?.value || 'email';
        const usesEmail = value === 'email' || value === 'both';
        const usesWhatsApp = value === 'whatsapp' || value === 'both';
        if (emailTemplateField) emailTemplateField.hidden = !usesEmail;
        if (manualEmailsField) manualEmailsField.hidden = !usesEmail;
        if (whatsAppTemplateField) whatsAppTemplateField.hidden = !usesWhatsApp;
        if (emailTemplate) emailTemplate.required = usesEmail;
        if (whatsAppTemplate) whatsAppTemplate.required = usesWhatsApp;
    };
    channel?.addEventListener('change', updateChannelFields);
    updateChannelFields();

    const builder = document.querySelector('[data-recipient-builder]');
    if (!builder) return;

    const availableList = builder.querySelector('[data-client-list="available"]');
    const selectedList = builder.querySelector('[data-client-list="selected"]');
    const filterForm = document.querySelector('[data-client-filter]');
    const searchInput = filterForm?.querySelector('[name="q"]');
    const planFilter = filterForm?.querySelector('[data-plan-filter]');
    const planToggle = planFilter?.querySelector('.multi-select-toggle');
    const planSummary = planFilter?.querySelector('[data-plan-summary]');
    const planSearch = planFilter?.querySelector('[data-plan-search]');
    const planChecks = () => Array.from(planFilter?.querySelectorAll('[data-plan-check]') || []);
    const planOptions = () => Array.from(planFilter?.querySelectorAll('[data-plan-option]') || []);
    const planEmptySearch = planFilter?.querySelector('[data-plan-empty-search]');
    const clearFilterButton = document.querySelector('[data-clear-client-filter]');
    const waitModal = document.getElementById('waitModal');

    const normalize = (value) => (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

    const rows = (list) => Array.from(list.querySelectorAll('[data-client-row]'));
    const visibleRows = (list) => rows(list).filter((row) => !row.hidden);
    const checkedRows = (list) => visibleRows(list).filter((row) => row.querySelector('[data-client-check]')?.checked);
    const selectedPlans = () => planChecks().filter((input) => input.checked).map((input) => input.value);

    const updatePlanSummary = () => {
        const plans = selectedPlans();
        if (!planSummary) return;

        if (plans.length === 0) {
            planSummary.textContent = 'Todos los planes';
        } else if (plans.length === 1) {
            planSummary.textContent = plans[0];
        } else {
            planSummary.textContent = plans.length + ' planes seleccionados';
        }
    };

    const applyPlanSearch = () => {
        const term = normalize(planSearch?.value || '');
        let visible = 0;

        planOptions().forEach((option) => {
            const matches = term === '' || normalize(option.dataset.planOptionText || '').includes(term);
            option.hidden = !matches;
            if (matches) {
                visible++;
            }
        });

        if (planEmptySearch) {
            planEmptySearch.hidden = visible > 0;
        }
    };

    const showWait = () => {
        if (!waitModal) return;
        waitModal.classList.add('show');
        waitModal.setAttribute('aria-hidden', 'false');
    };

    const hideWait = () => {
        if (!waitModal) return;
        waitModal.classList.remove('show');
        waitModal.setAttribute('aria-hidden', 'true');
    };

    const sortList = (list) => {
        rows(list)
            .sort((a, b) => Number(a.dataset.sortIndex || 0) - Number(b.dataset.sortIndex || 0))
            .forEach((row) => list.appendChild(row));
    };

    const ensureHiddenInput = (row) => {
        if (row.querySelector('input[type="hidden"][name="client_token[]"]')) return;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'client_token[]';
        hidden.value = row.dataset.clientToken || '';
        hidden.setAttribute('form', 'campaignForm');
        row.appendChild(hidden);
    };

    const removeHiddenInput = (row) => {
        row.querySelector('input[type="hidden"][name="client_token[]"]')?.remove();
    };

    const applyFilter = () => {
        const term = normalize(searchInput?.value || '');
        const plans = selectedPlans();

        rows(availableList).forEach((row) => {
            const matchesText = term === '' || normalize(row.dataset.clientSearch || '').includes(term);
            const matchesPlan = plans.length === 0 || plans.includes(row.dataset.clientPlan || '');
            row.hidden = !(matchesText && matchesPlan);
        });

        updateState();
    };

    const updateState = () => {
        const availableVisible = visibleRows(availableList).length;
        const selectedTotal = rows(selectedList).length;
        const availableChecked = checkedRows(availableList).length;
        const selectedChecked = checkedRows(selectedList).length;

        const availableCount = document.querySelector('[data-available-count]');
        const selectedCount = document.querySelector('[data-selected-count]');
        if (availableCount) {
            availableCount.textContent = availableVisible === 1 ? '1 disponible' : `${availableVisible} disponibles`;
        }
        if (selectedCount) {
            selectedCount.textContent = selectedTotal === 1 ? '1 seleccionado' : `${selectedTotal} seleccionados`;
        }

        const emptyAvailable = document.querySelector('[data-empty-available]');
        const emptySelected = document.querySelector('[data-empty-selected]');
        if (emptyAvailable) emptyAvailable.hidden = availableVisible > 0;
        if (emptySelected) emptySelected.hidden = selectedTotal > 0;

        builder.querySelector('[data-move-clients="add-selected"]').disabled = availableChecked === 0;
        builder.querySelector('[data-move-clients="add-all"]').disabled = availableVisible === 0;
        builder.querySelector('[data-move-clients="remove-selected"]').disabled = selectedChecked === 0;
        builder.querySelector('[data-move-clients="remove-all"]').disabled = selectedTotal === 0;
    };

    const moveToSelected = (items) => {
        items.forEach((row) => {
            row.hidden = false;
            row.querySelector('[data-client-check]').checked = false;
            ensureHiddenInput(row);
            selectedList.appendChild(row);
        });
        sortList(selectedList);
        applyFilter();
    };

    const moveToAvailable = (items) => {
        items.forEach((row) => {
            row.querySelector('[data-client-check]').checked = false;
            removeHiddenInput(row);
            availableList.appendChild(row);
        });
        sortList(availableList);
        applyFilter();
    };

    builder.querySelectorAll('[data-move-clients]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.moveClients;
            if (action === 'add-selected') moveToSelected(checkedRows(availableList));
            if (action === 'add-all') moveToSelected(visibleRows(availableList));
            if (action === 'remove-selected') moveToAvailable(checkedRows(selectedList));
            if (action === 'remove-all') moveToAvailable(rows(selectedList));
        });
    });

    builder.addEventListener('change', (event) => {
        if (event.target?.matches('[data-client-check]')) {
            updateState();
        }
    });

    planToggle?.addEventListener('click', () => {
        const open = !planFilter.classList.contains('open');
        planFilter.classList.toggle('open', open);
        planToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            applyPlanSearch();
            window.setTimeout(() => planSearch?.focus(), 0);
        }
    });

    planFilter?.addEventListener('change', (event) => {
        if (event.target?.matches('[data-plan-check]')) {
            updatePlanSummary();
        }
    });

    planSearch?.addEventListener('input', applyPlanSearch);

    planSearch?.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            planFilter?.classList.remove('open');
            planToggle?.setAttribute('aria-expanded', 'false');
            planToggle?.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!planFilter || planFilter.contains(event.target)) return;
        planFilter.classList.remove('open');
        planToggle?.setAttribute('aria-expanded', 'false');
    });

    filterForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        planFilter?.classList.remove('open');
        planToggle?.setAttribute('aria-expanded', 'false');
        showWait();
        window.setTimeout(() => {
            applyFilter();
            hideWait();
        }, 120);
    });

    clearFilterButton?.addEventListener('click', () => {
        showWait();
        window.setTimeout(() => {
            if (searchInput) searchInput.value = '';
            if (planSearch) planSearch.value = '';
            planChecks().forEach((input) => {
                input.checked = false;
            });
            updatePlanSummary();
            applyPlanSearch();
            planFilter?.classList.remove('open');
            planToggle?.setAttribute('aria-expanded', 'false');
            applyFilter();
            hideWait();
        }, 120);
    });

    updatePlanSummary();
    applyPlanSearch();
    applyFilter();
})();
</script>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
