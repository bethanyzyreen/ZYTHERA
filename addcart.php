<?php
// ── addcart.php ───────────────────────────────────────────────
// AJAX endpoint: adds/updates a product in the cart.
// Called by website.php's addToCart() JS function via fetch POST.
// Returns JSON: { success, cart, total_items } or { redirect } or { message }
// ─────────────────────────────────────────────────────────────

// Suppress PHP error output so errors don't corrupt JSON
ini_set('display_errors', 0);
error_reporting(0);

// Buffer everything so config.php cannot accidentally emit HTML
ob_start();

require 'config.php';

// Discard anything config.php may have echoed (errors, notices, etc.)
ob_end_clean();

// Now safe to set JSON header
header('Content-Type: application/json');

// ── Must be logged in ─────────────────────────────────────────
if (empty($_SESSION['logged_in_user'])) {
    echo json_encode(['redirect' => 'logsign.php']);
    exit;
}

$userEmail = $_SESSION['logged_in_user'];
$userRole  = $_SESSION['role'] ?? 'user';

// ── Admins cannot add to cart ─────────────────────────────────
if ($userRole === 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admins cannot add to cart.']);
    exit;
}

// ── Read & validate input ─────────────────────────────────────
$productId = (int)($_POST['id']  ?? 0);
$addQty    = (int)($_POST['qty'] ?? 1);

if ($productId <= 0 || $addQty <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity.']);
    exit;
}

// ── Load product from DB ──────────────────────────────────────
try {
    $db   = getDBConnection();
    $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ? LIMIT 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

if ((int)$product->stock <= 0) {
    echo json_encode(['success' => false, 'message' => 'Sorry, this item is out of stock.']);
    exit;
}

// ── Check current qty already in cart ────────────────────────
$currentCart = loadCartForUser($userEmail);
$existingQty = 0;
foreach ($currentCart as $ci) {
    if ((int)$ci['id'] === $productId) {
        $existingQty = (int)$ci['qty'];
        break;
    }
}

$newQty = $existingQty + $addQty;

// ── Cap at available stock ────────────────────────────────────
$maxStock = (int)$product->stock;
if ($newQty > $maxStock) {
    $newQty = $maxStock;
    if ($existingQty >= $maxStock) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have the maximum available stock (' . $maxStock . ') in your cart.'
        ]);
        exit;
    }
}

// ── Upsert into carts table ───────────────────────────────────
saveCartItem($userEmail, $productId, $newQty);

// ── Reload cart from DB for fresh response ────────────────────
$updatedCart = loadCartForUser($userEmail);

// ── Sync session ──────────────────────────────────────────────
$_SESSION['cart'][$userEmail] = $updatedCart;

// ── Compute total items in cart ───────────────────────────────
$totalItems = 0;
foreach ($updatedCart as $ci) {
    $totalItems += (int)$ci['qty'];
}

echo json_encode([
    'success'     => true,
    'cart'        => array_values($updatedCart),
    'total_items' => $totalItems,
]);