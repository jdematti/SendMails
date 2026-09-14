# SendMails

Sistema PHP para administrar plantillas HTML, clientes desde SQL Server, campanas de correo, facturas y cola de envios por sucursal.

## Requisitos

- PHP 7.4 o superior.
- Extension `pdo_sqlsrv` habilitada.
- Composer.
- Acceso a la base central `SendMails`.
- Acceso a las bases operativas de cada sucursal. Para clientes se usa `dbo.v_sendmail_clientes` cuando existe; si no existe, se usa `dbo.clientes` con columnas compatibles.

## Primer uso

1. Abrir `config_db.php`.
2. Cargar servidor, base de datos central `SendMails`, usuario y contrasena.
3. Usar `Probar conexion`.
4. Usar `Guardar y crear tablas`.
5. Ingresar con el usuario Admin inicial.

La conexion central se guarda en `storage/db_config.json`. Esa conexion debe apuntar a `SendMails`, donde quedan las tablas propias del sistema:

- `SendMail_Settings`
- `SendMail_Branches`
- `SendMail_UserBranches`
- `SendMail_Templates`
- `SendMail_Campaigns`
- `SendMail_Queue`
- `SendMail_Log`
- `SendMail_Unsubscribes`
- `SendMail_EmailExclusions`
- `SendMail_Users`
- `SendMail_PasswordResets`
- `SendMail_InvoiceTemplates`
- `SendMail_InvoiceBatches`
- `SendMail_InvoiceQueue`
- `SendMail_InvoiceLog`
- `SendMail_WhatsAppTemplates`
- `SendMail_WhatsAppBatches`
- `SendMail_WhatsAppQueue`
- `SendMail_WhatsAppEvents`
- `SendMail_WhatsAppOptOuts`

Las bases de las sucursales se cargan desde `branches.php`. Esas conexiones se usan para consultar clientes/facturas y para actualizar `FacturasTel.mail_enviado` cuando corresponde. Plantillas, SMTP, colas, logs y usuarios quedan en `SendMails`, asociados a `branch_id` cuando aplica.

## Seguridad

Usuario inicial:

- usuario: `admin`
- email: `demattij@infracom.com.ar`
- rol: `Admin`

El login acepta usuario o email. Los roles disponibles son:

- `Admin`: acceso completo.
- `Usuario`: acceso a Dashboard, Clientes, Plantillas, Campanas, Cola y Logs. No puede acceder a Configuracion ni tipeando la URL.

Los usuarios nuevos creados por Admin deben cambiar su contrasena temporal al ingresar. Cada usuario tiene permisos sobre una o mas sucursales. Si tiene una sola sucursal asignada, entra directo; si tiene varias, el sistema pide seleccionar con cual trabajar. La recuperacion de contrasena usa el SMTP disponible y links con vencimiento de 60 minutos.

## Sucursales

Abrir `branches.php` para cargar o editar:

- nombre de sucursal
- servidor
- puerto
- base de datos
- usuario DB
- contrasena DB
- encriptacion y confianza de certificado
- estado activo/inactivo

Las contrasenas de sucursal se guardan cifradas en `SendMails`. El archivo `storage/branch_secret.key` contiene la clave local usada para ese cifrado y debe conservarse junto con la instalacion.

## SMTP

Abrir `smtp.php` y cargar:

- servidor
- puerto
- usuario
- contrasena
- seguridad TLS, SSL o ninguna
- email remitente
- nombre remitente
- email de respuesta
- URL publica del sistema para generar links de baja
- segundos entre cada envio

La configuracion SMTP es por sucursal activa. La fecha de la prueba SMTP y de los registros nuevos usa la zona horaria local del servidor. En Windows se toma el identificador del sistema operativo, por ejemplo `Argentina Standard Time`.

## Variables de plantillas

Las plantillas soportan:

- `{{codigo_cliente}}`
- `{{razon_social}}`
- `{{plan}}`
- `{{email}}`
- `{{telefono_movil}}`
- `{{localidad}}`
- `{{provincia}}`
- `{{url_baja}}`
- `{{unsubscribe_url}}`

Los clientes se leen desde la base de la sucursal activa. El origen preferido es `dbo.v_sendmail_clientes`; si no esta disponible, se usa `dbo.clientes`. El sistema reconoce nombres comunes de columnas como `codigo_cliente`, `razon_social`, `plan_contratado`, `email`, `telefono_movil`, `localidad` y `provincia`.

