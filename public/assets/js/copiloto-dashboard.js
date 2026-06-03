/**
 * Copiloto de Management (pestaña del dashboard).
 * @global {{ load: () => Promise<void> } | undefined} ColmenaCopilotoDashboard
 */
(function () {
    const apiUrl = "api/management-copilot.php";
    const peopleListApi = "api/person-management-copilot-list.php";
    const personApi = "api/person-management-copilot.php";
    const rootEl = document.getElementById("copilotoDashboardRoot");
    const teamInput = document.getElementById("dashboardTeamId");
    const loadingEl = document.getElementById("copilotoDashboardLoading");
    const errorEl = document.getElementById("copilotoDashboardError");
    const contentEl = document.getElementById("copilotoDashboardContent");
    const periodEl = document.getElementById("copilotoPeriod");
    const refreshBtn = document.getElementById("copilotoRefresh");
    const personModal = document.getElementById("copilotoPersonModal");
    const personModalTitle = document.getElementById("copilotoPersonModalTitle");
    const personModalRole = document.getElementById("copilotoPersonModalRole");
    const personModalBody = document.getElementById("copilotoPersonModalBody");

    let loadSeq = 0;
    /** @type {number | null} */
    let personModalLoadSeq = null;

    function teamId() {
        const fromRoot = rootEl?.getAttribute("data-team-id");
        if (fromRoot) {
            const n = parseInt(fromRoot, 10);
            if (Number.isFinite(n) && n > 0) {
                return n;
            }
        }
        const fromInput = teamInput?.value;
        const n = parseInt(String(fromInput ?? ""), 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s == null ? "" : String(s);
        return d.innerHTML;
    }

    /** @param {string} text */
    function inlineMarkdown(text) {
        const escaped = escapeHtml(text);
        return escaped.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
    }

    /** @param {string} markdown */
    function renderMarkdown(markdown) {
        if (!markdown || String(markdown).trim() === "") return "";

        let html = "";
        let inList = false;
        let inSection = false;
        const lines = String(markdown).split(/\r\n|\r|\n/);

        const closeList = () => {
            if (inList) {
                html += "</ul>";
                inList = false;
            }
        };

        const closeSection = () => {
            closeList();
            if (inSection) {
                html += "</section>";
                inSection = false;
            }
        };

        for (const line of lines) {
            const trim = line.trim();

            if (trim === "---") {
                closeSection();
                html += '<hr class="copiloto-prose__hr">';
                continue;
            }

            if (trim === "") {
                closeList();
                continue;
            }

            const h1 = trim.match(/^# (.+)$/);
            if (h1) {
                closeSection();
                html += `<h2 class="copiloto-prose__title">${inlineMarkdown(h1[1])}</h2>`;
                continue;
            }

            const h2 = trim.match(/^## (.+)$/);
            if (h2) {
                closeSection();
                html += '<section class="copiloto-prose__section">';
                html += `<h3 class="copiloto-prose__heading">${inlineMarkdown(h2[1])}</h3>`;
                inSection = true;
                continue;
            }

            const h3 = trim.match(/^### (.+)$/);
            if (h3) {
                closeList();
                html += `<h4 class="copiloto-prose__subheading">${inlineMarkdown(h3[1])}</h4>`;
                continue;
            }

            const li = trim.match(/^[-*] (.+)$/);
            if (li) {
                if (!inList) {
                    html += '<ul class="copiloto-prose__list">';
                    inList = true;
                }
                html += `<li>${inlineMarkdown(li[1])}</li>`;
                continue;
            }

            closeList();
            html += `<p class="copiloto-prose__p">${inlineMarkdown(trim)}</p>`;
        }

        closeSection();
        return html;
    }

    /** @param {object} rec */
    function isStructuredRecommendation(rec) {
        return (
            (Array.isArray(rec.executive_bullets) && rec.executive_bullets.length > 0) ||
            (Array.isArray(rec.risks) && rec.risks.length > 0) ||
            (Array.isArray(rec.actions) && rec.actions.length > 0) ||
            (Array.isArray(rec.people_focus) && rec.people_focus.length > 0) ||
            (Array.isArray(rec.topics_focus) && rec.topics_focus.length > 0) ||
            (Array.isArray(rec.delegations) && rec.delegations.length > 0)
        );
    }

    /** @param {object} rec */
    function isStructuredPersonRecommendation(rec) {
        const situation =
            rec.situation && typeof rec.situation === "object"
                ? rec.situation.current || rec.situation.text || ""
                : "";
        return (
            Boolean(situation && String(situation).trim()) ||
            (Array.isArray(rec.risks) && rec.risks.length > 0) ||
            (Array.isArray(rec.blockers) && rec.blockers.length > 0)
        );
    }

    /** @param {object} meta */
    function renderReportHeader(meta) {
        const periodLabel =
            meta.period_start && meta.period_end
                ? `${meta.period_start} — ${meta.period_end}`
                : "";

        const encargados = Array.isArray(meta.encargados) ? meta.encargados : [];
        const encargadoNames = encargados
            .map((e) => (e && e.name ? String(e.name).trim() : ""))
            .filter((n) => n !== "");

        let header = `<header class="copiloto-dashboard__header">
            <p class="copiloto-dashboard__period muted">Semana ${escapeHtml(periodLabel)} · Equipo directo</p>`;
        if (encargadoNames.length > 0) {
            header += `<p class="copiloto-dashboard__audience muted">Dirigido a: ${escapeHtml(encargadoNames.join(", "))}</p>`;
        }
        if (meta.generated_at) {
            header += `<p class="muted copiloto-dashboard__meta">Generado: ${escapeHtml(formatGeneratedAt(meta.generated_at))}`;
            if (meta.model) {
                header += ` · Modelo: ${escapeHtml(meta.model)}`;
            }
            header += "</p>";
        }
        header += "</header>";
        return header;
    }

    /**
     * @param {object} rec
     * @param {object} meta
     * @param {{ compact?: boolean, includePeopleMount?: boolean }} [opts]
     */
    function renderProseReport(rec, meta, opts) {
        const compact = opts?.compact === true;
        const includePeopleMount = opts?.includePeopleMount !== false;
        const body = rec.summary || "";
        const proseClass = compact
            ? "copiloto-prose copiloto-prose--compact"
            : "copiloto-prose";

        let html = compact ? "" : renderReportHeader(meta);
        html += `<article class="${proseClass}">${renderMarkdown(body)}</article>`;

        if (!compact && includePeopleMount) {
            html += `<div id="copilotoPeopleGridMount"></div>`;
        }

        return html;
    }

    function periodKey() {
        const v = periodEl?.value;
        return v === "previous" ? "previous" : "current";
    }

    function setLoading(on) {
        if (loadingEl) {
            loadingEl.hidden = !on;
            loadingEl.textContent = on ? "Cargando copiloto…" : "";
        }
    }

    function setError(msg) {
        if (!errorEl) return;
        if (msg) {
            errorEl.hidden = false;
            errorEl.textContent = msg;
        } else {
            errorEl.hidden = true;
            errorEl.textContent = "";
        }
    }

    function formatGeneratedAt(iso) {
        if (!iso) return "";
        const d = new Date(String(iso));
        if (Number.isNaN(d.getTime())) return String(iso);
        return d.toLocaleString("es", { dateStyle: "medium", timeStyle: "short" });
    }

    function riskLevelLabel(level) {
        if (level === "high") return "Alto";
        if (level === "medium") return "Medio";
        if (level === "low") return "Bajo";
        return "";
    }

    function personNameClass(person) {
        if (window.ColmenaPersonTeam?.personNameClass) {
            return window.ColmenaPersonTeam.personNameClass(person) || "";
        }
        return person?.is_direct_team ? "person-name--direct-team" : "";
    }

    /** @param {unknown} item */
    function renderRationale(item) {
        const r = item && typeof item === "object" ? item.rationale : "";
        if (!r || String(r).trim() === "") return "";
        return `<p class="copiloto-card__rationale"><strong>Por qué:</strong> ${escapeHtml(String(r))}</p>`;
    }

    /** @param {unknown[]} items */
    function renderListSection(title, items, renderItem) {
        if (!Array.isArray(items) || items.length === 0) {
            return `<section class="copiloto-section"><h3 class="copiloto-section__title">${escapeHtml(title)}</h3><p class="muted">Sin ítems en esta categoría.</p></section>`;
        }
        const cards = items.map(renderItem).join("");
        return `<section class="copiloto-section"><h3 class="copiloto-section__title">${escapeHtml(title)}</h3><div class="copiloto-section__cards">${cards}</div></section>`;
    }

    /** @param {string} rationale */
    function renderModalRationale(rationale) {
        if (!rationale || String(rationale).trim() === "") return "";
        return `<p class="copiloto-person-modal__rationale"><span class="copiloto-person-modal__rationale-label">Por qué:</span> ${escapeHtml(String(rationale))}</p>`;
    }

    /** @param {string} title @param {unknown[]} items @param {boolean} withRationale @param {string} [sectionClass] */
    function renderModalItemSection(title, items, withRationale, sectionClass) {
        if (!Array.isArray(items) || items.length === 0) return "";
        const extraClass = sectionClass ? ` ${sectionClass}` : "";
        const lis = items
            .map((item) => {
                const t = typeof item === "string" ? item : item.title || item.text || "";
                const rat =
                    withRationale && item && item.rationale
                        ? renderModalRationale(String(item.rationale))
                        : "";
                return `<li class="copiloto-person-modal__item"><span class="copiloto-person-modal__item-title">${escapeHtml(String(t))}</span>${rat}</li>`;
            })
            .join("");
        return `<section class="copiloto-person-modal__section${extraClass}"><h3 class="copiloto-person-modal__section-title">${escapeHtml(title)}</h3><ul class="copiloto-person-modal__items">${lis}</ul></section>`;
    }

    /** @param {object} rec @param {object} meta */
    function renderPersonReadingHtml(rec, meta) {
        if (!rec || rec.status === "error") {
            return `<div class="copiloto-person-modal__empty form-error">${escapeHtml(
                rec?.error_message || meta?.message || "Error al generar."
            )}</div>`;
        }

        if (rec.summary && !isStructuredPersonRecommendation(rec)) {
            const risk = rec.risk_level || "";
            const riskBadge = risk
                ? `<span class="copiloto-person-modal__risk copiloto-person-modal__risk--${escapeHtml(risk)}">${escapeHtml(riskLevelLabel(risk))}</span>`
                : "";
            const periodLabel =
                meta.period_start && meta.period_end
                    ? `Semana ${meta.period_start} — ${meta.period_end}`
                    : "";
            let footer = "";
            if (meta?.generated_at) {
                footer = `<footer class="copiloto-person-modal__footer muted">Generado: ${escapeHtml(formatGeneratedAt(meta.generated_at))}`;
                if (meta.model) footer += ` · ${escapeHtml(meta.model)}`;
                footer += "</footer>";
            }
            return `<div class="copiloto-person-modal__content">
                <div class="copiloto-person-modal__topbar">
                    ${riskBadge}
                    ${periodLabel ? `<span class="copiloto-person-modal__period muted">${escapeHtml(periodLabel)}</span>` : ""}
                </div>
                ${renderProseReport(rec, meta, { compact: true, includePeopleMount: false })}
                ${footer}
            </div>`;
        }

        const risk = rec.risk_level || "";
        const riskBadge = risk
            ? `<span class="copiloto-person-modal__risk copiloto-person-modal__risk--${escapeHtml(risk)}">${escapeHtml(riskLevelLabel(risk))}</span>`
            : "";

        const periodLabel =
            meta.period_start && meta.period_end
                ? `Semana ${meta.period_start} — ${meta.period_end}`
                : "";

        const situation =
            rec.situation && typeof rec.situation === "object"
                ? rec.situation.current || rec.situation.text || ""
                : "";
        const situationRat =
            rec.situation && rec.situation.rationale
                ? renderModalRationale(String(rec.situation.rationale))
                : "";

        let situationHtml = "";
        if (situation) {
            situationHtml = `<section class="copiloto-person-modal__section"><h3 class="copiloto-person-modal__section-title">Situación actual</h3><div class="copiloto-person-modal__prose"><p>${escapeHtml(String(situation))}</p>${situationRat}</div></section>`;
        }

        let footer = "";
        if (meta?.generated_at) {
            footer = `<footer class="copiloto-person-modal__footer muted">Generado: ${escapeHtml(formatGeneratedAt(meta.generated_at))}`;
            if (meta.model) footer += ` · ${escapeHtml(meta.model)}`;
            footer += "</footer>";
        }

        return `<div class="copiloto-person-modal__content">
            <div class="copiloto-person-modal__topbar">
                ${riskBadge}
                ${periodLabel ? `<span class="copiloto-person-modal__period muted">${escapeHtml(periodLabel)}</span>` : ""}
            </div>
            ${rec.summary ? `<section class="copiloto-person-modal__summary" aria-label="Resumen"><p>${escapeHtml(rec.summary)}</p></section>` : ""}
            ${situationHtml}
            ${renderModalItemSection("Riesgos", rec.risks, true, "copiloto-person-modal__section--risks")}
            ${renderModalItemSection("Posibles bloqueos", rec.blockers, true)}
            ${footer}
        </div>`;
    }

    /** @param {object[]} people */
    function renderPeopleGrid(people) {
        if (!Array.isArray(people) || people.length === 0) {
            return `<section class="copiloto-section"><h3 class="copiloto-section__title">Lectura IA por persona</h3><p class="muted">No hay fichas de persona en el equipo.</p></section>`;
        }

        const cards = people
            .map((p) => {
                const name = p.display_name || `Persona #${p.person_id}`;
                const nameCls = personNameClass(p);
                const role = p.role ? `<span class="copiloto-person-card__role muted">${escapeHtml(p.role)}</span>` : "";
                const risk = p.risk_level || "";
                const riskBadge =
                    risk && p.has_recommendation
                        ? `<span class="copiloto-person-card__risk copiloto-person-card__risk--${escapeHtml(risk)}">${escapeHtml(riskLevelLabel(risk))}</span>`
                        : "";
                const preview = p.summary_preview
                    ? `<p class="copiloto-person-card__preview">${escapeHtml(p.summary_preview)}</p>`
                    : `<p class="muted copiloto-person-card__preview">${
                          p.has_recommendation ? "Clic para ver la lectura completa." : "Sin lectura generada esta semana."
                      }</p>`;
                const statusCls = p.has_recommendation
                    ? "copiloto-person-card--has-rec"
                    : "copiloto-person-card--empty";

                return `<button type="button" class="copiloto-person-card ${statusCls}" data-copilot-person-id="${escapeHtml(String(p.person_id))}" data-copilot-person-name="${escapeHtml(name)}"${p.role ? ` data-copilot-person-role="${escapeHtml(String(p.role))}"` : ""} aria-label="Ver lectura IA de ${escapeHtml(name)}">
                    <span class="copiloto-person-card__head">
                        <span class="copiloto-person-card__name ${escapeHtml(nameCls)}">${escapeHtml(name)}</span>
                        ${riskBadge}
                    </span>
                    ${role}
                    ${preview}
                </button>`;
            })
            .join("");

        return `<section class="copiloto-section copiloto-section--people"><h3 class="copiloto-section__title">Lectura IA por persona (equipo directo)</h3>
            <p class="muted copiloto-section__hint">Hacé clic en una tarjeta para ver la lectura completa de management.</p>
            <div class="copiloto-person-grid">${cards}</div></section>`;
    }

    /** @param {object} rec */
    function renderRecommendation(rec, meta) {
        const bullets = rec.executive_bullets || [];
        const summary = rec.summary || "";
        const header = renderReportHeader(meta);

        let execHtml = "";
        if (bullets.length > 0) {
            execHtml = `<section class="copiloto-section copiloto-section--highlight"><h3 class="copiloto-section__title">Resumen ejecutivo semanal</h3><ul class="copiloto-bullets">${bullets
                .map((b) => `<li>${escapeHtml(String(b))}</li>`)
                .join("")}</ul></section>`;
        } else if (summary) {
            execHtml = `<section class="copiloto-section copiloto-section--highlight"><h3 class="copiloto-section__title">Resumen ejecutivo semanal</h3><p>${escapeHtml(summary)}</p></section>`;
        }

        const risks = renderListSection("Riesgos detectados", rec.risks, (item) => {
            const title = item.title || "Riesgo";
            const sev = item.severity ? ` <span class="pill">${escapeHtml(item.severity)}</span>` : "";
            return `<article class="copiloto-card">${sev}<h4 class="copiloto-card__title">${escapeHtml(title)}</h4>${renderRationale(item)}</article>`;
        });

        const actions = renderListSection("Acciones recomendadas", rec.actions, (item) => {
            const title = item.title || "Acción";
            let extra = "";
            if (item.suggested_person_id) {
                extra = `<p class="muted">Persona sugerida: #${escapeHtml(String(item.suggested_person_id))}</p>`;
            }
            return `<article class="copiloto-card"><h4 class="copiloto-card__title">${escapeHtml(title)}</h4>${renderRationale(item)}${extra}</article>`;
        });

        const people = renderListSection("Personas a revisar", rec.people_focus, (item) => {
            const name = item.name || `Persona #${item.person_id || "?"}`;
            const signals = Array.isArray(item.signals)
                ? `<ul class="copiloto-card__signals">${item.signals
                      .map((s) => `<li>${escapeHtml(String(s))}</li>`)
                      .join("")}</ul>`
                : "";
            const openBtn =
                item.person_id != null
                    ? `<button type="button" class="btn btn--small copiloto-card__open-person" data-copilot-person-id="${escapeHtml(String(item.person_id))}" data-copilot-person-name="${escapeHtml(name)}">Ver lectura IA</button>`
                    : "";
            return `<article class="copiloto-card"><h4 class="copiloto-card__title">${escapeHtml(name)}</h4>${renderRationale(item)}${signals}${openBtn}</article>`;
        });

        const topics = renderListSection("Temas críticos", rec.topics_focus, (item) => {
            const title = item.title || `Tema #${item.topic_id || "?"}`;
            const link =
                item.topic_id != null
                    ? `<a href="dashboard.php?topic=${encodeURIComponent(String(item.topic_id))}&panel=matrix" class="copiloto-card__link">Abrir tema</a>`
                    : "";
            return `<article class="copiloto-card"><h4 class="copiloto-card__title">${escapeHtml(title)}</h4>${renderRationale(item)}${link}</article>`;
        });

        const delegations = renderListSection("Delegaciones sugeridas", rec.delegations, (item) => {
            const title = item.title || `Tema #${item.topic_id || "?"}`;
            return `<article class="copiloto-card"><h4 class="copiloto-card__title">${escapeHtml(title)}</h4>${renderRationale(item)}</article>`;
        });

        return (
            header +
            execHtml +
            risks +
            actions +
            people +
            topics +
            delegations +
            `<div id="copilotoPeopleGridMount"></div>`
        );
    }

    function closePersonModal() {
        if (!personModal) return;
        personModal.hidden = true;
        document.body.style.overflow = "";
        personModalLoadSeq = null;
    }

    function setPersonModalSubtitle(role) {
        if (!personModalRole) return;
        const trimmed = role && String(role).trim();
        if (trimmed) {
            personModalRole.textContent = trimmed;
            personModalRole.hidden = false;
        } else {
            personModalRole.textContent = "";
            personModalRole.hidden = true;
        }
    }

    async function openPersonModal(personId, displayName, role) {
        if (!personModal || !personModalBody || !personModalTitle) return;
        const tid = teamId();
        if (tid < 1 || personId < 1) return;

        personModalTitle.textContent = displayName || "Persona";
        setPersonModalSubtitle(role);
        personModalBody.innerHTML =
            '<div class="copiloto-person-modal__loading"><p class="muted">Cargando lectura IA…</p></div>';
        personModal.hidden = false;
        document.body.style.overflow = "hidden";

        const seq = Date.now();
        personModalLoadSeq = seq;

        try {
            const url = `${personApi}?team_id=${encodeURIComponent(String(tid))}&person_id=${encodeURIComponent(String(personId))}&period=${encodeURIComponent(periodKey())}`;
            const res = await fetch(url, { credentials: "same-origin" });
            const data = await res.json();
            if (personModalLoadSeq !== seq) return;

            if (!res.ok || !data.ok) {
                throw new Error(data.error || `Error HTTP ${res.status}`);
            }

            if (!data.recommendation) {
                personModalBody.innerHTML = `<div class="copiloto-person-modal__empty muted">${escapeHtml(
                    data.meta?.message || "Sin recomendación para esta semana."
                )}</div>`;
                return;
            }

            personModalBody.innerHTML = renderPersonReadingHtml(
                data.recommendation,
                data.meta || {}
            );
        } catch (e) {
            if (personModalLoadSeq !== seq) return;
            const msg = e instanceof Error ? e.message : "Error";
            personModalBody.innerHTML = `<div class="copiloto-person-modal__empty form-error">${escapeHtml(msg)}</div>`;
        }
    }

    function bindPersonInteractions(people) {
        /** @type {Record<number, { name: string, role: string }>} */
        const peopleMeta = {};
        if (Array.isArray(people)) {
            people.forEach((p) => {
                if (p.person_id != null) {
                    peopleMeta[p.person_id] = {
                        name: p.display_name || "",
                        role: p.role ? String(p.role) : "",
                    };
                }
            });
        }

        contentEl?.querySelectorAll("[data-copilot-person-id]").forEach((el) => {
            el.addEventListener("click", () => {
                const pid = parseInt(el.getAttribute("data-copilot-person-id") || "", 10);
                if (!Number.isFinite(pid) || pid < 1) return;
                const fromAttr = el.getAttribute("data-copilot-person-name");
                const name =
                    (fromAttr && fromAttr.trim()) ||
                    peopleMeta[pid]?.name ||
                    "";
                const fromRole = el.getAttribute("data-copilot-person-role");
                const role =
                    (fromRole && fromRole.trim()) ||
                    peopleMeta[pid]?.role ||
                    "";
                openPersonModal(pid, name, role);
            });
        });
    }

    async function loadPeopleGrid() {
        const mount = contentEl?.querySelector("#copilotoPeopleGridMount");
        if (!mount) return;

        const tid = teamId();
        if (tid < 1) {
            mount.outerHTML = "";
            return;
        }

        try {
            const url = `${peopleListApi}?team_id=${encodeURIComponent(String(tid))}&period=${encodeURIComponent(periodKey())}`;
            const res = await fetch(url, { credentials: "same-origin" });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || `Error HTTP ${res.status}`);
            }
            const html = renderPeopleGrid(data.people || []);
            mount.outerHTML = html;
            bindPersonInteractions(data.people || []);
        } catch (e) {
            const msg = e instanceof Error ? e.message : "Error";
            mount.outerHTML = `<section class="copiloto-section"><h3 class="copiloto-section__title">Lectura IA por persona</h3><p class="form-error">${escapeHtml(msg)}</p></section>`;
        }
    }

    async function load() {
        if (!rootEl || !contentEl) return;
        const tid = teamId();
        if (tid < 1) {
            setError("No hay equipo seleccionado.");
            return;
        }

        const seq = ++loadSeq;
        setLoading(true);
        setError("");

        try {
            const url = `${apiUrl}?team_id=${encodeURIComponent(String(tid))}&period=${encodeURIComponent(periodKey())}`;
            const res = await fetch(url, { credentials: "same-origin" });
            const data = await res.json();
            if (seq !== loadSeq) return;

            if (!res.ok || !data.ok) {
                throw new Error(data.error || `Error HTTP ${res.status}`);
            }

            const meta = data.meta || {};
            if (!meta.has_recommendation || !data.recommendation) {
                contentEl.innerHTML = `<div class="copiloto-empty">
                    <p class="muted">${escapeHtml(meta.message || "Aún no hay recomendaciones generadas.")}</p>
                    <p class="muted">Ejecutá: <code>php database/generate_management_recommendations.php</code></p>
                </div>`;
                await loadPeopleGridStandalone();
                return;
            }

            if (data.recommendation.status === "error") {
                contentEl.innerHTML = `<div class="copiloto-empty form-error">${escapeHtml(
                    data.recommendation.error_message || meta.message || "Error en la generación."
                )}</div>`;
                await loadPeopleGridStandalone();
                return;
            }

            const rec = data.recommendation;
            contentEl.innerHTML = isStructuredRecommendation(rec)
                ? renderRecommendation(rec, meta)
                : renderProseReport(rec, meta);
            await loadPeopleGrid();
        } catch (e) {
            if (seq !== loadSeq) return;
            const msg = e instanceof Error ? e.message : "Error al cargar";
            setError(msg);
            contentEl.innerHTML = "";
        } finally {
            if (seq === loadSeq) {
                setLoading(false);
            }
        }
    }

    async function loadPeopleGridStandalone() {
        const tid = teamId();
        if (tid < 1) return;
        try {
            const url = `${peopleListApi}?team_id=${encodeURIComponent(String(tid))}&period=${encodeURIComponent(periodKey())}`;
            const res = await fetch(url, { credentials: "same-origin" });
            const data = await res.json();
            if (!res.ok || !data.ok) return;
            const gridHtml = renderPeopleGrid(data.people || []);
            contentEl.innerHTML += gridHtml;
            bindPersonInteractions(data.people || []);
        } catch (_) {
            /* optional when team rec missing */
        }
    }

    personModal?.querySelectorAll("[data-copilot-person-close]").forEach((el) => {
        el.addEventListener("click", closePersonModal);
    });

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && personModal && !personModal.hidden) {
            closePersonModal();
        }
    });

    periodEl?.addEventListener("change", () => load());
    refreshBtn?.addEventListener("click", () => load());

    window.ColmenaCopilotoDashboard = { load };
})();
