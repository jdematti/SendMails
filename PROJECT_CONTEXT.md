# SendMails: referencia técnica

Fecha de revisión: 2026-09-14. Referencia local para próximos cambios, basada en el código disponible. Volver a comprobar los archivos afectados antes de modificar: este documento puede quedar desactualizado.

Actualización 2026-09-14: se implementaron adjuntos para plantillas de campañas email. Ver detalles en la sección Plantillas y correo. Las observaciones de la revisión inicial siguientes describen el estado anterior a ese cambio.

## Estado actual: mejoras de agilidad (2026-09-14)

Esta sección y MEJORAS_Y_DESPLIEGUE.md prevalecen sobre las observaciones históricas siguientes. Se implementaron los cambios en una copia de trabajo aislada porque la instalación habitual apunta a datos reales.

- send.php e invoices.php son entradas del flujo compartido compose.php / assets/js/compose.js. Tres pasos, búsqueda SQL paginada de 50 filas, selección por IDs, revisión antes de confirmar y filtros recordados por usuario/sucursal. Ya admite campañas solo con emails manuales.
- compose_api.php autentica y verifica CSRF, cierra el bloqueo de sesión antes del trabajo largo, y coordina ComposeService con DraftRepository. Los borradores son compartidos por sucursal, con revisiones y confirmación idempotente.
- Las colas de ambos canales se crean en una transacción. BulkInsert limita cada INSERT a menos de 2100 parámetros. Se probó con 4000 destinatarios.
- MessageSnapshot guarda una copia por campaña/lote, incluidos adjuntos y opciones de plantilla; pending aplica esa copia. La migración captura el contenido vigente de lotes queued/processing/paused. No reconstruye históricos terminados.
- Schema::ensure solo comprueba una versión, una vez por petición. migrate.php ejecuta Schema::migrate y AgilityMigration en transacción con bloqueo de migración/worker. En actualizaciones no reasigna usuarios ni elimina plantillas mediante semillas.
- Nueva tabla SendMail_Drafts. Campaigns, InvoiceBatches y WhatsAppBatches tienen message_snapshot; Queue e InvoiceQueue, is_test. Templates e InvoiceTemplates tienen content_revision para impedir publicaciones concurrentes.
- TemplateDraft integra borradores compartidos en ambos editores, conserva archivos antes de preview, detecta cambios concurrentes y permite publicar como nueva plantilla. Los iframes del contenido HTML de estos editores y del compositor tienen sandbox.
- Desde 2026-09-15, `attachment_preview.php` y `assets/js/attachment-preview.js` agregan vista previa de adjuntos de campañas: imágenes, PDF mediante visor nativo y texto literal, descarga para otros formatos, archivos locales sin subir y guardados a demanda. El endpoint exige sesión/sucursal, restringe plantillas y borradores a esa sucursal y verifica huella de nombre/contenido para rechazar versiones distintas. Devuelve descarga con no-store/nosniff; el cliente solo incrusta tipos reconocidos, libera URLs temporales y no ejecuta HTML/SVG. No cambia el esquema ni los snapshots de lotes confirmados.
- El modo test de InvoiceMailerService no marca FacturasTel como enviada. Un fallo después de aceptación SMTP deja el item para revisión en sending con explicación, sin reintento automático.
- activity.php / ActivityRepository consolidan email y WhatsApp de campañas/facturas, con lotes, progreso, estados, errores y pausa/continuación. Inicio usa estas métricas. Las páginas administrativas previas siguen disponibles.
- WorkerRuntime alterna sucursales activas y canales, persiste sus intervalos, mantiene heartbeat y un bloqueo exclusivo. Señala sending de una ejecución anterior para revisión. No hay procesamiento desde el navegador.
- WorkerControl agrega control cooperativo por sucursal mediante archivos privados con revisión; worker_control.php exige sesión, sucursal actual y CSRF para cambios. El indicador global del encabezado abre un modal; lee heartbeat por sucursal y permite detener/iniciar sin terminar el proceso de Windows. Errores por canal e inactividad se muestran en rojo.
- PurgeRepository/purge.php permiten purgar solo a administradores, en todas las sucursales o por filtro, con antigüedad mínima de 90 días. Vista previa de hasta 20 lotes terminales sin actividad reciente y presupuesto de 10.000 destinatarios/registros (un lote mayor se procesa solo), confirmación vinculada a sesión/usuario, revalidación transaccional y comprobante idempotente en SendMail_Settings (`purge_receipt:<token>`). No afecta bases de origen ni plantillas/bajas/exclusiones. PurgeMigration agrega siete índices en `20260914-2`; consultas con enlaces separados por campaña/destinatario, timeout SQL de 15 segundos y presupuesto de 45 segundos para revertir antes del límite PHP. Se conserva la transacción completa por vista previa.
- El actualizador Windows activa mantenimiento, deshabilita la tarea y espera hasta 180 segundos a que termine y se libere el bloqueo del worker, mostrando progreso. Continúa automáticamente con el respaldo de archivos y conserva CMD no rastreados antes de hacer fast-forward; si vence el plazo, no interrumpe procesos ni modifica el código. Ejecuta Composer, migración y registro del worker como SYSTEM cada minuto; libera mantenimiento al completar.
- Pruebas: tests/run.ps1 crea y elimina una base temporal local SQLEXPRESS y almacenamiento temporal separado, sin leer la configuración real. Incluye migración, 4000 filas, snapshots, atomicidad de canales, conflictos, permisos de sucursal y modo test. Worker/transporte/tarea se simulan. tests/browser.cjs prueba los flujos reales de PHP/JS con esa base temporal en Edge. Documentación de ejecución y límites en MEJORAS_Y_DESPLIEGUE.md.
- No se ejecutaron migraciones sobre datos reales ni envíos externos durante el desarrollo. La ejecución con credenciales/servicios y la cuenta SYSTEM de producción se comprueba al desplegar.
- Smoke test 2026-09-15: `tests/run.ps1 -Smoke` suma autenticación real, permisos y recorrido de pantallas con datos ficticios y transportes deshabilitados. Se corrigieron parámetros SQL repetidos en eventos, bajas y sincronización de plantillas WhatsApp, y el ancho del buscador de Clientes en móvil. Detalle de cobertura y límites en `SMOKE_TEST_2026-09-15.md`.
- Revisión de usabilidad 2026-09-15: `assets/css/usability.css` y `assets/js/usability.js` compactan las vistas, hacen accesibles las tablas desplazables y agrupan opciones. Inicio ofrece dos tareas principales; Seguimiento carga destinatarios solo en detalle/filtro de estado/`recipients=1`, conserva página y posición al refrescar y expone administración desde Más opciones. Compositor: filtros secundarios desplegables, planes con casillas, borradores en desplegable y acciones al pie; mantiene el contrato de `input.plans` como array. Editores agrupan opciones avanzadas y revelan campos inválidos; la vista previa de campaña espera la carga inicial del iframe antes de reemplazar su documento. Cuenta usa opciones con texto. Pruebas nuevas: 50 vistas, 11 recorridos y comparación antes/después en `USABILIDAD_2026-09-15.md`. No cambia el esquema.

