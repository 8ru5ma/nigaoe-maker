<?php
/**
 * にがおえメーカー - パーツ CRUD API
 *
 * GET    /api/parts.php           → パーツ一覧（カテゴリ別）
 * POST   /api/parts.php           → パーツ追加（管理者のみ）
 * DELETE /api/parts.php?id=xxx    → パーツ削除（管理者のみ）
 */

require_once __DIR__ . '/config.php';

handleCors();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        listParts();
    } elseif ($method === 'POST') {
        checkAdmin();
        createPart();
    } elseif ($method === 'DELETE') {
        checkAdmin();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonErr(400, 'id が指定されていません');
        deletePart($id);
    } else {
        jsonErr(405, 'Method Not Allowed');
    }
} catch (PDOException $e) {
    // DB エラーは詳細を隠す
    error_log('[nigaoe] PDO error: ' . $e->getMessage());
    jsonErr(500, 'データベースエラーが発生しました');
}

// ─────────────────────────────────────────────────────────────
//  GET: パーツ一覧
// ─────────────────────────────────────────────────────────────
function listParts()
{
    $rows = getDB()
        ->query('SELECT id, category, label, image_path, extra_path, sort_order FROM parts ORDER BY category, sort_order, id')
        ->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $cat  = $r['category'];
        $part = [
            'id'    => (int)$r['id'],
            'label' => $r['label'],
            'url'   => UPLOAD_URL_BASE . $r['image_path'],
        ];
        // 目カテゴリ: extra_path = 左目（自動生成）
        if ($r['extra_path']) {
            $part['rightUrl'] = UPLOAD_URL_BASE . $r['image_path'];
            $part['leftUrl']  = UPLOAD_URL_BASE . $r['extra_path'];
        }
        $result[$cat][] = $part;
    }

    jsonOk($result);
}

// ─────────────────────────────────────────────────────────────
//  POST: パーツ追加
// ─────────────────────────────────────────────────────────────
function createPart()
{
    $category = trim($_POST['category'] ?? '');
    $label    = trim($_POST['label']    ?? '');
    $eyeSide  = trim($_POST['eye_side'] ?? 'none');  // right | left | both | none

    if (!$category) jsonErr(400, 'category は必須です');
    if (!$label)    jsonErr(400, 'label は必須です');

    $file = $_FILES['image'] ?? null;
    if (!$file)     jsonErr(400, '画像ファイルが必要です');

    validateUpload($file);

    // アップロードディレクトリ確認
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0755, true);
    }
    if (!is_writable(UPLOAD_DIR)) {
        jsonErr(500, 'アップロードディレクトリに書き込めません');
    }

    // ファイル保存
    $filename = generateFilename();
    $destPath = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonErr(500, 'ファイルの保存に失敗しました');
    }

    // 目カテゴリ: 右目 → 左目を自動生成（水平反転）
    $extraFilename = null;
    if ($category === 'eyes' && $eyeSide === 'right') {
        $extraFilename = generateFilename('_left');
        if (!flipH($destPath, UPLOAD_DIR . $extraFilename)) {
            // 反転失敗でも登録は続行（left は null のまま）
            $extraFilename = null;
            error_log('[nigaoe] flipH failed: ' . $destPath);
        }
    }

    // DB 挿入
    $stmt = getDB()->prepare(
        'INSERT INTO parts (category, label, image_path, extra_path) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$category, $label, $filename, $extraFilename]);
    $newId = (int)getDB()->lastInsertId();

    $data = [
        'id'    => $newId,
        'label' => $label,
        'url'   => UPLOAD_URL_BASE . $filename,
    ];
    if ($extraFilename) {
        $data['rightUrl'] = UPLOAD_URL_BASE . $filename;
        $data['leftUrl']  = UPLOAD_URL_BASE . $extraFilename;
    }

    jsonOk($data, 201);
}

// ─────────────────────────────────────────────────────────────
//  DELETE: パーツ削除
// ─────────────────────────────────────────────────────────────
function deletePart($id)
{
    $stmt = getDB()->prepare('SELECT image_path, extra_path FROM parts WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) jsonErr(404, 'パーツが見つかりません');

    // ファイル削除
    $main = UPLOAD_DIR . $row['image_path'];
    if (file_exists($main)) @unlink($main);

    if ($row['extra_path']) {
        $extra = UPLOAD_DIR . $row['extra_path'];
        if (file_exists($extra)) @unlink($extra);
    }

    // DB 削除
    getDB()->prepare('DELETE FROM parts WHERE id = ?')->execute([$id]);

    jsonOk(null);
}

// ─────────────────────────────────────────────────────────────
//  ユーティリティ
// ─────────────────────────────────────────────────────────────
function generateFilename($suffix = '')
{
    return uniqid('p_', true) . $suffix . '.png';
}
