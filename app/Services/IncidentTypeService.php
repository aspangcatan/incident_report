<?php

namespace App\Services;

use App\DataTransferObjects\IncidentTypes\IncidentTypeData;
use App\Models\IncidentType;

class IncidentTypeService
{
    public function create(IncidentTypeData $data): IncidentType
    {
        return IncidentType::create($data->toAttributes());
    }

    public function update(IncidentType $type, IncidentTypeData $data): IncidentType
    {
        $type->update($data->toAttributes());

        return $type;
    }

    /** Callers must check the 'delete' policy first: a type in use can't be deleted. */
    public function delete(IncidentType $type): void
    {
        $type->delete();
    }
}
