# Requirements Document

## Introduction

The Village Cooperative Management System (VCMS) is a web-based application designed for small village savings cooperatives in Nepal. It enables committee administrators to manage member savings, loans, repayments, interest calculations, cash/bank accounts, and end-of-cycle distributions. The system operates on a Nepali calendar (Bikram Sambat), supports both English and Nepali text, and enforces strict accounting-period controls. It is optimized for cooperatives of 50–500 members with a simple, minimal-click UI suitable for non-technical committee members.

---

## Glossary

- **VCMS**: Village Cooperative Management System — this software.
- **Admin**: An authenticated user with permission to manage cooperative data.
- **Super_Admin**: A privileged Admin who can reopen locked accounting months and manage other Admin accounts.
- **Member**: A cooperative participant identified by a permanent auto-generated Member ID.
- **Member_ID**: A permanent, auto-generated identifier in the format B000001, B000002, etc.
- **Cycle**: A complete savings-and-distribution lifecycle; the cooperative can run multiple sequential cycles.
- **Accounting_Period**: A single Nepali calendar month (BS year + month) that is either OPEN or CLOSED.
- **BS_Month**: A month in the Bikram Sambat (Nepali) calendar (Baishakh through Chaitra).
- **Month_Close**: The action of closing the current Accounting_Period, triggering interest calculation, locking the period, and opening the next one.
- **Saving_Transaction**: A record of a member's fixed monthly savings contribution.
- **Loan**: A disbursement of cooperative funds to a member, tracked with principal, interest rate, and repayment status.
- **Repayment**: A payment toward an outstanding Loan (principal only, interest only, or both).
- **Savings_Interest**: Compound interest at 12% per annum (1% per month) credited to a member's savings balance at Month_Close.
- **Distribution**: The end-of-cycle event where each member's net entitlement (Savings + Interest − Outstanding Loan) is calculated, documented, and paid out.
- **Distribution_Ledger**: A PDF document listing each member's Savings, Interest, Outstanding Loan, and Final Payable amount, with a signature column.
- **Cash_In_Hand**: The cooperative's physical cash balance.
- **Bank_Balance**: The cooperative's bank account balance.
- **Audit_Log**: An immutable record of every system action.
- **Session**: An authenticated PHP session for an Admin.
- **CSRF_Token**: A per-request token used to prevent cross-site request forgery.
- **Language_File**: A translation file containing all UI label strings in either English or Nepali.
- **Bulk_Collection_Screen**: The UI screen where an Admin selects multiple members and records their monthly savings in a single operation.

---

## Requirements

### Requirement 1: Admin Authentication

**User Story:** As an Admin, I want to securely log in and out of the VCMS, so that only authorized users can access cooperative data.

#### Acceptance Criteria

1. WHEN an Admin submits a valid username and password, THE Authentication_System SHALL create a PHP Session and redirect the Admin to the Dashboard.
2. WHEN an Admin submits an invalid username or password, THE Authentication_System SHALL reject the login, display an error message indicating invalid credentials, and record the failed attempt in the Audit_Log within 500ms.
3. WHILE a Session is active and no HTTP request has been received from that Session for more than 30 minutes, THE Authentication_System SHALL invalidate the Session and redirect the Admin to the login page.
4. WHERE the Admin selects "Remember Login", THE Authentication_System SHALL persist a secure, HttpOnly, SameSite=Strict cookie valid for 14 days.
5. WHEN an Admin returns to the application with a valid Remember Login cookie, THE Authentication_System SHALL automatically create a new PHP Session, rotate the cookie with a fresh 14-day expiry, redirect the Admin to the Dashboard, and record the auto-login event in the Audit_Log.
6. WHEN an Admin logs out, THE Authentication_System SHALL invalidate the Session, clear all session cookies, and record the logout in the Audit_Log.
7. WHEN an Admin submits the Change Password form with a current password that matches the stored hash, THE Authentication_System SHALL update the password hash using bcrypt with a cost factor of at least 10 and record the change in the Audit_Log.
8. WHEN an Admin submits the Change Password form with a current password that does not match the stored hash, THE Authentication_System SHALL reject the request and return a descriptive error indicating the current password is incorrect.
9. THE Authentication_System SHALL hash all passwords using bcrypt (cost factor ≥ 10) before storage; plaintext passwords SHALL never be stored.
10. WHEN an Admin accumulates 5 consecutive failed login attempts, THE Authentication_System SHALL lock that account for 15 minutes, reject further login attempts during the lockout period, and record the lockout event in the Audit_Log.
11. WHEN any state-changing form is submitted, THE CSRF_Protection_Layer SHALL validate the CSRF_Token; IF the token is absent or invalid, THEN THE CSRF_Protection_Layer SHALL reject the request with HTTP 403 and record the event in the Audit_Log.

