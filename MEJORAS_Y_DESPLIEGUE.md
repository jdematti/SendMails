# Agilidad de SendMails

Alcance acordado: 3–4 sucursales, 3–4 usuarios, aproximadamente 4.000 clientes por sucursal y lotes de hasta 4.000 destinatarios. Se conserva la identidad visual. Los borradores se comparten entre los usuarios de la misma sucursal.

## Uso

- Crear campaña y Preparar facturas comparten tres pasos: destinatarios, mensaje y revisión/programación.
- La búsqueda muestra 50 filas por página. Se distingue seleccionar la página de seleccionar todos los resultados. Las selecciones se conservan al paginar; los filtros se recuerdan por usuario y sucursal. Cambiar el vencimiento vacía la selección de facturas y lo informa.
- La revisión muestra cantidades válidas, inválidas, excluidas y duplicadas por canal, una muestra del email, adjuntos y la plantilla WhatsApp. Se comprueba la configuración antes de confirmar.
- Se puede crear una campaña exclusivamente con emails manuales.
- Guardar borrador permite continuar desde otra cuenta de la misma sucursal. Las revisiones impiden sobrescribir silenciosamente una edición concurrente.
- Las plantillas tienen borrador y publicación separados. Sus archivos se conservan antes de una prueba. Un conflicto con la versión publicada permite publicar como plantilla nueva.
- El inicio y el seguimiento incluyen campañas, facturas, email y WhatsApp. Cada lote permite consultar progreso, destinatarios, errores, pausa y continuación. Se mantienen las páginas administrativas anteriores para sus operaciones existentes.
- Las tablas tienen un área de desplazamiento y encabezados fijos. El diseño conserva el menú, colores y controles habituales.
- Los accesos «Ver campañas» y «Ver facturas» abren el seguimiento filtrado. Las páginas administrativas anteriores siguen disponibles desde el seguimiento.

## Control del proceso y purga

- El indicador junto al cambio de sucursal abre un modal con el estado y la última actividad. Es verde cuando el proceso de esa sucursal está operativo; rojo cuando está detenido, por iniciar, sin actividad reciente o con un error. El color siempre se acompaña de texto.
- Detener/Iniciar afecta solamente a la sucursal seleccionada. Los usuarios con acceso a esa sucursal pueden utilizarlo. Detener deja finalizar la operación en curso y conserva los pendientes. Iniciar permite retomarlos en la próxima ejecución automática, normalmente dentro de un minuto.
- El control se guarda en `storage/worker-control-<sucursal>.json`, con bloqueo y revisión para detectar cambios concurrentes. El worker consulta esa señal entre operaciones y continúa atendiendo las demás sucursales. La tarea de Windows permanece programada; la interfaz no finaliza procesos del servidor ni necesita privilegios SYSTEM.
- Si la tarea de Windows no funciona, el botón permanece rojo: Iniciar no repara una tarea deshabilitada o una conexión caída. El modal lo informa. Los errores de un canal no se borran por un envío exitoso del otro canal.
- `Configuración > Purgar historial` está disponible solo para administradores. Permite todas las sucursales o una específica, tipo, canal y fecha de corte, con un mínimo obligatorio de 90 días.
- Solo se incluyen lotes completados/detenidos sin destinatarios pendientes o en curso, ni actividad reciente en sus destinatarios o registros. Se eliminan el lote, sus destinatarios, copia del mensaje y registros/eventos asociados. Se conservan bases de origen, plantillas, borradores, bajas y exclusiones.
- La vista previa identifica hasta 100 lotes por operación, sus sucursales y cantidades. Cambiar filtros requiere calcularla nuevamente. La confirmación revalida y bloquea esos lotes; cualquier cambio o error revierte toda la operación. Repetir una confirmación no duplica la purga.
- Se conserva un comprobante de fecha, administrador, filtros y cantidades en la base central. La purga no reduce automáticamente el tamaño físico de los archivos de SQL Server. Los registros de pruebas sin lote no se incluyen en esta operación.

## Envíos y datos

