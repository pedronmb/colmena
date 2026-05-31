/**
 * Organigrama jerárquico del equipo (pestaña en Dashboard).
 * @global { { load: () => Promise<void> } | undefined } ColmenaOrgChart
 */
(function () {
    const embedRoot = document.getElementById("orgChartDashboardRoot");
    const chartRoot = document.getElementById("orgChartDashboardChart");
    const loadingEl = document.getElementById("orgChartDashboardLoading");

    function getTeamId() {
        const hidden = document.getElementById("dashboardTeamId");
        const fromEmbed = embedRoot?.getAttribute("data-team-id");
        const raw = hidden ? hidden.value : fromEmbed;
        const n = Number(raw);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    /**
     * @param {object[]} people
     * @returns {object[]}
     */
    function buildTree(people) {
        const byId = new Map(
            people.map((p) => [
                p.id,
                {
                    ...p,
                    children: [],
                },
            ])
        );
        const roots = [];

        byId.forEach((node) => {
            const parentId = node.reports_to_id;
            if (parentId == null || parentId === "" || !byId.has(parentId)) {
                roots.push(node);
            } else {
                byId.get(parentId).children.push(node);
            }
        });

        const sortRec = (nodes) => {
            nodes.sort((a, b) =>
                String(a.display_name || "").localeCompare(String(b.display_name || ""), "es")
            );
            nodes.forEach((n) => sortRec(n.children));
        };
        sortRec(roots);

        return roots;
    }

    /**
     * @param {object} node
     * @returns {string}
     */
    function renderBranch(node) {
        const nodeClass =
            window.ColmenaPersonTeam?.orgChartNodeClass?.(node) || "org-chart__node";
        const role =
            node.role && String(node.role).trim() !== ""
                ? `<span class="org-chart__role">${escapeHtml(String(node.role).trim())}</span>`
                : "";
        const nameClass =
            window.ColmenaPersonTeam?.personNameClass?.(node) || "";
        const nameClassAttr = nameClass ? ` class="org-chart__name ${nameClass}"` : ' class="org-chart__name"';

        let html = `<li class="org-chart__branch">
            <div class="${nodeClass}">
                <span${nameClassAttr}>${escapeHtml(node.display_name || "")}</span>
                ${role}
            </div>`;

        if (node.children && node.children.length > 0) {
            html += `<div class="org-chart__down" aria-hidden="true"></div>`;
            html += `<ul class="org-chart__children">${node.children
                .map((child) => renderBranch(child))
                .join("")}</ul>`;
        }

        html += "</li>";
        return html;
    }

    /**
     * @param {object[]} roots
     * @returns {string}
     */
    function renderTree(roots) {
        if (!roots.length) {
            return `<p class="muted org-chart__empty">No hay personas en el equipo. Creá fichas en <a href="people-edit.php">Editar fichas</a>.</p>`;
        }

        return `<div class="org-chart">
            <ul class="org-chart__roots">${roots.map((r) => renderBranch(r)).join("")}</ul>
        </div>`;
    }

    function setLoading(msg) {
        if (!loadingEl) {
            return;
        }
        if (msg) {
            loadingEl.textContent = msg;
            loadingEl.hidden = false;
        } else {
            loadingEl.textContent = "";
            loadingEl.hidden = true;
        }
    }

    async function load() {
        if (!chartRoot) {
            return;
        }

        const teamId = getTeamId();
        if (!teamId) {
            chartRoot.innerHTML =
                '<p class="form-error" role="alert">Equipo no configurado.</p>';
            return;
        }

        setLoading("Cargando organigrama…");
        chartRoot.innerHTML = "";

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
                throw new Error(data.error || "No se pudo cargar el organigrama");
            }

            const roots = buildTree(data.people);
            chartRoot.innerHTML = renderTree(roots);
            setLoading("");
        } catch (e) {
            setLoading("");
            chartRoot.innerHTML = `<p class="form-error" role="alert">${escapeHtml(
                e instanceof Error ? e.message : "Error"
            )}</p>`;
        }
    }

    window.ColmenaOrgChart = { load };
})();
