/**
 * Utilidades compartidas para InvGate (tickets y estadísticas).
 */
(function () {
    function getTeamId() {
        const appEl = document.getElementById("appPersonalTeamId");
        if (appEl) {
            const n = Number(appEl.value);
            if (Number.isFinite(n) && n > 0) {
                return n;
            }
        }
        return 0;
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function formatCell(value) {
        if (value === null || value === undefined || value === "") {
            return '<span class="muted">—</span>';
        }
        return escapeHtml(String(value));
    }

    function formatTimestamp(raw) {
        if (raw === null || raw === undefined || raw === "" || raw === "0") {
            return "—";
        }
        const s = String(raw).trim();
        const n = Number(s);
        if (Number.isFinite(n) && n > 1e9) {
            const d = new Date(n * 1000);
            if (!Number.isNaN(d.getTime())) {
                return d.toLocaleString("es-AR", {
                    dateStyle: "short",
                    timeStyle: "short",
                });
            }
        }
        if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
            const p = s.slice(0, 10).split("-");
            if (p.length === 3) {
                return `${p[2]}/${p[1]}/${p[0]}`;
            }
        }
        return escapeHtml(s);
    }

    function formatNumber(value, decimals) {
        if (value === null || value === undefined || value === "") {
            return "—";
        }
        const n = Number(value);
        if (!Number.isFinite(n)) {
            return "—";
        }
        if (decimals !== undefined) {
            return n.toLocaleString("es-AR", {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
        }
        return n.toLocaleString("es-AR");
    }

    function formatHours(hours) {
        if (hours === null || hours === undefined || hours === "") {
            return "—";
        }
        const n = Number(hours);
        if (!Number.isFinite(n)) {
            return "—";
        }
        if (n < 24) {
            return `${formatNumber(n, 1)} h`;
        }
        const days = n / 24;
        return `${formatNumber(days, 1)} d`;
    }

    function formatPct(value) {
        if (value === null || value === undefined || value === "") {
            return "—";
        }
        return `${formatNumber(value, 1)}%`;
    }

    /**
     * @param {object} ticket
     * @param {string} rawQuery
     */
    function ticketMatchesSearch(ticket, rawQuery) {
        const q = String(rawQuery || "")
            .trim()
            .toLowerCase();
        if (!q) {
            return true;
        }
        const incidentId =
            ticket.invgate_incident_id != null && ticket.invgate_incident_id !== ""
                ? String(ticket.invgate_incident_id).toLowerCase()
                : "";
        const title =
            ticket.title != null && ticket.title !== ""
                ? String(ticket.title).toLowerCase()
                : "";
        const hay = `${incidentId} ${title}`;
        const words = q.split(/\s+/).filter(Boolean);
        return words.every((w) => hay.includes(w));
    }

    /**
     * @param {object[]} tickets
     * @param {string} rawQuery
     */
    function filterTicketsBySearch(tickets, rawQuery) {
        if (!Array.isArray(tickets)) {
            return [];
        }
        const q = String(rawQuery || "").trim();
        if (!q) {
            return tickets;
        }
        return tickets.filter((t) => ticketMatchesSearch(t, q));
    }

    window.InvgateCommon = {
        getTeamId,
        escapeHtml,
        formatCell,
        formatTimestamp,
        formatNumber,
        formatHours,
        formatPct,
        ticketMatchesSearch,
        filterTicketsBySearch,
    };
})();
