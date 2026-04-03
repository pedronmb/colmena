(function () {
    const teamInput = document.getElementById("editTeamId");
    const loadingEl = document.getElementById("editListLoading");
    const table = document.getElementById("editListTable");
    const tbody = document.getElementById("editListBody");
    const modal = document.getElementById("editModal");
    const form = document.getElementById("editForm");
    const errorEl = document.getElementById("editFormError");
    const submitBtn = document.getElementById("editSubmit");

    const listUrl = "api/team-people.php";
    const oneUrl = "api/team-person.php";

    function getTeamId() {
        const n = teamInput ? Number(teamInput.value) : 0;
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function formatBirthdayDisplay(iso) {
        if (!iso || typeof iso !== "string") return "—";
        const p = iso.split("-");
        if (p.length !== 3) return escapeHtml(iso);
        return `${escapeHtml(p[2])}/${escapeHtml(p[1])}/${escapeHtml(p[0])}`;
    }

    function truncate(s, max) {
        if (!s) return "—";
        const t = String(s).trim();
        if (!t) return "—";
        if (t.length <= max) return escapeHtml(t);
        return escapeHtml(t.slice(0, max)) + "…";
    }

    function openModal() {
        modal.hidden = false;
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = "";
    }

    modal?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (t.closest("[data-edit-close]")) closeModal();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !modal.hidden) closeModal();
    });

    function fillForm(p) {
        document.getElementById("editPersonId").value = String(p.id);
        document.getElementById("editTeamIdField").value = String(p.team_id);
        document.getElementById("editDisplayName").value = p.display_name || "";
        document.getElementById("editEmail").value = p.email || "";
        const roleEl = document.getElementById("editRole");
        if (roleEl) roleEl.value = p.role || "";
        document.getElementById("editBirthday").value = p.birthday || "";
        document.getElementById("editExtraInfo").value = p.extra_info || "";
    }

    async function openEdit(id) {
        const teamId = getTeamId();
        if (!teamId) return;
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
        try {
            const res = await fetch(
                `${oneUrl}?id=${encodeURIComponent(String(id))}&team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !data.person) {
                throw new Error(data.error || "No se pudo cargar la ficha");
            }
            fillForm(data.person);
            openModal();
            document.getElementById("editDisplayName").focus();
        } catch (e) {
            alert(e instanceof Error ? e.message : "Error");
        }
    }

    async function loadList() {
        const teamId = getTeamId();
        if (!teamId || !tbody) return;
        loadingEl.textContent = "Cargando…";
        loadingEl.hidden = false;
        table.hidden = true;
        tbody.innerHTML = "";

        try {
            const res = await fetch(`${listUrl}?team_id=${encodeURIComponent(String(teamId))}`, {
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.people)) {
                throw new Error(data.error || "Error al cargar");
            }
            loadingEl.hidden = true;
            if (data.people.length === 0) {
                loadingEl.textContent = "No hay personas. Usa «Nueva persona» arriba.";
                loadingEl.hidden = false;
                return;
            }
            data.people.forEach((p) => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td>${escapeHtml(p.display_name)}</td>
                    <td>${p.role ? escapeHtml(p.role) : "—"}</td>
                    <td>${p.email ? escapeHtml(p.email) : "—"}</td>
                    <td>${formatBirthdayDisplay(p.birthday)}</td>
                    <td class="data-table__notes">${truncate(p.extra_info, 80)}</td>
                    <td><button type="button" class="btn btn--small" data-edit-id="${p.id}">Editar</button></td>
                `;
                tbody.appendChild(tr);
            });
            tbody.querySelectorAll("[data-edit-id]").forEach((btn) => {
                btn.addEventListener("click", () => {
                    const id = Number(btn.getAttribute("data-edit-id"));
                    if (Number.isFinite(id)) openEdit(id);
                });
            });
            table.hidden = false;
        } catch (e) {
            loadingEl.textContent = e instanceof Error ? e.message : "Error";
            loadingEl.hidden = false;
        }
    }

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }

        const fd = new FormData(form);
        const birthdayRaw = String(fd.get("birthday") || "").trim();
        const emailRaw = String(fd.get("email") || "").trim();
        const roleRaw = String(fd.get("role") || "").trim();
        const payload = {
            id: Number(fd.get("id")),
            team_id: Number(fd.get("team_id")),
            display_name: String(fd.get("display_name") || "").trim(),
            email: emailRaw === "" ? null : emailRaw,
            role: roleRaw === "" ? null : roleRaw,
            birthday: birthdayRaw === "" ? null : birthdayRaw,
            extra_info: String(fd.get("extra_info") || "").trim() || null,
        };

        submitBtn.classList.add("loading");
        submitBtn.disabled = true;

        try {
            const res = await fetch(oneUrl, {
                method: "PUT",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo guardar");
            }
            closeModal();
            await loadList();
        } catch (err) {
            if (errorEl) {
                errorEl.textContent = err instanceof Error ? err.message : "Error";
                errorEl.hidden = false;
            }
        } finally {
            submitBtn.classList.remove("loading");
            submitBtn.disabled = false;
        }
    });

    document.addEventListener("colmena:person-created", () => {
        loadList();
    });

    loadList();
})();
