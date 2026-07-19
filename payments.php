<?php
/**
 * payments.php - Quotation & M-Pesa payment backend for Adalyn Technologies.
 * Stores data in payments_data.json on the server (no database needed).
 * Requires a real PHP-enabled host (like TrueHost) - will not work on GitHub Pages
 * or a plain "python -m http.server".
 *
 * SETUP REQUIRED before this can charge real money:
 * 1. Sign up at https://developer.safaricom.co.ke
 * 2. Get Consumer Key, Consumer Secret, Passkey, and your Shortcode
 * 3. Fill in the CONFIG section below
 * 4. Change ADMIN_KEY below to your own secret password
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// ===================== CONFIG - FILL THESE IN =====================
define('ADMIN_KEY', 'change-this-to-your-own-secret-password');

define('MPESA_ENV', 'sandbox'); // change to 'production' when ready to charge real money
define('MPESA_CONSUMER_KEY', '');
define('MPESA_CONSUMER_SECRET', '');
define('MPESA_SHORTCODE', '174379'); // 174379 is the Daraja sandbox test shortcode
define('MPESA_PASSKEY', '');
define('MPESA_CALLBACK_URL', 'https://yourdomain.com/mpesa_callback.php'); // must be your real live domain
// =====================================================================

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

function generateReference() {
    return 'ADQ-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
}

function normalizePhone($phone) {
    $phone = preg_replace('/\s+/', '', $phone);
    $phone = str_replace('+', '', $phone);
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    }
    return $phone;
}

function getMpesaBaseUrl() {
    return MPESA_ENV === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function getMpesaAccessToken() {
    $url = getMpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function initiateSTKPush($phone, $amount, $accountRef, $description) {
    $token = getMpesaAccessToken();
    if (!$token) return ['error' => 'Could not authenticate with M-Pesa. Check your Daraja credentials.'];

    $timestamp = date('YmdHis');
    $password = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);
    $phone = normalizePhone($phone);

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => intval(round($amount)),
        'PartyA' => $phone,
        'PartyB' => MPESA_SHORTCODE,
        'PhoneNumber' => $phone,
        'CallBackURL' => MPESA_CALLBACK_URL,
        'AccountReference' => substr($accountRef, 0, 20),
        'TransactionDesc' => substr($description, 0, 100),
    ];

    $ch = curl_init(getMpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

$data = loadData($dataFile);

// ---- Admin: create a quotation ----
if ($method === 'POST' && $action === 'create') {
    if (($input['admin_key'] ?? '') !== ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid admin key.']);
        exit;
    }
    $clientName = trim($input['client_name'] ?? '');
    $clientPhone = trim($input['client_phone'] ?? '');
    $title = trim($input['title'] ?? '');
    $totalAmount = floatval($input['total_amount'] ?? 0);
    $depositPercent = intval($input['deposit_percent'] ?? 50);

    if ($clientName === '' || $title === '' || $totalAmount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Client name, title, and total amount are required.']);
        exit;
    }

    $quotation = [
        'reference' => generateReference(),
        'client_name' => htmlspecialchars($clientName, ENT_QUOTES),
        'client_phone' => htmlspecialchars($clientPhone, ENT_QUOTES),
        'title' => htmlspecialchars($title, ENT_QUOTES),
        'total_amount' => $totalAmount,
        'deposit_percent' => $depositPercent,
        'stage' => 'awaiting_deposit',
        'payments' => [],
        'created_at' => date('c')
    ];
    $data['quotations'][] = $quotation;
    saveData($dataFile, $data);
    echo json_encode(['success' => true, 'quotation' => $quotation]);
    exit;
}

// ---- Admin: list all quotations ----
if ($method === 'GET' && $action === 'list') {
    if (($_GET['admin_key'] ?? '') !== ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid admin key.']);
        exit;
    }
    echo json_encode(['success' => true, 'quotations' => $data['quotations']]);
    exit;
}

// ---- Admin: update project stage ----
if ($method === 'POST' && $action === 'set_stage') {
    if (($input['admin_key'] ?? '') !== ADMIN_KEY) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid admin key.']);
        exit;
    }
    $ref = $input['reference'] ?? '';
    $stage = $input['stage'] ?? '';
    foreach ($data['quotations'] as &$q) {
        if ($q['reference'] === $ref) {
            $q['stage'] = $stage;
            break;
        }
    }
    unset($q);
    saveData($dataFile, $data);
    echo json_encode(['success' => true]);
    exit;
}

// ---- Client: look up a quotation by reference ----
if ($method === 'GET' && $action === 'get') {
    $ref = $_GET['reference'] ?? '';
    foreach ($data['quotations'] as $q) {
        if ($q['reference'] === $ref) {
            echo json_encode(['success' => true, 'quotation' => $q]);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Quotation not found.']);
    exit;
}

// ---- Client: trigger STK Push for deposit or balance ----
if ($method === 'POST' && $action === 'pay') {
    $ref = $input['reference'] ?? '';
    $phone = trim($input['phone'] ?? '');
    $paymentType = $input['payment_type'] ?? '';

    $found = false;
    foreach ($data['quotations'] as &$q) {
        if ($q['reference'] === $ref) {
            $found = true;
            $depositAmount = round($q['total_amount'] * $q['deposit_percent'] / 100, 2);
            $balanceAmount = round($q['total_amount'] - $depositAmount, 2);
            $amount = $paymentType === 'deposit' ? $depositAmount : $balanceAmount;

            if ($phone === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Phone number is required.']);
                exit;
            }

            $stkResult = initiateSTKPush($phone, $amount, $ref, $q['title']);

            $paymentRecord = [
                'id' => uniqid('p_', true),
                'type' => $paymentType,
                'amount' => $amount,
                'phone' => $phone,
                'status' => isset($stkResult['ResponseCode']) && $stkResult['ResponseCode'] === '0' ? 'processing' : 'failed',
                'checkout_request_id' => $stkResult['CheckoutRequestID'] ?? '',
                'created_at' => date('c')
            ];
            $q['payments'][] = $paymentRecord;
            saveData($dataFile, $data);

            if ($paymentRecord['status'] === 'processing') {
                echo json_encode(['success' => true, 'message' => 'Check your phone and enter your M-Pesa PIN to complete payment.']);
            } else {
                echo json_encode(['success' => false, 'error' => $stkResult['error'] ?? ($stkResult['errorMessage'] ?? 'Could not initiate payment. Please try again.')]);
            }
            exit;
        }
    }
    unset($q);

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Quotation not found.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);