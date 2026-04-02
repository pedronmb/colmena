# Colmena

Aplicación web en **PHP** con **SQLite** para gestionar equipos, personas (tarjetas sin cuenta de acceso), temas con prioridad e importancia, dashboards tipo matriz Eisenhower, y alertas con fecha de cumplimiento.

---

## Requisitos

| Requisito | Notas |
|-----------|--------|
| PHP | 8.0 o superior (recomendado 8.1+) |
| Extensiones | `pdo_sqlite`, `json`, `session` |
| Servidor web | Apache, nginx u otro con soporte PHP (en local suele usarse **XAMPP** u homólogo) |
| Base de datos | SQLite (archivo `database/app.sqlite`; no hace falta servidor MySQL) |

---

## Instalación rápida (entorno nuevo)

1. **Copiar el proyecto** en la carpeta del servidor (por ejemplo `htdocs/colmena` en XAMPP).

2. **Crear la base de datos y datos de demostración** desde la raíz del proyecto:

   ```bash
   php database/init.php
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

- **Equipo por defecto en formularios:** la demo usa `team_id = 1` en campos ocultos. Para varios equipos habría que ampliar la UI y la lógica.

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
│   └── migrate_*.php      # Scripts de migración para bases ya existentes
├── public/                # Document root recomendado
│   ├── index.php          # Temas
│   ├── dashboard.php      # Matriz y listado
│   ├── alerts.php         # Alertas
│   ├── people.php, people-edit.php
│   ├── login.php
│   ├── api/               # Endpoints JSON
│   └── assets/            # CSS, JS, favicon
└── src/
    ├── Bootstrap.php
    ├── Database/
    ├── Models/
    ├── Repositories/
    ├── Services/
    └── Support/
```

---

## Funcionalidades principales

- **Sesión:** login por email/contraseña; sesión PHP.
- **Temas:** título, descripción, urgencia (5 niveles), importancia (5 niveles), asignación a una tarjeta de persona, estados.
- **Personas:** tarjetas de equipo (no son usuarios de login).
- **Dashboards:** matriz urgencia × importancia y lista; tooltip en la matriz.
- **Alertas:** fecha de cumplimiento; aviso tras iniciar sesión si la fecha está vencida o en los próximos 7 días.
- **Tema claro/oscuro:** preferencia en el cliente (`theme.js`).

---

## Actualizar una base de datos ya existente

Si ya tienes un `app.sqlite` antiguo y **no** quieres borrarlo con `init.php`, ejecuta solo los scripts que apliquen (cada uno comprueba si hace falta y sale sin cambios si ya está aplicado):

| Script | Propósito |
|--------|-----------|
| `database/migrate_team_people.php` | Tabla `team_people` si faltaba |
| `database/migrate_topic_completed_at.php` | Campo `completed_at` en temas |
| `database/migrate_team_people_role.php` | Campo `role` en personas |
| `database/migrate_topics_importance_priority.php` | Importancia y prioridad ampliada |
| `database/migrate_topics_five_levels.php` | Escala de 5 niveles en urgencia/importancia |
| `database/migrate_team_alerts.php` | Tabla `team_alerts` |

Ejemplo (desde la raíz del proyecto, con PHP en el PATH):

```bash
php database/migrate_topics_five_levels.php
php database/migrate_team_alerts.php
```

El orden debe respetar el **historial de tu base**: si partes de una versión muy antigua, puede ser necesario ejecutar migraciones anteriores primero.

---

## Solución de problemas

- **“Base de datos no inicializada”** o página en blanco: comprobar que exista `database/app.sqlite` y que `config/config.php` apunte a la ruta correcta.
- **Error al escribir en SQLite:** permisos de carpeta/archivo en `database/`.
- **Clase `App\Bootstrap` ya declarada:** asegurarse de usar la versión actual de `bootstrap_web.php` (usa `require_once` y caché de configuración).
- **Sesión / login:** comprobar que las cookies funcionen (mismo dominio, HTTPS en producción si aplica).

---

## Git y GitHub

1. Instala **Git para Windows**: [git-scm.com/download/win](https://git-scm.com/download/win) (cierra y abre la terminal después).
2. Crea un **repositorio vacío** en GitHub (sin README si vas a subir este proyecto tal cual).
3. En PowerShell, desde la carpeta del proyecto:

   ```powershell
   cd ruta\a\colmena
   .\scripts\git-init-and-push.ps1 -RemoteUrl 'https://github.com/TU_USUARIO/TU_REPO.git'
   ```

   Sustituye la URL por la de tu repo (HTTPS o SSH). La primera vez Git pedirá autenticación: en GitHub suele usarse un **personal access token** en lugar de la contraseña.

**Nota:** En este entorno no se pudo ejecutar `git` (no estaba instalado en el PATH). Si ya tienes Git, puedes hacer lo mismo a mano:

```bash
git init
git add .
git commit -m "Initial commit: Colmena"
git branch -M main
git remote add origin https://github.com/TU_USUARIO/TU_REPO.git
git push -u origin main
```

El archivo `.gitignore` evita subir `database/*.sqlite`.

---

## Licencia y uso

Proyecto de uso interno / demostración; ajusta licencia y despliegue según tu organización.

---

## Desarrollo

- Estilo PHP: `declare(strict_types=1);`, tipado donde procede.
- API en `public/api/*.php`: JSON, `Content-Type: application/json`.
- Sin framework obligatorio; autoload PSR-4 simple para `App\*` bajo `src/`.

Para cambios en el esquema, actualiza `database/schema.sql` y, si aplica, añade un `migrate_*.php` para quien ya tenga datos en producción.