## Observaciones históricas de la revisión inicial

Los apartados que siguen documentan la versión anterior y conservan contexto de los módulos que no cambiaron. Los problemas ya corregidos se indican en el estado actual de arriba.

## Alcance y verificación

- Aplicación PHP sin framework, con páginas que combinan controlador, HTML y JavaScript; clases estáticas en `app/` para datos y servicios.
- Se revisaron arquitectura, flujos, consultas, controles de acceso, configuración, pantallas y scripts operativos. No es una auditoría exhaustiva ni una validación funcional de producción.
- Los 61 archivos PHP propios pasaron `php -l` con PHP CLI 8.2.12. No se encontraron pruebas automatizadas propias en el inventario.
- El runtime local incluye `pdo_sqlsrv`, `curl`, `openssl`, `mbstring`, `dom`, `fileinfo` y `zip`. Composer fija PHPMailer v6.10.0; `composer.json` pide `^6.10`. README declara PHP 7.4 o superior.
- No se ejecutaron bootstrap, migraciones, consultas a bases, worker, envíos, tareas programadas ni llamadas a Meta. No se inspeccionaron valores de credenciales locales. No se hizo validación visual en navegador.
- Existe un directorio `.git`, pero `git status` respondió que no es un repositorio Git. No asumir que hay historial recuperable.
- Actualización al preparar publicación: Git ya está operativo, rama `main`, remoto `origin` en `https://github.com/jdematti/SendMails.git`. Antes de esta publicación solo estaba versionado README. Se incorporó `.gitignore` para dependencias, configuración y claves locales, logs y archivos del agente.
- Esta revisión solo añadió este documento; no cambió la aplicación ni sus datos.

