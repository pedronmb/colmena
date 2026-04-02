-- Colmena — esquema relacional SQLite
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'lead', 'member', 'viewer')),
    availability TEXT DEFAULT 'available' CHECK (availability IN ('available', 'busy', 'away', 'offline')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
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
    birthday TEXT,
    extra_info TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_team_people_team ON team_people(team_id);

CREATE TABLE topics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id),
    person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    body TEXT,
    priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('very_low', 'low', 'medium', 'high', 'critical')),
    importance TEXT NOT NULL DEFAULT 'medium' CHECK (importance IN ('very_low', 'low', 'medium', 'high', 'very_high')),
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
