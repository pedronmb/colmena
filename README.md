# Colmena

Aplicación web en **PHP** con **SQLite** para gestionar equipos, personas (tarjetas sin cuenta de acceso), temas con **urgencia e importancia en escala numérica 1–10**, dashboards tipo matriz Eisenhower con **perfiles en pentágono** (cinco ejes 0–10 y gráfico radar en la pestaña del mismo nombre), alertas con fecha de cumplimiento, vista **DevOps** (integración con Azure DevOps), **InvGate** (tickets sincronizados por persona con catálogos de categorías, tipos y estados), **bloc personal** (notas y archivos por usuario) y administración de usuarios.

Repositorio: [github.com/pedronmb/colmena](https://github.com/pedronmb/colmena)

---

## Requisitos

| Requisito | Notas |
|-----------|--------|
| PHP | 8.0 o superior (recomendado 8.1+) |
| Extensiones | `pdo_sqlite`, `json`, `session`, `curl` (InvGate y Azure DevOps) |
| Servidor web | Apache, nginx u otro con soporte PHP (en local suele usarse **XAMPP** u homólogo) |
| Base de datos | SQLite (archivo `database/app.sqlite`; no hace falta servidor MySQL) |
| Front-end | HTML, CSS y JavaScript **vanilla** (sin npm ni bundler obligatorio) |

---

## Instalación rápida (entorno nuevo)

1. **Copiar el proyecto** en la carpeta del servidor (por ejemplo `htdocs/colmena` en XAMPP).

2. **Crear la base de datos y datos de demostración** desde la **raíz del proyecto**:

   ```bash
   php database/init.php
   ```

   En Windows, si `php` no está en el PATH:

   ```powershell
   c:\xampp\php\php.exe database\init.php
   ```

   - Crea `database/app.sqlite` aplicando `database/schema.sql`.
   - Inserta un usuario demo, un equipo, miembros y tarjetas de ejemplo.
   - **Advertencia:** si `app.sqlite` ya existía, **se borra** y se vuelve a crear desde cero.

3. **Copiar la configuración** (si aún no existe `config/config.php`):

   ```bash
   cp config/config.php.default config/config.php
   ```

   En Windows:

   ```powershell
   copy config\config.php.default config\config.php
   ```

   Ajustá `db.path` y, si vas a usar integraciones, los bloques `azure_devops` e `invgate` (ver sección **Configuración**).

4. **Configurar el virtual host o la ruta** para que la **raíz pública** sea el directorio `public/` (recomendado).

   Si no puedes apuntar el document root a `public/`, coloca el proyecto en una subcarpeta y accede a `http://localhost/colmena/public/` (ajusta la ruta según tu entorno).

5. **Abrir la aplicación** en el navegador y entrar con la cuenta demo:

   | Campo | Valor |
   |--------|--------|
   | Email | `demo@local.test` |
   | Contraseña | `demo123` |

6. **Permisos (Linux/macOS):** el usuario del servidor web debe poder **leer y escribir** `database/app.sqlite` (y la carpeta `database/` si hace falta crear el archivo).

---

## Configuración

- **Ruta de la base de datos:** `config/config.php` → clave `db.path` (por defecto apunta a `database/app.sqlite`).

- **Equipo en contexto:** la aplicación usa el **espacio de trabajo personal** del usuario (`PersonalTeamBootstrap`) para `team_id` en formularios y API cuando corresponde.

- **Azure DevOps (vista DevOps):** en `config/config.php`, bloque `azure_devops`:

  ```php
  'azure_devops' => [
      'organization' => 'mi-org',
      'project' => 'mi-proyecto',
      'pat' => '', // Personal Access Token (Work items: Read)
      'max_items' => 200,
      'wiql' => null, // opcional: consulta WIQL personalizada
  ],
  ```

  No subas `config.php` al repositorio si incluye el PAT.

- **InvGate (sincronización de tickets):** en `config/config.php`, bloque `invgate`:

  ```php
  'invgate' => [
      'server_url' => 'https://helpdesk.empresa.com', // sin barra final
      'user' => 'usuario_api',
      'password' => 'contraseña',
      'limit' => 100,
  ],
  ```

  Copiá los valores desde `config/config.php.default` si aún no tenés el bloque. No subas `config.php` al repositorio si incluye credenciales.

- **Ollama (recomendaciones IA de InvGate):** en `config/config.php`, bloque `ollama`:

  ```php
  'ollama' => [
      'base_url' => 'http://localhost:11434',
      'model' => 'llama3.1',
      'timeout' => 120,
  ],
  ```

  Se utiliza para generar resumen y recomendación de próximos pasos por ticket abierto.

  En cada ficha de persona (**Editar fichas**) podés cargar el **ID InvGate** (`team_people.invgate_id`).

  #### Scripts de sincronización (CLI)

  Hay **cuatro scripts** independientes. El orden recomendado es:

  1. **Catálogo** — nombres de categorías, tipos y estados (`invgate_categories`, `invgate_types`, `invgate_statuses`).
  2. **Tickets** — incidentes abiertos por agente (`invgate_tickets`).
  3. **Comentarios** — respuestas de cada ticket (`invgate_ticket_comments`).
  4. **Recomendaciones IA** — resumen + recomendación por ticket abierto (`invgate_ticket_recommendations`).

  | Script | Endpoint InvGate | Tabla destino |
  |--------|------------------|---------------|
  | `database/sync_invgate_catalog.php` | `/categories`, `/incident.attributes.type`, `/incident.attributes.status` | `invgate_categories`, `invgate_types`, `invgate_statuses` |
  | `database/sync_invgate_tickets.php` | `/incidents.by.agent` | `invgate_tickets` |
  | `database/sync_invgate_comments.php` | `/incident.comment` | `invgate_ticket_comments` |
  | `database/generate_invgate_recommendations.php` | `http://localhost:11434/generate` | `invgate_ticket_recommendations` |

  El sync de **catálogo** hace upsert (inserta nuevos y actualiza nombres si cambiaron en InvGate; no borra registros locales). Si hay IDs en tickets que no aparecieron en el listado completo, intenta traerlos individualmente con `?id=`.

  El sync de **tickets** guarda por incidente: `title`, `description`, `category_id`, `source_id`, `status_id`, `type_id`, `priority`, fechas y solicitante (`user_id`). Solo inserta/actualiza los que devuelve la API (tickets abiertos asignados al agente).

  El sync de **comentarios** solo **inserta** comentarios nuevos (clave local: ticket + `msg_num`).

  **Ejecutar sincronización manual:**

  ```bash
  php database/sync_invgate_catalog.php
  php database/sync_invgate_tickets.php
  php database/sync_invgate_comments.php
  php database/generate_invgate_recommendations.php
  ```

  En Windows (XAMPP):

  ```powershell
  c:\xampp\php\php.exe database\sync_invgate_catalog.php
  c:\xampp\php\php.exe database\sync_invgate_tickets.php
  c:\xampp\php\php.exe database\sync_invgate_comments.php
  c:\xampp\php\php.exe database\generate_invgate_recommendations.php
  ```

  **Tarea programada (Windows):** Programador de tareas → crear tarea básica → acción «Iniciar un programa»:

  - Programa: `c:\xampp\php\php.exe`
  - Argumentos: `database\sync_invgate_catalog.php`
  - Iniciar en: carpeta raíz del proyecto (donde está `database/`)

  Si querés programar tickets/comentarios/recomendaciones, repetí la tarea cambiando los argumentos a:
  - `database\sync_invgate_tickets.php`
  - `database\sync_invgate_comments.php`
  - `database\generate_invgate_recommendations.php`

  En Linux/macOS podés usar `cron`, por ejemplo cada hora:

  ```bash
  0 * * * * cd /ruta/a/colmena && php database/sync_invgate_catalog.php && php database/sync_invgate_tickets.php && php database/sync_invgate_comments.php && php database/generate_invgate_recommendations.php
  ```

  Los tickets cerrados en InvGate dejan de actualizarse pero **permanecen** en la base local.

  #### Interfaz web

  La solapa **InvGate** (`public/invgate.php`) tiene tres pestañas:

  - **Tickets** — listado agrupado por persona (incluye fichas con ID InvGate aunque no tengan tickets abiertos). Las columnas **Estado**, **Tipo** y **Categoría** muestran el nombre del catálogo sincronizado; si falta el catálogo, se muestra el ID numérico. Al hacer clic en un ticket se abre el detalle con descripción y comentarios sincronizados.
  - **Estadísticas** — métricas por persona y resumen del equipo (`GET api/invgate-stats.php?team_id=&period=7|30|all&stale_days=3`).
  - **Recomendaciones IA** — lista plana de tickets abiertos con resumen y recomendación guardados en base local (`GET api/invgate-recommendations.php` y `GET api/invgate-recommendation.php`).

  **Métricas disponibles (por persona):**

  | Bloque | Métrica | Definición |
  |--------|---------|------------|
  | Carga | Abiertos | Tickets con estado no final (IDs 5–8) |
  | Carga | Carga ponderada | Suma de pesos por prioridad (P1=5, P2=3, P3=2, resto=1) |
  | Carga | % P1/P2 | Porcentaje de abiertos con prioridad 1 o 2 |
  | Aging | Edad promedio / mediana / máxima | Días desde `created_at` en abiertos; máxima incluye `#invgate_incident_id` del caso más antiguo |
  | Aging | Stale | Abiertos sin interacción hace N días (`last_update` o último comentario) |
  | Aging | P1/P2 envejecidos | Abiertos alta prioridad con más de N días |
  | Distribución | Por estado / tipo / categoría | Conteo y % sobre abiertos |
  | Histórico | Resueltos (período / 7d / 30d) | Tickets finales con `last_update` en la ventana |
  | Histórico | Tiempo de resolución | `last_update − created_at` (promedio, p50, p90) en finales del período |
  | Histórico | Throughput semanal | Cierres por semana ISO (últimas 8 semanas) |
  | Comentarios | Actividad | Comentarios por ticket, total del agente, idle, tasa con solución |

  **Métricas disponibles (resumen del equipo):**

  | Bloque | Métrica | Definición |
  |--------|---------|------------|
  | Aging | Ticket más antiguo | Máxima edad del backlog asignado al equipo; indica persona y `#ID` |
  | Balanceo | Ratio de desequilibrio | Carga ponderada máxima ÷ promedio por persona |
  | Balanceo | Concentración | % de la carga total en la persona más cargada |
  | Balanceo | Capacidad relativa | Personas con carga, abiertos y stale por debajo del promedio del equipo |
  | Distribución | Por categoría / tipo (equipo) | Agregado de todos los abiertos asignados a personas del equipo |
  | Huérfanos | Sin asignar | Tickets abiertos con `person_id` nulo (globales, mismo criterio que pestaña Tickets) |

  **Limitaciones:** el sync de tickets solo trae incidentes **abiertos**; los cierres históricos solo aparecen si el ticket estuvo abierto al sincronizar y luego se actualizó el estado local. Las métricas de comentarios requieren `sync_invgate_comments.php` y que `author_id` coincida con el ID InvGate de la persona.

---

## Estructura del proyecto (resumen)

```
colmena/
├── bootstrap_web.php      # Bootstrap web: autoload, sesión, config
├── config/
│   ├── config.php         # Configuración local (no versionada)
│   └── config.php.default # Plantilla de configuración
├── database/
│   ├── schema.sql         # Esquema completo (instalaciones nuevas)
│   ├── init.php           # Crea DB desde cero + datos demo
│   ├── migrate_*.php      # Migraciones para bases ya existentes
│   ├── sync_invgate_catalog.php   # Sync CLI catálogo InvGate
│   ├── sync_invgate_tickets.php   # Sync CLI tickets → invgate_tickets
│   ├── sync_invgate_comments.php  # Sync CLI comentarios → invgate_ticket_comments
│   └── generate_invgate_recommendations.php # Recomendaciones IA con Ollama
├── public/                # Document root recomendado
│   ├── index.php          # Temas
│   ├── dashboard.php      # Matriz, lista, foco, calendario y pestaña Perfiles (pentágono)
│   ├── pentagon-dashboard.php  # Redirección a dashboard.php?panel=pentagon (compatibilidad)
│   ├── devops.php         # DevOps (Azure DevOps)
│   ├── invgate.php        # InvGate (tickets agrupados por persona)
│   ├── alerts.php         # Alertas
│   ├── people.php         # Tablero de personas y temas
│   ├── people-edit.php    # CRUD de fichas + perfil pentágono
│   ├── scratchpad.php     # Bloc personal
│   ├── users.php          # Usuarios (admin)
│   ├── login.php
│   ├── api/               # Endpoints JSON
│   ├── includes/          # Parciales PHP (nav, pentagon-profile-fields, etc.)
│   └── assets/            # CSS, JS, favicon
└── src/
    ├── Bootstrap.php
    ├── Database/
    ├── Models/
    ├── Repositories/      # Incl. InvgateTicketRepository, InvgateCatalogRepository, …
    ├── Services/          # Incl. InvgateClient, Invgate*SyncService, AzureDevOpsClient, …
    └── Support/           # Incl. BirthdayNormalizer, PentagonAxisNormalizer, …
```

---

## Funcionalidades principales

- **Sesión:** login por email/contraseña; sesión PHP.
- **Temas:** título, descripción, **urgencia** y **importancia** cada una en escala **entera 1–10** (por defecto 5), asignación a una tarjeta de persona, estados y fechas. La matriz Eisenhower usa la mitad del rango (entre 5 y 6) como frontera entre cuadrantes.
- **Personas:** tarjetas de equipo (no son usuarios de login); tablero en **Personas** y edición detallada en **Editar fichas**.
- **Perfil (pentágono):** cinco ejes opcionales en `team_people` (escala **0–10**): visión estratégica, ejecución técnica, comunicación, análisis de datos/riesgos, innovación/creatividad. Se editan en **Editar fichas**; el radar por persona está en **Dashboards → pestaña Perfiles (pentágono)** (`dashboard.php?panel=pentagon`), con SVG nativo (sin npm).
- **Dashboards:** matriz urgencia × importancia, lista, «Hacer hoy», calendario de alertas y la pestaña anterior.
- **Alertas:** fecha de cumplimiento; aviso tras iniciar sesión si la fecha está vencida o en los próximos 7 días.
- **DevOps:** interfaz para enlazar trabajo con **Azure DevOps** (work items vía `azure-devops-workitems.php`; configuración en `config.php`).
- **InvGate:** solapa con pestañas **Tickets**, **Estadísticas** y **Recomendaciones IA**. Muestra estado, tipo y categoría por **nombre** (tablas lookup), detalle con descripción/comentarios y recomendaciones generadas por Ollama para tickets abiertos.
- **Bloc personal:** notas y archivos privados del usuario conectado.
- **Usuarios:** alta y gestión de cuentas (rol administrativo).
- **Tema claro/oscuro:** preferencia en el cliente (`theme.js`).

### API JSON (referencia rápida)

Los endpoints viven en `public/api/*.php` (mismo origen que la app, `credentials: same-origin`). Entre otros:

- `login.php`, `logout.php`, `me.php`
- `topics.php`, `topic.php` (**priority** e **importance** como enteros **1–10** en JSON), `people-board.php`
- `team-people.php`, `team-person.php` (personas; **PUT/POST** aceptan las claves `axis_*` del pentágono)
- `alerts.php`, `users.php`, `teams.php`
- `azure-devops-workitems.php` (GET: work items de Azure DevOps)
- `invgate-tickets.php` (GET: tickets agrupados por persona), `invgate-ticket.php` (GET: detalle + comentarios de un ticket), `invgate-stats.php` (GET: estadísticas por persona del equipo), `invgate-recommendations.php` (GET: lista plana + estado de recomendación), `invgate-recommendation.php` (GET: detalle de recomendación por ticket)
- `user-scratchpad.php`, `user-files.php`, `user-file-download.php`

---

## Actualizar una base de datos ya existente

Si ya tienes un `app.sqlite` antiguo y **no** quieres borrarlo con `init.php`, ejecuta solo los scripts que apliquen. Cada script comprueba si hace falta y puede no modificar nada si la migración ya está aplicada.

| Script | Propósito |
|--------|-----------|
| `database/migrate_team_people.php` | Tabla `team_people` si faltaba |
| `database/migrate_team_people_role.php` | Campo `role` en personas |
| `database/migrate_team_people_pentagon.php` | Campos del pentágono en `team_people` (instalaciones sin ejes previos) |
| `database/migrate_team_people_direct_team.php` | Campo `is_direct_team` en personas (equipo directo vs colaborador) |
| `database/migrate_pentagon_axes_v2.php` | Renombra columnas del pentágono v1 → v2 (`axis_autonomy_problem_solving`, `axis_impact_scope`, `axis_influence_mentorship`, `axis_business_communication`, `axis_technical_competence`) |
| `database/migrate_topic_completed_at.php` | Campo `completed_at` en temas |
| `database/migrate_topics_importance_priority.php` | Importancia y prioridad ampliada |
| `database/migrate_topics_five_levels.php` | Escala de 5 niveles en urgencia/importancia (histórico; bases nuevas no lo necesitan) |
| `database/migrate_topics_numeric_1_10.php` | Convierte `topics.priority` e `topics.importance` a **INTEGER 1–10** (instalaciones nuevas con `schema.sql` ya vienen así; ejecútalo si tu `app.sqlite` aún tiene texto de cinco niveles) |
| `database/migrate_team_alerts.php` | Tabla `team_alerts` |
| `database/migrate_personal_workspace.php` | Espacio de trabajo personal por usuario |
| `database/migrate_user_scratchpad_files.php` | Tablas/recursos de bloc y archivos personales |
| `database/migrate_team_people_invgate_id.php` | Campo `invgate_id` en personas |
| `database/migrate_invgate_tickets.php` | Tablas `invgate_tickets` e `invgate_ticket_comments` |
| `database/migrate_invgate_tickets_source_status_type.php` | Campos `source_id`, `status_id` y `type_id` en tickets InvGate |
| `database/migrate_invgate_catalog.php` | Tablas lookup: categorías, tipos y estados de InvGate |
| `database/migrate_invgate_recommendations.php` | Tabla `invgate_ticket_recommendations` para recomendaciones IA |

Ejemplo (desde la raíz del proyecto):

```bash
php database/migrate_team_people_pentagon.php
php database/migrate_pentagon_axes_v2.php   # si ya tenías columnas axis_* antiguas
```

En Windows con XAMPP, si `php` no está en el PATH:

```powershell
c:\xampp\php\php.exe database\migrate_team_people_pentagon.php
c:\xampp\php\php.exe database\migrate_pentagon_axes_v2.php
```

También podés aplicar el SQL directo: `database/migrate_pentagon_axes_v2.sql` (SQLite 3.25+).

El orden debe respetar el **historial de tu base**: si partes de una versión muy antigua, puede ser necesario ejecutar migraciones anteriores primero.

**Ejemplo — habilitar InvGate en una base existente** (después de las migraciones previas que correspondan):

```bash
php database/migrate_team_people_invgate_id.php
php database/migrate_invgate_tickets.php
php database/migrate_invgate_tickets_source_status_type.php
php database/migrate_invgate_catalog.php
```

Luego configurá `config.php` y ejecutá los sync CLI en el orden indicado arriba.

---

## Solución de problemas

- **“Base de datos no inicializada”** o página en blanco: comprobar que exista `database/app.sqlite` y que `config/config.php` apunte a la ruta correcta.
- **Error al escribir en SQLite:** permisos de carpeta/archivo en `database/`.
- **Error SQL en temas** (columnas `priority`/`importance` con tipo o restricciones antiguas): ejecutar `database/migrate_topics_numeric_1_10.php` (tras las migraciones previas de temas si tu base es muy antigua) o recrear la BD con `init.php` si puedes perder datos.
- **Clase `App\Bootstrap` ya declarada:** asegurarse de usar la versión actual de `bootstrap_web.php` (usa `require_once` y caché de configuración).
- **Sesión / login:** comprobar que las cookies funcionen (mismo dominio, HTTPS en producción si aplica).
- **InvGate — columnas Estado/Tipo/Categoría muestran IDs o `—`:** ejecutar `database/sync_invgate_catalog.php` (requiere credenciales en `config.php`). Si la tabla no existe, correr antes `database/migrate_invgate_catalog.php`.
- **InvGate — sin tickets:** verificar que las personas tengan `invgate_id` en **Editar fichas** y ejecutar `database/sync_invgate_tickets.php`.
- **InvGate — sin comentarios en el detalle:** ejecutar `database/sync_invgate_comments.php` (requiere tickets ya sincronizados).
- **InvGate — error de autenticación o red:** revisar `server_url` (con `https://`, sin barra final), usuario/contraseña API y que PHP tenga la extensión `curl` habilitada.

---

## Git y GitHub

1. Instala **Git para Windows**: [git-scm.com/download/win](https://git-scm.com/download/win) si aún no lo tienes.
2. Remoto de este proyecto: `https://github.com/pedronmb/colmena.git`

   ```powershell
   cd ruta\a\colmena
   git remote add origin https://github.com/pedronmb/colmena.git
   git push -u origin main
   ```

   La primera vez Git puede pedir autenticación: en GitHub suele usarse un **personal access token** en lugar de la contraseña.

El archivo `.gitignore` evita subir `database/*.sqlite`.

---

## Licencia

Este proyecto se publica bajo **GNU General Public License v3.0** — ver el archivo [`LICENSE`](LICENSE) en la raíz del repositorio.

---

## Desarrollo

- Estilo PHP: `declare(strict_types=1);`, tipado donde procede.
- API en `public/api/*.php`: JSON, `Content-Type: application/json; charset=utf-8`.
- Sin framework obligatorio; autoload PSR-4 simple para `App\*` bajo `src/`.
- Gráficos del pentágono: `public/assets/js/pentagon-radar-svg.js`; la carga de tarjetas usa `pentagon-dashboard.js`, invocada desde la pestaña en `dashboard.js`.
- InvGate (UI): `public/assets/js/invgate-common.js`, `invgate.js`, `invgate-stats.js`, `invgate-recommendations.js`; consume `api/invgate-tickets.php`, `api/invgate-ticket.php`, `api/invgate-stats.php`, `api/invgate-recommendations.php` y `api/invgate-recommendation.php`.
- InvGate (stats): `src/Services/InvgateStatsService.php`, `src/Repositories/InvgateStatsRepository.php`.
- Sync InvGate (CLI): `src/Services/InvgateClient.php`, `InvgateCatalogSyncService`, `InvgateTicketSyncService`, `InvgateCommentSyncService`.

Para cambios en el esquema, actualiza `database/schema.sql` y, si aplica, añade o ajusta un `migrate_*.php` para quien ya tenga datos en producción.
