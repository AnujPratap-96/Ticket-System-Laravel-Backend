<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\OtpService;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Public self-service: a company creates its own workspace (emailed code, like customer sign-up). */
class WorkspaceSignupController extends Controller
{
    public function __construct(private OtpService $otp, private TenantProvisioner $provisioner) {}

    /** "Is this address free?" and, for sign-in pages, "what is this workspace called?" */
    public function show(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', strtolower($slug))->where('is_active', true)->first();
        abort_if(! $tenant, 404, 'We could not find that workspace.');

        $brand = \App\Support\Brand::for($tenant);

        return response()->json(['name' => $tenant->name, 'slug' => $tenant->slug, 'brand_color' => $tenant->brand_color, 'logo_url' => $brand['logo_url']]);
    }

    public function available(Request $request): JsonResponse
    {
        $slug = strtolower((string) $request->query('slug'));

        return response()->json(['slug' => $slug, 'available' => (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug) && ! $this->provisioner->taken($slug)]);
    }

    public function register(Request $request): JsonResponse
    {
        abort_unless(config('tenancy.self_signup'), 403, 'Creating workspaces is turned off. Ask the platform owner.');

        $data = $request->validate([
            'workspace_name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['nullable', 'string', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/'],
            'admin_name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default(), 'max:128', 'confirmed'],
        ]);

        $slug = $data['slug'] ?? $this->provisioner->suggestSlug($data['workspace_name']);
        abort_if($this->provisioner->taken($slug), 422, 'That workspace address is already taken. Choose another.');

        $this->otp->start('workspace', strtolower($data['email']), [
            'workspace_name' => $data['workspace_name'], 'slug' => $slug, 'admin_name' => $data['admin_name'], 'password_hash' => Hash::make($data['password']),
        ]);

        return response()->json(['message' => 'We emailed you a code. Enter it to create your workspace.', 'email' => strtolower($data['email']), 'slug' => $slug], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'string', 'size:6']]);
        $p = $this->otp->verify('workspace', strtolower($data['email']), $data['code']);

        abort_if($this->provisioner->taken($p['slug']), 422, 'That workspace address was taken while you were verifying. Start again with another address.');

        $tenant = $this->provisioner->create($p['workspace_name'], $p['slug'], $p['admin_name'], strtolower($data['email']), $p['password_hash']);

        return response()->json(['message' => 'Your workspace is ready.', 'tenant' => ['name' => $tenant->name, 'slug' => $tenant->slug, 'url' => $tenant->url('/login')]], 201);
    }
}
