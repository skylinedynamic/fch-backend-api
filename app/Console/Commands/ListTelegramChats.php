<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use RuntimeException;

/** Setup helper: after pressing Start on the bot, this shows the chat id for TELEGRAM_OWNER_CHAT_ID. */
class ListTelegramChats extends Command
{
    protected $signature = 'telegram:chat-ids';

    protected $description = 'List the chats that recently messaged the Telegram bot, to find TELEGRAM_OWNER_CHAT_ID';

    public function handle(TelegramClient $telegram): int
    {
        if (!$telegram->isConfigured()) {
            $this->error(__('telegram.no_token'));

            return self::FAILURE;
        }

        try {
            $chats = $telegram->recentChats();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($chats === []) {
            $this->warn(__('telegram.no_chats'));

            return self::SUCCESS;
        }

        $this->table(['Chat id', 'Name', 'Type'], array_map(fn (array $chat) => [$chat['id'], $chat['name'], $chat['type']], $chats));

        return self::SUCCESS;
    }
}