- Confirmar crea el lote; el navegador no procesa mensajes. El worker consulta la cola cada minuto mediante la tarea de Windows y puede seguir sin una sesión iniciada.
- Alterna sucursales y canales, respetando sus intervalos y las fechas programadas. Persiste la espera entre ejecuciones y evita procesos simultáneos mediante un bloqueo.
- Una copia del mensaje se guarda por lote, incluidos adjuntos y configuración de plantilla. Las ediciones posteriores no alteran sus pendientes. Las bajas se vuelven a verificar al procesar campañas.
- La creación por email + WhatsApp comparte una transacción central. Si falla el segundo canal, se revierte el primero. Repetir una confirmación no duplica el lote.
- El modo test de facturas identifica el envío como prueba y no cambia `FacturasTel.mail_enviado`. Ese modo pertenece al email; WhatsApp conserva sus destinatarios reales, indicado en la revisión.
- Si el proveedor aceptó un email pero falla su registro, se deja para revisión. Los envíos interrumpidos tampoco se reintentan automáticamente: su resultado puede ser incierto.
- Las inserciones se agrupan por debajo de 2.100 parámetros de SQL Server. La selección del siguiente trabajo consulta metadatos, sin cargar mensajes y adjuntos innecesariamente.
- Las migraciones se ejecutan por consola. Las peticiones normales solo comprueban la versión una vez, sin ejecutar creación de tablas, semillas o cambios de estructura.

## Actualizar producción

1. Coordinar una ventana sin crear envíos. Conservar un respaldo de la base central conforme al procedimiento habitual del servidor. El respaldo automático siguiente es de archivos, no de SQL Server.
2. Para esta primera actualización, copiar la nueva versión de `actualizar_produccion.cmd` a `C:\xampp\htdocs\SendMails`. La versión anterior no incluye las migraciones ni la configuración nueva de la tarea.
3. Abrir el archivo con doble clic y aceptar la elevación de Windows. Se necesitan permisos para administrar la tarea programada.
4. El script consulta Git, activa mantenimiento, deshabilita la tarea existente y comprueba que ningún envío siga en curso. Si hay uno activo, no lo interrumpe: hay que esperar y ejecutar nuevamente el actualizador.
5. Respalda los archivos, incluida la configuración privada, fuera del sitio web. Conserva en ese respaldo los tres CMD que todavía no estuvieran rastreados por Git antes de instalar las versiones del repositorio.
6. Actualiza por fast-forward, instala dependencias, ejecuta `php migrate.php` y configura `SendMails Worker` como SYSTEM, cada minuto. Después retira mantenimiento.
7. Abrir el seguimiento y comprobar que aparezca actividad reciente del worker. La primera ejecución puede esperar hasta el próximo minuto.

La migración fija el contenido actual de los lotes en cola, en proceso o pausados. No reconstruye el contenido histórico de mensajes ya terminados. Sus cambios de base son transaccionales; no envía mensajes ni modifica las facturas operativas.

Si algo falla, mantenimiento y la tarea permanecen pausados. Corregir el error indicado y volver a ejecutar el mismo CMD. No retirar manualmente mantenimiento para saltear una migración fallida. La carpeta del respaldo se muestra en la consola y contiene información privada.

La actualización no necesita nuevas dependencias de producción. La paginación de clientes, facturas y seguimiento utiliza ROW_NUMBER para evitar la dependencia de OFFSET/FETCH en los servidores de origen. Se conservan los módulos PHP que ya utiliza el proyecto.

## Verificación reproducible

Las pruebas no leen la configuración de producción. Crean una base con nombre `SendMails_Agility_Test_<identificador>` en la instancia local SQLEXPRESS, con autenticación Windows, y usan almacenamiento temporal independiente. Se necesita permiso para crear esa base local.

Ejecutar desde PowerShell:

    powershell -NoProfile -ExecutionPolicy Bypass -File tests/run.ps1

Para incluir navegador, disponer de Node, Playwright y Microsoft Edge:

    powershell -NoProfile -ExecutionPolicy Bypass -File tests/run.ps1 -Browser

Si Playwright no está en los módulos habituales de Node, `SENDMAILS_PLAYWRIGHT` acepta la ruta del paquete. Las capturas se guardan en el directorio temporal `sendmails-ui-artifacts`, o en `SENDMAILS_ARTIFACT_DIR`.

Cobertura: migración nueva y actualización, idempotencia, 4.000 destinatarios, filtros y paginación, confirmación repetida, rollback entre canales, aislamiento de sucursales, conflictos de borradores, snapshots, adjuntos, modo test, seguimiento, bloqueo y alternancia del worker, intervalos persistidos, respaldo y recuperación de scripts no rastreados. Los transportes y las tareas programadas están simulados; no se envía email ni WhatsApp externo.

La prueba local de revisión y confirmación de 4.000 emails completó su recorrido en aproximadamente 2,5 segundos. Es una medición de la base temporal, no una promesa para producción ni una comparación con el tiempo anterior. Falta medir el servidor real y comprobar allí SMTP/Meta y la ejecución bajo SYSTEM.
