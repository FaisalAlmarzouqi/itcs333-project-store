<?php
declare(strict_types=1);

$method = http_method();
$rest = trim((string)($GLOBALS['_route']['rest'] ?? ''), '/');
$segments = $rest === '' ? [] : explode('/', $rest);
$itemId = isset($segments[0]) ? param_id($segments[0]) : null;

// Ensure session is active and retrieve authenticated user ID
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$userId = null;
foreach (['user_id', 'uid', 'auth_user_id'] as $key) {
    if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
        $userId = (int)$_SESSION[$key];
        break;
    }
}
if ($userId === null && isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id'])) {
    if (is_numeric($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] > 0) {
        $userId = (int)$_SESSION['user']['id'];
    }
}

if ($userId === null) {
    send_error(401, 'AUTH_REQUIRED');
}

$pdo = db();

$fetchCartLine = static function (int $cartId, int $uid) use ($pdo): ?array {
    $stmt = $pdo->prepare(
        'SELECT ci.id, ci.product_id, ci.qty, p.price AS unit_price '
        . 'FROM cart_items ci '
        . 'JOIN products p ON p.id = ci.product_id '
        . 'WHERE ci.id = :id AND ci.user_id = :user_id'
    );
    $stmt->execute([':id' => $cartId, ':user_id' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $qty = (int)$row['qty'];
    $unitPrice = (float)$row['unit_price'];
    $subtotal = $qty * $unitPrice;

    return [
        'id' => (int)$row['id'],
        'product_id' => (int)$row['product_id'],
        'qty' => $qty,
        'unit_price' => $unitPrice,
        'subtotal' => $subtotal,
    ];
};

// GET /api/cart
if ($method === 'GET' && count($segments) === 0) {
    $stmt = $pdo->prepare(
        'SELECT ci.id, ci.product_id, ci.qty, p.price AS unit_price '
        . 'FROM cart_items ci '
        . 'JOIN products p ON p.id = ci.product_id '
        . 'WHERE ci.user_id = :user_id '
        . 'ORDER BY ci.id ASC'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $qty = (int)$row['qty'];
        $unitPrice = (float)$row['unit_price'];
        $subtotal = $qty * $unitPrice;
        $total += $subtotal;

        $items[] = [
            'id' => (int)$row['id'],
            'product_id' => (int)$row['product_id'],
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ];
    }

    send_json(200, [
        'data' => $items,
        'total' => $total,
    ]);
}

// POST /api/cart
if ($method === 'POST' && count($segments) === 0) {
    $body = json_body();
    $productId = isset($body['product_id']) && filter_var($body['product_id'], FILTER_VALIDATE_INT)
        ? (int)$body['product_id']
        : 0;
    $qty = isset($body['qty']) && filter_var($body['qty'], FILTER_VALIDATE_INT)
        ? (int)$body['qty']
        : 0;

    if ($qty < 1) {
        send_error(422, 'VALIDATION', ['qty' => 'must be >= 1']);
    }

    $prodStmt = $pdo->prepare('SELECT id, price FROM products WHERE id = :id');
    $prodStmt->execute([':id' => $productId]);
    $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        send_error(404, 'PRODUCT_NOT_FOUND');
    }

    $existingStmt = $pdo->prepare(
        'SELECT id, qty FROM cart_items WHERE user_id = :user_id AND product_id = :product_id'
    );
    $existingStmt->execute([
        ':user_id' => $userId,
        ':product_id' => $productId,
    ]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $newQty = (int)$existing['qty'] + $qty;
        $updStmt = $pdo->prepare('UPDATE cart_items SET qty = :qty WHERE id = :id AND user_id = :user_id');
        $updStmt->execute([
            ':qty' => $newQty,
            ':id' => (int)$existing['id'],
            ':user_id' => $userId,
        ]);
        $line = $fetchCartLine((int)$existing['id'], $userId);
        send_json(201, $line);
    }

    $newId = db_insert_and_get_id(
        $pdo,
        'INSERT INTO cart_items (user_id, product_id, qty) VALUES (:user_id, :product_id, :qty)',
        [
            ':user_id' => $userId,
            ':product_id' => $productId,
            ':qty' => $qty,
        ]
    );

    $line = $fetchCartLine((int)$newId, $userId);
    send_json(201, $line);
}

// PATCH /api/cart/{id}
if ($method === 'PATCH' && $itemId !== null && count($segments) === 1) {
    $body = json_body();
    $qty = isset($body['qty']) && filter_var($body['qty'], FILTER_VALIDATE_INT)
        ? (int)$body['qty']
        : 0;

    if ($qty < 1) {
        send_error(422, 'VALIDATION', ['qty' => 'must be >= 1']);
    }

    $checkStmt = $pdo->prepare('SELECT id FROM cart_items WHERE id = :id AND user_id = :user_id');
    $checkStmt->execute([':id' => $itemId, ':user_id' => $userId]);
    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        send_error(404, 'NOT_FOUND');
    }

    $updStmt = $pdo->prepare('UPDATE cart_items SET qty = :qty WHERE id = :id AND user_id = :user_id');
    $updStmt->execute([
        ':qty' => $qty,
        ':id' => $itemId,
        ':user_id' => $userId,
    ]);

    $line = $fetchCartLine($itemId, $userId);
    send_json(200, $line);
}

// DELETE /api/cart/{id}
if ($method === 'DELETE' && $itemId !== null && count($segments) === 1) {
    $checkStmt = $pdo->prepare('SELECT id FROM cart_items WHERE id = :id AND user_id = :user_id');
    $checkStmt->execute([':id' => $itemId, ':user_id' => $userId]);
    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        send_error(404, 'NOT_FOUND');
    }

    $delStmt = $pdo->prepare('DELETE FROM cart_items WHERE id = :id AND user_id = :user_id');
    $delStmt->execute([':id' => $itemId, ':user_id' => $userId]);

    send_json(200, ['deleted' => true]);
}

send_error(404, 'NOT_FOUND');