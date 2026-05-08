# Zephyr — Furniture E-Commerce Web App

A PHP-based furniture e-commerce system with user authentication, shopping cart, order management, and an admin dashboard.

## ⚙️ Requirements

- PHP **8.0+**
- A local server: [XAMPP](https://www.apachefriends.org/), [Laragon](https://laragon.org/), or any Apache/Nginx with PHP


## 🔐 Authentication & Session Behavior

- Users register and log in via `logsign.php`
- Sessions use **session cookies** — no fixed expiry time
- Session ends naturally when the **browser is closed**
- Logging out via the logout button clears session data but **does NOT delete the cart**
- Cookies used: `zephyr_user`, `zephyr_role`, `zephyr_name`, `zephyr_login`


## 🛒 Cart Behavior

| Situation | Cart |
|---|---|
| User logs out → logs back in | ✅ Cart is restored |
| Browser is closed / session expires | ❌ Cart is cleared |
| Admin deletes a user | ❌ Cart is cleared |

Cart data is saved per user in `data/carts.json`.



## 🛠️ Admin Features

Access the admin panel at `admin.php` (requires admin role).

- **Add / Edit products** — name, price, stock, category, size, color, image, description
- **Delete products**
- **Restock products**
- **View all orders** across all users
- **Update order status** — `Pending` → `Processing` → `Shipped` → `Delivered` / `Cancelled`
- **Delete users** (cannot delete yourself)



## 📦 Product Categories

- `Sofa`
- `Chair`
- `Set`



## 💰 Pricing

- All prices are in **Philippine Peso (₱)**
- Tax rate: **12% VAT** (applied at checkout)


## 🗂️ Key Files Explained

### `config.php`
Core of the app. Handles:
- Session initialization
- JSON persistence functions (`loadUsers`, `saveUsers`, `loadInventory`, `saveInventory`, `loadOrders`, `saveOrders`, `loadCarts`, `saveCarts`)
- Cookie tamper-check (security)
- The `Product` class with OOP encapsulation (`__get`, `__set`, `__toString`, `toStdClass`, `fromStdClass`)

### `addcart.php`
AJAX endpoint called by the storefront. Validates stock, caps quantity at available stock, and saves the cart to `carts.json`.

### `admin_action.php`
Handles all admin POST/GET actions:
- `POST` → Add or edit a product
- `GET ?delete=ID` → Delete a product
- `GET ?restock_id=ID&amount=N` → Restock a product
- `GET ?update_status` → Update order status (returns JSON)
- `GET ?delete_user` → Delete a user (returns JSON)

### `logout.php`
Clears login session keys and all `zephyr_*` cookies. Shows a 60-second animated countdown before redirecting to `logsign.php`. Cart data is **preserved** in `carts.json`.


## 🎨 Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ |
| Frontend | HTML, CSS, Bootstrap 5, JavaScript |
| Data Storage | MySQL files |
| Timezone | Asia/Manila (GMT+8) |

## 📝 Notes

- This project was built as an academic/portfolio project
- No external framework or database is used — pure PHP + JSON
- All sensitive session operations are server-side only
- Product images should be placed inside a `pci/` folder at the project root

---

## 📄 License

This project is for educational purposes. Feel free to use and modify.
