# Cancer Symptom Tracker: REST API

Base URL (local): `http://127.0.0.1:8000/api`

All requests must send `Accept: application/json`.
Protected endpoints need `Authorization: Bearer <token>` (Laravel Sanctum).

## Authentication

| Method | Endpoint | Auth | Description |
|---|---|---|---|
| POST | `/register` | No | Register a clinician. Returns `user` and `token`. Role is always `clinician`. |
| POST | `/login` | No | Returns `user` and `token`. 401 on bad credentials. |
| POST | `/logout` | Yes | Revokes the current token. |
| GET | `/me` | Yes | The authenticated user. |
| GET | `/health` | No | Liveness check. |

Rate limits: login 6 requests per minute, register 10 requests per minute.

## Patients

A clinician sees and edits only patients assigned to them. Admins see all patients.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/patients?search=&per_page=` | Paginated list with latest submission and unresolved alert count |
| POST | `/patients` | Create a patient (assigned to the caller; admins may set `assigned_clinician_id`) |
| GET | `/patients/{id}` | One patient |
| PUT/PATCH | `/patients/{id}` | Update a patient |
| DELETE | `/patients/{id}` | Delete a patient (204) |

Create body:
```json
{ "name": "Test Patient", "dob": "1980-04-12", "diagnosis": "Breast cancer, stage I", "mobile_app_user_id": "mobile-100" }
```

## Symptom submissions

| Method | Endpoint | Description |
|---|---|---|
| GET | `/patients/{id}/symptom-submissions?from=&to=&per_page=` | History, newest first |
| POST | `/symptom-submissions` | Mobile app sync: store one check-in |

Submit body (identify the patient by `patient_id` or `mobile_app_user_id`; each score is an integer 0-10, and at least one score is required):
```json
{
  "mobile_app_user_id": "mobile-001",
  "submitted_at": "2026-10-09T09:00:00Z",
  "pain": 7, "fatigue": 6, "nausea": 2, "anxiety": 3, "appetite": 4, "dyspnea": 1,
  "notes": "Worse in the evening"
}
```

## Status codes

| Code | Meaning |
|---|---|
| 200 / 201 / 204 | Success / created / deleted |
| 401 | Missing or invalid token, or bad login |
| 403 | Patient belongs to another clinician |
| 404 | Record not found |
| 422 | Validation failed (`errors` lists the fields) |
| 429 | Rate limit exceeded |

## Demo accounts (after `php artisan db:seed --class=DemoSeeder`)

| Role | Email | Password |
|---|---|---|
| admin | admin@example.com | password |
| clinician | dr.mwansa@example.com | password |
| clinician | dr.banda@example.com | password |

Demo data is simulated. Change or remove these accounts before any real use.
