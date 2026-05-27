/**
 * InvGate: listado de tickets agrupados por persona (solo lectura).
 */
(function () {
    const apiUrl = "api/invgate-tickets.php";
    const ticketApiUrl = "api/invgate-ticket.php";
    const loadingEl = document.getElementById("invgateLoading");
    const metaEl = document.getElementById("invgateMeta");
    const rootEl = document.getElementById("invgateRoot");
    const detailEl = document.getElementById("invgateDetail");

    let selectedTicketId = null;
    let detailRequestSeq = 0;

    const chevronSvg =
        '<svg class="icon icon--chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    let groupIdSeq = 0;

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
                return `<tr class="invgate-table__row${active ? " invgate-table__row--active" : ""}" data-ticket-id="${id > 0 ? id : ""}" tabindex="0" role="button" aria-label="Ver detalle del ticket">
            <td>${formatCell(t.invgate_incident_id)}</td>
            <td><strong>${formatCell(t.title)}</strong></td>
            <td>${statusCell}</td>
            <td>${typeCell}</td>
            <td>${formatCell(t.priority)}</td>
            <td>${categoryCell}</td>
            <td>${formatCell(t.user_id)}</td>
            <td>${formatTimestamp(t.created_at)}</td>
            <td>${formatTimestamp(t.last_update)}</td>
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
        const teamId = getTeamId();
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
                <th>Solicitante</th>
                <th>Creado</th>
                <th>Última actualización</th>
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

    function renderGroup(person, tickets) {
        const name = escapeHtml(person.display_name || "Sin nombre");
        const count = tickets.length;
        const countLabel = count === 1 ? "1 ticket" : `${count} tickets`;
        const titleHtml = `${name}${personSubtitle(person)} <span class="invgate-group__count muted">— ${countLabel}</span>`;
        let body;
        if (count === 0) {
            body = '<p class="muted invgate-group__empty">Sin tickets sincronizados.</p>';
        } else {
            body = renderTicketTable(tickets);
        }
        return renderGroupShell(titleHtml, body, { collapsed: true });
    }

    function renderOrphans(tickets) {
        if (!tickets.length) {
            return "";
        }
        const countLabel =
            tickets.length === 1 ? "1 ticket" : `${tickets.length} tickets`;
        const titleHtml = `Sin persona asignada <span class="invgate-group__count muted">— ${countLabel}</span>`;
        return renderGroupShell(titleHtml, renderTicketTable(tickets), {
            extraClass: "invgate-group--orphan",
            collapsed: true,
        });
    }

    function renderMeta(meta, groupCount) {
        if (!metaEl) {
            return;
        }
        const total =
            meta && typeof meta.ticket_total === "number" ? meta.ticket_total : 0;
        const withId =
            meta && typeof meta.people_with_invgate_id === "number"
                ? meta.people_with_invgate_id
                : 0;
        metaEl.textContent = `${total} ticket${total === 1 ? "" : "s"} en total · ${groupCount} persona${groupCount === 1 ? "" : "s"} · ${withId} con ID InvGate`;
        metaEl.hidden = false;
    }

    function renderAll(groups, orphanTickets, meta) {
        if (!rootEl) {
            return;
        }
        const groupList = Array.isArray(groups) ? groups : [];
        const orphans = Array.isArray(orphanTickets) ? orphanTickets : [];
        const hasGroups = groupList.length > 0;
        const hasOrphans = orphans.length > 0;

        if (!hasGroups && !hasOrphans) {
            rootEl.innerHTML =
                '<p class="muted">Ningún ticket en la base de datos. Configurá el ID InvGate en <strong>Editar fichas</strong> y ejecutá <code>php database/sync_invgate_tickets.php</code>.</p>';
            rootEl.hidden = false;
            if (metaEl) {
                metaEl.hidden = true;
            }
            return;
        }

        groupIdSeq = 0;
        const sections = groupList
            .map((g) => {
                const person = g.person && typeof g.person === "object" ? g.person : {};
                const tickets = Array.isArray(g.tickets) ? g.tickets : [];
                return renderGroup(person, tickets);
            })
            .join("");
        closeDetail();
        rootEl.innerHTML = sections + renderOrphans(orphans);
        rootEl.hidden = false;
        bindGroupToggles();
        bindTicketRows();
        renderMeta(meta, groupList.length);
    }

    async function loadTickets() {
        const teamId = getTeamId();
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
            renderAll(data.groups, data.orphan_tickets, data.meta);
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

    loadTickets();
})();
