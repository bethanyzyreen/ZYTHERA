<?php

date_default_timezone_set('Asia/Manila');

ini_set('session.gc_maxlifetime', 43200);
ini_set('session.cookie_lifetime', 0);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── DATABASE CONFIG ───────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'system_db');

// ── CONNECT DATABASE ──────────────────────────────────────────
function getDBConnection() {

    static $pdo = null;

    if ($pdo === null) {

        try {

            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);

        } catch (PDOException $e) {

            die("DATABASE ERROR: " . $e->getMessage());
        }
    }

    return $pdo;
}

// ── LOAD INVENTORY ────────────────────────────────────────────
function loadInventory(): array {

    try {

        $db = getDBConnection();

        $stmt = $db->query("
            SELECT * FROM inventory
            ORDER BY id ASC
        ");

        return $stmt->fetchAll();

    } catch (PDOException $e) {

        die("loadInventory ERROR: " . $e->getMessage());
    }
}

// ── SAVE INVENTORY ────────────────────────────────────────────
function saveInventory(array $inventory): void {

    try {

        $db = getDBConnection();

        foreach ($inventory as $item) {

            $obj = is_array($item) ? (object)$item : $item;

            // CHECK IF PRODUCT EXISTS
            $check = $db->prepare("
                SELECT id FROM inventory
                WHERE id = ?
            ");

            $check->execute([
                (int)$obj->id
            ]);

            // ── UPDATE PRODUCT ───────────────────────────
            if ($check->fetch()) {

                $stmt = $db->prepare("
                    UPDATE inventory SET
                        name = ?,
                        size = ?,
                        color = ?,
                        price = ?,
                        description = ?,
                        stock = ?,
                        category = ?,
                        image = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $obj->name,
                    $obj->size,
                    $obj->color,
                    (float)$obj->price,
                    $obj->description,
                    (int)$obj->stock,
                    $obj->category,
                    $obj->image,
                    (int)$obj->id
                ]);

            } else {

                // ── INSERT PRODUCT ───────────────────────
                $stmt = $db->prepare("
                    INSERT INTO inventory
                    (
                        id,
                        name,
                        size,
                        color,
                        price,
                        description,
                        stock,
                        category,
                        image
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    (int)$obj->id,
                    $obj->name,
                    $obj->size,
                    $obj->color,
                    (float)$obj->price,
                    $obj->description,
                    (int)$obj->stock,
                    $obj->category,
                    $obj->image
                ]);
            }
        }

    } catch (PDOException $e) {

        die("saveInventory ERROR: " . $e->getMessage());
    }
}

// ── LOAD USERS ────────────────────────────────────────────────
function loadUsers(): array {

    try {

        $db = getDBConnection();

        $stmt = $db->query("
            SELECT * FROM users
            ORDER BY created_at DESC
        ");

        return $stmt->fetchAll();

    } catch (PDOException $e) {

        die("loadUsers ERROR: " . $e->getMessage());
    }
}

// ── LOAD CARTS ────────────────────────────────────────────────
function loadCarts(): array {

    try {

        $db = getDBConnection();

        $stmt = $db->query("
            SELECT * FROM carts
        ");

        return $stmt->fetchAll();

    } catch (PDOException $e) {

        die("loadCarts ERROR: " . $e->getMessage());
    }
}

// ── LOAD CART FOR ONE USER (returns array of cart items) ──────
function loadCartForUser(string $email): array {

    try {

        $db = getDBConnection();

        $stmt = $db->prepare("
            SELECT c.product_id AS id, c.qty,
                   i.name, i.price, i.image, i.stock
            FROM carts c
            JOIN inventory i ON i.id = c.product_id
            WHERE c.user_email = ?
        ");

        $stmt->execute([$email]);

        $rows = $stmt->fetchAll();

        $cart = [];

        foreach ($rows as $r) {
            $cart[] = [
                'id'    => (int)$r->id,
                'name'  => $r->name,
                'price' => (float)$r->price,
                'qty'   => (int)$r->qty,
                'image' => $r->image,
            ];
        }

        return $cart;

    } catch (PDOException $e) {

        return [];
    }
}

// ── SAVE CART ITEM FOR USER (upsert) ─────────────────────────
function saveCartItem(string $email, int $productId, int $qty): void {

    try {

        $db = getDBConnection();

        if ($qty <= 0) {

            $stmt = $db->prepare("
                DELETE FROM carts
                WHERE user_email = ? AND product_id = ?
            ");

            $stmt->execute([$email, $productId]);

        } else {

            $stmt = $db->prepare("
                INSERT INTO carts (user_email, product_id, qty)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = ?
            ");

            $stmt->execute([$email, $productId, $qty, $qty]);
        }

    } catch (PDOException $e) {

        // silently fail — session is source of truth
    }
}

// ── REMOVE CART ITEM FOR USER ─────────────────────────────────
function removeCartItem(string $email, int $productId): void {

    try {

        $db = getDBConnection();

        $stmt = $db->prepare("
            DELETE FROM carts
            WHERE user_email = ? AND product_id = ?
        ");

        $stmt->execute([$email, $productId]);

    } catch (PDOException $e) {
        // silently fail
    }
}

// ── CLEAR CART FOR USER ───────────────────────────────────────
function clearCartForUser(string $email): void {

    try {

        $db = getDBConnection();

        $stmt = $db->prepare("
            DELETE FROM carts
            WHERE user_email = ?
        ");

        $stmt->execute([$email]);

    } catch (PDOException $e) {
        // silently fail
    }
}

// ── SAVE ORDER TO DATABASE ────────────────────────────────────
function saveOrderToDB(string $email, array $order): bool {

    try {

        $db = getDBConnection();

        $info = $order['shipping_info'] ?? [];

        $stmt = $db->prepare("
            INSERT INTO orders
            (
                order_id, user_email, subtotal, shipping, total,
                date, status, full_name, phone, address,
                city, province, zip, pay_method, notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $order['order_id'],
            $email,
            (float)$order['subtotal'],
            (float)$order['shipping'],
            (float)$order['total'],
            $order['date'],
            $order['status'] ?? 'Pending',
            $info['full_name'] ?? '',
            $info['phone']     ?? '',
            $info['address']   ?? '',
            $info['city']      ?? '',
            $info['province']  ?? '',
            $info['zip']       ?? '',
            $order['pay_method'] ?? '',
            $info['notes']     ?? '',
        ]);

        $orderDbId = (int)$db->lastInsertId();

        $itemStmt = $db->prepare("
            INSERT INTO order_items
            (order_db_id, product_id, product_name, price, qty)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($order['items'] as $ci) {
            $itemStmt->execute([
                $orderDbId,
                (int)($ci['id']    ?? 0),
                $ci['name']        ?? '',
                (float)($ci['price'] ?? 0),
                (int)($ci['qty']   ?? 1),
            ]);
        }

        return true;

    } catch (PDOException $e) {

        return false;
    }
}

// ── UPDATE USER IN DATABASE ───────────────────────────────────
function saveUserToDB(string $email, string $name, ?string $hashedPassword, ?string $profilePic): void {

    try {

        $db = getDBConnection();

        if ($hashedPassword && $profilePic !== null) {

            $stmt = $db->prepare("
                UPDATE users SET name = ?, password = ?, profile_pic = ?
                WHERE email = ?
            ");

            $stmt->execute([$name, $hashedPassword, $profilePic, $email]);

        } elseif ($hashedPassword) {

            $stmt = $db->prepare("
                UPDATE users SET name = ?, password = ?
                WHERE email = ?
            ");

            $stmt->execute([$name, $hashedPassword, $email]);

        } elseif ($profilePic !== null) {

            $stmt = $db->prepare("
                UPDATE users SET name = ?, profile_pic = ?
                WHERE email = ?
            ");

            $stmt->execute([$name, $profilePic, $email]);

        } else {

            $stmt = $db->prepare("
                UPDATE users SET name = ?
                WHERE email = ?
            ");

            $stmt->execute([$name, $email]);
        }

    } catch (PDOException $e) {
        // silently fail
    }
}

// ── LOAD ORDERS ───────────────────────────────────────────────
function loadOrders(): array {

    try {

        $db = getDBConnection();

        $stmt = $db->query("
            SELECT * FROM orders
            ORDER BY id DESC
        ");

        $orders = $stmt->fetchAll();

        foreach ($orders as &$order) {

            $itemStmt = $db->prepare("
                SELECT * FROM order_items
                WHERE order_db_id = ?
            ");

            $itemStmt->execute([$order->id]);

            $order->items = $itemStmt->fetchAll();
        }

        return $orders;

    } catch (PDOException $e) {

        die("loadOrders ERROR: " . $e->getMessage());
    }
}

// ── SESSION DEFAULTS ──────────────────────────────────────────
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (!isset($_SESSION['orders'])) {
    $_SESSION['orders'] = [];
}

if (!isset($_SESSION['users'])) {
    $_SESSION['users'] = [];
}

// ── AUTO LOAD INVENTORY TO SESSION ───────────────────────────
$_SESSION['inventory'] = [];

try {

    $products = loadInventory();

    foreach ($products as $item) {

        $_SESSION['inventory'][$item->id] = $item;
    }

} catch (Exception $e) {
}

// ── LOAD CART FROM DB INTO SESSION FOR LOGGED-IN USER ────────
if (!empty($_SESSION['logged_in_user'])) {
    $__cartEmail = $_SESSION['logged_in_user'];
    $_SESSION['cart'][$__cartEmail] = loadCartForUser($__cartEmail);
}

// ── COOKIE AUTO LOGIN ─────────────────────────────────────────
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');

// AJAX endpoints that must never receive an HTML redirect
$ajaxScripts = ['addcart.php'];

if (
    !in_array($currentScript, ['logsign.php', 'logout.php']) &&
    !empty($_SESSION['logged_in_user'])
) {

    if (
        empty($_COOKIE['zafirah_user']) ||
        $_COOKIE['zafirah_user'] !== $_SESSION['logged_in_user']
    ) {

        unset($_SESSION['logged_in_user']);
        unset($_SESSION['role']);

        if (in_array($currentScript, $ajaxScripts)) {
            // Return JSON redirect instruction instead of HTTP redirect
            header('Content-Type: application/json');
            echo json_encode(['redirect' => 'logsign.php']);
            exit;
        }

        header('Location: logsign.php?expired=1');
        exit;
    }

    $exp = time() + 43200;

    setcookie(
        'zafirah_user',
        $_SESSION['logged_in_user'],
        $exp,
        '/'
    );
}

// ── HELPERS ───────────────────────────────────────────────────
define('TAX_RATE', 0.12);

function sanitize(string $v): string {

    return trim(htmlspecialchars($v));
}

function isBlank(string $v): bool {

    return empty(trim($v));
}

function formatPrice(float $p): string {

    return '₱' . number_format($p, 2);
}

function getStockLabel(int $s): string {

    if ($s <= 0) {
        return 'Out of Stock';
    }

    if ($s <= 5) {
        return 'Low Stock';
    }

    return 'In Stock';
}

function getStockBadge(int $s): string {

    switch (getStockLabel($s)) {

        case 'Out of Stock':
            return 'bg-danger';

        case 'Low Stock':
            return 'bg-warning text-dark';

        default:
            return 'bg-success';
    }
}

function nowFormatted(): string {

    return date('M d, Y h:i A');
}

// ── PRODUCT CLASS ─────────────────────────────────────────────
class Product {

    public int $id;
    public string $name;
    public string $size;
    public string $color;
    public float $price;
    public string $description;
    public int $stock;
    public string $category;
    public string $image;

    public function __construct(
        int $id,
        string $name,
        string $size,
        string $color,
        float $price,
        string $description,
        int $stock,
        string $category,
        string $image
    ) {

        $this->id = $id;
        $this->name = $name;
        $this->size = $size;
        $this->color = $color;
        $this->price = $price;
        $this->description = $description;
        $this->stock = $stock;
        $this->category = $category;
        $this->image = $image;
    }

    public function getFormattedPrice(): string {

        return formatPrice($this->price);
    }

    public function isAvailable(): bool {

        return $this->stock > 0;
    }
}