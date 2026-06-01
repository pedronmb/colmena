(function () {
    'use strict';

    var listRoot = document.getElementById('devopsListRoot');
    var listMeta = document.getElementById('devopsListMeta');
    var listLoading = document.getElementById('devopsListLoading');
    var teamInput = document.getElementById('appPersonalTeamId');
    var refreshBtn = document.getElementById('devopsRefresh');
    var panelList = document.getElementById('devopsPanelList');

    if (!listRoot) {
        return;
    }

    var chevronSvg =
        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';

    var groupIdSeq = 0;
    var listLoaded = false;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function getTeamId() {
        if (!teamInput || !teamInput.value) {
            return 0;
        }
        var n = parseInt(teamInput.value, 10);
        return isNaN(n) ? 0 : n;
    }

    function isListPanelActive() {
        return panelList && !panelList.hidden;
    }

    function formatTimestamp(raw) {
        if (!raw || raw === '0') {
            return '—';
        }
        var d = new Date(raw);
        if (isNaN(d.getTime())) {
            return escapeHtml(String(raw));
        }
        return escapeHtml(
            d.toLocaleString('es', {
                dateStyle: 'short',
                timeStyle: 'short',
            })
        );
    }

    function personSubtitle(person) {
        var bits = [];
        if (person.email) {
            bits.push(escapeHtml(person.email));
        }
        if (person.role) {
            bits.push(escapeHtml(person.role));
        }
        return bits.length ? ' <span class="muted invgate-group__meta">' + bits.join(' · ') + '</span>' : '';
    }

    function nextGroupDomId(prefix) {
        groupIdSeq += 1;
        return prefix + '-' + groupIdSeq;
    }

    function renderGroupShell(titleHtml, bodyHtml, options) {
        var collapsed = options.collapsed === true;
        var bodyId = nextGroupDomId('devops-group-body');
        var expanded = !collapsed;
        var toggleTitle = expanded ? 'Plegar work items' : 'Desplegar work items';
        var extra = options.extraClass ? ' ' + options.extraClass : '';
        return (
            '<section class="invgate-group devops-list-group' +
            (collapsed ? ' invgate-group--collapsed' : '') +
            extra +
            '">' +
            '<div class="invgate-group__head">' +
            '<button type="button" class="invgate-group__toggle panel-collapse-btn" aria-expanded="' +
            (expanded ? 'true' : 'false') +
            '" aria-controls="' +
            bodyId +
            '" title="' +
            toggleTitle +
            '">' +
            '<span class="panel-collapse-btn__chevron" aria-hidden="true">' +
            chevronSvg +
            '</span></button>' +
            '<h3 class="invgate-group__title">' +
            '<button type="button" class="invgate-group__title-btn" aria-expanded="' +
            (expanded ? 'true' : 'false') +
            '" aria-controls="' +
            bodyId +
            '">' +
            titleHtml +
            '</button></h3></div>' +
            '<div id="' +
            bodyId +
            '" class="invgate-group__body">' +
            bodyHtml +
            '</div></section>'
        );
    }

    function renderWorkItemTable(items, showAssignee) {
        if (!items.length) {
            return '<p class="muted invgate-group__empty">Sin work items.</p>';
        }
        var html =
            '<table class="data-table devops-list-table"><thead><tr>' +
            '<th>#</th><th>Título</th><th>Estado</th><th>Tipo</th>';
        if (showAssignee) {
            html += '<th>Asignado</th>';
        }
        html += '<th>Actualizado</th></tr></thead><tbody>';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var id = it.id || it.azure_id || '';
            var title = it.title || '(sin título)';
            var url = it.url || '';
            var titleCell = url
                ? '<a href="' +
                  escapeHtml(url) +
                  '" target="_blank" rel="noopener noreferrer">' +
                  escapeHtml(title) +
                  '</a>'
                : escapeHtml(title);
            html += '<tr>';
            html += '<td>#' + escapeHtml(String(id)) + '</td>';
            html += '<td>' + titleCell + '</td>';
            html += '<td>' + escapeHtml(it.state || '') + '</td>';
            html += '<td>' + escapeHtml(it.type || '') + '</td>';
            if (showAssignee) {
                var assignee = it.assigned_to || '';
                var upn = it.assigned_unique_name || '';
                var assigneeHtml = escapeHtml(assignee || upn || '—');
                if (upn && upn !== assignee) {
                    assigneeHtml +=
                        '<br><span class="muted devops-list-table__upn">' + escapeHtml(upn) + '</span>';
                }
                html += '<td>' + assigneeHtml + '</td>';
            }
            html += '<td class="devops-list-table__date">' + formatTimestamp(it.changed_at) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function renderPersonGroup(person, workItems) {
        var name =
            (window.ColmenaPersonTeam &&
                window.ColmenaPersonTeam.personNameSpanHtml &&
                window.ColmenaPersonTeam.personNameSpanHtml(person)) ||
            escapeHtml(person.display_name || 'Sin nombre');
        var count = workItems.length;
        var countLabel = count === 1 ? '1 work item' : count + ' work items';
        var titleHtml =
            name +
            personSubtitle(person) +
            ' <span class="invgate-group__count muted">— ' +
            countLabel +
            '</span>';
        var body = renderWorkItemTable(workItems, false);
        return renderGroupShell(titleHtml, body, { collapsed: count === 0 });
    }

    function renderOthersGroup(workItems) {
        if (!workItems.length) {
            return '';
        }
        var countLabel =
            workItems.length === 1 ? '1 work item' : workItems.length + ' work items';
        var titleHtml =
            'Otros <span class="muted devops-list-group__hint">(asignados fuera de Colmena)</span> ' +
            '<span class="invgate-group__count muted">— ' +
            countLabel +
            '</span>';
        var body = renderWorkItemTable(workItems, true);
        return renderGroupShell(titleHtml, body, {
            extraClass: 'invgate-group--orphan devops-list-group--others',
            collapsed: false,
        });
    }

    function setGroupCollapsed(groupEl, collapsed) {
        groupEl.classList.toggle('invgate-group--collapsed', collapsed);
        var expanded = !collapsed;
        var btns = groupEl.querySelectorAll('.invgate-group__toggle, .invgate-group__title-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
            btns[i].setAttribute('title', expanded ? 'Plegar work items' : 'Desplegar work items');
        }
    }

    function bindGroupToggles() {
        if (listRoot.dataset.devopsListToggleBound === '1') {
            return;
        }
        listRoot.dataset.devopsListToggleBound = '1';
        listRoot.addEventListener('click', function (e) {
            var trigger = e.target.closest('.invgate-group__toggle, .invgate-group__title-btn');
            if (!trigger || !listRoot.contains(trigger)) {
                return;
            }
            var group = trigger.closest('.invgate-group');
            if (!group) {
                return;
            }
            setGroupCollapsed(group, !group.classList.contains('invgate-group--collapsed'));
        });
    }

    function renderListMeta(meta, syncMeta) {
        if (!listMeta) {
            return;
        }
        var total =
            meta && typeof meta.work_item_total === 'number' ? meta.work_item_total : 0;
        var people =
            meta && typeof meta.people_count === 'number' ? meta.people_count : 0;
        var parts = [
            total + ' work item' + (total === 1 ? '' : 's') + ' activos',
            people + ' persona' + (people === 1 ? '' : 's') + ' en el equipo',
        ];
        if (syncMeta && syncMeta.last_synced_at) {
            parts.push('última sync: ' + syncMeta.last_synced_at);
        }
        listMeta.textContent = parts.join(' · ');
        listMeta.hidden = false;
    }

    function renderList(body) {
        if (listLoading) {
            listLoading.hidden = true;
        }
        var groups = body.groups || [];
        var others = body.others_work_items || [];
        var hasData =
            groups.some(function (g) {
                return (g.work_items || []).length > 0;
            }) || others.length > 0;

        if (body.empty && !hasData) {
            listRoot.innerHTML =
                '<div class="devops-setup-banner">' +
                '<p class="devops-setup-banner__title">Sin work items en la base local</p>' +
                '<p class="muted devops-setup-banner__text">' +
                escapeHtml(body.hint || 'Ejecutá php database/sync_azure_work_items.php') +
                '</p></div>';
            if (listMeta) {
                listMeta.hidden = true;
            }
            return;
        }

        groupIdSeq = 0;
        var html = '';
        for (var g = 0; g < groups.length; g++) {
            var group = groups[g];
            var person = group.person || {};
            var items = group.work_items || [];
            html += renderPersonGroup(person, items);
        }
        html += renderOthersGroup(others);

        if (!html) {
            listRoot.innerHTML =
                '<p class="muted">No hay work items activos para mostrar.</p>';
        } else {
            listRoot.innerHTML = html;
            bindGroupToggles();
        }
        renderListMeta(body.meta, body.sync_meta);
    }

    function renderListError(msg) {
        if (listLoading) {
            listLoading.hidden = true;
        }
        listRoot.innerHTML =
            '<p class="devops-board__error" role="alert">' + escapeHtml(msg) + '</p>';
        if (listMeta) {
            listMeta.hidden = true;
        }
    }

    function loadList() {
        var teamId = getTeamId();
        if (teamId < 1) {
            renderListError('No se encontró el equipo personal.');
            return;
        }
        if (listLoading) {
            listLoading.hidden = false;
        }
        listRoot.innerHTML = '';
        listRoot.appendChild(listLoading);

        fetch(
            'api/azure-devops-workitems-list.php?team_id=' + encodeURIComponent(String(teamId)),
            { credentials: 'same-origin' }
        )
            .then(function (r) {
                return r.json().then(function (body) {
                    return { ok: r.ok, body: body };
                });
            })
            .then(function (res) {
                var body = res.body;
                if (!body || body.ok !== true) {
                    renderListError((body && body.error) || 'No se pudo cargar la lista.');
                    return;
                }
                if (body.configured === false) {
                    listRoot.innerHTML =
                        '<div class="devops-setup-banner">' +
                        '<p class="devops-setup-banner__title">Azure DevOps no está configurado</p>' +
                        '<p class="muted devops-setup-banner__text">' +
                        escapeHtml(body.hint || '') +
                        '</p></div>';
                    if (listMeta) {
                        listMeta.hidden = true;
                    }
                    return;
                }
                if (!res.ok) {
                    renderListError((body && body.error) || 'Error del servidor.');
                    return;
                }
                listLoaded = true;
                renderList(body);
            })
            .catch(function () {
                renderListError('Error de red o respuesta no válida.');
            });
    }

    function initTabs() {
        var tabs = document.querySelectorAll('.devops-tab');
        var panelBoard = document.getElementById('devopsPanelBoard');
        if (!tabs.length || !panelBoard || !panelList) {
            return;
        }

        function activate(panelName) {
            for (var t = 0; t < tabs.length; t++) {
                var tab = tabs[t];
                var active = tab.getAttribute('data-panel') === panelName;
                tab.classList.toggle('devops-tab--active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            }
            var showBoard = panelName === 'board';
            panelBoard.hidden = !showBoard;
            panelList.hidden = showBoard;
            if (!showBoard && !listLoaded) {
                loadList();
            }
        }

        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () {
                var panel = this.getAttribute('data-panel');
                if (panel) {
                    activate(panel);
                }
            });
        }

        window.DevopsTabs = {
            getActivePanel: function () {
                var active = document.querySelector('.devops-tab--active');
                return active ? active.getAttribute('data-panel') : 'board';
            },
            activate: activate,
        };
    }

    window.DevopsList = {
        load: loadList,
        isActive: isListPanelActive,
    };

    initTabs();
})();
