<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function respond(array $body, int $statusCode): never
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'PUT'], true)) {
    header('Allow: GET, PUT');
    respond(['error' => 'GETまたはPUTメソッドを使用してください。'], 405);
}

require_once dirname(__DIR__, 2) . '/app/session.php';
startAppSession();
$userId = currentUserId();
if ($userId === null) {
    respond(['error' => 'ログインが必要です。'], 401);
}

try {
    /** @var PDO $pdo */
    $pdo = require dirname(__DIR__, 2) . '/app/db.php';
    // リクエストからユーザーIDを受け取らず、ログイン中のユーザーだけを扱う。
    $statement = $pdo->prepare(
        "SELECT COALESCE(user_colors.colors, '{}'::jsonb) AS colors,
                COALESCE(user_colors.appearance, '{}'::jsonb) AS appearance
         FROM users LEFT JOIN user_colors ON user_colors.user_id = users.id
         WHERE users.id = :user_id"
    );
    $statement->execute([':user_id' => $userId]);
    $account = $statement->fetch();
    if ($account === false) {
        respond(['error' => 'ログインが必要です。'], 401);
    }

    if ($method === 'GET') {
        respond(['data' => [
            'colors' => json_decode($account['colors'], false, 512, JSON_THROW_ON_ERROR),
            'appearance' => json_decode($account['appearance'], false, 512, JSON_THROW_ON_ERROR),
        ]], 200);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', false, 512, JSON_THROW_ON_ERROR);
    if (!($input instanceof stdClass) || !(($input->colors ?? null) instanceof stdClass)) {
        respond(['error' => '配色をオブジェクト形式で指定してください。'], 422);
    }

    require_once dirname(__DIR__, 2) . '/app/color_templates.php';
    $allowedComponents = colorComponentKeys();
    $colors = get_object_vars($input->colors);
    foreach ($colors as $component => $color) {
        // CSSやURLを受け付けず、6桁のHEXカラーだけを保存する。
        if (!in_array($component, $allowedComponents, true)
            || !is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
            respond(['error' => '設定項目と色を確認してください。色は#から始まる6桁で指定します。'], 422);
        }
        $colors[$component] = strtoupper($color);
    }
    $paletteError = colorPaletteError($colors, defaultColorTemplate()['colors']);
    if ($paletteError !== null) {
        respond(['error' => $paletteError], 422);
    }

    // 旧画面から配色だけ送られた場合は、保存済みの表示設定を変更しない。
    $appearanceJson = null;
    if (property_exists($input, 'appearance')) {
        if (!($input->appearance instanceof stdClass)) {
            respond(['error' => '表示設定をオブジェクト形式で指定してください。'], 422);
        }
        $appearance = get_object_vars($input->appearance);
        foreach ($appearance as $key => $value) {
            $valid = match ($key) {
                'input_border_width', 'card_border_width', 'button_border_width' =>
                    is_int($value) && $value >= 0 && $value <= 6,
                'blur_enabled', 'shadow_enabled' => is_bool($value),
                'font' => is_string($value) && in_array($value, ['default', 'sans', 'serif', 'rounded', 'system'], true),
                default => false,
            };
            if (!$valid) {
                respond(['error' => '表示設定を確認してください。枠は0〜6px、フォントは選択肢から指定します。'], 422);
            }
        }
        $appearanceJson = json_encode((object) $appearance, JSON_THROW_ON_ERROR);
    }

    $statement = $pdo->prepare(
        "INSERT INTO user_colors (user_id, colors, appearance)
         VALUES (:user_id, :colors, COALESCE(CAST(:appearance AS jsonb), '{}'::jsonb))
         ON CONFLICT (user_id) DO UPDATE SET colors = EXCLUDED.colors,
             appearance = COALESCE(CAST(:appearance_update AS jsonb), user_colors.appearance),
             updated_at = CURRENT_TIMESTAMP
         RETURNING appearance"
    );
    $statement->execute([
        ':user_id' => $userId,
        ':colors' => json_encode((object) $colors, JSON_THROW_ON_ERROR),
        ':appearance' => $appearanceJson,
        ':appearance_update' => $appearanceJson,
    ]);
    respond(['data' => [
        'colors' => (object) $colors,
        'appearance' => json_decode($statement->fetchColumn(), false, 512, JSON_THROW_ON_ERROR),
    ]], 200);
} catch (JsonException) {
    respond(['error' => '正しい形式のJSONを送信してください。'], 400);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    respond(['error' => '表示設定を取得・保存できませんでした。'], 500);
}
