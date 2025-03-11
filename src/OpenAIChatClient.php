<?php

class OpenAIChatClient
{
    private $serverUrl;
    private $apiKey;
    private $log;
    private $model;

    public function __construct(string $serverUrl, string $apiKey, string $model = "4o-mini")
    {
        $this->serverUrl = $serverUrl;
        $this->apiKey = $apiKey;
        $this->log = "promptLog.txt";
        $this->model = $model;
    }

    private function aiCurl(&$ch, $data)
    {
        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "api-key: {$this->apiKey}"
        ]);

        $jsonResponse = curl_exec($ch);

        return json_decode($jsonResponse, true);
    }

    /**
     * Sends a request to the OpenAI Azure API.
     *
     * @param array $messages A list of messages for the chat session.
     * @param int $maxTokens Maximum number of tokens for the response.
     * @param float $temperature Randomness of the response (0.0 to 1.0).
     * @return array The API response as an associative array, or null on error.
     */
    public function chat(array $messages, int $maxTokens = 1024, float $temperature = 0.7): ?array
    {
        // Log Prompts
        $logFileHandle = fopen($this->log, 'a');
        fputs($logFileHandle, json_encode($messages) . "\n");
        fclose($logFileHandle);

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature
        ];

        $ch = curl_init();
        $response = $this->aiCurl($ch, $data);

        if (isset($response['error']) && $response['error']['code'] == '429') {
            echo "Rate limit exceeded. Sleeping for 60 seconds...\n";
            sleep(60);
            $response = $this->aiCurl($ch, $data);

            // if sleep didn't fix, just die
            // @TODO slop
            if (isset($response['error']) && $response['error']['code'] == '429') {
                exit;
            }
        } else if (isset($response['error'])) {
            echo "Error in AI response\n";
            var_dump($response);
        }

        curl_close($ch);

        $messages[] = ['role' => 'assistant', 'content' => $response['choices'][0]['message']['content']];

        return [
            $response['choices'][0]['message']['content'],
            $messages
        ];
    }

//    /**
//     * Enforce a valid JSON response
//     * @param array $messages
//     * @return void
//     */
//    public function chatJSON(array $messages) {
//        $response = chat($messages);
//
//        try {
//            $object = json_decode($response);
//        } catch (Exception $e) {
//            while ($object == null) {
//                $messages =
//                $response = chat($messages);
//            }
//        }
//
//    }
}
