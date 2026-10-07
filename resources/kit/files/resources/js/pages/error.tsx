import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { home } from '@/routes';

/**
 * The page `App\Http\ExceptionResponses` renders for 403, 404, 500 and 503 while debug is
 * off (exceptions.md). It has no layout, because a failed request may not have the user or
 * the navigation an app layout reads.
 */
export default function ErrorPage({ status }: { status: number }) {
    const { t } = useTranslation();
    const title = t(`errors.title_${status}`);

    return (
        <>
            <Head title={title} />
            <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 text-center">
                <p className="text-6xl font-semibold tracking-tight text-muted-foreground">
                    {status}
                </p>
                <div className="max-w-md space-y-2">
                    <h1 className="text-xl font-medium">{title}</h1>
                    <p className="text-sm text-muted-foreground">
                        {t(`errors.description_${status}`)}
                    </p>
                </div>
                <Button asChild variant="outline">
                    <Link href={home()}>{t('errors.back_home')}</Link>
                </Button>
            </div>
        </>
    );
}
