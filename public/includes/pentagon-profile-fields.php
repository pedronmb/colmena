<?php

declare(strict_types=1);

/** @var string $pentagonIdPrefix Prefijo para `id` de inputs (ej. '' o 'edit-') */
/** @var string $pentagonNamePrefix Prefijo para `name` (ej. '' o 'edit_') — evita duplicar names entre dos formularios en la misma página */

$pre = $pentagonIdPrefix ?? '';
$namePre = $pentagonNamePrefix ?? '';
$axes = [
    'axis_strategic_vision' => [
        'label' => 'Visión estratégica',
        'hint' => 'Capacidad de ver el impacto a largo plazo.',
    ],
    'axis_technical_execution' => [
        'label' => 'Ejecución técnica',
        'hint' => 'Habilidad para picar código o resolver problemas complejos.',
    ],
    'axis_team_management' => [
        'label' => 'Comunicación',
        'hint' => 'Claridad al expresar ideas, escucha activa y alineación con el equipo y las partes interesadas.',
    ],
    'axis_data_risk' => [
        'label' => 'Análisis de datos / riesgos',
        'hint' => 'Evaluación de métricas y seguridad.',
    ],
    'axis_innovation' => [
        'label' => 'Innovación / creatividad',
        'hint' => 'Capacidad de proponer soluciones fuera de la caja.',
    ],
];
?>
<fieldset class="pentagon-profile-fields form__full">
    <legend class="pentagon-profile-fields__legend">Perfil (pentágono)</legend>
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
