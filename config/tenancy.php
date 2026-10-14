<?php

return [
    /*
    | Wildcard domain for workspaces: with "deskflow.example.com", the workspace "acme" lives at
    | acme.deskflow.example.com. Leave empty to identify the workspace with the X-Tenant header / ?tenant= instead
    | (what local development and a single Vercel domain use).
    */
    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    /*
    | When there is exactly ONE workspace and the request does not say which, use it. Convenient for a single-company
    | install and for local development; it switches itself off the moment a second workspace exists.
    */
    'single_tenant_fallback' => (bool) env('TENANCY_SINGLE_FALLBACK', true),

    // Anyone may create a workspace from the public sign-up page (with an emailed code). Turn off to create them only from the platform console.
    'self_signup' => (bool) env('TENANCY_SELF_SIGNUP', true),
];
