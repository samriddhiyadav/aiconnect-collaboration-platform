# Deployment Guide (TeamSphere)

TeamSphere is a PHP + MySQL role-based collaboration app.

## Recommended Quick Deploy: Railway

1. Push repository to GitHub.
2. Create Railway PHP service.
3. Add MySQL database service.
4. Configure env variables from `.env.example`.
5. Import `docs/schema.sql`.
6. Deploy and validate role dashboards and auth flow.

## Alternative: Render

- Deploy PHP web service.
- Attach managed MySQL.
- Configure same env vars and uploads path.

## Post-Deploy Checklist

- DB migration integrity
- role auth and access isolation
- document upload restrictions
- safe production error behavior
