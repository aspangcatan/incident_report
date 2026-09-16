<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show(Attachment $attachment)
    {
        $this->authorize('view', $attachment->attachable);

        return Storage::disk('local')->download($attachment->disk_path, $attachment->original_filename);
    }
}