---

### Requirement 2: Multi-Admin Management

**User Story:** As a Super_Admin, I want to create and manage Admin accounts, so that multiple committee members can operate the system.

#### Acceptance Criteria

1. THE Admin_Manager SHALL store each Admin record with the fields: Name (required), Username (required, unique), Password (bcrypt hash, cost ≥ 10), Phone (up to 15 characters), and Status (Active/Inactive).
2. WHEN a Super_Admin creates a new Admin, THE Admin_Manager SHALL record a User_Creation event in the Audit_Log including the new Admin's username and the Super_Admin's username.
3. WHEN a Super_Admin modifies an existing Admin record, THE Admin_Manager SHALL record a User_Modification event in the Audit_Log including the list of changed fields and the Super_Admin's username.
4. IF an Admin's Status is set to Inactive, THEN THE Authentication_System SHALL reject login attempts for that Admin and SHALL invalidate any active Session for that Admin within 60 seconds.
5. THE Admin_Manager SHALL enforce unique usernames; IF a duplicate username is submitted, THEN THE Admin_Manager SHALL return an error indicating the username is already taken and not save the record.
6. THE Admin_Manager SHALL enforce role-based access control; IF a non-Super_Admin submits a request to create, modify, or deactivate any Admin account, THEN THE API_Layer SHALL reject the request with HTTP 403.

---

### Requirement 3: Member Management

**User Story:** As an Admin, I want to add, edit, and search for members, so that the cooperative roster stays accurate.

#### Acceptance Criteria

1. WHEN a new Member is created, THE Member_Manager SHALL auto-generate a unique Member_ID in the format B followed by a zero-padded 6-digit sequence (e.g., B000001) that is permanent and never reused.
2. THE Member_Manager SHALL store each Member record with the fields: Member_ID, Full Name (required, up to 100 characters), Phone Number (required, 7–15 digits), Address (optional), Join Date (BS date, required), Status (Active/Inactive), and Notes (optional, up to 500 characters).
3. THE Member_Manager SHALL persist Full Name, Address, and Notes fields in utf8mb4 encoding with utf8mb4_unicode_ci collation to support both English and Nepali text.
4. WHEN an Admin searches by Member_ID, Name (partial/substring match), or Phone, THE Member_Manager SHALL return matching results within 300ms for a dataset of up to 500 members.
5. WHEN an Admin edits a Member record, THE Member_Manager SHALL update the record and write a Member_Edit event to the Audit_Log including the Admin's identity and the changed field names with their old and new values.
6. IF an Admin attempts to delete a Member who has existing Saving_Transactions, Loans, or Repayments, THEN THE Member_Manager SHALL reject the deletion and return a descriptive error naming the blocking records.
7. WHEN an Admin deletes a Member who has no existing Saving_Transactions, Loans, or Repayments, THE Member_Manager SHALL permanently remove the Member record and write a Member_Delete event to the Audit_Log including the Admin's identity.
8. WHEN an Admin changes a Member's Status, THE Member_Manager SHALL record the status change in the Audit_Log including the Admin's identity and the old and new Status values.
9. WHEN a Member create or update form is submitted with a required field missing or a Phone Number outside 7–15 digits, THE Member_Manager SHALL reject the request and return a descriptive validation error identifying the failing field.

---

### Requirement 4: Accounting Period and Month Workflow

**User Story:** As an Admin, I want to work within a single open Nepali calendar month at a time, so that all transactions are correctly period-stamped and historical data is immutable.

#### Acceptance Criteria

