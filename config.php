<?php

date_default_timezone_set('Asia/Manila');
ini_set('session.gc_maxlifetime', 180);
ini_set('session.cookie_lifetime', 0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Database connection ───────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'system_db');
define('DB_USER', 'root');       // change to your DB username
define('DB_PASS', '');           // change to your DB password
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ── Users ─────────────────────────────────────────────────────
function loadUsers(): array {
    $rows = getDB()->query("SELECT * FROM users")->fetchAll();
    $users = [];
    foreach ($rows as $row) {
        $users[$row['email']] = [
            'name'        => $row['name'],
            'password'    => $row['password'],
            'role'        => $row['role'],
            'profile_pic' => $row['profile_pic'],
        ];
    }
    return $users;
}

function saveUser(string $email, array $data): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO users (email, name, password, role, profile_pic)
        VALUES (:email, :name, :password, :role, :profile_pic)
        ON DUPLICATE KEY UPDATE
            name        = VALUES(name),
            password    = VALUES(password),
            role        = VALUES(role),
            profile_pic = VALUES(profile_pic)
    ");
    $stmt->execute([
        ':email'       => $email,
        ':name'        => $data['name'],
        ':password'    => $data['password'],
        ':role'        => $data['role'],
        ':profile_pic' => $data['profile_pic'] ?? null,
    ]);
    // Sync session
    $_SESSION['users'][$email] = $data;
}

function deleteUser(string $email): void {
    getDB()->prepare("DELETE FROM users WHERE email = ?")->execute([$email]);
    unset($_SESSION['users'][$email]);
    unset($_SESSION['orders'][$email]);
}

// ── Inventory ─────────────────────────────────────────────────
function loadInventory(): array {
    $rows = getDB()->query("SELECT * FROM products ORDER BY id ASC")->fetchAll();
    $list = [];
    foreach ($rows as $row) {
        $list[] = (object)[
            'id'          => (int)$row['id'],
            'name'        => $row['name'],
            'size'        => $row['size'],
            'color'       => $row['color'],
            'price'       => (float)$row['price'],
            'description' => $row['description'],
            'stock'       => (int)$row['stock'],
            'category'    => $row['category'],
            'image'       => $row['image'],
            'saved_at'    => $row['saved_at'],
        ];
    }
    return $list;
}

function syncInventorySession(): void {
    $_SESSION['inventory'] = [];
    foreach (loadInventory() as $item) {
        $_SESSION['inventory'][(int)$item->id] = $item;
    }
}

