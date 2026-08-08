# Village Cooperative Management System (VCMS)

## Version
1.0

---

# Project Overview

Develop a modern web-based Cooperative Management System for small village savings groups in Nepal.

The software must be designed specifically for local village cooperatives where approximately 50–60 members save a fixed amount every month and may take loans from the cooperative.

This is NOT a banking system.

It is a cooperative management software with simple workflow suitable for village committee members.

The UI must be modern, fast, responsive and extremely easy to use.

---

# Technology Stack

## Backend

- Core PHP 8.2+
- REST API
- PDO
- MySQL 8+
- MVC Folder Structure (without framework)

Do NOT use Laravel or CodeIgniter.

---

## Frontend

- React
- Vite
- Tailwind CSS
- Axios
- React Router
- React Hook Form
- Lucide React Icons

No Bootstrap.

---

## Database

MySQL

Every table must use

utf8mb4

Collation

utf8mb4_unicode_ci

or

utf8mb4_0900_ai_ci

Must fully support:

English Names

Example

Ram Bahadur

Nepali Names

Example

राम बहादुर

---

# Main Features

- Multi Admin
- Secure Login
- Member Management
- Monthly Savings
- Loan Management
- Loan Repayment
- Compound Savings Interest
- Cash Management
- Bank Management
- Cash Transfer
- Reports
- Member Statement
- Distribution
- Audit Log
- Backup & Restore
- Nepali + English Language

---

# Authentication

- Login
- Logout
- Change Password

Password must be hashed.

Use PHP Sessions.

Session Timeout

Remember Login

Audit every login.

---

# Multi Admin

Multiple admins can use system.

Fields

- Name
- Username
- Password
- Phone
- Status

Password change supported.

---

# Audit Log

EVERY action must be logged.

Examples

Login

Logout

Failed Login

Add Member

Edit Member

Delete Member

Monthly Saving

Loan Disbursement

Loan Repayment

Interest Calculation

Month Close

Distribution

Cash Deposit

Cash Withdrawal

Bank Transfer

Backup

Restore

Settings Change

Password Change

User Creation

User Modification

Everything.

Log Fields

- DateTime
- Admin
- Action
- Description
- IP
- Browser

Audit Log cannot be edited.

---

# Language

Entire software must support

English

Nepali

Language switch available on top.

Every label must come from language files.

Do NOT hardcode text.

---

# Member

No KYC.

Fields

Member ID

Auto Generated

Format

B000001

B000002

B000003

Never changes.

Other Fields

- Full Name
- Phone Number
- Address (Optional)
- Join Date
- Status
- Notes

Support Nepali names.

Search by

Member ID

Name

Phone

---

# Dashboard

Cards

Total Members

Cash in Hand

Bank Balance

Outstanding Loan

Current Accounting Month

Current Accounting Year

Current Cycle

Quick Buttons

Monthly Saving

New Loan

Repayment

Reports

Distribution

Members

Recent Activities

Audit Summary

---

# Accounting Period

System uses Nepali Calendar.

Accounting Period

Year

2083

Month

Shrawan

Accounting is NOT based on English Month.

English Date is stored only for audit.

Only ONE accounting month can remain OPEN.

---

# Month Workflow

Example

Meeting held on

Second Tuesday

Shrawan

Admin enters

Monthly Savings

Loans

Repayments

Cash Transfer

Everything recorded under

Shrawan 2083

Nothing automatically changes.

Next meeting

Second Tuesday

Bhadra

Admin presses

Close Shrawan

Software

1 Calculate Interest

2 Lock Shrawan

3 Open Bhadra

Then admin starts Bhadra entries.

---

# Month Closing

One button.

Close Current Month

System automatically

Validate

Calculate Interest

Lock Month

Generate Summary

Audit Log

Open Next Month

If current month

Chaitra 2083

Next month automatically

Baishakh 2084

---

# Month Lock

Closed month

Cannot edit

Cannot delete

Cannot add transaction

Only view

Only Super Admin can reopen.

Reopen requires reason.

Audit log required.

---

# Savings

Fixed Monthly Saving

Example

1000

Admin should NEVER open each member individually.

Need Bulk Collection Screen.

Screen

Current Month

List of Members

Checkbox

Paid

