(function () {
    const summary = document.getElementById("widget-summary");
    const status = document.getElementById("widget-status");
    const refreshButton = document.getElementById("widget-refresh");
    const signOutButton = document.getElementById("widget-sign-out");
    const signOutError = document.getElementById("widget-sign-out-error");
    const invgateSyncStatus = document.getElementById("widget-invgate-sync-status");
    const invgateSyncToken = document.querySelector('meta[name="colmena-invgate-sync"]');
    const timeFormat = new Intl.DateTimeFormat("es-AR", { hour: "2-digit", minute: "2-digit" });
    const syncTimeFormat = new Intl.DateTimeFormat("es-AR", {
        day: "numeric", month: "short", hour: "2-digit", minute: "2-digit",
    });
    const dateFormat = new Intl.DateTimeFormat("es-AR", { day: "numeric", month: "short", timeZone: "UTC" });
    const numberFormat = new Intl.NumberFormat("es-AR");
    let request = null;
    let syncRequest = null;
    let updatedAt = null;
    let signingOut = false;

    function showStatus(message, warning = false) {
        status.textContent = message;
        status.toggleAttribute("data-warning", warning);
    }

    function showMetric(key, count, label) {
        document.getElementById(`widget-${key}`).textContent = count === null ? "—" : numberFormat.format(count);
        document.getElementById(`widget-${key}-label`).textContent = count === null ? "No disponible" : label;
    }

    function showInvgateSync(sync) {
        if (!invgateSyncStatus) return;
        invgateSyncStatus.hidden = !sync;
        if (!sync) return;

        const results = {
            running: "En curso", success: "Completada", partial: "Parcial", failed: "Falló", unavailable: "No disponible",
        };
        const result = results[sync.result] ?? (sync.queued ? "En cola" : "Sin intentos");
        const attempted = sync.last_attempt_at ? new Date(sync.last_attempt_at) : null;
        const time = attempted && Number.isFinite(attempted.getTime()) ? ` · ${syncTimeFormat.format(attempted)}` : "";
        invgateSyncStatus.textContent = `Sincronización: ${result}${time}`;
    }

    function showList(key, section, describe) {
        const list = document.getElementById(`widget-${key}-list`);
        document.getElementById(`widget-${key}-total`).textContent = numberFormat.format(section.total);
        document.getElementById(`widget-${key}-empty`).hidden = section.items.length !== 0;
        list.replaceChildren(...section.items.map((item) => {
            const row = document.createElement("li");
            const title = document.createElement("span");
            title.className = "widget__item-title";
            title.textContent = item.title;
            title.title = item.title;
            const detail = document.createElement("span");
            detail.className = "widget__detail";
            detail.textContent = describe(item);
            row.append(title, detail);
            return row;
        }));
    }

    function clearSummary() {
        summary.hidden = true;
        updatedAt = null;
        for (const key of ["alert", "topic"]) {
            document.getElementById(`widget-${key}-list`).replaceChildren();
            document.getElementById(`widget-${key}-total`).textContent = "";
        }
        showMetric("invgate", null, "");
        showMetric("devops", null, "");
        showInvgateSync(null);
    }

    function requireLogin() {
        invgateSyncToken?.remove();
        syncRequest?.abort();
        clearSummary();
        location.replace("login.php?next=widget");
    }

    async function refresh() {
        if (request || signingOut) return;

        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 12000);
        refreshButton.disabled = true;

        try {
            const response = await fetch("api/widget-summary.php", { cache: "no-store", signal: controller.signal });
            if (signingOut) return;
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
            if (signingOut) return;
            const refreshed = new Date(data.updated_at);
            if (!data.ok || !Number.isFinite(refreshed.getTime())) throw new Error("Resumen inválido");

            showList("alert", data.alerts, (item) => dateFormat.format(new Date(`${item.due_date}T00:00:00Z`)));
            showList("topic", data.critical_topics, (item) => `Urg. ${item.priority} · Imp. ${item.importance}`);
            showMetric("invgate", data.invgate_open, "Tickets abiertos");
            showMetric("devops", data.devops_active, "Work items activos");
            showInvgateSync(data.invgate_sync);
            updatedAt = refreshed;
            summary.hidden = false;
            showStatus(`Actualizado a las ${timeFormat.format(updatedAt)}`);
        } catch {
            if (!signingOut) {
                const lastUpdate = updatedAt ? ` Última actualización a las ${timeFormat.format(updatedAt)}.` : "";
                showStatus(`No se pudo actualizar.${lastUpdate}`, true);
            }
        } finally {
            clearTimeout(timeout);
            request = null;
            refreshButton.disabled = signingOut;
        }
    }

    async function requestInvgateSync() {
        if (!invgateSyncToken?.isConnected || syncRequest || signingOut) return;
        const controller = new AbortController();
        syncRequest = controller;
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
            const response = await fetch("api/widget-invgate-sync.php", {
                method: "POST",
                headers: { "X-CSRF-Token": invgateSyncToken.content },
                cache: "no-store",
                signal: controller.signal,
            });
            if (signingOut) return;
            if (response.status === 401) {
                requireLogin();
            } else if (response.status === 403) {
                invgateSyncToken.remove();
            }
        } catch {
            // The five-minute summary refresh keeps its last values if the request fails.
        } finally {
            clearTimeout(timeout);
            syncRequest = null;
        }
    }

    signOutButton.addEventListener("click", async () => {
        signingOut = true;
        signOutButton.disabled = true;
        refreshButton.disabled = true;
        signOutError.hidden = true;
        request?.abort();
        syncRequest?.abort();

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
        }
    });

    refreshButton.addEventListener("click", refresh);
    window.addEventListener("colmena-invgate-sync", requestInvgateSync);
    window.addEventListener("online", refresh);
    window.addEventListener("pageshow", (event) => {
        if (event.persisted) {
            clearSummary();
            refresh();
        }
    });
    setInterval(refresh, 5 * 60 * 1000);
    refresh();
})();
