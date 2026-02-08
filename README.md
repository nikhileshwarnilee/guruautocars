# Guru Auto Cars - Garage Management ERP

Core PHP + MySQL + AdminLTE based ERP for Indian multi-branch garage operations.

## Tech Stack
- Core PHP (no framework)
- MySQL
- XAMPP (Apache + MySQL)
- AdminLTE UI assets from `/assets`

## Default Login
- URL: `/guruautocars/login.php`
- Username: `admin`
- Password: `Admin@123`

## Quick Start
1. Create database `guruautocars` in MySQL.
2. Import full schema and seed data:
   - `mysql -u root guruautocars < database/schema.sql`
3. Start Apache + MySQL in XAMPP.
4. Open: `http://localhost/guruautocars/login.php`

## Implemented Modules
- Authentication + RBAC
- Organization (Companies, Garages, Staff)
- Customers
- Vehicles
- Job Cards
- Inventory
- Billing (GST-ready)
- Reports & Analytics

## Security Baseline
- Password hashing (`password_hash`, `password_verify`)
- Session-based authentication
- Role/permission checks per module
- CSRF token validation on POST actions
- Prepared statements for database operations

## Notes
- `template/` is treated as read-only UI reference only.
- Runtime assets are loaded only from `/assets`.
