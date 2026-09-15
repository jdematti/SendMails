# Revisión de usabilidad — 15/09/2026

Se recorrió SendMails desde la perspectiva de una persona nueva: identificar por dónde empezar, reconocer la acción siguiente, entender los estados y encontrar la información sin recorrer pantallas extensas. La referencia inicial fue `29be4fb`; este cambio incorpora las mejoras verificadas.

## Hallazgos y cambios

| Obstáculo observado | Cambio realizado |
|---|---|
| Inicio presentaba siete indicadores con el mismo peso, sin explicar las tareas principales. | Dos entradas claras: Crear campaña y Preparar facturas, una guía breve de tres pasos, cuatro indicadores principales y contadores secundarios en una línea. |
| Preparación mostraba filtros que no siempre se usan y una selección múltiple de planes poco evidente. | Canal y búsqueda/vencimiento primero; Más filtros con contador, planes con casillas y restauración de filtros al abrir un borrador. |
| Borradores y botones repetidos ocupaban buena parte del encabezado del compositor. | Selector de borradores desplegable, pasos en una fila y acciones de continuación al pie. Direcciones manuales en una sección explícita. |
| Seguimiento mezclaba el resumen con cincuenta destinatarios y múltiples botones por envío. | Vista inicial de envíos; Ver detalle abre destinatarios y controles. Consulta general mediante Ver todos los destinatarios; administración en Más opciones. |
| El refresco podía mover la lectura o reemplazar el control con foco. | Conserva desplazamiento de las tablas y espera mientras se usa un control o un detalle abierto. |
| Los editores desplegaban opciones secundarias junto al contenido principal. | Agrupación de personalización, banda/botón/pie, remitente, redes, HTML y envío de prueba. Adjuntos y modo de prueba conservan acceso claro. |
| Una plantilla podía mostrar su vista previa en blanco durante la carga inicial. | La actualización espera que termine la carga inicial del iframe. Se comprueba que la vista previa renderice y responda al editar el título. |
| Usuarios tenía una tabla estrecha, desborde en móvil y configuración de enlaces con demasiado protagonismo. | Lista a todo el ancho y configuración desplegable. Se corrigió también el espacio vacío del encabezado móvil en páginas cortas. |
| Iconos de cuenta y tablas anchas resultaban poco evidentes. | Menú de cuenta con texto, cierre con Escape, foco visible, acceso por teclado al contenido y avisos de desplazamiento en tablas. |

## Comparación con los mismos datos

Mediciones locales con 4.000 clientes/facturas ficticios. Escritorio: 1366 × 900; móvil: 390 × 900. Los campos se cuentan fuera de secciones cerradas; la altura corresponde al documento inicial. Abrir opciones o detalles aumenta esa altura.

| Vista / medida | Antes | Después |
|---|---:|---:|
| Crear campaña, escritorio: inicio de la tabla | 654 px | 467 px |
| Preparar facturas, móvil: campos inicialmente desplegados | 9 | 2 |
| Preparar facturas, móvil: altura inicial | 1836 px | 953 px |
| Seguimiento, móvil: altura inicial | 4921 px | 1013 px |
| Editor de campañas, escritorio: controles desplegados | 26 | 11 |
| Editor de facturas, escritorio: controles desplegados | 17 | 7 |

La reducción de Seguimiento se debe a separar resumen y destinatarios. Los destinatarios siguen accesibles y paginados; esa consulta ya no se ejecuta al abrir el resumen. Las tablas conservan su contenido mediante desplazamiento propio.

## Verificación

- **58/58 comprobaciones** del smoke funcional, más integración, migración, paginación, concurrencia, snapshots, adjuntos, purga y worker/actualizador simulados.
- **50 vistas:** 25 páginas en escritorio y móvil, con capturas, estilos cargados, sin desborde horizontal general ni encabezados móviles con espacio sobrante.
- **11 recorridos de uso:** inicio/teclado, cuenta, planes y selección, borradores con emails manuales, filtros de facturas, detalle y paginación, pausa/continuación/cancelación de detener, refresco sin saltos, vista previa editable, validación de campos desplegables y controles móviles de 390/320 px.
- Sintaxis: **98 PHP, 14 JS/CJS y 7 PowerShell** sin errores.
- Sin errores JavaScript ni avisos PHP en el recorrido final. El smoke funcional no registró HTTP 500.

Los últimos ajustes de cuenta y encabezado se verificaron nuevamente con las 50 vistas y los 11 recorridos. El entorno usó PHP 8.2, SQL Server Express local y Edge. Las bases temporales y sus archivos se eliminaron al finalizar.

## Alcance

No se enviaron correos ni WhatsApps. Se usaron clientes ficticios y transportes bloqueados; no se ejecutaron tareas de Windows ni operaciones sobre producción. Las reglas de confirmación, permisos, snapshots y purga se mantienen; no hay cambios de esquema.

Esta es una revisión funcional y heurística de usabilidad. La rapidez de aprendizaje de una persona nueva requiere observarla usando el sistema; no se midió ese tiempo. Tampoco se validaron proveedores reales ni PHP 8.5 de producción. En móvil, las tablas anchas siguen usando desplazamiento horizontal señalizado.

Ejecución reproducible: `powershell -NoProfile -ExecutionPolicy Bypass -File tests/run.ps1 -Smoke`. Ver [MEJORAS_Y_DESPLIEGUE.md](MEJORAS_Y_DESPLIEGUE.md) para requisitos y ubicación de capturas.