## Inicio, datos y configuración

- Todas las páginas cargan `app/bootstrap.php`: sesión, autoload de Composer, constantes, helpers, zona horaria, clases y `Auth::enforceRequest()`.
- `app/Database.php`: conexión central PDO SQL Server; configuración en `storage/db_config.json`. Su contraseña se serializa sin cifrado.
- `app/BranchRepository.php`: conexiones operativas por sucursal, permisos de usuarios y sucursal seleccionada en `$_SESSION['branch_id']`.
- `activeBranchWhere()` limita a la sucursal activa; sin sucursal, devuelve `1 = 0` en web y no filtra en CLI. El worker procesa distintas sucursales.
- `app/Settings.php`: tabla central de claves/JSON. Claves `app`, `smtp:<id>`, `whatsapp:<id>` y `unsubscribe_secret`. URL pública global; SMTP y Meta por sucursal. SMTP guarda contraseña en JSON sin cifrar; secretos de Meta se cifran.
- `app/SecretBox.php`: AES-256-GCM, clave local `storage/branch_secret.key`, formato `v1:`. No perder esa clave al mover la instalación.
- `storage/.htaccess` contiene `Require all denied`; `router.php` bloquea `/storage/` para el servidor PHP de desarrollo. La aplicación efectiva de la protección Apache no se verificó.
- `helpers.php`: escape HTML, CSRF, flashes, validación básica, reparación de mojibake y fechas. Zona horaria: `APP_TIMEZONE`, `TZ` o detección Windows. SQL usa `SYSDATETIME()`.

## Esquema central

`app/Schema.php` concentra creación y actualización de tablas, índices, defaults, semillas y migración de datos antiguos. `ensure()` se invoca desde autenticación y muchos repositorios, sin guardia de ejecución única por petición.

Familias de tablas `dbo.SendMail_*`:

- Configuración y acceso: `Settings`, `Branches`, `UserBranches`, `Users`, `PasswordResets`.
- Campañas email: `Templates`, `Campaigns`, `Queue`, `Log`.
- Destinatarios: `Unsubscribes`, `EmailExclusions`.
- Facturas email: `InvoiceTemplates`, `InvoiceBatches`, `InvoiceQueue`, `InvoiceLog`.
- WhatsApp: `WhatsAppTemplates`, `WhatsAppBatches`, `WhatsAppQueue`, `WhatsAppEvents`, `WhatsAppOptOuts`.

Las semillas crean Admin, sucursal Principal desde la conexión central y plantillas iniciales. Migran filas sin `branch_id` a una sucursal por defecto y generan lotes para colas de facturas antiguas. Para tablas existentes sin IDENTITY/default, agregan secuencias para IDs. También copian SMTP a sucursales sin configuración y eliminan ciertas plantillas duplicadas sin referencias.