Amount

Default Amount

1000

Admin selects paid members.

One click

Collect Savings

System inserts saving transaction for every selected member.

Prevent duplicate monthly saving.

---

# Savings Interest

Annual

12%

Fixed

Cannot be changed.

Monthly Rate

1%

Interest Type

Compound

Interest calculated ONLY when month closes.

Never automatically.

Example

Deposit

1000

Month End

Interest

10

Balance

1010

Next Month

Deposit

1000

Balance

2010

Interest

20.10

Every interest credit stored as transaction.

---

# Loan

Admin selects member.

One click

Disburse Loan.

Fields

Loan Amount

Loan Date

Remarks

Interest Rate

(Fixed by cooperative)

Loan Status

Outstanding

Completed

Cancelled

---

# Loan Repayment

Admin can receive

Principal Only

Interest Only

Both

Fields

Principal

Interest

Remarks

Save

Loan balance updates automatically.

---

# Cash

Maintain

Cash In Hand

---

# Bank

Maintain

Bank Balance

Admin can

Cash → Bank

Bank → Cash

Every transfer recorded.

---

# Reports

Member Statement

Monthly Report

Loan Report

Cash Book

Bank Book

Savings Report

Interest Report

Audit Report

Distribution Report

Export

PDF

Excel

Print

---

# Member Statement

Shows

All Savings

Interest Credits

Loans

Repayments

Distribution

Running Balance

---

# Distribution

When cooperative decides to distribute money.

Admin presses

Generate Distribution Ledger

Software

Calculates

Savings

Interest

Outstanding Loan

Final Amount

Generate PDF

Fields

Member

Savings

Interest

Loan

Final Payable

Signature

Secretary prints PDF.

Members receive money.

Members sign.

After meeting

Admin presses

Distribution Completed

Software

Archives cycle

Creates distribution transactions

Resets balances

Marks cycle completed

Audit Log

---

# Cycle

Software supports multiple cycles.

Cycle 1

Completed

Cycle 2

Active

Cycle 3

Future

History never deleted.

---

# Search

Fast search.

Search

Member ID

Phone

English Name

Nepali Name

---

# Notifications

Future feature.

Not required now.

---

# UI Requirements

Modern SaaS style.

Use

Rounded Cards

Tailwind

Clean Tables

Minimal Colors

Responsive

Desktop First

Tablet Support

Large Buttons

Simple Forms

No clutter.

---

# Tables

Every table

Search

Filter

Pagination

Export

Print

---

# Forms

Inline Validation

Required Fields

No page reload.

---

# Security

Prepared Statements

CSRF Protection

Password Hashing

Session Security

XSS Protection

Validation

Secure File Upload

Role Based Access

---

# Database Standard

Almost every table should include

id

created_at

updated_at

created_by

updated_by

status

---

# Folder Structure

backend

app

controllers

models

services

helpers

middleware

config

routes

api

public

uploads

logs

frontend

src

components

pages

layouts

hooks

services

utils

assets

---

# Coding Standards

Reusable Components

Reusable APIs

Proper Error Handling

No Duplicate Code

Meaningful Variable Names

Comment Complex Logic

Responsive UI

Clean Code

---

# Future Features

Member Login

SMS Notification

Android App

QR Member Card

Receipt Printing

Online Backup

Cloud Sync

Multiple Cooperatives

Role Based Permission

---

# Important Business Rules

1. Monthly saving amount is fixed.

2. Annual savings interest is fixed at 12%.

3. Interest is compounded monthly.

4. Interest is calculated only during Month Close.

5. Only one accounting month can remain open.

6. Closed months cannot be modified.

7. Every action must be logged.

8. Every transaction belongs to a Nepali accounting month.

9. English timestamp stored for auditing.

10. Distribution occurs only when admin chooses.

11. Distribution generates PDF first.

12. After physical distribution, admin confirms completion.

13. Member IDs are permanent.

14. Member IDs are auto generated.

15. Member IDs follow format:

B000001

B000002

B000003

16. Entire software supports English and Nepali.

17. Database must fully support Nepali text.

18. UI must require minimum clicks.

19. Bulk monthly saving entry is mandatory.

20. Software should be optimized for cooperatives with 50–500 members while remaining scalable for future growth.