<?php
/**
 * Fälligkeit eines Prüflings als Abzeichen – Symbol und Wort, nie nur eine
 * Farbe (Prueflinge::faelligkeit()). Mit $mitText steht die Erklärung
 * („nächste Prüfung am …“) darunter, sonst nur als title.
 *
 * @var array{code:string,symbol:string,wort:string,text:string,klasse:string} $f
 * @var bool|null $mitText
 */
use App\Support\View;

?>
<span class="badge <?= View::e($f['klasse']) ?>" title="<?= View::e($f['text']) ?>"><span aria-hidden="true"><?= View::e($f['symbol']) ?></span> <?= View::e($f['wort']) ?></span><?php if (!empty($mitText)): ?>
<div class="small muted"><?= View::e($f['text']) ?></div><?php endif;
