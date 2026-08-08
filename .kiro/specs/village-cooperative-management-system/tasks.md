# Implementation Plan: Village Cooperative Management System (VCMS)

## Overview

This plan implements the VCMS as a PHP 8.2+ REST API backend (Core PHP, PDO, MVC, no framework) with a React + Vite + Tailwind CSS frontend. Tasks are ordered from foundational infrastructure → database schema → backend services → frontend pages → integration wiring. All 17 correctness properties are covered as optional property-based test sub-tasks using [eris/eris](https://github.com/giorgiosironi/eris) with PHPUnit on the backend and Vitest on the frontend.

---

## Tasks

- [x] 1. Project scaffolding and environment setup
  - Create `backend/` and `frontend/` directory trees matching the Component Map in the design
  - Initialize `frontend/` with Vite + React; install Tailwind CSS, Axios, React Router, React Hook Form, Lucide React Icons (pinned exact versions)
  - Initialize `backend/composer.json`; require `eris/eris`, `phpunit/phpunit`, `setasign/fpdf` (or mPDF), `phpoffice/phpspreadsheet`
  - Create `backend/public/index.php` as the sole entry point (bootstrap, session_start, dispatcher)
  - Create `backend/public/.htaccess` to route all requests to `index.php` and block direct access to `uploads/backups/`
  - Create `backend/app/config/App.php` (base URL, environment, timezone UTC)
  - _Requirements: 16.5_

- [x] 2. Database schema: migration script
  - [x] 2.1 Write idempotent SQL migration script (`backend/database/migrations/001_initial_schema.sql`)
    - Create tables in dependency order: `settings`, `admins`, `cycles`, `accounting_periods`, `members`, `saving_transactions`, `interest_transactions`, `loans`, `repayments`, `cash_bank_transactions`, `distributions`, `distribution_items`, `audit_logs`
    - Apply utf8mb4 / utf8mb4_unicode_ci on every table and every text column
    - Add all standard columns (`id`, `created_at`, `updated_at`, `created_by`, `updated_by`, `status`) to every table
    - Add all indexes specified in Requirement 16.4 (member_id, phone, full_name FULLTEXT, bs_year, bs_month, loan_status, cycle_id on all transaction tables, audit_logs composite)
    - Add UNIQUE constraint on `(member_id, accounting_period_id)` in `saving_transactions`
    - Add `BEFORE UPDATE` and `BEFORE DELETE` MySQL triggers on `audit_logs` to block modifications
    - Apply ON DELETE RESTRICT on all financial foreign keys; seed `settings` table with defaults (`cooperative_name`, `fixed_monthly_saving=1000`, `interest_rate_annual=12`, `default_language=en`)
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 12.3_

  - [ ]* 2.2 Write property test for audit log immutability (Property 15)
    - **Property 15: Audit log is immutable at the database level**
    - **Validates: Requirements 12.3**
    - Use PHPUnit to attempt `UPDATE` and `DELETE` on `audit_logs`; assert MySQL trigger raises an error and the row is unchanged

- [x] 3. Database connection and core backend infrastructure
  - [x] 3.1 Implement `backend/app/config/Database.php` — PDO singleton factory
    - Configure `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`, `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
    - Set charset to `utf8mb4` in DSN
    - _Requirements: 15.1, 16.2_

  - [x] 3.2 Implement `backend/app/helpers/Response.php` — JSON response builder
    - `Response::success($data, $message, $code)` and `Response::error($code, $message, $fields, $httpStatus)`
    - Return standard envelope: `{ success, data, message }` / `{ success, error: { code, message, fields } }`
    - Set `Content-Type: application/json`
    - _Requirements: 15.4_

  - [x] 3.3 Implement `backend/app/helpers/Validator.php` — input validation helper
    - Rules: `required`, `type:int|decimal|string|enum`, `min`, `max`, `minLength`, `maxLength`, `regex`, `unique`
    - Return structured `{ field => message }` errors array on failure
    - _Requirements: 15.2, 15.3_

  - [x] 3.4 Implement `backend/app/routes/api.php` — route table and dispatcher
    - Each route entry: `[METHOD, path_pattern, 'Controller@action', roles[]]`
    - Dispatcher parses `$_SERVER['REQUEST_URI']`, matches route, runs middleware stack, calls controller
    - _Requirements: 15.7_

- [x] 4. Middleware layer
  - [x] 4.1 Implement `AuthMiddleware.php` — session validation and timeout
    - _Requirements: 1.3, 2.4_

  - [x] 4.2 Implement `CsrfMiddleware.php` — CSRF token validation
    - _Requirements: 1.11, 15.8, 15.9_

  - [ ]* 4.3 Write property test for CSRF strict validation (Property 3)
    - **Property 3: CSRF tokens are validated strictly**
    - **Validates: Requirements 1.11, 15.8, 15.9**

  - [x] 4.4 Implement `RbacMiddleware.php` — role-based access control
    - _Requirements: 2.6, 15.7_

- [x] 5. Authentication — backend
  - [x] 5.1 Implement `AdminModel.php` — DB queries for admin records
    - _Requirements: 1.1, 1.4, 1.9, 1.10, 15.1_

  - [x] 5.2 Implement `AuthService.php` — login, logout, remember-me, change-password logic
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10_

  - [ ]* 5.3 Write property test for invalid credential rejection (Property 1)
    - **Property 1: Invalid credentials are always rejected**
    - **Validates: Requirements 1.2**

  - [ ]* 5.4 Write property test for password hashing round-trip (Property 2)
    - **Property 2: Password hashing round-trip preserves no plaintext**
    - **Validates: Requirements 1.7, 1.9**

  - [x] 5.5 Implement `AuthController.php` — wire routes to AuthService
    - _Requirements: 1.1, 1.6, 1.7_

- [x] 6. Admin management — backend
  - [x] 6.1 Implement `AdminService.php` + `AdminController.php` — CRUD for admin accounts
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6_

  - [ ]* 6.2 Write property test for admin username uniqueness (Property 16)
    - **Property 16: Admin username uniqueness**
    - **Validates: Requirements 2.5**

- [x] 7. BS Calendar helper
  - [x] 7.1 Implement `backend/app/helpers/BsCalendar.php`
    - _Requirements: 4.5_

  - [x] 7.2 Implement `frontend/src/utils/bsDate.js` — frontend BS date utilities
    - _Requirements: 4.2, 4.5_

  - [ ]* 7.3 Write unit tests for BsCalendar (PHPUnit)
  - [ ]* 7.4 Write unit tests for bsDate.js (Vitest)

- [x] 8. Member management — backend
  - [x] 8.1 Implement `MemberModel.php` — DB queries for member records
    - _Requirements: 3.1, 3.4, 3.6, 3.7_

  - [x] 8.2 Implement `MemberService.php` — member business logic
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.7, 3.8, 3.9_

  - [ ]* 8.3 Write property test for Member ID format and uniqueness (Property 4)
  - [ ]* 8.4 Write property test for member validation rejection (Property 5)

  - [x] 8.5 Implement `MemberController.php` — wire routes to MemberService
    - _Requirements: 3.1, 3.4_

- [x] 9. Accounting period and Month_Close — backend
  - [x] 9.1 Implement `AccountingPeriodModel.php` and `CycleModel.php`
    - _Requirements: 4.1, 4.2_

  - [x] 9.2 Implement `MonthCloseService.php` — atomic Month_Close
    - _Requirements: 4.3, 4.4, 4.5, 4.6, 4.9, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6_

  - [ ]* 9.3 Write property test for exactly one OPEN period invariant (Property 6)
  - [ ]* 9.4 Write property test for Month_Close state transitions (Property 7)
  - [ ]* 9.5 Write property test for savings interest formula correctness (Property 8)

  - [x] 9.6 Implement `AccountingPeriodController.php` — period routes
    - _Requirements: 4.1, 4.7, 4.8_

- [x] 10. Savings (bulk collection) — backend
  - [x] 10.1 Implement `SavingTransactionModel.php` — saving transaction DB queries
    - _Requirements: 5.1, 5.4_

  - [x] 10.2 Implement `SavingsService.php` — bulk savings collection
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [ ]* 10.3 Write property test for no duplicate saving transaction (Property 9)

  - [x] 10.4 Implement `SavingsController.php` — wire savings routes
    - _Requirements: 5.1, 5.2_

- [x] 11. Loan management — backend
  - [x] 11.1 Implement `LoanModel.php` and `RepaymentModel.php` — DB queries
    - _Requirements: 7.1, 7.7_

  - [x] 11.2 Implement `LoanService.php` — loan disbursement and repayment logic
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 7.9, 7.10, 7.11_

  - [ ]* 11.3 Write property test for repayment over-payment rejection (Property 10)

  - [x] 11.4 Implement `LoanController.php` — wire loan routes
    - _Requirements: 7.1, 7.4_

- [x] 12. Cash and bank management — backend
  - [x] 12.1 Implement `CashBankTransactionModel.php` — cash/bank DB queries
    - _Requirements: 8.1, 8.6_

  - [x] 12.2 Implement `CashBankService.php` — transfer logic
    - _Requirements: 8.2, 8.3, 8.4, 8.5_

  - [ ]* 12.3 Write property test for cash/bank transfer conservation law (Property 11)
  - [ ]* 12.4 Write property test for insufficient-funds transfer rejection (Property 12)

  - [x] 12.5 Implement `CashBankController.php` — wire cash/bank routes
    - _Requirements: 8.2, 8.3_

- [ ] 13. Checkpoint — core backend complete
  - Verify DB migration applies cleanly on a fresh MySQL 8+ instance
  - Confirm all Month_Close integration paths pass PHPUnit integration tests

- [x] 14. Dashboard — backend
  - [x] 14.1 Implement `DashboardController.php` + `DashboardService.php`
    - _Requirements: 9.1, 9.3, 9.4, 9.5_

- [x] 15. Distribution — backend
  - [x] 15.1 Implement `DistributionModel.php` — distribution DB queries
    - _Requirements: 10.1, 10.2, 10.6_

  - [x] 15.2 Implement `DistributionService::generatePdf()` — Phase 1 ledger generation
    - _Requirements: 10.1, 10.2, 10.3_

  - [ ]* 15.3 Write property test for distribution final payable formula (Property 13)

  - [x] 15.4 Implement `DistributionService::confirm()` — Phase 2 atomic confirmation
    - _Requirements: 10.4, 10.5, 10.7_

  - [x] 15.5 Implement `DistributionController.php` — wire distribution routes
    - _Requirements: 10.1, 10.4, 10.6_

- [x] 16. Reports and exports — backend
  - [x] 16.1 Implement `ReportService.php` — all report queries
    - _Requirements: 11.1, 11.2, 11.3, 11.5, 11.6, 12.4_

  - [ ]* 16.2 Write property test for member statement running balance correctness (Property 14)

  - [x] 16.3 Implement `ExcelExporter.php` and `PdfGenerator.php` helpers
    - _Requirements: 11.4_

  - [x] 16.4 Implement `ReportController.php` — wire all report routes
    - _Requirements: 11.1, 11.4, 11.5_

- [x] 17. Audit log — backend
  - [x] 17.1 Implement `AuditLogModel.php` and `AuditLogger.php`
    - _Requirements: 12.1, 12.2, 12.5, 12.6_

  - [x] 17.2 Implement `AuditController.php` — wire audit routes
    - _Requirements: 12.4_

- [x] 18. Language support — backend
  - [x] 18.1 Create `backend/lang/en.json` and `backend/lang/ne.json`
    - _Requirements: 14.1, 14.2, 14.6_

  - [x] 18.2 Implement language delivery endpoints
    - _Requirements: 14.3, 14.4, 14.5_

- [x] 19. Backup and restore — backend
  - [x] 19.1 Implement `BackupService.php` and `BackupController.php`
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5_

  - [x] 19.2 Implement `GET /backup/list` and wire all backup routes
    - _Requirements: 13.1, 13.2_

- [x] 20. Settings — backend
  - [x] 20.1 Implement settings read/write endpoints
    - _Requirements: 12.1_

- [ ] 21. Checkpoint — full backend API complete
  - Manually test all endpoints with a REST client against the local DB

- [x] 22. Frontend — core infrastructure
  - [x] 22.1 Configure Vite + React + Tailwind CSS
    - _Requirements: 14.1_

  - [x] 22.2 Implement Axios HTTP client and API service layer
    - _Requirements: 1.11, 15.8_

  - [x] 22.3 Implement i18n hook and language service
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

  - [ ]* 22.4 Write Vitest test for language fallback (Property 17)

  - [ ] 22.5 Implement shared UI component library
    - `Button.jsx`, `Card.jsx`, `Modal.jsx`, `Badge.jsx`, `Alert.jsx`, `Spinner.jsx`
    - `Table.jsx`, `FormField.jsx`, `SelectField.jsx`, `DatePickerBS.jsx`
    - `Sidebar.jsx`, `Header.jsx`, `PageHeader.jsx`
    - `AppLayout.jsx` and `AuthLayout.jsx`
    - _Requirements: 14.1, 14.2_

  - [x] 22.6 Implement `useAuth.jsx` hook and route guards
    - _Requirements: 1.1, 2.6_

  - [x] 22.7 Implement `frontend/src/utils/currency.js`
    - _Requirements: 6.1_

  - [ ]* 22.8 Write Vitest test for currency rounding utility

- [ ] 23. Frontend — authentication pages
  - [ ] 23.1 Implement `Login.jsx` page
    - _Requirements: 1.1, 1.2, 1.4, 14.1_

- [ ] 24. Frontend — Dashboard
  - [ ] 24.1 Implement `Dashboard.jsx` page
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

- [ ] 25. Frontend — Member Management
  - [ ] 25.1 Implement `MemberList.jsx` and `MemberForm.jsx`
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.8, 3.9, 14.1_

  - [ ] 25.2 Implement `MemberStatement.jsx`
    - _Requirements: 11.2, 11.4_

- [ ] 26. Frontend — Bulk Savings Collection
  - [ ] 26.1 Implement `BulkCollection.jsx` page
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 14.1_

  - [ ]* 26.2 Write Vitest test for BulkCollection screen

- [ ] 27. Frontend — Loan Management
  - [ ] 27.1 Implement `LoanList.jsx` and `LoanForm.jsx`
    - _Requirements: 7.1, 7.2, 7.3, 14.1_

  - [ ] 27.2 Implement `RepaymentForm.jsx`
    - _Requirements: 7.4, 7.5, 7.6, 7.7, 7.8_

- [ ] 28. Frontend — Cash and Bank Management
  - [ ] 28.1 Implement `CashBook.jsx` and `TransferForm.jsx`
    - _Requirements: 8.2, 8.3, 8.4, 8.5, 14.1_

- [ ] 29. Frontend — Distribution
  - [ ] 29.1 Implement `DistributionLedger.jsx`
    - _Requirements: 10.1, 10.2, 10.3_

  - [ ] 29.2 Implement `DistributionConfirm.jsx`
    - _Requirements: 10.4, 10.5, 10.7_

- [ ] 30. Frontend — Reports
  - [ ] 30.1 Implement `ReportSelector.jsx` and `ReportViewer.jsx`
    - _Requirements: 11.1, 11.3, 11.4, 11.5, 12.4_

- [ ] 31. Frontend — Admin Management, Backup, and Settings
  - [ ] 31.1 Implement `AdminList.jsx` and `AdminForm.jsx` (Super_Admin only)
    - _Requirements: 2.1, 2.5, 2.6_

  - [ ] 31.2 Implement `BackupRestore.jsx`
    - _Requirements: 13.1, 13.3, 13.5_

  - [ ] 31.3 Implement `Settings.jsx`
    - _Requirements: 14.1, 14.3_

- [ ] 32. Accounting period UI — Month_Close and reopen
  - [ ] 32.1 Implement month-close workflow in `Settings.jsx` or dedicated `MonthClose.jsx`
    - _Requirements: 4.1, 4.3, 4.4, 4.7, 4.8_

- [ ] 33. Integration and route wiring — frontend
  - [ ] 33.1 Wire all React Router routes in `App.jsx`
    - _Requirements: 2.6, 15.7_

  - [ ] 33.2 Implement React Error Boundary and global toast notifications
    - _Requirements: 9.5_

- [ ] 34. Checkpoint — full frontend complete
  - Verify language switch works on every page without full reload
  - Verify Nepali Unicode text renders correctly in member names, addresses, and form fields

- [ ] 35. Integration testing — PHPUnit
  - [ ]* 35.1 Write `MonthCloseIntegrationTest.php`
    - _Requirements: 4.3, 6.2_

  - [ ]* 35.2 Write `BulkCollectionIntegrationTest.php`
    - _Requirements: 5.2, 5.4_

  - [ ]* 35.3 Write `DistributionIntegrationTest.php`
    - _Requirements: 10.4_

  - [ ]* 35.4 Write `AuditLogImmutabilityTest.php`
    - _Requirements: 12.3_

- [ ] 36. Final checkpoint — all tests pass
  - Verify all 16 requirements have at least one passing test or covered integration path
  - Verify all 17 correctness properties have a corresponding property-based test

---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Checkpoints (tasks 13, 21, 34, 36) ensure incremental validation at major milestones
- All monetary arithmetic uses `DECIMAL(15,2)` in MySQL and `PHP_ROUND_HALF_UP` in PHP
- BS↔AD dual date storage: BS columns for period filtering/display; AD `DATE` column for SQL ordering
- The UNIQUE constraint on `(member_id, accounting_period_id)` in `saving_transactions` acts as a database-level duplicate guard
- `audit_logs` is protected by MySQL `BEFORE UPDATE` / `BEFORE DELETE` triggers
- Distribution is a two-step flow (generate PDF → confirm) matching the cooperative's physical meeting workflow

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["2.1", "3.1", "3.2", "3.3", "3.4"] },
    { "id": 1, "tasks": ["2.2", "4.1", "4.2", "4.4", "7.1", "7.2"] },
    { "id": 2, "tasks": ["4.3", "5.1", "5.2", "6.1", "7.3", "7.4"] },
    { "id": 3, "tasks": ["5.3", "5.4", "5.5", "6.2", "8.1", "8.2"] },
    { "id": 4, "tasks": ["8.3", "8.4", "8.5", "9.1", "10.1", "11.1"] },
    { "id": 5, "tasks": ["9.2", "9.6", "10.2", "10.4", "11.2", "11.4", "12.1", "12.2"] },
    { "id": 6, "tasks": ["9.3", "9.4", "9.5", "10.3", "11.3", "12.3", "12.4", "12.5"] },
    { "id": 7, "tasks": ["14.1", "15.1", "17.1", "17.2", "18.1", "18.2", "19.1", "19.2", "20.1"] },
    { "id": 8, "tasks": ["15.2", "15.4", "15.5", "16.1", "16.3", "16.4"] },
    { "id": 9, "tasks": ["15.3", "16.2", "22.1", "22.2", "22.3"] },
    { "id": 10, "tasks": ["22.4", "22.5", "22.6", "22.7"] },
    { "id": 11, "tasks": ["22.8", "23.1", "24.1"] },
    { "id": 12, "tasks": ["25.1", "25.2", "26.1", "27.1", "27.2", "28.1", "29.1", "30.1", "31.1", "31.2", "31.3"] },
    { "id": 13, "tasks": ["26.2", "29.2", "32.1", "33.1", "33.2"] },
    { "id": 14, "tasks": ["35.1", "35.2", "35.3", "35.4"] }
  ]
}
```
