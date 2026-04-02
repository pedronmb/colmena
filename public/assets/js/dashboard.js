/**
 * Dashboards: matriz Eisenhower (urgencia × importancia) y lista.
 */
(function () {
    const apiUrl = "api/topics.php";
    const peopleApi = "api/team-people.php";
    const teamInput = document.getElementById("dashboardTeamId");
    const matrixRoot = document.getElementById("dashboardMatrixRoot");
    const listBody = document.getElementById("dashboardListBody");
    const showDoneEl = document.getElementById("dashboardShowDone");
    const tabButtons = document.querySelectorAll(".dashboard-tab");
    const panelMatrix = document.getElementById("dashboardPanelMatrix");
    const panelList = document.getElementById("dashboardPanelList");

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

    /** Margen interior del cuadrado (%) para que los puntos no toquen el borde */
    const MATRIX_PAD = 6;

    /** @type {Record<number, string>} */
    let personNames = {};

    /** @type {Map<number, object>} */
    let matrixTopicById = new Map();

    /** @type {HTMLElement | null} */
    let matrixPopupEl = null;

    function ensureMatrixPopup() {
        if (matrixPopupEl) {
            return matrixPopupEl;
        }
        const el = document.createElement("div");
        el.id = "matrixTopicPopup";
        el.className = "matrix-topic-popup";
        el.hidden = true;
        el.setAttribute("role", "tooltip");
        el.innerHTML = `
            <div class="matrix-topic-popup__title"></div>
            <dl class="matrix-topic-popup__meta">
                <dt>Persona</dt><dd class="matrix-topic-popup__dd" data-field="person"></dd>
                <dt>Criticidad</dt><dd class="matrix-topic-popup__dd" data-field="urgency"></dd>
                <dt>Importancia</dt><dd class="matrix-topic-popup__dd" data-field="importance"></dd>
            </dl>`;
        document.body.appendChild(el);
        matrixPopupEl = el;
        return el;
    }

    function hideMatrixPopup() {
        const el = matrixPopupEl;
        if (el) {
            el.hidden = true;
        }
    }

    function positionMatrixPopup(anchor) {
        const el = ensureMatrixPopup();
        el.style.visibility = "hidden";
        el.hidden = false;
        const rect = anchor.getBoundingClientRect();
        const pad = 10;
        const gap = 8;
        const pw = el.offsetWidth;
        const ph = el.offsetHeight;
        let left = rect.right + gap;
        let top = rect.top + rect.height / 2 - ph / 2;
        if (left + pw > window.innerWidth - pad) {
            left = rect.left - pw - gap;
        }
        if (left < pad) {
            left = pad;
        }
        if (top < pad) {
            top = pad;
        }
        if (top + ph > window.innerHeight - pad) {
            top = window.innerHeight - ph - pad;
        }
        el.style.left = `${Math.round(left)}px`;
        el.style.top = `${Math.round(top)}px`;
        el.style.visibility = "";
    }

    function showMatrixPopup(anchor, topic) {
        const el = ensureMatrixPopup();
        const pr = priorityLabels[topic.priority] || topic.priority;
        const im = importanceLabels[topic.importance || "medium"] || importanceLabels.medium;
        const titleEl = el.querySelector(".matrix-topic-popup__title");
        if (titleEl) {
            titleEl.textContent = topic.title || "";
        }
        const personDd = el.querySelector('[data-field="person"]');
        const urgDd = el.querySelector('[data-field="urgency"]');
        const impDd = el.querySelector('[data-field="importance"]');
        if (personDd) {
            personDd.textContent = personLabel(topic);
        }
        if (urgDd) {
            urgDd.textContent = pr;
        }
        if (impDd) {
            impDd.textContent = im;
        }
        el.hidden = false;
        positionMatrixPopup(anchor);
    }

    function bindMatrixPopupScrollClose() {
        hideMatrixPopup();
    }

    function getTeamId() {
        const n = teamInput ? Number(teamInput.value) : 0;
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function includeDone() {
        return showDoneEl && showDoneEl.checked ? "1" : "0";
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function urgencyNorm(p) {
        const m = {
            very_low: 0,
            low: 0.25,
            medium: 0.5,
            high: 0.75,
            critical: 1,
        };
        return m[p] !== undefined ? m[p] : 0.5;
    }

    function importanceNorm(i) {
        const k = i || "medium";
        const m = {
            very_low: 0,
            low: 0.25,
            medium: 0.5,
            high: 0.75,
            very_high: 1,
        };
        return m[k] !== undefined ? m[k] : 0.5;
    }

    function basePercents(t) {
        const usable = 100 - 2 * MATRIX_PAD;
        const u = urgencyNorm(t.priority);
        const v = importanceNorm(t.importance);
        return {
            left: MATRIX_PAD + u * usable,
            bottom: MATRIX_PAD + v * usable,
        };
    }

    function cellKey(t) {
        return `${t.priority}|${t.importance || "medium"}`;
    }

    function jitterPx(indexInCell, totalInCell) {
        if (totalInCell <= 1) {
            return [0, 0];
        }
        const angle = (indexInCell * 2.39996322972865332) % (2 * Math.PI);
        const ring = Math.floor(indexInCell / 7);
        const r = 5 + ring * 4;
        return [Math.round(Math.cos(angle) * r), Math.round(Math.sin(angle) * r)];
    }

    async function loadPeople() {
        const teamId = getTeamId();
        if (!teamId) {
            return;
        }
        try {
            const res = await fetch(`${peopleApi}?team_id=${encodeURIComponent(String(teamId))}`, {
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok || !Array.isArray(data.people)) {
                return;
            }
            personNames = {};
            data.people.forEach((p) => {
                personNames[p.id] = p.display_name;
            });
        } catch (e) {
            personNames = {};
        }
    }

    function personLabel(topic) {
        const pid = topic.person_id;
        if (pid == null || pid === "") {
            return "—";
        }
        const id = Number(pid);
        return personNames[id] || "Tarjeta #" + id;
    }

    function renderMatrixPlot(topics) {
        if (!matrixRoot) {
            return;
        }

        const byCell = {};
        topics.forEach((t) => {
            const k = cellKey(t);
            if (!byCell[k]) {
                byCell[k] = [];
            }
            byCell[k].push(t);
        });
        Object.keys(byCell).forEach((k) => {
            byCell[k].sort((a, b) => a.id - b.id);
        });

        const dots =
            topics.length === 0
                ? ""
                : topics
                      .map((t) => {
                          const group = byCell[cellKey(t)] || [t];
                          const idx = group.findIndex((x) => x.id === t.id);
                          const [jx, jy] = jitterPx(idx, group.length);
                          const pos = basePercents(t);
                          const done = t.status === "done";
                          const pr = priorityLabels[t.priority] || t.priority;
                          const im = importanceLabels[t.importance || "medium"] || importanceLabels.medium;
                          const aria = `${t.title}. Persona: ${personLabel(t)}. Criticidad: ${pr}. Importancia: ${im}.`;
                          return `<a href="index.php?topic=${encodeURIComponent(String(t.id))}" class="matrix-dot${done ? " matrix-dot--done" : ""}"
            data-topic-id="${t.id}"
            style="left:${pos.left.toFixed(2)}%;bottom:${pos.bottom.toFixed(2)}%;transform:translate(calc(-50% + ${jx}px),calc(50% + ${jy}px));"
            aria-label="${escapeHtml(aria)}"></a>`;
                      })
                      .join("");

        const empty = topics.length === 0 ? '<p class="matrix-plot__empty muted">No hay temas para mostrar.</p>' : "";

        matrixRoot.innerHTML = `
      <div class="matrix-plot">
        <div class="matrix-plot__inner">
            <span class="matrix-plot__axis matrix-plot__axis--y">
            <span class="matrix-plot__axis-arrow" aria-hidden="true">↑</span> Importancia (muy baja → muy alta)
          </span>
          <div class="matrix-plot__square">
            <div class="matrix-plot__chart" role="img" aria-label="Mapa de temas: urgencia en horizontal, importancia en vertical">
              <div class="matrix-plot__grid" aria-hidden="true"></div>
              ${empty}
              ${dots}
            </div>
          </div>
        </div>
        <p class="matrix-plot__axis matrix-plot__axis--x">
          Urgencia (muy baja → crítica) <span class="matrix-plot__axis-arrow" aria-hidden="true">→</span>
        </p>
      </div>`;

        matrixTopicById = new Map(topics.map((t) => [t.id, t]));
        matrixRoot.querySelectorAll(".matrix-dot").forEach((dot) => {
            const id = Number(dot.getAttribute("data-topic-id"));
            if (!Number.isFinite(id)) {
                return;
            }
            const show = () => {
                const topic = matrixTopicById.get(id);
                if (topic) {
                    showMatrixPopup(dot, topic);
                }
            };
            dot.addEventListener("mouseenter", show);
            dot.addEventListener("mouseleave", hideMatrixPopup);
            dot.addEventListener("focus", show);
            dot.addEventListener("blur", hideMatrixPopup);
        });
    }

    function renderList(topics) {
        if (!listBody) {
            return;
        }
        if (topics.length === 0) {
            listBody.innerHTML = '<tr><td colspan="5" class="muted">No hay temas.</td></tr>';
            return;
        }
        listBody.innerHTML = topics
            .map((t) => {
                const pr = priorityLabels[t.priority] || t.priority;
                const im = importanceLabels[t.importance || "medium"] || importanceLabels.medium;
                return `<tr>
        <td><a href="index.php?topic=${encodeURIComponent(String(t.id))}">${escapeHtml(t.title)}</a></td>
        <td>${escapeHtml(pr)}</td>
        <td>${escapeHtml(im)}</td>
        <td>${escapeHtml(t.status)}</td>
        <td><a class="btn btn--small" href="index.php?topic=${encodeURIComponent(String(t.id))}">Editar</a></td>
      </tr>`;
            })
            .join("");
    }

    async function loadDashboard() {
        const teamId = getTeamId();
        if (!matrixRoot && !listBody) {
            return;
        }
        if (matrixRoot) {
            hideMatrixPopup();
            matrixRoot.innerHTML = '<p class="muted matrix-plot__loading">Cargando…</p>';
        }
        await loadPeople();
        try {
            const res = await fetch(
                `${apiUrl}?team_id=${encodeURIComponent(String(teamId))}&include_done=${encodeURIComponent(includeDone())}`,
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
            const topics = data.topics;
            renderMatrixPlot(topics);
            renderList(topics);
        } catch (e) {
            const msg = e instanceof Error ? e.message : "Error";
            if (matrixRoot) {
                matrixRoot.innerHTML = `<p class="form-error" role="alert">${escapeHtml(msg)}</p>`;
            }
            if (listBody) {
                listBody.innerHTML = `<tr><td colspan="5" class="form-error">${escapeHtml(msg)}</td></tr>`;
            }
        }
    }

    function setActiveTab(panel) {
        tabButtons.forEach((btn) => {
            const active = btn.getAttribute("data-panel") === panel;
            btn.classList.toggle("dashboard-tab--active", active);
            btn.setAttribute("aria-selected", active ? "true" : "false");
        });
        if (panelMatrix && panelList) {
            const showMatrix = panel === "matrix";
            panelMatrix.hidden = !showMatrix;
            panelList.hidden = showMatrix;
        }
    }

    tabButtons.forEach((btn) => {
        btn.addEventListener("click", () => {
            const panel = btn.getAttribute("data-panel");
            if (panel) {
                setActiveTab(panel);
            }
        });
    });

    showDoneEl?.addEventListener("change", () => {
        loadDashboard();
    });

    window.addEventListener("scroll", bindMatrixPopupScrollClose, true);
    window.addEventListener("resize", bindMatrixPopupScrollClose);

    loadDashboard();
})();
