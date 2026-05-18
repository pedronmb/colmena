-- Perfil pentágono v2: nuevos ejes en team_people (SQLite 3.25+)
-- Ejecutar sobre app.sqlite existente con columnas axis_* antiguas.
-- Conserva los valores numéricos (0–10); revisá las fichas si el significado de cada eje cambió.

ALTER TABLE team_people RENAME COLUMN axis_strategic_vision TO axis_autonomy_problem_solving;
ALTER TABLE team_people RENAME COLUMN axis_technical_execution TO axis_impact_scope;
ALTER TABLE team_people RENAME COLUMN axis_team_management TO axis_influence_mentorship;
ALTER TABLE team_people RENAME COLUMN axis_data_risk TO axis_business_communication;
ALTER TABLE team_people RENAME COLUMN axis_innovation TO axis_technical_competence;
