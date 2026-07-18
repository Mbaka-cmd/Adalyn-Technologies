<?php
/**
 * comments.php - Simple shared comment backend for Adalyn Technologies portfolio page.
 * Stores comments in comments_data.json on the server (no database needed).
 * Works on any standard PHP hosting (like TrueHost). Will NOT work on GitHub Pages
 * or a plain "python -m http.server" - it needs a real PHP-enabled server.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$dataFile = __DIR__ . '/comments_data.json';

function loadComments($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveComments($file, $comments) {
    // Use file locking to avoid corruption if two people post at the same time
    $fp = fopen($file, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($comments, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

$comments = loadComments($dataFile);

if ($method === 'GET' && $action === 'list') {
    echo json_encode(['success' => true, 'comments' => $comments]);
    exit;
}

if ($method === 'POST' && $action === 'add') {
    $name = trim($input['name'] ?? '');
    $text = trim($input['text'] ?? '');
    $rating = intval($input['rating'] ?? 5);
    if ($name === '' || $text === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Name and comment text are required.']);
        exit;
    }
    $newComment = [
        'id' => uniqid('c_', true),
        'name' => htmlspecialchars($name, ENT_QUOTES),
        'text' => htmlspecialchars($text, ENT_QUOTES),
        'rating' => max(1, min(5, $rating)),
        'likes' => 0,
        'replies' => [],
        'created_at' => date('c')
    ];
    array_unshift($comments, $newComment);
    saveComments($dataFile, $comments);
    echo json_encode(['success' => true, 'comment' => $newComment]);
    exit;
}

if ($method === 'POST' && $action === 'edit') {
    $id = $input['id'] ?? '';
    $text = trim($input['text'] ?? '');
    foreach ($comments as &$c) {
        if ($c['id'] === $id) {
            $c['text'] = htmlspecialchars($text, ENT_QUOTES);
            break;
        }
    }
    unset($c);
    saveComments($dataFile, $comments);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'POST' && $action === 'delete') {
    $id = $input['id'] ?? '';
    $comments = array_values(array_filter($comments, function($c) use ($id) {
        return $c['id'] !== $id;
    }));
    saveComments($dataFile, $comments);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'POST' && $action === 'like') {
    $id = $input['id'] ?? '';
    $delta = isset($input['delta']) ? intval($input['delta']) : 1;
    foreach ($comments as &$c) {
        if ($c['id'] === $id) {
            $c['likes'] = max(0, $c['likes'] + $delta);
            break;
        }
    }
    unset($c);
    saveComments($dataFile, $comments);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'POST' && $action === 'reply') {
    $id = $input['id'] ?? '';
    $text = trim($input['text'] ?? '');
    foreach ($comments as &$c) {
        if ($c['id'] === $id) {
            $c['replies'][] = htmlspecialchars($text, ENT_QUOTES);
            break;
        }
    }
    unset($c);
    saveComments($dataFile, $comments);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);