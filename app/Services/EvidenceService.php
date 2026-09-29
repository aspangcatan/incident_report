<?php

namespace App\Services;

use App\Enums\EvidenceStage;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** Stores evidence files on an incident, tagged with who added them and at which stage. */
class EvidenceService
{
    /** Rules for one uploaded evidence file (same as the report form). */
    public const FILE_RULES = ['file', 'max:25600', 'mimes:pdf,png,jpg,jpeg,doc,docx'];

    /** @param  UploadedFile[]  $files */
    public function store(Incident $incident, User $uploader, array $files, EvidenceStage $stage, ?int $correctiveActionId = null): void
    {
        foreach ($files as $file) {
            $attachment = $incident->attachments()->make([
                'uploaded_by' => $uploader->id,
                'disk_path' => $file->store("incidents/{$incident->id}", 'local'),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'category' => 'evidence',
            ]);
            $attachment->forceFill(['stage' => $stage->value, 'corrective_action_id' => $correctiveActionId])->save();
        }
    }
}
