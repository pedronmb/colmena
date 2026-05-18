<?php

declare(strict_types=1);

/** @var string $pentagonIdPrefix Prefijo para `id` de inputs (ej. '' o 'edit-') */
/** @var string $pentagonNamePrefix Prefijo para `name` (ej. '' o 'edit_') — evita duplicar names entre dos formularios en la misma página */

$pre = $pentagonIdPrefix ?? '';
$namePre = $pentagonNamePrefix ?? '';
$axes = [
    'axis_autonomy_problem_solving' => [
        'label' => 'Autonomía y Resolución de Problemas',
        'hint' => 'Capacidad de trabajar con poca supervisión, priorizar y desbloquear situaciones complejas.',
    ],
    'axis_impact_scope' => [
        'label' => 'Impacto y Alcance (Scope)',
        'hint' => 'Amplitud y relevancia del trabajo: alcance de iniciativas, sistemas o equipos que afecta.',
    ],
    'axis_influence_mentorship' => [
        'label' => 'Influencia, Mentoría y Liderazgo Técnico',
        'hint' => 'Guía a otros, define estándares técnicos y eleva el nivel del equipo.',
    ],
    'axis_business_communication' => [
        'label' => 'Negocio y Comunicación (Habilidades Blandas)',
        'hint' => 'Entiende el contexto de negocio, negocia prioridades y comunica con claridad a stakeholders.',
    ],
    'axis_technical_competence' => [
        'label' => 'Competencia Técnica',
        'hint' => 'Dominio de herramientas, arquitectura y calidad en la ejecución técnica.',
    ],
];
?>
<fieldset class="pentagon-profile-fields form__full">
    <div class="pentagon-profile-fields__head">
        <legend class="pentagon-profile-fields__legend">Perfil (pentágono)</legend>
        <?php require __DIR__ . '/pentagon-help-trigger.php'; ?>
    </div>
    <p class="muted pentagon-profile-fields__lead">Escala 0 (bajo) a 10 (alto). En el radar, sin puntuación previa se muestra como 0.</p>
    <div class="pentagon-profile-fields__grid">
        <?php foreach ($axes as $name => $meta) :
            $fieldName = $namePre !== '' ? $namePre . $name : $name;
            $id = $pre . $name;
            ?>
            <div class="pentagon-axis-field">
                <label class="pentagon-axis-field__label" for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="pentagon-axis-field__title"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="muted pentagon-axis-field__hint"><?= htmlspecialchars($meta['hint'], ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <div class="pentagon-axis-field__control">
                    <input class="pentagon-axis-range" type="range" name="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" min="0" max="10" step="1" value="0" title="<?= htmlspecialchars($meta['label'] . ': ' . $meta['hint'], ENT_QUOTES, 'UTF-8') ?>">
                    <output class="pentagon-axis-field__value" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>_out" for="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">0</output>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</fieldset>