1. THE Accounting_Period_Manager SHALL ensure that exactly one Accounting_Period has status OPEN at any given time.
2. WHILE an Accounting_Period is OPEN, THE Transaction_Recorder SHALL assign every new Saving_Transaction, Loan, and Repayment to that Accounting_Period's BS year and month.
3. WHEN an Admin triggers Month_Close, THE Month_Close_Service SHALL execute the following steps atomically within a single database transaction: (a) validate that Cash_In_Hand equals the sum of all cash-in minus all cash-out transactions for the period and that no Saving_Transaction, Loan, or Repayment record in the period has an incomplete status; (b) calculate Savings_Interest for all active Members; (c) set the current Accounting_Period status to CLOSED; (d) open the next sequential BS month as a new Accounting_Period with status OPEN; (e) generate a month summary containing the count of Saving_Transactions, total savings amount, total interest credited, count of new Loans, total loan amount disbursed, total repayments received, and closing Cash_In_Hand and Bank_Balance; (f) write a Month_Close event to the Audit_Log.
4. IF the Month_Close validation in step (a) detects a blocking condition, THEN THE Month_Close_Service SHALL abort the entire operation, leave the Accounting_Period status unchanged, and return a descriptive error identifying the specific blocking condition.
5. WHEN the current Accounting_Period is Chaitra of year Y and Month_Close is triggered, THE Month_Close_Service SHALL open Baishakh of year Y+1 as the next Accounting_Period.
6. WHILE an Accounting_Period is CLOSED, THE Transaction_Recorder SHALL reject any attempt to add, edit, or delete transactions belonging to that period and return a descriptive error stating the period is closed.
7. IF a Super_Admin submits a month-reopen request with a stated reason for an Accounting_Period that is currently CLOSED, THEN THE Accounting_Period_Manager SHALL set that Accounting_Period status to OPEN, close any currently OPEN Accounting_Period, record the reason and Super_Admin identity in the Audit_Log, and ensure no other Accounting_Period remains OPEN simultaneously.
8. IF a Super_Admin submits a month-reopen request for an Accounting_Period that is already OPEN or the request contains no reason, THEN THE Accounting_Period_Manager SHALL reject the request and return a descriptive error.
9. IF Month_Close fails at any step after it has begun, THE Month_Close_Service SHALL roll back all database changes made during that Month_Close attempt and return the system to its pre-attempt state.

---

### Requirement 5: Bulk Monthly Savings Collection

**User Story:** As an Admin, I want to record savings for multiple members at once, so that monthly collection is fast and error-free.

#### Acceptance Criteria

1. WHEN the Bulk_Collection_Screen loads, THE Savings_Recorder SHALL display all Active members for the current OPEN Accounting_Period with a checkbox per member, a pre-filled savings amount equal to the cooperative's configured fixed monthly saving amount, and visual indicators (pre-checked checkboxes) for members who already have a Saving_Transaction for the current Accounting_Period.
2. WHEN an Admin submits the Bulk_Collection_Screen with one or more members selected, THE Savings_Recorder SHALL create one Saving_Transaction per selected member under the current Accounting_Period in a single database transaction and return a success response listing the count of records saved.
3. IF the Bulk_Collection_Screen is submitted with zero members selected, THEN THE Savings_Recorder SHALL reject the request and return a descriptive error indicating at least one member must be selected.
4. IF a Saving_Transaction already exists for a given Member in the current Accounting_Period, THEN THE Savings_Recorder SHALL skip that member and include the member's name in a duplicate-warning section of the response rather than creating a duplicate record.
5. WHEN the bulk save completes, THE Savings_Recorder SHALL write a single Audit_Log entry including the Admin's identity, the current Accounting_Period, the count of successfully recorded savings, and the count of duplicates skipped.

---

### Requirement 6: Savings Interest Calculation

**User Story:** As an Admin, I want the system to automatically calculate compound savings interest at Month_Close, so that member balances grow accurately without manual computation.

#### Acceptance Criteria

