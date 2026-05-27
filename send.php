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
$mottaker_epost = 'post@grevent.no';
$mottaker_navn  = 'Grevent AS';
$emne_prefix    = '[Grevent.no] Ny forespørsel: ';

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

// Bygg HTML-versjon av e-posten
$html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
$html .= '<style>body{font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;}';
$html .= 'h2{color:#00AEEF;border-bottom:2px solid #00AEEF;padding-bottom:8px;}';
$html .= '.felt{margin:12px 0;} .etikett{font-weight:bold;color:#58595B;font-size:13px;text-transform:uppercase;letter-spacing:1px;}';
$html .= '.verdi{margin-top:4px;font-size:15px;} .melding-boks{background:#f7f8fa;border-left:3px solid #00AEEF;padding:12px 16px;margin-top:8px;border-radius:0 4px 4px 0;}';
$html .= '.footer{margin-top:32px;font-size:12px;color:#999;border-top:1px solid #eee;padding-top:16px;}';
$html .= '</style></head><body>';
$html .= '<h2>Ny forespørsel via grevent.no</h2>';
$html .= '<div class="felt"><div class="etikett">Navn</div><div class="verdi">' . $navn . '</div></div>';
if ($firma)   $html .= '<div class="felt"><div class="etikett">Firma</div><div class="verdi">' . $firma . '</div></div>';
$html .= '<div class="felt"><div class="etikett">E-post</div><div class="verdi"><a href="mailto:' . $epost . '">' . $epost . '</a></div></div>';
if ($telefon) $html .= '<div class="felt"><div class="etikett">Telefon</div><div class="verdi">' . $telefon . '</div></div>';
if ($pakke)   $html .= '<div class="felt"><div class="etikett">Interessert i</div><div class="verdi">' . $pakke . '</div></div>';
$html .= '<div class="felt"><div class="etikett">Melding</div><div class="melding-boks">' . nl2br($melding) . '</div></div>';
$html .= '<div class="footer">Sendt ' . date('d.m.Y \k\l. H:i') . ' fra grevent.no</div>';
$html .= '</body></html>';

// Bygg MIME-e-post (plain text + HTML)
$boundary = '----=_Part_' . uniqid();

$headers  = "From: \"Grevent Kontaktskjema\" <post@grevent.no>\r\n";
$headers .= "Reply-To: \"$navn\" <$epost>\r\n";
$headers .= "To: \"$mottaker_navn\" <$mottaker_epost>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
$headers .= "X-Mailer: Grevent-Nettside/1.0\r\n";

$mime  = "--$boundary\r\n";
$mime .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
$mime .= $body . "\r\n";
$mime .= "--$boundary\r\n";
$mime .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
$mime .= $html . "\r\n";
$mime .= "--$boundary--";

// Send e-post
$sendt = mail($mottaker_epost, $emne, $mime, $headers);

// Svar til klienten
header('Content-Type: application/json; charset=UTF-8');

if ($sendt) {
    // Send bekreftelse til avsender
    $bekr_emne   = 'Takk for henvendelsen, ' . $navn . '!';
    $bekr_tekst  = "Hei $navn,\n\nTakk for at du tok kontakt med Grevent AS.\n";
    $bekr_tekst .= "Vi har mottatt din forespørsel og tar kontakt så snart som mulig.\n\n";
    $bekr_tekst .= "Ha en fin dag videre!\nGrevent AS\npost@grevent.no";

    $bekr_headers  = "From: \"Grevent AS\" <post@grevent.no>\r\n";
    $bekr_headers .= "Reply-To: post@grevent.no\r\n";
    $bekr_headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    mail($epost, $bekr_emne, $bekr_tekst, $bekr_headers);

    echo json_encode(['success' => true, 'melding' => 'Takk! Vi tar kontakt snart.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'melding' => 'Noe gikk galt. Ring oss på +47 XXX XX XXX.']);
}
