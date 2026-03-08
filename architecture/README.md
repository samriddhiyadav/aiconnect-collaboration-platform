# Architecture Notes

## Modules

- `src/auth`: login/session handling
- `src/admin`: admin controls and reporting
- `src/manager`: team-level operational views
- `src/employee`: task/document workflows
- `includes/db_connect.php`: PDO connection + query helpers

## Data Flow

1. User authenticates and role is resolved.
2. Route/module executes role-scoped logic.
3. Data fetched from MySQL and rendered in dashboard pages.
4. Upload workflows persist file metadata and storage references.
