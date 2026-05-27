/**
 * InvGate: estadísticas por persona (carga, distribución, histórico, comentarios).
 */
(function () {
    const apiUrl = "api/invgate-stats.php";
    const C = window.InvgateCommon;

    const panelEl = document.getElementById("invgatePanelStats");
    const loadingEl = document.getElementById("invgateStatsLoading");
    const metaEl = document.getElementById("invgateStatsMeta");
    const rootEl = document.getElementById("invgateStatsRoot");
    const periodEl = document.getElementById("invgateStatsPeriod");
    const staleEl = document.getElementById("invgateStatsStaleDays");
    const refreshBtn = document.getElementById("invgateStatsRefresh");

    let loaded = false;
    let loadSeq = 0;

    const chevronSvg =
        '<svg class="icon icon--chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    let groupIdSeq = 0;

    /** @type {Record<string, string | ((staleDays: number) => string)>} */
    const METRIC_HELP = {
        open_count:
            "Tickets asignados cuyo estado no es final (IDs 5–8 en InvGate). Coincide con la pestaña Tickets.",
        weighted_load:
            "Suma de pesos en abiertos: prioridad 1 = 5, 2 = 3, 3 = 2, resto o sin prioridad = 1. Compara carga real, no solo cantidad.",
        high_priority_pct:
            "Porcentaje de tickets abiertos con prioridad 1 o 2 (alta urgencia en InvGate).",
        backlog_age_avg:
            "Promedio de días desde la creación del ticket hasta hoy, solo sobre tickets abiertos.",
        backlog_age_median:
            "Mediana de días desde la creación hasta hoy en abiertos. Menos sensible a valores extremos que el promedio.",
        stale_count: (days) =>
            `Tickets abiertos sin actividad (última actualización o comentario) hace más de ${days} días.`,
        aging_high_priority: (days) =>
            `Tickets abiertos con prioridad 1 o 2 creados hace más de ${days} días.`,
        first_response:
            "Promedio de horas desde la creación hasta el primer comentario del agente (ID InvGate de la persona). Requiere sync de comentarios.",
        open_total: "Total de tickets abiertos del equipo (estado no final).",
        weighted_load_total: "Suma de la carga ponderada de todas las personas del equipo.",
        stale_total: (days) =>
            `Total de tickets abiertos del equipo sin movimiento hace más de ${days} días.`,
        resolved_30d_total:
            "Tickets que pasaron a estado final con última actualización en los últimos 30 días (según datos locales).",
        top_by_load: "Las tres personas con mayor carga ponderada en tickets abiertos.",
        dist_status: "Cantidad y porcentaje de tickets abiertos en cada estado de InvGate.",
        dist_type: "Cantidad y porcentaje de tickets abiertos por tipo de incidente.",
        dist_category: "Cantidad y porcentaje de tickets abiertos por categoría.",
        resolved_period:
            "Tickets en estado final cuya última actualización cae en el período seleccionado (aproxima la fecha de cierre).",
        resolved_7d:
            "Tickets en estado final con última actualización en los últimos 7 días.",
        resolved_30d:
            "Tickets en estado final con última actualización en los últimos 30 días.",
        resolution_avg:
            "Promedio de horas entre creación y última actualización en tickets finales del período.",
        resolution_p50:
            "Mediana del tiempo de resolución: la mitad de los cierres del período fueron más rápidos.",
        resolution_p90:
            "Percentil 90: el 90% de los cierres del período fueron más rápidos que este valor.",
        weekly_throughput:
            "Tickets que pasaron a estado final por semana ISO (últimas 8 semanas en el gráfico).",
        comments_per_ticket:
            "Promedio de comentarios sincronizados por ticket de la persona.",
        comments_agent:
            "Comentarios cuyo autor coincide con el ID InvGate de la persona.",
        avg_idle:
            "Promedio de horas desde la última interacción (actualización o comentario) en tickets abiertos.",
        solution_rate:
            "Porcentaje de tickets con al menos un comentario marcado como solución en InvGate.",
    };

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

    const helpIconSvg =
        '<svg class="icon icon--help" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.25a2.75 2.75 0 1 1 4.35 2.24c-.85.68-1.35 1.45-1.35 2.51" stroke-linecap="round"/><circle cx="12" cy="17" r="0.75" fill="currentColor" stroke="none"/></svg>';

    function renderHelpTrigger(text) {
        if (!text) {
            return "";
        }
        const t = C.escapeHtml(text);
        return `<span class="invgate-stat-help" tabindex="0" role="note" aria-label="${t}" title="${t}">${helpIconSvg}</span>`;
    }

    function renderLabelWithHelp(label, helpKey, staleDays) {
        const text = helpText(helpKey, staleDays);
        return `${C.escapeHtml(label)}${renderHelpTrigger(text)}`;
    }

    function nextGroupDomId(prefix) {
        groupIdSeq += 1;
        return `${prefix}-${groupIdSeq}`;
    }

    /**
     * @param {string} label
     * @param {string} value
     * @param {{ hint?: string, helpKey?: string, staleDays?: number }} [options]
     */
    function renderStatCard(label, value, options) {
        const opts = options || {};
        const hintHtml = opts.hint
            ? `<span class="invgate-stat-card__hint muted">${C.escapeHtml(opts.hint)}</span>`
            : "";
        const labelHtml = opts.helpKey
            ? renderLabelWithHelp(label, opts.helpKey, opts.staleDays)
            : C.escapeHtml(label);
        return `<div class="invgate-stat-card">
            <span class="invgate-stat-card__label">${labelHtml}</span>
            <span class="invgate-stat-card__value">${value}</span>
            ${hintHtml}
        </div>`;
    }

    function renderMetricListItem(label, valueHtml, helpKey, staleDays) {
        return `<li class="invgate-stat-metric">
            <span class="invgate-stat-metric__label">${renderLabelWithHelp(label, helpKey, staleDays)}</span>
            <strong class="invgate-stat-metric__value">${valueHtml}</strong>
        </li>`;
    }

    function renderSectionTitle(title, helpKey, staleDays) {
        return `<h4 class="invgate-stats-section-title">${renderLabelWithHelp(title, helpKey, staleDays)}</h4>`;
    }

    function renderDistributionBars(items, total) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<p class="muted invgate-stats__empty">Sin datos.</p>';
        }
        const max = Math.max(...items.map((i) => Number(i.count) || 0), 1);
        return `<ul class="invgate-stat-bars">
            ${items
                .map((item) => {
                    const count = Number(item.count) || 0;
                    const pct = total > 0 ? Math.round((100 * count) / total) : 0;
                    const width = Math.round((100 * count) / max);
                    const label = item.label != null ? String(item.label) : "—";
                    return `<li class="invgate-stat-bars__item">
                        <span class="invgate-stat-bars__label">${C.escapeHtml(label)}</span>
                        <span class="invgate-stat-bars__track" aria-hidden="true"><span class="invgate-stat-bars__fill" style="width:${width}%"></span></span>
                        <span class="invgate-stat-bars__count">${count} <span class="muted">(${pct}%)</span></span>
                    </li>`;
                })
                .join("")}
        </ul>`;
    }

    function renderThroughputTable(rows) {
        if (!Array.isArray(rows) || rows.length === 0) {
            return '<p class="muted invgate-stats__empty">Sin cierres en las últimas semanas.</p>';
        }
        return `<table class="data-table invgate-stats-table">
            <thead><tr><th>Semana</th><th>Cerrados</th></tr></thead>
            <tbody>${rows
                .map(
                    (r) =>
                        `<tr><td>${C.escapeHtml(String(r.week))}</td><td>${C.formatNumber(r.count)}</td></tr>`
                )
                .join("")}</tbody>
        </table>`;
    }

    function renderTeamSummary(summary, staleDays) {
        if (!summary || typeof summary !== "object") {
            return "";
        }
        const top = Array.isArray(summary.top_by_load) ? summary.top_by_load : [];
        const topHtml =
            top.length === 0
                ? '<p class="muted">Sin carga asignada.</p>'
                : `<ol class="invgate-stats-ranking">
            ${top
                .map(
                    (p, i) =>
                        `<li><strong>${C.escapeHtml(String(p.display_name))}</strong> — carga ${C.formatNumber(p.weighted_load)}, ${C.formatNumber(p.open_count)} abiertos</li>`
                )
                .join("")}
        </ol>`;

        return `<section class="invgate-stats-team">
            <h3 class="invgate-stats-team__title">Resumen del equipo</h3>
            <div class="invgate-stats-kpis">
                ${renderStatCard("Abiertos (total)", C.formatNumber(summary.open_total), { helpKey: "open_total" })}
                ${renderStatCard("Carga ponderada", C.formatNumber(summary.weighted_load_total), { helpKey: "weighted_load_total" })}
                ${renderStatCard("Stale (total)", C.formatNumber(summary.stale_total), { helpKey: "stale_total", staleDays, hint: "sin movimiento" })}
                ${renderStatCard("Resueltos 30d", C.formatNumber(summary.resolved_30d_total), { helpKey: "resolved_30d_total" })}
            </div>
            <div class="invgate-stats-team__ranking">
                <h4 class="invgate-stats-section-title">${renderLabelWithHelp("Top 3 por carga ponderada", "top_by_load")}</h4>
                ${topHtml}
            </div>
        </section>`;
    }

    function renderPersonKpis(current, comments, staleDays) {
        return `<div class="invgate-stats-kpis invgate-stats-kpis--person">
            ${renderStatCard("Abiertos", C.formatNumber(current.open_count), { helpKey: "open_count" })}
            ${renderStatCard("Carga ponderada", C.formatNumber(current.weighted_load), { helpKey: "weighted_load" })}
            ${renderStatCard("% P1/P2", C.formatPct(current.high_priority_pct), { helpKey: "high_priority_pct" })}
            ${renderStatCard("Edad promedio", current.backlog_age_avg_days != null ? `${C.formatNumber(current.backlog_age_avg_days, 1)} d` : "—", { helpKey: "backlog_age_avg" })}
            ${renderStatCard("Stale", C.formatNumber(current.stale_count), { helpKey: "stale_count", staleDays })}
            ${renderStatCard("1ª respuesta", C.formatHours(comments.avg_first_response_hours), { helpKey: "first_response" })}
        </div>`;
    }

    function renderPersonDetail(personRow, staleDays) {
        const current = personRow.current || {};
        const dist = personRow.distributions || {};
        const hist = personRow.historical || {};
        const comments = personRow.comments || {};

        const medianVal =
            current.backlog_age_median_days != null
                ? `${C.formatNumber(current.backlog_age_median_days, 1)} d`
                : "—";

        return `<div class="invgate-stats-detail">
            <section class="invgate-stats-detail__block">
                <h4 class="invgate-stats-section-title">Aging</h4>
                <ul class="invgate-stats-metrics-list">
                    ${renderMetricListItem("Edad mediana del backlog", medianVal, "backlog_age_median", staleDays)}
                    ${renderMetricListItem(`P1/P2 con más de ${staleDays} días`, C.formatNumber(current.aging_high_priority_count), "aging_high_priority", staleDays)}
                </ul>
            </section>
            <section class="invgate-stats-detail__block">
                ${renderSectionTitle("Distribución por estado", "dist_status", staleDays)}
                ${renderDistributionBars(dist.by_status, current.open_count)}
            </section>
            <section class="invgate-stats-detail__block">
                ${renderSectionTitle("Distribución por tipo", "dist_type", staleDays)}
                ${renderDistributionBars(dist.by_type, current.open_count)}
            </section>
            <section class="invgate-stats-detail__block">
                ${renderSectionTitle("Distribución por categoría", "dist_category", staleDays)}
                ${renderDistributionBars(dist.by_category, current.open_count)}
            </section>
            <section class="invgate-stats-detail__block">
                <h4 class="invgate-stats-section-title">Histórico</h4>
                <ul class="invgate-stats-metrics-list">
                    ${renderMetricListItem("Resueltos (período)", C.formatNumber(hist.resolved_in_period), "resolved_period", staleDays)}
                    ${renderMetricListItem("Resueltos 7d", C.formatNumber(hist.resolved_7d), "resolved_7d", staleDays)}
                    ${renderMetricListItem("Resueltos 30d", C.formatNumber(hist.resolved_30d), "resolved_30d", staleDays)}
                    ${renderMetricListItem("Tiempo resolución (promedio)", C.formatHours(hist.resolution_avg_hours), "resolution_avg", staleDays)}
                    ${renderMetricListItem("Tiempo resolución (p50)", C.formatHours(hist.resolution_p50_hours), "resolution_p50", staleDays)}
                    ${renderMetricListItem("Tiempo resolución (p90)", C.formatHours(hist.resolution_p90_hours), "resolution_p90", staleDays)}
                </ul>
                <h5 class="invgate-stats-subtitle">${renderLabelWithHelp("Throughput semanal", "weekly_throughput", staleDays)}</h5>
                ${renderThroughputTable(hist.weekly_throughput)}
            </section>
            <section class="invgate-stats-detail__block">
                <h4 class="invgate-stats-section-title">Comentarios</h4>
                <ul class="invgate-stats-metrics-list">
                    ${renderMetricListItem("Comentarios por ticket (prom.)", comments.avg_per_ticket != null ? C.formatNumber(comments.avg_per_ticket, 2) : "—", "comments_per_ticket", staleDays)}
                    ${renderMetricListItem("Comentarios del agente", comments.total != null ? C.formatNumber(comments.total) : "—", "comments_agent", staleDays)}
                    ${renderMetricListItem("Tiempo sin interacción (prom.)", C.formatHours(comments.avg_idle_hours), "avg_idle", staleDays)}
                    ${renderMetricListItem("Tasa con solución marcada", C.formatPct(comments.solution_rate_pct), "solution_rate", staleDays)}
                </ul>
                ${comments.total === null ? '<p class="muted invgate-stats__hint">Ejecutá <code>php database/sync_invgate_comments.php</code> para métricas de actividad.</p>' : ""}
            </section>
        </div>`;
    }

    function renderPersonGroup(personRow, staleDays) {
        const person = personRow.person || {};
        const name = C.escapeHtml(person.display_name || "Sin nombre");
        const openCount = personRow.current?.open_count ?? 0;
        const bodyId = nextGroupDomId("invgate-stats-body");
        const detailHtml = renderPersonDetail(personRow, staleDays);
        const kpisHtml = renderPersonKpis(personRow.current || {}, personRow.comments || {}, staleDays);

        return `<section class="invgate-group invgate-group--collapsed invgate-stats-person">
            <div class="invgate-group__head">
                <button type="button" class="invgate-group__toggle panel-collapse-btn" aria-expanded="false" aria-controls="${bodyId}" title="Desplegar detalle">
                    <span class="panel-collapse-btn__chevron" aria-hidden="true">${chevronSvg}</span>
                </button>
                <h3 class="invgate-group__title">
                    <button type="button" class="invgate-group__title-btn" aria-expanded="false" aria-controls="${bodyId}">${name} <span class="invgate-group__count muted">— ${C.formatNumber(openCount)} abiertos</span></button>
                </h3>
            </div>
            <div id="${bodyId}" class="invgate-group__body">
                ${kpisHtml}
                ${detailHtml}
            </div>
        </section>`;
    }

    function setGroupCollapsed(groupEl, collapsed) {
        groupEl.classList.toggle("invgate-group--collapsed", collapsed);
        const expanded = !collapsed;
        groupEl.querySelectorAll(".invgate-group__toggle, .invgate-group__title-btn").forEach((btn) => {
            btn.setAttribute("aria-expanded", expanded ? "true" : "false");
            btn.setAttribute("title", expanded ? "Plegar detalle" : "Desplegar detalle");
        });
    }

    function bindGroupToggles() {
        if (!rootEl || rootEl.dataset.statsToggleBound === "1") {
            return;
        }
        rootEl.dataset.statsToggleBound = "1";
        rootEl.addEventListener("click", (e) => {
            const trigger = e.target.closest(".invgate-group__toggle, .invgate-group__title-btn");
            if (!trigger || !rootEl.contains(trigger)) {
                return;
            }
            const group = trigger.closest(".invgate-group");
            if (!group) {
                return;
            }
            setGroupCollapsed(group, !group.classList.contains("invgate-group--collapsed"));
        });
    }

    function renderAll(data) {
        if (!rootEl) {
            return;
        }
        const people = Array.isArray(data.people) ? data.people : [];
        const staleDays = Number(data.stale_days) || 3;
        groupIdSeq = 0;

        if (people.length === 0) {
            rootEl.innerHTML =
                '<p class="muted">No hay personas con ID InvGate en el equipo. Configurá el ID en <strong>Editar fichas</strong>.</p>';
        } else {
            rootEl.innerHTML =
                renderTeamSummary(data.team_summary, staleDays) +
                people.map((p) => renderPersonGroup(p, staleDays)).join("");
            bindGroupToggles();
        }
        rootEl.hidden = false;

        if (metaEl) {
            const notes =
                data.meta && data.meta.data_notes ? String(data.meta.data_notes) : "";
            metaEl.textContent = notes;
            metaEl.hidden = notes === "";
        }
    }

    async function loadStats(force) {
        if (!panelEl || panelEl.hidden) {
            return;
        }
        if (loaded && !force) {
            return;
        }

        const teamId = C.getTeamId();
        if (teamId < 1) {
            if (loadingEl) {
                loadingEl.textContent = "No se pudo determinar el equipo de trabajo.";
            }
            return;
        }

        const period = periodEl ? periodEl.value : "30";
        const staleDays = staleEl ? Number(staleEl.value) : 3;
        const seq = ++loadSeq;

        if (loadingEl) {
            loadingEl.hidden = false;
            loadingEl.textContent = "Cargando estadísticas…";
        }
        if (rootEl) {
            rootEl.hidden = true;
        }
        if (metaEl) {
            metaEl.hidden = true;
        }

        try {
            const qs = new URLSearchParams({
                team_id: String(teamId),
                period,
                stale_days: String(Number.isFinite(staleDays) && staleDays > 0 ? staleDays : 3),
            });
            const res = await fetch(`${apiUrl}?${qs}`, { credentials: "same-origin" });
            const data = await res.json().catch(() => ({}));
            if (seq !== loadSeq) {
                return;
            }
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudieron cargar las estadísticas");
            }
            if (loadingEl) {
                loadingEl.hidden = true;
            }
            loaded = true;
            renderAll(data);
        } catch (e) {
            if (seq !== loadSeq) {
                return;
            }
            if (loadingEl) {
                loadingEl.hidden = false;
                loadingEl.textContent =
                    e instanceof Error ? e.message : "Error al cargar estadísticas.";
            }
            if (rootEl) {
                rootEl.hidden = true;
            }
        }
    }

    function initControls() {
        if (refreshBtn) {
            refreshBtn.addEventListener("click", () => {
                loaded = false;
                loadStats(true);
            });
        }
        if (periodEl) {
            periodEl.addEventListener("change", () => {
                loaded = false;
                loadStats(true);
            });
        }
        if (staleEl) {
            staleEl.addEventListener("change", () => {
                loaded = false;
                loadStats(true);
            });
        }
    }

    initControls();
    window.InvgateStats = { loadStats, reset: () => { loaded = false; } };
})();
