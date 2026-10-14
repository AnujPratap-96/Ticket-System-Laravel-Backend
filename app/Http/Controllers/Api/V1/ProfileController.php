<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Ticket;
use App\Services\TicketRoutingService;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Change the display name. The email address is deliberately not editable here:
     * it is the login and is verified by a code, so changing it would need its own verified flow.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            // The "post" shown in the reply signature, e.g. "Senior Support Engineer"
            'job_title' => ['nullable', 'string', 'max:100'],
        ]);
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($data['name'])));

        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => ['Please enter a valid name.']]);
        }

        $changes = ['name' => $name];
        // Only touched when sent, so older clients that send just the name do not wipe it.
        if ($request->has('job_title')) {
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($data['job_title'] ?? ''))));
            $changes['job_title'] = $title !== '' ? $title : null;
        }

        $request->user()->update($changes);

        return response()->json(['message' => 'Profile updated', 'user' => new UserResource($request->user()->fresh('department'))]);
    }

    /**
     * Staff: mark yourself away (leave) until a date, or come back early (`away_until: null`). Automatic routing skips
     * you meanwhile. With `release_tickets`, your open tickets go back to the pool and are routed to teammates now.
     */
    public function availability(Request $request, TicketRoutingService $routing): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->isStaff(), 403, 'Only staff can set availability.');

        $data = $request->validate([
            'away_until' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays(180)->toDateString()],
            'release_tickets' => ['sometimes', 'boolean'],
        ]);

        $user->update(['away_until' => $data['away_until'] ?? null]);
        $moved = 0;

        if ($user->away_until && $request->boolean('release_tickets')) {
            Ticket::where('assigned_agent_id', $user->id)->whereIn('status', ['open', 'in_progress'])->get()->each(function (Ticket $t) use ($routing, $user, &$moved) {
                $t->update(['assigned_agent_id' => null]);
                $routing->autoRoute($t) && $moved++;
            });
        }

        return response()->json([
            'message' => $user->away_until ? 'You are marked as away.'.($moved ? " {$moved} of your tickets were handed to teammates." : '') : 'Welcome back. You will receive new tickets again.',
            'user' => new UserResource($user->fresh('department')),
            'handed_over' => $moved,
        ]);
    }

    /**
     * Step 1 of a photo change: signed parameters so the browser can upload straight to Cloudinary.
     * The public_id is chosen here (random suffix) so nobody can overwrite another person's photo.
     */
    public function signAvatar(Request $request, CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($cloudinary->enabled(), 503, 'Photo uploads are not configured.');

        $publicId = "deskflow/avatars/{$request->user()->id}_".bin2hex(random_bytes(8));

        return response()->json($cloudinary->signAvatarUpload($publicId));
    }

    /**
     * Step 2: the browser reports the uploaded public_id; we verify it with Cloudinary before trusting it.
     */
    public function setAvatar(Request $request, CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($cloudinary->enabled(), 503, 'Photo uploads are not configured.');
        $user = $request->user();

        $data = $request->validate(['public_id' => ['required', 'string', 'max:255']]);
        $publicId = $data['public_id'];

        // Only a photo uploaded under this user's own, server-issued id may be attached.
        if (! preg_match('#^deskflow/avatars/'.$user->id.'_[a-f0-9]{16}$#', $publicId)) {
            throw ValidationException::withMessages(['public_id' => ['Invalid photo reference.']]);
        }

        $asset = $cloudinary->fetchAsset($publicId, 'image', 'upload');
        if (! $asset) {
            throw ValidationException::withMessages(['public_id' => ['The uploaded photo could not be found. Please try again.']]);
        }
        if (($asset['bytes'] ?? 0) > CloudinaryService::AVATAR_MAX_BYTES || ! in_array(strtolower($asset['format'] ?? ''), CloudinaryService::AVATAR_FORMATS, true)) {
            $cloudinary->delete($publicId, 'image', 'upload');
            throw ValidationException::withMessages(['public_id' => ['Photos must be JPG, PNG or WEBP and under 2 MB.']]);
        }

        $old = $user->avatar_public_id;
        $user->update(['avatar_public_id' => $publicId]);

        if ($old && $old !== $publicId) {
            $cloudinary->delete($old, 'image', 'upload');   // do not leave orphaned photos behind
        }

        return response()->json(['message' => 'Photo updated', 'user' => new UserResource($user->fresh('department'))]);
    }

    public function removeAvatar(Request $request, CloudinaryService $cloudinary): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_public_id) {
            if ($cloudinary->enabled()) {
                $cloudinary->delete($user->avatar_public_id, 'image', 'upload');
            }
            $user->update(['avatar_public_id' => null]);
        }

        return response()->json(['message' => 'Photo removed', 'user' => new UserResource($user->fresh('department'))]);
    }
}
