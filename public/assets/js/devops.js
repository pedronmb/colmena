(function () {
    'use strict';

    var root = document.getElementById('devopsRoot');
    var meta = document.getElementById('devopsMeta');
    var refreshBtn = document.getElementById('devopsRefresh');
    var personFilter = document.getElementById('devopsPersonFilter');
    var suggestionsEl = document.getElementById('devopsPersonSuggestions');
    /** @type {object|null} última respuesta ok del API */
    var lastBoardData = null;
    /** @type {Array<{upn: string, display: string, upnNorm: string, haystack: string}>} */
    var personIndex = [];
    var suggestionActive = -1;
    var hideSuggestionsTimer = null;

    if (!root || !meta) {
        return;
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
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

    /** UPN normalizado (minúsculas) o cadena vacía si no hay asignado reconocible. */
    function itemUpnNormalized(it) {
        var u = (it.assigned_unique_name && String(it.assigned_unique_name).trim()) || '';
        if (u !== '') {
            return u.toLowerCase();
        }
        var name = (it.assigned_to && String(it.assigned_to).trim()) || '';
        if (name.indexOf('@') !== -1) {
            return name.toLowerCase();
        }
        return '';
    }

    function getFilterQueryNormalized() {
        if (!personFilter) {
            return '';
        }
        return String(personFilter.value || '')
            .trim()
            .toLowerCase();
    }

    /** Filtro del tablero: el UPN del ítem debe contener el texto (sin distinguir mayúsculas). */
    function itemMatchesPersonFilter(it, queryNorm) {
        if (!queryNorm) {
            return true;
        }
        var upn = itemUpnNormalized(it);
        if (upn === '') {
            return false;
        }
        return upn.indexOf(queryNorm) !== -1;
    }

    /**
     * Lista única de personas asignadas en los datos cargados (para sugerencias).
     * @param {object} data
     */
    function rebuildPersonIndex(data) {
        personIndex = [];
        var seen = Object.create(null);
        var columns = (data && data.columns) || [];
        for (var c = 0; c < columns.length; c++) {
            var items = (columns[c] && columns[c].items) || [];
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var rawUpn =
                    (it.assigned_unique_name && String(it.assigned_unique_name).trim()) || '';
                var assignName = (it.assigned_to && String(it.assigned_to).trim()) || '';
                if (rawUpn === '' && assignName.indexOf('@') !== -1) {
                    rawUpn = assignName;
                }
                if (rawUpn === '') {
                    continue;
                }
                var upnNorm = rawUpn.toLowerCase();
                if (seen[upnNorm]) {
                    continue;
                }
                seen[upnNorm] = true;
                var display = assignName && assignName !== rawUpn ? assignName : rawUpn;
                var haystack = (display + ' ' + rawUpn).toLowerCase();
                personIndex.push({
                    upn: rawUpn,
                    display: display,
                    upnNorm: upnNorm,
                    haystack: haystack,
                });
            }
        }
        personIndex.sort(function (a, b) {
            return a.display.localeCompare(b.display, 'es', { sensitivity: 'base' });
        });
    }

    function setSuggestionsExpanded(open) {
        if (!personFilter) {
            return;
        }
        personFilter.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function clearSuggestionHighlight() {
        if (!suggestionsEl) {
            return;
        }
        var opts = suggestionsEl.querySelectorAll('.devops-suggestions__option');
        for (var o = 0; o < opts.length; o++) {
            opts[o].classList.remove('devops-suggestions__option--active');
            opts[o].setAttribute('aria-selected', 'false');
        }
        suggestionActive = -1;
    }

    function highlightSuggestion(index) {
        if (!suggestionsEl) {
            return;
        }
        var opts = suggestionsEl.querySelectorAll('.devops-suggestions__option');
        clearSuggestionHighlight();
        if (index < 0 || index >= opts.length) {
            return;
        }
        suggestionActive = index;
        opts[index].classList.add('devops-suggestions__option--active');
        opts[index].setAttribute('aria-selected', 'true');
        opts[index].scrollIntoView({ block: 'nearest' });
    }

    function getFilteredPersonChoices(queryNorm) {
        if (!queryNorm) {
            return [];
        }
        var out = [];
        for (var p = 0; p < personIndex.length; p++) {
            var person = personIndex[p];
            if (person.haystack.indexOf(queryNorm) !== -1) {
                out.push(person);
            }
        }
        return out.slice(0, 20);
    }

    function updateSuggestionsUI() {
        if (!personFilter || !suggestionsEl) {
            return;
        }
        var q = getFilterQueryNormalized();
        if (q === '' || personIndex.length === 0) {
            suggestionsEl.innerHTML = '';
            suggestionsEl.hidden = true;
            setSuggestionsExpanded(false);
            clearSuggestionHighlight();
            return;
        }
        var choices = getFilteredPersonChoices(q);
        if (choices.length === 0) {
            suggestionsEl.innerHTML =
                '<li class="devops-suggestions__empty" role="option" aria-disabled="true">Sin coincidencias</li>';
            suggestionsEl.hidden = false;
            setSuggestionsExpanded(true);
            clearSuggestionHighlight();
            return;
        }
        var html = '';
        for (var i = 0; i < choices.length; i++) {
            var ch = choices[i];
            var id = 'devops-sug-' + i;
            html +=
                '<li role="option" tabindex="-1" class="devops-suggestions__option" id="' +
                id +
                '" data-upn="' +
                escapeAttr(ch.upn) +
                '">';
            html += '<span class="devops-suggestions__name">' + escapeHtml(ch.display) + '</span>';
            if (ch.display !== ch.upn) {
                html +=
                    '<span class="devops-suggestions__upn">' + escapeHtml(ch.upn) + '</span>';
            }
            html += '</li>';
        }
        suggestionsEl.innerHTML = html;
        suggestionsEl.hidden = false;
        setSuggestionsExpanded(true);
        clearSuggestionHighlight();

        var opts = suggestionsEl.querySelectorAll('.devops-suggestions__option[data-upn]');
        for (var j = 0; j < opts.length; j++) {
            opts[j].addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                var upn = this.getAttribute('data-upn');
                if (upn && personFilter) {
                    personFilter.value = upn;
                }
                suggestionsEl.hidden = true;
                setSuggestionsExpanded(false);
                if (lastBoardData) {
                    renderBoard(lastBoardData);
                }
            });
        }
    }

    function scheduleHideSuggestions() {
        if (hideSuggestionsTimer) {
            clearTimeout(hideSuggestionsTimer);
        }
        hideSuggestionsTimer = setTimeout(function () {
            if (suggestionsEl) {
                suggestionsEl.hidden = true;
            }
            setSuggestionsExpanded(false);
            clearSuggestionHighlight();
        }, 180);
    }

    function cancelHideSuggestions() {
        if (hideSuggestionsTimer) {
            clearTimeout(hideSuggestionsTimer);
            hideSuggestionsTimer = null;
        }
    }

    function renderBoard(data) {
        var columns = data.columns || [];
        var queryNorm = getFilterQueryNormalized();
        if (columns.length === 0) {
            root.innerHTML = '<p class="muted devops-board__empty">No hay work items en el rango configurado (o la consulta no devolvió resultados).</p>';
            meta.textContent = data.organization && data.project
                ? data.organization + ' / ' + data.project
                : '';
            return;
        }

        var prepared = [];
        var totalFiltered = 0;
        for (var pc = 0; pc < columns.length; pc++) {
            var pcol = columns[pc];
            var rawItems = pcol.items || [];
            var filtered = [];
            for (var fi = 0; fi < rawItems.length; fi++) {
                if (itemMatchesPersonFilter(rawItems[fi], queryNorm)) {
                    filtered.push(rawItems[fi]);
                }
            }
            totalFiltered += filtered.length;
            prepared.push({ state: pcol.state || '', items: filtered });
        }

        if (queryNorm && totalFiltered === 0) {
            root.innerHTML =
                '<p class="muted devops-board__empty">Ningún work item coincide con ese filtro en el UPN del asignado.</p>';
            meta.textContent = data.organization && data.project
                ? data.organization + ' / ' + data.project
                : '';
            return;
        }

        var html = '<div class="devops-columns" role="list">';
        for (var c = 0; c < prepared.length; c++) {
            var col = prepared[c];
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
                var uniqueName = it.assigned_unique_name || '';
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
                if (uniqueName && uniqueName !== assignee) {
                    html +=
                        '<span class="devops-card__unique" title="uniqueName (Azure DevOps)">' +
                        escapeHtml(uniqueName) +
                        '</span>';
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
                    lastBoardData = null;
                    personIndex = [];
                    renderError((body && body.error) || 'No se pudo obtener la respuesta.');
                    return;
                }
                if (body.configured === false) {
                    lastBoardData = null;
                    personIndex = [];
                    renderNotConfigured(body.hint);
                    return;
                }
                if (!res.ok) {
                    lastBoardData = null;
                    personIndex = [];
                    renderError((body && body.error) || 'Error del servidor.');
                    return;
                }
                lastBoardData = body;
                rebuildPersonIndex(body);
                renderBoard(body);
                updateSuggestionsUI();
            })
            .catch(function () {
                lastBoardData = null;
                personIndex = [];
                renderError('Error de red o respuesta no válida.');
            });
    }

    function onFilterInput() {
        if (lastBoardData) {
            renderBoard(lastBoardData);
        }
        updateSuggestionsUI();
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', load);
    }
    if (personFilter) {
        personFilter.addEventListener('input', onFilterInput);
        personFilter.addEventListener('focus', function () {
            cancelHideSuggestions();
            updateSuggestionsUI();
        });
        personFilter.addEventListener('blur', function () {
            scheduleHideSuggestions();
        });
        personFilter.addEventListener('keydown', function (ev) {
            if (!suggestionsEl || suggestionsEl.hidden) {
                return;
            }
            var opts = suggestionsEl.querySelectorAll('.devops-suggestions__option[data-upn]');
            var len = opts.length;
            if (len === 0) {
                return;
            }
            if (ev.key === 'ArrowDown') {
                ev.preventDefault();
                var next = suggestionActive + 1;
                if (next >= len) {
                    next = 0;
                }
                highlightSuggestion(next);
            } else if (ev.key === 'ArrowUp') {
                ev.preventDefault();
                var prev = suggestionActive - 1;
                if (prev < 0) {
                    prev = len - 1;
                }
                highlightSuggestion(prev);
            } else if (ev.key === 'Enter' && suggestionActive >= 0 && opts[suggestionActive]) {
                ev.preventDefault();
                var upn = opts[suggestionActive].getAttribute('data-upn');
                if (upn) {
                    personFilter.value = upn;
                }
                suggestionsEl.hidden = true;
                setSuggestionsExpanded(false);
                clearSuggestionHighlight();
                if (lastBoardData) {
                    renderBoard(lastBoardData);
                }
            } else if (ev.key === 'Escape') {
                suggestionsEl.hidden = true;
                setSuggestionsExpanded(false);
                clearSuggestionHighlight();
            }
        });
    }

    load();
})();