1. WHEN Month_Close is triggered, THE Month_Close_Service SHALL calculate Savings_Interest for each active Member using the formula: `interest = round(current_savings_balance × 0.01, 2)`, where `current_savings_balance` is the sum of all Saving_Transactions and prior Interest_Transactions for that Member in the current Cycle, and rounding is to 2 decimal places using half-up rounding.
2. WHEN Month_Close is triggered, THE Month_Close_Service SHALL create one Interest_Transaction per active Member whose savings balance is greater than zero, crediting the computed interest amount (as defined in criterion 1) to that Member's savings balance.
3. THE Month_Close_Service SHALL calculate Savings_Interest only as part of Month_Close and never at any other time.
4. WHEN Month_Close completes for a Member with a positive savings balance, THE Month_Close_Service SHALL ensure that the Member's recorded savings balance equals the pre-close balance plus the interest amount computed by the formula in criterion 1.
5. IF a Member has a savings balance of zero or negative at the time of Month_Close, THEN THE Month_Close_Service SHALL not create an Interest_Transaction for that Member.
6. WHEN Month_Close completes, THE Month_Close_Service SHALL write an Interest_Calculation event to the Audit_Log including the total number of Interest_Transactions created and the total interest amount credited.

---

### Requirement 7: Loan Management

**User Story:** As an Admin, I want to disburse loans to members and track their repayments, so that the cooperative's lending activities are fully recorded.

#### Acceptance Criteria

1. WHEN an Admin creates a Loan for an Active Member, THE Loan_Manager SHALL store the record with: Member_ID, Loan Amount (1–999,999,999), Loan Date (BS and English), Interest Rate (0.01%–100%), Remarks, and Status set to Outstanding.
2. WHEN a Loan is created and Cash_In_Hand is sufficient to cover the Loan Amount, THE Loan_Manager SHALL record the disbursement as a Cash_Out transaction reducing Cash_In_Hand by the Loan Amount and assign the transaction to the current OPEN Accounting_Period.
3. IF Cash_In_Hand is less than the Loan Amount at the time of disbursement, THEN THE Loan_Manager SHALL reject the loan creation and return a descriptive error indicating insufficient Cash_In_Hand.
4. WHEN an Admin records a Repayment of type Principal Only, THE Repayment_Recorder SHALL reduce the Loan's outstanding principal by the repayment amount, leave accrued interest unchanged, record the repayment amount as a Cash_In transaction, and assign the transaction to the current OPEN Accounting_Period.
5. WHEN an Admin records a Repayment of type Interest Only, THE Repayment_Recorder SHALL reduce the Loan's accrued interest by the repayment amount, leave outstanding principal unchanged, record the repayment amount as a Cash_In transaction, and assign the transaction to the current OPEN Accounting_Period.
6. WHEN an Admin records a Repayment of type Both, THE Repayment_Recorder SHALL apply the repayment amount first to accrued interest and then to outstanding principal, record the full repayment amount as a Cash_In transaction, and assign the transaction to the current OPEN Accounting_Period.
7. WHEN the outstanding principal of a Loan reaches zero, THE Loan_Manager SHALL automatically update the Loan Status to Completed and write a Loan_Completed event to the Audit_Log.
8. IF an Admin attempts to record a Repayment amount that exceeds the outstanding principal plus accrued interest, THEN THE Repayment_Recorder SHALL reject the repayment and return a descriptive validation error.
9. WHEN a Loan is created, THE Loan_Manager SHALL write a Loan_Disbursement event to the Audit_Log including Member_ID, Loan Amount, and the Admin's identity.
10. WHEN an Admin updates a Loan's Remarks, Interest Rate, or Status (excluding auto-completion), THE Loan_Manager SHALL write a Loan_Edit event to the Audit_Log including Member_ID, the changed fields with old and new values, and the Admin's identity.
11. WHEN an Admin sets a Loan Status to Cancelled, THE Loan_Manager SHALL write a Loan_Cancelled event to the Audit_Log, treat the outstanding balance as zero for Distribution calculations, and not adjust Cash_In_Hand for the cancellation.

---

### Requirement 8: Cash and Bank Management

**User Story:** As an Admin, I want to track Cash_In_Hand and Bank_Balance and record transfers between them, so that the cooperative's money is fully accounted for.

#### Acceptance Criteria

