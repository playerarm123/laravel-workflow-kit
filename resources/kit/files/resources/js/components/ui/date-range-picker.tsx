import { CalendarIcon, XIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';
import { enUS, th } from 'react-day-picker/locale';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * ช่วงวันแบบอ่านง่ายตาม locale — ครึ่งช่วง (มีแค่ฝั่งเดียว) โชว์ฝั่งนั้นฝั่งเดียว
 * ไม่มีเลยคืน `''` ให้คนเรียกใส่ placeholder เอง
 */
export function formatDateRange(
    from: Date | undefined,
    to: Date | undefined,
    locale: string,
): string {
    const formatter = new Intl.DateTimeFormat(locale, { dateStyle: 'medium' });

    if (from === undefined) {
        return to === undefined ? '' : formatter.format(to);
    }

    if (to === undefined) {
        return formatter.format(from);
    }

    return formatter.formatRange(from, to);
}

type Props = {
    value: DateRange | undefined;
    onChange: (range: DateRange | undefined) => void;
    /** โชว์บนปุ่มเมื่อยังไม่ได้เลือก */
    placeholder: string;
    /** ป้ายปุ่มล้างในตัว popover */
    clearLabel: string;
    /** BCP 47 — ใช้ทั้งปฏิทินและรูปแบบวันที่บนปุ่ม */
    locale: string;
    className?: string;
};

const CALENDAR_LOCALES = { th, en: enUS } as const;

function calendarLocaleOf(locale: string) {
    return locale in CALENDAR_LOCALES
        ? CALENDAR_LOCALES[locale as keyof typeof CALENDAR_LOCALES]
        : enUS;
}

/**
 * ปุ่มที่เปิดปฏิทินเลือกช่วงวัน — ค่าถูกส่งขึ้นไปทุกคลิก (ครึ่งช่วงก็ส่ง) คนถือ state
 * เป็นคนตัดสินว่าจะเอาไปทำอะไร ปุ่มล้างอยู่ใน popover เพราะปุ่มหลักต้องเปิดปฏิทิน
 */
export function DateRangePicker({
    value,
    onChange,
    placeholder,
    clearLabel,
    locale,
    className,
}: Props) {
    const label =
        value === undefined ? '' : formatDateRange(value.from, value.to, locale);

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        'justify-start font-normal',
                        label === '' && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarIcon className="size-4" />
                    {label === '' ? placeholder : label}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                    mode="range"
                    numberOfMonths={2}
                    selected={value}
                    defaultMonth={value?.from}
                    onSelect={onChange}
                    locale={calendarLocaleOf(locale)}
                />
                {value !== undefined && (
                    <div className="flex justify-end border-t border-border p-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onChange(undefined)}
                        >
                            <XIcon className="size-4" />
                            {clearLabel}
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
