(function () {
    const form = document.getElementById("personForm");
    const board = document.getElementById("peopleBoard");
    const submitBtn = document.getElementById("personSubmit");
    const errorEl = document.getElementById("personFormError");

    const priorityLabels = {
        very_low: "Muy baja",
        low: "Baja",
        medium: "Media",
        high: "Alta",
        critical: "Crítica",
    };

    const importanceLabels = {
        very_low: "Muy baja",
        low: "Baja",
        medium: "Media",
        high: "Alta",
        very_high: "Muy alta",
    };

    function priorityLabel(p) {
        return priorityLabels[p] || priorityLabels.medium;
    }

    function importanceLabel(i) {
        return importanceLabels[i] || importanceLabels.medium;
    }

    function getTeamId() {
        const input = document.querySelector('#personForm input[name="team_id"]');
        const n = input ? Number(input.value) : 0;
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function formatTopicDt(iso) {
        if (!iso) return "";
        const s = String(iso).trim();
        const norm = s.includes("T") ? s : s.replace(" ", "T");
        const d = new Date(norm);
        if (Number.isNaN(d.getTime())) return s;
        return d.toLocaleString("es", { dateStyle: "short", timeStyle: "short" });
    }

    function topicDatesLine(t) {
        const bits = [];
        if (t.created_at) bits.push(`Creado: ${formatTopicDt(t.created_at)}`);
        if (t.completed_at) bits.push(`Realizado: ${formatTopicDt(t.completed_at)}`);
        if (!bits.length) return "";
        return `<div class="person-topic__dates muted">${bits.map(escapeHtml).join(" · ")}</div>`;
    }

    function priorityClass(p) {
        if (p === "critical") return "pill pill--urgent";
        if (p === "high") return "pill pill--high";
        if (p === "low" || p === "very_low") return "pill pill--low";
        return "pill";
    }

    function importanceClass(i) {
        if (i === "very_high" || i === "high") return "pill pill--high";
        if (i === "very_low" || i === "low") return "pill pill--low";
        return "pill";
    }

    function formatBirthdayEs(iso) {
        if (!iso || typeof iso !== "string") return "";
        const p = iso.split("-");
        if (p.length !== 3) return iso;
        return `${p[2]}/${p[1]}/${p[0]}`;
    }

    function personDetailsHtml(person) {
        const bits = [];
        if (person.role && String(person.role).trim() !== "") {
            bits.push(`<p class="person-card__meta muted"><span class="person-card__role-label">Rol:</span> ${escapeHtml(String(person.role).trim())}</p>`);
        }
        if (person.birthday) {
            bits.push(`<p class="person-card__meta muted">Cumpleaños: ${escapeHtml(formatBirthdayEs(person.birthday))}</p>`);
        }
        if (person.extra_info && String(person.extra_info).trim() !== "") {
            const t = String(person.extra_info).trim();
            const short = t.length > 140 ? t.slice(0, 140) + "…" : t;
            bits.push(`<p class="person-card__extra muted">${escapeHtml(short)}</p>`);
        }
        return bits.join("");
    }

    function renderTopicsList(topics) {
        if (topics.length === 0) {
            return '<p class="person-card__empty muted">Sin temas aún</p>';
        }
        let html = "<ul class=\"person-topics\">";
        topics.forEach((t) => {
            const pri = priorityLabel(t.priority);
            const imp = importanceLabel(t.importance);
            const pcl = priorityClass(t.priority);
            const icl = importanceClass(t.importance);
            html += `<li>
                <span class="person-topic__title">${escapeHtml(t.title)}</span>
                <span class="person-topic__badges">
                    <span class="${pcl}" title="Urgencia">${escapeHtml(pri)}</span>
                    <span class="${icl}" title="Importancia">${escapeHtml(imp)}</span>
                </span>
                <span class="person-topic__meta">#${t.id} · ${escapeHtml(t.status)}</span>
                ${topicDatesLine(t)}
            </li>`;
        });
        html += "</ul>";
        return html;
    }

    function renderBoard(people, unassigned) {
        if (!board) return;
        board.innerHTML = "";
        const grid = document.createElement("div");
        grid.className = "people-grid";

        people.forEach(({ person, topics }) => {
            const card = document.createElement("article");
            card.className = "person-card";
            const contact =
                person.email && String(person.email).trim() !== ""
                    ? `<p class="person-card__email muted">${escapeHtml(String(person.email))}</p>`
                    : "";

            card.innerHTML = `
                <header class="person-card__head">
                    <h3 class="person-card__name">${escapeHtml(person.display_name)}</h3>
                </header>
                ${contact}
                ${personDetailsHtml(person)}
                ${renderTopicsList(topics)}
            `;
            grid.appendChild(card);
        });

        if (unassigned.length > 0) {
            const card = document.createElement("article");
            card.className = "person-card person-card--unassigned";
            card.innerHTML = `
                <header class="person-card__head">
                    <h3 class="person-card__name">Sin tarjeta</h3>
                </header>
                <p class="muted person-card__hint">Temas antiguos o sin persona asignada</p>
                ${renderTopicsList(unassigned)}
            `;
            grid.appendChild(card);
        }

        if (!people.length && !unassigned.length) {
            board.innerHTML = '<p class="muted">No hay tarjetas. Añade una persona arriba.</p>';
            return;
        }

        board.appendChild(grid);
    }

    async function loadBoard() {
        const teamId = getTeamId();
        if (!teamId || !board) return;
        board.innerHTML = '<p class="muted people-board__loading">Cargando…</p>';

        try {
            const res = await fetch(
                `api/people-board.php?team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.people)) {
                throw new Error(data.error || "No se pudo cargar el tablero");
            }
            const unassigned = Array.isArray(data.unassigned_topics) ? data.unassigned_topics : [];
            if (!data.people.length && !unassigned.length) {
                board.innerHTML = '<p class="muted">No hay tarjetas. Añade una persona arriba.</p>';
                return;
            }
            renderBoard(data.people, unassigned);
        } catch (e) {
            board.innerHTML = `<p class="form-error" role="alert">${escapeHtml(e instanceof Error ? e.message : "Error")}</p>`;
        }
    }

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }

        const fd = new FormData(form);
        const emailRaw = String(fd.get("email") || "").trim();
        const birthdayRaw = String(fd.get("birthday") || "").trim();
        const extraRaw = String(fd.get("extra_info") || "").trim();
        const roleRaw = String(fd.get("role") || "").trim();
        const payload = {
            team_id: Number(fd.get("team_id")),
            display_name: String(fd.get("display_name") || "").trim(),
            email: emailRaw === "" ? null : emailRaw,
            role: roleRaw === "" ? null : roleRaw,
            birthday: birthdayRaw === "" ? null : birthdayRaw,
            extra_info: extraRaw === "" ? null : extraRaw,
        };

        submitBtn.classList.add("loading");
        submitBtn.disabled = true;

        try {
            const res = await fetch("api/team-people.php", {
                method: "POST",
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
                throw new Error(data.error || "No se pudo crear la tarjeta");
            }
            form.reset();
            await loadBoard();
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

    const personFormPanel = document.getElementById("personFormPanel");
    const personFormPanelToggle = document.getElementById("personFormPanelToggle");
    personFormPanelToggle?.addEventListener("click", () => {
        personFormPanel?.classList.toggle("panel--collapsed");
        const collapsed = personFormPanel?.classList.contains("panel--collapsed");
        personFormPanelToggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
        personFormPanelToggle.setAttribute(
            "title",
            collapsed ? "Desplegar formulario" : "Plegar formulario"
        );
    });

    loadBoard();
})();