Si una plantilla de campana no incluye `{{url_baja}}` ni `{{unsubscribe_url}}`, el sistema agrega automaticamente un pie con el link de baja.

## Campanas

### Archivos adjuntos

En `Plantillas > Campañas`, al crear o editar una plantilla, la sección `Archivos adjuntos` permite seleccionar hasta 5 archivos, con un máximo de 10 MB en total. Se pueden quitar archivos existentes marcando `Quitar` y guardando la plantilla. También se respetan los límites de carga de PHP.

Los adjuntos se guardan junto con la plantilla en la base central y se copian al crear una plantilla desde otra. Se incluyen en cada email de campaña que use esa plantilla, tanto desde la cola web como desde el worker. Los cambios en los adjuntos también afectan los emails pendientes que usen la plantilla.

`Enviar prueba` incluye los archivos guardados y los nuevos seleccionados, descontando los marcados para quitar, sin guardar los cambios. Después de una prueba o un error hay que volver a seleccionar los archivos nuevos antes de guardar. Estos adjuntos corresponden al canal email.

El esquema agrega automáticamente la columna nullable `SendMail_Templates.attachments_json`; las plantillas existentes siguen funcionando sin adjuntos. Para comprobar validación y composición MIME sin enviar correos ni conectar a la base: `php tests/campaign_attachments_test.php`.

Desde `send.php` se crea una campana:

- El panel derecho define los destinatarios reales.
- No se encolan clientes que ya cancelaron la suscripcion.
- Solo se encolan clientes con email valido.

Crear una campana no envia mails inmediatamente; solo deja registros pendientes en `SendMail_Queue` para la sucursal activa.

## Bajas de suscripcion

Los correos de campana incluyen un link a `unsubscribe.php` firmado por token. La pantalla es publica y pide confirmacion antes de guardar la baja en `SendMail_Unsubscribes`.

Cuando un destinatario cancela la suscripcion:

- deja de aparecer en la preparacion de nuevas campanas;
- si ya estaba pendiente en la cola, el envio se marca como `skipped`;
- la baja se aplica por email normalizado dentro de la sucursal del envio.

Para envios desde `worker.php` o tareas programadas, configurar la `URL publica del sistema` en `smtp.php`.

## Facturas

Desde `invoices.php` se listan facturas de `FacturasTel` por vencimiento, estado, SNB, email y nombre. La pantalla limita los resultados iniciales para evitar generar enlaces y tokens para miles de facturas de una vez. El sistema genera un link compatible con KratosWeb usando el cifrado de `mcript.php`, crea un envio de facturas en `SendMail_InvoiceBatches`, encola los items y permite gestionarlos desde `invoice_sends.php`.

La plantilla de facturas se edita desde el boton `Plantilla` del modulo Facturas. Soporta:

- `{{nombre}}`
- `{{email}}`
- `{{importe}}`
- `{{snb}}`
- `{{vencimiento}}`
- `{{url_factura}}`
- `{{web}}`
- `{{domicilio}}`
- `{{facebook}}`
- `{{instagram}}`
- `{{whatsapp}}`

La plantilla de facturas y el SMTP usado son los de la sucursal activa. El remitente, reply-to, BCC, dominio web, prefijo de localidad y modo test se configuran en la plantilla de facturas. Cuando un envio finaliza correctamente se actualiza `FacturasTel.mail_enviado` y `FacturasTel.fecha_mail_enviado` en la base operativa de esa sucursal.

La clave compatible con el sistema externo de facturas se configura mediante la variable de entorno `INVOICE_CRYPTO_KEY` o el archivo local `storage/invoice_crypto.key`. Debe ser la misma clave que utiliza el sistema que abre los enlaces; no generar una nueva al instalar. Este archivo, `storage/db_config.json` y `storage/branch_secret.key` quedan excluidos de Git y deben conservarse en el respaldo privado de la instalacion. En una instalacion nueva, ejecutar `composer install` para obtener las dependencias de `composer.lock`.

## WhatsApp Business Platform

La integracion utiliza la Cloud API oficial de Meta y una configuracion independiente por sucursal. No registra ni migra el numero existente: el alta o coexistencia del numero que ya funciona con WhatsApp Business se completa primero en Meta Business.

En `whatsapp.php`, con la sucursal activa seleccionada, cargar:

