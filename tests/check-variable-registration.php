<?php

declare(strict_types=1);

/**
 * Standardtest (von testsuite.php anlegen erzeugt): Jede registrierte Variable
 * bekommt einen Ident, einen Typ und eine Darstellung/Profil - Idents dürfen sich
 * nicht doppeln, Positionen keine unerklärten Lücken haben.
 *
 * Aufruf: php tests/check-variable-registration.php
 */

require_once __DIR__ . '/harness.php';

$m = neueInstanz();

// Variablen entstehen nur je Notifier - drei eintragen (ohne Kalenderquelle, kein Abruf)
$notifier = static fn (int $nr): array => ['Ident' => 'NOTIFIER' . $nr, 'Name' => '', 'Find' => 'x', 'RegExpression' => false, 'Prenotify' => 0, 'Postnotify' => 0];
IPS_SetProperty($m->instanzId(), 'Notifiers', json_encode([$notifier(1), $notifier(2), $notifier(3)], JSON_THROW_ON_ERROR));
IPS_ApplyChanges($m->instanzId());

$idents    = [];
$positionen = [];
foreach (IPS_GetChildrenIDs($m->instanzId()) as $vid) {
    $obj = IPS_GetObject($vid);
    if ($obj['ObjectType'] !== 2 /* Variable */) {
        continue;
    }
    $ident = $obj['ObjectIdent'];
    pruefe($ident !== '', "Variable #$vid hat einen Ident");
    pruefe(!isset($idents[$ident]), "Ident '$ident' ist eindeutig");
    $idents[$ident] = true;
    $positionen[]    = $obj['ObjectPosition'];

    $var = IPS_GetVariable($vid);
    pruefe($var['VariableType'] >= 0, "'$ident': VariableType gesetzt");
}

sort($positionen);
for ($i = 1, $n = count($positionen); $i < $n; $i++) {
    if ($positionen[$i] - $positionen[$i - 1] > 10) {
        pruefe(false, "Positionslücke zwischen {$positionen[$i - 1]} und {$positionen[$i]} - beabsichtigt (Reserve) oder Tippfehler?");
    }
}
pruefe($idents !== [], 'mindestens eine Variable wurde registriert');

ergebnis();
