/**
 * Tema claro/oscuro (localStorage colmena-theme: light | dark).
 */
(function () {
    const key = "colmena-theme";
    const root = document.documentElement;

    function isDark() {
        return root.getAttribute("data-theme") === "dark";
    }

    function setTheme(dark) {
        root.setAttribute("data-theme", dark ? "dark" : "light");
        try {
            localStorage.setItem(key, dark ? "dark" : "light");
        } catch (e) {}
        syncToggle();
    }

    function syncToggle() {
        const btn = document.getElementById("themeToggle");
        if (!btn) {
            return;
        }
        const dark = isDark();
        btn.setAttribute("aria-pressed", dark ? "true" : "false");
        btn.title = dark ? "Modo claro" : "Modo oscuro";
        btn.setAttribute("aria-label", dark ? "Activar modo claro" : "Activar modo oscuro");
        const hint = btn.querySelector(".theme-toggle__text");
        if (hint) {
            hint.textContent = dark ? "Modo claro" : "Modo oscuro";
        }
    }

    document.getElementById("themeToggle")?.addEventListener("click", function () {
        setTheme(!isDark());
    });

    syncToggle();
})();
