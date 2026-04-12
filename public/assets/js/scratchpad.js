/**
 * Bloc personal: texto (scratchpad) y archivos por usuario.
 */
(function () {
    const apiScratch = "api/user-scratchpad.php";
    const apiFiles = "api/user-files.php";
    const apiDownload = "api/user-file-download.php";

    const textarea = document.getElementById("scratchpadContent");
    const saveBtn = document.getElementById("scratchpadSave");
    const scratchError = document.getElementById("scratchpadError");
    const scratchStatus = document.getElementById("scratchpadStatus");

    const uploadForm = document.getElementById("fileUploadForm");
    const uploadSubmit = document.getElementById("fileUploadSubmit");
    const filesError = document.getElementById("filesError");
    const filesLoading = document.getElementById("filesLoading");
    const filesRoot = document.getElementById("filesListRoot");

    function escapeHtml(s) {
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function formatBytes(n) {
        const x = Number(n);
        if (!Number.isFinite(x) || x < 0) {
            return "—";
        }
        if (x < 1024) {
            return `${x} B`;
        }
        if (x < 1048576) {
            return `${(x / 1024).toFixed(1)} KB`;
        }
        return `${(x / 1048576).toFixed(1)} MB`;
    }

    async function loadScratchpad() {
        if (!textarea) {
            return;
        }
        if (scratchError) {
            scratchError.hidden = true;
            scratchError.textContent = "";
        }
        try {
            const res = await fetch(apiScratch, { credentials: "same-origin" });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || typeof data.content !== "string") {
                throw new Error(data.error || "No se pudo cargar el texto");
            }
            textarea.value = data.content;
        } catch (e) {
            if (scratchError) {
                scratchError.textContent = e instanceof Error ? e.message : "Error";
                scratchError.hidden = false;
            }
        }
    }

    saveBtn?.addEventListener("click", async () => {
        if (!textarea) {
            return;
        }
        if (scratchError) {
            scratchError.hidden = true;
            scratchError.textContent = "";
        }
        if (scratchStatus) {
            scratchStatus.textContent = "";
        }
        saveBtn.disabled = true;
        saveBtn.classList.add("loading");
        try {
            const res = await fetch(apiScratch, {
                method: "PUT",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ content: textarea.value }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo guardar");
            }
            if (scratchStatus) {
                scratchStatus.textContent = "Guardado.";
            }
        } catch (e) {
            if (scratchError) {
                scratchError.textContent = e instanceof Error ? e.message : "Error";
                scratchError.hidden = false;
            }
        } finally {
            saveBtn.disabled = false;
            saveBtn.classList.remove("loading");
        }
    });

    function renderFiles(files) {
        if (!filesRoot) {
            return;
        }
        if (!files.length) {
            filesRoot.innerHTML =
                '<p class="muted">No hay archivos. Sube uno con el formulario de arriba.</p>';
            filesRoot.hidden = false;
            return;
        }
        const rows = files
            .map(
                (f) => `<tr>
            <td><strong>${escapeHtml(String(f.original_name ?? ""))}</strong></td>
            <td class="muted">${escapeHtml(formatBytes(f.size_bytes))}</td>
            <td class="muted">${escapeHtml(String(f.created_at ?? "").slice(0, 19).replace("T", " "))}</td>
            <td><div class="alerts-table__actions">
                <a class="btn btn--small" href="${apiDownload}?id=${encodeURIComponent(String(f.id))}">Descargar</a>
                <button type="button" class="btn btn--small" data-delete-file-id="${f.id}">Eliminar</button>
            </div></td>
        </tr>`
            )
            .join("");
        filesRoot.innerHTML = `<table class="data-table alerts-table">
            <thead><tr><th>Nombre</th><th>Tamaño</th><th>Subido</th><th></th></tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
        filesRoot.hidden = false;
    }

    async function loadFiles() {
        if (filesLoading) {
            filesLoading.hidden = false;
        }
        if (filesRoot) {
            filesRoot.hidden = true;
        }
        if (filesError) {
            filesError.hidden = true;
            filesError.textContent = "";
        }
        try {
            const res = await fetch(apiFiles, { credentials: "same-origin" });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok || !Array.isArray(data.files)) {
                throw new Error(data.error || "No se pudieron cargar los archivos");
            }
            if (filesLoading) {
                filesLoading.hidden = true;
            }
            renderFiles(data.files);
        } catch (e) {
            if (filesLoading) {
                filesLoading.textContent = e instanceof Error ? e.message : "Error";
            }
        }
    }

    uploadForm?.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (!uploadForm || !uploadSubmit) {
            return;
        }
        if (filesError) {
            filesError.hidden = true;
            filesError.textContent = "";
        }
        const fd = new FormData(uploadForm);
        if (!fd.get("file")) {
            return;
        }
        uploadSubmit.disabled = true;
        uploadSubmit.classList.add("loading");
        try {
            const res = await fetch(apiFiles, {
                method: "POST",
                body: fd,
                credentials: "same-origin",
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo subir");
            }
            uploadForm.reset();
            await loadFiles();
        } catch (err) {
            if (filesError) {
                filesError.textContent = err instanceof Error ? err.message : "Error";
                filesError.hidden = false;
            }
        } finally {
            uploadSubmit.disabled = false;
            uploadSubmit.classList.remove("loading");
        }
    });

    filesRoot?.addEventListener("click", async (e) => {
        const t = e.target;
        if (!(t instanceof HTMLElement)) {
            return;
        }
        const id = t.getAttribute("data-delete-file-id");
        if (!id) {
            return;
        }
        if (!window.confirm("¿Eliminar este archivo?")) {
            return;
        }
        t.disabled = true;
        try {
            const res = await fetch(apiFiles, {
                method: "DELETE",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ file_id: Number(id) }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) {
                window.location.href = "login.php";
                return;
            }
            if (!res.ok || !data.ok) {
                throw new Error(data.error || "No se pudo eliminar");
            }
            await loadFiles();
        } catch (err) {
            alert(err instanceof Error ? err.message : "Error");
        } finally {
            t.disabled = false;
        }
    });

    loadScratchpad();
    loadFiles();
})();
