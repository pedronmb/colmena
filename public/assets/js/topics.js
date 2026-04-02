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
    const personSelect = document.getElementById("topicPerson");
    const showDoneToggle = document.getElementById("showCompletedTopics");
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

    function personOptionLabel(p) {
        const r = p.role && String(p.role).trim() !== "" ? String(p.role).trim() : "";
        return r ? `${p.display_name} (${r})` : p.display_name;
    }

    async function loadTeamPeople() {
        const teamId = getTeamId();
        if (!teamId || (!personSelect && !personFilterSelect)) {
            return;
        }
        const prevFilter = personFilterSelect ? personFilterSelect.value : "";
        if (personSelect) {
            personSelect.innerHTML = '<option value="">Cargando…</option>';
            personSelect.disabled = true;
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
            personNames = {};
            data.people.forEach((p) => {
                personNames[p.id] = personOptionLabel(p);
            });

            if (personSelect) {
                personSelect.innerHTML = "";
                if (data.people.length === 0) {
                    const opt = document.createElement("option");
                    opt.value = "";
                    opt.textContent = "Primero crea tarjetas en Personas";
                    personSelect.appendChild(opt);
                    personSelect.disabled = true;
                } else {
                    data.people.forEach((p) => {
                        const opt = document.createElement("option");
                        opt.value = String(p.id);
                        opt.textContent = personOptionLabel(p);
                        personSelect.appendChild(opt);
                    });
                    personSelect.disabled = false;
                }
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
            if (personSelect) {
                personSelect.innerHTML = "";
                const opt = document.createElement("option");
                opt.value = "";
                opt.textContent = e instanceof Error ? e.message : "Error";
                personSelect.appendChild(opt);
                personSelect.disabled = true;
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
        if (modalTitle) modalTitle.textContent = "Nuevo tema";
        if (submitLabel) submitLabel.textContent = "Crear tema";
    }

    async function openModalCreate() {
        if (topicIdField) topicIdField.value = "";
        if (modalTitle) modalTitle.textContent = "Nuevo tema";
        if (submitLabel) submitLabel.textContent = "Crear tema";
        form.reset();
        showModal();
        await loadTeamPeople();
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
            if (personSelect && t.person_id != null) {
                personSelect.value = String(t.person_id);
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
        feed.innerHTML = "";
        if (lastTopics.length === 0) {
            const empty = document.createElement("li");
            empty.className = "feed__empty muted";
            empty.textContent =
                lastIncludeDone === "1"
                    ? "No hay temas (ni pendientes ni realizados)."
                    : "No hay temas pendientes. Activa «Mostrar realizados» para ver los hechos.";
            feed.appendChild(empty);
            return;
        }
        if (filtered.length === 0) {
            const empty = document.createElement("li");
            empty.className = "feed__empty muted";
            if (byPerson.length === 0) {
                empty.textContent = "No hay temas asignados a esta persona.";
            } else {
                empty.textContent = "Ningún tema coincide con la búsqueda.";
            }
            feed.appendChild(empty);
            return;
        }
        filtered.forEach((topic) => feed.appendChild(renderTopicCard(topic)));
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

    searchInput?.addEventListener("input", () => {
        renderTopicFeed();
    });

    personFilterSelect?.addEventListener("change", () => {
        renderTopicFeed();
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
                if (data.topic && personSelect) {
                    const opt = personSelect.options[personSelect.selectedIndex];
                    if (opt && data.topic.person_id != null) {
                        personNames[Number(data.topic.person_id)] = opt.text || "";
                    }
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

                if (data.topic && personSelect) {
                    const opt = personSelect.options[personSelect.selectedIndex];
                    if (opt && data.topic.person_id != null) {
                        personNames[Number(data.topic.person_id)] = opt.text || personLabel(data.topic);
                    }
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
        if (!tid) {
            return;
        }
        const n = Number(tid);
        if (!Number.isFinite(n) || n < 1) {
            return;
        }
        openModalEdit(n).then(() => {
            try {
                const u = new URL(window.location.href);
                u.searchParams.delete("topic");
                const qs = u.searchParams.toString();
                window.history.replaceState({}, "", u.pathname + (qs ? "?" + qs : "") + (u.hash || ""));
            } catch (e) {
                /* ignore */
            }
        });
    })();
})();
