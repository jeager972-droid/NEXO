-- ============================================================
-- FIX (BUG-8): jwt_blocklist RLS policies blocking logout/revocation
-- ============================================================
-- Problem: jwt_blocklist RLS policies require is_super_rector(), which
-- checks app.current_role. This variable is only set in requireAuth()
-- at the end of verification. However:
-- - revokeJwt() (called in /auth/logout) executes INSERT without
--   app.current_role set → RLS blocks INSERT silently
-- - isJwtRevoked() (called in verifyJwtToken() before requireAuth())
--   → RLS blocks SELECT → always returns false
--
-- Impact: Logout does not invalidate JWT in database. If Redis fails,
-- tokens from closed sessions remain valid until expiration.
--
-- Solution: Change policies to allow INSERT/SELECT for any connection,
-- since jwt_blocklist is a system table for JWT revocation, not user data.
-- ============================================================

-- Drop existing restrictive policies
DROP POLICY IF EXISTS jbl_select ON jwt_blocklist;
DROP POLICY IF EXISTS jbl_insert ON jwt_blocklist;

-- Create permissive policies for system operations
CREATE POLICY jbl_select ON jwt_blocklist FOR SELECT
    USING (true);

CREATE POLICY jbl_insert ON jwt_blocklist FOR INSERT
    WITH CHECK (true);
