(function () {
    const form = document.getElementById("personForm");
    const board = document.getElementById("peopleBoard");
    const submitBtn = document.getElementById("personSubmit");
    const errorEl = document.getElementById("personFormError");

    const personCardModal = document.getElementById("personCardModal");
    const personCardModalTitle = document.getElementById("personCardModalTitle");
    const personCardModalDetails = document.getElementById("personCardModalDetails");
    const personCardModalToolbar = document.getElementById("personCardModalToolbar");
    const personCardModalToggleDone = document.getElementById("personCardModalToggleDone");
    const personCardModalTopics = document.getElementById("personCardModalTopics");
    const personCardModalNewTopic = document.getElementById("personCardModalNewTopic");

    /** En el modal: si es false solo se listan temas no realizados; si true también los hechos */
    let personModalShowResolved = false;

    const personTopicEditModal = document.getElementById("personTopicEditModal");
    const personTopicEditForm = document.getElementById("personTopicEditForm");
    const personTopicEditError = document.getElementById("personTopicEditError");
    const pteSubmit = document.getElementById("pteSubmit");
    const ptePersonWrap = document.getElementById("ptePersonWrap");
    const ptePersonSelect = document.getElementById("ptePersonSelect");
    const ptePersonId = document.getElementById("ptePersonId");

    const topicOneUrl = "api/topic.php";
    const teamPeopleUrl = "api/team-people.php";

    /** @type {{ people: Array<{person: object, topics: object[]}>, unassigned: object[] }} */
    let boardSnapshot = { people: [], unassigned: [] };

    /** @type {{ kind: 'person' | 'unassigned' | null, personId: number | null }} */
    let personModalOpen = { kind: null, personId: null };

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
        const formInput = document.querySelector(
            '#personForm input[name="team_id"]'
        );
        if (formInput) {
            const n = Number(formInput.value);
            if (Number.isFinite(n) && n > 0) {
                return n;
            }
        }
        const boardEl = document.getElementById("peopleBoard");
        if (boardEl && boardEl.dataset.teamId) {
            const n = Number(boardEl.dataset.teamId);
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

    function formatBirthdayEs(stored) {
        if (!stored || typeof stored !== "string") return "";
        const p = stored.split("-");
        let mm;
        let dd;
        if (p.length === 2) {
            mm = p[0];
            dd = p[1];
        } else if (p.length === 3) {
            mm = p[1];
            dd = p[2];
        } else {
            return stored;
        }
        return `${String(dd).padStart(2, "0")}/${String(mm).padStart(2, "0")}`;
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

    function topicsOpenOnly(topics) {
        return topics.filter((t) => t.status !== "done");
    }

    function renderTopicsList(topics) {
        const openOnly = topicsOpenOnly(topics);
        if (openOnly.length === 0) {
            if (topics.length === 0) {
                return '<p class="person-card__empty muted">Sin temas aún</p>';
            }
            return '<p class="person-card__empty muted">Sin temas pendientes</p>';
        }
        let html = "<ul class=\"person-topics\">";
        openOnly.forEach((t) => {
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

    function closePersonCardModal() {
        if (!personCardModal) return;
        personCardModal.hidden = true;
        personModalOpen = { kind: null, personId: null };
        personModalShowResolved = false;
        if (!personTopicEditModal || personTopicEditModal.hidden) {
            document.body.style.overflow = "";
        }
    }

    function closeTopicEditModal() {
        if (!personTopicEditModal) return;
        personTopicEditModal.hidden = true;
        if (personCardModal && !personCardModal.hidden) {
            document.body.style.overflow = "hidden";
        } else {
            document.body.style.overflow = "";
        }
    }

    function fillPersonCardModal(person, topics, isUnassigned) {
        if (!personCardModalTitle || !personCardModalDetails || !personCardModalTopics) {
            return;
        }
        personCardModalTitle.textContent = isUnassigned
            ? "Sin tarjeta"
            : person.display_name || "Tarjeta";

        if (isUnassigned) {
            personCardModalDetails.innerHTML =
                '<p class="muted">Temas antiguos o sin persona asignada. Para editar el contenido de un tema sin tarjeta, elige persona abajo o usa <a href="index.php">Temas</a>.</p>';
        } else {
            const contact =
                person.email && String(person.email).trim() !== ""
                    ? `<p class="person-card__email">${escapeHtml(String(person.email))}</p>`
                    : "";
            personCardModalDetails.innerHTML = contact + personDetailsHtml(person);
        }

        const doneList = topics.filter((t) => t.status === "done");
        const doneCount = doneList.length;
        if (personCardModalToolbar && personCardModalToggleDone && personCardModalNewTopic) {
            personCardModalToolbar.hidden = false;
            if (isUnassigned) {
                personCardModalNewTopic.href = "index.php";
            } else if (person && person.id != null) {
                personCardModalNewTopic.href = `index.php?person_id=${encodeURIComponent(String(person.id))}`;
            } else {
                personCardModalNewTopic.href = "index.php";
            }
            if (doneCount > 0) {
                personCardModalToggleDone.disabled = false;
                personCardModalToggleDone.textContent = personModalShowResolved
                    ? "Ocultar realizados"
                    : `Mostrar realizados (${doneCount})`;
            } else {
                personCardModalToggleDone.disabled = true;
                personCardModalToggleDone.textContent = "Mostrar realizados";
            }
        }

        personCardModalTopics.innerHTML = "";
        if (topics.length === 0) {
            personCardModalTopics.innerHTML =
                '<p class="muted person-card-modal__empty">No hay temas en esta tarjeta.</p>';
            return;
        }

        let visible;
        if (personModalShowResolved) {
            const open = topicsOpenOnly(topics);
            const done = topics.filter((t) => t.status === "done");
            visible = open.concat(done);
        } else {
            visible = topicsOpenOnly(topics);
        }

        if (visible.length === 0) {
            personCardModalTopics.innerHTML =
                '<p class="muted person-card-modal__empty">No hay temas pendientes. Pulsa «Mostrar realizados» arriba para ver los hechos.</p>';
            return;
        }

        visible.forEach((t) => {
            const row = document.createElement("div");
            row.className = "person-card-modal__topic";
            const isDone = t.status === "done";
            const pri = priorityLabel(t.priority);
            const imp = importanceLabel(t.importance);
            const pcl = priorityClass(t.priority);
            const icl = importanceClass(t.importance);
            row.innerHTML = `
                <label class="person-card-modal__topic-check">
                    <input type="checkbox" class="person-card-modal__checkbox" data-topic-id="${t.id}"
                        ${isDone ? "checked" : ""}
                        aria-label="Tema ${t.id}, marcar como realizado" />
                </label>
                <div class="person-card-modal__topic-main">
                    <strong class="${isDone ? "person-card-modal__topic-title person-card-modal__topic-title--done" : "person-card-modal__topic-title"}">${escapeHtml(t.title)}</strong>
                    <div class="person-card-modal__topic-meta muted">
                        <span class="${pcl}" title="Urgencia">${escapeHtml(pri)}</span>
                        <span class="${icl}" title="Importancia">${escapeHtml(imp)}</span>
                        <span>· #${t.id} · ${escapeHtml(t.status)}</span>
                    </div>
                    ${topicDatesLine(t)}
                </div>
                <button type="button" class="btn btn--small" data-edit-topic="${t.id}">Editar</button>
            `;
            personCardModalTopics.appendChild(row);
        });
    }

    function openPersonCardModalPerson(personId) {
        const entry = boardSnapshot.people.find(
            (p) => p.person && p.person.id === personId
        );
        if (!entry) return;
        personModalOpen = { kind: "person", personId };
        personModalShowResolved = false;
        fillPersonCardModal(entry.person, entry.topics, false);
        if (personCardModal) {
            personCardModal.hidden = false;
            document.body.style.overflow = "hidden";
        }
    }

    function openPersonCardModalUnassigned() {
        personModalOpen = { kind: "unassigned", personId: null };
        personModalShowResolved = false;
        fillPersonCardModal(null, boardSnapshot.unassigned, true);
        if (personCardModal) {
            personCardModal.hidden = false;
            document.body.style.overflow = "hidden";
        }
    }

    function refreshPersonCardModalAfterBoardLoad() {
        if (!personCardModal || personCardModal.hidden) return;
        if (personModalOpen.kind === "person" && personModalOpen.personId != null) {
            const entry = boardSnapshot.people.find(
                (p) => p.person && p.person.id === personModalOpen.personId
            );
            if (entry) {
                fillPersonCardModal(entry.person, entry.topics, false);
            } else {
                closePersonCardModal();
            }
        } else if (personModalOpen.kind === "unassigned") {
            fillPersonCardModal(null, boardSnapshot.unassigned, true);
        }
    }

    async function patchTopicCompleted(topicId, completed) {
        const teamId = getTeamId();
        const res = await fetch(topicOneUrl, {
            method: "PATCH",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({
                topic_id: topicId,
                team_id: teamId,
                completed: completed,
            }),
        });
        const data = await res.json().catch(() => ({}));
        if (res.status === 401) {
            window.location.href = "login.php";
            return;
        }
        if (!res.ok || !data.ok) {
            throw new Error(data.error || "No se pudo actualizar");
        }
    }

    async function loadTeamPeopleOptions() {
        const teamId = getTeamId();
        if (!ptePersonSelect || !teamId) return;
        const res = await fetch(
            `${teamPeopleUrl}?team_id=${encodeURIComponent(String(teamId))}`,
            { credentials: "same-origin" }
        );
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok || !Array.isArray(data.people)) {
            throw new Error(data.error || "No se pudieron cargar las personas");
        }
        ptePersonSelect.innerHTML = "";
        data.people.forEach((p) => {
            const opt = document.createElement("option");
            opt.value = String(p.id);
            opt.textContent = p.display_name || `Tarjeta #${p.id}`;
            ptePersonSelect.appendChild(opt);
        });
    }

    async function openTopicEditModal(topicId) {
        const teamId = getTeamId();
        if (!teamId || !personTopicEditForm) return;
        if (personTopicEditError) {
            personTopicEditError.hidden = true;
            personTopicEditError.textContent = "";
        }
        const res = await fetch(
            `${topicOneUrl}?id=${encodeURIComponent(String(topicId))}&team_id=${encodeURIComponent(String(teamId))}`,
            { credentials: "same-origin" }
        );
        const data = await res.json().catch(() => ({}));
        if (res.status === 401) {
            window.location.href = "login.php";
            return;
        }
        if (!res.ok || !data.ok || !data.topic) {
            alert(data.error || "No se pudo cargar el tema");
            return;
        }
        const t = data.topic;
        document.getElementById("pteTopicId").value = String(t.id);
        document.getElementById("pteTeamId").value = String(teamId);
        document.getElementById("pteTitle").value = t.title || "";
        document.getElementById("pteBody").value = t.body || "";
        document.getElementById("ptePriority").value = t.priority || "medium";
        document.getElementById("pteImportance").value = t.importance || "medium";

        const pid = t.person_id != null ? Number(t.person_id) : NaN;
        if (Number.isFinite(pid) && pid > 0) {
            if (ptePersonWrap) ptePersonWrap.hidden = true;
            if (ptePersonId) ptePersonId.value = String(pid);
        } else {
            if (ptePersonId) ptePersonId.value = "";
            if (ptePersonWrap) ptePersonWrap.hidden = false;
            try {
                await loadTeamPeopleOptions();
            } catch (e) {
                alert(e instanceof Error ? e.message : "Error");
                return;
            }
            if (ptePersonSelect && ptePersonSelect.options.length > 0) {
                ptePersonSelect.value = ptePersonSelect.options[0].value;
            }
        }

        personTopicEditModal.hidden = false;
        document.getElementById("pteTitle").focus();
    }

    function renderBoard(people, unassigned) {
        if (!board) return;
        boardSnapshot = { people: people.slice(), unassigned: unassigned.slice() };
        board.innerHTML = "";
        const grid = document.createElement("div");
        grid.className = "people-grid";

        people.forEach(({ person, topics }) => {
            const card = document.createElement("article");
            card.className = "person-card person-card--interactive";
            card.setAttribute("data-person-id", String(person.id));
            card.setAttribute("tabindex", "0");
            card.setAttribute("role", "button");
            card.setAttribute(
                "aria-label",
                `Abrir detalle y temas de ${person.display_name || "persona"}`
            );
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
            card.className =
                "person-card person-card--unassigned person-card--interactive";
            card.setAttribute("data-card", "unassigned");
            card.setAttribute("tabindex", "0");
            card.setAttribute("role", "button");
            card.setAttribute(
                "aria-label",
                "Abrir temas sin tarjeta asignada"
            );
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
            board.innerHTML =
                '<p class="muted">No hay tarjetas. Añádelas en <a href="people-edit.php">Editar fichas</a>.</p>';
            syncPeopleSearchFromBoard();
            return;
        }

        board.appendChild(grid);
        syncPeopleSearchFromBoard();
        refreshPersonCardModalAfterBoardLoad();
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
            const unassigned = Array.isArray(data.unassigned_topics)
                ? data.unassigned_topics
                : [];
            if (!data.people.length && !unassigned.length) {
                boardSnapshot = { people: [], unassigned: [] };
                board.innerHTML =
                    '<p class="muted">No hay tarjetas. Añádelas en <a href="people-edit.php">Editar fichas</a>.</p>';
                refreshPersonCardModalAfterBoardLoad();
                syncPeopleSearchFromBoard();
                return;
            }
            renderBoard(data.people, unassigned);
        } catch (e) {
            board.innerHTML = `<p class="form-error" role="alert">${escapeHtml(e instanceof Error ? e.message : "Error")}</p>`;
            if (peoplePersonSearch) {
                peoplePersonSearch.disabled = true;
                peoplePersonSearch.placeholder = "Error al cargar el tablero";
                peoplePersonSearch.value = "";
            }
            if (peoplePersonListbox) {
                peoplePersonListbox.innerHTML = "";
            }
            setPeopleSearchListboxOpen(false);
        }
    }

    board?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        const card = t.closest(".person-card--interactive");
        if (!card || !board.contains(card)) return;
        e.preventDefault();
        if (card.getAttribute("data-card") === "unassigned") {
            openPersonCardModalUnassigned();
        } else {
            const pid = Number(card.getAttribute("data-person-id"));
            if (Number.isFinite(pid)) openPersonCardModalPerson(pid);
        }
    });

    board?.addEventListener("keydown", (e) => {
        if (e.key !== "Enter" && e.key !== " ") return;
        const t = e.target;
        if (!(t instanceof Element)) return;
        const card = t.closest(".person-card--interactive");
        if (!card || !board.contains(card)) return;
        e.preventDefault();
        if (card.getAttribute("data-card") === "unassigned") {
            openPersonCardModalUnassigned();
        } else {
            const pid = Number(card.getAttribute("data-person-id"));
            if (Number.isFinite(pid)) openPersonCardModalPerson(pid);
        }
    });

    personCardModal?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (t.closest("[data-person-card-close]")) {
            closePersonCardModal();
        }
    });

    personCardModalToggleDone?.addEventListener("click", () => {
        if (personCardModalToggleDone.disabled) {
            return;
        }
        personModalShowResolved = !personModalShowResolved;
        if (personModalOpen.kind === "person" && personModalOpen.personId != null) {
            const entry = boardSnapshot.people.find(
                (p) => p.person && p.person.id === personModalOpen.personId
            );
            if (entry) {
                fillPersonCardModal(entry.person, entry.topics, false);
            }
        } else if (personModalOpen.kind === "unassigned") {
            fillPersonCardModal(null, boardSnapshot.unassigned, true);
        }
    });

    personCardModalTopics?.addEventListener("change", async (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement) || !t.classList.contains("person-card-modal__checkbox")) {
            return;
        }
        const id = Number(t.getAttribute("data-topic-id"));
        if (!Number.isFinite(id)) return;
        const checked = t.checked;
        t.disabled = true;
        try {
            await patchTopicCompleted(id, checked);
            await loadBoard();
        } catch (err) {
            t.checked = !checked;
            alert(err instanceof Error ? err.message : "Error");
        } finally {
            t.disabled = false;
        }
    });

    personCardModalTopics?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        const btn = t.closest("[data-edit-topic]");
        if (!btn || !personCardModalTopics.contains(btn)) return;
        const raw = btn.getAttribute("data-edit-topic");
        const topicId = raw ? Number(raw) : NaN;
        if (!Number.isFinite(topicId)) return;
        e.preventDefault();
        e.stopPropagation();
        openTopicEditModal(topicId);
    });

    document.addEventListener("keydown", (e) => {
        if (e.key !== "Escape") return;
        if (personTopicEditModal && !personTopicEditModal.hidden) {
            closeTopicEditModal();
        } else if (personCardModal && !personCardModal.hidden) {
            closePersonCardModal();
        }
    });

    personTopicEditModal?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (t.closest("[data-pte-close]")) {
            closeTopicEditModal();
        }
    });

    personTopicEditForm?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (personTopicEditError) {
            personTopicEditError.hidden = true;
            personTopicEditError.textContent = "";
        }
        const teamId = getTeamId();
        const topicId = Number(document.getElementById("pteTopicId").value);
        let personId = 0;
        if (ptePersonWrap && !ptePersonWrap.hidden && ptePersonSelect) {
            personId = Number(ptePersonSelect.value);
        } else if (ptePersonId) {
            personId = Number(ptePersonId.value);
        }
        const title = String(document.getElementById("pteTitle").value || "").trim();
        let body = String(document.getElementById("pteBody").value || "").trim();
        if (body === "") body = null;
        const priority = String(document.getElementById("ptePriority").value || "medium");
        const importance = String(document.getElementById("pteImportance").value || "medium");

        if (!Number.isFinite(topicId) || topicId < 1) return;
        if (!Number.isFinite(personId) || personId < 1) {
            if (personTopicEditError) {
                personTopicEditError.textContent = "Elige una persona (tarjeta) para el tema.";
                personTopicEditError.hidden = false;
            }
            return;
        }
        if (title === "") {
            if (personTopicEditError) {
                personTopicEditError.textContent = "El título es obligatorio.";
                personTopicEditError.hidden = false;
            }
            return;
        }

        pteSubmit?.classList.add("loading");
        if (pteSubmit) pteSubmit.disabled = true;
        try {
            const res = await fetch(topicOneUrl, {
                method: "PATCH",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({
                    topic_id: topicId,
                    team_id: teamId,
                    title,
                    body,
                    priority,
                    importance,
                    person_id: personId,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo guardar");
            }
            closeTopicEditModal();
            await loadBoard();
            refreshPersonCardModalAfterBoardLoad();
        } catch (err) {
            if (personTopicEditError) {
                personTopicEditError.textContent =
                    err instanceof Error ? err.message : "Error";
                personTopicEditError.hidden = false;
            }
        } finally {
            pteSubmit?.classList.remove("loading");
            if (pteSubmit) pteSubmit.disabled = false;
        }
    });

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }

        if (window.ColmenaBirthday && form) {
            window.ColmenaBirthday.syncBirthdayHidden(form);
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
            document.dispatchEvent(new CustomEvent("colmena:person-created"));
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

    const peoplePersonSearch = document.getElementById("peoplePersonSearch");
    const peoplePersonListbox = document.getElementById("peoplePersonListbox");
    const peoplePersonCombobox = document.getElementById("peoplePersonCombobox");

    let peopleSearchBlurTimer = 0;

    function labelPerson(p) {
        const r =
            p.role && String(p.role).trim() !== ""
                ? String(p.role).trim()
                : "";
        return r ? `${p.display_name} (${r})` : p.display_name;
    }

    function getBoardPersonObjects() {
        return boardSnapshot.people
            .map((e) => e.person)
            .filter((p) => p && p.id != null);
    }

    function filterBoardPeople(query) {
        const q = String(query || "")
            .trim()
            .toLowerCase();
        const list = getBoardPersonObjects();
        if (!q) {
            return list.slice();
        }
        const words = q.split(/\s+/).filter(Boolean);
        return list.filter((p) => {
            const email = p.email && String(p.email).trim() !== "" ? String(p.email).trim() : "";
            const hay = `${labelPerson(p)} ${email}`.toLowerCase();
            return words.every((w) => hay.includes(w));
        });
    }

    function setPeopleSearchListboxOpen(open) {
        if (!peoplePersonListbox || !peoplePersonSearch) {
            return;
        }
        peoplePersonListbox.hidden = !open;
        peoplePersonSearch.setAttribute("aria-expanded", open ? "true" : "false");
    }

    function renderPeopleSearchListbox(matches) {
        if (!peoplePersonListbox) {
            return;
        }
        peoplePersonListbox.innerHTML = "";
        const max = 60;
        const slice = matches.slice(0, max);
        slice.forEach((p) => {
            const li = document.createElement("li");
            li.className = "topic-person-combobox__option";
            li.setAttribute("role", "option");
            li.setAttribute("data-id", String(p.id));
            li.textContent = labelPerson(p);
            peoplePersonListbox.appendChild(li);
        });
        if (matches.length > max) {
            const li = document.createElement("li");
            li.className = "topic-person-combobox__hint muted";
            li.textContent = `Mostrando ${max} de ${matches.length}. Refina la búsqueda.`;
            peoplePersonListbox.appendChild(li);
        }
        setPeopleSearchListboxOpen(slice.length > 0);
    }

    function openPeopleSearchForQuery() {
        if (!peoplePersonSearch) {
            return;
        }
        const matches = filterBoardPeople(peoplePersonSearch.value);
        renderPeopleSearchListbox(matches);
        if (
            matches.length === 0 &&
            getBoardPersonObjects().length > 0 &&
            peoplePersonListbox
        ) {
            peoplePersonListbox.innerHTML = "";
            const li = document.createElement("li");
            li.className = "topic-person-combobox__hint muted";
            li.textContent = "Ninguna persona coincide. Prueba otro texto.";
            peoplePersonListbox.appendChild(li);
            setPeopleSearchListboxOpen(true);
        }
    }

    function syncPeopleSearchFromBoard() {
        if (!peoplePersonSearch) {
            return;
        }
        const n = getBoardPersonObjects().length;
        if (n === 0) {
            peoplePersonSearch.disabled = true;
            peoplePersonSearch.placeholder = "No hay tarjetas. Añádelas en Editar fichas.";
            peoplePersonSearch.value = "";
            if (peoplePersonListbox) {
                peoplePersonListbox.innerHTML = "";
            }
            setPeopleSearchListboxOpen(false);
        } else {
            peoplePersonSearch.disabled = false;
            peoplePersonSearch.placeholder =
                "Escribe para buscar por nombre, rol o email…";
        }
    }

    function scrollPersonCardIntoView(personId) {
        if (!board) {
            return;
        }
        const card = board.querySelector(
            `[data-person-id="${String(personId)}"]`
        );
        if (card && typeof card.scrollIntoView === "function") {
            card.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }

    function selectPersonFromSearch(personId, labelText) {
        const id = Number(personId);
        if (!Number.isFinite(id)) {
            return;
        }
        if (peoplePersonSearch) {
            peoplePersonSearch.value = labelText;
        }
        setPeopleSearchListboxOpen(false);
        openPersonCardModalPerson(id);
        scrollPersonCardIntoView(id);
    }

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

    peoplePersonSearch?.addEventListener("input", () => {
        openPeopleSearchForQuery();
    });

    peoplePersonSearch?.addEventListener("focus", () => {
        if (peopleSearchBlurTimer) {
            window.clearTimeout(peopleSearchBlurTimer);
            peopleSearchBlurTimer = 0;
        }
        if (getBoardPersonObjects().length > 0) {
            openPeopleSearchForQuery();
        }
    });

    peoplePersonSearch?.addEventListener("blur", () => {
        peopleSearchBlurTimer = window.setTimeout(() => {
            setPeopleSearchListboxOpen(false);
        }, 200);
    });

    peoplePersonSearch?.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && peoplePersonListbox && !peoplePersonListbox.hidden) {
            e.stopPropagation();
            setPeopleSearchListboxOpen(false);
            return;
        }
        if (e.key !== "Enter") {
            return;
        }
        const first = peoplePersonListbox?.querySelector(
            ".topic-person-combobox__option[data-id]"
        );
        if (!first) {
            return;
        }
        e.preventDefault();
        const id = first.getAttribute("data-id");
        const txt = first.textContent || "";
        if (id) {
            selectPersonFromSearch(id, txt);
        }
    });

    peoplePersonListbox?.addEventListener("mousedown", (e) => {
        const opt =
            e.target && e.target.closest
                ? e.target.closest(".topic-person-combobox__option")
                : null;
        if (!opt || !peoplePersonListbox.contains(opt)) {
            return;
        }
        e.preventDefault();
        const id = opt.getAttribute("data-id");
        const txt = opt.textContent || "";
        if (id) {
            selectPersonFromSearch(id, txt);
        }
    });

    document.addEventListener("click", (e) => {
        if (!peoplePersonCombobox || !(e.target instanceof Node)) {
            return;
        }
        if (!peoplePersonCombobox.contains(e.target)) {
            setPeopleSearchListboxOpen(false);
        }
    });

    if (board) {
        loadBoard();
    }
})();
