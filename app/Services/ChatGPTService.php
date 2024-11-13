<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;

class ChatGPTService
{
    public function __construct()
    {

    }

    /**
     * @param array $messages
     * @param string $model
     * @return array
     */
    public function create(array $messages, string $model): array
    {
        try {
            $stream = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages
            ]);
            return [
                'success' => true,
                'data' => $stream
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
