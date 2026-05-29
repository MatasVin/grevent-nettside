<?php
/**
 * Grevent AS — Filmottaker
 * Mottar filer fra send-filer.html, lagrer dem og sender e-postvarsling.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'melding' => 'Kun POST er tillatt.']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$mottaker_epost = 'matas.vin@gmail.com'; // Bytt til post@grevent.no etter testing
$server_domain  = $_SERVER['SERVER_NAME'] ?? 'grevent.no';
$fra_epost      = 'noreply@' . $server_domain;

function rens(string $v): string {
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}

$navn  = rens($_POST['navn']  ?? '');
$epost = filter_var(trim($_POST['epost'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$navn || !$epost) {
    http_response_code(400);
    echo json_encode(['success' => false, 'melding' => 'Navn og e-post er påkrevd.']);
    exit;
}

if (empty($_FILES['filer'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'melding' => 'Ingen filer mottatt.']);
    exit;
}

$tillatte_ext = ['jpg','jpeg','png','gif','webp','svg','pdf','ppt','pptx','pps','ppsx',
                 'doc','docx','xls','xlsx','key','pages','numbers','ai','eps','psd',
                 'mp4','mov','avi','mkv','wmv','m4v','zip','rar'];

$upload_base = __DIR__ . '/uploads/';
$token       = bin2hex(random_bytes(16));
$upload_dir  = $upload_base . $token . '/';

if (!is_dir($upload_base)) {
    mkdir($upload_base, 0755, true);
}
if (!mkdir($upload_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'melding' => 'Kunne ikke opprette mappe på server.']);
    exit;
}

function format_bytes(int $b): string {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024)    return round($b / 1024, 0)    . ' KB';
    return $b . ' B';
}

$lagrede = [];
$filer   = $_FILES['filer'];
$antall  = is_array($filer['name']) ? count($filer['name']) : 0;

for ($i = 0; $i < $antall; $i++) {
    if ($filer['error'][$i] !== UPLOAD_ERR_OK) continue;

    $original = $filer['name'][$i];
    $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    if (!in_array($ext, $tillatte_ext, true)) continue;

    // Sanitiser filnavn
    $sikkert = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
    $maal    = $upload_dir . $sikkert;

    // Unngå duplikater
    $base = pathinfo($sikkert, PATHINFO_FILENAME);
    $n    = 1;
    while (file_exists($maal)) {
        $maal = $upload_dir . $base . '_' . $n . '.' . $ext;
        $n++;
    }

    if (move_uploaded_file($filer['tmp_name'][$i], $maal)) {
        $lagrede[] = ['navn' => $original, 'storrelse' => $filer['size'][$i]];
    }
}

if (empty($lagrede)) {
    // Rydd opp tom mappe
    @rmdir($upload_dir);
    http_response_code(400);
    echo json_encode(['success' => false, 'melding' => 'Ingen gyldige filer ble lastet opp. Sjekk filtypen og prøv igjen.']);
    exit;
}

// Bygg e-post
$fil_liste = implode("\n", array_map(
    fn($f) => '  - ' . $f['navn'] . ' (' . format_bytes($f['storrelse']) . ')',
    $lagrede
));

$nedlastings_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $server_domain . '/uploads/' . $token . '/';

$body  = "Nye filer mottatt via grevent.no\n";
$body .= str_repeat('=', 40) . "\n\n";
$body .= "Fra:       $navn\n";
$body .= "E-post:    $epost\n";
$body .= "Antall:    " . count($lagrede) . " fil(er)\n";
$body .= "Tidspunkt: " . date('d.m.Y H:i') . "\n\n";
$body .= "Filer:\n$fil_liste\n\n";
$body .= "Last ned: $nedlastings_url\n\n";
$body .= str_repeat('=', 40) . "\n";

$headers  = "From: \"Grevent Filmottak\" <{$fra_epost}>\r\n";
$headers .= "Reply-To: \"$navn\" <$epost>\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

mail($mottaker_epost, '[Grevent.no] Nye filer fra ' . $navn, $body, $headers);

echo json_encode(['success' => true, 'antall' => count($lagrede)]);
