(function () {
    function isDirectTeam(person) {
        return person?.is_direct_team === true || person?.is_direct_team === 1;
    }

    function directTeamNameClass(person) {
        return isDirectTeam(person) ? "person-name--direct-team" : "";
    }

    function personNameClassAttr(person, baseClass) {
        const base = baseClass ? String(baseClass).trim() : "";
        const extra = directTeamNameClass(person);
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
        const cls = directTeamNameClass(person);
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
        isDirectTeam,
        directTeamNameClass,
        personNameClassAttr,
        personNameSpanHtml,
        personOptionLabelHtml,
        compareByDisplayName,
        sortWithDirectTeamFirst,
    };
})();
