(function () {
    /** @type {string|null} */
    let cachedCurrentEmail = null;

    function getCurrentUserEmail() {
        if (cachedCurrentEmail !== null) {
            return cachedCurrentEmail;
        }
        const el = document.getElementById("appCurrentUserEmail");
        cachedCurrentEmail =
            el && el.value ? String(el.value).trim().toLowerCase() : "";
        return cachedCurrentEmail;
    }

    /** @param {unknown} email */
    function normalizeEmail(email) {
        return String(email || "").trim().toLowerCase();
    }

    /** @param {object|null|undefined} person */
    function personContactEmail(person) {
        if (!person) {
            return "";
        }
        return normalizeEmail(person.email);
    }

    /** @param {object|null|undefined} person */
    function isCurrentUser(person) {
        const mine = getCurrentUserEmail();
        if (!mine) {
            return false;
        }
        const theirs = personContactEmail(person);
        return theirs !== "" && theirs === mine;
    }

    /** @param {unknown} email */
    function isCurrentUserByEmail(email) {
        const mine = getCurrentUserEmail();
        if (!mine) {
            return false;
        }
        const theirs = normalizeEmail(email);
        return theirs !== "" && theirs === mine;
    }

    function isDirectTeam(person) {
        return person?.is_direct_team === true || person?.is_direct_team === 1;
    }

    /** @param {object|null|undefined} person */
    function personNameClass(person) {
        if (isCurrentUser(person)) {
            return "person-name--current-user";
        }
        if (isDirectTeam(person)) {
            return "person-name--direct-team";
        }
        return "";
    }

    function directTeamNameClass(person) {
        return isDirectTeam(person) && !isCurrentUser(person)
            ? "person-name--direct-team"
            : "";
    }

    /** @param {object|null|undefined} person */
    function personCardModifierClass(person) {
        if (isCurrentUser(person)) {
            return "person-card--current-user";
        }
        return "";
    }

    /** @param {object|null|undefined} node */
    function orgChartNodeClass(node) {
        if (isCurrentUser(node)) {
            return "org-chart__node org-chart__node--current";
        }
        if (isDirectTeam(node)) {
            return "org-chart__node org-chart__node--direct";
        }
        return "org-chart__node";
    }

    function personNameClassAttr(person, baseClass) {
        const base = baseClass ? String(baseClass).trim() : "";
        const extra = personNameClass(person);
        const combined = extra ? (base ? `${base} ${extra}` : extra) : base;
        return combined ? ` class="${combined}"` : "";
    }

    function escapeHtml(s) {
        const d = document.createElement("div");
        if (!d || typeof d.textContent !== "string") {
            return String(s)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;");
        }
        d.textContent = s;
        return d.innerHTML;
    }

    function personNameSpanHtml(person) {
        const cls = personNameClass(person);
        const name = escapeHtml(person?.display_name || "");
        return cls ? `<span class="${cls}">${name}</span>` : `<span>${name}</span>`;
    }

    function personOptionLabelHtml(person) {
        const r =
            person?.role && String(person.role).trim() !== ""
                ? String(person.role).trim()
                : "";
        const namePart = personNameSpanHtml(person);
        return r ? `${namePart} (${escapeHtml(r)})` : namePart;
    }

    function compareByDisplayName(a, b) {
        return String(a?.display_name || "").localeCompare(
            String(b?.display_name || ""),
            "es",
            { sensitivity: "base" }
        );
    }

    /** Equipo directo primero; dentro de cada grupo, secondaryCompare (por defecto nombre). */
    function sortWithDirectTeamFirst(people, secondaryCompare) {
        const compare = secondaryCompare || compareByDisplayName;
        return [...people].sort((a, b) => {
            const aDirect = isDirectTeam(a) ? 1 : 0;
            const bDirect = isDirectTeam(b) ? 1 : 0;
            if (aDirect !== bDirect) {
                return bDirect - aDirect;
            }
            return compare(a, b);
        });
    }

    window.ColmenaPersonTeam = {
        getCurrentUserEmail,
        isCurrentUser,
        isCurrentUserByEmail,
        isDirectTeam,
        personNameClass,
        directTeamNameClass,
        personCardModifierClass,
        orgChartNodeClass,
        personNameClassAttr,
        personNameSpanHtml,
        personOptionLabelHtml,
        compareByDisplayName,
        sortWithDirectTeamFirst,
    };
})();
