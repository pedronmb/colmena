/**
 * Centro de Salud y Capacidad del Equipo (pestaña del dashboard).
 * @global { { load: () => Promise<void> } | undefined } ColmenaHealthDashboard
 */
(function () {
    const apiUrl = "api/team-health.php";
    const rootEl = document.getElementById("healthDashboardRoot");
    const teamInput = document.getElementById("dashboardTeamId");
    const loadingEl = document.getElementById("healthDashboardLoading");
    const errorEl = document.getElementById("healthDashboardError");
    const summaryEl = document.getElementById("healthDashboardSummary");
    const tableEl = document.getElementById("healthDashboardTable");
    const sortEl = document.getElementById("healthSort");
    const periodEl = document.getElementById("healthPeriod");
    const staleEl = document.getElementById("healthStaleDays");
    const refreshBtn = document.getElementById("healthRefresh");

    const STATUS_LABELS = {
        green: "Verde",
        yellow: "Amarillo",
        red: "Rojo",
    };

    const helpIconSvg =
        '<svg class="icon icon--help" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.25a2.75 2.75 0 1 1 4.35 2.24c-.85.68-1.35 1.45-1.35 2.51" stroke-linecap="round"/><circle cx="12" cy="17" r="0.75" fill="currentColor" stroke="none"/></svg>';

    /** @type {Record<string, string | ((staleDays: number) => string)>} */
    const METRIC_HELP = {
        team_health:
            "Promedio del índice de salud (0–100) del equipo según el filtro activo. Parte de 100 y descuenta carga alta, tickets stale y concentración de trabajo.",
        average_load:
            "Promedio de la carga total (0–100) por persona: suma normalizada de temas, InvGate, DevOps y señales de criticidad.",
        most_loaded:
            "Persona con mayor puntuación de carga en este momento y su valor numérico.",
        concentration:
            "Porcentaje de la carga total del equipo que concentra la persona más cargada. Por encima de 45% sugiere desbalance fuerte.",
        imbalance_ratio:
            "Carga máxima del equipo dividida por la carga promedio. Valores altos indican que unas pocas personas absorben mucho más que el resto.",
        critical_topics:
            "Cantidad de temas activos con urgencia e importancia mayores o iguales a 8 (asignados a personas del equipo).",
        stale_tickets: (days) =>
            `Total de tickets InvGate abiertos sin actividad (última actualización o comentario) hace más de ${days} días.`,
        team_status:
            "Semáforo del equipo: verde si la mayoría está bien; amarillo si hay señales moderadas; rojo si hay sobrecarga crítica o mucha concentración de carga.",
    };

    let loadSeq = 0;
    /** @type {object | null} */
    let lastPayload = null;

    function getScope() {
        const active = rootEl?.querySelector(
            ".health-filters__segment--active[data-health-scope]"
        );
        const scope = active?.getAttribute("data-health-scope");
        if (scope === "all" || scope === "direct" || scope === "collaborators") {
            return scope;
        }
        return "direct";
    }

    function setScope(scope) {
        rootEl?.querySelectorAll("[data-health-scope]").forEach((btn) => {
            const match = btn.getAttribute("data-health-scope") === scope;
            btn.classList.toggle("health-filters__segment--active", match);
            btn.setAttribute("aria-pressed", match ? "true" : "false");
        });
    }

    function teamId() {
        const fromRoot = rootEl?.getAttribute("data-team-id");
        if (fromRoot) {
            const n = parseInt(fromRoot, 10);
            if (Number.isFinite(n) && n > 0) {
                return n;
            }
        }
        const fromInput = teamInput?.value;
        const n = parseInt(String(fromInput ?? ""), 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    }

    function setLoading(on, msg) {
        if (!loadingEl) {
            return;
        }
        loadingEl.hidden = !on;
        loadingEl.textContent = on ? msg || "Cargando…" : "";
    }

    function setError(msg) {
        if (!errorEl) {
            return;
        }
        if (msg) {
            errorEl.hidden = false;
            errorEl.textContent = msg;
        } else {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
    }

    function statusBadge(status) {
        const key = status === "green" || status === "yellow" || status === "red" ? status : "yellow";
        const label = STATUS_LABELS[key] || key;
        return `<span class="health-status health-status--${key}">${escapeHtml(label)}</span>`;
    }

    function helpText(key, staleDays) {
        const entry = METRIC_HELP[key];
        if (entry == null) {
            return "";
        }
        if (typeof entry === "function") {
            return entry(staleDays ?? 3);
        }
        return entry;
    }

    function renderHelpTrigger(text) {
        if (!text) {
            return "";
        }
        const t = escapeHtml(text);
        return `<span class="invgate-stat-help" tabindex="0" role="note" aria-label="${t}" title="${t}">${helpIconSvg}</span>`;
    }

    function renderLabelWithHelp(label, helpKey, staleDays) {
        const text = helpText(helpKey, staleDays);
        return `${escapeHtml(label)}${renderHelpTrigger(text)}`;
    }

    /**
     * @param {string} label
     * @param {string} value
     * @param {{ helpKey?: string, staleDays?: number, valueClass?: string }} [options]
     */
    function renderStatCard(label, value, options) {
        const opts = options || {};
        const labelHtml = opts.helpKey
            ? renderLabelWithHelp(label, opts.helpKey, opts.staleDays)
            : escapeHtml(label);
        const valueClass = opts.valueClass ? ` ${opts.valueClass}` : "";
        return `<div class="health-stat-card">
            <span class="health-stat-card__label">${labelHtml}</span>
            <span class="health-stat-card__value${valueClass}">${value}</span>
        </div>`;
    }

    function renderSummary(team, meta) {
        if (!summaryEl || !team) {
            return;
        }
        const most = team.most_loaded_person;
        const mostLabel = most
            ? `${escapeHtml(most.name)} (${most.load_score})`
            : "—";
        const sources = meta?.data_sources || {};
        const invgateNote =
            sources.invgate === false
                ? '<p class="muted health-dashboard__note">Sin datos InvGate (tablas no disponibles o sin sincronizar).</p>'
                : "";
        const devopsNote =
            sources.devops === false
                ? '<p class="muted health-dashboard__note">DevOps no disponible en este entorno.</p>'
                : "";
        const staleDays = meta?.stale_days ?? 3;
        const teamStatusHelp = helpText("team_status", staleDays);

        summaryEl.innerHTML = `
            <section class="health-dashboard-team">
                <div class="health-dashboard-team__head">
                    <h3 class="health-dashboard-team__title">Resumen del equipo</h3>
                    <span class="health-dashboard-team__status-wrap" title="${escapeHtml(teamStatusHelp)}"${teamStatusHelp ? ` aria-label="${escapeHtml(teamStatusHelp)}"` : ""}>
                        ${statusBadge(team.status)}
                        ${teamStatusHelp ? renderHelpTrigger(teamStatusHelp) : ""}
                    </span>
                </div>
                <div class="health-dashboard-kpis">
                    ${renderStatCard("Salud promedio", escapeHtml(String(team.health_score ?? "—")), { helpKey: "team_health", staleDays })}
                    ${renderStatCard("Carga promedio", escapeHtml(String(team.average_load_score ?? "—")), { helpKey: "average_load", staleDays })}
                    ${renderStatCard("Más cargada", mostLabel, { helpKey: "most_loaded", staleDays, valueClass: " health-stat-card__value--text" })}
                    ${renderStatCard("Concentración", `${escapeHtml(String(team.concentration_percent ?? "—"))}%`, { helpKey: "concentration", staleDays })}
                    ${renderStatCard("Ratio desequilibrio", escapeHtml(team.imbalance_ratio != null ? String(team.imbalance_ratio) : "—"), { helpKey: "imbalance_ratio", staleDays })}
                    ${renderStatCard("Temas críticos", escapeHtml(String(team.critical_topics_count ?? 0)), { helpKey: "critical_topics", staleDays })}
                    ${renderStatCard("Tickets stale", escapeHtml(String(team.stale_tickets_count ?? 0)), { helpKey: "stale_tickets", staleDays })}
                </div>
                <p class="health-dashboard__recommendation"><strong>Recomendación:</strong> ${escapeHtml(team.main_recommendation || "")}</p>
                ${invgateNote}
                ${devopsNote}
            </section>`;
    }

    /**
     * @param {object[]} people
     * @param {string} sortKey
     */
    function sortPeople(people, sortKey) {
        const list = [...people];
        list.sort((a, b) => {
            const mA = a.metrics || {};
            const mB = b.metrics || {};
            switch (sortKey) {
                case "health_asc":
                    return (a.health_score ?? 0) - (b.health_score ?? 0);
                case "critical_desc":
                    return (mB.critical_topics ?? 0) - (mA.critical_topics ?? 0);
                case "stale_desc":
                    return (mB.stale_tickets ?? 0) - (mA.stale_tickets ?? 0);
                case "name_asc":
                    return String(a.name || "").localeCompare(String(b.name || ""), "es", {
                        sensitivity: "base",
                    });
                case "load_desc":
                default:
                    return (b.load_score ?? 0) - (a.load_score ?? 0);
            }
        });
        return list;
    }

    function renderMetricsChips(m) {
        const chips = [
            { key: "T", label: "Temas activos", value: m.active_topics ?? 0 },
            { key: "Cr", label: "Temas críticos", value: m.critical_topics ?? 0 },
            { key: "IG", label: "Tickets InvGate abiertos", value: m.open_tickets ?? 0 },
            { key: "St", label: "Tickets stale", value: m.stale_tickets ?? 0 },
            { key: "DO", label: "Ítems DevOps", value: m.devops_items ?? 0 },
        ];
        return `<div class="health-metrics-chips">${chips
            .map(
                (c) =>
                    `<span class="health-metric-chip" title="${escapeHtml(c.label)}">${escapeHtml(c.key)} ${escapeHtml(String(c.value))}</span>`
            )
            .join("")}</div>`;
    }

    function renderPersonDetail(person) {
        const b = person.breakdown || {};
        const m = person.metrics || {};
        const risks = Array.isArray(person.risks) ? person.risks : [];
        const actions = Array.isArray(person.suggested_actions) ? person.suggested_actions : [];
        const mainRisk = person.main_risk ? `<p class="health-detail__lead">${escapeHtml(person.main_risk)}</p>` : "";

        const risksHtml =
            risks.length > 0
                ? `<ul class="health-detail__list">${risks.map((r) => `<li>${escapeHtml(r)}</li>`).join("")}</ul>`
                : '<p class="muted">Sin riesgos.</p>';
        const actionsHtml =
            actions.length > 0
                ? `<ul class="health-detail__list">${actions.map((a) => `<li>${escapeHtml(a)}</li>`).join("")}</ul>`
                : '<p class="muted">Sin acciones sugeridas.</p>';

        return `
            <div class="health-detail">
                ${mainRisk}
                <h4 class="health-detail__title">Conteos</h4>
                ${renderMetricsChips(m)}
                <h4 class="health-detail__title">Desglose de carga (0–100)</h4>
                <ul class="health-detail__metrics">
                    <li>Temas: <strong>${escapeHtml(String(b.topics_score ?? 0))}</strong></li>
                    <li>InvGate: <strong>${escapeHtml(String(b.invgate_score ?? 0))}</strong></li>
                    <li>DevOps: <strong>${escapeHtml(String(b.devops_score ?? 0))}</strong></li>
                    <li>Criticidad: <strong>${escapeHtml(String(b.criticality_score ?? 0))}</strong></li>
                </ul>
                <h4 class="health-detail__title">Riesgos</h4>
                ${risksHtml}
                <h4 class="health-detail__title">Acciones sugeridas</h4>
                ${actionsHtml}
            </div>`;
    }

    /**
     * @param {object[]} people
     */
    function renderTable(people) {
        if (!tableEl) {
            return;
        }
        if (!people.length) {
            tableEl.innerHTML = '<p class="muted">No hay personas en este segmento.</p>';
            return;
        }

        const sortKey = sortEl?.value || "load_desc";
        const sorted = sortPeople(people, sortKey);

        const rows = sorted
            .map((person) => {
                const m = person.metrics || {};
                const typeShort = person.is_direct_team ? "Directo" : "Colab.";
                const role = person.role ? escapeHtml(person.role) : "—";
                const pid = person.person_id;
                const detailId = `health-detail-${pid}`;

                return `
                    <tr class="health-table__row" data-person-id="${escapeHtml(String(pid))}">
                        <td class="health-table__cell-person">
                            <div class="health-table__person">
                                <strong class="health-table__name">${escapeHtml(person.name || "")}</strong>
                                <span class="health-table__meta">${role} · ${escapeHtml(typeShort)}</span>
                            </div>
                        </td>
                        <td class="health-table__cell-scores">
                            <div class="health-table__scores">
                                <span class="health-table__score" title="Carga total">
                                    <span class="health-table__score-label">Carga</span>
                                    <strong>${escapeHtml(String(person.load_score ?? 0))}</strong>
                                </span>
                                <span class="health-table__score" title="Salud">
                                    <span class="health-table__score-label">Salud</span>
                                    <strong>${escapeHtml(String(person.health_score ?? 0))}</strong>
                                </span>
                            </div>
                        </td>
                        <td class="health-table__cell-status">${statusBadge(person.status)}</td>
                        <td class="health-table__cell-metrics">${renderMetricsChips(m)}</td>
                        <td class="health-table__cell-action">
                            <button type="button" class="btn btn--small health-table__toggle" aria-expanded="false" aria-controls="${detailId}" data-health-toggle="${escapeHtml(String(pid))}">
                                Detalle
                            </button>
                        </td>
                    </tr>
                    <tr class="health-table__detail-row" id="${detailId}" hidden>
                        <td colspan="5">${renderPersonDetail(person)}</td>
                    </tr>`;
            })
            .join("");

        tableEl.innerHTML = `
            <div class="health-table-wrap">
                <table class="data-table health-table">
                    <thead>
                        <tr>
                            <th>Persona</th>
                            <th>Carga / Salud</th>
                            <th>Estado</th>
                            <th>Indicadores</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;

        tableEl.querySelectorAll("[data-health-toggle]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const id = btn.getAttribute("data-health-toggle");
                const detailRow = tableEl.querySelector(`#health-detail-${id}`);
                if (!detailRow) {
                    return;
                }
                const open = detailRow.hidden;
                detailRow.hidden = !open;
                btn.setAttribute("aria-expanded", open ? "true" : "false");
                btn.textContent = open ? "Ocultar" : "Detalle";
            });
        });
    }

    function renderAll() {
        if (!lastPayload) {
            return;
        }
        renderSummary(lastPayload.team, lastPayload.meta);
        const sortKey = sortEl?.value || "load_desc";
        const people = sortPeople(lastPayload.people || [], sortKey);
        renderTable(people);
    }

    async function load() {
        if (!rootEl) {
            return;
        }
        const tid = teamId();
        if (tid < 1) {
            setError("Equipo no configurado.");
            return;
        }

        const seq = ++loadSeq;
        setError("");
        setLoading(true);

        const scope = getScope();
        const period = periodEl?.value || "30";
        let staleDays = parseInt(String(staleEl?.value ?? "3"), 10);
        if (!Number.isFinite(staleDays) || staleDays < 1) {
            staleDays = 3;
        }
        if (staleDays > 90) {
            staleDays = 90;
        }
        if (staleEl) {
            staleEl.value = String(staleDays);
        }

        const qs = new URLSearchParams({
            team_id: String(tid),
            scope,
            period,
            stale_days: String(staleDays),
        });

        try {
            const res = await fetch(`${apiUrl}?${qs.toString()}`, { credentials: "same-origin" });
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            const data = await res.json();
            if (seq !== loadSeq) {
                return;
            }
            if (!data.ok) {
                throw new Error(data.error || "Error al cargar salud del equipo");
            }
            lastPayload = data;
            renderAll();
        } catch (e) {
            if (seq !== loadSeq) {
                return;
            }
            const msg = e instanceof Error ? e.message : "Error";
            setError(msg);
            if (summaryEl) {
                summaryEl.innerHTML = "";
            }
            if (tableEl) {
                tableEl.innerHTML = "";
            }
        } finally {
            if (seq === loadSeq) {
                setLoading(false);
            }
        }
    }

    function wireToolbarOnce() {
        if (window.__colmenaHealthToolbarWired) {
            return;
        }
        window.__colmenaHealthToolbarWired = true;

        rootEl?.querySelectorAll("[data-health-scope]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const scope = btn.getAttribute("data-health-scope");
                if (!scope) {
                    return;
                }
                setScope(scope);
                load();
            });
        });
        periodEl?.addEventListener("change", () => load());
        staleEl?.addEventListener("change", () => load());
        refreshBtn?.addEventListener("click", () => load());
        sortEl?.addEventListener("change", () => {
            if (lastPayload) {
                renderTable(lastPayload.people || []);
            }
        });
    }

    wireToolbarOnce();
    window.ColmenaHealthDashboard = { load };
})();
