/**
 * Alertas: crear, editar, listar y eliminar.
 */
(function () {
    const apiUrl = "api/alerts.php";
    const teamId = 1;
    const form = document.getElementById("alertForm");
    const submitBtn = document.getElementById("alertSubmit");
    const cancelEditBtn = document.getElementById("alertCancelEdit");
    const errorEl = document.getElementById("alertFormError");
    const loadingEl = document.getElementById("alertsLoading");
    const rootEl = document.getElementById("alertsListRoot");

    const defaultSubmitLabel = "Guardar alerta";
    const editSubmitLabel = "Guardar cambios";

    /** @type {number | null} */
    let editingAlertId = null;
    /** @type {Array<Record<string, unknown>>} */
    let cachedAlerts = [];

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function formatDateYmd(ymd) {
        if (!ymd || typeof ymd !== "string" || ymd.length < 10) {
            return ymd || "";
        }
        const p = ymd.slice(0, 10).split("-");
        if (p.length !== 3) {
            return ymd;
        }
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    function exitEditMode() {
        editingAlertId = null;
        if (form) {
            form.reset();
        }
        if (submitBtn) {
            submitBtn.textContent = defaultSubmitLabel;
        }
        if (cancelEditBtn) {
            cancelEditBtn.hidden = true;
        }
    }

    function enterEditMode(a) {
        if (!form || !submitBtn || !cancelEditBtn) {
            return;
        }
        editingAlertId = typeof a.id === "number" ? a.id : Number(a.id);
        const due = typeof a.due_date === "string" ? a.due_date.slice(0, 10) : "";
        form.querySelector('[name="title"]').value = String(a.title ?? "");
        form.querySelector('[name="due_date"]').value = due;
        const bodyEl = form.querySelector('[name="body"]');
        if (bodyEl) {
            bodyEl.value = a.body != null ? String(a.body) : "";
        }
        submitBtn.textContent = editSubmitLabel;
        cancelEditBtn.hidden = false;
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
        form.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    function renderList(alerts) {
        if (!rootEl) {
            return;
        }
        if (!alerts.length) {
            rootEl.innerHTML =
                '<p class="muted">No hay alertas. Crea una arriba con su fecha de cumplimiento.</p>';
            rootEl.hidden = false;
            return;
        }
        const rows = alerts
            .map(
                (a) => `<tr>
            <td>${escapeHtml(formatDateYmd(a.due_date))}</td>
            <td><strong>${escapeHtml(a.title)}</strong></td>
            <td class="muted">${a.body ? escapeHtml(String(a.body)) : "—"}</td>
            <td><div class="alerts-table__actions"><button type="button" class="btn btn--small" data-edit-id="${a.id}">Editar</button><button type="button" class="btn btn--small" data-delete-id="${a.id}">Eliminar</button></div></td>
        </tr>`
            )
            .join("");
        rootEl.innerHTML = `<table class="data-table alerts-table">
            <thead><tr><th>Fecha</th><th>Título</th><th>Notas</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
        rootEl.hidden = false;
    }

    async function loadAlerts() {
        if (loadingEl) {
            loadingEl.hidden = false;
        }
        if (rootEl) {
            rootEl.hidden = true;
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
            if (!res.ok || !data.ok || !Array.isArray(data.alerts)) {
                throw new Error(data.error || "No se pudieron cargar las alertas");
            }
            if (loadingEl) {
                loadingEl.hidden = true;
            }
            cachedAlerts = data.alerts;
            renderList(data.alerts);
        } catch (e) {
            if (loadingEl) {
                loadingEl.textContent = e instanceof Error ? e.message : "Error";
            }
        }
    }

    cancelEditBtn?.addEventListener("click", () => {
        exitEditMode();
    });

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
        const fd = new FormData(form);
        const title = String(fd.get("title") || "").trim();
        const due = String(fd.get("due_date") || "").trim();
        const body = String(fd.get("body") || "").trim() || null;
        if (!title || !due) {
            return;
        }
        const isEdit = editingAlertId !== null;
        submitBtn.disabled = true;
        submitBtn.classList.add("loading");
        try {
            const res = await fetch(apiUrl, {
                method: isEdit ? "PUT" : "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify(
                    isEdit
                        ? {
                              team_id: teamId,
                              alert_id: editingAlertId,
                              title,
                              due_date: due,
                              body,
                          }
                        : {
                              team_id: teamId,
                              title,
                              due_date: due,
                              body,
                          }
                ),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo guardar");
            }
            exitEditMode();
            await loadAlerts();
        } catch (err) {
            if (errorEl) {
                errorEl.textContent = err instanceof Error ? err.message : "Error";
                errorEl.hidden = false;
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.classList.remove("loading");
        }
    });

    rootEl?.addEventListener("click", async (e) => {
        const t = e.target;
        if (!(t instanceof HTMLElement)) {
            return;
        }
        const editId = t.getAttribute("data-edit-id");
        if (editId) {
            const a = cachedAlerts.find((x) => Number(x.id) === Number(editId));
            if (a) {
                enterEditMode(a);
            }
            return;
        }
        const id = t.getAttribute("data-delete-id");
        if (!id) {
            return;
        }
        if (!window.confirm("¿Eliminar esta alerta?")) {
            return;
        }
        t.disabled = true;
        try {
            const res = await fetch(apiUrl, {
                method: "DELETE",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    team_id: teamId,
                    alert_id: Number(id),
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo eliminar");
            }
            if (editingAlertId === Number(id)) {
                exitEditMode();
            }
            await loadAlerts();
        } catch (err) {
            alert(err instanceof Error ? err.message : "Error");
        } finally {
            t.disabled = false;
        }
    });

    loadAlerts();
})();