1. THE Cash_Bank_Manager SHALL carry forward the Cash_In_Hand balance and Bank_Balance from the previous Cycle when a new Cycle begins; balances are not reset to zero at Cycle start.
2. WHEN an Admin records a Cash → Bank transfer of amount A (where A is a positive value with up to 2 decimal places), THE Cash_Bank_Manager SHALL decrease Cash_In_Hand by A, increase Bank_Balance by A, and record the transfer as a transaction with UTC timestamp, Admin identity, and an Audit_Log entry, under the current OPEN Accounting_Period.
3. WHEN an Admin records a Bank → Cash transfer of amount A (where A is a positive value with up to 2 decimal places), THE Cash_Bank_Manager SHALL decrease Bank_Balance by A, increase Cash_In_Hand by A, and record the transfer as a transaction with UTC timestamp, Admin identity, and an Audit_Log entry, under the current OPEN Accounting_Period.
4. IF a Cash → Bank transfer amount is zero, negative, has more than 2 decimal places, or exceeds the current Cash_In_Hand balance, THEN THE Cash_Bank_Manager SHALL reject the transfer and return a descriptive error identifying the specific validation failure.
5. IF a Bank → Cash transfer amount is zero, negative, has more than 2 decimal places, or exceeds the current Bank_Balance, THEN THE Cash_Bank_Manager SHALL reject the transfer and return a descriptive error identifying the specific validation failure.
6. THE Cash_Bank_Manager SHALL record every deposit, withdrawal, and transfer under the current OPEN Accounting_Period.

---

### Requirement 9: Dashboard

**User Story:** As an Admin, I want a summary dashboard on login, so that I can quickly see the cooperative's current financial status.

#### Acceptance Criteria

1. WHEN the Dashboard loads, THE Dashboard_Service SHALL display the following summary cards fetched at page-load time: Total Active Members, Cash_In_Hand, Bank_Balance, Total Outstanding Loan amount, Current Accounting Month (BS name), Current Accounting Year (BS), and Current Cycle name.
2. THE Dashboard SHALL provide quick-action buttons that navigate directly to: Bulk Monthly Saving, New Loan, Repayment, Reports, Distribution, and Member Management.
3. WHEN the Dashboard loads, THE Dashboard_Service SHALL return a complete API response within 1 second of request receipt for datasets up to 500 members.
4. WHEN the Dashboard loads, THE Dashboard_Service SHALL display a Recent Activities panel showing the 10 most recent Audit_Log entries from the full Audit_Log in reverse-chronological order.
5. IF the Dashboard_Service fails to retrieve data due to a server error, THEN THE Dashboard SHALL display a descriptive error message and provide a retry option rather than showing stale or empty values silently.

---

### Requirement 10: Distribution

**User Story:** As an Admin, I want to generate a distribution ledger and confirm payout, so that the end-of-cycle settlement is documented and the system resets for the next cycle.

#### Acceptance Criteria

1. WHEN an Admin triggers Generate Distribution Ledger, THE Distribution_Service SHALL calculate for each Active Member within the current Cycle: Total Savings (sum of all Saving_Transactions in the Cycle), Total Interest (sum of all Interest_Transactions in the Cycle), Total Outstanding Loan (sum of outstanding principal of all Outstanding-status Loans in the Cycle), and Final Payable Amount = (Total Savings + Total Interest) − Total Outstanding Loan.
2. WHEN an Admin triggers Generate Distribution Ledger, THE Distribution_Service SHALL generate a PDF Distribution_Ledger containing: Member name, Member_ID, Savings total, Interest total, Outstanding Loan, Final Payable Amount, and a Signature column — formatted for A4 physical printing and signing.
3. IF a Member's Final Payable Amount is negative (Outstanding Loan exceeds Savings + Interest), THEN THE Distribution_Service SHALL set that Member's payout to zero, flag the row in the PDF with a visual indicator, and not redistribute the shortfall to other members.
4. WHEN an Admin confirms Distribution Completed, THE Distribution_Service SHALL atomically within a single database transaction: (a) create Distribution_Transactions for each member's Final Payable Amount (zero for flagged members); (b) reduce Cash_In_Hand by the total amount disbursed; (c) reset each member's savings and interest balances to zero for the new Cycle; (d) mark all Outstanding-status Loans as Completed; (e) open a new Cycle; (f) write a Distribution_Completed event to the Audit_Log including the Admin's identity, total amount disbursed, and member count.
5. IF the Distribution PDF has not been generated for the current Cycle before the Admin confirms Distribution Completed, THEN THE Distribution_Service SHALL reject the confirmation and return a descriptive error.
6. THE Distribution_Service SHALL never delete historical Cycle data; completed Cycles SHALL be archived and remain viewable.
7. WHEN a new Cycle begins after Distribution, THE Cash_Bank_Manager SHALL carry forward any remaining Cash_In_Hand and Bank_Balance to the new Cycle without resetting them.

