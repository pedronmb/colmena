(function () {
    const form = document.getElementById("userCreateForm");
    const listWrap = document.getElementById("usersListWrap");
    const errorEl = document.getElementById("userFormError");
    const submitBtn = document.getElementById("userSubmit");

    const roleLabels = {
        admin: "Administrador",
        lead: "Líder",
        member: "Miembro",
        viewer: "Solo lectura",
    };

    const availLabels = {
        available: "Disponible",
        busy: "Ocupado",
        away: "Ausente",
        offline: "Desconectado",
    };

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function showError(msg) {
        if (!errorEl) return;
        errorEl.textContent = msg;
        errorEl.hidden = false;
    }

    function clearError() {
        if (!errorEl) return;
        errorEl.textContent = "";
        errorEl.hidden = true;
    }

    function formatDt(iso) {
        if (!iso) return "—";
        const s = String(iso).trim();
        const norm = s.includes("T") ? s : s.replace(" ", "T");
        const d = new Date(norm);
        if (Number.isNaN(d.getTime())) return escapeHtml(s);
        return escapeHtml(
            d.toLocaleString("es", { dateStyle: "short", timeStyle: "short" })
        );
    }

    function renderTable(users) {
        if (!listWrap) return;
        if (!users.length) {
            listWrap.innerHTML = '<p class="muted">No hay usuarios.</p>';
            return;
        }
        const rows = users
            .map(
                (u) =>
                    `<tr>
                        <td>${escapeHtml(u.email)}</td>
                        <td>${escapeHtml(u.display_name)}</td>
                        <td>${escapeHtml(roleLabels[u.role] || u.role)}</td>
                        <td>${escapeHtml(availLabels[u.availability] || u.availability)}</td>
                        <td class="muted">${formatDt(u.created_at)}</td>
                    </tr>`
            )
            .join("");
        listWrap.innerHTML = `<div class="edit-list-wrap"><table class="data-table users-table">
            <thead><tr>
                <th scope="col">Email</th>
                <th scope="col">Nombre</th>
                <th scope="col">Rol</th>
                <th scope="col">Disponibilidad</th>
                <th scope="col">Alta</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table></div>`;
    }

    async function loadTeamsForSelect() {
        const teamSel = document.getElementById("userTeamId");
        if (!teamSel) {
            return;
        }
        try {
            const res = await fetch("api/teams.php", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!res.ok || !data.ok || !Array.isArray(data.teams)) {
                return;
            }
            const first = document.createElement("option");
            first.value = "0";
            first.textContent = "Solo espacio personal (recomendado)";
            teamSel.innerHTML = "";
            teamSel.appendChild(first);
            data.teams.forEach((t) => {
                const opt = document.createElement("option");
                opt.value = String(t.id);
                opt.textContent = `${t.name} (#${t.id})`;
                teamSel.appendChild(opt);
            });
            teamSel.value = "0";
        } catch (_) {
            /* sin lista de equipos */
        }
    }

    async function loadUsers() {
        if (!listWrap) return;
        listWrap.innerHTML = '<p class="muted users-list__loading">Cargando…</p>';
        try {
            const res = await fetch("api/users.php", {
                credentials: "same-origin",
                headers: { Accept: "application/json" },
            });
            const data = await res.json();
            if (!data.ok) {
                listWrap.innerHTML = `<p class="form-error" role="alert">${escapeHtml(data.error || "Error al cargar")}</p>`;
                return;
            }
            renderTable(data.users || []);
        } catch (e) {
            listWrap.innerHTML = '<p class="form-error" role="alert">No se pudo cargar la lista.</p>';
        }
    }

    if (form) {
        form.addEventListener("submit", async function (e) {
            e.preventDefault();
            clearError();
            const fd = new FormData(form);
            const teamId = Number(fd.get("team_id"));
            const payload = {
                email: String(fd.get("email") || "").trim(),
                display_name: String(fd.get("display_name") || "").trim(),
                password: String(fd.get("password") || ""),
                role: String(fd.get("role") || "member"),
                availability: String(fd.get("availability") || "available"),
                team_id: Number.isFinite(teamId) && teamId > 0 ? teamId : 0,
                role_in_team: String(fd.get("role_in_team") || "member"),
            };

            if (submitBtn) submitBtn.disabled = true;
            try {
                const res = await fetch("api/users.php", {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                    },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.ok) {
                    showError(data.error || "No se pudo crear el usuario");
                    return;
                }
                form.reset();
                const teamSel = document.getElementById("userTeamId");
                if (teamSel) {
                    teamSel.value = "0";
                }
                await loadUsers();
            } catch (err) {
                showError("Error de red. Inténtalo de nuevo.");
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    loadTeamsForSelect();
    loadUsers();
})();
