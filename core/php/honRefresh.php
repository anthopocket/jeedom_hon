<?php
/* Rafraîchissement différé d'un équipement hOn, lancé en arrière-plan par hon::scheduleRefresh().
 * Usage (CLI uniquement) : php honRefresh.php <eqLogic_id> <délai1> [délai2 ...]
 * Exemple : php honRefresh.php 130 10  → rafraîchit au bout de 10 s
 */
if (php_sapi_name() != 'cli' || $argc < 3) exit(1);

require_once __DIR__ . '/../../../../core/php/core.inc.php';

$eqLogic = eqLogic::byId(intval($argv[1]));
if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'hon') exit(1);

$elapsed = 0;
for ($i = 2; $i < $argc; $i++) {
    $at = max(0, intval($argv[$i]));
    if ($at > $elapsed) sleep($at - $elapsed);
    $elapsed = max($elapsed, $at);
    log::add('hon', 'debug', 'Rafraîchissement différé (+' . $at . ' s) de ' . $eqLogic->getName());
    $eqLogic->refreshInfo();
}
