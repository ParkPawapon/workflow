# DB Schema Overview

Last updated: 25 March 2026

This file describes the current schema families used by the application. It is a working overview of production-relevant tables, not a full dump of every column in the database.

## 1. Identity, Roles, and System Configuration
### Core people and org structure
- `teacher`
  - primary user/person record used across the application
  - key fields used in code: `pID`, `fName`, `roleID`, `positionID`, `oID`, `telephone`, `status`
- `position`
  - organizational positions used for director/deputy/head/teacher decisions
- `faction`
  - organizational group ownership used in circulars and sender-side grouping when present

### System roles and compatibility
- `dh_roles`
- `dh_user_roles`
- legacy compatibility still uses `teacher.roleID` in many flows

### Global configuration
- `dh_status`
- `dh_year`
- `dh_version`
- `dh_exec_duty_logs`

## 2. Audit, Files, and Shared Workflow Infrastructure
### Audit
- `dh_logs`
  - security and workflow audit trail

### File storage metadata
- `dh_files`
- `dh_file_refs`

### Shared document infrastructure
These tables exist to support cross-module document abstractions and read tracking.
- `dh_documents`
- `dh_document_recipients`
- `dh_read_receipts`
- `dh_sequences`
- `dh_migrations`
- `dh_login_attempts`

## 3. Circular Workflow Tables
### Internal and external circulars
- `dh_circulars`
- `dh_circular_recipients`
- `dh_circular_inboxes`
- `dh_circular_routes`
- `dh_circular_announcements`

### Notes
- Internal and external circulars share the same primary document table.
- `dh_circulars.circularID` is the primary key. External source document numbers in `extBookNo` may repeat; the per-year internal receive sequence in `extReceiveSeq` remains unique.
- Inbox rows drive recipient-facing visibility.
- Route rows preserve forward/review/return history.

## 4. Memo Workflow Tables
- `dh_memos`
- `dh_memo_routes`

### Notes
- Memo attachments still use `dh_files` and `dh_file_refs`.
- Approval, return, sign, cancel, and archive behavior is encoded in service logic rather than separate state tables.

## 5. Orders Tables
- `dh_orders`
- `dh_order_recipients`
- `dh_order_inboxes`
- `dh_order_routes`

### Notes
- Orders use owner-side and inbox-side flows.
- Status transitions are `WAITING_ATTACHMENT -> COMPLETE -> SENT`.

## 6. Outgoing Registration Tables
- `dh_outgoing_letters`

### Notes
- Outgoing files are attached through `dh_file_refs`.
- External circular intake is related but is not stored in the same outgoing table.

## 7. Room Booking Tables
- `dh_rooms`
- `dh_room_bookings`

### Notes
- Room status availability is controlled from `dh_rooms.roomStatus`.
- Booking status is handled through a compatibility layer because schema values may differ between environments.
- The application converts room booking status values through helper functions in `src/Services/room/room-booking-utils.php`.

## 8. Vehicle Reservation Tables
- `dh_vehicles`
- `dh_vehicle_bookings`

### Notes
- Vehicle files are attached through `dh_files` and `dh_file_refs`.
- Approval and assignment fields are stored on the booking row.
- PDF generation depends on the booking being in an approved state.

## 9. Repair Workflow Tables
- `dh_repair_requests`

### Notes
- Repair attachments are stored through `dh_files` and `dh_file_refs`.
- Report, approval, and management pages all operate on the same request table.

## 10. Collation and Compatibility Note
The project currently operates against legacy tables that may not all share the same collation.

Practical implications:
- string joins against `dh_file_refs.moduleName`, `entityName`, and legacy string keys can fail if collation assumptions are wrong
- new queries should prefer existing table collations or explicitly collate/cast at the query boundary when needed
- schema cleanup should be planned carefully and validated across Docker and local MariaDB before global collation changes are attempted

## 11. Migration Rules
- add columns and tables through tracked migrations
- avoid destructive schema changes without a dedicated cleanup plan
- keep runtime-compatible defaults for legacy controllers and views