- WhatsApp Business Account ID (WABA ID)
- Phone Number ID
- token de acceso permanente
- App Secret
- Verify Token del webhook
- version de Graph API
- numero receptor de prueba

El token de acceso, App Secret y Verify Token se guardan cifrados. La pantalla informa el Callback URL que debe registrarse en Meta. La URL debe ser publica y HTTPS. Despues de probar la conexion, sincronizar las plantillas aprobadas desde Meta y configurar en `whatsapp_templates.php` el orden de sus variables.

Las plantillas `MARKETING` se ofrecen en campañas y las `UTILITY` en facturas. Para facturas, la variable `invoice_url` envia inicialmente el enlace generado por el flujo actual. El estado inicial `accepted` solo indica que Meta acepto la solicitud; `sent`, `delivered`, `read` y `failed` se actualizan con el webhook firmado.

Los celulares argentinos se normalizan al formato `549` seguido del codigo de area y numero, sin `0` ni `15`. Una respuesta `BAJA`, `STOP`, `CANCELAR`, `CANCELACION`, `NO RECIBIR` o `NO QUIERO RECIBIR` bloquea futuras campañas de WhatsApp para ese celular dentro de la sucursal. No bloquea facturas transaccionales.

## Worker

Para procesar pendientes de campanas y facturas, tanto por email como por WhatsApp, desde consola:

```powershell
php worker.php --limit=100
```

Tambien se pueden procesar pendientes desde `queue.php` indicando un limite por tanda. El worker no requiere sucursal seleccionada: procesa pendientes de todas las sucursales y toma el SMTP o la configuracion de Meta correspondiente a cada item por su `branch_id`.

## Actualizar produccion con doble clic

Si la instalacion existente todavia no tiene Git inicializado, copiar `inicializar_produccion.cmd` a su carpeta y ejecutarlo una sola vez. Requiere Git instalado y acceso al repositorio de GitHub. Respalda todos los archivos en `%LOCALAPPDATA%\SendMails\backups`, conserva configuracion y claves privadas, sincroniza el codigo con `main` e instala los metadatos de Git con seguimiento de `origin/main`. Los archivos de codigo que difieran se reemplazan por la version del repositorio; quedan guardados en el respaldo. No modifica las bases de datos. Si Git reconoce un repositorio existente, se detiene para no reinicializarlo. Si hay una carpeta `.git` que Git rechaza con `not a git repository`, primero la incluye en el respaldo completo y luego la mueve a `git-invalido-original` dentro de ese respaldo, sin borrarla. No modifica archivos `.git` que apunten a worktrees ni intenta resolver errores de permisos automaticamente. Despues ejecutar el actualizador para instalar dependencias. El respaldo contiene credenciales y debe mantenerse privado.

Colocar `actualizar_produccion.cmd` en la carpeta del proyecto de produccion y abrirlo con doble clic. Requiere un repositorio Git ya configurado en `main` con remoto `origin`, acceso a ese remoto y PHP disponible. Si Composer no esta en PATH, descarga una copia local en `storage/tools/composer.phar` desde getcomposer.org y verifica su SHA-256 oficial antes de ejecutarla. No requiere instalar Composer globalmente; la primera descarga necesita acceso a Internet. Usa PHP de `C:\xampp\php` si existe, o el disponible en PATH.

El actualizador comprueba cambios locales, consulta el remoto y solo permite avanzar sin merges ni sobrescrituras forzadas. Conserva la clave de facturas de versiones antiguas en el archivo privado antes de actualizar, y ejecuta `composer install --no-dev`. Si la clave ya no esta en el codigo ni configurada localmente, hay que copiar `storage/invoice_crypto.key` desde el respaldo privado. La ventana permanece abierta mostrando el resultado. Las migraciones siguen ejecutandose mediante el mecanismo habitual de la aplicacion al acceder a ella.

## Tarea programada en Windows

En produccion, desde PowerShell:

```powershell
cd C:\xampp\htdocs\SendMails
powershell -ExecutionPolicy Bypass -File .\install_scheduled_task.ps1 -IntervalMinutes 15 -Limit 1000
```

La tarea ejecuta `run_worker.ps1`, que llama a `worker.php`, escribe logs en `storage\logs` y usa un bloqueo para evitar dos corridas simultaneas.

Para ejecutar una corrida manual:

```powershell
powershell -ExecutionPolicy Bypass -File .\run_worker.ps1 -Limit 1000
```
"# SendMails" 
