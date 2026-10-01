<?php

namespace App\Services\FCH\Common\Notification;

/**
 * Fills {{placeholder}} tokens. Templates can come from the database, so this
 * is deliberately plain string substitution - nothing is ever evaluated.
 */
class TemplateRenderer
{
    private const PLACEHOLDER = '/\{\{\s*(.*?)\s*\}\}/';

    public function render(NotificationTypeDefinition $type, array $data): RenderedNotification
    {
        return new RenderedNotification(
            title: $this->fill($type->title, $data),
            body: $this->fill($type->body, $data),
            url: $this->url($type->url, $data),
            icon: $type->icon,
            category: $type->category,
        );
    }

    private function fill(string $template, array $data, bool $encode = false): string
    {
        return preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($data, $encode) {
            $value = data_get($data, $match[1]);
            $value = is_scalar($value) ? (string) $value : '';

            return $encode ? rawurlencode($value) : $value;
        }, $template);
    }

    /**
     * Links are frontend routes, so only an in-portal path ("/...") is kept;
     * placeholder values are URL-encoded so they cannot add path segments.
     */
    private function url(?string $template, array $data): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }

        $url = $this->fill($template, $data, true);

        return str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : null;
    }
}
