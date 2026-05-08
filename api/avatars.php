<?php
/**
 * にがおえメーカー - 似顔絵保存・共有 API
 *
 * POST /api/avatars.php          → 似顔絵保存 → share_token を返す
 * GET  /api/avatars.php?token=xx → 似顔絵データ取得
 */

require_once __DIR__ . '/config.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        saveAvatar();
    } elseif ($method === 'GET') {
        $token = trim($_GET['token'] ?? '');
        if (!$token) jsonErr(400, 'token が指定されていません');
        loadAvatar($token);
    } else {
        jsonErr(405, 'Method Not Allowed');
    }
} catch (PDOException $e) {
    error_log('[nigaoe] PDO error: ' . $e->getMessage());
    jsonErr(500, 'データベースエラーが発生しました');
}

// ─────────────────────────────────────────────────────────────
//  POST: 似顔絵を保存してトークンを返す
// ─────────────────────────────────────────────────────────────
function saveAvatar()
{
    $raw = file_get_contents('php://input');
    if (!$raw) jsonErr(400, 'リクエストボディが空です');

    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonErr(400, 'JSON のパースに失敗しました');
    }

    // selected と skin だけ保存（余計なデータを除外）
    $payload = [
        'selected' => $input['selected'] ?? [],
        'skin'     => $input['skin'] ?? '#f5c8a0',
    ];

    $partsJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $token     = bin2hex(random_bytes(16));  // 32文字の16進数

    $stmt = getDB()->prepare(
        'INSERT INTO avatars (parts_json, share_token) VALUES (?, ?)'
    );
    $stmt->execute([$partsJson, $token]);

    jsonOk(['token' => $token], 201);
}

// ─────────────────────────────────────────────────────────────
//  GET: トークンで似顔絵データを取得
// ─────────────────────────────────────────────────────────────
function loadAvatar($token)
{
    // トークン形式の簡易チェック（英数字32文字）
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        jsonErr(400, 'トークンの形式が不正です');
    }

    $stmt = getDB()->prepare(
        'SELECT parts_json FROM avatars WHERE share_token = ?'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) jsonErr(404, '共有データが見つかりません');

    $data = json_decode($row['parts_json'], true);
    jsonOk($data);
}
