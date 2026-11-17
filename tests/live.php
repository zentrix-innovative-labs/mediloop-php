<?php
// A smoke test against the live API. Usage: MEDILOOP_API_KEY=sk_live_... MEDILOOP_FACILITY=<uuid> php tests/live.php
require __DIR__ . '/../autoload.php';
use Mediloop\Client;
$m = (new Client(getenv('MEDILOOP_API_KEY') ?: ''))->forFacility(getenv('MEDILOOP_FACILITY') ?: '');
$cover = $m->post('/api/v1/coverage/verify/', ['patient_uhid' => getenv('MEDILOOP_PATIENT') ?: '', 'benefit_category' => 'consultation', 'amount' => 50000]);
echo 'cover: ', json_encode(['covered' => $cover['covered'], 'insurer' => $cover['primary']['insurer_name'] ?? null, 'patient_pays' => $cover['patient_responsibility']]), "\n";
$n = 0;
foreach ($m->paginate('/api/v1/healthcare-billing/bills/', ['page_size' => 10]) as $bill) { $n++; }
echo "bills read across pages: $n\n";
try {
    $m->get('/api/v1/fraud/flags/');
} catch (Mediloop\PermissionException $e) {
    echo 'refusal carried its reason: ', $e->errorCode, ' (HTTP ', $e->status, ")\n";
}
