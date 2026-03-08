# Security Checklist

## Key Risks

- Role access control must be enforced consistently across all pages.
- File uploads can be abuse vectors.
- Verbose debug output must be disabled in production.

## Required Hardening

1. Apply centralized RBAC guard per route.
2. Enforce strict MIME/type/size checks for uploads.
3. Add CSRF protection for all mutating actions.
4. Keep sensitive error details in logs only.
5. Enforce HTTPS-only deployment.
