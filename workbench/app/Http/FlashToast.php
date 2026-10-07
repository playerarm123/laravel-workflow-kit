<?php

namespace App\Http;

/**
 * The payload of the `toast` flash, which the web side shows with sonner.
 *
 * The title is translated here because the hook that reads the flash sits outside the Inertia context (`withApp()`),
 * and a redirect back to the same URL is a replace swap that fires no `navigate`. The web side therefore has no
 * reliable translation catalogue to translate with.
 *
 * @see resources/js/types/ui.ts FlashToast
 * @see resources/js/hooks/use-flash-toast.ts
 */
final class FlashToast
{
    public const KEY = 'toast';

    /**
     * @return array{type: string, title: string, message: string}
     */
    public static function success(string $message): array
    {
        return self::make('success', $message);
    }

    /**
     * @return array{type: string, title: string, message: string}
     */
    public static function error(string $message): array
    {
        return self::make('error', $message);
    }

    /**
     * @return array{type: string, title: string, message: string}
     */
    private static function make(string $type, string $message): array
    {
        return [
            'type' => $type,
            'title' => __("common.toast_{$type}_title"),
            'message' => $message,
        ];
    }
}
