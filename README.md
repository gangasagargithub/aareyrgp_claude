# RBAC Console

A PHP + PDO + MySQL admin panel for the `aareyrgp_claude` RBAC schema.
NOC-themed UI: dark slate, cyan/amber accents, monospace data fields.

## Setup

1. **Create the database**
   Import the schema (creates tables + seeds default roles/permissions):
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   If you already have the schema live (as in this session), skip this —
   it's already applied.

2. **Configure the connection**
   Edit `config/database.php`:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_PORT', '3306');
   define('DB_NAME', 'aareyrgp_claude');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   ```

3. **Serve the app**
   ```bash
   php -S localhost:8000
   ```
   Or point an Apache/Nginx vhost at this folder.

4. **Log in**
   Use one of the seeded accounts, e.g.:
   - `gangasagar@prajapati.ind.in` — Super Admin
   (Password is the existing bcrypt hash from your prior data — reset it
   via `password_hash()` if you don't have the plaintext.)

## Structure

```
config/database.php     PDO connection
includes/auth.php        Login, logout, session guard, audit logging
includes/sidebar.php     Shared nav partial
assets/css/style.css     Design tokens + all styling
database/schema.sql      Full schema + seed data
login.php                Sign-in page
dashboard.php            Overview + live status strip
users.php                User list, create, delete
roles.php                Roles + permission matrix
audit.php                Audit log viewer
logout.php               Session teardown
```

## Notes

- Passwords are hashed with bcrypt (`password_hash()` / `password_verify()`).
- All queries use PDO prepared statements — no raw string interpolation.
- Every login, logout, user creation, and deletion writes to `audit_logs`.
- Session data (`user_id`, `roles`, etc.) drives what's shown per user;
  there's no route-level permission enforcement yet — add checks against
  `$_SESSION['roles']` or a permission lookup if you need per-page gating.
