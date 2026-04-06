<?php

declare(strict_types=1);

if (!isset($bfIdPrefix) || !is_string($bfIdPrefix) || $bfIdPrefix === '') {
    $bfIdPrefix = 'birthday';
}

$meses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
    5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$idMonth = $bfIdPrefix . 'BirthdayMonth';
$idDay = $bfIdPrefix . 'BirthdayDay';
$idHidden = $bfIdPrefix . 'Birthday';
?>
<label class="form__full">
    Cumpleaños (opcional)
    <span class="muted birthday-md-hint">Solo mes y día</span>
    <div class="birthday-md-row">
        <select name="birthday_month" id="<?= htmlspecialchars($idMonth, ENT_QUOTES, 'UTF-8') ?>" aria-label="Mes del cumpleaños">
            <option value="">—</option>
            <?php for ($mi = 1; $mi <= 12; $mi++) : ?>
                <option value="<?= $mi ?>"><?= htmlspecialchars($meses[$mi], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endfor; ?>
        </select>
        <select name="birthday_day" id="<?= htmlspecialchars($idDay, ENT_QUOTES, 'UTF-8') ?>" aria-label="Día del cumpleaños">
            <option value="">—</option>
            <?php for ($di = 1; $di <= 31; $di++) : ?>
                <option value="<?= $di ?>"><?= $di ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <input type="hidden" name="birthday" value="" id="<?= htmlspecialchars($idHidden, ENT_QUOTES, 'UTF-8') ?>">
</label>
