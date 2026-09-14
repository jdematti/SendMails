<?php

declare(strict_types=1);

const APP_NAME = 'SendMails';
const BASE_PATH = __DIR__ . '/..';
const STORAGE_PATH = BASE_PATH . '/storage';
const DB_CONFIG_PATH = STORAGE_PATH . '/db_config.json';
const BRANCH_SECRET_KEY_PATH = STORAGE_PATH . '/branch_secret.key';
const DEFAULT_TEMPLATE_PATH = STORAGE_PATH . '/default_template.html';

const AUTH_SESSION_TIMEOUT_SECONDS = 10800;
const AUTH_PASSWORD_RESET_MINUTES = 60;
const INITIAL_ADMIN_USERNAME = 'admin';
const INITIAL_ADMIN_NAME = 'Jose';
const INITIAL_ADMIN_EMAIL = 'demattij@infracom.com.ar';
const INITIAL_ADMIN_PASSWORD_HASH = '$2y$10$WblPOWd3CEZOzdyWgJLYruLtbzLVdjHGePPlmFpRXXhZ6GZTsUdu6';

const CLIENT_SOURCE_SCHEMA = 'dbo';
const CLIENT_SOURCE = 'v_sendmail_clientes';

const TEMPLATE_VARIABLES = [
    '{{codigo_cliente}}',
    '{{razon_social}}',
    '{{plan}}',
    '{{email}}',
    '{{telefono_movil}}',
    '{{localidad}}',
    '{{provincia}}',
    '{{url_baja}}',
    '{{unsubscribe_url}}',
];
