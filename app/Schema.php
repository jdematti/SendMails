<?php

declare(strict_types=1);

final class Schema
{
    public const VERSION = '20260914-1';
    private static bool $checked = false;
    private static bool $migrating = false;

    public static function ensure(): void
    {
        if (self::$checked || self::$migrating) return;
        try {
            $version = Database::pdo()->query("SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = 'schema_version'")->fetchColumn();
        } catch (Throwable $e) {
            throw new SchemaNotReady('La base necesita inicializacion. Ejecuta php migrate.php desde la carpeta del sistema.', 0, $e);
        }
        if ($version !== self::VERSION) {
            throw new SchemaNotReady('Hay una actualizacion de base pendiente. Ejecuta actualizar_produccion.cmd o php migrate.php.');
        }
        self::$checked = true;
    }

    public static function migrate(): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Las migraciones se ejecutan por consola.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        self::$migrating = true;
        try {
            $lock = $pdo->query("DECLARE @r int; EXEC @r = sp_getapplock @Resource='SendMails:migration', @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=0; SELECT @r")->fetchColumn();
            if ((int) $lock < 0) throw new RuntimeException('Otra migracion esta en curso.');
            $version = null;
            if ($pdo->query("SELECT OBJECT_ID('dbo.SendMail_Settings', 'U')")->fetchColumn()) {
                $version = $pdo->query("SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = 'schema_version'")->fetchColumn();
            }
            if ($version !== self::VERSION) {
                self::applyLegacy($version === null && !$pdo->query("SELECT OBJECT_ID('dbo.SendMail_Users', 'U')")->fetchColumn());
                AgilityMigration::run($pdo);
                $stmt = $pdo->prepare("UPDATE dbo.SendMail_Settings SET setting_value=:version, updated_at=SYSDATETIME() WHERE setting_key='schema_version'");
                $stmt->execute([':version' => self::VERSION]);
                if (!$stmt->rowCount()) {
                    $stmt = $pdo->prepare("INSERT INTO dbo.SendMail_Settings(setting_key, setting_value) VALUES ('schema_version', :version)");
                    $stmt->execute([':version' => self::VERSION]);
                }
            }
            $pdo->commit();
            self::$checked = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally { self::$migrating = false; }
    }