## Autenticación y permisos

- `app/Auth.php` y `app/UserRepository.php`; roles `Admin` y `Usuario`, login por usuario/email, contraseñas con `password_hash`/`password_verify`.
- Sesión de tres horas de inactividad; nuevos usuarios deben cambiar contraseña temporal. Protección del último Admin activo.
- Recuperación: token aleatorio, hash SHA-256 almacenado, vigencia de 60 minutos, envío SMTP inmediato.
- Administración restringida por nombre de página: SMTP, conexión central, usuarios, sucursales y configuración Meta.
- Páginas públicas declaradas: login, recuperación, reset, baja y webhook. CLI omite el control web.
- Formularios POST usan CSRF. Selecciones de clientes/facturas transportan JSON base64url firmado con HMAC y token CSRF de la sesión, incluyendo sucursal.

## Clientes y campañas

- `ClientRepository`: prefiere `dbo.v_sendmail_clientes`, con fallback a `dbo.clientes`; detecta alias de columnas por metadatos. Campos: ID, código, razón social, plan, email, celular, localidad, provincia.
- `clients.php`: búsqueda y filtros de suscripción/exclusión; permite alternar exclusión de email.
- `send.php`: carga clientes alcanzables, filtros locales por texto/planes y paneles de disponibles/seleccionados. El panel derecho define la selección enviada. Canales `email`, `whatsapp`, `both`; fecha/hora programada y emails manuales adicionales.
- Email: valida direcciones, filtra bajas/exclusiones y deduplica por email normalizado; crea campaña y cola en una transacción mediante `QueueRepository`.
- `UnsubscribeRepository`: bajas por email y sucursal. Tokens HMAC con secreto central; `unsubscribe.php` muestra confirmación y guarda por POST.
- `EmailExclusionRepository`: exclusiones globales por email, sin `branch_id`. No confundir con bajas por sucursal.
- `campaigns.php`: pausa, reanudación, detención; elimina campañas email únicamente `queued` sin movimientos. Detener omite pendientes, no cancela un envío ya iniciado.

## Plantillas y correo

- `templates.php` / `template_edit.php` / `TemplateRepository`: plantillas de campañas por sucursal, clonación, HTML y editor visual propio.
- Editor visual: campos de texto, imágenes reordenables, distribución automática o de 1–3 columnas, lectura de imágenes como data URL con límite de 4 MB por archivo en JavaScript. Genera HTML de tablas; reconoce marcas `data-sm-field` y `data-sm-image`.
- Las vistas previas usan iframe `srcdoc`. El editor visual puede regenerar el HTML; no asumir que conserva arbitrariamente cualquier estructura importada.
- Variables de campañas definidas en `app/config.php`: código, razón social, plan, email, celular, localidad, provincia y URL de baja.
- `MailerService`: render con `strtr`, pie de baja si falta placeholder, encabezado List-Unsubscribe, envío PHPMailer, imágenes data URI convertidas a CID y logs con snapshot HTML/contexto/duración.
- Las colas email conservan contexto del destinatario, pero consultan asunto/HTML actuales de la plantilla al enviar. Editar una plantilla puede afectar pendientes existentes.
- Envíos de prueba SMTP, previews y recuperación son inmediatos; crear campañas/facturas solamente encola.
- Adjuntos de campañas: `app/CampaignAttachments.php` valida cargas, nombres, cantidad (5), tamaño agregado (10 MB), serialización y composición MIME. Contenido base64 y metadatos se guardan en `SendMail_Templates.attachments_json` (nvarchar(max), nullable); no se almacenan archivos permanentes en el filesystem.
- `template_edit.php` usa multipart, permite agregar/quitar adjuntos, los copia desde la plantilla origen y los pasa a preview sin guardar. Los archivos nuevos se deben volver a seleccionar tras preview/error. La actualización de plantilla y adjuntos es un único INSERT/UPDATE, restringido por sucursal.
- `QueueRepository::pending()` carga los adjuntos actuales de la plantilla; `MailerService` los incorpora con PHPMailer `addStringAttachment`. Aplica a cola web y worker. No se agregaron adjuntos a WhatsApp ni a facturas.
- Pruebas: `tests/campaign_attachments_test.php` verifica serialización binaria, eliminación, límites, errores de upload, nombres y MIME combinado con imágenes CID, sin bootstrap/DB/envíos. También se comprobó multipart HTTP real en un servidor local temporal (dos archivos íntegros y rechazo de seis). No se probó SMTP externo ni la migración contra la base real.

