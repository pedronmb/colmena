# Colmena

Aplicación web en **PHP** con **SQLite** para gestionar equipos, personas (tarjetas sin cuenta de acceso), temas con **urgencia e importancia en escala numérica 1–10**, dashboards tipo matriz Eisenhower con **perfiles en pentágono** (cinco ejes 0–10 y gráfico radar en la pestaña del mismo nombre), alertas con fecha de cumplimiento, vista **DevOps** (integración con Azure DevOps), **bloc personal** (notas y archivos por usuario) y administración de usuarios.

Repositorio: [github.com/pedronmb/colmena](https://github.com/pedronmb/colmena)

---

## Requisitos

| Requisito | Notas |
|-----------|--------|
| PHP | 8.0 o superior (recomendado 8.1+) |
| Extensiones | `pdo_sqlite`, `json`, `session` |
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

3. **Configurar el virtual host o la ruta** para que la **raíz pública** sea el directorio `public/` (recomendado).

   Si no puedes apuntar el document root a `public/`, coloca el proyecto en una subcarpeta y accede a `http://localhost/colmena/public/` (ajusta la ruta según tu entorno).

4. **Abrir la aplicación** en el navegador y entrar con la cuenta demo:

   | Campo | Valor |
   |--------|--------|
   | Email | `demo@local.test` |
   | Contraseña | `demo123` |

5. **Permisos (Linux/macOS):** el usuario del servidor web debe poder **leer y escribir** `database/app.sqlite` (y la carpeta `database/` si hace falta crear el archivo).

---

## Configuración

- **Ruta de la base de datos:** `config/config.php` → clave `db.path` (por defecto apunta a `database/app.sqlite`).

- **Equipo en contexto:** la aplicación usa el **espacio de trabajo personal** del usuario (`PersonalTeamBootstrap`) para `team_id` en formularios y API cuando corresponde.

---

## Estructura del proyecto (resumen)

```
colmena/
├── bootstrap_web.php      # Bootstrap web: autoload, sesión, config
├── config/
│   └── config.php         # Configuración (BD, etc.)
├── database/
│   ├── schema.sql         # Esquema completo (instalaciones nuevas)
│   ├── init.php           # Crea DB desde cero + datos demo
│   └── migrate_*.php      # Migraciones para bases ya existentes
├── public/                # Document root recomendado
│   ├── index.php          # Temas
│   ├── dashboard.php      # Matriz, lista, foco, calendario y pestaña Perfiles (pentágono)
│   ├── pentagon-dashboard.php  # Redirección a dashboard.php?panel=pentagon (compatibilidad)
│   ├── devops.php         # DevOps (Azure DevOps)
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
    ├── Repositories/
    ├── Services/
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
- **DevOps:** interfaz para enlazar trabajo con **Azure DevOps** (configuración/API según entorno).
- **Bloc personal:** notas y archivos privados del usuario conectado.
- **Usuarios:** alta y gestión de cuentas (rol administrativo).
- **Tema claro/oscuro:** preferencia en el cliente (`theme.js`).

### API JSON (referencia rápida)

Los endpoints viven en `public/api/*.php` (mismo origen que la app, `credentials: same-origin`). Entre otros:

- `login.php`, `logout.php`, `me.php`
- `topics.php`, `topic.php` (**priority** e **importance** como enteros **1–10** en JSON), `people-board.php`
- `team-people.php`, `team-person.php` (personas; **PUT/POST** aceptan las claves `axis_*` del pentágono)
- `alerts.php`, `users.php`, `teams.php`
- `user-scratchpad.php`, `user-files.php`

---

## Actualizar una base de datos ya existente

Si ya tienes un `app.sqlite` antiguo y **no** quieres borrarlo con `init.php`, ejecuta solo los scripts que apliquen. Cada script comprueba si hace falta y puede no modificar nada si la migración ya está aplicada.

| Script | Propósito |
|--------|-----------|
| `database/migrate_team_people.php` | Tabla `team_people` si faltaba |
| `database/migrate_team_people_role.php` | Campo `role` en personas |
| `database/migrate_team_people_pentagon.php` | Campos `axis_strategic_vision`, `axis_technical_execution`, `axis_team_management`, `axis_data_risk`, `axis_innovation` en `team_people` |
| `database/migrate_topic_completed_at.php` | Campo `completed_at` en temas |
| `database/migrate_topics_importance_priority.php` | Importancia y prioridad ampliada |
| `database/migrate_topics_five_levels.php` | Escala de 5 niveles en urgencia/importancia (histórico; bases nuevas no lo necesitan) |
| `database/migrate_topics_numeric_1_10.php` | Convierte `topics.priority` e `topics.importance` a **INTEGER 1–10** (instalaciones nuevas con `schema.sql` ya vienen así; ejecútalo si tu `app.sqlite` aún tiene texto de cinco niveles) |
| `database/migrate_team_alerts.php` | Tabla `team_alerts` |
| `database/migrate_personal_workspace.php` | Espacio de trabajo personal por usuario |
| `database/migrate_user_scratchpad_files.php` | Tablas/recursos de bloc y archivos personales |

Ejemplo (desde la raíz del proyecto):

```bash
php database/migrate_team_people_pentagon.php
```

En Windows con XAMPP, si `php` no está en el PATH:

```powershell
c:\xampp\php\php.exe database\migrate_team_people_pentagon.php
```

El orden debe respetar el **historial de tu base**: si partes de una versión muy antigua, puede ser necesario ejecutar migraciones anteriores primero.

---

## Solución de problemas

- **“Base de datos no inicializada”** o página en blanco: comprobar que exista `database/app.sqlite` y que `config/config.php` apunte a la ruta correcta.
- **Error al escribir en SQLite:** permisos de carpeta/archivo en `database/`.
- **Error SQL en temas** (columnas `priority`/`importance` con tipo o restricciones antiguas): ejecutar `database/migrate_topics_numeric_1_10.php` (tras las migraciones previas de temas si tu base es muy antigua) o recrear la BD con `init.php` si puedes perder datos.
- **Clase `App\Bootstrap` ya declarada:** asegurarse de usar la versión actual de `bootstrap_web.php` (usa `require_once` y caché de configuración).
- **Sesión / login:** comprobar que las cookies funcionen (mismo dominio, HTTPS en producción si aplica).

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

Para cambios en el esquema, actualiza `database/schema.sql` y, si aplica, añade o ajusta un `migrate_*.php` para quien ya tenga datos en producción.
