(function () {
    'use strict';

    var root = document.getElementById('devopsRoot');
    var meta = document.getElementById('devopsMeta');
    var refreshBtn = document.getElementById('devopsRefresh');
    if (!root || !meta) {
        return;
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderLoading() {
        root.innerHTML = '<p class="muted devops-board__empty">Cargando work items…</p>';
        meta.textContent = '';
    }

    function renderNotConfigured(hint) {
        root.innerHTML =
            '<div class="devops-setup-banner">' +
            '<p class="devops-setup-banner__title">Azure DevOps no está configurado</p>' +
            '<p class="muted devops-setup-banner__text">' +
            escapeHtml(hint || 'Añadí organización, proyecto y PAT en la configuración.') +
            '</p>' +
            '<p class="muted devops-setup-banner__text">Configurá <code class="devops-code">azure_devops</code> en <code class="devops-code">config/config.php</code>: organization, project y pat (Personal Access Token).</p>' +
            '</div>';
        meta.textContent = '';
    }

    function renderError(msg) {
        root.innerHTML =
            '<p class="devops-board__error" role="alert">' + escapeHtml(msg || 'Error al cargar.') + '</p>';
        meta.textContent = '';
    }

    function renderBoard(data) {
        var columns = data.columns || [];
        if (columns.length === 0) {
            root.innerHTML = '<p class="muted devops-board__empty">No hay work items en el rango configurado (o la consulta no devolvió resultados).</p>';
            meta.textContent = data.organization && data.project
                ? data.organization + ' / ' + data.project
                : '';
            return;
        }

        var html = '<div class="devops-columns" role="list">';
        for (var c = 0; c < columns.length; c++) {
            var col = columns[c];
            var state = col.state || '';
            var items = col.items || [];
            html += '<section class="devops-column" role="listitem">';
            html += '<header class="devops-column__head">';
            html += '<h2 class="devops-column__title">' + escapeHtml(state) + '</h2>';
            html += '<span class="devops-column__count">' + items.length + '</span>';
            html += '</header>';
            html += '<ul class="devops-column__list">';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var title = it.title || '(sin título)';
                var type = it.type || '';
                var assignee = it.assigned_to || '';
                var url = it.url || '';
                var id = it.id;
                html += '<li class="devops-card">';
                if (url) {
                    html +=
                        '<a class="devops-card__link" href="' +
                        escapeHtml(url) +
                        '" target="_blank" rel="noopener noreferrer">';
                }
                html += '<span class="devops-card__id">#' + escapeHtml(String(id)) + '</span>';
                html += '<span class="devops-card__title">' + escapeHtml(title) + '</span>';
                if (type) {
                    html += '<span class="devops-card__type">' + escapeHtml(type) + '</span>';
                }
                if (assignee) {
                    html += '<span class="devops-card__assignee">' + escapeHtml(assignee) + '</span>';
                }
                if (url) {
                    html += '</a>';
                }
                html += '</li>';
            }
            html += '</ul></section>';
        }
        html += '</div>';
        root.innerHTML = html;
        meta.textContent = data.organization && data.project
            ? data.organization + ' / ' + data.project
            : '';
    }

    function load() {
        renderLoading();
        fetch('api/azure-devops-workitems.php', { credentials: 'same-origin' })
            .then(function (r) {
                return r.json().then(function (body) {
                    return { ok: r.ok, body: body };
                });
            })
            .then(function (res) {
                var body = res.body;
                if (!body || body.ok !== true) {
                    renderError((body && body.error) || 'No se pudo obtener la respuesta.');
                    return;
                }
                if (body.configured === false) {
                    renderNotConfigured(body.hint);
                    return;
                }
                if (!res.ok) {
                    renderError((body && body.error) || 'Error del servidor.');
                    return;
                }
                renderBoard(body);
            })
            .catch(function () {
                renderError('Error de red o respuesta no válida.');
            });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', load);
    }
    load();
})();
