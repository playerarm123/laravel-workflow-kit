import { ImageOff } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    src: string | null;
    alt: string;
    className?: string;
};

/**
 * An image of the library, with a placeholder in place of the browser's broken-image icon when
 * there is none or the file cannot load.
 */
export function MediaImage({ src, alt, className }: Props) {
    const [failedSrc, setFailedSrc] = useState<string | null>(null);

    if (src === null || src === failedSrc) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center bg-muted text-muted-foreground',
                    className,
                )}
                aria-label={alt}
                role="img"
            >
                <ImageOff className="size-5" />
            </div>
        );
    }

    return (
        <img
            src={src}
            alt={alt}
            loading="lazy"
            className={cn('object-cover', className)}
            onError={() => setFailedSrc(src)}
        />
    );
}
