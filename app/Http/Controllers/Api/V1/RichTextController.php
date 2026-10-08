<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RichText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RichTextController extends Controller
{
    /** The reply box's "Preview" tab: exactly what recipients will see. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['present', 'nullable', 'string', 'max:20000']]);

        return response()->json(['html' => RichText::toHtml($data['body'])]);
    }
}
