# HRMS Portal — Workforce Management Platform

> A complete, self-hosted **Human Resource Management System** for small & mid-size teams. Attendance, tasks, leave, leads, GPS tracking, device-locked sign-in and live system monitoring — all in plain PHP + MySQL, no framework, no build step.

[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![Status](https://img.shields.io/badge/status-production-brightgreen)]()
[![License](https://img.shields.io/badge/license-MIT-blue.svg)]()

---

## ✨ Why this HRMS?

Most open-source HR systems are heavy Laravel/Django stacks that need composer, npm, redis and a CI pipeline before a single user can clock in. **HRMS Portal** is the opposite: drop the folder on any cPanel/Hostinger/XAMPP host, import the SQL, and you're live in minutes.

It is built for **field-heavy businesses** — sales reps, technicians, delivery agents — where you need:

- 📍 **GPS-geofenced check-ins** for office staff
- 📱 **Device-locked sign-in** for field workers (one phone per employee, period)
- ✅ **Daily-task gating** before checkout
- 🗺️ **Live location tracking** with photo proof
- 📊 **Live system monitor** to see who is signed in, from where, on which device

---

## 🚀 Key features

### Attendance & time tracking
- One-click **check-in / check-out** with GPS coordinates and IP capture
- Geofence enforcement (configurable radius, default 100 m)
- Office-staff IP-restriction toggle per user
- **Day-end gate** — checkout blocked until all daily tasks are completed *and* a daily report file + notes are uploaded
- Approved leave automatically marks the day as `on_leave`

### Task management
- Admin assigns **daily / weekly / monthly** tasks with deadlines, attachments and optional call-recording proof
- Workers mark tasks `in_progress` → `completed` with proof file + notes
- Auto status transitions to `overdue` when past deadline
- Full task history view for admins

### Leave management
- 7 leave types (annual, sick, personal, maternity, paternity, emergency, unpaid)
- Employee request form, admin review panel with remarks
- Approved leaves automatically integrate with the attendance roster

### Leads / Mini-CRM
- Full lead pipeline (new → contacted → qualified → meeting → negotiation → won/lost)
- Activity timeline, attachments, products, follow-up calendar
- KPI dashboard, lead-import wizard, kanban-style pipeline view

### 🆕 Device locking & single-session security
- **Field workers are locked to their first device** — admin must explicitly reset before they can sign in elsewhere
- **One active session per account, all roles** — a new login automatically signs out any other device
- Per-device fingerprint stored in browser `localStorage` and bound server-side
- Admin "**Reset Device**" button on every user row

### 🆕 System Monitor (admin)
A live observability page with four tabs:

| Tab | What you see |
|---|---|
| **Active Sessions** | Every signed-in user with their IP, device ID, user-agent, last-login timestamp + force-sign-out button |
| **Bound Devices** | All devices currently locked to an account |
| **System Health** | PHP / MySQL versions, memory usage, disk space, upload limits, geofence config, table sizes |
| **System Log** | Filterable audit trail (login success/fail, device bind/reject/reset, session replace, logout, admin actions) |

### Reports & exports
- Filterable attendance reports with **CSV export**
- Reports Center with date-range KPIs
- Location tracker page (per-user, per-day map of GPS pings)
- Data-purge tool to delete records older than X months

### Security baked in
- Bcrypt password hashing (cost 12)
- CSRF tokens on every form **and** every AJAX request
- HTTPS-aware session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- Server-side validation of every checkout/check-in (geofence + IP)
- Dev-mode bypass switch for local testing
- Random-named uploads with extension whitelist + size cap

---

## 🧰 Tech stack

| Layer | Choice |
|---|---|
| Backend | **PHP 8.x** + **PDO** (no framework, no Composer) |
| Database | **MySQL 5.7+ / MariaDB 10.3+** |
| Frontend | **Bootstrap 5.3** + **Bootstrap Icons 1.11** |
| Scripting | Vanilla JavaScript (Fetch API + HTML5 Geolocation) |
| Hosting | Any LAMP / LEMP host — Hostinger, cPanel, XAMPP, Laragon, MAMP |

No build step. No `node_modules`. No `composer install`. No transpilation.

---

## ⚡ Quick start

### 1. Clone

```bash
git clone https://github.com/codeandpromote/project-management-basic.git hrms_software
cd hrms_software
```

### 2. Create the database

In phpMyAdmin (or `mysql` CLI), create a database named `hrms_db` and import:

```bash
mysql -u root -p hrms_db < schema.sql
mysql -u root -p hrms_db < schema_update.sql
```

> The app also **auto-migrates** missing columns/tables on first request, so this step is optional after a `git pull`.

### 3. Configure

Edit `config.php`:

```php
define('DEV_MODE',        true);             // bypasses IP + GPS checks (turn off in prod)
define('DB_HOST',         'localhost');
define('DB_NAME',         'hrms_db');
define('DB_USER',         'root');
define('DB_PASS',         '');
define('OFFICE_IP',       '203.0.113.10');   // your office public IP
define('OFFICE_LAT',       40.712800);
define('OFFICE_LNG',      -74.006000);
define('GEOFENCE_RADIUS',  100);             // metres
define('BASE_URL',        'http://localhost/hrms_software/');
```

### 4. Sign in

Point your browser at `http://localhost/hrms_software/` and log in with one of the seed accounts:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@hrms.com` | `password` |
| Office Staff | `jane@hrms.com` | `password` |
| Field Worker | `john@hrms.com` | `password` |

> ⚠️ **Change every seed password before going to production.** Do it from `Admin → User Management → Edit`.

---

## 🔐 Roles & permissions

| Module | Admin | Office Staff | Field Worker |
|---|:-:|:-:|:-:|
| Dashboard | ✅ | ✅ | ✅ |
| Tasks (assign) | ✅ | — | — |
| Tasks (perform) | ✅ | ✅ | ✅ |
| Leave (request) | ✅ | ✅ | ✅ |
| Leave (review) | ✅ | — | — |
| Attendance reports | ✅ | — | — |
| Leads | ✅ | ✅ | ✅ |
| User Management | ✅ | — | — |
| Location Tracker | ✅ | — | — |
| System Monitor | ✅ | — | — |
| Data Maintenance | ✅ | — | — |

---

## 🗂️ Project structure

```
hrms_software/
├── index.php                       # Login page (captures device fingerprint)
├── dashboard.php                   # Main employee/admin dashboard
├── auth.php                        # Sessions, CSRF, login, geofence, event log
├── config.php                      # Database, geofence, office IP, dev mode
├── db_connect.php                  # PDO singleton + auto-migration
├── schema.sql                      # Initial DB schema + seed users
├── schema_update.sql               # Idempotent additive migrations
│
├── attendance_handler.php          # AJAX: check-in / check-out / day-end
├── task_handler.php                # AJAX: start / complete / delete tasks
├── tasks.php                       # Admin task assignment UI
├── leave_module.php                # Leave request + admin review
├── lead_handler.php                # AJAX endpoints for leads
├── leads.php  lead_view.php  …     # CRM module
├── location_handler.php            # GPS log endpoint with photo proof
│
├── admin_users.php                 # Create / edit / deactivate / reset device
├── admin_reports.php               # Attendance reports + CSV export
├── admin_reports_center.php        # KPI date-range reports
├── admin_locations.php             # Live location tracker map
├── admin_media.php                 # Files & uploads browser
├── admin_task_history.php          # Full task history filter
├── admin_system_monitor.php        # 🆕 Sessions / devices / health / log
├── kpi_dashboard.php               # Lead-pipeline KPIs
├── data_purge.php                  # Delete data older than X months
│
├── includes/
│   ├── header.php                  # Topbar, sidebar wrapper
│   ├── footer.php
│   └── sidebar_nav.php             # Role-aware navigation
│
├── assets/css/style.css            # All custom styles
└── uploads/                        # User uploads (auto-created)
```

---

## 🛡️ Device-locking flow (how it works)

```
┌──────────────────────────────────────────────────────────┐
│  Browser on first visit                                  │
│    • Generates UUID  →  localStorage.hrms_device_id      │
│    • Submits as hidden field with login                  │
└────────────────────────┬─────────────────────────────────┘
                         ▼
┌──────────────────────────────────────────────────────────┐
│  attemptLogin($email, $password, $deviceFingerprint)     │
│                                                          │
│  IF role = field_worker:                                 │
│    • users.device_id IS NULL  →  bind it now             │
│    • users.device_id = fingerprint  →  allow             │
│    • mismatch  →  REJECT  ("contact admin")              │
│                                                          │
│  ALL roles:                                              │
│    • generate new session_token  →  store in users + $_SESSION │
│    • record IP + user-agent + login time                 │
└────────────────────────┬─────────────────────────────────┘
                         ▼
┌──────────────────────────────────────────────────────────┐
│  Every subsequent request → requireLogin()               │
│    • Compare $_SESSION['session_token'] vs DB            │
│    • Mismatch (= a newer login rotated it) → kick out    │
│      → redirect to index.php?reason=session_replaced     │
│      → AJAX gets 401 JSON                                │
└──────────────────────────────────────────────────────────┘
```

**Admin reset** = `users.device_id`, `device_bound_at`, `session_token` are all set to `NULL`. The next login on any device rebinds.

---

## 🩺 System Monitor at a glance

The admin can audit at any time:

- **Who is online right now** — name, role, IP, device ID, browser, login time
- **Which field workers are bound to which devices** — and when
- **Server health** — PHP/MySQL versions, RAM used, peak, disk free, upload limits
- **Application config** — DEV_MODE state, office IP, geofence radius, session lifetime
- **Database stats** — total size, per-table row counts and size
- **Audit log** — filterable by event type, user, date range, with one-click clearance (all, or older-than-N-days)

---

## 🔧 Configuration reference

| Constant | Default | Purpose |
|---|---|---|
| `DEV_MODE` | `false` | Bypass IP + GPS checks for local dev |
| `OFFICE_IP` | `203.0.113.10` | Public IP allowed for office-staff check-in |
| `OFFICE_LAT` / `OFFICE_LNG` | demo values | Geofence centre |
| `GEOFENCE_RADIUS` | `100` m | Maximum distance allowed for office check-in |
| `SESSION_LIFETIME` | `7200` (2 h) | Session cookie lifetime |
| `MAX_FILE_MB` | `30` | Upload size cap |
| `ALLOWED_EXT` | pdf, docx, xlsx, png, jpg, mp3, m4a, … | Upload extension whitelist |

---

## ❓ FAQ

**Does this work on shared hosting (Hostinger / Bluehost / cPanel)?**
Yes — that's the primary target. The auto-migrator falls back to FK-less table creation when the host disallows foreign-key constraints.

**Can a field worker just clear localStorage to bypass device binding?**
No. Clearing localStorage generates a *new* fingerprint, which won't match the bound one — they'll get *"this account is locked to a registered device"* and have to ask the admin to reset.

**Can two admins be signed in at the same time?**
Two **different** admins, yes. The same admin account on two devices, no — the older session is force-logged-out the moment the newer one signs in.

**What if my host disables `random_bytes` / `localStorage`?**
Both are universal on PHP 7+ and any modern browser. There's a JS fallback UUID generator for very old browsers, and `random_bytes` is required by `password_hash` anyway.

**Is the audit log retention configurable?**
The Clear Log form on the System Monitor takes a `days` value — leave blank to truncate, fill in `30` to keep the last 30 days, etc. You can also wire it into `data_purge.php` as a cron.

---

## 🧭 Roadmap ideas

- [ ] Two-factor authentication (TOTP)
- [ ] Push notifications via FCM for task assignments
- [ ] Payroll module (CTC, deductions, payslip PDF)
- [ ] Shift & roster planner
- [ ] Multi-tenant / company workspaces
- [ ] REST API + mobile app

PRs welcome.

---

## 🤝 Contributing

1. Fork & branch off `main`
2. Keep changes additive — preserve the existing PDO singleton, CSRF, and `validate_access()` patterns
3. Test against the seed users (admin / office / field) before opening a PR
4. Run `php -l` on every modified file

---

## 📜 License

MIT — do whatever you want, just keep the copyright notice.

---

## 🌐 Keywords

`hrms` · `human resource management` · `attendance system php` · `employee tracking` · `geofence check-in` · `field worker app` · `device lock login` · `single session enforcement` · `php hrms open source` · `bootstrap 5 admin panel` · `mysql attendance` · `leave management system` · `mini crm leads php` · `gps employee tracker`
