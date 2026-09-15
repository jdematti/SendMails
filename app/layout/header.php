<?php
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$currentUser = Auth::currentUser();
$isAdminUser = Auth::isAdmin();
$currentBranch = $currentUser && Database::configExists() ? BranchRepository::selected() : null;
$navItems = [
    'index.php' => 'Inicio',
    'clients.php' => 'Clientes',
];
$templateItems = [
    ['href' => 'templates.php', 'label' => 'Campañas', 'pages' => ['templates.php', 'template_edit.php']],
    ['href' => 'invoice_templates.php', 'label' => 'Facturas', 'pages' => ['invoice_templates.php', 'invoice_template_edit.php']],
    ['href' => 'whatsapp_templates.php', 'label' => 'WhatsApp', 'pages' => ['whatsapp_templates.php']],
];
$campaignItems = [
    ['href' => 'send.php', 'label' => 'Crear Campaña', 'pages' => ['send.php']],
    ['href' => 'activity.php?kind=campaign', 'label' => 'Ver campañas', 'pages' => ['campaigns.php'], 'kind'=>'campaign'],
];
$invoiceItems = [
    ['href' => 'invoices.php', 'label' => 'Preparar Facturas', 'pages' => ['invoices.php']],
    ['href' => 'activity.php?kind=invoice', 'label' => 'Ver facturas', 'pages' => ['invoice_sends.php'], 'kind'=>'invoice'],
];
$configItems = [
    'users.php' => 'Usuarios',
    'branches.php' => 'Sucursales',
    'smtp.php' => 'SMTP',
    'whatsapp.php' => 'WhatsApp / Meta',
    'config_db.php' => 'Base de datos',
    'purge.php' => 'Purgar historial',
];
$tailNavItems = [
    'activity.php' => 'Seguimiento de envíos',
];
$isSubItemActive = static function (array $item) use ($currentPage): bool {
    if ($currentPage === 'activity.php' && isset($item['kind'])) return query_string('kind') === $item['kind'];
    if ($currentPage === 'queue.php' && isset($item['type'])) {
        $queueType = query_string('type');
        if ($queueType === '') {
            $queueType = UnifiedQueueService::TYPE_CAMPAIGNS;
        }

        return $queueType === (string) $item['type'];
    }

    return in_array($currentPage, $item['pages'] ?? [$item['href']], true);
};
$isGroupActive = static function (array $items) use ($isSubItemActive): bool {
    foreach ($items as $item) {
        if ($isSubItemActive($item)) {
            return true;
        }
    }

    return false;
};
$isTemplatesActive = $isGroupActive($templateItems);
$isCampaignsActive = $isGroupActive($campaignItems);
$isInvoicesActive = $isGroupActive($invoiceItems);
$isConfigActive = array_key_exists($currentPage, $configItems);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? APP_NAME) ?> | <?= APP_NAME ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/agility.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="index.php" data-wait>
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <span>
                    <strong>SendMails</strong>
                </span>
            </a>
            <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="mainNav" aria-label="Abrir menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
        <nav class="nav" id="mainNav">
            <?php foreach ($navItems as $href => $label): ?>
                <a href="<?= e($href) ?>" class="<?= $currentPage === $href && ($href !== 'activity.php' || query_string('kind') === '') ? 'active' : '' ?>" data-wait><?= e($label) ?></a>
            <?php endforeach; ?>

            <div class="nav-group <?= $isTemplatesActive ? 'open active' : '' ?>" data-nav-group>
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $isTemplatesActive ? 'true' : 'false' ?>">
                    <span>Plantillas</span>
                </button>
                <div class="nav-submenu">
                    <?php foreach ($templateItems as $item): ?>
                        <a href="<?= e($item['href']) ?>" class="<?= $isSubItemActive($item) ? 'active' : '' ?>" data-wait><?= e($item['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="nav-group <?= $isCampaignsActive ? 'open active' : '' ?>" data-nav-group>
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $isCampaignsActive ? 'true' : 'false' ?>">
                    <span>Campañas</span>
                </button>
                <div class="nav-submenu">
                    <?php foreach ($campaignItems as $item): ?>
                        <a href="<?= e($item['href']) ?>" class="<?= $isSubItemActive($item) ? 'active' : '' ?>" data-wait><?= e($item['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="nav-group <?= $isInvoicesActive ? 'open active' : '' ?>" data-nav-group>
                <button class="nav-group-toggle" type="button" aria-expanded="<?= $isInvoicesActive ? 'true' : 'false' ?>">
                    <span>Facturas</span>
                </button>
                <div class="nav-submenu">
                    <?php foreach ($invoiceItems as $item): ?>
                        <a href="<?= e($item['href']) ?>" class="<?= $isSubItemActive($item) ? 'active' : '' ?>" data-wait><?= e($item['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($tailNavItems as $href => $label): ?>
                <a href="<?= e($href) ?>" class="<?= $currentPage === $href && ($href !== 'activity.php' || query_string('kind') === '') ? 'active' : '' ?>" data-wait><?= e($label) ?></a>
            <?php endforeach; ?>

            <?php if ($isAdminUser): ?>
                <div class="nav-group <?= $isConfigActive ? 'open active' : '' ?>" data-nav-group>
                    <button class="nav-group-toggle" type="button" aria-expanded="<?= $isConfigActive ? 'true' : 'false' ?>">
                        <span>Configuracion</span>
                    </button>
                    <div class="nav-submenu">
                        <?php foreach ($configItems as $href => $label): ?>
                            <a href="<?= e($href) ?>" class="<?= $currentPage === $href ? 'active' : '' ?>" data-wait><?= e($label) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
    </aside>
    <main class="main">
        <header class="topbar">
            <div>
                <h1><?= e($pageTitle ?? APP_NAME) ?></h1>
                <?php if (!empty($pageSubtitle)): ?>
                    <p><?= e($pageSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($currentUser): ?>
                <div class="topbar-user">
                    <?php if ($currentBranch): ?>
                        <?php require __DIR__ . '/service_control.php'; ?>
                        <a class="topbar-branch" href="branch_select.php" data-wait title="Cambiar sucursal">
                            <?= e((string) $currentBranch['name']) ?>
                        </a>
                    <?php endif; ?>
                    <span><?= e($currentUser['full_name']) ?> · <?= e($currentUser['role']) ?></span>
                    <a class="topbar-icon-action" href="change_password.php" data-wait title="Cambiar contraseña" aria-label="Cambiar contraseña">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="8" cy="15" r="3"></circle>
                            <path d="M10.6 13.4 18 6"></path>
                            <path d="M16.2 7.8 18.8 10.4"></path>
                            <path d="M13.9 10.1 16 12.2"></path>
                        </svg>
                    </a>
                    <a class="topbar-icon-action" href="logout.php" title="Salir" aria-label="Salir">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M10 5H6.75A1.75 1.75 0 0 0 5 6.75v10.5c0 .97.78 1.75 1.75 1.75H10"></path>
                            <path d="M15 8l4 4-4 4"></path>
                            <path d="M19 12H9"></path>
                        </svg>
                    </a>
                </div>
            <?php endif; ?>
        </header>

        <?php foreach (flashes() as $item): ?>
            <div class="alert <?= e($item['type']) ?>"><?= e($item['message']) ?></div>
        <?php endforeach; ?>
