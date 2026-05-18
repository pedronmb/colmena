<?php

declare(strict_types=1);

use App\Support\SeniorityMarkdownRenderer;

if (defined('COLMENA_PENTAGON_HELP_MODAL')) {
    return;
}

define('COLMENA_PENTAGON_HELP_MODAL', true);

$seniorityHelpHtml = SeniorityMarkdownRenderer::renderFromProjectRoot();
?>
<div
    id="pentagonSeniorityHelpModal"
    class="modal"
    hidden
    aria-modal="true"
    role="dialog"
    aria-labelledby="pentagonSeniorityHelpTitle"
>
    <div class="modal__backdrop" data-pentagon-help-close></div>
    <div class="modal__card modal__card--wide pentagon-seniority-help-modal__card">
        <header class="modal__head">
            <h2 id="pentagonSeniorityHelpTitle">Ejes del pentágono</h2>
            <button type="button" class="icon-btn" data-pentagon-help-close aria-label="Cerrar"><?php require __DIR__ . '/icon-close.php'; ?></button>
        </header>
        <div class="pentagon-seniority-help-modal__body seniority-help">
            <?= $seniorityHelpHtml ?>
        </div>
        <footer class="pentagon-seniority-help-modal__footer">
            <button type="button" class="btn" data-pentagon-help-close>Cerrar</button>
        </footer>
    </div>
</div>
