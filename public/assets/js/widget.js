(function () {
    const summary = document.getElementById("widget-summary");
    const status = document.getElementById("widget-status");
    const refreshButton = document.getElementById("widget-refresh");
    const signOutButton = document.getElementById("widget-sign-out");
    const signOutError = document.getElementById("widget-sign-out-error");
    const invgateSyncStatus = document.getElementById("widget-invgate-sync-status");
    const invgateSyncToken = document.querySelector('meta[name="colmena-invgate-sync"]');
    const invgateRequestStatus = document.getElementById("widget-invgate-request-status");
    const invgateSyncButton = document.getElementById("widget-invgate-sync-button");
    const ticketUrlPrefix = document.querySelector('meta[name="colmena-invgate-ticket-prefix"]')?.content;
    const sectionButtons = document.querySelectorAll("[data-widget-section]");
    const panels = Object.fromEntries(Object.entries({
        invgate: "widget-invgate-recent", "invgate-people": "widget-invgate-groups",
    }).map(([key, list]) => [key, {
        root: document.getElementById(`widget-${key}-panel`),
        status: document.getElementById(`widget-${key}-detail-status`),
        list: document.getElementById(list),
    }]));
    const timeFormat = new Intl.DateTimeFormat("es-AR", { hour: "2-digit", minute: "2-digit" });
    const dateTimeFormat = new Intl.DateTimeFormat("es-AR", {
        day: "numeric", month: "short", hour: "2-digit", minute: "2-digit",
    });
    const numberFormat = new Intl.NumberFormat("es-AR");
    let request = null;
    let syncRequest = null;
    let syncStatusRefresh;
    let syncRetry;
    let updatedAt = null;
    let signingOut = false;
    let activeSection = "invgate";

    function showStatus(message, warning = false) {
        status.textContent = message;
        status.toggleAttribute("data-warning", warning);
    }

    function showTicketCount(count) {
        document.getElementById("widget-invgate").textContent = count === null ? "—" : numberFormat.format(count);
        document.getElementById("widget-invgate-label").textContent = count === null ? "No disponible" : "Tickets abiertos";
    }

    function showInvgateSync(sync) {
        clearTimeout(syncStatusRefresh);
        if (!invgateSyncStatus) return;
        invgateSyncStatus.hidden = !sync;
        if (!sync) return;

        const results = {
            running: "En curso", success: "Completada", partial: "Parcial", failed: "Falló", unavailable: "No disponible",
        };
        const result = results[sync.result] ?? "Sin intentos";
        const attempted = sync.last_attempt_at ? new Date(sync.last_attempt_at) : null;
        const time = attempted && Number.isFinite(attempted.getTime()) ? ` · ${dateTimeFormat.format(attempted)}` : "";
        invgateSyncStatus.textContent = `Último intento de InvGate: ${result}${time}`;
        if (sync.result === "running" && invgateSyncToken?.isConnected) {
            syncStatusRefresh = setTimeout(() => refresh(), 10000);
        }
    }

    function showInvgateRequest(source, message) {
        if (!invgateRequestStatus) return;
        invgateRequestStatus.textContent = message ? `Solicitud ${source} a las ${timeFormat.format(new Date())}. ${message}` : "";
        invgateRequestStatus.hidden = !message;
    }

    function textNode(tag, text, className) {
        const node = document.createElement(tag);
        node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    function ticketRow(ticket, description) {
        const row = document.createElement("li");
        const titleLine = document.createElement("span");
        titleLine.className = "widget__item-title";
        const number = `#${ticket.number}`;
        if (ticketUrlPrefix && Number.isSafeInteger(ticket.number) && ticket.number > 0) {
            const link = textNode("a", number, "widget__ticket-link");
            link.href = ticketUrlPrefix + ticket.number;
            link.target = "_blank";
            link.rel = "noopener";
            link.setAttribute("aria-label", `Abrir ticket ${number} en InvGate`);
            titleLine.append(link, ` ${ticket.title}`);
        } else {
            titleLine.textContent = `${number} ${ticket.title}`;
        }
        row.append(titleLine);
        if (description) row.append(textNode("span", description, "widget__detail"));
        return row;
    }

    function showRecentInvgate(tickets, total) {
        const { root, status: detailStatus, list } = panels.invgate;
        list.replaceChildren(...tickets.map((ticket) => {
            const time = new Date(ticket.interaction_at);
            const description = [ticket.person, Number.isFinite(time.getTime()) ? dateTimeFormat.format(time) : null];
            return ticketRow(ticket, description.filter(Boolean).join(" · "));
        }));
        detailStatus.textContent = total === null ? "InvGate no está disponible."
            : tickets.length ? "" : "Sin actividad registrada en los tickets abiertos.";
        root.dataset.loaded = "true";
    }

    function showInvgatePeople(groups, total) {
        const { root, status: detailStatus, list } = panels["invgate-people"];
        const previouslyOpen = new Set([...list.querySelectorAll("details[open]")].map((group) => group.dataset.person));
        list.replaceChildren(...groups.map((group) => {
            const details = document.createElement("details");
            details.dataset.person = String(group.person_id);
            details.open = previouslyOpen.has(details.dataset.person);
            const heading = document.createElement("summary");
            heading.append(textNode("span", group.name), textNode("span", numberFormat.format(group.tickets.length), "widget__total"));
            details.append(heading);
            if (group.tickets.length === 0) {
                const message = total === null ? "Tickets no disponibles" : "Sin tickets abiertos";
                details.append(textNode("p", message, "widget__empty"));
            } else {
                const tickets = document.createElement("ul");
                tickets.className = "widget__list";
                tickets.append(...group.tickets.map((ticket) => ticketRow(ticket,
                    ticket.manual_priority === null ? "Sin prioridad" : `Prioridad en Colmena ${ticket.manual_priority}`)));
                details.append(tickets);
            }
            return details;
        }));
        detailStatus.textContent = total === null ? "InvGate no está disponible."
            : groups.length ? "" : "No hay personas marcadas como Equipo directo.";
        root.dataset.loaded = "true";
    }

    function displaySection(section) {
        activeSection = section;
        for (const button of sectionButtons) {
            button.setAttribute("aria-expanded", String(button.dataset.widgetSection === section));
        }
        for (const [key, panel] of Object.entries(panels)) {
            panel.root.hidden = key !== section;
        }
        if (!panels[section].root.dataset.loaded) {
            panels[section].status.textContent = "Cargando detalle…";
        }
    }

    function clearSummary() {
        summary.hidden = true;
        updatedAt = null;
        for (const panel of Object.values(panels)) {
            panel.list.replaceChildren();
            panel.status.textContent = "";
            delete panel.root.dataset.loaded;
        }
        displaySection("invgate");
        showTicketCount(null);
        showInvgateSync(null);
        showInvgateRequest();
        clearTimeout(syncRetry);
    }

    function requireLogin() {
        invgateSyncToken?.remove();
        request?.abort();
        syncRequest?.abort();
        clearSummary();
        location.replace("login.php?next=widget");
    }

    async function refresh(replaceRequest = false) {
        if (signingOut || (request && !replaceRequest)) return;
        request?.abort();

        const section = activeSection;
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 12000);
        refreshButton.disabled = true;

        try {
            const url = `api/widget-summary.php?section=${encodeURIComponent(section)}`;
            const response = await fetch(url, { cache: "no-store", signal: controller.signal });
            if (request !== controller || signingOut) return;
            if (response.status === 401) {
                requireLogin();
                return;
            }
            if (response.status === 403) {
                invgateSyncToken?.remove();
                syncRequest?.abort();
                clearSummary();
                showStatus("Ya no tenés acceso a este espacio de trabajo.", true);
                return;
            }
            if (!response.ok) throw new Error("No se pudo actualizar");
            const data = await response.json();
            if (request !== controller || signingOut) return;
            const refreshed = new Date(data.updated_at);
            if (!data.ok || !Number.isFinite(refreshed.getTime())) throw new Error("Resumen inválido");

            showTicketCount(data.invgate_open);
            if (section === "invgate") showRecentInvgate(data.detail, data.invgate_open);
            else showInvgatePeople(data.detail, data.invgate_open);
            showInvgateSync(data.invgate_sync);
            updatedAt = refreshed;
            summary.hidden = false;
            showStatus(`Actualizado a las ${timeFormat.format(updatedAt)}`);
        } catch {
            if (request === controller && !signingOut) {
                if (!panels[section].root.dataset.loaded) {
                    panels[section].status.textContent = "No se pudo cargar el detalle.";
                }
                const lastUpdate = updatedAt ? ` Última actualización a las ${timeFormat.format(updatedAt)}.` : "";
                showStatus(`No se pudo actualizar.${lastUpdate}`, true);
            }
        } finally {
            clearTimeout(timeout);
            if (request === controller) {
                request = null;
                refreshButton.disabled = signingOut;
            }
        }
    }

    async function requestInvgateSync(source) {
        if (!invgateSyncToken?.isConnected || syncRequest || signingOut) return;
        clearTimeout(syncRetry);
        const controller = new AbortController();
        syncRequest = controller;
        if (invgateSyncButton) invgateSyncButton.disabled = true;
        showInvgateRequest(source, "Enviando…");
        const timeout = setTimeout(() => controller.abort(), 10 * 60 * 1000);
        clearTimeout(syncStatusRefresh);
        syncStatusRefresh = setTimeout(() => refresh(), 1500);
        try {
            const response = await fetch("api/widget-invgate-sync.php", {
                method: "POST",
                headers: { "X-CSRF-Token": invgateSyncToken.content },
                cache: "no-store",
                signal: controller.signal,
            });
            if (signingOut || !invgateSyncToken.isConnected) return;
            if (response.status === 401) {
                requireLogin();
                return;
            }
            if (response.status === 403) {
                invgateSyncToken.remove();
                showInvgateRequest(source, "El servidor rechazó la solicitud. Volvé a abrir el widget con una sesión administradora.");
                clearTimeout(syncStatusRefresh);
                return;
            }
            if (!response.ok) {
                showInvgateRequest(source, `El servidor respondió con HTTP ${response.status}. Revisá el último intento.`);
                void refresh(true);
                return;
            }
            const data = await response.json();
            if (signingOut || !invgateSyncToken.isConnected) return;
            const messages = {
                started: "El servidor inició la sincronización.",
                finished: "El servidor terminó la sincronización.",
                recent: "El último intento está dentro del intervalo de 15 minutos.",
                busy: "Ya hay una sincronización en curso.",
            };
            const message = data?.ok && messages[data.state];
            if (!message) throw new Error("Respuesta de sincronización inválida");
            showInvgateRequest(source, message);
            void refresh(true);
            if (source === "automática" && data.state === "recent"
                && Number.isInteger(data.retry_after) && data.retry_after > 0 && data.retry_after <= 60) {
                // The native timer can fire just before the server's cooldown ends.
                syncRetry = setTimeout(() => requestInvgateSync(source), (data.retry_after + 1) * 1000);
            }
        } catch {
            if (!signingOut && invgateSyncToken.isConnected) {
                showInvgateRequest(source, "No se recibió una confirmación del servidor. Revisá el último intento.");
                void refresh(true);
            }
        } finally {
            clearTimeout(timeout);
            syncRequest = null;
            if (invgateSyncButton) invgateSyncButton.disabled = signingOut || !invgateSyncToken.isConnected;
        }
    }

    signOutButton.addEventListener("click", async () => {
        signingOut = true;
        signOutButton.disabled = true;
        refreshButton.disabled = true;
        signOutError.hidden = true;
        request?.abort();
        syncRequest?.abort();
        clearTimeout(syncStatusRefresh);
        clearTimeout(syncRetry);
        if (invgateSyncButton) invgateSyncButton.disabled = true;

        try {
            const response = await fetch("api/logout.php", {
                method: "POST",
                cache: "no-store",
                signal: AbortSignal.timeout(12000),
            });
            if (!response.ok || !(await response.json()).ok) throw new Error("No se pudo cerrar sesión");
            requireLogin();
        } catch {
            signOutError.textContent = "No se pudo cerrar sesión. Volvé a intentar.";
            signOutError.hidden = false;
            signingOut = false;
            signOutButton.disabled = false;
            refreshButton.disabled = request !== null;
            if (invgateSyncButton) invgateSyncButton.disabled = syncRequest !== null || !invgateSyncToken?.isConnected;
        }
    });

    refreshButton.addEventListener("click", () => refresh());
    for (const button of sectionButtons) {
        button.addEventListener("click", () => {
            const key = button.dataset.widgetSection;
            if (signingOut || activeSection === key) return;
            displaySection(key);
            void refresh(true);
        });
    }
    invgateSyncButton?.addEventListener("click", () => requestInvgateSync("manual"));
    window.addEventListener("colmena-invgate-sync", () => requestInvgateSync("automática"));
    window.addEventListener("online", () => refresh());
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            clearSummary();
            refresh(true);
        }
    });
    setInterval(refresh, 5 * 60 * 1000);
    refresh();
})();
