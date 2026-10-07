<?php

namespace App\Http;

/**
 * Payload ของ flash `toast` ที่ฝั่งเว็บเอาไปแสดงด้วย sonner
 *
 * หัวข้อถูกแปลตรงนี้ เพราะ hook ที่รับ flash อยู่นอก Inertia context (`withApp()`)
 * และ redirect กลับ URL เดิมเป็น replace swap ที่ไม่ยิง `navigate` — ฝั่งเว็บจึงไม่มี
 * catalogue คำแปลที่เชื่อถือได้ให้แปลเอง
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
