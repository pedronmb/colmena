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
        backlog_age_avg:
            "Promedio de días desde la creación del ticket hasta hoy, solo sobre tickets abiertos.",
        backlog_age_median:
            "Mediana de días desde la creación hasta hoy en abiertos. Menos sensible a valores extremos que el promedio.",
        stale_count: (days) =>
            `Tickets abiertos sin actividad (última actualización o comentario) hace más de ${days} días.`,
        aging_high_priority: (days) =>
            `Tickets abiertos con prioridad 1 o 2 creados hace más de ${days} días.`,
        open_total: "Total de tickets abiertos del equipo (estado no final).",
        backlog_age_avg_total:
            "Promedio de días desde la creación hasta hoy en todos los tickets abiertos del equipo.",
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
        backlog_age_max:
            "Mayor cantidad de días desde la creación entre los tickets abiertos (el caso más antiguo del backlog).",
        oldest_ticket:
            "Incidente InvGate (#ID) con mayor antigüedad entre los tickets abiertos considerados.",
        load_imbalance:
            "Carga máxima del equipo dividida por la carga ponderada promedio por persona. Valores altos indican desequilibrio.",
        load_concentration:
            "Porcentaje de la carga ponderada total del equipo que concentra la persona más cargada.",
        underloaded:
            "Personas con carga ponderada, abiertos y stale estrictamente por debajo del promedio del equipo (capacidad relativa).",
        team_dist_category:
            "Distribución de todos los tickets abiertos asignados a personas del equipo, agrupados por categoría.",
        team_dist_type:
            "Distribución de todos los tickets abiertos asignados a personas del equipo, agrupados por tipo.",
        orphans:
            "Tickets abiertos sin persona asignada en la base local (globales, no filtrados por equipo). Mismo criterio que la pestaña Tickets.",
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
                    const ticketIds = Array.isArray(item.ticket_ids)
                        ? item.ticket_ids.map((id) => Number(id)).filter((id) => id > 0)
                        : [];
                    const trackAttrs =
                        ticketIds.length > 0
                            ? ` data-ticket-ids="${C.escapeHtml(ticketIds.join(","))}" tabindex="0"`
                            : "";
                    const trackAria =
                        ticketIds.length > 0
                            ? ` aria-label="${C.escapeHtml(`${label}: ${count} tickets`)}"`
                            : ' aria-hidden="true"';
                    return `<li class="invgate-stat-bars__item">
                        <span class="invgate-stat-bars__label">${C.escapeHtml(label)}</span>
                        <span class="invgate-stat-bars__track${ticketIds.length > 0 ? " invgate-stat-bars__track--interactive" : ""}"${trackAttrs}${trackAria}><span class="invgate-stat-bars__fill" style="width:${width}%"></span></span>
                        <span class="invgate-stat-bars__count">${count} <span class="muted">(${pct}%)</span></span>
                    </li>`;
                })
                .join("")}
        </ul>`;
    }

    /** @type {HTMLElement | null} */
    let distTooltipEl = null;

    /** @type {HTMLElement | null} */
    let distTooltipAnchor = null;

    function ensureDistTooltip() {
        if (distTooltipEl) {
            return distTooltipEl;
        }
        const el = document.createElement("div");
        el.id = "invgateStatBarsTooltip";
        el.className = "invgate-stat-bars-tooltip";
        el.hidden = true;
        el.setAttribute("role", "tooltip");
        document.body.appendChild(el);
        distTooltipEl = el;
        return el;
    }

    function hideDistTooltip() {
        if (distTooltipEl) {
            distTooltipEl.hidden = true;
        }
        distTooltipAnchor = null;
    }

    function positionDistTooltip(anchor) {
        const el = ensureDistTooltip();
        el.style.visibility = "hidden";
        el.hidden = false;
        const rect = anchor.getBoundingClientRect();
        const pad = 10;
        const gap = 8;
        const pw = el.offsetWidth;
        const ph = el.offsetHeight;
        let left = rect.left + rect.width / 2 - pw / 2;
        let top = rect.bottom + gap;
        if (top + ph > window.innerHeight - pad) {
            top = rect.top - ph - gap;
        }
        if (left < pad) {
            left = pad;
        }
        if (left + pw > window.innerWidth - pad) {
            left = window.innerWidth - pw - pad;
        }
        if (top < pad) {
            top = pad;
        }
        el.style.left = `${Math.round(left)}px`;
        el.style.top = `${Math.round(top)}px`;
        el.style.visibility = "";
    }

    function showDistTooltip(anchor) {
        const raw = anchor.getAttribute("data-ticket-ids") || "";
        const ids = raw.split(",").filter(Boolean);
        if (ids.length === 0) {
            hideDistTooltip();
            return;
        }
        const labelEl = anchor.closest(".invgate-stat-bars__item")?.querySelector(".invgate-stat-bars__label");
        const label = labelEl ? labelEl.textContent || "" : "";
        const el = ensureDistTooltip();
        const idsHtml = ids.map((id) => `#${C.escapeHtml(id)}`).join(", ");
        el.innerHTML = `<div class="invgate-stat-bars-tooltip__title">${C.escapeHtml(label)}</div>
            <div class="invgate-stat-bars-tooltip__ids">${idsHtml}</div>`;
        el.hidden = false;
        distTooltipAnchor = anchor;
        positionDistTooltip(anchor);
    }

    function bindDistributionTooltips() {
        if (!rootEl || rootEl.dataset.distTooltipBound === "1") {
            return;
        }
        rootEl.dataset.distTooltipBound = "1";

        rootEl.addEventListener("mouseover", (e) => {
            const track = e.target.closest(".invgate-stat-bars__track[data-ticket-ids]");
            if (!track || !rootEl.contains(track)) {
                return;
            }
            if (distTooltipAnchor === track && distTooltipEl && !distTooltipEl.hidden) {
                return;
            }
            showDistTooltip(track);
        });

        rootEl.addEventListener("mouseout", (e) => {
            const track = e.target.closest(".invgate-stat-bars__track[data-ticket-ids]");
            if (!track) {
                return;
            }
            const related = e.relatedTarget;
            if (related instanceof Node && track.contains(related)) {
                return;
            }
            hideDistTooltip();
        });

        rootEl.addEventListener("focusin", (e) => {
            const track = e.target.closest(".invgate-stat-bars__track[data-ticket-ids]");
            if (!track || !rootEl.contains(track)) {
                return;
            }
            showDistTooltip(track);
        });

        rootEl.addEventListener("focusout", (e) => {
            const track = e.target.closest(".invgate-stat-bars__track[data-ticket-ids]");
            if (!track) {
                return;
            }
            const related = e.relatedTarget;
            if (related instanceof Node && track.contains(related)) {
                return;
            }
            hideDistTooltip();
        });

        window.addEventListener(
            "scroll",
            () => {
                if (distTooltipAnchor && distTooltipEl && !distTooltipEl.hidden) {
                    positionDistTooltip(distTooltipAnchor);
                }
            },
            true
        );
    }

    function formatOldestTicketHint(oldest) {
        if (!oldest || oldest.invgate_incident_id == null) {
            return "";
        }
        const parts = [`#${oldest.invgate_incident_id}`];
        if (oldest.person_display_name) {
            parts.push(C.escapeHtml(String(oldest.person_display_name)));
        }
        return parts.join(" · ");
    }

    function renderRankingList(items, formatLine) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<p class="muted">Sin datos.</p>';
        }
        return `<ol class="invgate-stats-ranking">
            ${items.map((item, i) => `<li>${formatLine(item, i)}</li>`).join("")}
        </ol>`;
    }

    function renderOrphanTicketsList(orphans) {
        if (!orphans || typeof orphans !== "object") {
            return "";
        }
        const tickets = Array.isArray(orphans.tickets) ? orphans.tickets : [];
        if (tickets.length === 0) {
            return '<p class="muted invgate-stats__empty">No hay tickets huérfanos abiertos.</p>';
        }
        return `<ul class="invgate-stats-orphan-list">
            ${tickets
                .map((t) => {
                    const id = t.invgate_incident_id != null ? `#${t.invgate_incident_id}` : "—";
                    const age =
                        t.age_days != null ? `${C.formatNumber(t.age_days, 1)} d` : "—";
                    const cat = t.category_name ? C.escapeHtml(String(t.category_name)) : "—";
                    const typ = t.type_name ? C.escapeHtml(String(t.type_name)) : "—";
                    return `<li><strong>${C.escapeHtml(id)}</strong> — ${age} · ${cat} · ${typ}</li>`;
                })
                .join("")}
        </ul>`;
    }

    function renderLoadBalanceSection(loadBalance, staleDays) {
        const lb = loadBalance && typeof loadBalance === "object" ? loadBalance : {};
        const underloaded = Array.isArray(lb.underloaded) ? lb.underloaded : [];
        const underHtml = renderRankingList(underloaded, (p) => {
            const name = C.escapeHtml(String(p.display_name || "—"));
            return `<strong>${name}</strong> — carga ${C.formatNumber(p.weighted_load)}, ${C.formatNumber(p.open_count)} abiertos, ${C.formatNumber(p.stale_count)} stale`;
        });

        const imbalance =
            lb.imbalance_ratio != null ? C.formatNumber(lb.imbalance_ratio, 2) : "—";
        const concentration =
            lb.concentration_pct != null ? C.formatPct(lb.concentration_pct) : "—";
        const avgHint =
            lb.avg_weighted_load != null
                ? `Promedios: carga ${C.formatNumber(lb.avg_weighted_load, 2)}, abiertos ${C.formatNumber(lb.avg_open_count, 2)}, stale ${C.formatNumber(lb.avg_stale_count, 2)}`
                : "";

        return `<div class="invgate-stats-team__block">
            <h4 class="invgate-stats-section-title">Balanceo de carga</h4>
            <ul class="invgate-stats-metrics-list">
                ${renderMetricListItem("Ratio de desequilibrio", imbalance, "load_imbalance", staleDays)}
                ${renderMetricListItem("Concentración (persona más cargada)", concentration, "load_concentration", staleDays)}
            </ul>
            ${avgHint ? `<p class="muted invgate-stats__hint">${avgHint}</p>` : ""}
            <h5 class="invgate-stats-subtitle">${renderLabelWithHelp("Personas con capacidad relativa", "underloaded", staleDays)}</h5>
            ${underHtml}
        </div>`;
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
        const topHtml = renderRankingList(top, (p) => {
            const name = C.escapeHtml(String(p.display_name || "—"));
            return `<strong>${name}</strong> — carga ${C.formatNumber(p.weighted_load)}, ${C.formatNumber(p.open_count)} abiertos`;
        });

        const maxAgeVal =
            summary.backlog_age_max_days != null
                ? `${C.formatNumber(summary.backlog_age_max_days, 1)} d`
                : "—";
        const oldestHint = formatOldestTicketHint(summary.oldest_open_ticket);

        const teamDist = summary.distributions || {};
        const openTotal = Number(summary.open_total) || 0;

        const orphans = summary.orphans || {};
        const orphanCount = Number(orphans.open_count) || 0;
        const orphanLoad = Number(orphans.weighted_load) || 0;
        const orphanMaxAge =
            orphans.backlog_age_max_days != null
                ? `${C.formatNumber(orphans.backlog_age_max_days, 1)} d`
                : "—";
        const orphanOldestHint = formatOldestTicketHint(orphans.oldest_open_ticket);

        return `<section class="invgate-stats-team">
            <h3 class="invgate-stats-team__title">Resumen del equipo</h3>
            <div class="invgate-stats-kpis">
                ${renderStatCard("Abiertos (total)", C.formatNumber(summary.open_total), { helpKey: "open_total" })}
                ${renderStatCard("Carga ponderada", C.formatNumber(summary.weighted_load_total), { helpKey: "weighted_load_total" })}
                ${renderStatCard("Edad promedio", summary.backlog_age_avg_total != null ? `${C.formatNumber(summary.backlog_age_avg_total, 1)} d` : "—", { helpKey: "backlog_age_avg_total" })}
                ${renderStatCard("Ticket más antiguo", maxAgeVal, { helpKey: "backlog_age_max", hint: oldestHint || undefined })}
                ${renderStatCard("Stale (total)", C.formatNumber(summary.stale_total), { helpKey: "stale_total", staleDays, hint: "sin movimiento" })}
                ${renderStatCard("Resueltos 30d", C.formatNumber(summary.resolved_30d_total), { helpKey: "resolved_30d_total" })}
            </div>
            <div class="invgate-stats-team__ranking">
                <h4 class="invgate-stats-section-title">${renderLabelWithHelp("Top 3 por carga ponderada", "top_by_load")}</h4>
                ${topHtml}
            </div>
            ${renderLoadBalanceSection(summary.load_balance, staleDays)}
            <div class="invgate-stats-team__block">
                ${renderSectionTitle("Carga por categoría (equipo)", "team_dist_category", staleDays)}
                ${renderDistributionBars(teamDist.by_category, openTotal)}
            </div>
            <div class="invgate-stats-team__block">
                ${renderSectionTitle("Carga por tipo (equipo)", "team_dist_type", staleDays)}
                ${renderDistributionBars(teamDist.by_type, openTotal)}
            </div>
            <div class="invgate-stats-team__block">
                <h4 class="invgate-stats-section-title">${renderLabelWithHelp("Tickets huérfanos", "orphans", staleDays)}</h4>
                <div class="invgate-stats-kpis invgate-stats-kpis--compact">
                    ${renderStatCard("Abiertos sin asignar", C.formatNumber(orphanCount), { helpKey: "orphans" })}
                    ${renderStatCard("Carga ponderada", C.formatNumber(orphanLoad), { helpKey: "weighted_load" })}
                    ${renderStatCard("Más antiguo", orphanMaxAge, { helpKey: "backlog_age_max", hint: orphanOldestHint || undefined })}
                </div>
                ${renderOrphanTicketsList(orphans)}
            </div>
        </section>`;
    }

    function renderPersonKpis(current, staleDays) {
        return `<div class="invgate-stats-kpis invgate-stats-kpis--person">
            ${renderStatCard("Abiertos", C.formatNumber(current.open_count), { helpKey: "open_count" })}
            ${renderStatCard("Carga ponderada", C.formatNumber(current.weighted_load), { helpKey: "weighted_load" })}
            ${renderStatCard("Edad promedio", current.backlog_age_avg_days != null ? `${C.formatNumber(current.backlog_age_avg_days, 1)} d` : "—", { helpKey: "backlog_age_avg" })}
            ${renderStatCard("Stale", C.formatNumber(current.stale_count), { helpKey: "stale_count", staleDays })}
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
        const maxAgeVal =
            current.backlog_age_max_days != null
                ? `${C.formatNumber(current.backlog_age_max_days, 1)} d`
                : "—";
        const oldest = current.oldest_open_ticket;
        const oldestVal =
            oldest && oldest.invgate_incident_id != null
                ? `${maxAgeVal} <span class="muted">(#${C.escapeHtml(String(oldest.invgate_incident_id))})</span>`
                : maxAgeVal;

        return `<div class="invgate-stats-detail">
            <section class="invgate-stats-detail__block">
                <h4 class="invgate-stats-section-title">Aging</h4>
                <ul class="invgate-stats-metrics-list">
                    ${renderMetricListItem("Ticket más antiguo", oldestVal, "backlog_age_max", staleDays)}
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
        const kpisHtml = renderPersonKpis(personRow.current || {}, staleDays);

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
            bindDistributionTooltips();
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
