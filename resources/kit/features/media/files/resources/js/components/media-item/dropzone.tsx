import { UploadCloud } from 'lucide-react';
import type { DragEvent } from 'react';
import { useRef, useState } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** The files dropped or picked; the caller keeps and filters them. */
    onFiles: (files: File[]) => void;
    /** The file input's `accept`, such as `.jpg,.png`. */
    accept: string;
    multiple?: boolean;
    disabled?: boolean;
    hint: string;
};

/**
 * A box to drop files on or click to pick them. It holds no files itself: a dropped file cannot
 * be put back into an `<input type="file">` in every browser, so the caller keeps the queue.
 */
export function Dropzone({
    onFiles,
    accept,
    multiple = true,
    disabled = false,
    hint,
}: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);

    const emit = (list: FileList | null) => {
        if (list === null || list.length === 0) {
            return;
        }

        onFiles(multiple ? Array.from(list) : [list[0]]);
    };

    const onDrop = (event: DragEvent<HTMLButtonElement>) => {
        event.preventDefault();
        setIsDragging(false);

        if (!disabled) {
            emit(event.dataTransfer.files);
        }
    };

    const onDragOver = (event: DragEvent<HTMLButtonElement>) => {
        event.preventDefault();

        if (!disabled && !isDragging) {
            setIsDragging(true);
        }
    };

    return (
        <>
            <button
                type="button"
                disabled={disabled}
                onClick={() => inputRef.current?.click()}
                onDrop={onDrop}
                onDragOver={onDragOver}
                onDragLeave={() => setIsDragging(false)}
                className={cn(
                    'flex w-full flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed bg-card p-8 text-sm text-muted-foreground transition-colors',
                    'hover:border-primary/60 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    isDragging && 'border-primary bg-primary/5 text-foreground',
                    disabled && 'cursor-not-allowed opacity-60',
                )}
            >
                <UploadCloud className="size-8" />
                <span>{hint}</span>
            </button>
            <input
                ref={inputRef}
                type="file"
                accept={accept}
                multiple={multiple}
                className="hidden"
                data-test="media-file-input"
                onChange={(event) => {
                    emit(event.target.files);
                    /** Cleared, so picking the same file again still fires onChange. */
                    event.target.value = '';
                }}
            />
        </>
    );
}
