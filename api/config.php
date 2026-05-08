<?php
/**
 * にがおえメーカー - 設定ファイル
 * ★ マークの箇所をサーバーにアップ後に書き換えてください
 */

// ═══════════════════════════════════════════════════════════════
//  ★ DB接続設定（シンクラウド/エックスサーバー）
//     cPanel の MySQL データベースウィザードで作成した情報を入力
// ═══════════════════════════════════════════════════════════════
define('DB_HOST',    'localhost');              // 通常は localhost のまま
define('DB_NAME',    'CHANGE_ME_dbname');       // ★ データベース名
define('DB_USER',    'CHANGE_ME_user');         // ★ DBユーザー名
define('DB_PASS',    'CHANGE_ME_password');     // ★ DBパスワード
define('DB_CHARSET', 'utf8mb4');

// ═══════════════════════════════════════════════════════════════
//  ★ 管理者パスワード
//     admin.html でパーツ追加・削除する際に使います
//     必ず変更してください（英数字記号推奨）
// ═══════════════════════════════════════════════════════════════
define('ADMIN_PASSWORD', 'CHANGE_ME_admin_password');

// ═══════════════════════════════════════════════════════════════
//  ★ アップロード URL パス
//     サイトが https://example.com/nigaoe/ にある場合
//       → '/nigaoe/uploads/parts/'
//     サイトが https://example.com/ にある場合
//       → '/uploads/parts/'
// ═══════════════════════════════════════════════════════════════
define('UPLOAD_URL_BASE', '/uploads/parts/');
define('UPLOAD_DIR',      realpath(__DIR__ . '/../uploads/parts') . '/');
define('UPLOAD_MAX_SIZE', 2 * 1024 * 1024);  // 2 MB

// ═══════════════════════════════════════════════════════════════
//  初回セットアップ SQL
//  phpMyAdmin などで以下を一度だけ実行してください
// ═══════════════════════════════════════════════════════════════
/*
CREATE TABLE IF NOT EXISTS parts (
  id         INT          AUTO_INCREMENT PRIMARY KEY,
  category   VARCHAR(50)  NOT NULL,
  label      VARCHAR(100) NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  extra_path VARCHAR(255) DEFAULT NULL COMMENT '目カテゴリの左目画像（自動生成）',
  sort_order INT          DEFAULT 0,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS avatars (
  id          INT         AUTO_INCREMENT PRIMARY KEY,
  parts_json  TEXT        NOT NULL,
  share_token VARCHAR(32) UNIQUE,
  created_at  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

// ═══════════════════════════════════════════════════════════════
//  共通ヘルパー関数
// ═══════════════════════════════════════════════════════════════

/** PDO 接続（シングルトン） */
function getDB()
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** 成功 JSON を返して終了 */
function jsonOk($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** エラー JSON を返して終了 */
function jsonErr($status, $msg)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 管理者トークン認証 */
function checkAdmin()
{
    $tok = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if ($tok !== ADMIN_PASSWORD) {
        jsonErr(401, '管理者パスワードが違います');
    }
}

/** CORS・OPTIONS 処理（同一ドメインのみ許可） */
function handleCors()
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** PNG 画像を左右反転して保存（GD使用） */
function flipH($srcPath, $dstPath)
{
    $img = @imagecreatefrompng($srcPath);
    if (!$img) return false;
    imageflip($img, IMG_FLIP_HORIZONTAL);
    $ok = imagepng($img, $dstPath, 9);
    imagedestroy($img);
    return $ok;
}

/** アップロードファイルのバリデーション */
function validateUpload($f)
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $msgs = [
            UPLOAD_ERR_INI_SIZE  => 'php.ini の upload_max_filesize を超えています',
            UPLOAD_ERR_FORM_SIZE => 'フォームの MAX_FILE_SIZE を超えています',
            UPLOAD_ERR_PARTIAL   => 'アップロードが途中で中断されました',
            UPLOAD_ERR_NO_FILE   => 'ファイルが送信されていません',
        ];
        jsonErr(400, $msgs[$f['error']] ?? 'アップロードエラー (code ' . $f['error'] . ')');
    }
    if ($f['size'] > UPLOAD_MAX_SIZE) {
        jsonErr(400, 'ファイルは 2MB 以下にしてください');
    }
    // MIME タイプ確認（偽装対策）
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
    if ($mime !== 'image/png') {
        jsonErr(400, 'PNG ファイルのみアップロードできます（検出: ' . $mime . '）');
    }
}
