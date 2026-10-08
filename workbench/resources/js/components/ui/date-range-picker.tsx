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
 * A date range, readable in the locale. A half range (one side only) shows that side alone.
 * With neither side it returns `''`, so the caller supplies its own placeholder.
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
    /** Shown on the button while nothing is picked */
    placeholder: string;
    /** Label of the clear button inside the popover */
    clearLabel: string;
    /** BCP 47. Used for both the calendar and the date format on the button */
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
 * A button that opens a calendar to pick a date range. The value goes up on every click (a half range too),
 * and whoever holds the state decides what to do with it. Clear sits in the popover, because the main button opens the calendar.
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
