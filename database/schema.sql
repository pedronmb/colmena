-- Colmena — esquema relacional SQLite
PRAGMA foreign_keys = ON;

CREATE TABLE teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'lead', 'member', 'viewer')),
    availability TEXT DEFAULT 'available' CHECK (availability IN ('available', 'busy', 'away', 'offline')),
    personal_team_id INTEGER REFERENCES teams(id),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE team_members (
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_in_team TEXT NOT NULL DEFAULT 'member' CHECK (role_in_team IN ('owner', 'lead', 'member')),
    joined_at TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (team_id, user_id)
);

-- Personas/tarjetas del equipo (no son cuentas de login)
CREATE TABLE team_people (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    display_name TEXT NOT NULL,
    email TEXT,
    role TEXT,
    invgate_id INTEGER,
    birthday TEXT,
    extra_info TEXT,
    axis_autonomy_problem_solving INTEGER,
    axis_impact_scope INTEGER,
    axis_influence_mentorship INTEGER,
    axis_business_communication INTEGER,
    axis_technical_competence INTEGER,
    is_direct_team INTEGER NOT NULL DEFAULT 0,
    reports_to_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_team_people_team ON team_people(team_id);
CREATE INDEX idx_team_people_reports_to ON team_people(reports_to_id);

CREATE TABLE topics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id),
    person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    body TEXT,
    priority INTEGER NOT NULL DEFAULT 5 CHECK (priority BETWEEN 1 AND 10),
    importance INTEGER NOT NULL DEFAULT 5 CHECK (importance BETWEEN 1 AND 10),
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'blocked', 'done', 'archived')),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    completed_at TEXT
);

CREATE INDEX idx_topics_team ON topics(team_id);
CREATE INDEX idx_topics_person ON topics(person_id);
CREATE INDEX idx_topics_priority ON topics(priority);
CREATE INDEX idx_topics_importance ON topics(importance);
CREATE INDEX idx_topics_status ON topics(status);
CREATE INDEX idx_topics_updated ON topics(updated_at DESC);

CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_comments_topic ON comments(topic_id);

-- Alertas del equipo (fecha de cumplimiento; aviso en la última semana o vencidas)
CREATE TABLE team_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    body TEXT,
    due_date TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_team_alerts_team ON team_alerts(team_id);
CREATE INDEX idx_team_alerts_due ON team_alerts(due_date);

-- Bloc de notas y archivos personales (por usuario logueado)
CREATE TABLE user_scratchpad (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    content TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE user_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    mime_type TEXT,
    size_bytes INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_user_files_user ON user_files(user_id);

-- Tickets sincronizados desde InvGate (por persona del equipo)
CREATE TABLE invgate_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    invgate_incident_id INTEGER NOT NULL UNIQUE,
    user_id INTEGER,
    title TEXT NOT NULL,
    description TEXT,
    category_id INTEGER,
    source_id INTEGER,
    status_id INTEGER,
    type_id INTEGER,
    created_at TEXT NOT NULL,
    last_update TEXT NOT NULL,
    priority INTEGER
);

CREATE INDEX idx_invgate_tickets_person ON invgate_tickets(person_id);
CREATE INDEX idx_invgate_tickets_last_update ON invgate_tickets(last_update DESC);

-- Work items sincronizados desde Azure DevOps
CREATE TABLE azure_work_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    azure_id INTEGER NOT NULL UNIQUE,
    person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    work_item_type TEXT,
    state TEXT NOT NULL,
    assigned_to TEXT,
    assigned_unique_name TEXT,
    url TEXT,
    created_at TEXT,
    changed_at TEXT NOT NULL DEFAULT '0',
    synced_at TEXT NOT NULL DEFAULT (datetime('now')),
    removed_at TEXT
);

CREATE INDEX idx_azure_work_items_person ON azure_work_items(person_id);
CREATE INDEX idx_azure_work_items_state ON azure_work_items(state);
CREATE INDEX idx_azure_work_items_changed ON azure_work_items(changed_at DESC);

-- Catálogos lookup para mostrar nombres (InvGate)
CREATE TABLE invgate_categories (
    invgate_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    parent_category_id INTEGER
);

CREATE TABLE invgate_types (
    invgate_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE invgate_statuses (
    invgate_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
);

CREATE TABLE invgate_ticket_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id INTEGER NOT NULL REFERENCES invgate_tickets(id) ON DELETE CASCADE,
    author_id INTEGER,
    message TEXT NOT NULL,
    created_at TEXT NOT NULL,
    msg_num INTEGER NOT NULL,
    is_solution INTEGER NOT NULL DEFAULT 0,
    UNIQUE(incident_id, msg_num)
);

CREATE INDEX idx_invgate_ticket_comments_incident ON invgate_ticket_comments(incident_id);

CREATE TABLE invgate_ticket_recommendations (
    ticket_id INTEGER PRIMARY KEY REFERENCES invgate_tickets(id) ON DELETE CASCADE,
    summary TEXT NOT NULL,
    recommendation TEXT NOT NULL,
    generated_at TEXT NOT NULL,
    model TEXT,
    error TEXT
);
