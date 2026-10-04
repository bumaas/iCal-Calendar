<?php

declare(strict_types=1);

/*
 * Gemeinsamer Testrahmen: bindet iCalCalendarReader an den offiziellen Kernel-Stub
 * (symcon/SymconStubs, Submodul tests/stubs, gepinnt auf bf2950f).
 * Von testsuite.php anlegen erzeugt - Netz-Naht und modulspezifische Helfer sind ein
 * TODO-Grundgerüst, kein fertiger Testrahmen. Siehe stub-und-vorlagen.md.
 *
 * Einbinden mit require_once __DIR__ . '/harness.php'; Instanzen über neueInstanz().
 */

require_once __DIR__ . '/stubs/autoload.php';

// PHP-Warnungen/-Notices sollen Tests abbrechen, nicht still durchlaufen.
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

require_once dirname(__DIR__) . '/iCalCalendarReader/module.php';

final class iCalCalendarReaderHarness extends iCalCalendarReader
{
    public const MODULE_ID = '{5127CDDC-2859-4223-A870-4D26AC83622C}'; // iCalCalendarReader/module.json

    /**
     * Netz-Naht: LoadCalendarURL() ist der einzige Netzzugriff (curl). Ist $urlAntwort
     * gesetzt, liefert die Harness stattdessen [Status, Inhalt] - kein echter Abruf.
     *
     * @var array{0: int, 1: string}|null
     */
    public ?array $urlAntwort = null;
    public int $urlAbrufe     = 0;

    public function LoadCalendarURL(string &$content): int
    {
        if ($this->urlAntwort === null) {
            throw new RuntimeException('Test ohne $urlAntwort würde echt ins Netz gehen');
        }
        $this->urlAbrufe++;
        [$status, $content] = $this->urlAntwort;
        return $status;
    }

    /** @var list<array{0: string, 1: mixed}> jedes SetValue */
    public array $writes = [];
    /** @var list<int> jedes SetStatus */
    public array $status = [];
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer  = [];
    private int $logOffset = 0;

    protected function getTime(): int
    {
        return time();
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        $ok = parent::SetValue($Ident, $Value);
        $this->writes[] = [$Ident, $Value];
        return $ok;
    }

    protected function SetStatus(int $Status): bool
    {
        $this->status[] = $Status;
        return parent::SetStatus($Status);
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        $this->timer[$Ident] = $Milliseconds;
        return parent::SetTimerInterval($Ident, $Milliseconds);
    }

    /** InstanceID ist im Stub protected - für Tests von außen lesbar machen. */
    public function instanzId(): int
    {
        return $this->InstanceID;
    }

    /** Alle Variablenwerte der Instanz (Ident -> Wert), typgetreu aus dem Kernel-Stub. */
    public function werte(): array
    {
        $werte = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $vid) {
            $obj = IPS_GetObject($vid);
            if ($obj['ObjectType'] === 2 /* Variable */) {
                $werte[$obj['ObjectIdent']] = GetValue($vid);
            }
        }
        return $werte;
    }

    public function logsZuruecksetzen(): void
    {
        $this->logOffset = count(IPS\LogServer::getLogMessages((string) $this->InstanceID));
    }

    /** @return list<array{Message: string, Type: int}> */
    public function logsSeitMarke(): array
    {
        return array_values(array_slice(IPS\LogServer::getLogMessages((string) $this->InstanceID), $this->logOffset));
    }
}

/** Legt eine Instanz im Kernel-Stub an (Create + ApplyChanges laufen in createInstance). */
function neueInstanz(): iCalCalendarReaderHarness
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => iCalCalendarReaderHarness::MODULE_ID,
        'ModuleName' => 'iCalCalendarReader',
        'ModuleType' => 3,
        'Class'      => iCalCalendarReaderHarness::class,
    ]);
    return IPS\InstanceManager::getInstanceInterface($id);
}

/**
 * Util Control: Das Modul fragt vor dem Löschen einer Notifier-Variable per
 * UC_FindReferences(), ob sie noch verwendet wird. Der Stub kennt weder die Instanz
 * noch die Funktion - beides liefert die Harness; Referenzen setzt der Test über
 * UtilControlAttrappe::$referenzen (Variablen-ID => Liste referenzierender IDs).
 */
final class UtilControlAttrappe extends IPSModuleStrict
{
    public const MODULE_ID = '{B69010EA-96D5-46DF-B885-24821B8C8DBD}';

    /** @var array<int, list<int>> */
    public static array $referenzen = [];
}

function UC_FindReferences(int $InstanceID, int $ID): array
{
    return UtilControlAttrappe::$referenzen[$ID] ?? [];
}

function neueUtilControl(): void
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => UtilControlAttrappe::MODULE_ID,
        'ModuleName' => 'Util Control',
        'ModuleType' => 0,
        'Class'      => UtilControlAttrappe::class,
    ]);
}

$pruefungen = 0;
$fehler     = [];
function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler[] = $text;
    }
    echo ($ok ? '  ok   ' : '  FEHL ') . $text . "\n";
}

/** Schlusszeile und Exit-Code - Format ist Pflicht (K2), rotgruen.php parst genau das. */
function ergebnis(): never
{
    global $pruefungen, $fehler;
    echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
    exit($fehler === [] ? 0 : 1);
}

IPS\Kernel::reset(); // einmal je Testlauf; weitere Instanzen entstehen im selben Kernel
