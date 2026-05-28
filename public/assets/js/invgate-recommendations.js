/**
 * InvGate: lista plana de tickets + recomendación IA por ticket.
 */
(function () {
    const listApiUrl = "api/invgate-recommendations.php";
    const detailApiUrl = "api/invgate-recommendation.php";
    const C = window.InvgateCommon;
    const loadingEl = document.getElementById("invgateRecLoading");
    const metaEl = document.getElementById("invgateRecMeta");
    const rootEl = document.getElementById("invgateRecRoot");
    const detailEl = document.getElementById("invgateRecDetail");
    const panelEl = document.getElementById("invgatePanelRecommendations");

    let selectedTicketId = null;
    let hasLoaded = false;
    let detailRequestSeq = 0;

    function formatCell(value) {
        return C.formatCell(value);
    }

    function escapeHtml(s) {
        return C.escapeHtml(s);
    }

    function formatTimestamp(raw) {
        return C.formatTimestamp(raw);
    }

    function closeDetail() {
        selectedTicketId = null;
        if (detailEl) {
            detailEl.hidden = true;
            detailEl.innerHTML = "";
        }
        if (rootEl) {
            rootEl.querySelectorAll(".invgate-table__row--active").forEach((row) => {
                row.classList.remove("invgate-table__row--active");
            });
        }
    }

    function renderMeta(meta) {
        if (!metaEl) {
            return;
        }
        const total = Number(meta && meta.ticket_total ? meta.ticket_total : 0);
        const withRecommendation = Number(
            meta && meta.with_recommendation ? meta.with_recommendation : 0
        );
        metaEl.textContent = `${total} tickets abiertos · ${withRecommendation} con recomendación`;
        metaEl.hidden = false;
    }

    function renderList(tickets, meta) {
        if (!rootEl) {
            return;
        }
        if (!Array.isArray(tickets) || tickets.length === 0) {
            rootEl.hidden = false;
            rootEl.innerHTML =
                '<p class="muted">No hay tickets abiertos para mostrar.</p>';
            closeDetail();
            renderMeta(meta || { ticket_total: 0, with_recommendation: 0 });
            return;
        }

        rootEl.innerHTML = `<div class="invgate-rec-table-wrap">
            <table class="data-table invgate-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Persona</th>
                        <th>Estado</th>
                        <th>Últ. update</th>
                        <th>Recomendación</th>
                    </tr>
                </thead>
                <tbody>
                    ${tickets
                        .map((ticket) => {
                            const id = ticket.id != null ? Number(ticket.id) : 0;
                            const active =
                                selectedTicketId != null && selectedTicketId === id;
                            const hasRecommendation = !!ticket.has_recommendation;
                            const recommendationCell = hasRecommendation
                                ? formatTimestamp(ticket.generated_at)
                                : "Pendiente";
                            return `<tr class="invgate-table__row${
                                active ? " invgate-table__row--active" : ""
                            }" data-ticket-id="${id > 0 ? id : ""}" tabindex="0" role="button" aria-label="Ver recomendación IA del ticket">
                                <td>${formatCell(ticket.invgate_incident_id)}</td>
                                <td><strong>${formatCell(ticket.title)}</strong></td>
                                <td>${formatCell(ticket.person_display_name)}</td>
                                <td>${formatCell(ticket.status_name || ticket.status_id)}</td>
                                <td>${formatTimestamp(ticket.last_update)}</td>
                                <td>${formatCell(recommendationCell)}</td>
                            </tr>`;
                        })
                        .join("")}
                </tbody>
            </table>
        </div>`;
        rootEl.hidden = false;
        bindRows();
        renderMeta(meta || { ticket_total: tickets.length, with_recommendation: 0 });
    }

    function renderDetailLoading(ticketId) {
        if (!detailEl) {
            return;
        }
        detailEl.hidden = false;
        detailEl.innerHTML = `<p class="invgate-detail__loading muted">Cargando recomendación para ticket #${escapeHtml(
            String(ticketId)
        )}…</p>`;
    }

    function renderDetail(ticket, recommendation) {
        if (!detailEl) {
            return;
        }
        const hasRecommendation =
            recommendation &&
            recommendation.summary &&
            recommendation.recommendation;
        detailEl.hidden = false;
        detailEl.innerHTML = `<div class="invgate-detail__head">
            <h3 class="invgate-detail__title">#${formatCell(
                ticket.invgate_incident_id
            )} — ${formatCell(ticket.title)}</h3>
        </div>
        <p class="invgate-detail__meta muted">Estado: ${formatCell(
            ticket.status_name || ticket.status_id
        )}</p>
        ${
            hasRecommendation
                ? `<section class="invgate-detail__section">
                <h4 class="invgate-detail__section-title">Resumen</h4>
                <p class="invgate-detail__text">${formatCell(recommendation.summary)}</p>
            </section>
            <section class="invgate-detail__section">
                <h4 class="invgate-detail__section-title">Recomendación</h4>
                <p class="invgate-detail__text">${formatCell(
                    recommendation.recommendation
                )}</p>
            </section>
            <p class="invgate-rec-meta muted">Generado: ${formatTimestamp(
                recommendation.generated_at
            )} · Modelo: ${formatCell(recommendation.model)}</p>`
                : '<p class="invgate-detail__empty muted">No hay recomendación todavía para este ticket. Se generará en la próxima ejecución del cron.</p>'
        }`;
    }

    async function openDetail(ticketId, rowEl) {
        if (!rootEl) {
            return;
        }
        selectedTicketId = ticketId;
        rootEl.querySelectorAll(".invgate-table__row").forEach((row) => {
            row.classList.toggle("invgate-table__row--active", row === rowEl);
        });
        renderDetailLoading(ticketId);

        const seq = ++detailRequestSeq;
        try {
            const teamId = C.getTeamId();
            const res = await fetch(
                `${detailApiUrl}?id=${encodeURIComponent(
                    String(ticketId)
                )}&team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (seq !== detailRequestSeq) {
                return;
            }
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo cargar la recomendación");
            }
            renderDetail(data.ticket, data.recommendation);
        } catch (e) {
            if (seq !== detailRequestSeq || !detailEl) {
                return;
            }
            detailEl.hidden = false;
            detailEl.innerHTML = `<p class="invgate-detail__empty muted">${
                e instanceof Error ? escapeHtml(e.message) : "Error al cargar."
            }</p>`;
        }
    }

    function bindRows() {
        if (!rootEl) {
            return;
        }
        rootEl.querySelectorAll(".invgate-table__row").forEach((row) => {
            const id = Number(row.getAttribute("data-ticket-id") || "0");
            if (id <= 0) {
                return;
            }
            row.addEventListener("click", () => openDetail(id, row));
            row.addEventListener("keydown", (e) => {
                if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    openDetail(id, row);
                }
            });
        });
    }

    async function load(force) {
        if (!panelEl || panelEl.hidden) {
            return;
        }
        if (!force && hasLoaded) {
            return;
        }
        const teamId = C.getTeamId();
        if (teamId < 1) {
            if (loadingEl) {
                loadingEl.hidden = false;
                loadingEl.textContent = "No se pudo determinar el equipo de trabajo.";
            }
            return;
        }

        if (loadingEl) {
            loadingEl.hidden = false;
            loadingEl.textContent = "Cargando…";
        }
        if (rootEl) {
            rootEl.hidden = true;
        }
        if (metaEl) {
            metaEl.hidden = true;
        }

        try {
            const res = await fetch(
                `${listApiUrl}?team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudieron cargar los tickets");
            }
            hasLoaded = true;
            if (loadingEl) {
                loadingEl.hidden = true;
            }
            renderList(data.tickets, data.meta);
        } catch (e) {
            if (loadingEl) {
                loadingEl.hidden = false;
                loadingEl.textContent =
                    e instanceof Error ? e.message : "Error al cargar.";
            }
            if (rootEl) {
                rootEl.hidden = true;
            }
            if (metaEl) {
                metaEl.hidden = true;
            }
        }
    }

    function reset() {
        hasLoaded = false;
        closeDetail();
        if (rootEl) {
            rootEl.innerHTML = "";
            rootEl.hidden = true;
        }
        if (metaEl) {
            metaEl.hidden = true;
        }
        if (loadingEl) {
            loadingEl.hidden = false;
            loadingEl.textContent = "Seleccioná esta pestaña para cargar recomendaciones.";
        }
    }

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && selectedTicketId != null) {
            closeDetail();
        }
    });

    window.InvgateRecommendations = {
        load,
        reset,
    };
})();
