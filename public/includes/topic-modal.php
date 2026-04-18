<?php
/** @var int $personalTeamId */
?>
    <div id="topicModal" class="modal" hidden aria-modal="true" role="dialog" aria-labelledby="modalTitle">
        <div class="modal__backdrop" data-close></div>
        <div class="modal__card modal__card--wide">
            <header class="modal__head">
                <h2 id="modalTitle">Nuevo tema</h2>
                <button type="button" class="icon-btn" data-close aria-label="Cerrar"><?php require __DIR__ . '/icon-close.php'; ?></button>
            </header>
            <form id="topicForm" class="form">
                <input type="hidden" name="topic_id" id="topicIdField" value="">
                <label>
                    Título
                    <input name="title" type="text" required maxlength="200" placeholder="Ej. Revisar API de facturación" autocomplete="off">
                </label>
                <label>
                    Descripción
                    <textarea name="body" rows="4" placeholder="Contexto o criterios de aceptación"></textarea>
                </label>
                <label>
                    Prioridad (urgencia)
                    <select name="priority">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium" selected>Media</option>
                        <option value="high">Alta</option>
                        <option value="critical">Crítica</option>
                    </select>
                </label>
                <label>
                    Importancia
                    <select name="importance">
                        <option value="very_low">Muy baja</option>
                        <option value="low">Baja</option>
                        <option value="medium" selected>Media</option>
                        <option value="high">Alta</option>
                        <option value="very_high">Muy alta</option>
                    </select>
                </label>
                <label id="topicCompletedWrap" class="topic-toolbar__toggle" hidden>
                    <input type="checkbox" id="topicCompletedField" name="completed" value="1">
                    Tema resuelto
                </label>
                <label class="form__full topic-person-field">
                    Persona (tarjeta)
                    <div class="topic-person-combobox" id="topicPersonCombobox">
                        <input type="hidden" name="person_id" id="topicPersonId" value="">
                        <input
                            type="text"
                            id="topicPersonSearch"
                            class="topic-person-combobox__input"
                            autocomplete="off"
                            placeholder="Escribe para buscar por nombre o rol…"
                            aria-autocomplete="list"
                            aria-controls="topicPersonListbox"
                            aria-expanded="false"
                            role="combobox"
                        />
                        <ul class="topic-person-combobox__list" id="topicPersonListbox" role="listbox" hidden></ul>
                    </div>
                </label>
                <p class="hint muted">Las tarjetas se dan de alta en <a href="people-edit.php">Editar fichas</a>; la vista por bloques está en <a href="people.php">Personas</a>.</p>
                <input type="hidden" name="team_id" value="<?= (int) $personalTeamId ?>">
                <footer class="form__actions">
                    <button type="button" class="btn" data-close>Cancelar</button>
                    <button type="submit" class="btn primary" id="submitTopic">
                        <span class="btn__label" id="submitTopicLabel">Crear tema</span>
                    </button>
                </footer>
            </form>
        </div>
    </div>
