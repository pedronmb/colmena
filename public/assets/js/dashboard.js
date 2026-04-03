/**
 * Dashboards: matriz Eisenhower (urgencia × importancia), lista y foco (orden por urgencia + importancia).
 */
(function () {
    const apiUrl = "api/topics.php";
    const alertsApiUrl = "api/alerts.php";
    const peopleApi = "api/team-people.php";
    const teamInput = document.getElementById("dashboardTeamId");
    const matrixRoot = document.getElementById("dashboardMatrixRoot");
    const listBody = document.getElementById("dashboardListBody");
    const focusBody = document.getElementById("dashboardFocusBody");
    const showDoneEl = document.getElementById("dashboardShowDone");
    const tabButtons = document.querySelectorAll(".dashboard-tab");
    const panelMatrix = document.getElementById("dashboardPanelMatrix");
    const panelList = document.getElementById("dashboardPanelList");
    const panelFocus = document.getElementById("dashboardPanelFocus");
    const panelCalendar = document.getElementById("dashboardPanelCalendar");
    const calendarRoot = document.getElementById("dashboardCalendarRoot");
    const dashboardCalYearLabel = document.getElementById("dashboardCalYearLabel");
    const dashboardCalPrev = document.getElementById("dashboardCalPrev");
    const dashboardCalNext = document.getElementById("dashboardCalNext");

    const MONTH_NAMES_SHORT = [
        "Ene",
        "Feb",
        "Mar",
        "Abr",
        "May",
        "Jun",
        "Jul",
        "Ago",
        "Sep",
        "Oct",
        "Nov",
        "Dic",
    ];

    const WEEKDAY_LABELS = ["L", "M", "X", "J", "V", "S", "D"];

    /** @type {object[]} */
    let lastAlerts = [];

    let calendarYear = new Date().getFullYear();

    const PRIORITY_RANK = {
        very_low: 0,
        low: 1,
        medium: 2,
        high: 3,
        critical: 4,
    };

    const IMPORTANCE_RANK = {
        very_low: 0,
        low: 1,
        medium: 2,
        high: 3,
        very_high: 4,
    };

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

    /** Separación en px entre puntos que comparten la misma celda (espiral áurea) */
    const JITTER_POINTS_PER_RING = 5;
    const JITTER_BASE_RADIUS_PX = 12;
    const JITTER_RING_STEP_PX = 14;

    /** @type {Record<number, string>} */
    let personNames = {};

    /** @type {Map<number, object>} */
    let matrixTopicById = new Map();

    /** @type {HTMLElement | null} */
    let matrixPopupEl = null;

    /** @type {HTMLElement | null} */
    let calendarDayPopupEl = null;

    /** @type {Map<string, object[]>} */
    let calendarAlertsByDate = new Map();

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

    function ensureCalendarDayPopup() {
        if (calendarDayPopupEl) {
            return calendarDayPopupEl;
        }
        const el = document.createElement("div");
        el.id = "yearCalDayPopup";
        el.className = "year-cal-day-popup";
        el.hidden = true;
        el.setAttribute("role", "tooltip");
        el.innerHTML = `
            <div class="year-cal-day-popup__date"></div>
            <ul class="year-cal-day-popup__list"></ul>`;
        document.body.appendChild(el);
        calendarDayPopupEl = el;
        return el;
    }

    function hideCalendarDayPopup() {
        if (calendarDayPopupEl) {
            calendarDayPopupEl.hidden = true;
        }
    }

    function positionCalendarDayPopup(anchor) {
        const el = ensureCalendarDayPopup();
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

    /**
     * @param {HTMLElement} anchor
     * @param {string} ymd
     * @param {object[]} list
     */
    function showCalendarDayPopup(anchor, ymd, list) {
        if (!list.length) {
            return;
        }
        const el = ensureCalendarDayPopup();
        const dateEl = el.querySelector(".year-cal-day-popup__date");
        const ul = el.querySelector(".year-cal-day-popup__list");
        if (!dateEl || !ul) {
            return;
        }
        const d = new Date(`${ymd}T12:00:00`);
        const label = Number.isNaN(d.getTime())
            ? ymd
            : d.toLocaleDateString("es", {
                  day: "numeric",
                  month: "long",
                  year: "numeric",
              });
        dateEl.textContent = label;
        ul.innerHTML = "";
        list.forEach((a) => {
            const li = document.createElement("li");
            li.textContent = a.title && String(a.title).trim() !== "" ? String(a.title) : "(Sin título)";
            ul.appendChild(li);
        });
        el.hidden = false;
        positionCalendarDayPopup(anchor);
    }

    function bindCalendarDayPopups() {
        if (!calendarRoot) {
            return;
        }
        calendarRoot.querySelectorAll(".year-cal__day[data-cal-date]").forEach((cell) => {
            const ymd = cell.getAttribute("data-cal-date");
            if (!ymd) {
                return;
            }
            const show = () => {
                const list = calendarAlertsByDate.get(ymd);
                if (list && list.length > 0) {
                    showCalendarDayPopup(cell, ymd, list);
                }
            };
            cell.addEventListener("mouseenter", show);
            cell.addEventListener("mouseleave", hideCalendarDayPopup);
            cell.addEventListener("focus", show);
            cell.addEventListener("blur", hideCalendarDayPopup);
        });
    }

    function bindMatrixPopupScrollClose() {
        hideMatrixPopup();
        hideCalendarDayPopup();
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
        const ring = Math.floor(indexInCell / JITTER_POINTS_PER_RING);
        const r = JITTER_BASE_RADIUS_PX + ring * JITTER_RING_STEP_PX;
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

    function priorityRank(p) {
        return PRIORITY_RANK[p] !== undefined ? PRIORITY_RANK[p] : PRIORITY_RANK.medium;
    }

    function importanceRank(i) {
        const k = i || "medium";
        return IMPORTANCE_RANK[k] !== undefined ? IMPORTANCE_RANK[k] : IMPORTANCE_RANK.medium;
    }

    /**
     * Más urgente e importante primero; desempate por actualización e id.
     * @param {object[]} topics
     */
    function sortTopicsForFocus(topics) {
        return [...topics].sort((a, b) => {
            const pd = priorityRank(b.priority) - priorityRank(a.priority);
            if (pd !== 0) {
                return pd;
            }
            const id = importanceRank(b.importance) - importanceRank(a.importance);
            if (id !== 0) {
                return id;
            }
            const sa = String(a.updated_at || a.created_at || "").trim();
            const sb = String(b.updated_at || b.created_at || "").trim();
            const ta = Date.parse(sa.includes("T") ? sa : sa.replace(" ", "T")) || 0;
            const tb = Date.parse(sb.includes("T") ? sb : sb.replace(" ", "T")) || 0;
            if (tb !== ta) {
                return tb - ta;
            }
            return b.id - a.id;
        });
    }

    function renderFocus(topics) {
        if (!focusBody) {
            return;
        }
        const sorted = sortTopicsForFocus(topics);
        if (sorted.length === 0) {
            focusBody.innerHTML = '<tr><td colspan="5" class="muted">No hay temas.</td></tr>';
            return;
        }
        focusBody.innerHTML = sorted
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

    /**
     * @param {object[]} alerts
     * @param {number} year
     * @returns {Map<string, object[]>}
     */
    function buildAlertsByDate(alerts, year) {
        const map = new Map();
        const y = String(year);
        alerts.forEach((a) => {
            const d = a.due_date;
            if (!d || typeof d !== "string" || !d.startsWith(`${y}-`)) {
                return;
            }
            if (!map.has(d)) {
                map.set(d, []);
            }
            map.get(d).push(a);
        });
        map.forEach((arr) => {
            arr.sort((x, y) => x.id - y.id);
        });
        return map;
    }

    function localTodayYmd() {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    }

    function renderYearCalendar(alerts, year) {
        if (!calendarRoot) {
            return;
        }
        if (dashboardCalYearLabel) {
            dashboardCalYearLabel.textContent = String(year);
        }
        calendarAlertsByDate = buildAlertsByDate(alerts, year);
        const todayStr = localTodayYmd();
        let monthsHtml = '<div class="year-cal__months">';
        for (let m = 0; m < 12; m++) {
            const first = new Date(year, m, 1);
            const startPad = (first.getDay() + 6) % 7;
            const dim = new Date(year, m + 1, 0).getDate();
            let daysHtml = '<div class="year-cal__days">';
            for (let i = 0; i < startPad; i++) {
                daysHtml += '<span class="year-cal__day year-cal__day--pad"></span>';
            }
            for (let day = 1; day <= dim; day++) {
                const mm = String(m + 1).padStart(2, "0");
                const dd = String(day).padStart(2, "0");
                const ymd = `${year}-${mm}-${dd}`;
                const dayAlerts = calendarAlertsByDate.get(ymd) || [];
                const n = dayAlerts.length;
                const isToday = ymd === todayStr;
                let dayClass = "year-cal__day";
                if (isToday) {
                    dayClass += " year-cal__day--today";
                }
                if (n === 1) {
                    dayClass += " year-cal__day--alert";
                } else if (n > 1) {
                    dayClass += " year-cal__day--alert year-cal__day--alert-many";
                }
                const dataDate = n > 0 ? ` data-cal-date="${ymd}"` : "";
                let titleAttr = "";
                if (n === 0) {
                    let title = ymd;
                    if (isToday) {
                        title = `Hoy · ${ymd}`;
                    }
                    titleAttr = ` title="${escapeHtml(title)}"`;
                }
                daysHtml += `<span class="${dayClass}"${dataDate}${titleAttr}>${day}</span>`;
            }
            daysHtml += "</div>";
            const weekdaysRow = WEEKDAY_LABELS.map(
                (l) => `<span class="year-cal__wd">${l}</span>`
            ).join("");
            monthsHtml += `<div class="year-cal__month">
        <div class="year-cal__month-title">${MONTH_NAMES_SHORT[m]}</div>
        <div class="year-cal__weekdays">${weekdaysRow}</div>
        ${daysHtml}
      </div>`;
        }
        monthsHtml += "</div>";
        calendarRoot.innerHTML = monthsHtml;
        bindCalendarDayPopups();
    }

    function setCalendarError(msg) {
        if (!calendarRoot) {
            return;
        }
        calendarRoot.innerHTML = `<p class="form-error" role="alert">${escapeHtml(msg)}</p>`;
    }

    async function loadDashboard() {
        const teamId = getTeamId();
        if (!matrixRoot && !listBody && !focusBody && !calendarRoot) {
            return;
        }
        if (matrixRoot) {
            hideMatrixPopup();
            matrixRoot.innerHTML = '<p class="muted matrix-plot__loading">Cargando…</p>';
        }
        if (calendarRoot) {
            hideCalendarDayPopup();
            calendarRoot.innerHTML = '<p class="muted year-cal__loading">Cargando calendario…</p>';
        }
        await loadPeople();

        const topicsUrl = `${apiUrl}?team_id=${encodeURIComponent(String(teamId))}&include_done=${encodeURIComponent(includeDone())}`;
        const alertsUrl = `${alertsApiUrl}?team_id=${encodeURIComponent(String(teamId))}`;

        const [topicsRes, alertsRes] = await Promise.all([
            fetch(topicsUrl, { method: "GET", credentials: "same-origin" }),
            calendarRoot
                ? fetch(alertsUrl, { method: "GET", credentials: "same-origin" })
                : Promise.resolve(null),
        ]);

        if (topicsRes.status === 401 || (alertsRes && alertsRes.status === 401)) {
            window.location.href = "login.php";
            return;
        }

        try {
            const data = await topicsRes.json().catch(() => ({}));
            if (!topicsRes.ok || !data.ok || !Array.isArray(data.topics)) {
                throw new Error(data.error || "No se pudieron cargar los temas");
            }
            const topics = data.topics;
            renderMatrixPlot(topics);
            renderList(topics);
            renderFocus(topics);
        } catch (e) {
            const msg = e instanceof Error ? e.message : "Error";
            if (matrixRoot) {
                matrixRoot.innerHTML = `<p class="form-error" role="alert">${escapeHtml(msg)}</p>`;
            }
            if (listBody) {
                listBody.innerHTML = `<tr><td colspan="5" class="form-error">${escapeHtml(msg)}</td></tr>`;
            }
            if (focusBody) {
                focusBody.innerHTML = `<tr><td colspan="5" class="form-error">${escapeHtml(msg)}</td></tr>`;
            }
        }

        if (calendarRoot && alertsRes) {
            try {
                const adata = await alertsRes.json().catch(() => ({}));
                if (!alertsRes.ok || !adata.ok || !Array.isArray(adata.alerts)) {
                    throw new Error(adata.error || "No se pudieron cargar las alertas");
                }
                lastAlerts = adata.alerts;
                renderYearCalendar(lastAlerts, calendarYear);
            } catch (e) {
                lastAlerts = [];
                const msg = e instanceof Error ? e.message : "Error";
                setCalendarError(msg);
            }
        }
    }

    function setActiveTab(panel) {
        tabButtons.forEach((btn) => {
            const active = btn.getAttribute("data-panel") === panel;
            btn.classList.toggle("dashboard-tab--active", active);
            btn.setAttribute("aria-selected", active ? "true" : "false");
        });
        if (panelMatrix) {
            panelMatrix.hidden = panel !== "matrix";
        }
        if (panelList) {
            panelList.hidden = panel !== "list";
        }
        if (panelFocus) {
            panelFocus.hidden = panel !== "focus";
        }
        if (panelCalendar) {
            panelCalendar.hidden = panel !== "calendar";
        }
        if (panel !== "calendar") {
            hideCalendarDayPopup();
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

    dashboardCalPrev?.addEventListener("click", () => {
        calendarYear -= 1;
        renderYearCalendar(lastAlerts, calendarYear);
    });

    dashboardCalNext?.addEventListener("click", () => {
        calendarYear += 1;
        renderYearCalendar(lastAlerts, calendarYear);
    });

    window.addEventListener("scroll", bindMatrixPopupScrollClose, true);
    window.addEventListener("resize", bindMatrixPopupScrollClose);

    loadDashboard();
})();
