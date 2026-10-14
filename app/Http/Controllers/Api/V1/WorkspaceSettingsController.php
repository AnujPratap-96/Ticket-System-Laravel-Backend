<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\CloudinaryService;
use App\Support\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Settings of the signed-in admin's own workspace (the Tenant row). Admin only.
 */
class WorkspaceSettingsController extends Controller
{
    // ---------------- reply-by-email channel ----------------

    public function emailChannel(): JsonResponse
    {
        return response()->json($this->emailState());
    }

    /** Turn the channel on (or rotate its secret). The secret is shown ONCE, inside the ready-made URL. */
    public function enableEmailChannel(): JsonResponse
    {
        $tenant = TenantContext::get();
        $secret = Str::random(40);
        $tenant->update(['inbound_secret' => $secret]);

        return response()->json($this->emailState() + ['secret' => $secret, 'webhook_url' => $this->url($tenant->slug, $secret)]);
    }

    public function disableEmailChannel(): JsonResponse
    {
        TenantContext::get()->update(['inbound_secret' => null]);

        return response()->json($this->emailState());
    }

    public function updateEmailChannel(Request $request): JsonResponse
    {
        $data = $request->validate(['department_id' => ['nullable', \App\Tenancy\TenantRule::exists('departments')]]);
        TenantContext::get()->update(['inbound_department_id' => $data['department_id'] ?? null]);

        return response()->json($this->emailState());
    }

    private function emailState(): array
    {
        $t = TenantContext::get()->fresh();

        return [
            'enabled' => $t->inbound_secret !== null && $t->inbound_secret !== '',
            'department_id' => $t->inbound_department_id,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'webhook_base' => $this->url($t->slug, null),
        ];
    }

    private function url(string $slug, ?string $secret): string
    {
        return rtrim(config('app.url'), '/').'/api/v1/webhooks/inbound-email?tenant='.$slug.($secret ? '&secret='.$secret : '');
    }

    // ---------------- security ----------------

    public function security(): JsonResponse
    {
        return response()->json(['require_two_factor' => (bool) TenantContext::get()->fresh()->require_two_factor]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $data = $request->validate(['require_two_factor' => ['required', 'boolean']]);

        // Do not let an admin lock themselves (and everyone else) out: they must have it first.
        if ($data['require_two_factor'] && ! $request->user()->hasTwoFactor()) {
            throw ValidationException::withMessages(['require_two_factor' => ['Turn on two-factor authentication for your own account first (My account), then require it for everyone.']]);
        }
        TenantContext::get()->update(['require_two_factor' => $data['require_two_factor']]);

        return $this->security();
    }

    // ---------------- branding ----------------

    public function branding(): JsonResponse
    {
        return response()->json($this->brandingState());
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
        ]);

        $clean = array_map(fn ($v) => is_string($v) ? (trim(strip_tags($v)) ?: null) : $v, $data);
        if (isset($clean['brand_color'])) {
            $clean['brand_color'] = strtolower($clean['brand_color']);
        }
        TenantContext::get()->update($clean);

        return response()->json($this->brandingState());
    }

    /** Step 1 of a logo change: signed parameters so the browser can upload straight to Cloudinary. */
    public function signLogo(CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($cloudinary->enabled(), 503, 'Logo uploads are not configured.');

        return response()->json($cloudinary->signAvatarUpload('deskflow/logos/'.TenantContext::id().'_'.bin2hex(random_bytes(8)), 'c_limit,w_600,h_240'));
    }

    /** Step 2: the browser reports the uploaded id; verify it with Cloudinary before trusting it. */
    public function setLogo(Request $request, CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($cloudinary->enabled(), 503, 'Logo uploads are not configured.');
        $tenant = TenantContext::get();
        $publicId = $request->validate(['public_id' => ['required', 'string', 'max:255']])['public_id'];

        // Only a logo uploaded under THIS workspace's own server-issued id may be attached.
        if (! preg_match('#^deskflow/logos/'.$tenant->id.'_[a-f0-9]{16}$#', $publicId)) {
            throw ValidationException::withMessages(['public_id' => ['Invalid logo reference.']]);
        }
        $asset = $cloudinary->fetchAsset($publicId, 'image', 'upload');
        if (! $asset) {
            throw ValidationException::withMessages(['public_id' => ['The uploaded logo could not be found. Please try again.']]);
        }
        if (($asset['bytes'] ?? 0) > CloudinaryService::AVATAR_MAX_BYTES || ! in_array(strtolower($asset['format'] ?? ''), CloudinaryService::AVATAR_FORMATS, true)) {
            $cloudinary->delete($publicId, 'image', 'upload');
            throw ValidationException::withMessages(['public_id' => ['Logos must be JPG, PNG or WEBP and under 2 MB.']]);
        }

        $old = $tenant->logo_public_id;
        $tenant->update(['logo_public_id' => $publicId]);
        if ($old && $old !== $publicId) {
            $cloudinary->delete($old, 'image', 'upload');
        }

        return response()->json($this->brandingState());
    }

    public function removeLogo(CloudinaryService $cloudinary): JsonResponse
    {
        $tenant = TenantContext::get();
        if ($old = $tenant->logo_public_id) {
            $tenant->update(['logo_public_id' => null]);
            $cloudinary->enabled() && $cloudinary->delete($old, 'image', 'upload');
        }

        return response()->json($this->brandingState());
    }

    private function brandingState(): array
    {
        $t = TenantContext::get()->fresh();
        $brand = Brand::for($t);

        return [
            'name' => $t->name, 'brand_color' => $t->brand_color, 'sender_name' => $t->sender_name, 'reply_to_email' => $t->reply_to_email,
            'logo_url' => $brand['logo_url'], 'default_color' => Brand::DEFAULT_COLOR, 'logo_uploads' => app(CloudinaryService::class)->enabled(),
        ];
    }

    public function activity(Request $request): JsonResponse
    {
        $rows = \App\Models\AdminAudit::with('user:id,name,email')->latest('id')->paginate(30);

        return response()->json($rows);
    }

    /** A full copy of the workspace's data for the admin (portability / leaving). Secrets and password hashes are left out. */
    public function export(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $slug = TenantContext::get()->slug;

        return response()->streamDownload(function () {
            $out = fn ($v) => print(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            echo '{"exported_at":'.json_encode(now()->toISOString()).',"workspace":'.json_encode(TenantContext::get()->only(['name', 'slug', 'created_at']));
            $parts = [
                'users' => fn () => \App\Models\User::query()->select(['id', 'name', 'email', 'role', 'organization_id', 'department_id', 'is_active', 'created_at']),
                'organizations' => fn () => \App\Models\Organization::query(),
                'departments' => fn () => \App\Models\Department::query(),
                'tickets' => fn () => \App\Models\Ticket::query(),
                'ticket_messages' => fn () => \App\Models\TicketMessage::query()->where('is_internal_note', false),
                'articles' => fn () => \App\Models\Article::query(),
                'canned_responses' => fn () => \App\Models\CannedResponse::query(),
                'tags' => fn () => \App\Models\Tag::query(),
            ];
            foreach ($parts as $name => $query) {
                echo ',"'.$name.'":[';
                $first = true;
                $query()->orderBy('id')->chunk(500, function ($rows) use (&$first, $out) {
                    foreach ($rows as $row) {
                        echo $first ? '' : ',';
                        $first = false;
                        $out($row);
                    }
                });
                echo ']';
            }
            echo '}';
        }, "workspace-{$slug}-export.json", ['Content-Type' => 'application/json']);
    }
}
