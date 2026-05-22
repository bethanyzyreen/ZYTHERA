<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'config.php';

// ── ADD / EDIT product ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id'] ?? '';

    $rawPrice   = $_POST['price'] ?? '0';
    $cleanPrice = str_replace([',', '₱', ' '], '', $rawPrice);
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $cleanPrice)) {
        $cleanPrice = preg_replace('/[^\d.]/', '', $cleanPrice);
    }

    $category = $_POST['category'] ?? 'Sofa';
    if      (strcasecmp($category, 'sofa')  === 0) $category = 'Sofa';
    elseif  (strcasecmp($category, 'chair') === 0) $category = 'Chair';
    elseif  (strcasecmp($category, 'set')   === 0) $category = 'Set';

    $description = $_POST['description'] ?? '';
    if (str_word_count($description) < 3) {
        $description .= ' (short description — ' . str_word_count($description) . ' word/s)';
    }

    $imagePath = $_POST['image'] ?? '';
    if (strpos($imagePath, 'pci/') === false && strpos($imagePath, 'http') === false) {
        $imagePath = 'pci/' . ltrim($imagePath, '/');
    }

    $colorArray  = array_map('trim', str_getcsv($_POST['color'] ?? ''));
    $colorStored = implode(', ', $colorArray);

    $productObj = (object)[
        'id'          => $id !== '' ? (int)$id : 0,
        'name'        => sanitize($_POST['name'] ?? ''),
        'size'        => sanitize($_POST['size'] ?? ''),
        'color'       => $colorStored,
        'price'       => (float)$cleanPrice,
        'description' => sanitize($description),
        'stock'       => (int)($_POST['stock'] ?? 0),
        'category'    => $category,
        'image'       => $imagePath,
        'saved_at'    => nowFormatted(),
    ];

    saveProduct($productObj); // INSERT or UPDATE in DB, then resyncs session

    header("Location: admin.php");
    exit;
}

// ── RESTOCK Product ───────────────────────────────────────────
if (isset($_GET['restock_id']) && isset($_GET['amount'])) {
    $id     = (int)$_GET['restock_id'];
    $amount = max(0, (int)$_GET['amount']);

    $inv = $_SESSION['inventory'][$id] ?? null;
    if ($inv) {
        $productObj = Product::fromStdClass($inv);
        $productObj->reststock($amount);
        $std = $productObj->toStdClass();
        $std->saved_at = nowFormatted();
        saveProduct($std);
        header("Location: admin.php?success=restocked");
        exit;
    }
}

// ── UPDATE Order Status ───────────────────────────────────────
if (isset($_GET['update_status'])) {
    header('Content-Type: application/json');
    $orderId   = trim($_GET['order_id'] ?? '');
    $newStatus = trim($_GET['status'] ?? '');

    $validStatuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status.']);
        exit;
    }

    $updated = updateOrderStatus($orderId, $newStatus);
    echo json_encode($updated
        ? ['success' => true]
        : ['success' => false, 'message' => 'Order not found.']
    );
    exit;
}

// ── DELETE User ───────────────────────────────────────────────
if (isset($_GET['delete_user'])) {
    header('Content-Type: application/json');
    $email        = trim($_GET['delete_user']);
    $currentAdmin = $_SESSION['logged_in_user'] ?? '';

    if ($email === $currentAdmin) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete yourself.']);
        exit;
    }

    if (!isset($_SESSION['users'][$email])) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // CASCADE in DB removes cart_items and orders for this user automatically
    deleteUser($email);

    // Clean up session cart for this user
    unset($_SESSION['cart'][$email]);
    unset($_SESSION['profile_pic'][$email]);

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE Product ────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    deleteProduct($id);
    header("Location: admin.php");
    exit;
}