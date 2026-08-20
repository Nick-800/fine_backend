# Legacy Sync File Cleanup Specification

- **Date:** 2026-08-16
- **Status:** Approved
- **Scope:** Backend `fine_backend` removal of dead legacy sync files.

---

## 1. Overview & Business Rationale

Following the adoption of the online-only local-server architecture (`docs/superpowers/specs/2026-08-04-online-only-local-server-design.md`), offline delta synchronization was deprecated and removed from the system.

Three dead PHP files remained in `fine_backend`:
- `app/Models/SyncConflict.php`
- `app/Enums/SyncConflictStatus.php`
- `app/Services/SyncService.php`

These files are unused, contain references to nonexistent database tables (`sync_conflicts`), and are not called by any route, controller, service, or test.

---

## 2. Changes

Remove the 3 dead legacy files:
- `fine_backend/app/Models/SyncConflict.php` [DELETE]
- `fine_backend/app/Enums/SyncConflictStatus.php` [DELETE]
- `fine_backend/app/Services/SyncService.php` [DELETE]

---

## 3. Verification Plan

1. Run full Pest test suite: `php artisan test --compact`.
2. Run Laravel Pint: `vendor/bin/pint --format agent`.
