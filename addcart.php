<?php
require 'config.php'; // starts session and loads users/inventory

header('Content-Type: application/json');

// ── 1. Must be logged in ──────────────────────────────────────────────────────
$userEmail = $_SESSION['logged_in_user'] ?? null;
if (!$userEmail) {
    echo json_encode(['success' => false, 'redirect' => 'logsign.php', 'debug' => 'not logged in']);
    exit;
}

// ── 2. Validate input ─────────────────────────────────────────────────────────
$id    = isset($_POST['id'])    ? (int)$_POST['id']         : 0;
$name  = isset($_POST['name'])  ? trim($_POST['name'])       : '';
$price = isset($_POST['price']) ? (float)$_POST['price']    : 0.0;
$qty   = isset($_POST['qty'])   ? max(1, (int)$_POST['qty']) : 1;
$image = isset($_POST['image']) ? trim($_POST['image'])       : '';

if (!$id || !$name || $price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product data.', 'debug' => compact('id','name','price','qty')]);
    exit;
}

// ── 3. Stock check ────────────────────────────────────────────────────────────
$inventory = $_SESSION['inventory'] ?? [];
$stockItem = null;
foreach ($inventory as $inv) {
    $inv = (object)$inv;
    if ((int)$inv->id === $id) { $stockItem = $inv; break; }
}

$maxStock = $stockItem ? (int)$stockItem->stock : 0;

if ($maxStock === 0) {
    echo json_encode(['success' => false, 'message' => 'Sorry, this item is out of stock.']);
    exit;
}

// Auto-get image from inventory if not passed
if (!$image && $stockItem && !empty($stockItem->image)) {
    $image = $stockItem->image;
}

// ── 4. Init cart for this user ────────────────────────────────────────────────
if (!isset($_SESSION['cart'][$userEmail]) || !is_array($_SESSION['cart'][$userEmail])) {
    $_SESSION['cart'][$userEmail] = [];
}

$cart = &$_SESSION['cart'][$userEmail];

// ── 5. Add or increment quantity — capped at stock ───────────────────────────
$found = false;
foreach ($cart as &$item) {
    if (is_array($item) && (int)$item['id'] === $id) {
        $currentQty  = (int)$item['qty'];
        $newQty      = $currentQty + $qty;
        if ($newQty > $maxStock) {
            if ($currentQty >= $maxStock) {
                echo json_encode(['success' => false, 'message' => 'You already have the maximum stock (' . $maxStock . ') in your cart.']);
                exit;
            }
            $newQty = $maxStock; // clamp to max
        }
        $item['qty'] = $newQty;
        if (empty($item['image']) && $image) $item['image'] = $image;
        $found = true;
        break;
    }
}
unset($item);

if (!$found) {
    // New item — clamp requested qty to stock
    $qty    = min($qty, $maxStock);
    $cart[] = [
        'id'    => $id,
        'name'  => htmlspecialchars($name),
        'price' => $price,
        'qty'   => $qty,
        'image' => $image,
    ];
}

// ── 6. Total item count (for badge in website.php) ───────────────────────────
$totalItems = 0;
foreach ($cart as $cartItem) {
    $totalItems += is_array($cartItem) ? (int)($cartItem['qty'] ?? 1) : 1;
}

// Persist cart to file
$allCarts = loadCarts();
$allCarts[$userEmail] = array_values($cart);
saveCarts($allCarts);

echo json_encode([
    'success'     => true,
    'total_items' => $totalItems,
    'cart'        => array_values($cart),
    'message'     => htmlspecialchars($name) . ' added to cart!',
]);
exit;