<?php
/**
 * HINP WhatsApp Webhook Handler
 * ─────────────────────────────────────────────────────────
 * POST /webhook.php  ←  Infobip sends incoming WhatsApp messages here
 * GET  /webhook.php  ←  Dashboard reads stored messages from here
 * ─────────────────────────────────────────────────────────
 */

// Allow dashboard (same domain) to call this endpoint
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$DATA_FILE = __DIR__ . '/messages_store.json';

// ── Helpers ───────────────────────────────────────────────
function loadMessages($file) {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveMessages($file, array $messages) {
    // Keep last 1000 messages to avoid huge file
    if (count($messages) > 1000) {
        $messages = array_slice($messages, -1000);
    }
    @file_put_contents(
        $file,
        json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

// ── POST: Receive webhook from Infobip ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $body    = file_get_contents('php://input');
    $payload = json_decode($body, true);

    if (!$payload || !isset($payload['results'])) {
        echo json_encode(['status' => 'ok', 'note' => 'empty or non-standard payload']);
        exit;
    }

    $messages = loadMessages($DATA_FILE);
    $saved    = 0;

    foreach ($payload['results'] as $m) {
        $msgId = $m['messageId'] ?? null;

        // Avoid duplicates
        if ($msgId) {
            foreach ($messages as $existing) {
                if (($existing['id'] ?? '') === $msgId) continue 2;
            }
        }

        $messages[] = [
            'id'      => $msgId ?? uniqid('msg_', true),
            'from'    => $m['from']              ?? 'unknown',
            'contact' => $m['contact']['name']   ?? null,
            'text'    => $m['message']['text']
                      ?? $m['message']['caption']
                      ?? '[' . ($m['message']['type'] ?? 'media') . ']',
            'type'    => $m['message']['type']   ?? 'TEXT',
            'time'    => $m['receivedAt']         ?? date('c'),
        ];
        $saved++;
    }

    saveMessages($DATA_FILE, $messages);

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'saved' => $saved]);
    exit;
}

// ── GET: Serve messages to dashboard ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $messages = loadMessages($DATA_FILE);
    echo json_encode([
        'messages' => $messages,
        'total'    => count($messages),
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