---

### Requirement 11: Reports and Exports

**User Story:** As an Admin, I want to generate and export various reports, so that the cooperative's financial records are transparent and auditable.

#### Acceptance Criteria

1. THE Report_Engine SHALL support the following report types: Member Statement, Monthly Report, Loan Report, Cash Book, Bank Book, Savings Report, Interest Report, Audit Report, and Distribution Report.
2. WHEN a Member Statement is requested with a Member_ID and an optional BS date range, THE Report_Engine SHALL display all Saving_Transactions, Interest_Transactions, Loans, Repayments, and Distributions for that Member within the specified range, with a running balance column computed as the net cumulative balance (savings + interest − loan disbursements + repayments + distributions).
3. THE Report_Engine SHALL accept BS year/month range filters for all tabular reports (Monthly Report, Loan Report, Cash Book, Bank Book, Savings Report, Interest Report, Distribution Report) and apply those filters before returning results.
4. THE Report_Engine SHALL support export of every report to PDF and Excel (XLSX) formats and a print-optimized browser layout.
5. WHEN a report is exported, THE Report_Engine SHALL write a Report_Export event to the Audit_Log including report type, the date range filter applied, the Member_ID filter if applicable, and the Admin's identity.
6. WHEN a report query is executed for up to 500 members over a full BS year, THE Report_Engine SHALL return results within 3 seconds.

---

### Requirement 12: Audit Log

**User Story:** As a Super_Admin, I want every system action recorded immutably, so that the cooperative's operations are fully traceable.

#### Acceptance Criteria

1. THE Audit_Logger SHALL record an entry for every action: Login, Logout, Failed Login, Add/Edit/Delete Member, Monthly Saving, Loan Disbursement, Loan Repayment, Interest Calculation, Month Close, Distribution, Cash Deposit/Withdrawal, Bank Transfer, Backup, Restore, Settings Change, Password Change, User Creation, and User Modification.
2. THE Audit_Logger SHALL store each entry with: DateTime (UTC timestamp), Admin username (for Failed Login, the submitted string up to 100 characters), Action type, Description (human-readable detail, up to 500 characters), IP address (stored as "unavailable" if not resolvable), and Browser/User-Agent string (up to 255 characters, stored as "unavailable" if absent).
3. THE Audit_Log table SHALL be insert-only at the database level; no UPDATE or DELETE privileges SHALL be granted on this table.
4. WHEN a Super_Admin views the Audit Report, THE Report_Engine SHALL display Audit_Log entries filtered by AND-combination of date range, Admin username, and action type, ordered reverse-chronologically, paginated at 200 entries per page.
5. THE Audit_Logger SHALL complete each log write within 100ms.
6. IF an Audit_Log write fails, THEN THE Audit_Logger SHALL write the failure details to a filesystem log file and SHALL NOT block or roll back the primary action that triggered the log.

---

### Requirement 13: Backup and Restore

**User Story:** As an Admin, I want to create and restore database backups, so that cooperative data is protected against loss.

#### Acceptance Criteria

1. WHEN an Admin triggers a Backup, THE Backup_Service SHALL export the full MySQL database to a gzip-compressed SQL file named `backup_YYYYMMDD_HHMMSS.sql.gz` and record a Backup event in the Audit_Log including the filename, file size in bytes, and the Admin's identity.
2. THE Backup_Service SHALL store backup files in the `backend/public/uploads/backups/` directory.
3. WHEN an Admin triggers a Restore by uploading a backup file, THE Backup_Service SHALL validate that the file is a readable gzip-compressed SQL file before applying it; IF the validation passes, THEN THE Backup_Service SHALL prompt the Admin to confirm the destructive overwrite before proceeding.
4. IF the uploaded file is not a readable gzip-compressed SQL file, THEN THE Backup_Service SHALL reject the restore, return a descriptive error indicating the file is invalid, and make no changes to the database.
5. WHEN a Restore completes successfully, THE Backup_Service SHALL record a Restore event in the Audit_Log including the filename and the Admin's identity.