function saveProduct(object $product): void {
    $db = getDB();
    if ((int)$product->id === 0) {
        // INSERT — let AUTO_INCREMENT assign the id
        $stmt = $db->prepare("
            INSERT INTO products (name, size, color, price, description, stock, category, image, saved_at)
            VALUES (:name, :size, :color, :price, :description, :stock, :category, :image, :saved_at)
        ");
        $stmt->execute([
            ':name'        => $product->name,
            ':size'        => $product->size,
            ':color'       => $product->color,
            ':price'       => $product->price,
            ':description' => $product->description,
            ':stock'       => $product->stock,
            ':category'    => $product->category,
            ':image'       => $product->image,
            ':saved_at'    => $product->saved_at ?? nowFormatted(),
        ]);
        $product->id = (int)$db->lastInsertId();
    } else {
        // UPDATE
        $stmt = $db->prepare("
            UPDATE products
            SET name=:name, size=:size, color=:color, price=:price,
                description=:description, stock=:stock, category=:category,
                image=:image, saved_at=:saved_at
            WHERE id=:id
        ");
        $stmt->execute([
            ':name'        => $product->name,
            ':size'        => $product->size,
            ':color'       => $product->color,
            ':price'       => $product->price,
            ':description' => $product->description,
            ':stock'       => $product->stock,
            ':category'    => $product->category,
            ':image'       => $product->image,
            ':saved_at'    => $product->saved_at ?? nowFormatted(),
            ':id'          => $product->id,
        ]);
    }
    syncInventorySession();
}

function deleteProduct(int $id): void {
    getDB()->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    unset($_SESSION['inventory'][$id]);
}

// ── Carts ─────────────────────────────────────────────────────
function loadCart(string $email): array {
    $stmt = getDB()->prepare("SELECT * FROM cart_items WHERE user_email = ?");
    $stmt->execute([$email]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id'    => (int)$row['product_id'],
            'name'  => $row['name'],
            'price' => (float)$row['price'],
            'qty'   => (int)$row['qty'],
            'color' => $row['color'],
            'image' => $row['image'],
        ];
    }
    return $items;
}

function saveCart(string $email, array $items): void {
    $db = getDB();
    $db->prepare("DELETE FROM cart_items WHERE user_email = ?")->execute([$email]);
    if (!empty($items)) {
        $stmt = $db->prepare("
            INSERT INTO cart_items (user_email, product_id, name, price, qty, color, image)
            VALUES (:email, :product_id, :name, :price, :qty, :color, :image)
        ");
        foreach ($items as $item) {
            $stmt->execute([
                ':email'      => $email,
                ':product_id' => (int)$item['id'],
                ':name'       => $item['name'],
                ':price'      => (float)$item['price'],
                ':qty'        => (int)$item['qty'],
                ':color'      => $item['color'] ?? '',
                ':image'      => $item['image'] ?? '',
            ]);
        }
    }
    $_SESSION['cart'][$email] = $items;
}

// ── Orders ────────────────────────────────────────────────────
function loadOrders(): array {
    $stmt = getDB()->prepare("
        SELECT o.*, GROUP_CONCAT(
            JSON_OBJECT(
                'id', oi.product_id,
                'name', oi.name,
                'price', oi.price,
                'qty', oi.qty,
                'image', oi.image
            ) ORDER BY oi.id SEPARATOR '|||'
        ) AS items_json
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute();
    $allOrders = [];
    foreach ($stmt->fetchAll() as $row) {
        $items = [];
        if ($row['items_json']) {
            foreach (explode('|||', $row['items_json']) as $chunk) {
                $item = json_decode($chunk, true);
                if ($item) $items[] = $item;
            }
        }
        $shippingInfo = json_decode($row['shipping_info'] ?? '{}', true) ?: [];
        $allOrders[$row['user_email']][] = [
            'order_id'         => $row['order_id'],
            'items'            => $items,
            'subtotal'         => (float)$row['subtotal'],
            'shipping'         => (float)$row['shipping'],
            'total'            => (float)$row['total'],
            'date'             => $row['created_at'],
            'status'           => $row['status'],
            'shipping_address' => $row['shipping_address'],
            'pay_method'       => $row['pay_method'],
            'shipping_info'    => $shippingInfo,
        ];
    }
    return $allOrders;
}

function loadUserOrders(string $email): array {
    $stmt = getDB()->prepare("
        SELECT o.*, GROUP_CONCAT(
            JSON_OBJECT(
                'id', oi.product_id,
                'name', oi.name,
                'price', oi.price,
                'qty', oi.qty,
                'image', oi.image
            ) ORDER BY oi.id SEPARATOR '|||'
        ) AS items_json
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_email = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$email]);
    $orders = [];
    foreach ($stmt->fetchAll() as $row) {
        $items = [];
        if ($row['items_json']) {
            foreach (explode('|||', $row['items_json']) as $chunk) {
                $item = json_decode($chunk, true);
                if ($item) $items[] = $item;
            }
        }
        $shippingInfo = json_decode($row['shipping_info'] ?? '{}', true) ?: [];
        $orders[] = [
            'order_id'         => $row['order_id'],
            'items'            => $items,
            'subtotal'         => (float)$row['subtotal'],
            'shipping'         => (float)$row['shipping'],
            'total'            => (float)$row['total'],
            'date'             => $row['created_at'],
            'status'           => $row['status'],
            'shipping_address' => $row['shipping_address'],
            'pay_method'       => $row['pay_method'],
            'shipping_info'    => $shippingInfo,
        ];
    }
    return $orders;
}

function saveOrder(string $email, array $order): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO orders
            (order_id, user_email, subtotal, shipping, total, status, shipping_address, pay_method, shipping_info, created_at)
        VALUES
            (:order_id, :email, :subtotal, :shipping, :total, :status, :shipping_address, :pay_method, :shipping_info, :created_at)
    ");
    $stmt->execute([
        ':order_id'        => $order['order_id'],
        ':email'           => $email,
        ':subtotal'        => $order['subtotal'],
        ':shipping'        => $order['shipping'],
        ':total'           => $order['total'],
        ':status'          => $order['status'],
        ':shipping_address'=> $order['shipping_address'],
        ':pay_method'      => $order['pay_method'],
        ':shipping_info'   => json_encode($order['shipping_info']),
        ':created_at'      => $order['date'],
    ]);
    $dbOrderId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare("
        INSERT INTO order_items (order_id, product_id, name, price, qty, image)
        VALUES (:order_id, :product_id, :name, :price, :qty, :image)
    ");
    foreach ($order['items'] as $item) {
        $itemStmt->execute([
            ':order_id'   => $dbOrderId,
            ':product_id' => (int)($item['id'] ?? 0),
            ':name'       => $item['name'] ?? '',
            ':price'      => (float)($item['price'] ?? 0),
            ':qty'        => (int)($item['qty'] ?? 1),
            ':image'      => $item['image'] ?? '',
        ]);
    }
    // Keep session in sync
    if (!isset($_SESSION['orders'][$email])) $_SESSION['orders'][$email] = [];
    $_SESSION['orders'][$email][] = $order;
}

function updateOrderStatus(string $orderId, string $newStatus): bool {
    $stmt = getDB()->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
    $stmt->execute([$newStatus, $orderId]);
    return $stmt->rowCount() > 0;
}

function deleteUserOrders(string $email): void {
    // order_items are deleted via ON DELETE CASCADE on the orders table
    getDB()->prepare("DELETE FROM orders WHERE user_email = ?")->execute([$email]);
    unset($_SESSION['orders'][$email]);
}

function syncOrdersSession(): void {
    $_SESSION['orders'] = loadOrders();
}

// ── Bootstrap session on every request ───────────────────────
if (!isset($_SESSION['cart']))   $_SESSION['cart']   = [];
if (!isset($_SESSION['orders'])) $_SESSION['orders'] = [];

// Load users into session
$_zUsers = loadUsers();
$_SESSION['users'] = $_zUsers;
unset($_zUsers);

// Load inventory into session (keyed by id)
syncInventorySession();

// Load all orders into session (keyed by user_email)
syncOrdersSession();

// ── Cookie-expiry auto-logout ─────────────────────────────────
$_zCurrentScript = basename($_SERVER['PHP_SELF'] ?? '');
if (
    !in_array($_zCurrentScript, ['logsign.php', 'logout.php'], true) &&
    !empty($_SESSION['logged_in_user'])
) {
    if (empty($_COOKIE['zafirah_user'])) {
        $keysToRemove = ['logged_in_user', 'role', 'login_time', 'session_start'];
        foreach ($keysToRemove as $_k) unset($_SESSION[$_k]);
        header('Location: logsign.php?expired=1');
        exit;
    }
    if ($_COOKIE['zafirah_user'] !== $_SESSION['logged_in_user']) {
        $keysToRemove = ['logged_in_user', 'role', 'login_time', 'session_start'];
        foreach ($keysToRemove as $_k) unset($_SESSION[$_k]);
        header('Location: logsign.php?expired=1');
        exit;
    }
    // Renew cookie on every page load while active
    $exp = time() + 60;
    $email     = $_SESSION['logged_in_user'];
    $role      = $_SESSION['role'] ?? ($_COOKIE['zafirah_role'] ?? '');
    $name      = $_COOKIE['zafirah_name'] ?? '';
    $loginTime = $_COOKIE['zafirah_login'] ?? date('h:i A');
    setcookie('zafirah_user',  $email,     $exp, '/', '', false, true);
    setcookie('zafirah_role',  $role,      $exp, '/', '', false, true);
    setcookie('zafirah_name',  $name,      $exp, '/', '', false, true);
    setcookie('zafirah_login', $loginTime, $exp, '/', '', false, true);
}
unset($_zCurrentScript);

define('TAX_RATE', 0.12);

// ── Helpers ───────────────────────────────────────────────────
function sanitize(string $v): string { return trim(htmlspecialchars($v)); }
function isBlank(string $v): bool    { return empty(trim($v)); }
function formatPrice(float $p): string { return '₱' . number_format($p, 2); }
function nowFormatted(): string { return date('M d, Y h:i A'); }

function getStockLabel(int $s): string {
    if ($s === 0) return 'Out of Stock';
    if ($s <= 5)  return 'Low Stock';
    return 'In Stock';
}

function getStockBadge(int $s): string {
    switch (getStockLabel($s)) {
        case 'Out of Stock': return 'bg-danger';
        case 'Low Stock':    return 'bg-warning text-dark';
        default:             return 'bg-success';
    }
}

// ── Product class (unchanged) ─────────────────────────────────
class Product {
    private int    $id;
    public string  $name;
    public string  $size;
    public string  $color;
    public string  $description;
    public string  $category;
    private string $image;
    protected float $price;
    private int    $stock;

    public function __construct(
        int    $id, string $name, string $size, string $color,
        float  $price, string $description, int $stock,
        string $category, string $image
    ) {
        $this->id = $id; $this->name = $name; $this->size = $size;
        $this->color = $color; $this->price = $price;
        $this->description = $description; $this->stock = $stock;
        $this->category = $category; $this->image = $image;
    }

    public function __get(string $prop): mixed {
        if ($prop === 'price') return $this->price;
        if ($prop === 'stock') return $this->stock;
        if ($prop === 'id')    return $this->id;
        return null;
    }

    public function __set(string $prop, mixed $value): void {
        if ($prop === 'price') {
            $this->price = max(0, (float)str_replace([',','₱',' '], '', (string)$value));
        }
        if ($prop === 'stock') $this->stock = max(0, (int)$value);
    }

    public function __toString(): string {
        return "[Product #{$this->id}] {$this->name} — ₱" . number_format($this->price, 2)
             . " | Stock: {$this->stock} | Category: {$this->category}";
    }

    public function reststock(int $amount): void { $this->stock += $amount; }
    public function getFormattedPrice(): string  { return formatPrice($this->price); }
    public function isAvailable(): bool          { return $this->stock > 0; }

    public function toStdClass(): object {
        return (object)[
            'id' => $this->id, 'name' => $this->name, 'size' => $this->size,
            'color' => $this->color, 'price' => $this->price,
            'description' => $this->description, 'stock' => $this->stock,
            'category' => $this->category, 'image' => $this->image,
        ];
    }

    public static function fromStdClass(object $obj): self {
        return new self(
            (int)($obj->id ?? 0), (string)($obj->name ?? ''),
            (string)($obj->size ?? ''), (string)($obj->color ?? ''),
            (float)($obj->price ?? 0), (string)($obj->description ?? ''),
            (int)($obj->stock ?? 0), (string)($obj->category ?? ''),
            (string)($obj->image ?? '')
        );
    }
}