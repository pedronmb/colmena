(function () {
    const form = document.getElementById("loginForm");
    const submitBtn = document.getElementById("loginSubmit");
    const errorEl = document.getElementById("loginError");

    form?.addEventListener("submit", async (e) => {
        e.preventDefault();
        errorEl.hidden = true;
        errorEl.textContent = "";

        const fd = new FormData(form);
        const payload = {
            email: String(fd.get("email") || "").trim(),
            password: String(fd.get("password") || ""),
        };

        submitBtn.classList.add("loading");
        submitBtn.disabled = true;

        try {
            const res = await fetch("api/login.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo iniciar sesión");
            }

            window.location.href = "index.php";
        } catch (err) {
            errorEl.textContent = err instanceof Error ? err.message : "Error";
            errorEl.hidden = false;
        } finally {
            submitBtn.classList.remove("loading");
            submitBtn.disabled = false;
        }
    });
})();
