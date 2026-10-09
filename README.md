# Cancer Symptom Tracker: Clinician Alert Dashboard

**University of Zambia: CSC 4004 Project**
Student: Maximillan Soko (2021494314)

A clinician-facing web dashboard (Part B of the mHealth_15 system). It receives
cancer patient symptom data from a companion mobile app through a REST API,
analyses it against configurable thresholds, raises alerts, and presents trends
and summary reports.

## Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel (PHP 8.2+) |
| Database | PostgreSQL |
| Authentication | Laravel Sanctum (token-based) |
| Frontend | Blade, Bootstrap 5 |
| Charts | Chart.js, D3.js |
| Testing | PHPUnit, Postman |
| Dev environment | Native PHP (XAMPP) + PostgreSQL, run from Git Bash |

> Laravel Sail (Docker) is a supported alternative, but this project was
> developed on a native environment because Docker Desktop was unavailable on
> the development machine.

## Requirements

- PHP 8.2 or newer, with the `pdo_pgsql`, `pgsql`, `zip`, `fileinfo`,
  `mbstring`, and `openssl` extensions enabled in `php.ini`
- Composer 2
- PostgreSQL 14 or newer (pgAdmin is optional but useful)
- Git

Check your setup:

```bash
php -v
composer --version
php -r "echo extension_loaded('pdo_pgsql') ? 'pdo_pgsql OK' : 'pdo_pgsql MISSING';"
```

## Setup

### 1. Get the code

```bash
git clone https://github.com/Maximillan10/Cancer-Symptom-Monitoring-System.git symptom-tracker
cd symptom-tracker
composer install
cp .env.example .env
php artisan key:generate
```

### 2. Create the database

In pgAdmin: right-click **Databases > Create > Database**, and name it
`symptom_tracker`.

Or with `psql`:

```sql
CREATE DATABASE symptom_tracker;
```

### 3. Configure `.env`

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=symptom_tracker
DB_USERNAME=postgres
DB_PASSWORD=your_postgres_password
```

If the password contains `#` or `$`, wrap it in double quotes.

### 4. Run the migrations

```bash
php artisan migrate --seed
```

### 5. Start the app

```bash
php artisan serve
```

Open http://127.0.0.1:8000

## Setting up from scratch (first-time project creation)

Only needed if you are rebuilding the project rather than cloning it.

```bash
composer create-project laravel/laravel symptom-tracker
cd symptom-tracker
# set the .env database values as above
# copy the schema files into the project:
#   migrations/*.php -> database/migrations/
#   models/*.php     -> app/Models/   (replace the default User.php)
php artisan install:api      # installs Sanctum; answer "no" to running migrations
php artisan migrate
```

## Running the project and tests

```bash
php artisan migrate --seed     # creates tables and simulated patients
php artisan serve              # http://127.0.0.1:8000
php artisan test               # full PHPUnit suite
```

Log in with the seeded demo clinician (see `docs/README.md` for the email and password).

## Repository layout

| Folder | Contents |
|---|---|
| app/ | Controllers, models, services (alert engine), observers |
| database/ | Migrations and seeders (25 simulated patients) |
| resources/ | Blade views (dashboard, alerts, thresholds) |
| routes/ | Web and API routes |
| postman/ | Postman API collection |
| scripts/ | Load-test script |
| tests/ | PHPUnit tests |
| docs/ | ERD, test report, setup notes |


## Database schema

Matches Section 3.3 of the approved proposal.

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Clinicians and admins (extends Laravel's default table) | `name`, `email`, `password`, `role` (clinician / admin) |
| `patients` | Patient records | `assigned_clinician_id`, `mobile_app_user_id`, `name`, `dob`, `diagnosis` |
| `symptom_submissions` | One row per patient check-in | `patient_id`, `submitted_at`, `pain`, `fatigue`, `nausea`, `anxiety`, `appetite`, `dyspnea` (each 0-10), `notes` |
| `alert_thresholds` | Per-clinician alert rules | `clinician_id`, `symptom_type`, `threshold_value`, `window_days`, `is_active` |
| `alerts` | Raised when a submission breaches a threshold | `patient_id`, `triggered_at`, `symptom_type`, `severity`, `threshold_breached`, `resolved_at`, `resolved_by` |
| `reports` | Generated patient summaries | `patient_id`, `generated_by`, `generated_at`, `report_type`, `content_json` |

The `alerts` table also has `rule`, `trigger_value` and `message` columns, so each alert explains itself.

### Relationships

- A user (clinician) has many patients, alert thresholds, and generated reports
- A patient has many symptom submissions, alerts, and reports
- An alert is resolved by one user (`resolved_by`)

### Design notes

- **`users` instead of a separate `clinicians` table.** Laravel's default
  authentication table is extended with a `role` column, which keeps
  authentication standard and simple.
- **Wide `symptom_submissions` table.** Each mobile check-in maps to one row,
  so the mobile app's JSON payload matches the table directly.
- **`window_days` on thresholds.** When null, the threshold is an absolute
  severity limit. When set, it defines the window for rate-of-change detection.
  One table supports both alert types.
- **`resolved_at` / `resolved_by` on alerts.** These give a built-in audit
  trail of who acknowledged each alert and when.

## Project milestones

| Milestone | Status | Commit |
|---|---|---|
| Proposal and presentation approval | Done | Approved Apr 15, 2026 |
| DB Schema & Environment Setup | Done | `DB schema and environment setup` |
| Backend Development: Authentication & API | Done | `Add Sanctum authentication and patient/symptom REST API` |
| Prototype: Clinician Dashboard Frontend | Done | `Add clinician dashboard: login, patient list, Chart.js trends and D3 heatmap` |
| Alert System & Symptom Trend Analysis | Done | `Add alert engine, thresholds settings, alerts page and resolve workflow` |
| System Testing & Validation | Done | `System Testing & Validation` |
| Documentation, Report Writing & Submission | In progress | Final report and evidence files |

## Troubleshooting

| Problem | Fix |
|---|---|
| `could not find driver` | Enable `extension=pdo_pgsql` and `extension=pgsql` in `php.ini` (remove the `;`), then reopen the terminal |
| `password authentication failed` | The `DB_PASSWORD` in `.env` does not match your PostgreSQL `postgres` password |
| `database "symptom_tracker" does not exist` | Create the database first (setup step 2) |
| `php: command not found` in Git Bash | Add the PHP folder to your PATH, for example `export PATH="/c/path/to/php:$PATH"` |
| Composer "Permission denied" or timeouts on Desktop | Create the project in a plain folder such as `C:\dev` and exclude it from antivirus scanning |
| `stdout is not a tty` when piping PHP output | Git Bash quirk; avoid piping `php` into `grep`, or run the command in PowerShell |

## Ethical considerations

Only simulated patient data is used. No real patient data is stored or processed.
