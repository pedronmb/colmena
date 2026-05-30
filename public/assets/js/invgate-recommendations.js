/**
 * InvGate: modal de análisis IA (resumen + recomendación por ticket).
 */
(function () {
    const detailApiUrl = "api/invgate-recommendation.php";
    const C = window.InvgateCommon;
    const modalEl = document.getElementById("invgateAiModal");
    const bodyEl = document.getElementById("invgateAiModalBody");

    let detailRequestSeq = 0;
    let modalWired = false;

    function escapeHtml(s) {
        return C.escapeHtml(s);
    }

    function formatCell(value) {
        return C.formatCell(value);
    }

    function formatTimestamp(raw) {
        return C.formatTimestamp(raw);
    }

    function closeModal() {
        if (!modalEl) {
            return;
        }
        modalEl.hidden = true;
        detailRequestSeq += 1;
        if (bodyEl) {
            bodyEl.innerHTML = "";
        }
    }

    function wireModalOnce() {
        if (modalWired || !modalEl) {
            return;
        }
        modalWired = true;
        modalEl.addEventListener("click", (e) => {
            const t = e.target;
            if (t instanceof Element && t.closest("[data-invgate-ai-close]")) {
                closeModal();
            }
        });
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && modalEl && !modalEl.hidden) {
                closeModal();
            }
        });
    }

    function renderTicketHead(ticket) {
        return `<div class="invgate-detail__head invgate-ai-modal__ticket-head">
            <h3 class="invgate-detail__title">#${formatCell(
                ticket.invgate_incident_id
            )} — ${formatCell(ticket.title)}</h3>
        </div>
        <p class="invgate-detail__meta muted">Estado: ${formatCell(
            ticket.status_name || ticket.status_id
        )}</p>`;
    }

    function renderLoading(ticketId) {
        if (!bodyEl) {
            return;
        }
        bodyEl.innerHTML = `<p class="invgate-detail__loading muted">Cargando análisis para ticket #${escapeHtml(
            String(ticketId)
        )}…</p>`;
    }

    function renderContent(ticket, recommendation) {
        if (!bodyEl) {
            return;
        }
        const hasRecommendation =
            recommendation &&
            recommendation.summary &&
            recommendation.recommendation;

        bodyEl.innerHTML = `${renderTicketHead(ticket)}
        <div class="invgate-ai-modal__content">
        ${
            hasRecommendation
                ? `<section class="invgate-ai-modal__block">
                <h4 class="invgate-detail__section-title">Resumen</h4>
                <p class="invgate-detail__text">${formatCell(recommendation.summary)}</p>
            </section>
            <section class="invgate-ai-modal__block invgate-ai-modal__block--accent">
                <h4 class="invgate-detail__section-title">Recomendación</h4>
                <p class="invgate-detail__text">${formatCell(
                    recommendation.recommendation
                )}</p>
            </section>
            <p class="invgate-ai-modal__footer muted">Generado: ${formatTimestamp(
                recommendation.generated_at
            )} · Modelo: ${formatCell(recommendation.model)}</p>`
                : `<p class="invgate-detail__empty muted">No hay recomendación todavía para este ticket. Se generará en la próxima ejecución del cron.</p>`
        }
        </div>`;
    }

    function renderError(message) {
        if (!bodyEl) {
            return;
        }
        bodyEl.innerHTML = `<p class="invgate-detail__empty muted">${escapeHtml(message)}</p>`;
    }

    async function openForTicket(ticketId) {
        const id = Number(ticketId);
        if (!Number.isFinite(id) || id < 1 || !modalEl || !bodyEl) {
            return;
        }

        wireModalOnce();
        modalEl.hidden = false;
        renderLoading(id);

        const seq = ++detailRequestSeq;
        try {
            const teamId = C.getTeamId();
            const res = await fetch(
                `${detailApiUrl}?id=${encodeURIComponent(String(id))}&team_id=${encodeURIComponent(String(teamId))}`,
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
                throw new Error(data.error || "No se pudo cargar el análisis IA");
            }
            renderContent(data.ticket, data.recommendation);
        } catch (e) {
            if (seq !== detailRequestSeq) {
                return;
            }
            renderError(e instanceof Error ? e.message : "Error al cargar.");
        }
    }

    window.InvgateRecommendations = {
        openForTicket,
    };
})();
