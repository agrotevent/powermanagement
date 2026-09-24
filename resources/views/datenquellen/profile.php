<?php
use App\Support\View;

$title = 'Registerprofile';
$subtitle = 'Welche Modbus-Register ein Gerät hat und wohin die Werte gehören';
?>
<div class="toolbar-zeile">
    <p class="small muted mb0">Mitgeliefert: Victron GX (System + VE.Bus) und eine Vorlage für ComAp InteliGen 200. Die ComAp-Adressen
        stehen in der Modbus-Registerliste, die InteliConfig für die jeweilige Steuerung erzeugt.</p>
    <div class="spacer"></div>
    <?php if (View::can('datenquellen.manage')): ?><a class="btn btn-sm btn-primary" href="/datenquellen/profile/0">+ Eigenes Profil</a><?php endif; ?>
</div>
<div class="card">
    <div class="table-wrap">
        <table class="tabelle-karten">
            <thead><tr><th>Profil</th><th>Hersteller</th><th class="num">Werte</th><th class="num">ohne Adresse</th><th class="num">Befehle</th></tr></thead>
            <tbody>
            <?php foreach ($profile as $p): ?>
                <tr>
                    <td data-label="Profil"><a href="/datenquellen/profile/<?= (int) $p['id'] ?>"><strong><?= View::e($p['name']) ?></strong></a>
                        <?= (int) $p['ist_mitgeliefert'] === 1 ? ' <span class="badge">mitgeliefert</span>' : '' ?>
                        <div class="small muted mono"><?= View::e($p['schluessel']) ?></div></td>
                    <td data-label="Hersteller"><?= View::e($p['hersteller']) ?></td>
                    <td data-label="Werte" class="num"><?= count($p['register']) ?></td>
                    <td data-label="ohne Adresse" class="num"><?= $p['ohne_adresse'] > 0 ? '<span class="text-warn">' . (int) $p['ohne_adresse'] . '</span>' : '0' ?></td>
                    <td data-label="Befehle" class="num"><?= count(array_filter($p['befehle'], static fn ($b) => !empty($b['aktiv']))) ?> / <?= count($p['befehle']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
