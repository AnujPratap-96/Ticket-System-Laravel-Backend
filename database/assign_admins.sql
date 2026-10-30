-- ============================================================
-- DeskFlow: Assign Platform Admin & TechCorp Tenant Admin
-- Run this directly in your Supabase SQL Editor if needed.
-- ============================================================

-- 1. Ensure TechCorp workspace exists
INSERT INTO tenants (name, slug, is_active, created_at, updated_at)
VALUES ('TechCorp Support', 'techcorp', true, NOW(), NOW())
ON CONFLICT (slug) DO UPDATE
SET is_active = true, updated_at = NOW();

-- 2. Platform Admin: officialthakur94@gmail.com (Role: super_admin, tenant_id: NULL)
DO $$
DECLARE
    v_platform_email TEXT := 'officialthakur94@gmail.com';
    v_user_id BIGINT;
    -- Bcrypt hash for 'Password123!' (cost 12)
    v_default_pw TEXT := '$2y$12$EdDslZCtGj8MKx21fvTsGOmBuNkU4x.umekkTzqaoIGJ/cbdE9mm.';
BEGIN
    SELECT id INTO v_user_id FROM users WHERE LOWER(email) = LOWER(v_platform_email) LIMIT 1;

    IF v_user_id IS NOT NULL THEN
        UPDATE users
        SET role = 'super_admin',
            tenant_id = NULL,
            is_active = true,
            email_verified_at = COALESCE(email_verified_at, NOW()),
            updated_at = NOW()
        WHERE id = v_user_id;
        RAISE NOTICE 'Updated existing user % to super_admin (tenant_id = NULL)', v_platform_email;
    ELSE
        INSERT INTO users (
            tenant_id, name, email, password, role, is_active,
            is_available_for_routing, email_verified_at, created_at, updated_at
        ) VALUES (
            NULL, 'Official Thakur (Platform Admin)', LOWER(v_platform_email),
            v_default_pw, 'super_admin', true, false, NOW(), NOW(), NOW()
        );
        RAISE NOTICE 'Created new super_admin user % with password Password123!', v_platform_email;
    END IF;
END $$;

-- 3. TechCorp Admin: thakurajsingh9@gmail.com (Role: admin, tenant_id: TechCorp)
DO $$
DECLARE
    v_techcorp_email TEXT := 'thakurajsingh9@gmail.com';
    v_techcorp_id BIGINT;
    v_user_id BIGINT;
    -- Bcrypt hash for 'Password123!' (cost 12)
    v_default_pw TEXT := '$2y$12$EdDslZCtGj8MKx21fvTsGOmBuNkU4x.umekkTzqaoIGJ/cbdE9mm.';
BEGIN
    SELECT id INTO v_techcorp_id FROM tenants WHERE slug = 'techcorp' LIMIT 1;
    SELECT id INTO v_user_id FROM users WHERE LOWER(email) = LOWER(v_techcorp_email) LIMIT 1;

    IF v_user_id IS NOT NULL THEN
        UPDATE users
        SET role = 'admin',
            tenant_id = v_techcorp_id,
            is_active = true,
            email_verified_at = COALESCE(email_verified_at, NOW()),
            updated_at = NOW()
        WHERE id = v_user_id;
        RAISE NOTICE 'Updated existing user % to TechCorp admin (tenant_id = %)', v_techcorp_email, v_techcorp_id;
    ELSE
        INSERT INTO users (
            tenant_id, name, email, password, role, is_active,
            is_available_for_routing, email_verified_at, created_at, updated_at
        ) VALUES (
            v_techcorp_id, 'Thakur Aj Singh (TechCorp Admin)', LOWER(v_techcorp_email),
            v_default_pw, 'admin', true, false, NOW(), NOW(), NOW()
        );
        RAISE NOTICE 'Created new TechCorp admin user % with password Password123!', v_techcorp_email;
    END IF;
END $$;

-- 4. Verification output: Show current assignments
SELECT id, name, email, role, tenant_id, is_active, email_verified_at 
FROM users 
WHERE LOWER(email) IN ('officialthakur94@gmail.com', 'thakurajsingh9@gmail.com');
