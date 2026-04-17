/**
 * Temas: lista, realizado, modal crear/editar al pulsar fila.
 */
(function () {
    const modal = document.getElementById("topicModal");
    const openBtn = document.getElementById("openTopicModal");
    const form = document.getElementById("topicForm");
    const feed = document.getElementById("topicFeed");
    const submitBtn = document.getElementById("submitTopic");
    const submitLabel = document.getElementById("submitTopicLabel");
    const modalTitle = document.getElementById("modalTitle");
    const topicIdField = document.getElementById("topicIdField");
    const topicCompletedWrap = document.getElementById("topicCompletedWrap");
    const topicCompletedField = document.getElementById("topicCompletedField");
    const personIdHidden = document.getElementById("topicPersonId");
    const personSearchInput = document.getElementById("topicPersonSearch");
    const personListbox = document.getElementById("topicPersonListbox");
    const personComboboxRoot = document.getElementById("topicPersonCombobox");
    const showDoneToggle = document.getElementById("showCompletedTopics");
    const viewByImportanceToggle = document.getElementById(
        "topicViewByImportance"
    );
    const viewByPriorityToggle = document.getElementById("topicViewByPriority");
    const searchInput = document.getElementById("topicSearchFilter");
    const personFilterSelect = document.getElementById("topicPersonFilter");

    const apiUrl = "api/topics.php";
    const topicOneUrl = "api/topic.php";
    const peopleApi = "api/team-people.php";

    function getTeamId() {
        const input = document.querySelector('input[name="team_id"]');
        const n = input ? Number(input.value) : 0;
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function includeDoneParam() {
        return showDoneToggle && showDoneToggle.checked ? "1" : "0";
    }

    /** @type {Record<number, string>} */
    let personNames = {};

    /** @type {object[]} */
    let lastTopics = [];

    /** @type {string} */
    let lastIncludeDone = "0";
    let editingTopicInitialCompleted = false;

    /** Personas del equipo para el combobox del modal (objetos API) */
    /** @type {object[]} */
    let modalPeople = [];

    function personOptionLabel(p) {
        const r = p.role && String(p.role).trim() !== "" ? String(p.role).trim() : "";
        return r ? `${p.display_name} (${r})` : p.display_name;
    }

    function setPersonListboxOpen(open) {
        if (!personListbox || !personSearchInput) {
            return;
        }
        personListbox.hidden = !open;
        personSearchInput.setAttribute("aria-expanded", open ? "true" : "false");
    }

    function filterModalPeople(query) {
        const q = String(query || "").trim().toLowerCase();
        if (!q) {
            return modalPeople.slice();
        }
        const words = q.split(/\s+/).filter(Boolean);
        return modalPeople.filter((p) => {
            const hay = personOptionLabel(p).toLowerCase();
            return words.every((w) => hay.includes(w));
        });
    }

    function renderPersonListbox(matches) {
        if (!personListbox) {
            return;
        }
        personListbox.innerHTML = "";
        const max = 60;
        const slice = matches.slice(0, max);
        slice.forEach((p) => {
            const li = document.createElement("li");
            li.className = "topic-person-combobox__option";
            li.setAttribute("role", "option");
            li.setAttribute("data-id", String(p.id));
            li.textContent = personOptionLabel(p);
            personListbox.appendChild(li);
        });
        if (matches.length > max) {
            const li = document.createElement("li");
            li.className = "topic-person-combobox__hint muted";
            li.textContent = `Mostrando ${max} de ${matches.length}. Refina la búsqueda.`;
            personListbox.appendChild(li);
        }
        const hasOpts = slice.length > 0;
        setPersonListboxOpen(hasOpts);
    }

    function applyPersonSelection(personId, labelText) {
        if (!personIdHidden || !personSearchInput) {
            return;
        }
        personIdHidden.value = String(personId);
        personSearchInput.value = labelText;
        setPersonListboxOpen(false);
    }

    function clearPersonCombobox() {
        if (!personIdHidden || !personSearchInput) {
            return;
        }
        personIdHidden.value = "";
        personSearchInput.value = "";
        if (personListbox) {
            personListbox.innerHTML = "";
        }
        setPersonListboxOpen(false);
    }

    function openPersonListForQuery() {
        if (!personSearchInput) {
            return;
        }
        const matches = filterModalPeople(personSearchInput.value);
        renderPersonListbox(matches);
        if (matches.length === 0 && modalPeople.length > 0) {
            personListbox.innerHTML = "";
            const li = document.createElement("li");
            li.className = "topic-person-combobox__hint muted";
            li.textContent = "Ninguna persona coincide. Prueba otro texto.";
            personListbox.appendChild(li);
            setPersonListboxOpen(true);
        }
    }

    async function loadTeamPeople() {
        const teamId = getTeamId();
        if (!teamId || (!personIdHidden && !personFilterSelect)) {
            return;
        }
        const prevFilter = personFilterSelect ? personFilterSelect.value : "";
        if (personSearchInput) {
            personSearchInput.placeholder = "Cargando…";
            personSearchInput.disabled = true;
        }
        if (personFilterSelect) {
            personFilterSelect.innerHTML =
                '<option value="">Cargando…</option>';
            personFilterSelect.disabled = true;
        }
        try {
            const res = await fetch(
                `${peopleApi}?team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.people)) {
                throw new Error(data.error || "No se pudieron cargar las personas");
            }
            modalPeople = data.people;
            personNames = {};
            data.people.forEach((p) => {
                personNames[p.id] = personOptionLabel(p);
            });

            if (personSearchInput && personIdHidden) {
                if (data.people.length === 0) {
                    clearPersonCombobox();
                    personSearchInput.placeholder = "Primero crea tarjetas en Editar fichas";
                    personSearchInput.disabled = true;
                } else {
                    personSearchInput.placeholder =
                        "Escribe para buscar por nombre o rol…";
                    personSearchInput.disabled = false;
                }
                setPersonListboxOpen(false);
            }

            if (personFilterSelect) {
                personFilterSelect.innerHTML = "";
                const allOpt = document.createElement("option");
                allOpt.value = "";
                allOpt.textContent = "Todas las personas";
                personFilterSelect.appendChild(allOpt);
                data.people.forEach((p) => {
                    const opt = document.createElement("option");
                    opt.value = String(p.id);
                    opt.textContent = personOptionLabel(p);
                    personFilterSelect.appendChild(opt);
                });
                personFilterSelect.disabled = false;
                if (prevFilter) {
                    const hasPrev = Array.from(personFilterSelect.options).some(
                        (o) => o.value === prevFilter
                    );
                    personFilterSelect.value = hasPrev ? prevFilter : "";
                }
            }
        } catch (e) {
            modalPeople = [];
            if (personSearchInput && personIdHidden) {
                clearPersonCombobox();
                personSearchInput.placeholder = e instanceof Error ? e.message : "Error";
                personSearchInput.disabled = true;
            }
            if (personFilterSelect) {
                personFilterSelect.innerHTML =
                    '<option value="">Todas las personas</option>';
                personFilterSelect.disabled = false;
            }
        }
    }

    function showModal() {
        modal.hidden = false;
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = "";
        if (topicIdField) topicIdField.value = "";
        if (topicCompletedWrap) topicCompletedWrap.hidden = true;
        if (topicCompletedField) topicCompletedField.checked = false;
        editingTopicInitialCompleted = false;
        if (modalTitle) modalTitle.textContent = "Nuevo tema";
        if (submitLabel) submitLabel.textContent = "Crear tema";
    }

    async function openModalCreate(preselectedPersonId) {
        if (topicIdField) topicIdField.value = "";
        if (topicCompletedWrap) topicCompletedWrap.hidden = true;
        if (topicCompletedField) topicCompletedField.checked = false;
        editingTopicInitialCompleted = false;
        if (modalTitle) modalTitle.textContent = "Nuevo tema";
        if (submitLabel) submitLabel.textContent = "Crear tema";
        form.reset();
        showModal();
        await loadTeamPeople();
        if (preselectedPersonId != null) {
            const pid = Number(preselectedPersonId);
            if (Number.isFinite(pid) && pid > 0) {
                const match = modalPeople.find((p) => p.id === pid);
                const label =
                    personNames[pid] ||
                    (match ? personOptionLabel(match) : `Tarjeta #${pid}`);
                applyPersonSelection(pid, label);
            }
        }
        const first = form.querySelector("input[name=title]");
        if (first) first.focus();
    }

    async function openModalEdit(topicId) {
        const teamId = getTeamId();
        if (!teamId || !Number.isFinite(topicId)) return;
        await loadTeamPeople();
        try {
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
                throw new Error(data.error || "No se pudo cargar el tema");
            }
            const t = data.topic;
            if (topicIdField) topicIdField.value = String(t.id);
            if (topicCompletedWrap) topicCompletedWrap.hidden = false;
            if (topicCompletedField) {
                const isCompleted = t.status === "done";
                topicCompletedField.checked = isCompleted;
                editingTopicInitialCompleted = isCompleted;
            }
            if (modalTitle) modalTitle.textContent = "Editar tema";
            if (submitLabel) submitLabel.textContent = "Guardar cambios";

            const titleInput = form.querySelector('input[name="title"]');
            const bodyInput = form.querySelector('textarea[name="body"]');
            const pr = form.querySelector('select[name="priority"]');
            const im = form.querySelector('select[name="importance"]');
            if (titleInput) titleInput.value = t.title || "";
            if (bodyInput) bodyInput.value = t.body != null ? String(t.body) : "";
            if (pr) {
                let pv = t.priority || "medium";
                if (
                    !["very_low", "low", "medium", "high", "critical"].includes(pv)
                ) {
                    pv = "medium";
                }
                pr.value = pv;
            }
            if (im) {
                let iv = t.importance || "medium";
                if (
                    !["very_low", "low", "medium", "high", "very_high"].includes(iv)
                ) {
                    iv = "medium";
                }
                im.value = iv;
            }
            if (personIdHidden && personSearchInput) {
                if (t.person_id != null && t.person_id !== "") {
                    const pid = Number(t.person_id);
                    applyPersonSelection(
                        pid,
                        personNames[pid] || "Tarjeta #" + pid
                    );
                } else {
                    clearPersonCombobox();
                }
            }

            showModal();
            if (titleInput) titleInput.focus();
        } catch (err) {
            alert(err instanceof Error ? err.message : "Error");
        }
    }

    openBtn?.addEventListener("click", () => {
        openModalCreate();
    });

    modal?.addEventListener("click", (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (t.closest("[data-close]")) closeModal();
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && !modal.hidden) closeModal();
    });

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

    /** Orden fijo de columnas en vista por importancia */
    const IMPORTANCE_COLUMN_KEYS = [
        "very_low",
        "low",
        "medium",
        "high",
        "very_high",
    ];

    /** Orden fijo de columnas en vista por prioridad (urgencia) */
    const PRIORITY_COLUMN_KEYS = [
        "very_low",
        "low",
        "medium",
        "high",
        "critical",
    ];

    function normalizeImportance(val) {
        const v = val || "medium";
        return IMPORTANCE_COLUMN_KEYS.includes(v) ? v : "medium";
    }

    function normalizePriority(val) {
        const v = val || "medium";
        return PRIORITY_COLUMN_KEYS.includes(v) ? v : "medium";
    }

    function sortTopicsByUpdatedDesc(topics) {
        return [...topics].sort((a, b) => {
            const sa = String(a.updated_at || a.created_at || "").trim();
            const sb = String(b.updated_at || b.created_at || "").trim();
            const ta = Date.parse(sa.includes("T") ? sa : sa.replace(" ", "T")) || 0;
            const tb = Date.parse(sb.includes("T") ? sb : sb.replace(" ", "T")) || 0;
            return tb - ta;
        });
    }

    function priorityLabel(p) {
        return priorityLabels[p] || priorityLabels.medium;
    }

    function importanceLabel(i) {
        return importanceLabels[i] || importanceLabels.medium;
    }

    function personLabel(topic) {
        const pid = topic.person_id;
        if (pid == null || pid === "") return "Sin tarjeta";
        const id = Number(pid);
        return personNames[id] || "Tarjeta #" + id;
    }

    function formatTopicDt(iso) {
        if (!iso) return "";
        const s = String(iso).trim();
        const norm = s.includes("T") ? s : s.replace(" ", "T");
        const d = new Date(norm);
        if (Number.isNaN(d.getTime())) return s;
        return d.toLocaleString("es", { dateStyle: "short", timeStyle: "short" });
    }

    function topicSearchHaystack(topic) {
        return [
            topic.title,
            topic.body || "",
            String(topic.id),
            personLabel(topic),
            priorityLabel(topic.priority),
            importanceLabel(topic.importance),
            topic.status || "",
        ].join(" ");
    }

    /**
     * @param {object[]} topics
     * @param {string} rawQuery
     */
    function filterTopicsBySearch(topics, rawQuery) {
        const q = rawQuery.trim().toLowerCase();
        if (!q) {
            return topics;
        }
        const words = q.split(/\s+/).filter(Boolean);
        return topics.filter((topic) => {
            const hay = topicSearchHaystack(topic).toLowerCase();
            return words.every((w) => hay.includes(w));
        });
    }

    /**
     * @param {object[]} topics
     * @param {string} personIdStr
     */
    function filterTopicsByPerson(topics, personIdStr) {
        const pid = personIdStr ? Number(personIdStr) : NaN;
        if (!Number.isFinite(pid) || pid < 1) {
            return topics;
        }
        return topics.filter((t) => {
            const id =
                t.person_id != null && t.person_id !== ""
                    ? Number(t.person_id)
                    : NaN;
            return Number.isFinite(id) && id === pid;
        });
    }

    function renderTopicFeed() {
        if (!feed) {
            return;
        }
        const personVal = personFilterSelect ? personFilterSelect.value : "";
        const byPerson = filterTopicsByPerson(lastTopics, personVal);
        const q = searchInput ? searchInput.value : "";
        const filtered = filterTopicsBySearch(byPerson, q);
        const viewByImportance =
            viewByImportanceToggle && viewByImportanceToggle.checked;
        const viewByPriority =
            viewByPriorityToggle && viewByPriorityToggle.checked;

        feed.innerHTML = "";
        feed.className = "topic-feed-root";

        function appendEmptyMessage(text) {
            const ul = document.createElement("ul");
            ul.className = "feed feed--tasks";
            const empty = document.createElement("li");
            empty.className = "feed__empty muted";
            empty.textContent = text;
            ul.appendChild(empty);
            feed.appendChild(ul);
        }

        if (lastTopics.length === 0) {
            appendEmptyMessage(
                lastIncludeDone === "1"
                    ? "No hay temas (ni pendientes ni realizados)."
                    : "No hay temas pendientes. Activa «Mostrar realizados» para ver los hechos."
            );
            return;
        }
        if (filtered.length === 0) {
            appendEmptyMessage(
                byPerson.length === 0
                    ? "No hay temas asignados a esta persona."
                    : "Ningún tema coincide con la búsqueda."
            );
            return;
        }

        if (viewByPriority) {
            feed.classList.add("topic-feed-root--board");
            const board = document.createElement("div");
            board.className = "topic-importance-board";
            board.setAttribute("role", "region");
            board.setAttribute(
                "aria-label",
                "Temas agrupados por nivel de prioridad (urgencia)"
            );

            /** @type {Record<string, object[]>} */
            const groups = {};
            PRIORITY_COLUMN_KEYS.forEach((k) => {
                groups[k] = [];
            });
            filtered.forEach((t) => {
                groups[normalizePriority(t.priority)].push(t);
            });

            PRIORITY_COLUMN_KEYS.forEach((key) => {
                const col = document.createElement("div");
                col.className = "topic-importance-col";
                const h3 = document.createElement("h3");
                h3.className = "topic-importance-col__title";
                h3.textContent = priorityLabels[key];
                const ul = document.createElement("ul");
                ul.className = "feed feed--tasks feed--in-column";
                const sorted = sortTopicsByUpdatedDesc(groups[key]);
                if (sorted.length === 0) {
                    const emptyLi = document.createElement("li");
                    emptyLi.className =
                        "topic-importance-col__empty muted feed__empty";
                    emptyLi.textContent = "Sin temas";
                    ul.appendChild(emptyLi);
                } else {
                    sorted.forEach((topic) => {
                        ul.appendChild(renderTopicCard(topic));
                    });
                }
                col.appendChild(h3);
                col.appendChild(ul);
                board.appendChild(col);
            });

            feed.appendChild(board);
            return;
        }

        if (viewByImportance) {
            feed.classList.add("topic-feed-root--board");
            const board = document.createElement("div");
            board.className = "topic-importance-board";
            board.setAttribute("role", "region");
            board.setAttribute(
                "aria-label",
                "Temas agrupados por nivel de importancia"
            );

            /** @type {Record<string, object[]>} */
            const groups = {};
            IMPORTANCE_COLUMN_KEYS.forEach((k) => {
                groups[k] = [];
            });
            filtered.forEach((t) => {
                groups[normalizeImportance(t.importance)].push(t);
            });

            IMPORTANCE_COLUMN_KEYS.forEach((key) => {
                const col = document.createElement("div");
                col.className = "topic-importance-col";
                const h3 = document.createElement("h3");
                h3.className = "topic-importance-col__title";
                h3.textContent = importanceLabels[key];
                const ul = document.createElement("ul");
                ul.className = "feed feed--tasks feed--in-column";
                const sorted = sortTopicsByUpdatedDesc(groups[key]);
                if (sorted.length === 0) {
                    const emptyLi = document.createElement("li");
                    emptyLi.className =
                        "topic-importance-col__empty muted feed__empty";
                    emptyLi.textContent = "Sin temas";
                    ul.appendChild(emptyLi);
                } else {
                    sorted.forEach((topic) => {
                        ul.appendChild(renderTopicCard(topic));
                    });
                }
                col.appendChild(h3);
                col.appendChild(ul);
                board.appendChild(col);
            });

            feed.appendChild(board);
            return;
        }

        const ul = document.createElement("ul");
        ul.className = "feed feed--tasks";
        filtered.forEach((topic) => ul.appendChild(renderTopicCard(topic)));
        feed.appendChild(ul);
    }

    function renderTopicCard(topic) {
        const li = document.createElement("li");
        li.className = "feed__item";
        const pri = priorityLabel(topic.priority);
        const imp = importanceLabel(topic.importance);
        const isDone = topic.status === "done";
        const showDone = showDoneToggle && showDoneToggle.checked;
        li.innerHTML = `
            <label class="feed__check" title="Marcar como realizado">
                <input type="checkbox" class="feed__checkbox" data-topic-id="${topic.id}"
                    ${isDone ? "checked" : ""}
                    aria-label="Realizado: ${escapeHtml(topic.title)}" />
            </label>
            <div class="feed__body" role="button" tabindex="0" data-topic-id="${topic.id}" title="Editar tema">
                <strong class="${isDone ? "feed__title feed__title--done" : "feed__title"}">${escapeHtml(topic.title)}</strong>
                <div class="meta">#${topic.id} · Urg.: ${escapeHtml(pri)} · Imp.: ${escapeHtml(imp)} · ${escapeHtml(
            topic.status
        )} · ${escapeHtml(personLabel(topic))}</div>
                <div class="meta meta--dates">Creado: ${escapeHtml(formatTopicDt(topic.created_at))}${
            topic.completed_at
                ? ` · Realizado: ${escapeHtml(formatTopicDt(topic.completed_at))}`
                : ""
        }</div>
            </div>
        `;
        if (showDone && isDone) {
            li.classList.add("feed__item--done");
        }
        return li;
    }

    async function loadTopics() {
        const teamId = getTeamId();
        if (!teamId || !feed) return;
        await loadTeamPeople();
        try {
            const inc = includeDoneParam();
            const res = await fetch(
                `${apiUrl}?team_id=${encodeURIComponent(String(teamId))}&include_done=${encodeURIComponent(inc)}`,
                { method: "GET", credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.topics)) {
                throw new Error(data.error || "No se pudieron cargar los temas");
            }
            lastTopics = data.topics;
            lastIncludeDone = inc;
            renderTopicFeed();
        } catch (err) {
            console.error(err);
        }
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    feed?.addEventListener("click", (e) => {
        const t = e.target;
        if (t instanceof HTMLElement && t.closest(".feed__check")) return;
        if (t instanceof HTMLInputElement && t.classList.contains("feed__checkbox")) return;
        const body = t instanceof HTMLElement ? t.closest(".feed__body") : null;
        if (!body || !feed.contains(body)) return;
        const id = Number(body.getAttribute("data-topic-id"));
        if (!Number.isFinite(id)) return;
        e.preventDefault();
        openModalEdit(id);
    });

    feed?.addEventListener("keydown", (e) => {
        if (e.key !== "Enter" && e.key !== " ") return;
        const t = e.target;
        if (!(t instanceof HTMLElement) || !t.classList.contains("feed__body")) return;
        e.preventDefault();
        const id = Number(t.getAttribute("data-topic-id"));
        if (Number.isFinite(id)) openModalEdit(id);
    });

    async function patchCompleted(topicId, completed) {
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
        return data.topic;
    }

    feed?.addEventListener("change", async (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement) || !t.classList.contains("feed__checkbox")) return;
        const id = Number(t.getAttribute("data-topic-id"));
        if (!Number.isFinite(id)) return;
        const checked = t.checked;
        t.disabled = true;
        try {
            await patchCompleted(id, checked);
            await loadTopics();
        } catch (err) {
            t.checked = !checked;
            alert(err instanceof Error ? err.message : "Error");
        } finally {
            t.disabled = false;
        }
    });

    showDoneToggle?.addEventListener("change", () => {
        loadTopics();
    });

    viewByImportanceToggle?.addEventListener("change", () => {
        if (viewByImportanceToggle.checked && viewByPriorityToggle) {
            viewByPriorityToggle.checked = false;
        }
        renderTopicFeed();
    });

    viewByPriorityToggle?.addEventListener("change", () => {
        if (viewByPriorityToggle.checked && viewByImportanceToggle) {
            viewByImportanceToggle.checked = false;
        }
        renderTopicFeed();
    });

    searchInput?.addEventListener("input", () => {
        renderTopicFeed();
    });

    personFilterSelect?.addEventListener("change", () => {
        renderTopicFeed();
    });

    let personComboboxBlurTimer = 0;

    personSearchInput?.addEventListener("input", () => {
        if (personIdHidden && personIdHidden.value) {
            const expected = personNames[Number(personIdHidden.value)];
            if (
                personSearchInput &&
                personSearchInput.value.trim() !== expected
            ) {
                personIdHidden.value = "";
            }
        }
        openPersonListForQuery();
    });

    personSearchInput?.addEventListener("focus", () => {
        if (personComboboxBlurTimer) {
            window.clearTimeout(personComboboxBlurTimer);
            personComboboxBlurTimer = 0;
        }
        if (modalPeople.length > 0) {
            openPersonListForQuery();
        }
    });

    personSearchInput?.addEventListener("blur", () => {
        personComboboxBlurTimer = window.setTimeout(() => {
            setPersonListboxOpen(false);
            if (personIdHidden && personSearchInput) {
                const id = personIdHidden.value;
                if (id) {
                    const label = personNames[Number(id)];
                    if (label && personSearchInput.value.trim() !== label) {
                        personIdHidden.value = "";
                    }
                }
            }
        }, 200);
    });

    personSearchInput?.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && personListbox && !personListbox.hidden) {
            e.stopPropagation();
            setPersonListboxOpen(false);
        }
    });

    personListbox?.addEventListener("mousedown", (e) => {
        const opt = e.target && e.target.closest
            ? e.target.closest(".topic-person-combobox__option")
            : null;
        if (!opt || !personListbox.contains(opt)) {
            return;
        }
        e.preventDefault();
        const id = opt.getAttribute("data-id");
        const txt = opt.textContent || "";
        if (id) {
            applyPersonSelection(id, txt);
        }
    });

    document.addEventListener("click", (e) => {
        if (
            modal?.hidden ||
            !personComboboxRoot ||
            !(e.target instanceof Node)
        ) {
            return;
        }
        if (!personComboboxRoot.contains(e.target)) {
            setPersonListboxOpen(false);
        }
    });

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        const personId = Number(fd.get("person_id"));
        const editId = topicIdField && topicIdField.value ? Number(topicIdField.value) : 0;
        const payload = {
            team_id: Number(fd.get("team_id")),
            person_id: personId,
            title: String(fd.get("title") || "").trim(),
            body: String(fd.get("body") || "").trim() || null,
            priority: String(fd.get("priority") || "medium"),
            importance: String(fd.get("importance") || "medium"),
        };

        if (!Number.isFinite(personId) || personId < 1) {
            alert("Elige una persona (tarjeta) para el tema.");
            return;
        }

        submitBtn.classList.add("loading");
        submitBtn.disabled = true;

        try {
            if (Number.isFinite(editId) && editId > 0) {
                const res = await fetch(topicOneUrl, {
                    method: "PATCH",
                    headers: { "Content-Type": "application/json" },
                    credentials: "same-origin",
                    body: JSON.stringify({
                        topic_id: editId,
                        ...payload,
                    }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.status === 401) {
                    window.location.href = "login.php";
                    return;
                }
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || res.statusText);
                }
                const requestedCompleted =
                    !!topicCompletedField &&
                    !!topicCompletedWrap &&
                    !topicCompletedWrap.hidden &&
                    topicCompletedField.checked;
                if (requestedCompleted !== editingTopicInitialCompleted) {
                    await patchCompleted(editId, requestedCompleted);
                }
                if (data.topic && data.topic.person_id != null) {
                    const pid = Number(data.topic.person_id);
                    personNames[pid] =
                        personSearchInput?.value?.trim() || personNames[pid] || "";
                }
            } else {
                const res = await fetch(apiUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload),
                });
                const data = await res.json().catch(() => ({}));

                if (res.status === 401) {
                    window.location.href = "login.php";
                    return;
                }
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || res.statusText);
                }

                if (data.topic && data.topic.person_id != null) {
                    const pid = Number(data.topic.person_id);
                    personNames[pid] =
                        personSearchInput?.value?.trim() ||
                        personNames[pid] ||
                        personLabel(data.topic);
                }
            }

            form.reset();
            if (topicIdField) topicIdField.value = "";
            if (modalTitle) modalTitle.textContent = "Nuevo tema";
            if (submitLabel) submitLabel.textContent = "Crear tema";
            await loadTeamPeople();
            closeModal();
            await loadTopics();
        } catch (err) {
            alert(err instanceof Error ? err.message : "Error al guardar el tema");
        } finally {
            submitBtn.classList.remove("loading");
            submitBtn.disabled = false;
        }
    });

    loadTopics();

    (function openTopicFromQuery() {
        const params = new URLSearchParams(window.location.search);
        const tid = params.get("topic");
        if (tid) {
            const n = Number(tid);
            if (!Number.isFinite(n) || n < 1) {
                return;
            }
            openModalEdit(n).then(() => {
                try {
                    const u = new URL(window.location.href);
                    u.searchParams.delete("topic");
                    const qs = u.searchParams.toString();
                    window.history.replaceState(
                        {},
                        "",
                        u.pathname + (qs ? "?" + qs : "") + (u.hash || "")
                    );
                } catch (e) {
                    /* ignore */
                }
            });
            return;
        }
        const pidRaw = params.get("person_id");
        if (!pidRaw) {
            return;
        }
        const pid = Number(pidRaw);
        if (!Number.isFinite(pid) || pid < 1) {
            return;
        }
        openModalCreate(pid).then(() => {
            try {
                const u = new URL(window.location.href);
                u.searchParams.delete("person_id");
                const qs = u.searchParams.toString();
                window.history.replaceState(
                    {},
                    "",
                    u.pathname + (qs ? "?" + qs : "") + (u.hash || "")
                );
            } catch (e) {
                /* ignore */
            }
        });
    })();
})();
