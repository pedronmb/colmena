/**
 * Modal de ayuda: definición de ejes del pentágono (SENORITY.md).
 */
(function () {
    const modal = document.getElementById("pentagonSeniorityHelpModal");
    if (!modal) {
        return;
    }

    let lastFocus = null;

    function openHelp() {
        lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modal.hidden = false;
        const closeBtn = modal.querySelector("[data-pentagon-help-close].icon-btn");
        if (closeBtn instanceof HTMLElement) {
            closeBtn.focus();
        }
    }

    function closeHelp() {
        modal.hidden = true;
        if (lastFocus instanceof HTMLElement) {
            lastFocus.focus();
        }
    }

    if (!window.__colmenaPentagonHelpWired) {
        window.__colmenaPentagonHelpWired = true;

        document.addEventListener("click", (e) => {
            const t = e.target;
            if (!(t instanceof Element)) {
                return;
            }
            if (t.closest("[data-pentagon-help-open]")) {
                e.preventDefault();
                openHelp();
                return;
            }
            if (t.closest("[data-pentagon-help-close]")) {
                closeHelp();
            }
        });

        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && !modal.hidden) {
                closeHelp();
            }
        });
    }

    window.ColmenaPentagonSeniorityHelp = { open: openHelp, close: closeHelp };
})();
