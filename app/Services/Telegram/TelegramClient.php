<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends messages through the Telegram Bot API, one way: the bot is only the
 * sender, nothing reads replies. Set up in config/services.php (telegram).
 *
 * The bot token is part of every request URL, so no error from here ever
 * carries the URL or the exception that would contain it.
 */
class TelegramClient
{
    private const TIMEOUT_SECONDS = 10;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const RETRIES = 2;
    private const RETRY_DELAY_MS = 500;
    private const PARSE_MODE = 'HTML';

    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * @param string $html Telegram HTML (b, i, code, a...); the caller escapes any values in it
     *
     * @throws RuntimeException when Telegram cannot be reached or refuses the message
     */
    public function sendMessage(string $chatId, string $html): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => self::PARSE_MODE,
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * The chats that have recently messaged the bot, once each, to find a chat id.
     *
     * @return list<array{id: string, name: string, type: string}>
     *
     * @throws RuntimeException when Telegram cannot be reached or refuses
     */
    public function recentChats(): array
    {
        return collect($this->call('getUpdates'))
            ->map(fn (array $update) => ($update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? [])['chat'] ?? null)
            ->filter()
            ->unique('id')
            ->map(fn (array $chat) => [
                'id' => (string) $chat['id'],
                'name' => trim(($chat['title'] ?? '') ?: (($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))),
                'type' => (string) ($chat['type'] ?? ''),
            ])
            ->values()
            ->all();
    }

    private function call(string $method, array $payload = []): array
    {
        try {
            $response = $this->request()->post('bot' . config('services.telegram.bot_token') . '/' . $method, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException(__('telegram.unreachable'));
        }

        if (!$response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException(__('telegram.refused', ['reason' => $response->json('description') ?? $response->status()]));
        }

        return (array) $response->json('result', []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.telegram.api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->retry(self::RETRIES, self::RETRY_DELAY_MS, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
