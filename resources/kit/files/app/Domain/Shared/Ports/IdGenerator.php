<?php

namespace App\Domain\Shared\Ports;

/**
 * ทางออกของ domain เวลาต้องสร้าง entity เป็นชุดโดยไม่รู้จำนวนล่วงหน้า —
 * ชั้น Domain แตะ Illuminate ไม่ได้ จึงขอ id ผ่าน interface นี้แทนการเรียก Str::uuid7() ตรง ๆ
 */
interface IdGenerator
{
    public function next(): string;
}
