(function () {
    const logoutBtn = document.getElementById("logoutBtn");
    logoutBtn?.addEventListener("click", async () => {
        logoutBtn.disabled = true;
        try {
            const res = await fetch("api/logout.php", { method: "POST" });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "Error al cerrar sesión");
            }
            window.location.href = "login.php";
        } catch {
            window.location.href = "login.php";
        }
    });
})();
