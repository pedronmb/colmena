(function () {
    const C = window.InvgateCommon;
    const root = document.getElementById("invgatePriorityRoot");
    const loading = document.getElementById("invgatePriorityLoading");
    const personSelect = document.getElementById("invgatePriorityPerson");
    const retry = document.getElementById("invgatePriorityRetry");
    if (!C || !root || !personSelect) return;

    const api = "api/invgate-prioritization.php";
    const groups = new Map();
    const busy = new Set();
    let loaded = false;
    let fetching = false;
    const esc = (value) => C.escapeHtml(String(value ?? "")).replace(/"/g, "&quot;").replace(/'/g, "&#39;");

    function ticketRows(group) {
        return group.tickets.map((ticket) => {
            const label = `#${ticket.invgate_incident_id}`;
            const url = C.incidentUrl(ticket.invgate_incident_id);
            return `<tr>
                <td><input type="number" class="invgate-priority-input" name="priority-${ticket.id}"
                    data-ticket-id="${ticket.id}" value="${esc(ticket.manual_priority)}" min="1" max="2147483647" step="1"
                    placeholder="Sin prioridad" aria-label="Prioridad del ticket ${esc(label)} de ${esc(group.person.display_name)}"></td>
                <td>${url ? `<a class="invgate-table__ticket-link" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(label)}</a>` : esc(label)}</td>
                <td><strong>${esc(ticket.title)}</strong></td>
                <td>${C.formatCell(ticket.status_name ?? ticket.status_id)}</td>
                <td>${C.formatTimestamp(ticket.last_update)}</td>
            </tr>`;
        }).join("");
    }

    function groupContent(group, expanded = false) {
        const person = group.person;
        const tickets = group.tickets;
        const prioritized = tickets.filter((ticket) => ticket.manual_priority !== null).length;
        const bodyId = `invgate-priority-tickets-${person.id}`;
        const toggleLabel = `${expanded ? "Plegar" : "Desplegar"} tickets de ${person.display_name}`;
        return `<div class="invgate-priority-head">
            <div class="invgate-priority-person">
                <button type="button" class="invgate-group__toggle panel-collapse-btn" data-priority-toggle
                    aria-expanded="${expanded}" aria-controls="${bodyId}" aria-label="${esc(toggleLabel)}" title="${esc(toggleLabel)}">
                    <span class="panel-collapse-btn__chevron" aria-hidden="true"><svg class="icon icon--chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></span>
                </button>
                <div><h3 class="invgate-group__title">
                    <button type="button" class="invgate-group__title-btn" data-priority-toggle
                        aria-expanded="${expanded}" aria-controls="${bodyId}" title="${esc(toggleLabel)}">${esc(person.display_name)}</button>
                    </h3>
                    <p class="muted invgate-priority-count">${tickets.length} ${tickets.length === 1 ? "ticket abierto" : "tickets abiertos"} · ${prioritized} con prioridad</p></div>
            </div>
            <div class="invgate-priority-actions">
                <button type="submit" class="btn btn--small" data-priority-save disabled>Guardar prioridades</button>
                <button type="button" class="btn btn--small" data-priority-pdf ${tickets.length ? "" : "disabled"}
                    aria-label="Descargar PDF de ${esc(person.display_name)}">Descargar PDF</button>
            </div>
        </div>
        <p class="muted invgate-priority-status" data-priority-status role="status" aria-live="polite"></p>
        <div id="${bodyId}" class="invgate-group__body">
        ${tickets.length ? `<table class="data-table invgate-priority-table">
            <caption class="visually-hidden">Tickets de ${esc(person.display_name)}, ordenados por prioridad</caption>
            <thead><tr><th scope="col">Prioridad</th><th scope="col">Ticket</th><th scope="col">Título</th><th scope="col">Estado</th><th scope="col">Actualizado</th></tr></thead>
            <tbody>${ticketRows(group)}</tbody></table>`
            : '<p class="muted invgate-group__empty">Sin tickets abiertos.</p>'}
        </div>`;
    }

    function setGroupCollapsed(form, collapsed) {
        form.classList.toggle("invgate-group--collapsed", collapsed);
        const label = `${collapsed ? "Desplegar" : "Plegar"} tickets de ${groups.get(Number(form.dataset.personId)).person.display_name}`;
        form.querySelectorAll("[data-priority-toggle]").forEach((button) => {
            button.setAttribute("aria-expanded", String(!collapsed));
            button.title = label;
            if (button.hasAttribute("aria-label")) button.setAttribute("aria-label", label);
        });
    }

    function changesFor(form) {
        const group = groups.get(Number(form.dataset.personId));
        const saved = new Map(group.tickets.map((ticket) => [ticket.id, ticket.manual_priority]));
        return [...form.querySelectorAll("input[data-ticket-id]")].flatMap((input) => {
            const ticketId = Number(input.dataset.ticketId);
            // El valor vacío quita la prioridad; la validación nativa se ejecuta al guardar.
            const priority = input.value === "" ? null : Number(input.value);
            return input.validity.badInput || priority !== saved.get(ticketId)
                ? [{ ticket_id: ticketId, priority }]
                : [];
        });
    }

    function updateControls(form) {
        const personId = Number(form.dataset.personId);
        const pending = changesFor(form).length > 0;
        const processing = busy.has(personId);
        form.querySelector("[data-priority-save]").disabled = processing || !pending;
        const pdf = form.querySelector("[data-priority-pdf]");
        pdf.disabled = processing || pending || groups.get(personId).tickets.length === 0;
        pdf.title = pending ? "Guardá las prioridades antes de descargar el PDF." : "";
        form.querySelectorAll("input").forEach((input) => { input.disabled = processing; });
        form.setAttribute("aria-busy", processing ? "true" : "false");
    }

    function message(form, text, error = false) {
        const status = form.querySelector("[data-priority-status]");
        status.textContent = text;
        status.classList.toggle("invgate-priority-status--error", error);
    }

    function filterPeople() {
        root.querySelectorAll("form[data-person-id]").forEach((form) => {
            form.hidden = personSelect.value !== "" && form.dataset.personId !== personSelect.value;
        });
    }

    async function readJson(response) {
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) window.location.href = "login.php";
        if (!response.ok || !data.ok) throw new Error(data.error || "No se pudo completar la operación.");
        return data;
    }

    async function load() {
        if (loaded || fetching) return;
        fetching = true;
        loading.hidden = false;
        loading.textContent = "Cargando prioridades…";
        retry.hidden = true;
        try {
            const teamId = C.getTeamId();
            if (teamId < 1) throw new Error("No se pudo determinar el equipo de trabajo.");
            const data = await readJson(await fetch(`${api}?team_id=${teamId}`, { credentials: "same-origin" }));
            groups.clear();
            (data.groups || []).forEach((group) => groups.set(Number(group.person.id), group));
            root.innerHTML = [...groups.values()].map((group) =>
                `<form class="invgate-group invgate-priority-group invgate-group--collapsed" data-person-id="${group.person.id}">${groupContent(group)}</form>`
            ).join("") || '<p class="muted">No hay personas con tickets abiertos o ID InvGate configurado.</p>';
            personSelect.innerHTML = '<option value="">Todas las personas</option>' + [...groups.values()].map((group) =>
                `<option value="${group.person.id}">${esc(group.person.display_name)}</option>`
            ).join("");
            personSelect.disabled = groups.size === 0;
            root.hidden = false;
            loading.hidden = true;
            loaded = true;
        } catch (error) {
            loading.textContent = error instanceof Error ? error.message : "Error al cargar prioridades.";
            retry.hidden = false;
        } finally {
            fetching = false;
        }
    }

    root.addEventListener("input", (event) => {
        const form = event.target.closest("form[data-person-id]");
        if (!form) return;
        updateControls(form);
        message(form, changesFor(form).length ? "Hay cambios sin guardar. Guardalos para ordenar la lista y descargar el PDF." : "");
    });

    root.addEventListener("invalid", (event) => {
        const form = event.target.closest("form[data-person-id]");
        if (form) setGroupCollapsed(form, false);
    }, true);

    root.addEventListener("submit", async (event) => {
        const form = event.target.closest("form[data-person-id]");
        if (!form) return;
        event.preventDefault();
        const personId = Number(form.dataset.personId);
        if (busy.has(personId) || !form.reportValidity()) return;
        const priorities = changesFor(form);
        if (!priorities.length) return;
        busy.add(personId);
        updateControls(form);
        message(form, "Guardando prioridades…");
        try {
            const data = await readJson(await fetch(api, {
                method: "PUT",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ team_id: C.getTeamId(), person_id: personId, priorities }),
            }));
            groups.set(personId, data.group);
            form.innerHTML = groupContent(data.group, !form.classList.contains("invgate-group--collapsed"));
            message(form, "Prioridades guardadas. La lista está ordenada de menor a mayor.");
            form.querySelector("[data-priority-pdf]").focus();
        } catch (error) {
            message(form, error instanceof Error ? error.message : "No se pudieron guardar las prioridades.", true);
        } finally {
            busy.delete(personId);
            updateControls(form);
        }
    });

    root.addEventListener("click", async (event) => {
        const toggle = event.target.closest("[data-priority-toggle]");
        if (toggle) {
            const form = toggle.closest("form[data-person-id]");
            setGroupCollapsed(form, !form.classList.contains("invgate-group--collapsed"));
            return;
        }
        const button = event.target.closest("[data-priority-pdf]");
        if (!button || button.disabled) return;
        const form = button.closest("form[data-person-id]");
        const personId = Number(form.dataset.personId);
        if (busy.has(personId) || changesFor(form).length) return;
        busy.add(personId);
        updateControls(form);
        message(form, "Preparando PDF…");
        try {
            const response = await fetch(`${api}?team_id=${C.getTeamId()}&person_id=${personId}&format=pdf`, { credentials: "same-origin" });
            if (!response.ok || !response.headers.get("Content-Type")?.includes("application/pdf")) {
                await readJson(response);
                throw new Error("No se pudo generar el PDF.");
            }
            const url = URL.createObjectURL(await response.blob());
            const anchor = document.createElement("a");
            anchor.href = url;
            anchor.download = `priorizacion-${groups.get(personId).person.display_name.replace(/[^\p{L}\p{N}-]+/gu, "-")}.pdf`;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            message(form, "PDF descargado.");
        } catch (error) {
            message(form, error instanceof Error ? error.message : "No se pudo descargar el PDF.", true);
        } finally {
            busy.delete(personId);
            updateControls(form);
        }
    });

    window.addEventListener("beforeunload", (event) => {
        if ([...root.querySelectorAll("form[data-person-id]")].some((form) => changesFor(form).length)) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    personSelect.addEventListener("change", filterPeople);
    retry.addEventListener("click", load);
    window.InvgatePrioritization = { load };
})();