## Facturas

- `invoices.php` / `InvoiceRepository`: consulta `FacturasTel` unida a `clientes` mediante `OId`/`IdCliente`, collation `Modern_Spanish_CI_AS`. Filtra autorizadas, no anuladas, vencimiento, impagas/pagas, SNB, email, celular y nombre.
- Vista inicial limitada a 250 registros; opciones 100/250/500/1000. Seleccionar todas vuelve a consultar en servidor sin límite de presentación. Conteo SQL y validación PHP de email pueden diferir.
- `InvoiceCrypto`: enlace compatible con sistema externo KratosWeb mediante AES-256-CBC y parámetros `l`/`f`. La compatibilidad es sensible a cambios de cifrado y formato.
- La clave de `InvoiceCrypto` se extrajo del código antes de versionarlo: ahora se lee de `INVOICE_CRYPTO_KEY` o `storage/invoice_crypto.key` (excluido de Git). La instalación local conserva el valor anterior; se comprobó que produce exactamente el mismo cifrado para un valor de prueba. Conservarla en respaldos privados junto con las otras claves.
- `invoice_templates.php` / `invoice_template_edit.php`: plantillas HTML, clonación y preview, remitente/reply-to/BCC, dominio/prefijo, redes/domicilio y modo test.
- Variables: nombre, email, importe, SNB, vencimiento, URL factura, web, domicilio, Facebook, Instagram y WhatsApp.
- `InvoiceMailerService`: usa SMTP de sucursal con overrides de plantilla; en modo test cambia destinatario y prefija asunto. Luego llama a `InvoiceRepository::markSent`, que actualiza cola central y `FacturasTel.mail_enviado`/`fecha_mail_enviado`.
- `invoice_sends.php`: gestión de lotes; `invoice_queue.php` redirige a cola unificada. `invoice_process_step.php` conserva endpoint email antiguo.
- `invoice_export.php`: genera XLSX directamente con XML y ZIP implementado en PHP, sin librería de planillas. Exporta según filtros y límite recibido.
- WhatsApp de facturas usa el enlace generado; no encontré actualización de `FacturasTel.mail_enviado` en su servicio.

## WhatsApp

- `whatsapp.php`: configuración Meta por sucursal; guardar, probar conexión, enviar hello_world y sincronizar plantillas.
- `WhatsAppBusinessService`: Cloud API por cURL, bearer token, timeout de conexión 10 s y total 45 s; versión configurable, default en código `v25.0`. No se verificó vigencia externa de esa versión.
- `whatsapp_templates.php` / `WhatsAppRepository`: sincronización, activación y orden de variables. Plantillas aprobadas `MARKETING` para campañas y `UTILITY` para facturas. Implementa parámetros de texto del cuerpo; no asumir soporte completo de headers/botones dinámicos.
- Deduplicación por celular para campaña y factura+celular para facturas, dentro del lote.
- `WhatsAppPhone`: normalización argentina a `549` + diez dígitos; también soporta país configurable.
- `WhatsAppMailerService`: reclama item y llama a Meta; respuesta aceptada se guarda como `accepted`, no equivale a entrega. Reintenta errores transitorios hasta cinco intentos con espera exponencial.
- `whatsapp_webhook.php`: desafío GET, firma HMAC SHA-256 POST, eventos por hash, actualización `sent`/`delivered`/`read`/`failed` y bajas por respuestas como BAJA/STOP. Las bajas bloquean campañas, no facturas transaccionales.

