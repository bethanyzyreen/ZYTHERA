<?php
// ── addcart.php ───────────────────────────────────────────────
// AJAX endpoint called by website.php when a user clicks "Add to Cart".
// Always responds with JSON.
// ─────────────────────────────────────────────────────────────
require 'config.php';
header('Content-Type: application/json');

// Must be logged in
if (empty($_SESSION['logged_in_user'])) {
    echo json_encode(['success' => false, 'redirect' => 'logsign.php']);
    exit;
}

$userEmail = $_SESSION['logged_in_user'];

// Only POST accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$productId = (int)($_POST['id']    ?? 0);
$name      = trim($_POST['name']   ?? '');
$price     = (float)($_POST['price'] ?? 0);
$qty       = max(1, (int)($_POST['qty'] ?? 1));
$image     = trim($_POST['image']  ?? '');

if ($productId <= 0 || $name === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid product data.']);
    exit;
}

// Check stock from session inventory
$invItem = $_SESSION['inventory'][$productId] ?? null;
if (!$invItem) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

$availableStock = (int)$invItem->stock;
if ($availableStock <= 0) {
    echo json_encode(['success' => false, 'message' => 'This item is out of stock.']);
    exit;
}

// Load current cart from session (already synced from DB at login / page load)
if (!isset($_SESSION['cart'][$userEmail])) {
    $_SESSION['cart'][$userEmail] = loadCart($userEmail);
}
$cart = &$_SESSION['cart'][$userEmail];

// Find if product already in cart
$found = false;
foreach ($cart as &$item) {
    if ((int)$item['id'] === $productId) {
        $newQty = (int)$item['qty'] + $qty;
        // Cap at available stock
        $item['qty'] = min($newQty, $availableStock);
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    $cart[] = [
        'id'    => $productId,
        'name'  => $name,
        'price' => $price,
        'qty'   => min($qty, $availableStock),
        'color' => '',
        'image' => $image,
    ];
}

// Persist to DB
saveCart($userEmail, $cart);

// Count total items for badge
$totalItems = 0;
foreach ($cart as $ci) {
    $totalItems += (int)($ci['qty'] ?? 1);
}

echo json_encode([
    'success'     => true,
    'cart'        => array_values($cart),
    'total_items' => $totalItems,
]);