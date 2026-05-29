<?php
/**
 * Grevent AS — Kontaktskjema e-posthandler
 * Legg denne filen i rotkatalogen på webhuset.no (samme mappe som index.html)
 *
 * Bruk: Oppdater $mottaker_epost til riktig adresse om nødvendig.
 */

// Tillat kun POST-forespørsler
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'melding' => 'Kun POST er tillatt.']);
    exit;
}

// Konfigurasjon — endre disse ved behov
$mottaker_epost = 'matas.vin@gmail.com';
$mottaker_navn  = 'Grevent AS';
$emne_prefix    = '[Grevent.no] Ny forespørsel: ';

// Fra-adresse: bruk serverens eget domene for å unngå SPF-avvisning.
// Reply-To settes til avsenderen slik at du kan svare direkte.
$server_domain = $_SERVER['SERVER_NAME'] ?? 'grevent.no';
$fra_epost     = 'noreply@' . $server_domain;

// Hjelper: rens inndata
function rens(string $verdi): string {
    return htmlspecialchars(strip_tags(trim($verdi)), ENT_QUOTES, 'UTF-8');
}

// Hent og valider felter
$navn    = rens($_POST['navn']    ?? '');
$firma   = rens($_POST['firma']   ?? '');
$epost   = filter_var(trim($_POST['epost'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefon = rens($_POST['telefon'] ?? '');
$pakke   = rens($_POST['pakke']   ?? '');
$melding = rens($_POST['melding'] ?? '');

// Valider obligatoriske felter
if (!$navn || !$epost || !$melding) {
    http_response_code(400);
    echo json_encode(['success' => false, 'melding' => 'Navn, e-post og melding er obligatorisk.']);
    exit;
}

// Bygg e-postemne
$emne = $emne_prefix . ($pakke ?: 'Generell henvendelse');

// Bygg e-posttekst (plain text)
$body  = "Ny forespørsel via grevent.no\n";
$body .= str_repeat('=', 40) . "\n\n";
$body .= "Navn:     $navn\n";
if ($firma)   $body .= "Firma:    $firma\n";
$body .= "E-post:   $epost\n";
if ($telefon) $body .= "Telefon:  $telefon\n";
if ($pakke)   $body .= "Pakke:    $pakke\n";
$body .= "\nMelding:\n" . str_repeat('-', 30) . "\n$melding\n";
$body .= "\n" . str_repeat('=', 40) . "\n";
$body .= "Sendt: " . date('d.m.Y H:i') . "\n";
$body .= "\n(Svar på denne e-posten går direkte til $epost)\n";


// Send enkel klartekst-e-post (unngår spamfiltre)
$headers  = "From: \"Grevent Kontaktskjema\" <{$fra_epost}>\r\n";
$headers .= "Reply-To: \"$navn\" <$epost>\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$sendt = mail($mottaker_epost, $emne, $body, $headers);

if (!$sendt) {
    error_log('[Grevent] mail() feilet for forespørsel fra ' . $epost . ' — ' . date('c'));
}

// Svar til klienten
header('Content-Type: application/json; charset=UTF-8');

if ($sendt) {
    // Send bekreftelse til avsender
    $bekr_emne   = 'Takk for henvendelsen, ' . $navn . '!';
    $bekr_tekst  = "Hei $navn,\n\nTakk for at du tok kontakt med Grevent AS.\n";
    $bekr_tekst .= "Vi har mottatt din forespørsel og tar kontakt så snart som mulig.\n\n";
    $bekr_tekst .= "Ha en fin dag videre!\nGrevent AS\npost@grevent.no";

    $bekr_headers  = "From: \"Grevent AS\" <{$fra_epost}>\r\n";
    $bekr_headers .= "Reply-To: post@grevent.no\r\n";
    $bekr_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    mail($epost, $bekr_emne, $bekr_tekst, $bekr_headers);

    echo json_encode(['success' => true, 'melding' => 'Takk! Vi tar kontakt snart.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'melding' => 'Noe gikk galt. Send oss en e-post på post@grevent.no.']);
}