## Cola, ejecución e interfaz

- `UnifiedQueueService`: combina campañas/facturas y email/WhatsApp; elige el próximo tipo/canal por fecha programada. Procesa una unidad por paso y consolida conteos.
- Estados de item email: `pending`, `sending`, `sent`, `failed`, `skipped`; WhatsApp agrega estados del proveedor. Lotes: `queued`, `processing`, `paused`, `stopped`, `completed`.
- Reclamo mediante UPDATE condicionado a `pending` y lote habilitado; evita que dos procesos reclamen el mismo item simultáneamente.
- `queue.php`: filtros, listados y procesamiento con progreso vía `queue_process_step.php`; fallback POST por tanda. Intervalo SMTP se espera entre pasos.
- `worker.php --limit=100`: CLI para todas las sucursales/canales. `run_worker.ps1`: archivo de bloqueo exclusivo y log diario en `storage/logs`. `install_scheduled_task.ps1`: registro de tarea Windows, default cada 15 minutos y límite 1000. No se verificó instalación activa.
- `index.php` y `logs.php` se apoyan en métricas/logs de campañas email; no asumir que reflejan todo el tráfico unificado.
- `app/layout/header.php` y `footer.php`: navegación agrupada, usuario/sucursal, menú móvil y modal de espera. `assets/css/app.css`: diseño propio, barra lateral oscura y contenido claro/turquesa; breakpoints 1180/980/560 px. JavaScript incrustado en páginas; no hay build frontend en el inventario.

## Puntos de atención para próximos cambios

Observaciones del código, sin correcciones aplicadas ni reproducción contra servicios reales:

1. Modo test de la cola de facturas también marca la factura operativa como enviada; la preview de plantilla es otro flujo.
2. Exclusiones manuales de email afectan a todas las sucursales; bajas email/WhatsApp tienen alcance por sucursal.
3. `Schema::ensure()` hace trabajo considerable y mutaciones incluso desde operaciones de lectura. Además asigna sucursal por defecto a usuarios sin asignaciones y borra ciertas plantillas duplicadas sin uso. Considerarlo antes de ejecutar la app para inspeccionarla.
4. Canal `both` realiza transacciones separadas: puede quedar email encolado aunque falle después WhatsApp.
5. `Auth::canProcessQueue()` declara una restricción de diez pendientes para Usuario, pero no se encontraron llamadas que la apliquen.
6. No se encontró recuperación automática de items abandonados en `sending`. Una caída después del envío externo y antes de registrar éxito deja incertidumbre; los cambios deben considerar duplicados.
7. En facturas, envío externo, actualización central y actualización operativa no son una operación atómica. Un error posterior al envío puede registrarlo como fallido.
8. Los iframe de preview HTML no tienen `sandbox` en los editores revisados; evaluar el aislamiento cuando se toque ese flujo.
9. El webhook elige configuración/sucursal desde el primer entry/change y la reutiliza para todo el payload; revisar el caso de múltiples cuentas o números por payload.
10. Borrado de sucursal revisa referencias email/facturas, pero su lista no incluye las tablas WhatsApp ni elimina `whatsapp:<id>`.
11. `send.php` exige un cliente seleccionado antes de incorporar emails manuales; una campaña exclusivamente manual no pasa esa validación.

## Cómo retomar

Leer esta referencia y los archivos del módulo solicitado. Mantener separados alcance de sucursal, tipo de envío y canal. Verificar tanto creación de cola como procesamiento web/CLI y, si aplica, webhook. Ajustar documentación después de cambios significativos. No guardar credenciales ni datos de clientes en esta referencia.
