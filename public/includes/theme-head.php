<meta name="color-scheme" content="light dark">
<script>
(function () {
    try {
        var k = "colmena-theme";
        var v = localStorage.getItem(k);
        var dark = false;
        if (v === "dark") {
            dark = true;
        } else if (v === "light") {
            dark = false;
        } else {
            dark = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
        }
        document.documentElement.setAttribute("data-theme", dark ? "dark" : "light");
    } catch (e) {}
})();
</script>