---

### Requirement 14: Language Support

**User Story:** As an Admin, I want to switch the UI between English and Nepali, so that committee members who are more comfortable in Nepali can use the system.

#### Acceptance Criteria

1. THE Language_Manager SHALL provide a language toggle control visible on every page that switches all UI labels — including button labels, table headers, navigation items, form field labels, validation messages, and error messages — between English and Nepali without a full page reload.
2. THE Language_Manager SHALL load all UI labels from Language_Files; no label text SHALL be hardcoded in PHP or React component source code.
3. WHEN the language is switched, THE Language_Manager SHALL persist the selected language in the current PHP Session so the preference is maintained across page navigations within that session.
4. THE Language_Manager SHALL default to English when no language preference exists in the current Session.
5. IF a translation key is missing from the active Language_File, THEN THE Language_Manager SHALL display the corresponding English label as a fallback rather than displaying a raw key or empty string.
6. THE Language_Manager SHALL support full Nepali Unicode text (utf8mb4) in all labels, member names, and address fields.

---

### Requirement 15: Security

**User Story:** As a Super_Admin, I want the system to enforce security controls at every layer, so that member data and cooperative funds are protected.

#### Acceptance Criteria

1. THE Database_Layer SHALL use PDO prepared statements for all database queries; string concatenation into SQL SHALL never be used.
2. WHEN a user input is received by the API_Layer, THE API_Layer SHALL validate it against expected type, format, length, and required-field rules before processing.
3. IF an input fails type, format, length, or required-field validation, THEN THE API_Layer SHALL reject the request and return a descriptive error response identifying the failing field and rule.
4. WHEN the API_Layer renders values in an HTML response, THE API_Layer SHALL HTML-encode those values; WHEN values appear in a JavaScript context, THE API_Layer SHALL JavaScript-encode them.
5. WHEN an Admin successfully logs in, THE Session_Manager SHALL regenerate the PHP session ID.
6. WHEN an Admin's role is elevated to Super_Admin within an active Session, THE Session_Manager SHALL regenerate the PHP session ID.
7. THE API_Layer SHALL enforce role-based access control; IF a non-Super_Admin submits a request for a Super_Admin-only action, THEN THE API_Layer SHALL reject the request with HTTP 403.
8. WHEN a state-changing request (POST, PUT, DELETE, PATCH) is received, THE API_Layer SHALL validate the CSRF_Token.
9. IF the CSRF_Token is absent or invalid on a state-changing request, THEN THE API_Layer SHALL reject the request with HTTP 403 and record the event in the Audit_Log.

---

### Requirement 16: Database Schema Standards

**User Story:** As a developer, I want every database table to follow a consistent standard, so that the schema is maintainable and auditable.

#### Acceptance Criteria

1. THE Database_Schema SHALL include the following columns in every table: `id` (BIGINT UNSIGNED AUTO_INCREMENT primary key), `created_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP), `updated_at` (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP), `created_by` (BIGINT UNSIGNED, nullable for system-generated rows), `updated_by` (BIGINT UNSIGNED, nullable for system-generated rows), and `status` (TINYINT UNSIGNED NOT NULL DEFAULT 1, where 1 = active and 0 = inactive unless the table defines its own enum values).
2. THE Database_Schema SHALL use `utf8mb4` character set and `utf8mb4_unicode_ci` collation on every table and every text column to support both English and Nepali content.
3. THE Database_Schema SHALL apply ON DELETE RESTRICT on all foreign key columns that reference tables with financial or audit history (members, loans, saving_transactions, repayments, audit_logs, distributions); ON DELETE CASCADE SHALL only be applied on pure child or lookup records with no independent financial history.
4. THE Database_Schema SHALL include indexes on all columns used in search, filter, or JOIN operations, specifically: member_id and phone on the members table (to meet the 300ms search benchmark from Requirement 3), bs_year and bs_month on the accounting_periods table, loan_status on the loans table, member_id and accounting_period_id on the saving_transactions table, created_at and admin_username and action_type on the audit_logs table, and cycle_id on all transaction tables.
5. THE Database_Schema SHALL use MySQL 8+ and be initializable from a single idempotent SQL migration script.
