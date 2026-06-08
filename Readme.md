# C2C Platform

Small PHP/MySQL marketplace project for buyer-to-buyer and buyer-to-seller trading.

## Stack
- PHP 8+
- MySQL (XAMPP)
- Bootstrap 5 + custom CSS
- Vanilla JavaScript

## What is implemented
- Account registration and login
- Product listing with multiple images
- Marketplace search/filter/sort
- Deal flow (request, accept/reject, pay, dispatch, complete/cancel)
- Basic chat per transaction
- Profile/settings/support pages
- Admin area for users, roles, listings, transactions, flags, support, KPI
- Multilingual labels (English/Afrikaans/isiXhosa for key UI text)

## Local setup
1. Copy the project into `C:\xampp\htdocs\c2c_platform`.
2. Create `.env` in the project root:

```env
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=c2c_platform
```

3. Create the database in MySQL.
4. Import your base schema (users/products/categories/transactions and related tables).
5. Start Apache + MySQL in XAMPP.
6. Open `http://localhost/c2c_platform`.

## Notes about schema updates
`includes/mock_system.php` contains `ensureMockSystemSchema($conn)`.
It adds missing mock-system columns/tables/indexes on startup so the project can run with older schemas.

## Main folders/files
- `admin/` admin pages
- `includes/db.php` DB bootstrap
- `includes/mock_system.php` shared helper functions
- `css/style.css` app styling
- `js/mobile-nav.js` navbar behavior
- `uploads/` product images

## Default roles
- Buyer
- Seller
- Moderator
- Admin

## Limitations / next work
- No production payment gateway (mock escrow only)
- No real courier API integration yet
- Minimal hardening for production (rate limit, CSRF, audit trail)
- Needs automated tests

## Quick developer checklist
- Run through buyer and seller flows after schema edits
- Check admin pages for permissions and status updates
- Verify file upload limits and image types
- Confirm language toggle still reads correctly from session



# Responsive Prototype Screenshot Manifest

| Area | Device | Page | File |
|---|---|---|---|
| Main website | mobile | login | main_website/mobile/login.png |
| Main website | mobile | register | main_website/mobile/register.png |
| Main website | mobile | forgot_password | main_website/mobile/forgot_password.png |
| Main website | mobile | reset_password | main_website/mobile/reset_password.png |
| Main website | mobile | dashboard | main_website/mobile/dashboard.png |
| Main website | mobile | listings | main_website/mobile/listings.png |
| Main website | mobile | product | main_website/mobile/product.png |
| Main website | mobile | sell | main_website/mobile/sell.png |
| Main website | mobile | transactions | main_website/mobile/transactions.png |
| Main website | mobile | chat | main_website/mobile/chat.png |
| Main website | mobile | profile | main_website/mobile/profile.png |
| Main website | mobile | settings | main_website/mobile/settings.png |
| Main website | mobile | support | main_website/mobile/support.png |
| Admin website | mobile | dashboard | admin_website/mobile/dashboard.png |
| Admin website | mobile | users | admin_website/mobile/users.png |
| Admin website | mobile | roles | admin_website/mobile/roles.png |
| Admin website | mobile | listings | admin_website/mobile/listings.png |
| Admin website | mobile | transactions | admin_website/mobile/transactions.png |
| Admin website | mobile | flags | admin_website/mobile/flags.png |
| Admin website | mobile | kpi | admin_website/mobile/kpi.png |
| Admin website | mobile | support | admin_website/mobile/support.png |
| Main website | tablet | login | main_website/tablet/login.png |
| Main website | tablet | register | main_website/tablet/register.png |
| Main website | tablet | forgot_password | main_website/tablet/forgot_password.png |
| Main website | tablet | reset_password | main_website/tablet/reset_password.png |
| Main website | tablet | dashboard | main_website/tablet/dashboard.png |
| Main website | tablet | listings | main_website/tablet/listings.png |
| Main website | tablet | product | main_website/tablet/product.png |
| Main website | tablet | sell | main_website/tablet/sell.png |
| Main website | tablet | transactions | main_website/tablet/transactions.png |
| Main website | tablet | chat | main_website/tablet/chat.png |
| Main website | tablet | profile | main_website/tablet/profile.png |
| Main website | tablet | settings | main_website/tablet/settings.png |
| Main website | tablet | support | main_website/tablet/support.png |
| Admin website | tablet | dashboard | admin_website/tablet/dashboard.png |
| Admin website | tablet | users | admin_website/tablet/users.png |
| Admin website | tablet | roles | admin_website/tablet/roles.png |
| Admin website | tablet | listings | admin_website/tablet/listings.png |
| Admin website | tablet | transactions | admin_website/tablet/transactions.png |
| Admin website | tablet | flags | admin_website/tablet/flags.png |
| Admin website | tablet | kpi | admin_website/tablet/kpi.png |
| Admin website | tablet | support | admin_website/tablet/support.png |
| Main website | desktop | login | main_website/desktop/login.png |
| Main website | desktop | register | main_website/desktop/register.png |
| Main website | desktop | forgot_password | main_website/desktop/forgot_password.png |
| Main website | desktop | reset_password | main_website/desktop/reset_password.png |
| Main website | desktop | dashboard | main_website/desktop/dashboard.png |
| Main website | desktop | listings | main_website/desktop/listings.png |
| Main website | desktop | product | main_website/desktop/product.png |
| Main website | desktop | sell | main_website/desktop/sell.png |
| Main website | desktop | transactions | main_website/desktop/transactions.png |
| Main website | desktop | chat | main_website/desktop/chat.png |
| Main website | desktop | profile | main_website/desktop/profile.png |
| Main website | desktop | settings | main_website/desktop/settings.png |
| Main website | desktop | support | main_website/desktop/support.png |
| Admin website | desktop | dashboard | admin_website/desktop/dashboard.png |
| Admin website | desktop | users | admin_website/desktop/users.png |
| Admin website | desktop | roles | admin_website/desktop/roles.png |
| Admin website | desktop | listings | admin_website/desktop/listings.png |
| Admin website | desktop | transactions | admin_website/desktop/transactions.png |
| Admin website | desktop | flags | admin_website/desktop/flags.png |
| Admin website | desktop | kpi | admin_website/desktop/kpi.png |
| Admin website | desktop | support | admin_website/desktop/support.png |

Total screenshots: 63