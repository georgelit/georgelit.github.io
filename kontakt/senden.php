<?php
/**
 * Kontaktformular der Website glitcircle.com
 *
 * Bewusst ohne Bibliothek und ohne Captcha. Gegen Formularspam wirken drei
 * Dinge zusammen: ein unsichtbares Zusatzfeld, eine Mindestzeit zwischen
 * Aufruf und Absenden, und eine Begrenzung pro IP je Stunde. Das reicht fuer
 * eine Seite dieser Groesse und laedt keinen fremden Dienst nach, der wieder
 * eine Einwilligung braeuchte.
 *
 * WICHTIG zur Zustellung: die Domain glitcircle.com laesst per SPF nur
 * Microsoft 365 als Absender zu (v=spf1 include:spf.protection.outlook.com
 * -all). Eine Mail, die dieser Webspace im Namen von @glitcircle.com
 * verschickt, faellt deshalb durch die Pruefung und landet im besten Fall im
 * Spam. Darum setzt dieses Skript KEINEN eigenen Absender auf der Hauptdomain,
 * sondern ueberlaesst ihn dem Server und traegt die Adresse des Absenders nur
 * als Reply-To ein.
 *
 * Zusaetzlich wird jede Anfrage als Datei ausserhalb des Webverzeichnisses
 * abgelegt. Selbst wenn die Mail unterwegs verlorengeht, ist die Anfrage da.
 */

declare(strict_types=1);

const EMPFAENGER      = 'hello@glitcircle.com';
const ABLAGE          = __DIR__ . '/../../kontakt-eingang';
const MINDESTZEIT     = 4;      // Sekunden zwischen Seitenaufbau und Absenden
// Grosszuegig, weil der Zeitstempel beim Bauen der Seite entsteht und die
// Seite ausgeliefert und zwischengespeichert wird, bevor jemand sie ausfuellt.
const HOECHSTZEIT     = 7776000; // 90 Tage
const MAX_PRO_STUNDE  = 5;      // je IP

function zurueck(string $status): never
{
    // Zurueck auf die Seite, von der das Formular kam. Ohne das landet ein
    // englischer Besucher ohne JavaScript auf der deutschen Kontaktseite.
    $erlaubt = ['/kontakt/', '/en/contact/'];
    $quelle = (string)($_POST['quelle'] ?? '');
    $pfad = in_array($quelle, $erlaubt, true) ? $quelle : '/kontakt/';
    $ziel = 'https://glitcircle.com' . $pfad . '?status=' . rawurlencode($status);
    if (($_SERVER['HTTP_ACCEPT'] ?? '') !== '' && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status === 'ok' ? 200 : 400);
        echo json_encode(['status' => $status], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . $ziel, true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zurueck('methode');
}

// --- Unsichtbares Zusatzfeld. Menschen sehen es nicht, viele Skripte fuellen es. ---
if (trim((string)($_POST['webseite'] ?? '')) !== '') {
    // Nicht als Fehler melden: wer hier haengenbleibt, soll das nicht merken.
    zurueck('ok');
}

// --- Mindestzeit ---
$auf = (int)($_POST['auf'] ?? 0);
$alter = time() - $auf;
if ($auf <= 0 || $alter < MINDESTZEIT || $alter > HOECHSTZEIT) {
    zurueck($alter > HOECHSTZEIT ? 'abgelaufen' : 'zuschnell');
}

// --- Pflichtfelder ---
$name      = trim((string)($_POST['name'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$firma     = trim((string)($_POST['firma'] ?? ''));
$telefon   = trim((string)($_POST['telefon'] ?? ''));
$nachricht = trim((string)($_POST['nachricht'] ?? ''));

if ($name === '' || $email === '' || $nachricht === '') {
    zurueck('unvollstaendig');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    zurueck('email');
}
if (mb_strlen($name) > 120 || mb_strlen($firma) > 160 || mb_strlen($telefon) > 60 || mb_strlen($nachricht) > 8000) {
    zurueck('zulang');
}
// Zeilenumbrueche in Kopfzeilen sind der klassische Weg, fremde Empfaenger
// unterzuschieben. Sie haben in diesen Feldern nichts zu suchen.
foreach ([$name, $email, $firma, $telefon] as $feld) {
    if (preg_match('/[\r\n]/', $feld)) {
        zurueck('ungueltig');
    }
}

// --- Begrenzung je IP ---
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
@mkdir(ABLAGE, 0700, true);
$zaehlerDatei = ABLAGE . '/.rate-' . hash('sha256', $ip) . '.txt';
$jetzt = time();
$treffer = [];
if (is_readable($zaehlerDatei)) {
    $treffer = array_filter(
        array_map('intval', explode(',', (string)file_get_contents($zaehlerDatei))),
        static fn (int $t): bool => $t > $jetzt - 3600
    );
}
if (count($treffer) >= MAX_PRO_STUNDE) {
    zurueck('zuviele');
}
$treffer[] = $jetzt;
@file_put_contents($zaehlerDatei, implode(',', $treffer), LOCK_EX);

// --- Ablage. Die Anfrage ist damit gesichert, unabhaengig vom Mailversand. ---
$datensatz = [
    'zeitpunkt' => gmdate('c'),
    'name'      => $name,
    'email'     => $email,
    'firma'     => $firma,
    'telefon'   => $telefon,
    'nachricht' => $nachricht,
    'quelle'    => (string)($_POST['quelle'] ?? ''),
    'ip'        => $ip,
];
@file_put_contents(
    ABLAGE . '/' . gmdate('Y-m-d_His') . '_' . substr(hash('sha256', $email . $jetzt), 0, 8) . '.json',
    json_encode($datensatz, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

// --- Benachrichtigung ---
$betreff = 'Anfrage über die Website: ' . mb_substr($name, 0, 60);
$rumpf = "Neue Anfrage über das Formular auf glitcircle.com\n\n"
    . "Name:      {$name}\n"
    . "E-Mail:    {$email}\n"
    . ($firma !== ''   ? "Firma:     {$firma}\n"   : '')
    . ($telefon !== '' ? "Telefon:   {$telefon}\n" : '')
    . "Seite:     " . ($datensatz['quelle'] !== '' ? $datensatz['quelle'] : 'unbekannt') . "\n"
    . "Zeitpunkt: " . gmdate('d.m.Y H:i') . " UTC\n\n"
    . "Nachricht:\n{$nachricht}\n";

// Kein eigener From auf glitcircle.com, siehe Kopfkommentar. Reply-To zeigt
// auf die Adresse des Absenders, damit die Antwort direkt funktioniert.
$kopf = [
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: glitcircle-formular',
];

$verschickt = @mail(
    EMPFAENGER,
    '=?UTF-8?B?' . base64_encode($betreff) . '?=',
    $rumpf,
    implode("\r\n", $kopf)
);

// Auch wenn der Versand scheitert, ist die Anfrage abgelegt. Der Besucher
// bekommt deshalb die Bestaetigung, und der Fehlschlag wird protokolliert.
if (!$verschickt) {
    @file_put_contents(ABLAGE . '/.versandfehler.log', gmdate('c') . " {$email}\n", FILE_APPEND | LOCK_EX);
}

zurueck('ok');