    private static function applyLegacy(bool $seed): void
    {
        $pdo = Database::pdo();

        $statements = [
            "IF OBJECT_ID('dbo.SendMail_Settings', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Settings (
                    setting_key nvarchar(100) NOT NULL CONSTRAINT PK_SendMail_Settings PRIMARY KEY,
                    setting_value nvarchar(max) NULL,
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Settings_updated DEFAULT SYSDATETIME()
                )",
            "IF OBJECT_ID('dbo.SendMail_Templates', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Templates (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Templates PRIMARY KEY,
                    name nvarchar(150) NOT NULL,
                    subject nvarchar(250) NOT NULL,
                    html_body nvarchar(max) NOT NULL,
                    is_active bit NOT NULL CONSTRAINT DF_SendMail_Templates_active DEFAULT 1,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Templates_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Templates_updated DEFAULT SYSDATETIME()
                )",
            "IF COL_LENGTH('dbo.SendMail_Templates', 'attachments_json') IS NULL
                ALTER TABLE dbo.SendMail_Templates ADD attachments_json nvarchar(max) NULL",
            "IF OBJECT_ID('dbo.SendMail_Campaigns', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Campaigns (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Campaigns PRIMARY KEY,
                    template_id int NOT NULL,
                    name nvarchar(180) NOT NULL,
                    send_mode nvarchar(30) NOT NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_Campaigns_status DEFAULT 'queued',
                    total_queued int NOT NULL CONSTRAINT DF_SendMail_Campaigns_queued DEFAULT 0,
                    total_sent int NOT NULL CONSTRAINT DF_SendMail_Campaigns_sent DEFAULT 0,
                    total_failed int NOT NULL CONSTRAINT DF_SendMail_Campaigns_failed DEFAULT 0,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Campaigns_created DEFAULT SYSDATETIME(),
                    started_at datetime2(0) NULL,
                    completed_at datetime2(0) NULL
                )",
            "IF OBJECT_ID('dbo.SendMail_Queue', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Queue (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Queue PRIMARY KEY,
                    campaign_id int NOT NULL,
                    template_id int NOT NULL,
                    client_oid nvarchar(80) NULL,
                    client_code nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    email_to nvarchar(320) NOT NULL,
                    context_json nvarchar(max) NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_Queue_status DEFAULT 'pending',
                    attempts int NOT NULL CONSTRAINT DF_SendMail_Queue_attempts DEFAULT 0,
                    scheduled_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Queue_scheduled DEFAULT SYSDATETIME(),
                    sent_at datetime2(0) NULL,
                    last_error nvarchar(max) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Queue_created DEFAULT SYSDATETIME()
                )",
            "IF COL_LENGTH('dbo.SendMail_Queue', 'context_json') IS NULL
                ALTER TABLE dbo.SendMail_Queue ADD context_json nvarchar(max) NULL",
            "IF OBJECT_ID('dbo.SendMail_Log', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Log (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Log PRIMARY KEY,
                    queue_id bigint NULL,
                    campaign_id int NULL,
                    template_id int NULL,
                    client_oid nvarchar(80) NULL,
                    client_code nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    email_to nvarchar(320) NOT NULL,
                    email_subject nvarchar(250) NULL,
                    status nvarchar(30) NOT NULL,
                    smtp_host nvarchar(250) NULL,
                    error_message nvarchar(max) NULL,
                    provider_response nvarchar(max) NULL,
                    html_snapshot nvarchar(max) NULL,
                    context_json nvarchar(max) NULL,
                    duration_ms int NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Log_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Queue_Status' AND object_id = OBJECT_ID('dbo.SendMail_Queue'))
                CREATE INDEX IX_SendMail_Queue_Status ON dbo.SendMail_Queue(status, scheduled_at, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Log_Created' AND object_id = OBJECT_ID('dbo.SendMail_Log'))
                CREATE INDEX IX_SendMail_Log_Created ON dbo.SendMail_Log(created_at DESC)",
            "IF OBJECT_ID('dbo.SendMail_Unsubscribes', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Unsubscribes (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Unsubscribes PRIMARY KEY,
                    branch_id int NULL,
                    email nvarchar(320) NOT NULL,
                    email_normalized nvarchar(320) NOT NULL,
                    client_oid nvarchar(80) NULL,
                    client_code nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    source nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_Unsubscribes_source DEFAULT 'campaign',
                    ip_address nvarchar(80) NULL,
                    user_agent nvarchar(300) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Unsubscribes_created DEFAULT SYSDATETIME()
                )",
            "IF COL_LENGTH('dbo.SendMail_Unsubscribes', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_Unsubscribes ADD branch_id int NULL",
            "IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_Unsubscribes_Email' AND object_id = OBJECT_ID('dbo.SendMail_Unsubscribes'))
                DROP INDEX UX_SendMail_Unsubscribes_Email ON dbo.SendMail_Unsubscribes",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_Unsubscribes_BranchEmail' AND object_id = OBJECT_ID('dbo.SendMail_Unsubscribes'))
                CREATE UNIQUE INDEX UX_SendMail_Unsubscribes_BranchEmail ON dbo.SendMail_Unsubscribes(branch_id, email_normalized)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Unsubscribes_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Unsubscribes'))
                CREATE INDEX IX_SendMail_Unsubscribes_Branch ON dbo.SendMail_Unsubscribes(branch_id, created_at DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Unsubscribes_Created' AND object_id = OBJECT_ID('dbo.SendMail_Unsubscribes'))
                CREATE INDEX IX_SendMail_Unsubscribes_Created ON dbo.SendMail_Unsubscribes(created_at DESC)",
            "IF OBJECT_ID('dbo.SendMail_EmailExclusions', 'U') IS NULL
                CREATE TABLE dbo.SendMail_EmailExclusions (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_EmailExclusions PRIMARY KEY,
                    email nvarchar(320) NOT NULL,
                    email_normalized nvarchar(320) NOT NULL,
                    client_oid nvarchar(80) NULL,
                    client_code nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_EmailExclusions_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_EmailExclusions_updated DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_EmailExclusions_Email' AND object_id = OBJECT_ID('dbo.SendMail_EmailExclusions'))
                CREATE UNIQUE INDEX UX_SendMail_EmailExclusions_Email ON dbo.SendMail_EmailExclusions(email_normalized)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_EmailExclusions_Created' AND object_id = OBJECT_ID('dbo.SendMail_EmailExclusions'))
                CREATE INDEX IX_SendMail_EmailExclusions_Created ON dbo.SendMail_EmailExclusions(created_at DESC)",
            "IF OBJECT_ID('dbo.SendMail_Users', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Users (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Users PRIMARY KEY,
                    username nvarchar(80) NOT NULL,
                    full_name nvarchar(150) NOT NULL,
                    email nvarchar(320) NOT NULL,
                    password_hash nvarchar(255) NOT NULL,
                    role nvarchar(20) NOT NULL CONSTRAINT DF_SendMail_Users_role DEFAULT 'Usuario',
                    is_active bit NOT NULL CONSTRAINT DF_SendMail_Users_active DEFAULT 1,
                    must_change_password bit NOT NULL CONSTRAINT DF_SendMail_Users_must_change DEFAULT 0,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Users_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Users_updated DEFAULT SYSDATETIME(),
                    last_login_at datetime2(0) NULL,
                    CONSTRAINT CK_SendMail_Users_role CHECK (role IN ('Admin', 'Usuario'))
                )",
            "IF COL_LENGTH('dbo.SendMail_Users', 'must_change_password') IS NULL
                ALTER TABLE dbo.SendMail_Users ADD must_change_password bit NOT NULL CONSTRAINT DF_SendMail_Users_must_change DEFAULT 0",
            "IF COL_LENGTH('dbo.SendMail_Users', 'last_login_at') IS NULL
                ALTER TABLE dbo.SendMail_Users ADD last_login_at datetime2(0) NULL",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_Users_username' AND object_id = OBJECT_ID('dbo.SendMail_Users'))
                CREATE UNIQUE INDEX UX_SendMail_Users_username ON dbo.SendMail_Users(username)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_Users_email' AND object_id = OBJECT_ID('dbo.SendMail_Users'))
                CREATE UNIQUE INDEX UX_SendMail_Users_email ON dbo.SendMail_Users(email)",
            "IF OBJECT_ID('dbo.SendMail_Branches', 'U') IS NULL
                CREATE TABLE dbo.SendMail_Branches (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_Branches PRIMARY KEY,
                    name nvarchar(150) NOT NULL,
                    server nvarchar(250) NOT NULL,
                    port int NULL,
                    database_name nvarchar(150) NOT NULL,
                    username nvarchar(150) NOT NULL,
                    password_cipher nvarchar(max) NOT NULL,
                    encrypt nvarchar(20) NOT NULL CONSTRAINT DF_SendMail_Branches_encrypt DEFAULT 'no',
                    trust_server_certificate bit NOT NULL CONSTRAINT DF_SendMail_Branches_trust DEFAULT 1,
                    is_active bit NOT NULL CONSTRAINT DF_SendMail_Branches_active DEFAULT 1,
                    notes nvarchar(500) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Branches_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_Branches_updated DEFAULT SYSDATETIME()
                )",
            "IF COL_LENGTH('dbo.SendMail_Branches', 'port') IS NULL
                ALTER TABLE dbo.SendMail_Branches ADD port int NULL",
            "IF COL_LENGTH('dbo.SendMail_Branches', 'notes') IS NULL
                ALTER TABLE dbo.SendMail_Branches ADD notes nvarchar(500) NULL",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Branches_Active' AND object_id = OBJECT_ID('dbo.SendMail_Branches'))
                CREATE INDEX IX_SendMail_Branches_Active ON dbo.SendMail_Branches(is_active, name)",
            "IF OBJECT_ID('dbo.SendMail_UserBranches', 'U') IS NULL
                CREATE TABLE dbo.SendMail_UserBranches (
                    user_id int NOT NULL,
                    branch_id int NOT NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_UserBranches_created DEFAULT SYSDATETIME(),
                    CONSTRAINT PK_SendMail_UserBranches PRIMARY KEY (user_id, branch_id)
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_UserBranches_Branch' AND object_id = OBJECT_ID('dbo.SendMail_UserBranches'))
                CREATE INDEX IX_SendMail_UserBranches_Branch ON dbo.SendMail_UserBranches(branch_id, user_id)",
            "IF OBJECT_ID('dbo.SendMail_PasswordResets', 'U') IS NULL
                CREATE TABLE dbo.SendMail_PasswordResets (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_PasswordResets PRIMARY KEY,
                    user_id int NOT NULL,
                    token_hash nvarchar(64) NOT NULL,
                    expires_at datetime2(0) NOT NULL,
                    used_at datetime2(0) NULL,
                    ip_address nvarchar(80) NULL,
                    user_agent nvarchar(300) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_PasswordResets_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_PasswordResets_Token' AND object_id = OBJECT_ID('dbo.SendMail_PasswordResets'))
                CREATE INDEX IX_SendMail_PasswordResets_Token ON dbo.SendMail_PasswordResets(token_hash, expires_at)",
            "IF OBJECT_ID('dbo.SendMail_InvoiceTemplates', 'U') IS NULL
                CREATE TABLE dbo.SendMail_InvoiceTemplates (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_InvoiceTemplates PRIMARY KEY,
                    name nvarchar(150) NOT NULL,
                    subject nvarchar(250) NOT NULL,
                    html_body nvarchar(max) NOT NULL,
                    from_email nvarchar(320) NULL,
                    from_name nvarchar(150) NULL,
                    reply_to nvarchar(320) NULL,
                    bcc nvarchar(320) NULL,
                    web nvarchar(180) NOT NULL,
                    loc_prefix nvarchar(40) NOT NULL,
                    domicilio nvarchar(250) NULL,
                    facebook nvarchar(180) NULL,
                    instagram nvarchar(180) NULL,
                    whatsapp nvarchar(80) NULL,
                    test_mode bit NOT NULL CONSTRAINT DF_SendMail_InvoiceTemplates_test DEFAULT 0,
                    test_email nvarchar(320) NULL,
                    is_active bit NOT NULL CONSTRAINT DF_SendMail_InvoiceTemplates_active DEFAULT 1,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceTemplates_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceTemplates_updated DEFAULT SYSDATETIME()
                )",
            "IF OBJECT_ID('dbo.SendMail_InvoiceBatches', 'U') IS NULL
                CREATE TABLE dbo.SendMail_InvoiceBatches (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_InvoiceBatches PRIMARY KEY,
                    template_id int NOT NULL,
                    name nvarchar(180) NOT NULL,
                    due_date date NULL,
                    due_date_label nvarchar(20) NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_InvoiceBatches_status DEFAULT 'queued',
                    total_queued int NOT NULL CONSTRAINT DF_SendMail_InvoiceBatches_queued DEFAULT 0,
                    total_sent int NOT NULL CONSTRAINT DF_SendMail_InvoiceBatches_sent DEFAULT 0,
                    total_failed int NOT NULL CONSTRAINT DF_SendMail_InvoiceBatches_failed DEFAULT 0,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceBatches_created DEFAULT SYSDATETIME(),
                    started_at datetime2(0) NULL,
                    completed_at datetime2(0) NULL
                )",
            "IF OBJECT_ID('dbo.SendMail_InvoiceQueue', 'U') IS NULL
                CREATE TABLE dbo.SendMail_InvoiceQueue (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_InvoiceQueue PRIMARY KEY,
                    batch_id int NULL,
                    template_id int NOT NULL,
                    invoice_id nvarchar(80) NOT NULL,
                    snb nvarchar(80) NOT NULL,
                    client_name nvarchar(250) NULL,
                    email_to nvarchar(320) NOT NULL,
                    amount decimal(18,2) NOT NULL CONSTRAINT DF_SendMail_InvoiceQueue_amount DEFAULT 0,
                    due_date date NULL,
                    due_date_label nvarchar(20) NULL,
                    invoice_url nvarchar(600) NOT NULL,
                    context_json nvarchar(max) NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_InvoiceQueue_status DEFAULT 'pending',
                    attempts int NOT NULL CONSTRAINT DF_SendMail_InvoiceQueue_attempts DEFAULT 0,
                    scheduled_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceQueue_scheduled DEFAULT SYSDATETIME(),
                    sent_at datetime2(0) NULL,
                    last_error nvarchar(max) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceQueue_created DEFAULT SYSDATETIME()
                )",
            "IF COL_LENGTH('dbo.SendMail_InvoiceQueue', 'batch_id') IS NULL
                ALTER TABLE dbo.SendMail_InvoiceQueue ADD batch_id int NULL",
            "IF OBJECT_ID('dbo.SendMail_InvoiceLog', 'U') IS NULL
                CREATE TABLE dbo.SendMail_InvoiceLog (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_InvoiceLog PRIMARY KEY,
                    queue_id bigint NULL,
                    template_id int NULL,
                    invoice_id nvarchar(80) NULL,
                    snb nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    email_to nvarchar(320) NOT NULL,
                    email_subject nvarchar(250) NULL,
                    status nvarchar(30) NOT NULL,
                    smtp_host nvarchar(250) NULL,
                    error_message nvarchar(max) NULL,
                    provider_response nvarchar(max) NULL,
                    html_snapshot nvarchar(max) NULL,
                    context_json nvarchar(max) NULL,
                    duration_ms int NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_InvoiceLog_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceBatches_Status' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceBatches'))
                CREATE INDEX IX_SendMail_InvoiceBatches_Status ON dbo.SendMail_InvoiceBatches(status, created_at DESC, id DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceQueue_Status' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceQueue'))
                CREATE INDEX IX_SendMail_InvoiceQueue_Status ON dbo.SendMail_InvoiceQueue(status, scheduled_at, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceQueue_Batch' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceQueue'))
                CREATE INDEX IX_SendMail_InvoiceQueue_Batch ON dbo.SendMail_InvoiceQueue(batch_id, status, id)",
            "IF OBJECT_ID('dbo.SendMail_InvoiceBatches', 'U') IS NOT NULL
                AND COL_LENGTH('dbo.SendMail_InvoiceQueue', 'batch_id') IS NOT NULL
                AND EXISTS (SELECT 1 FROM dbo.SendMail_InvoiceQueue WHERE batch_id IS NULL)
              BEGIN
                DECLARE @legacy_batches TABLE (
                    batch_id int NOT NULL,
                    template_id int NOT NULL,
                    due_date date NULL,
                    due_date_label nvarchar(20) NULL
                );

                INSERT INTO dbo.SendMail_InvoiceBatches
                    (template_id, name, due_date, due_date_label, status, total_queued, total_sent, total_failed, started_at, completed_at)
                OUTPUT INSERTED.id, INSERTED.template_id, INSERTED.due_date, INSERTED.due_date_label
                    INTO @legacy_batches(batch_id, template_id, due_date, due_date_label)
                SELECT
                    q.template_id,
                    N'Facturas anteriores' + CASE WHEN NULLIF(MAX(q.due_date_label), N'') IS NULL THEN N'' ELSE N' vto ' + MAX(q.due_date_label) END,
                    q.due_date,
                    q.due_date_label,
                    CASE
                        WHEN SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) = 0
                         AND SUM(CASE WHEN q.status = 'sending' THEN 1 ELSE 0 END) = 0 THEN 'completed'
                        WHEN SUM(CASE WHEN q.status IN ('sent', 'failed', 'sending', 'skipped') THEN 1 ELSE 0 END) > 0 THEN 'processing'
                        ELSE 'queued'
                    END,
                    COUNT(*),
                    SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END),
                    SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END),
                    CASE WHEN SUM(CASE WHEN q.status IN ('sent', 'failed', 'sending', 'skipped') THEN 1 ELSE 0 END) > 0 THEN MIN(q.created_at) ELSE NULL END,
                    CASE
                        WHEN SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) = 0
                         AND SUM(CASE WHEN q.status = 'sending' THEN 1 ELSE 0 END) = 0 THEN MAX(COALESCE(q.sent_at, q.created_at))
                        ELSE NULL
                    END
                FROM dbo.SendMail_InvoiceQueue q
                WHERE q.batch_id IS NULL
                GROUP BY q.template_id, q.due_date, q.due_date_label;

                UPDATE q
                SET batch_id = b.batch_id
                FROM dbo.SendMail_InvoiceQueue q
                INNER JOIN @legacy_batches b ON b.template_id = q.template_id
                    AND ((b.due_date = q.due_date) OR (b.due_date IS NULL AND q.due_date IS NULL))
                    AND ISNULL(b.due_date_label, N'') = ISNULL(q.due_date_label, N'')
                WHERE q.batch_id IS NULL;
              END",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceLog_Created' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceLog'))
                CREATE INDEX IX_SendMail_InvoiceLog_Created ON dbo.SendMail_InvoiceLog(created_at DESC)",
            "IF COL_LENGTH('dbo.SendMail_Campaigns', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_Campaigns ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_Queue', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_Queue ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_Log', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_Log ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_InvoiceBatches', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_InvoiceBatches ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_InvoiceQueue', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_InvoiceQueue ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_InvoiceLog', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_InvoiceLog ADD branch_id int NULL",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Campaigns_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Campaigns'))
                CREATE INDEX IX_SendMail_Campaigns_Branch ON dbo.SendMail_Campaigns(branch_id, status, created_at DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Queue_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Queue'))
                CREATE INDEX IX_SendMail_Queue_Branch ON dbo.SendMail_Queue(branch_id, status, scheduled_at, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Log_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Log'))
                CREATE INDEX IX_SendMail_Log_Branch ON dbo.SendMail_Log(branch_id, created_at DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceBatches_Branch' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceBatches'))
                CREATE INDEX IX_SendMail_InvoiceBatches_Branch ON dbo.SendMail_InvoiceBatches(branch_id, status, created_at DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceQueue_Branch' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceQueue'))
                CREATE INDEX IX_SendMail_InvoiceQueue_Branch ON dbo.SendMail_InvoiceQueue(branch_id, status, scheduled_at, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceLog_Branch' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceLog'))
                CREATE INDEX IX_SendMail_InvoiceLog_Branch ON dbo.SendMail_InvoiceLog(branch_id, created_at DESC)",
            "IF COL_LENGTH('dbo.SendMail_Templates', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_Templates ADD branch_id int NULL",
            "IF COL_LENGTH('dbo.SendMail_InvoiceTemplates', 'branch_id') IS NULL
                ALTER TABLE dbo.SendMail_InvoiceTemplates ADD branch_id int NULL",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Templates_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Templates'))
                CREATE INDEX IX_SendMail_Templates_Branch ON dbo.SendMail_Templates(branch_id, is_active, updated_at DESC)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_InvoiceTemplates_Branch' AND object_id = OBJECT_ID('dbo.SendMail_InvoiceTemplates'))
                CREATE INDEX IX_SendMail_InvoiceTemplates_Branch ON dbo.SendMail_InvoiceTemplates(branch_id, is_active, id)",
            "IF OBJECT_ID('dbo.SendMail_WhatsAppTemplates', 'U') IS NULL
                CREATE TABLE dbo.SendMail_WhatsAppTemplates (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_WhatsAppTemplates PRIMARY KEY,
                    branch_id int NOT NULL,
                    meta_template_id nvarchar(80) NOT NULL,
                    name nvarchar(180) NOT NULL,
                    language nvarchar(30) NOT NULL,
                    category nvarchar(30) NOT NULL,
                    status nvarchar(30) NOT NULL,
                    components_json nvarchar(max) NULL,
                    body_variables_json nvarchar(max) NULL,
                    is_active bit NOT NULL CONSTRAINT DF_SendMail_WhatsAppTemplates_active DEFAULT 1,
                    synced_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppTemplates_synced DEFAULT SYSDATETIME(),
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppTemplates_created DEFAULT SYSDATETIME(),
                    updated_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppTemplates_updated DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_WhatsAppTemplates_Meta' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppTemplates'))
                CREATE UNIQUE INDEX UX_SendMail_WhatsAppTemplates_Meta ON dbo.SendMail_WhatsAppTemplates(branch_id, meta_template_id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_WhatsAppTemplates_Ready' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppTemplates'))
                CREATE INDEX IX_SendMail_WhatsAppTemplates_Ready ON dbo.SendMail_WhatsAppTemplates(branch_id, category, status, is_active, name)",
            "IF OBJECT_ID('dbo.SendMail_WhatsAppBatches', 'U') IS NULL
                CREATE TABLE dbo.SendMail_WhatsAppBatches (
                    id int IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_WhatsAppBatches PRIMARY KEY,
                    branch_id int NOT NULL,
                    source_type nvarchar(20) NOT NULL,
                    template_id int NOT NULL,
                    name nvarchar(180) NOT NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_status DEFAULT 'queued',
                    total_queued int NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_queued DEFAULT 0,
                    total_sent int NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_sent DEFAULT 0,
                    total_failed int NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_failed DEFAULT 0,
                    total_delivered int NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_delivered DEFAULT 0,
                    total_read int NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_read DEFAULT 0,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppBatches_created DEFAULT SYSDATETIME(),
                    started_at datetime2(0) NULL,
                    completed_at datetime2(0) NULL,
                    CONSTRAINT CK_SendMail_WhatsAppBatches_source CHECK (source_type IN ('campaign', 'invoice'))
                )",
            "IF OBJECT_ID('dbo.SendMail_WhatsAppQueue', 'U') IS NULL
                CREATE TABLE dbo.SendMail_WhatsAppQueue (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_WhatsAppQueue PRIMARY KEY,
                    branch_id int NOT NULL,
                    batch_id int NOT NULL,
                    template_id int NOT NULL,
                    client_oid nvarchar(80) NULL,
                    client_code nvarchar(80) NULL,
                    client_name nvarchar(250) NULL,
                    source_record_id nvarchar(80) NULL,
                    phone_raw nvarchar(80) NULL,
                    phone_to nvarchar(30) NOT NULL,
                    context_json nvarchar(max) NULL,
                    status nvarchar(30) NOT NULL CONSTRAINT DF_SendMail_WhatsAppQueue_status DEFAULT 'pending',
                    attempts int NOT NULL CONSTRAINT DF_SendMail_WhatsAppQueue_attempts DEFAULT 0,
                    scheduled_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppQueue_scheduled DEFAULT SYSDATETIME(),
                    provider_message_id nvarchar(300) NULL,
                    provider_response nvarchar(max) NULL,
                    accepted_at datetime2(0) NULL,
                    sent_at datetime2(0) NULL,
                    delivered_at datetime2(0) NULL,
                    read_at datetime2(0) NULL,
                    failed_at datetime2(0) NULL,
                    last_error nvarchar(max) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppQueue_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_WhatsAppQueue_Pending' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppQueue'))
                CREATE INDEX IX_SendMail_WhatsAppQueue_Pending ON dbo.SendMail_WhatsAppQueue(branch_id, status, scheduled_at, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_WhatsAppQueue_Batch' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppQueue'))
                CREATE INDEX IX_SendMail_WhatsAppQueue_Batch ON dbo.SendMail_WhatsAppQueue(batch_id, status, id)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_WhatsAppQueue_Provider' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppQueue'))
                CREATE UNIQUE INDEX UX_SendMail_WhatsAppQueue_Provider ON dbo.SendMail_WhatsAppQueue(provider_message_id) WHERE provider_message_id IS NOT NULL",
            "IF OBJECT_ID('dbo.SendMail_WhatsAppEvents', 'U') IS NULL
                CREATE TABLE dbo.SendMail_WhatsAppEvents (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_WhatsAppEvents PRIMARY KEY,
                    branch_id int NULL,
                    provider_message_id nvarchar(300) NULL,
                    event_status nvarchar(30) NULL,
                    event_at datetime2(0) NULL,
                    event_hash nvarchar(64) NOT NULL,
                    payload_json nvarchar(max) NOT NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppEvents_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_WhatsAppEvents_Hash' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppEvents'))
                CREATE UNIQUE INDEX UX_SendMail_WhatsAppEvents_Hash ON dbo.SendMail_WhatsAppEvents(event_hash)",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_WhatsAppEvents_Message' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppEvents'))
                CREATE INDEX IX_SendMail_WhatsAppEvents_Message ON dbo.SendMail_WhatsAppEvents(provider_message_id, created_at DESC)",
            "IF OBJECT_ID('dbo.SendMail_WhatsAppOptOuts', 'U') IS NULL
                CREATE TABLE dbo.SendMail_WhatsAppOptOuts (
                    id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_SendMail_WhatsAppOptOuts PRIMARY KEY,
                    branch_id int NOT NULL,
                    phone_to nvarchar(30) NOT NULL,
                    scope nvarchar(20) NOT NULL CONSTRAINT DF_SendMail_WhatsAppOptOuts_scope DEFAULT 'campaign',
                    reason nvarchar(250) NULL,
                    created_at datetime2(0) NOT NULL CONSTRAINT DF_SendMail_WhatsAppOptOuts_created DEFAULT SYSDATETIME()
                )",
            "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SendMail_WhatsAppOptOuts_Phone' AND object_id = OBJECT_ID('dbo.SendMail_WhatsAppOptOuts'))
                CREATE UNIQUE INDEX UX_SendMail_WhatsAppOptOuts_Phone ON dbo.SendMail_WhatsAppOptOuts(branch_id, phone_to, scope)",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        self::ensureIdGenerators($pdo);
        self::ensureValueDefaults($pdo);
        self::ensureLocalDateDefaults($pdo);
        if ($seed) {
            self::seedInitialAdmin();
            self::seedDefaultBranch();
            self::seedTemplate();
            self::seedInvoiceTemplate();
            self::ensureBranchScopedDefaults();
        }
    }

    private static function ensureIdGenerators(PDO $pdo): void
    {
        $tables = [
            ['SendMail_Templates', 'int'],
            ['SendMail_Campaigns', 'int'],
            ['SendMail_Queue', 'bigint'],
            ['SendMail_Log', 'bigint'],
            ['SendMail_Unsubscribes', 'bigint'],
            ['SendMail_EmailExclusions', 'bigint'],
            ['SendMail_Users', 'int'],
            ['SendMail_PasswordResets', 'bigint'],
            ['SendMail_Branches', 'int'],
            ['SendMail_InvoiceTemplates', 'int'],
            ['SendMail_InvoiceBatches', 'int'],
            ['SendMail_InvoiceQueue', 'bigint'],
            ['SendMail_InvoiceLog', 'bigint'],
            ['SendMail_WhatsAppTemplates', 'int'],
            ['SendMail_WhatsAppBatches', 'int'],
            ['SendMail_WhatsAppQueue', 'bigint'],
            ['SendMail_WhatsAppEvents', 'bigint'],
            ['SendMail_WhatsAppOptOuts', 'bigint'],
        ];

        foreach ($tables as [$table, $type]) {
            $tableLiteral = str_replace("'", "''", $table);
            $sequence = 'SQ_' . $table . '_id';
            $constraint = 'DF_' . $table . '_id';
            $sequenceLiteral = str_replace("'", "''", $sequence);
            $constraintLiteral = str_replace("'", "''", $constraint);
            $tableSql = '[' . str_replace(']', ']]', $table) . ']';
            $sequenceSql = '[' . str_replace(']', ']]', $sequence) . ']';
            $constraintSql = '[' . str_replace(']', ']]', $constraint) . ']';
            $sequenceType = $type === 'bigint' ? 'bigint' : 'int';

            $isIdentity = (int) $pdo->query(
                "SELECT COUNT(1)
                 FROM sys.columns c
                 INNER JOIN sys.tables t ON t.object_id = c.object_id
                 WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
                   AND t.name = N'$tableLiteral'
                   AND c.name = N'id'
                   AND c.is_identity = 1"
            )->fetchColumn();

            if ($isIdentity > 0) {
                continue;
            }

            $hasDefault = (int) $pdo->query(
                "SELECT COUNT(1)
                 FROM sys.default_constraints dc
                 INNER JOIN sys.columns c ON c.default_object_id = dc.object_id
                 INNER JOIN sys.tables t ON t.object_id = c.object_id
                 WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
                   AND t.name = N'$tableLiteral'
                   AND c.name = N'id'"
            )->fetchColumn();

            if ($hasDefault > 0) {
                continue;
            }

            $next = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM dbo.$tableSql")->fetchColumn();
            $next = max(1, $next);

            $pdo->exec(
                "IF NOT EXISTS (SELECT 1 FROM sys.sequences WHERE SCHEMA_NAME(schema_id) = N'dbo' AND name = N'$sequenceLiteral')
                 BEGIN
                    DECLARE @sql nvarchar(max);
                    SET @sql = N'CREATE SEQUENCE dbo.$sequenceSql AS $sequenceType START WITH $next INCREMENT BY 1';
                    EXEC sys.sp_executesql @sql;
                 END"
            );

            $pdo->exec(
                "IF NOT EXISTS (
                    SELECT 1
                    FROM sys.default_constraints dc
                    INNER JOIN sys.columns c ON c.default_object_id = dc.object_id
                    INNER JOIN sys.tables t ON t.object_id = c.object_id
                    WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
                      AND t.name = N'$tableLiteral'
                      AND c.name = N'id'
                 )
                 BEGIN
                    ALTER TABLE dbo.$tableSql ADD CONSTRAINT $constraintSql DEFAULT (NEXT VALUE FOR dbo.$sequenceSql) FOR [id];
                 END"
            );
        }
    }

    private static function ensureValueDefaults(PDO $pdo): void
    {
        $defaults = [
            ['SendMail_Templates', 'is_active', 'DF_SendMail_Templates_active', '1'],
            ['SendMail_Campaigns', 'status', 'DF_SendMail_Campaigns_status', "N'queued'"],
            ['SendMail_Campaigns', 'total_queued', 'DF_SendMail_Campaigns_queued', '0'],
            ['SendMail_Campaigns', 'total_sent', 'DF_SendMail_Campaigns_sent', '0'],
            ['SendMail_Campaigns', 'total_failed', 'DF_SendMail_Campaigns_failed', '0'],
            ['SendMail_Queue', 'status', 'DF_SendMail_Queue_status', "N'pending'"],
            ['SendMail_Queue', 'attempts', 'DF_SendMail_Queue_attempts', '0'],
            ['SendMail_Unsubscribes', 'source', 'DF_SendMail_Unsubscribes_source', "N'campaign'"],
            ['SendMail_Users', 'role', 'DF_SendMail_Users_role', "N'Usuario'"],
            ['SendMail_Users', 'is_active', 'DF_SendMail_Users_active', '1'],
            ['SendMail_Users', 'must_change_password', 'DF_SendMail_Users_must_change', '0'],
            ['SendMail_Branches', 'encrypt', 'DF_SendMail_Branches_encrypt', "N'no'"],
            ['SendMail_Branches', 'trust_server_certificate', 'DF_SendMail_Branches_trust', '1'],
            ['SendMail_Branches', 'is_active', 'DF_SendMail_Branches_active', '1'],
            ['SendMail_InvoiceTemplates', 'test_mode', 'DF_SendMail_InvoiceTemplates_test', '0'],
            ['SendMail_InvoiceTemplates', 'is_active', 'DF_SendMail_InvoiceTemplates_active', '1'],
            ['SendMail_InvoiceBatches', 'status', 'DF_SendMail_InvoiceBatches_status', "N'queued'"],
            ['SendMail_InvoiceBatches', 'total_queued', 'DF_SendMail_InvoiceBatches_queued', '0'],
            ['SendMail_InvoiceBatches', 'total_sent', 'DF_SendMail_InvoiceBatches_sent', '0'],
            ['SendMail_InvoiceBatches', 'total_failed', 'DF_SendMail_InvoiceBatches_failed', '0'],
            ['SendMail_InvoiceQueue', 'amount', 'DF_SendMail_InvoiceQueue_amount', '0'],
            ['SendMail_InvoiceQueue', 'status', 'DF_SendMail_InvoiceQueue_status', "N'pending'"],
            ['SendMail_InvoiceQueue', 'attempts', 'DF_SendMail_InvoiceQueue_attempts', '0'],
            ['SendMail_WhatsAppTemplates', 'is_active', 'DF_SendMail_WhatsAppTemplates_active', '1'],
            ['SendMail_WhatsAppBatches', 'status', 'DF_SendMail_WhatsAppBatches_status', "N'queued'"],
            ['SendMail_WhatsAppBatches', 'total_queued', 'DF_SendMail_WhatsAppBatches_queued', '0'],
            ['SendMail_WhatsAppBatches', 'total_sent', 'DF_SendMail_WhatsAppBatches_sent', '0'],
            ['SendMail_WhatsAppBatches', 'total_failed', 'DF_SendMail_WhatsAppBatches_failed', '0'],
            ['SendMail_WhatsAppBatches', 'total_delivered', 'DF_SendMail_WhatsAppBatches_delivered', '0'],
            ['SendMail_WhatsAppBatches', 'total_read', 'DF_SendMail_WhatsAppBatches_read', '0'],
            ['SendMail_WhatsAppQueue', 'status', 'DF_SendMail_WhatsAppQueue_status', "N'pending'"],
            ['SendMail_WhatsAppQueue', 'attempts', 'DF_SendMail_WhatsAppQueue_attempts', '0'],
            ['SendMail_WhatsAppOptOuts', 'scope', 'DF_SendMail_WhatsAppOptOuts_scope', "N'campaign'"],
        ];

        foreach ($defaults as [$table, $column, $constraint, $definition]) {
            $tableSql = '[' . str_replace(']', ']]', $table) . ']';
            $columnSql = '[' . str_replace(']', ']]', $column) . ']';
            $constraintSql = '[' . str_replace(']', ']]', $constraint) . ']';
            $tableLiteral = str_replace("'", "''", $table);
            $columnLiteral = str_replace("'", "''", $column);

            $pdo->exec(
                "IF OBJECT_ID(N'dbo.$tableLiteral', N'U') IS NOT NULL
                   AND COL_LENGTH(N'dbo.$tableLiteral', N'$columnLiteral') IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1
                       FROM sys.default_constraints dc
                       INNER JOIN sys.columns c ON c.default_object_id = dc.object_id
                       INNER JOIN sys.tables t ON t.object_id = c.object_id
                       WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
                         AND t.name = N'$tableLiteral'
                         AND c.name = N'$columnLiteral'
                   )
                 BEGIN
                     ALTER TABLE dbo.$tableSql ADD CONSTRAINT $constraintSql DEFAULT $definition FOR $columnSql;
                 END"
            );
        }
    }

    private static function ensureLocalDateDefaults(PDO $pdo): void
    {
        $defaults = [
            ['SendMail_Settings', 'updated_at', 'DF_SendMail_Settings_updated'],
            ['SendMail_Templates', 'created_at', 'DF_SendMail_Templates_created'],
            ['SendMail_Templates', 'updated_at', 'DF_SendMail_Templates_updated'],
            ['SendMail_Campaigns', 'created_at', 'DF_SendMail_Campaigns_created'],
            ['SendMail_Queue', 'scheduled_at', 'DF_SendMail_Queue_scheduled'],
            ['SendMail_Queue', 'created_at', 'DF_SendMail_Queue_created'],
            ['SendMail_Log', 'created_at', 'DF_SendMail_Log_created'],
            ['SendMail_Unsubscribes', 'created_at', 'DF_SendMail_Unsubscribes_created'],
            ['SendMail_EmailExclusions', 'created_at', 'DF_SendMail_EmailExclusions_created'],
            ['SendMail_EmailExclusions', 'updated_at', 'DF_SendMail_EmailExclusions_updated'],
            ['SendMail_Users', 'created_at', 'DF_SendMail_Users_created'],
            ['SendMail_Users', 'updated_at', 'DF_SendMail_Users_updated'],
            ['SendMail_Branches', 'created_at', 'DF_SendMail_Branches_created'],
            ['SendMail_Branches', 'updated_at', 'DF_SendMail_Branches_updated'],
            ['SendMail_UserBranches', 'created_at', 'DF_SendMail_UserBranches_created'],
            ['SendMail_PasswordResets', 'created_at', 'DF_SendMail_PasswordResets_created'],
            ['SendMail_InvoiceTemplates', 'created_at', 'DF_SendMail_InvoiceTemplates_created'],
            ['SendMail_InvoiceTemplates', 'updated_at', 'DF_SendMail_InvoiceTemplates_updated'],
            ['SendMail_InvoiceBatches', 'created_at', 'DF_SendMail_InvoiceBatches_created'],
            ['SendMail_InvoiceQueue', 'scheduled_at', 'DF_SendMail_InvoiceQueue_scheduled'],
            ['SendMail_InvoiceQueue', 'created_at', 'DF_SendMail_InvoiceQueue_created'],
            ['SendMail_InvoiceLog', 'created_at', 'DF_SendMail_InvoiceLog_created'],
            ['SendMail_WhatsAppTemplates', 'synced_at', 'DF_SendMail_WhatsAppTemplates_synced'],
            ['SendMail_WhatsAppTemplates', 'created_at', 'DF_SendMail_WhatsAppTemplates_created'],
            ['SendMail_WhatsAppTemplates', 'updated_at', 'DF_SendMail_WhatsAppTemplates_updated'],
            ['SendMail_WhatsAppBatches', 'created_at', 'DF_SendMail_WhatsAppBatches_created'],
            ['SendMail_WhatsAppQueue', 'scheduled_at', 'DF_SendMail_WhatsAppQueue_scheduled'],
            ['SendMail_WhatsAppQueue', 'created_at', 'DF_SendMail_WhatsAppQueue_created'],
            ['SendMail_WhatsAppEvents', 'created_at', 'DF_SendMail_WhatsAppEvents_created'],
            ['SendMail_WhatsAppOptOuts', 'created_at', 'DF_SendMail_WhatsAppOptOuts_created'],
        ];

        foreach ($defaults as [$table, $column, $constraint]) {
            $tableSql = '[' . str_replace(']', ']]', $table) . ']';
            $columnSql = '[' . str_replace(']', ']]', $column) . ']';
            $constraintSql = '[' . str_replace(']', ']]', $constraint) . ']';
            $tableLiteral = str_replace("'", "''", $table);
            $columnLiteral = str_replace("'", "''", $column);

            $pdo->exec(
                "DECLARE @constraint sysname;
                 DECLARE @definition nvarchar(max);
                 DECLARE @sql nvarchar(max);

                 SELECT @constraint = dc.name, @definition = dc.definition
                 FROM sys.default_constraints dc
                 INNER JOIN sys.columns c ON c.default_object_id = dc.object_id
                 INNER JOIN sys.tables t ON t.object_id = c.object_id
                 WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
                   AND t.name = N'$tableLiteral'
                   AND c.name = N'$columnLiteral';

                 IF @constraint IS NOT NULL AND LOWER(COALESCE(@definition, N'')) NOT LIKE N'%sysdatetime%'
                 BEGIN
                     SET @sql = N'ALTER TABLE dbo.$tableSql DROP CONSTRAINT ' + QUOTENAME(@constraint);
                     EXEC sys.sp_executesql @sql;
                     SET @constraint = NULL;
                 END;

                 IF @constraint IS NULL
                 BEGIN
                     SET @sql = N'ALTER TABLE dbo.$tableSql ADD CONSTRAINT $constraintSql DEFAULT SYSDATETIME() FOR $columnSql';
                     EXEC sys.sp_executesql @sql;
                 END;"
            );
        }
    }

    private static function seedTemplate(): void
    {
        $pdo = Database::pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Templates')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $html = self::defaultTemplateHtml();
        if (is_file(DEFAULT_TEMPLATE_PATH)) {
            $html = repair_mojibake((string) file_get_contents(DEFAULT_TEMPLATE_PATH));
        }
        $branchId = BranchRepository::defaultId();

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.SendMail_Templates (branch_id, name, subject, html_body)
             VALUES (:branch_id, :name, :subject, :html_body)'
        );
        $stmt->execute([
            ':branch_id' => $branchId,
            ':name' => 'Espacial TV - contenido premium',
            ':subject' => 'Espacial TV - contenido premium para {{razon_social}}',
            ':html_body' => $html,
        ]);
    }

    private static function seedInitialAdmin(): void
    {
        $pdo = Database::pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Users')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.SendMail_Users (username, full_name, email, password_hash, role, is_active, must_change_password)
             VALUES (:username, :full_name, :email, :password_hash, :role, 1, 0)'
        );
        $stmt->execute([
            ':username' => INITIAL_ADMIN_USERNAME,
            ':full_name' => INITIAL_ADMIN_NAME,
            ':email' => INITIAL_ADMIN_EMAIL,
            ':password_hash' => INITIAL_ADMIN_PASSWORD_HASH,
            ':role' => 'Admin',
        ]);
    }

    private static function seedDefaultBranch(): void
    {
        $pdo = Database::pdo();
        $branchCount = (int) $pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Branches')->fetchColumn();
        if ($branchCount === 0) {
            $config = Database::loadConfig();
            $server = trim((string) ($config['server'] ?? ''));
            $database = trim((string) ($config['database'] ?? ''));
            $username = trim((string) ($config['username'] ?? ''));
            if ($server !== '' && $database !== '' && $username !== '') {
                $stmt = $pdo->prepare(
                    'INSERT INTO dbo.SendMail_Branches
                     (name, server, database_name, username, password_cipher, encrypt, trust_server_certificate, is_active, notes)
                     VALUES
                     (:name, :server, :database_name, :username, :password_cipher, :encrypt, :trust_server_certificate, 1, :notes)'
                );
                $stmt->execute([
                    ':name' => 'Principal',
                    ':server' => $server,
                    ':database_name' => $database,
                    ':username' => $username,
                    ':password_cipher' => BranchRepository::encryptPassword((string) ($config['password'] ?? '')),
                    ':encrypt' => (string) ($config['encrypt'] ?? 'no'),
                    ':trust_server_certificate' => !empty($config['trust_server_certificate']) ? 1 : 0,
                    ':notes' => 'Creada automaticamente desde la conexion central existente.',
                ]);
            }
        }

        $defaultId = (int) $pdo->query('SELECT TOP 1 id FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY id ASC')->fetchColumn();
        if ($defaultId <= 0) {
            return;
        }

        $pdo->exec(
            "INSERT INTO dbo.SendMail_UserBranches (user_id, branch_id)
             SELECT u.id, $defaultId
             FROM dbo.SendMail_Users u
             WHERE NOT EXISTS (
                SELECT 1 FROM dbo.SendMail_UserBranches ub WHERE ub.user_id = u.id
             )"
        );

        foreach ([
            'SendMail_Campaigns',
            'SendMail_Queue',
            'SendMail_Log',
            'SendMail_InvoiceBatches',
            'SendMail_InvoiceQueue',
            'SendMail_InvoiceLog',
            'SendMail_Unsubscribes',
            'SendMail_Templates',
            'SendMail_InvoiceTemplates',
        ] as $table) {
            $pdo->exec("UPDATE dbo.$table SET branch_id = $defaultId WHERE branch_id IS NULL");
        }

        $copySmtp = $pdo->prepare(
            "INSERT INTO dbo.SendMail_Settings (setting_key, setting_value)
             SELECT :branch_key, setting_value
             FROM dbo.SendMail_Settings
             WHERE setting_key = 'smtp'
               AND NOT EXISTS (SELECT 1 FROM dbo.SendMail_Settings WHERE setting_key = :branch_key_exists)"
        );
        $copySmtp->execute([
            ':branch_key' => 'smtp:' . $defaultId,
            ':branch_key_exists' => 'smtp:' . $defaultId,
        ]);
    }

    private static function seedInvoiceTemplate(): void
    {
        $pdo = Database::pdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM dbo.SendMail_InvoiceTemplates')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.SendMail_InvoiceTemplates
             (branch_id, name, subject, html_body, web, loc_prefix, domicilio, facebook, instagram, whatsapp, test_mode, test_email)
             VALUES
             (:branch_id, :name, :subject, :html_body, :web, :loc_prefix, :domicilio, :facebook, :instagram, :whatsapp, 1, :test_email)'
        );
        $stmt->execute([
            ':branch_id' => BranchRepository::defaultId(),
            ':name' => 'Factura electronica',
            ':subject' => 'Factura Electronica',
            ':html_body' => self::defaultInvoiceTemplateHtml(),
            ':web' => 'infracomcoopelectric.com.ar',
            ':loc_prefix' => 'ola',
            ':domicilio' => 'Belgrano 2800 - Olavarria',
            ':facebook' => '',
            ':instagram' => '',
            ':whatsapp' => '',
            ':test_email' => INITIAL_ADMIN_EMAIL,
        ]);
    }

    private static function ensureBranchScopedDefaults(): void
    {
        $pdo = Database::pdo();
        $branches = $pdo->query('SELECT id FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        if (!$branches) {
            return;
        }

        $smtpSourceKey = $pdo->query(
            "SELECT TOP 1 setting_key
             FROM dbo.SendMail_Settings
             WHERE setting_key LIKE 'smtp:%'
             ORDER BY setting_key ASC"
        )->fetchColumn();

        foreach (array_map('intval', $branches) as $branchId) {
            if (is_string($smtpSourceKey) && $smtpSourceKey !== '') {
                $targetKey = 'smtp:' . $branchId;
                $copy = $pdo->prepare(
                    'INSERT INTO dbo.SendMail_Settings (setting_key, setting_value)
                     SELECT :target_key, setting_value
                     FROM dbo.SendMail_Settings
                     WHERE setting_key = :source_key
                       AND NOT EXISTS (SELECT 1 FROM dbo.SendMail_Settings WHERE setting_key = :target_key_exists)'
                );
                $copy->execute([
                    ':target_key' => $targetKey,
                    ':source_key' => $smtpSourceKey,
                    ':target_key_exists' => $targetKey,
                ]);
            }
        }
    }

    private static function removeOrphanCopiedCampaignTemplates(PDO $pdo): void
    {
        $defaultId = (int) $pdo->query('SELECT TOP 1 id FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY id ASC')->fetchColumn();
        if ($defaultId <= 0) {
            return;
        }

        $pdo->exec(
            "DELETE t
             FROM dbo.SendMail_Templates t
             WHERE t.branch_id IS NOT NULL
               AND t.branch_id <> $defaultId
               AND EXISTS (
                    SELECT 1
                    FROM dbo.SendMail_Templates src
                    WHERE src.branch_id = $defaultId
                      AND src.name = t.name
                      AND src.subject = t.subject
                      AND CONVERT(nvarchar(max), src.html_body) = CONVERT(nvarchar(max), t.html_body)
                      AND COALESCE(src.attachments_json, N'[]') = COALESCE(t.attachments_json, N'[]')
               )
               AND NOT EXISTS (SELECT 1 FROM dbo.SendMail_Campaigns c WHERE c.template_id = t.id)
               AND NOT EXISTS (SELECT 1 FROM dbo.SendMail_Queue q WHERE q.template_id = t.id)
               AND NOT EXISTS (SELECT 1 FROM dbo.SendMail_Log l WHERE l.template_id = t.id)"
        );
    }

    private static function defaultInvoiceTemplateHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Factura Electronica</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,sans-serif;color:#102033;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef2f7;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #d9e2ec;">
          <tr>
            <td style="background:#0f172a;color:#ffffff;padding:24px 28px;text-align:center;">
              <h1 style="margin:0;font-size:24px;line-height:1.2;">Factura Electronica</h1>
              <p style="margin:8px 0 0;color:#cbd5e1;font-size:14px;">{{web}}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:26px 28px;text-align:center;">
              <p style="margin:0 0 12px;font-size:17px;">Hola <strong>{{nombre}}</strong></p>
              <p style="margin:0 0 22px;color:#475569;font-size:15px;line-height:1.55;">Ya podes descargar tu factura desde el siguiente acceso.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <tr>
                  <td style="padding:16px;text-align:center;">
                    <p style="margin:0 0 6px;color:#64748b;font-size:12px;text-transform:uppercase;">Servicio</p>
                    <p style="margin:0;font-size:18px;font-weight:bold;">{{snb}}</p>
                  </td>
                  <td style="padding:16px;text-align:center;">
                    <p style="margin:0 0 6px;color:#64748b;font-size:12px;text-transform:uppercase;">Vencimiento</p>
                    <p style="margin:0;font-size:18px;font-weight:bold;">{{vencimiento}}</p>
                  </td>
                  <td style="padding:16px;text-align:center;">
                    <p style="margin:0 0 6px;color:#64748b;font-size:12px;text-transform:uppercase;">Importe</p>
                    <p style="margin:0;font-size:18px;font-weight:bold;">{{importe}}</p>
                  </td>
                </tr>
              </table>
              <a href="{{url_factura}}" target="_blank" style="display:inline-block;background:#14b8a6;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:8px;">Descargar factura</a>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 28px;background:#0f172a;color:#cbd5e1;text-align:center;font-size:12px;line-height:1.5;">
              <p style="margin:0 0 6px;">{{domicilio}}</p>
              <p style="margin:0;">Si el correo es recibido como no deseado, marcalo como seguro para recibirlo correctamente.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    private static function defaultTemplateHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Espacial TV - La Fibra Optica de Infracom</title>
  <style>
    @media only screen and (max-width:620px) {
      .sm-container { width:100% !important; }
      .sm-col { display:block !important; width:100% !important; }
      .sm-col img { width:100% !important; height:auto !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#0a0a1a;font-family:Arial,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0a0a1a;">
    <tr>
      <td align="center" style="padding:20px 10px;">
        <table role="presentation" class="sm-container" data-sm-builder="1" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background:#0d0d2b;border-radius:16px;overflow:hidden;">
          <tr>
            <td style="background:linear-gradient(135deg,#0d0d2b 0%,#0a1a3a 100%);padding:32px 30px 24px;text-align:center;border-bottom:3px solid #00c8ff;">
              <p data-sm-field="title" style="margin:0 0 4px;font-family:Impact,Arial,sans-serif;font-size:52px;letter-spacing:6px;color:#00c8ff;line-height:1;">ESPACIAL</p>
              <p data-sm-field="subtitle" style="margin:0 0 14px;font-family:Arial,sans-serif;font-size:11px;letter-spacing:3px;color:#8899bb;text-transform:uppercase;">La Fibra &Oacute;ptica de INFRACOM</p>
              <p data-sm-field="headerKicker" style="margin:0;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;letter-spacing:1px;">&#10022;&nbsp; TE OFRECE CONTENIDO PREMIUM &nbsp;&#10022;</p>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 40px 10px;text-align:center;">
              <p data-sm-field="intro" style="margin:0;font-family:Arial,sans-serif;font-size:15px;color:#aabbdd;line-height:1.7;">Con la fibra &oacute;ptica de Infracom disfrut&aacute;s la mejor se&ntilde;al para ver los eventos m&aacute;s grandes del deporte y el entretenimiento. &iexcl;Mir&aacute; lo que est&aacute; llegando!</p>
            </td>
          </tr>
          <tr>
            <td style="padding:22px 40px 14px;text-align:center;">
              <p data-sm-field="imageLabel" style="margin:0;font-family:Impact,Arial,sans-serif;font-size:22px;letter-spacing:5px;color:#00c8ff;">&#128250; PR&Oacute;XIMOS EVENTOS</p>
            </td>
          </tr>
          <tr>
            <td data-sm-images="1" data-sm-layout="auto" style="padding:0 20px 24px;text-align:center;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td class="sm-col" width="100%" align="center" style="padding:8px;vertical-align:top;text-align:center;">
                    <img data-sm-image="1" src="" alt="Espacial TV" width="560" style="max-width:100%;width:100%;height:auto;display:block;margin:0 auto;border-radius:12px;border:1px solid #1d3658;">
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 20px 10px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#071830;border-radius:12px;border:1px solid rgba(0,200,255,0.3);">
                <tr>
                  <td style="padding:20px;text-align:center;">
                    <p style="margin:0 0 4px;font-family:Impact,Arial,sans-serif;font-size:42px;letter-spacing:2px;line-height:1;color:#ffffff;">
                      <span data-sm-field="channelsLead" style="color:#00c8ff;">ADEM&Aacute;S</span>
                      <span data-sm-field="channelsConnector">DE LOS</span>
                      <span data-sm-field="channelsCount" style="color:#00c8ff;">75</span>
                      <span data-sm-field="channelsLabel">CANALES</span>
                    </p>
                    <p data-sm-field="channelsText" style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:12px;color:#5577aa;letter-spacing:2px;text-transform:uppercase;">incluidos en tu paquete</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 40px;text-align:center;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                  <td style="border-top:1px solid rgba(0,200,255,0.2);"></td>
                  <td data-sm-field="dividerIcon" style="width:40px;text-align:center;padding:0 10px;font-size:18px;color:#00c8ff;">&#9889;</td>
                  <td style="border-top:1px solid rgba(0,200,255,0.2);"></td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 20px 30px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#071428;border-radius:14px;border:2px solid rgba(0,200,255,0.4);">
                <tr>
                  <td style="padding:26px 28px;text-align:center;">
                    <p data-sm-field="ctaEyebrow" style="margin:0 0 6px;font-family:Arial,sans-serif;font-size:11px;letter-spacing:4px;color:#00c8ff;text-transform:uppercase;font-weight:bold;">&iquest;Todav&iacute;a no lo ten&eacute;s?</p>
                    <p data-sm-field="ctaTitle" style="margin:0 0 8px;font-family:Impact,Arial,sans-serif;font-size:28px;letter-spacing:2px;color:#ffffff;">&iexcl;SOLICIT&Aacute; TU CONEXI&Oacute;N HOY!</p>
                    <p data-sm-field="ctaText" style="margin:0 0 20px;font-family:Arial,sans-serif;font-size:14px;color:#aabbdd;line-height:1.6;">Pedilo por este medio o escribinos por WhatsApp:</p>
                    <a data-sm-field="buttonText" href="https://wa.me/5492284599523" target="_blank" style="display:inline-block;background:#25D366;color:#ffffff;font-family:Arial,sans-serif;font-size:19px;font-weight:bold;text-decoration:none;padding:14px 34px;border-radius:50px;">&#128241;&nbsp;&nbsp;WhatsApp: 2284 599523</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="background:#070714;padding:20px 30px;text-align:center;border-top:1px solid rgba(0,200,255,0.15);">
              <p data-sm-field="footer" style="margin:0 0 10px;font-family:Arial,sans-serif;font-size:12px;color:#556688;">&copy; 2026 Infracom. Todos los derechos reservados.</p>
              <p data-sm-field="footerBrand" style="margin:0 0 5px;font-family:Impact,Arial,sans-serif;font-size:20px;letter-spacing:5px;color:#00c8ff;">ESPACIAL</p>
              <p data-sm-field="footerSubtitle" style="margin:0 0 8px;font-family:Arial,sans-serif;font-size:11px;color:#445566;letter-spacing:2px;text-transform:uppercase;">La Fibra &Oacute;ptica de Infracom</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
