<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignUploadRequest;
use App\Models\Ticket;
use App\Services\AttachmentService;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;

class AttachmentController extends Controller
{
    public function sign(SignUploadRequest $request, CloudinaryService $cloudinary): JsonResponse
    {
        abort_unless($cloudinary->enabled(), 503, 'File uploads are not configured.');

        $user = $request->user();

        if ($request->filled('ticket_id')) {
            $ticket = Ticket::findOrFail($request->ticket_id);
            $this->authorize('reply', $ticket);
            $folder = AttachmentService::ticketFolder($ticket);
        } else {
            $folder = AttachmentService::pendingFolder($user);
        }

        return response()->json($cloudinary->signUpload($folder));
    }
}
