/**
 * InvGate: listado de tickets agrupados por persona (solo lectura).
 */
(function () {
    const apiUrl = "api/invgate-tickets.php";
    const ticketApiUrl = "api/invgate-ticket.php";
    const C = window.InvgateCommon;
    const loadingEl = document.getElementById("invgateLoading");
    const metaEl = document.getElementById("invgateMeta");
    const rootEl = document.getElementById("invgateRoot");
    const detailEl = document.getElementById("invgateDetail");
    const searchInput = document.getElementById("invgateTicketSearch");

    let selectedTicketId = null;
    let detailRequestSeq = 0;
    let cachedGroups = [];
    let cachedOrphanTickets = [];
    let cachedMeta = null;

    const chevronSvg =
        '<svg class="icon icon--chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    let groupIdSeq = 0;

    function escapeHtml(s) {
        return C.escapeHtml(s);
    }

    function formatCell(value) {
        return C.formatCell(value);
    }

    function formatTimestamp(raw) {
        return C.formatTimestamp(raw);
    }

    function personSubtitle(person) {
        const bits = [];
        if (person.invgate_id != null && person.invgate_id !== "") {
            bits.push(`ID InvGate: ${escapeHtml(String(person.invgate_id))}`);
        }
        if (person.role) {
            bits.push(escapeHtml(String(person.role)));
        }
        if (person.email) {
            bits.push(escapeHtml(String(person.email)));
        }
        return bits.length ? ` <span class="muted invgate-group__meta">${bits.join(" · ")}</span>` : "";
    }

    function renderTicketRows(tickets) {
        return tickets
            .map((t) => {
                const id = t.id != null ? Number(t.id) : 0;
                const active =
                    selectedTicketId != null && id > 0 && id === selectedTicketId;
                const statusCell =
                    t.status_name != null && t.status_name !== ""
                        ? formatCell(t.status_name)
                        : formatCell(t.status_id);
                const typeCell =
                    t.type_name != null && t.type_name !== ""
                        ? formatCell(t.type_name)
                        : formatCell(t.type_id);
                const categoryCell =
                    t.category_name != null && t.category_name !== ""
                        ? formatCell(t.category_name)
                        : formatCell(t.category_id);
                const incidentLabel =
                    t.invgate_incident_id != null && t.invgate_incident_id !== ""
                        ? String(t.invgate_incident_id)
                        : id > 0
                          ? String(id)
                          : "";
                return `<tr class="invgate-table__row${active ? " invgate-table__row--active" : ""}" data-ticket-id="${id > 0 ? id : ""}" tabindex="0" role="button" aria-label="Ver detalle del ticket">
            <td>${formatCell(t.invgate_incident_id)}</td>
            <td><strong>${formatCell(t.title)}</strong></td>
            <td>${statusCell}</td>
            <td>${typeCell}</td>
            <td>${formatCell(t.priority)}</td>
            <td>${categoryCell}</td>
            <td class="invgate-table__date">${formatTimestamp(t.created_at)}</td>
            <td class="invgate-table__date">${formatTimestamp(t.last_update)}</td>
            <td class="invgate-table__actions">
                <button type="button" class="btn btn--small invgate-ai-btn" data-ticket-id="${id > 0 ? id : ""}" aria-label="Análisis IA del ticket #${escapeHtml(incidentLabel)}">Análisis IA</button>
            </td>
        </tr>`;
            })
            .join("");
    }

    function sanitizeInvgateHtml(html) {
        const template = document.createElement("template");
        template.innerHTML = String(html);
        template.content
            .querySelectorAll("script, iframe, object, embed, form, style, link, meta")
            .forEach((el) => el.remove());
        template.content.querySelectorAll("*").forEach((el) => {
            [...el.attributes].forEach((attr) => {
                const name = attr.name.toLowerCase();
                const val = attr.value.trim();
                if (
                    name.startsWith("on") ||
                    ((name === "href" || name === "src") && /^javascript:/i.test(val))
                ) {
                    el.removeAttribute(attr.name);
                }
            });
        });
        return template.innerHTML;
    }

    function formatHtmlContent(value) {
        if (value === null || value === undefined || value === "") {
            return '<p class="muted">Sin contenido.</p>';
        }
        return `<div class="invgate-detail__text invgate-detail__html">${sanitizeInvgateHtml(String(value))}</div>`;
    }

    function renderComments(comments) {
        if (!Array.isArray(comments) || comments.length === 0) {
            return '<p class="muted invgate-detail__empty">Sin comentarios sincronizados. Ejecutá <code>php database/sync_invgate_comments.php</code>.</p>';
        }
        return `<ol class="invgate-comments">
            ${comments
                .map((c) => {
                    const solution = c.is_solution
                        ? ' <span class="invgate-comments__badge">Solución</span>'
                        : "";
                    const author =
                        c.author_id != null && c.author_id !== ""
                            ? `Autor #${escapeHtml(String(c.author_id))}`
                            : "Autor desconocido";
                    return `<li class="invgate-comments__item">
                <header class="invgate-comments__meta muted">
                    <span>${escapeHtml(author)} · ${formatTimestamp(c.created_at)}</span>${solution}
                </header>
                ${formatHtmlContent(c.message)}
            </li>`;
                })
                .join("")}
        </ol>`;
    }

    function renderDetail(ticket, comments) {
        const incidentId = formatCell(ticket.invgate_incident_id);
        const title = formatCell(ticket.title);
        const statusLabel =
            ticket.status_name != null && ticket.status_name !== ""
                ? `Estado ${escapeHtml(String(ticket.status_name))}`
                : ticket.status_id != null
                  ? `Estado #${escapeHtml(String(ticket.status_id))}`
                  : null;
        const typeLabel =
            ticket.type_name != null && ticket.type_name !== ""
                ? `Tipo ${escapeHtml(String(ticket.type_name))}`
                : ticket.type_id != null
                  ? `Tipo #${escapeHtml(String(ticket.type_id))}`
                  : null;
        const categoryLabel =
            ticket.category_name != null && ticket.category_name !== ""
                ? `Categoría ${escapeHtml(String(ticket.category_name))}`
                : ticket.category_id != null
                  ? `Categoría #${escapeHtml(String(ticket.category_id))}`
                  : null;
        const metaBits = [
            ticket.priority != null ? `Prioridad ${escapeHtml(String(ticket.priority))}` : null,
            statusLabel,
            typeLabel,
            categoryLabel,
            `Actualizado ${formatTimestamp(ticket.last_update)}`,
        ].filter(Boolean);

        return `<div class="invgate-detail__head">
                <h3 class="invgate-detail__title">#${incidentId} — ${title}</h3>
                <button type="button" class="btn invgate-detail__close" id="invgateDetailClose" aria-label="Cerrar detalle">Cerrar</button>
            </div>
            <p class="invgate-detail__meta muted">${metaBits.join(" · ")}</p>
            <section class="invgate-detail__section">
                <h4 class="invgate-detail__section-title">Descripción</h4>
                ${formatHtmlContent(ticket.description)}
            </section>
            <section class="invgate-detail__section">
                <h4 class="invgate-detail__section-title">Comentarios</h4>
                ${renderComments(comments)}
            </section>`;
    }

    function setDetailLoading(ticketId) {
        if (!detailEl) {
            return;
        }
        detailEl.hidden = false;
        detailEl.innerHTML = `<p class="muted invgate-detail__loading">Cargando ticket #${escapeHtml(String(ticketId))}…</p>`;
        detailEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }

    function closeDetail() {
        selectedTicketId = null;
        detailRequestSeq += 1;
        if (detailEl) {
            detailEl.hidden = true;
            detailEl.innerHTML = "";
        }
        rootEl?.querySelectorAll(".invgate-table__row--active").forEach((row) => {
            row.classList.remove("invgate-table__row--active");
        });
    }

    function highlightSelectedRow() {
        if (!rootEl) {
            return;
        }
        rootEl.querySelectorAll(".invgate-table__row").forEach((row) => {
            const id = Number(row.getAttribute("data-ticket-id"));
            row.classList.toggle(
                "invgate-table__row--active",
                selectedTicketId != null && id === selectedTicketId
            );
        });
    }

    async function openTicketDetail(ticketId) {
        const teamId = C.getTeamId();
        if (ticketId < 1 || teamId < 1) {
            return;
        }
        if (selectedTicketId === ticketId) {
            closeDetail();
            return;
        }

        selectedTicketId = ticketId;
        highlightSelectedRow();
        setDetailLoading(ticketId);

        const requestId = ++detailRequestSeq;
        try {
            const res = await fetch(
                `${ticketApiUrl}?id=${encodeURIComponent(String(ticketId))}&team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (requestId !== detailRequestSeq || selectedTicketId !== ticketId) {
                return;
            }
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo cargar el ticket");
            }
            if (!detailEl) {
                return;
            }
            detailEl.hidden = false;
            detailEl.innerHTML = renderDetail(data.ticket, data.comments);
            detailEl.querySelector("#invgateDetailClose")?.addEventListener("click", closeDetail);
            detailEl.scrollIntoView({ behavior: "smooth", block: "nearest" });
        } catch (e) {
            if (requestId !== detailRequestSeq || selectedTicketId !== ticketId) {
                return;
            }
            if (detailEl) {
                detailEl.hidden = false;
                detailEl.innerHTML = `<p class="form-error" role="alert">${
                    e instanceof Error ? escapeHtml(e.message) : "Error al cargar el ticket."
                }</p>
                <button type="button" class="btn invgate-detail__close" id="invgateDetailClose">Cerrar</button>`;
                detailEl.querySelector("#invgateDetailClose")?.addEventListener("click", closeDetail);
            }
        }
    }

    function bindTicketRows() {
        if (!rootEl || rootEl.dataset.invgateRowBound === "1") {
            return;
        }
        rootEl.dataset.invgateRowBound = "1";
        rootEl.addEventListener("click", (e) => {
            if (e.target.closest(".invgate-table__actions")) {
                return;
            }
            const row = e.target.closest(".invgate-table__row");
            if (!row || !rootEl.contains(row)) {
                return;
            }
            const ticketId = Number(row.getAttribute("data-ticket-id"));
            if (!Number.isFinite(ticketId) || ticketId < 1) {
                return;
            }
            openTicketDetail(ticketId);
        });
        rootEl.addEventListener("keydown", (e) => {
            if (e.key !== "Enter" && e.key !== " ") {
                return;
            }
            if (e.target.closest(".invgate-table__actions")) {
                return;
            }
            const row = e.target.closest(".invgate-table__row");
            if (!row || !rootEl.contains(row)) {
                return;
            }
            e.preventDefault();
            const ticketId = Number(row.getAttribute("data-ticket-id"));
            if (Number.isFinite(ticketId) && ticketId > 0) {
                openTicketDetail(ticketId);
            }
        });
    }

    function bindAiButtons() {
        if (!rootEl || rootEl.dataset.invgateAiBound === "1") {
            return;
        }
        rootEl.dataset.invgateAiBound = "1";
        rootEl.addEventListener("click", (e) => {
            const btn = e.target.closest(".invgate-ai-btn");
            if (!btn || !rootEl.contains(btn)) {
                return;
            }
            e.stopPropagation();
            const ticketId = Number(btn.getAttribute("data-ticket-id"));
            if (!Number.isFinite(ticketId) || ticketId < 1) {
                return;
            }
            if (window.InvgateRecommendations?.openForTicket) {
                window.InvgateRecommendations.openForTicket(ticketId);
            }
        });
    }

    function renderTicketTable(tickets) {
        const rows = renderTicketRows(tickets);
        return `<table class="data-table invgate-table">
            <thead><tr>
                <th>#</th>
                <th>Título</th>
                <th>Estado</th>
                <th>Tipo</th>
                <th>Prioridad</th>
                <th>Categoría</th>
                <th class="invgate-table__date">Creado</th>
                <th class="invgate-table__date">Actualizado</th>
                <th class="invgate-table__actions-head">Análisis IA</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
    }

    function nextGroupDomId(prefix) {
        groupIdSeq += 1;
        return `${prefix}-${groupIdSeq}`;
    }

    function renderGroupShell(titleHtml, bodyHtml, options) {
        const collapsed = options.collapsed === true;
        const bodyId = nextGroupDomId("invgate-group-body");
        const expanded = !collapsed;
        const toggleTitle = expanded ? "Plegar tickets" : "Desplegar tickets";
        return `<section class="invgate-group${collapsed ? " invgate-group--collapsed" : ""}${options.extraClass ? ` ${options.extraClass}` : ""}">
            <div class="invgate-group__head">
                <button type="button" class="invgate-group__toggle panel-collapse-btn" aria-expanded="${expanded ? "true" : "false"}" aria-controls="${bodyId}" title="${toggleTitle}">
                    <span class="panel-collapse-btn__chevron" aria-hidden="true">${chevronSvg}</span>
                </button>
                <h3 class="invgate-group__title">
                    <button type="button" class="invgate-group__title-btn" aria-expanded="${expanded ? "true" : "false"}" aria-controls="${bodyId}">${titleHtml}</button>
                </h3>
            </div>
            <div id="${bodyId}" class="invgate-group__body">${bodyHtml}</div>
        </section>`;
    }

    function setGroupCollapsed(groupEl, collapsed) {
        groupEl.classList.toggle("invgate-group--collapsed", collapsed);
        const expanded = !collapsed;
        groupEl.querySelectorAll(".invgate-group__toggle, .invgate-group__title-btn").forEach((btn) => {
            btn.setAttribute("aria-expanded", expanded ? "true" : "false");
            btn.setAttribute("title", expanded ? "Plegar tickets" : "Desplegar tickets");
        });
    }

    function bindGroupToggles() {
        if (!rootEl || rootEl.dataset.invgateToggleBound === "1") {
            return;
        }
        rootEl.dataset.invgateToggleBound = "1";
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

    function getSearchQuery() {
        return searchInput ? searchInput.value : "";
    }

    function countTicketsInView(groups, orphanTickets) {
        let n = 0;
        (Array.isArray(groups) ? groups : []).forEach((g) => {
            n += Array.isArray(g.tickets) ? g.tickets.length : 0;
        });
        n += Array.isArray(orphanTickets) ? orphanTickets.length : 0;
        return n;
    }

    function ticketIdInView(ticketId, groups, orphanTickets) {
        if (ticketId == null || ticketId < 1) {
            return false;
        }
        const lists = [];
        (Array.isArray(groups) ? groups : []).forEach((g) => {
            if (Array.isArray(g.tickets)) {
                lists.push(...g.tickets);
            }
        });
        if (Array.isArray(orphanTickets)) {
            lists.push(...orphanTickets);
        }
        return lists.some((t) => Number(t.id) === ticketId);
    }

    function applySearch(groups, orphanTickets, rawQuery) {
        const q = String(rawQuery || "").trim();
        if (!q) {
            return {
                groups: Array.isArray(groups) ? groups : [],
                orphanTickets: Array.isArray(orphanTickets) ? orphanTickets : [],
                searching: false,
            };
        }
        const filteredGroups = (Array.isArray(groups) ? groups : [])
            .map((g) => ({
                person: g.person,
                tickets: C.filterTicketsBySearch(
                    Array.isArray(g.tickets) ? g.tickets : [],
                    q
                ),
            }))
            .filter((g) => g.tickets.length > 0);
        const filteredOrphans = C.filterTicketsBySearch(
            Array.isArray(orphanTickets) ? orphanTickets : [],
            q
        );
        return {
            groups: filteredGroups,
            orphanTickets: filteredOrphans,
            searching: true,
        };
    }

    function renderGroup(person, tickets, options) {
        const name =
            window.ColmenaPersonTeam?.personNameSpanHtml?.(person) ||
            escapeHtml(person.display_name || "Sin nombre");
        const count = tickets.length;
        const countLabel = count === 1 ? "1 ticket" : `${count} tickets`;
        const titleHtml = `${name}${personSubtitle(person)} <span class="invgate-group__count muted">— ${countLabel}</span>`;
        let body;
        if (count === 0) {
            body = '<p class="muted invgate-group__empty">Sin tickets sincronizados.</p>';
        } else {
            body = renderTicketTable(tickets);
        }
        const searching = options && options.searching === true;
        return renderGroupShell(titleHtml, body, { collapsed: !searching });
    }

    function renderOrphans(tickets, options) {
        if (!tickets.length) {
            return "";
        }
        const countLabel =
            tickets.length === 1 ? "1 ticket" : `${tickets.length} tickets`;
        const titleHtml = `Sin persona asignada <span class="invgate-group__count muted">— ${countLabel}</span>`;
        const searching = options && options.searching === true;
        return renderGroupShell(titleHtml, renderTicketTable(tickets), {
            extraClass: "invgate-group--orphan",
            collapsed: !searching,
        });
    }

    function renderMeta(meta, groupCount, viewOptions) {
        if (!metaEl) {
            return;
        }
        const total =
            meta && typeof meta.ticket_total === "number" ? meta.ticket_total : 0;
        const withId =
            meta && typeof meta.people_with_invgate_id === "number"
                ? meta.people_with_invgate_id
                : 0;
        const searching = viewOptions && viewOptions.searching === true;
        const shown =
            viewOptions && typeof viewOptions.shownTotal === "number"
                ? viewOptions.shownTotal
                : total;
        let ticketLabel = `${total} ticket${total === 1 ? "" : "s"} en total`;
        if (searching && shown !== total) {
            ticketLabel = `${shown} de ${total} tickets`;
        }
        metaEl.textContent = `${ticketLabel} · ${groupCount} persona${groupCount === 1 ? "" : "s"} · ${withId} con ID InvGate`;
        metaEl.hidden = false;
    }

    function renderAll(groups, orphanTickets, meta, viewOptions) {
        if (!rootEl) {
            return;
        }
        const groupList = Array.isArray(groups) ? groups : [];
        const orphans = Array.isArray(orphanTickets) ? orphanTickets : [];
        const searching = viewOptions && viewOptions.searching === true;
        const hasCachedData =
            (Array.isArray(cachedGroups) && cachedGroups.length > 0) ||
            (Array.isArray(cachedOrphanTickets) && cachedOrphanTickets.length > 0) ||
            (meta && typeof meta.ticket_total === "number" && meta.ticket_total > 0);

        if (!hasCachedData && groupList.length === 0 && orphans.length === 0) {
            rootEl.innerHTML =
                '<p class="muted">Ningún ticket en la base de datos. Configurá el ID InvGate en <strong>Editar fichas</strong> y ejecutá <code>php database/sync_invgate_tickets.php</code>.</p>';
            rootEl.hidden = false;
            if (metaEl) {
                metaEl.hidden = true;
            }
            return;
        }

        if (searching && groupList.length === 0 && orphans.length === 0) {
            rootEl.innerHTML =
                '<p class="muted">Ningún ticket coincide con la búsqueda.</p>';
            rootEl.hidden = false;
            if (selectedTicketId != null) {
                closeDetail();
            }
            renderMeta(meta, 0, viewOptions);
            return;
        }

        groupIdSeq = 0;
        const groupOptions = { searching };
        const sections = groupList
            .map((g) => {
                const person = g.person && typeof g.person === "object" ? g.person : {};
                const tickets = Array.isArray(g.tickets) ? g.tickets : [];
                return renderGroup(person, tickets, groupOptions);
            })
            .join("");
        if (
            selectedTicketId != null &&
            !ticketIdInView(selectedTicketId, groupList, orphans)
        ) {
            closeDetail();
        }
        rootEl.innerHTML = sections + renderOrphans(orphans, groupOptions);
        rootEl.hidden = false;
        bindGroupToggles();
        bindTicketRows();
        bindAiButtons();
        highlightSelectedRow();
        renderMeta(meta, groupList.length, viewOptions);
    }

    function rerenderFromCache() {
        const query = getSearchQuery();
        const filtered = applySearch(cachedGroups, cachedOrphanTickets, query);
        const shownTotal = countTicketsInView(filtered.groups, filtered.orphanTickets);
        renderAll(filtered.groups, filtered.orphanTickets, cachedMeta, {
            searching: filtered.searching,
            shownTotal,
        });
    }

    async function loadTickets() {
        const teamId = C.getTeamId();
        if (teamId < 1) {
            if (loadingEl) {
                loadingEl.textContent = "No se pudo determinar el equipo de trabajo.";
            }
            return;
        }
        if (loadingEl) {
            loadingEl.hidden = false;
        }
        if (rootEl) {
            rootEl.hidden = true;
        }
        if (metaEl) {
            metaEl.hidden = true;
        }
        try {
            const res = await fetch(
                `${apiUrl}?team_id=${encodeURIComponent(String(teamId))}`,
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
            if (loadingEl) {
                loadingEl.hidden = true;
            }
            cachedGroups = Array.isArray(data.groups) ? data.groups : [];
            cachedOrphanTickets = Array.isArray(data.orphan_tickets)
                ? data.orphan_tickets
                : [];
            cachedMeta = data.meta && typeof data.meta === "object" ? data.meta : null;
            rerenderFromCache();
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

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && selectedTicketId != null) {
            closeDetail();
        }
    });

    function activateInvgateTab(panelId) {
        const tabs = document.querySelectorAll(".invgate-tab");
        const panels = document.querySelectorAll(".invgate-panel");
        if (!panelId || !tabs.length || !panels.length) {
            return;
        }
        tabs.forEach((t) => {
            const active = t.getAttribute("aria-controls") === panelId;
            t.classList.toggle("invgate-tab--active", active);
            t.setAttribute("aria-selected", active ? "true" : "false");
        });
        panels.forEach((panel) => {
            panel.hidden = panel.id !== panelId;
        });
        if (panelId === "invgatePanelStats" && window.InvgateStats) {
            window.InvgateStats.loadStats(false);
        }
    }

    function ensureTicketVisibleInList(ticketId) {
        if (!rootEl || ticketId < 1) {
            return;
        }
        if (!ticketIdInView(ticketId, cachedGroups, cachedOrphanTickets)) {
            if (searchInput) {
                searchInput.value = "";
            }
            rerenderFromCache();
        }
        const row = rootEl.querySelector(
            `.invgate-table__row[data-ticket-id="${ticketId}"]`
        );
        if (!row) {
            return;
        }
        const group = row.closest(".invgate-group");
        if (group && group.classList.contains("invgate-group--collapsed")) {
            setGroupCollapsed(group, false);
        }
    }

    function navigateToTicket(ticketId) {
        const id = Number(ticketId);
        if (!Number.isFinite(id) || id < 1) {
            return;
        }
        activateInvgateTab("invgatePanelTickets");
        ensureTicketVisibleInList(id);
        selectedTicketId = null;
        openTicketDetail(id);
    }

    function initInvgateTabs() {
        const tabs = document.querySelectorAll(".invgate-tab");
        if (!tabs.length) {
            return;
        }

        tabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                const panelId = tab.getAttribute("aria-controls");
                if (!panelId) {
                    return;
                }
                activateInvgateTab(panelId);
            });
        });
    }

    searchInput?.addEventListener("input", () => {
        if (!cachedMeta) {
            return;
        }
        rerenderFromCache();
    });

    window.InvgateTickets = {
        navigateToTicket,
    };

    initInvgateTabs();
    loadTickets();
})();
