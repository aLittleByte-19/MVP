<?php

namespace App\Filament\Resources\CommunicationResource\Pages;

use App\Enums\CommunicationStatus;
use App\Filament\Resources\CommunicationResource;
use App\Services\BedrockService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCommunication extends CreateRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $bedrock = app(BedrockService::class);

        $generated = $bedrock->generateCommunication($data['prompt'], $data['tone'], $data['style']);

        return static::getModel()::create([
            'prompt' => $data['prompt'],
            'tone' => $data['tone'],
            'style' => $data['style'],
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'status' => CommunicationStatus::Draft,
        ]);
    }
}
