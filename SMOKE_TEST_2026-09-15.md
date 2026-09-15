# Smoke test de SendMails — 15/09/2026

Resultado final: **58/58 comprobaciones del recorrido ampliado aprobadas**, además de todas las pruebas de integración y los cuatro recorridos de navegador existentes. Sin errores JavaScript, respuestas HTTP 500 ni warnings/errores fatales de PHP en el recorrido final.

Se probó la versión de desarrollo basada en `3517143`, con las correcciones incluidas en este cambio. No se ejecutó la aplicación contra producción, ni se enviaron correos o mensajes de WhatsApp.

## Entorno y protección de datos

- Windows, PHP 8.2.12, PDO_SQLSRV con ODBC 17, SQL Server Express 16.0.1200.5, Node 24.14.1 y Microsoft Edge mediante Playwright.
- Base y almacenamiento temporales independientes; el ejecutor no lee la configuración privada de la instalación habitual. Se verificó su eliminación al finalizar.
- Clientes, contraseñas, teléfonos, configuraciones y tokens ficticios. PHP ejecutó las pruebas con las funciones de transporte usadas por correo/WhatsApp deshabilitadas. Los botones de envío y sincronización externos se rechazaron en el servidor de pruebas.
- Worker, respuesta de Meta y tareas de Windows simulados. Ninguna tarea programada real se instaló, inició o detuvo.

## Cobertura ejecutada

| Área | Verificaciones | Resultado |
|---|---|---|
| Acceso | Login válido e inválido, recuperación con token local, token usado, contraseña temporal, cierre de sesión | OK |
| Permisos | Usuario y administrador, pantallas restringidas, CSRF, acceso anónimo, sucursal no autorizada | OK |
| Pantallas | Inicio, clientes, plantillas de campañas/facturas/WhatsApp, preparación, gestión, seguimiento, colas, registros, usuarios, sucursales y configuración | OK |
| Campañas y facturas | 4.000 destinatarios, filtros, paginación, selección entre páginas, borradores compartidos, revisión y confirmación repetida | OK |
| Consistencia | Snapshots, conflictos concurrentes, rollback entre canales, exclusiones, modo test de facturas, migraciones repetibles | OK |
| Adjuntos | Imagen, PDF y texto, archivos locales y guardados, descarga, límites, versión y aislamiento entre sucursales | OK |
| Suscripciones y WhatsApp | Baja de email repetida, eventos firmados ficticios, rechazo de firma inválida, bajas WhatsApp repetidas, sincronización simulada y estados fuera de orden | OK |
| Servicio | Indicador, modal, detener/iniciar por sucursal, errores e inactividad; bloqueo, alternancia e intervalos del worker simulado | OK |
| Purga | Más de 90 días, permisos, estados protegidos, vista previa, confirmación, rollback, reintentos, timeout y comprobantes | OK |
| Despliegue | CMD real sobre instalación ficticia, respaldo, fast-forward, configuración privada, espera del worker y recuperación ante errores | OK |
| Presentación | Escritorio, móviles de 390 y 320 píxeles, estilos cargados, ausencia de desborde general y revisión de capturas | OK |

La purga se probó con 14.000 destinatarios, 14.000 registros asociados y 50.000 registros ajenos que debían conservarse. También se forzó una demora SQL para verificar la cancelación y reversión. La paginación se comprobó con nivel de compatibilidad SQL 100 en la base temporal.

Verificación estática adicional: **97 archivos PHP, 11 JS/CJS y 6 PowerShell** sin errores de sintaxis.

## Correcciones resultantes

1. **WhatsApp:** el callback de estados fallaba con `SQLSTATE[07002]` al ejecutar una consulta con parámetros nombrados repetidos. Se corrigió ese patrón en eventos, bajas y sincronización de plantillas. Las pruebas verifican inserción, actualización, duplicados, Unicode, contenido largo y conservación de mapeos locales.
2. **Clientes en pantallas estrechas:** se reemplazó el ancho mínimo fijo del buscador por un campo flexible que cabe en el contenedor.
3. **Infraestructura de prueba:** se corrigieron la espera de navegación, la entrega de archivos estáticos del router de smoke y un error del bloqueo de service workers de Playwright dentro de vistas previas con sandbox. No se relajó el sandbox de la aplicación.

## Repetir la prueba

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/run.ps1 -Smoke
```

Requiere las herramientas indicadas y permiso para crear una base temporal local. `SENDMAILS_PLAYWRIGHT` permite indicar la ruta del paquete; `SENDMAILS_ARTIFACT_DIR` selecciona dónde guardar `smoke-results.json` y capturas. Más detalles en [MEJORAS_Y_DESPLIEGUE.md](MEJORAS_Y_DESPLIEGUE.md).

## Límites

Este resultado valida los recorridos descritos en el entorno aislado. No comprueba entrega real de SMTP/Meta, credenciales, conectividad a bases de sucursales, rendimiento del servidor de producción ni permisos efectivos de la cuenta SYSTEM. Tampoco reproduce PHP 8.5/ODBC 18 de producción ni equivale a una auditoría completa de seguridad o de todos los casos posibles.
