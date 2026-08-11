# Kats Agencies

Kats Agencies is a property management platform for landlords and their tenants — property/unit setup, tenancy and rent tracking, M-Pesa payment collection, maintenance requests, and reporting, with a dedicated self-service portal for tenants.

Three roles, kept strictly separate:

- **System Admin** — manages the platform itself: landlord accounts and approvals, platform-wide reporting, audit logs, system settings. Never touches an individual landlord's day-to-day property/rent data.
- **Landlord** — manages their own properties, blocks, units, tenants, tenancies, rent, payments, expenses/income, maintenance, and documents.
- **Tenant** — a self-service portal scoped to their own tenancy only: rent status, payment history, statements/receipts, contract, maintenance requests, and notifications.

## Features

**Property & tenancy management** — Properties → Blocks → Units → Tenants → Tenancies, with occupancy tracked automatically as tenancies move in/out.

**Tenant onboarding, three ways**
- Self-registration via a secure, expiring invitation link (landlord picks the unit and rent terms, the tenant fills in their own details and creates their login)
- Manual entry by the landlord, for tenants who can't self-register
- Bulk CSV import for landlords migrating an existing portfolio, with per-row validation before anything is committed

**Rent & payments**
- Recurring rent charges generated automatically per tenancy
- Landlord-recorded payments (cash/bank/M-Pesa) or tenant-initiated M-Pesa STK Push, reconciled automatically against the right rent period(s)
- Every payment carries a source (genuine platform transaction vs. historical/imported/opening-balance assumption), so historical data can be safely corrected without ever risking a real transaction — with a full audit trail on every correction

**Tenant portal** — dashboard, rent history by year/month, payment history, downloadable statements and receipts, tenancy details, contract viewing, maintenance/complaint submission and status tracking, notifications and landlord announcements.

**Landlord operations** — arrears, expenses/income, financial overview and reports (CSV/PDF export), maintenance scheduling and vendor management, documents, tenant messaging/announcements, applications, deposits.

**Admin oversight** — landlord accounts and approval workflow, platform-wide financial and occupancy reporting, audit logs, role/permission management.

## Tech stack

- [Laravel](https://laravel.com) 13 / PHP 8.3
- [Livewire](https://livewire.laravel.com) 3 for the UI — no separate frontend build/API layer
- MySQL
- [spatie/laravel-permission](https://spatie.be/docs/laravel-permission) for role-based access control
- [spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog) for audit trails
- [barryvdh/laravel-dompdf](https://github.com/barryvdh/laravel-dompdf) for statement/receipt/report PDFs
- Safaricom Daraja API for M-Pesa STK Push

## Getting started

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

# set your database credentials in .env, then:
php artisan migrate --seed

npm run build   # or `npm run dev` while working on frontend assets
php artisan serve
```

The seeders create three demo logins (password `password` for all):

| Role | Email |
|---|---|
| Admin | `admin@katsagencies.test` |
| Landlord | `landlord@katsagencies.test` |
| Tenant | `tenant@katsagencies.test` |

### M-Pesa (optional)

Tenant-initiated payments use Safaricom's Daraja API. To enable it, set in `.env`:

```
MPESA_CONSUMER_KEY=...
MPESA_CONSUMER_SECRET=...
MPESA_CALLBACK_URL=https://<a-publicly-reachable-url>/mpesa/callback
```

`MPESA_SHORTCODE`/`MPESA_PASSKEY` default to Safaricom's published sandbox test values. Safaricom must be able to reach `MPESA_CALLBACK_URL` over HTTPS — use a tunnel (e.g. `ngrok`) for local development.

### Scheduled jobs

Rent charge generation and reminder notifications run on Laravel's scheduler (`bootstrap/app.php`). In development, run:

```bash
php artisan schedule:work
```

## Testing

```bash
php artisan test
```

## License

Proprietary — all rights reserved.
