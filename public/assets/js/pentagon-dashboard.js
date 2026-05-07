/**
 * Radar pentágono por persona (pestaña dentro de Dashboard u otro contenedor con los mismos ids).
 * @global { { load: () => Promise<void> } | undefined } ColmenaPentagonDashboard
 */
(function () {
    const MODAL_CHART_SIZE = 440;

    const AXIS_KEYS = [
        "axis_strategic_vision",
        "axis_technical_execution",
        "axis_team_management",
        "axis_data_risk",
        "axis_innovation",
    ];

    const AXIS_LABELS = [
        "Visión estratégica",
        "Ejecución técnica",
        "Comunicación",
        "Datos / riesgos",
        "Innovación",
    ];

    /** Definiciones (tooltips en el SVG); mismo orden que AXIS_KEYS */
    const AXIS_LABEL_TOOLTIPS = [
        "Capacidad de ver el impacto a largo plazo.",
        "Habilidad para picar código o resolver problemas complejos.",
        "Claridad al expresar ideas, escucha activa y alineación con el equipo y las partes interesadas.",
        "Evaluación de métricas y seguridad.",
        "Capacidad de proponer soluciones fuera de la caja.",
    ];

    function personHasScores(p) {
        return AXIS_KEYS.some((k) => p[k] !== null && p[k] !== undefined && p[k] !== "");
    }

    function valuesForPerson(p) {
        return AXIS_KEYS.map((k) => {
            const v = p[k];
            if (v === null || v === undefined || v === "") return 0;
            return Math.max(0, Math.min(10, Number(v)));
        });
    }

    function getPentagonModalEls() {
        return {
            modal: document.getElementById("pentagonCardModal"),
            titleEl: document.getElementById("pentagonCardModalTitle"),
            chartEl: document.getElementById("pentagonCardModalChart"),
            footnoteEl: document.getElementById("pentagonCardModalFootnote"),
        };
    }

    function closePentagonModal() {
        const { modal } = getPentagonModalEls();
        if (modal) {
            modal.hidden = true;
            document.body.style.overflow = "";
        }
    }

    function wirePentagonModalOnce() {
        if (window.__colmenaPentagonModalWired) {
            return;
        }
        const { modal } = getPentagonModalEls();
        if (!modal) {
            return;
        }
        window.__colmenaPentagonModalWired = true;
        modal.addEventListener("click", (e) => {
            const t = e.target;
            if (t instanceof Element && t.closest("[data-pentagon-modal-close]")) {
                closePentagonModal();
            }
        });
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !modal.hidden) {
                closePentagonModal();
            }
        });
    }

    function openPentagonModal(person) {
        wirePentagonModalOnce();
        const { modal, titleEl, chartEl, footnoteEl } = getPentagonModalEls();
        if (!modal || !titleEl || !chartEl || !window.ColmenaPentagonRadar) {
            return;
        }
        const hasData = personHasScores(person);
        titleEl.textContent = person.display_name || `Tarjeta #${person.id}`;
        chartEl.innerHTML = "";
        window.ColmenaPentagonRadar.render(chartEl, {
            labels: AXIS_LABELS,
            labelTooltips: AXIS_LABEL_TOOLTIPS,
            values: valuesForPerson(person),
            hasData,
            size: MODAL_CHART_SIZE,
            max: 10,
        });
        if (footnoteEl) {
            if (!hasData) {
                footnoteEl.hidden = false;
                footnoteEl.innerHTML =
                    'Sin puntuación — configurá los ejes en <a href="people-edit.php">Editar fichas</a>.';
            } else {
                footnoteEl.hidden = true;
                footnoteEl.textContent = "";
            }
        }
        modal.hidden = false;
        document.body.style.overflow = "hidden";
        const closeBtn = modal.querySelector("button.icon-btn[data-pentagon-modal-close]");
        if (closeBtn && typeof closeBtn.focus === "function") {
            closeBtn.focus();
        }
    }

    function renderCard(person) {
        const hasData = personHasScores(person);
        const card = document.createElement("article");
        card.className = "panel pentagon-dashboard-card pentagon-dashboard-card--interactive";
        card.setAttribute("tabindex", "0");
        card.setAttribute("role", "button");
        card.setAttribute("aria-haspopup", "dialog");
        const displayName = person.display_name || `Tarjeta #${person.id}`;
        card.setAttribute("aria-label", `Ampliar perfil: ${displayName}`);

        const title = document.createElement("h2");
        title.className = "pentagon-dashboard-card__title";
        title.textContent = displayName;
        card.appendChild(title);

        const chartWrap = document.createElement("div");
        chartWrap.className = "pentagon-dashboard-card__chart";
        window.ColmenaPentagonRadar.render(chartWrap, {
            labels: AXIS_LABELS,
            labelTooltips: AXIS_LABEL_TOOLTIPS,
            values: valuesForPerson(person),
            hasData,
            size: 260,
            max: 10,
        });
        card.appendChild(chartWrap);

        if (!hasData) {
            const hint = document.createElement("p");
            hint.className = "muted pentagon-dashboard-card__hint";
            hint.innerHTML =
                'Sin puntuación — configurá los ejes en <a href="people-edit.php">Editar fichas</a>.';
            hint.querySelectorAll("a").forEach((a) => {
                a.addEventListener("click", (e) => e.stopPropagation());
            });
            card.appendChild(hint);
        }

        card.addEventListener("click", () => openPentagonModal(person));
        card.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                openPentagonModal(person);
            }
        });

        return card;
    }

    function pentagonStatsSum(p) {
        return valuesForPerson(p).reduce((acc, v) => acc + v, 0);
    }

    function sortPeopleByPentagonSum(people) {
        return [...people].sort((a, b) => {
            const diff = pentagonStatsSum(b) - pentagonStatsSum(a);
            if (diff !== 0) {
                return diff;
            }
            return String(a.display_name || "").localeCompare(String(b.display_name || ""), "es", {
                sensitivity: "base",
            });
        });
    }

    async function load() {
        closePentagonModal();
        const root = document.getElementById("pentagonDashboardRoot");
        const loadingEl = document.getElementById("pentagonDashboardLoading");
        const gridEl = document.getElementById("pentagonDashboardGrid");
        if (!root || !window.ColmenaPentagonRadar) {
            return;
        }
        const teamId = Number(root.dataset.teamId || "0");
        if (!teamId) {
            if (loadingEl) loadingEl.textContent = "Equipo no disponible.";
            return;
        }
        if (loadingEl) {
            loadingEl.hidden = false;
            loadingEl.textContent = "Cargando…";
        }
        if (gridEl) gridEl.innerHTML = "";

        try {
            const res = await fetch(
                `api/team-people.php?team_id=${encodeURIComponent(String(teamId))}`,
                { credentials: "same-origin" }
            );
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.people)) {
                throw new Error(data.error || "No se pudo cargar el equipo");
            }
            if (loadingEl) loadingEl.hidden = true;
            if (!gridEl) return;

            if (data.people.length === 0) {
                gridEl.innerHTML =
                    '<p class="muted">No hay personas. Añadilas en <a href="people-edit.php">Editar fichas</a> o en <a href="people.php">Personas</a>.</p>';
                return;
            }

            sortPeopleByPentagonSum(data.people).forEach((p) => {
                gridEl.appendChild(renderCard(p));
            });
        } catch (e) {
            if (loadingEl) {
                loadingEl.hidden = false;
                loadingEl.textContent = e instanceof Error ? e.message : "Error";
            }
        }
    }

    window.ColmenaPentagonDashboard = { load };
})();
