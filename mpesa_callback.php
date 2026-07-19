<?php
/**
 * mpesa_callback.php - Safaricom calls this URL after an STK Push completes.
 * Must be publicly reachable over HTTPS - set this exact URL as MPESA_CALLBACK_URL
 * in payments.php once your site is live (e.g. https://adalyntechnologies.co.ke/mpesa_callback.php)
 */

header('Content-Type: application/json');

$dataFile = __DIR__ . '/payments_data.json';

function loadData($file) {
    if (!file_exists($file)) return ['quotations' => []];
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : ['quotations' => []];
}

function saveData($file, $data) {
    $fp = fopen($file, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$callback = $payload['Body']['stkCallback'] ?? null;
if (!$callback) {
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
    exit;
}

$checkoutRequestId = $callback['CheckoutRequestID'] ?? '';
$resultCode = $callback['ResultCode'] ?? 1;

$data = loadData($dataFile);
$updated = false;

foreach ($data['quotations'] as &$q) {
    foreach ($q['payments'] as &$p) {
        if ($p['checkout_request_id'] === $checkoutRequestId) {
            if ($resultCode === 0) {
                $p['status'] = 'completed';
                $receipt = '';
                foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
                    if ($item['Name'] === 'MpesaReceiptNumber') {
                        $receipt = $item['Value'];
                    }
                }
                $p['mpesa_receipt'] = $receipt;

                // Advance project stage automatically
                if ($p['type'] === 'deposit' && $q['stage'] === 'awaiting_deposit') {
                    $q['stage'] = 'in_progress';
                } elseif ($p['type'] === 'balance') {
                    $q['stage'] = 'delivered';
                }
            } else {
                $p['status'] = 'failed';
            }
            $updated = true;
            break 2;
        }
    }
}
unset($q, $p);

if ($updated) {
    saveData($dataFile, $data);
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);