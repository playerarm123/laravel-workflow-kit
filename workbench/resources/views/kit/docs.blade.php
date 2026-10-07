<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $page['title'] ?? 'Docs' }} · Kit · {{ config('app.name') }}</title>

        {{-- Follows the system's colour scheme, as the structure screen does --}}
        <script>
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>

        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-background font-sans text-foreground antialiased">
        <header class="sticky top-0 z-10 flex items-center gap-6 border-b bg-background/95 px-6 py-3 backdrop-blur">
            <a href="{{ route('kit.docs') }}" class="font-semibold">Kit</a>
            <nav class="flex gap-4 text-sm">
                <a href="{{ route('kit.docs') }}" class="text-foreground">Docs</a>
                <a href="{{ route('kit.structure') }}" class="text-muted-foreground hover:text-foreground">Structure</a>
            </nav>
            <span class="ml-auto text-xs text-muted-foreground">Read from the workflow kit · local only</span>
        </header>

        <div class="mx-auto flex max-w-7xl gap-8 px-6">
            <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-60 shrink-0 overflow-y-auto py-6 md:block">
                <input
                    id="kit-docs-filter"
                    type="search"
                    placeholder="Filter rules"
                    class="mb-4 w-full rounded-md border bg-transparent px-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-ring"
                >
                <ul class="space-y-0.5 text-sm" id="kit-docs-rules">
                    @foreach ($guidelines as $guideline)
                        <li data-filter="{{ strtolower($guideline['title'].' '.$guideline['name'].' '.$guideline['summary']) }}">
                            <a
                                href="{{ route('kit.docs', ['page' => $guideline['name']]) }}"
                                @class([
                                    'block rounded-md px-3 py-1.5',
                                    'bg-muted font-medium text-foreground' => ($page['name'] ?? null) === $guideline['name'],
                                    'text-muted-foreground hover:bg-muted/60 hover:text-foreground' => ($page['name'] ?? null) !== $guideline['name'],
                                ])
                            >{{ $guideline['title'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </aside>

            <main class="min-w-0 flex-1 py-8">
                @if ($page === null)
                    <h1 class="mb-2 text-3xl font-semibold">Kit docs</h1>
                    <p class="mb-8 max-w-3xl leading-7 text-muted-foreground">
                        The rules every project of the kit follows, each enforced by the Architecture suite and read here from
                        <code translate="no" class="rounded bg-muted px-1 py-0.5 text-[0.85em]">playerarm123/laravel-workflow-kit</code> as it stands.
                        Every rule says what to do, what not to, why, and which check holds it.
                    </p>

                    <h2 class="mb-4 text-xl font-semibold">Rules</h2>
                    <div class="mb-12 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($guidelines as $guideline)
                            <a
                                href="{{ route('kit.docs', ['page' => $guideline['name']]) }}"
                                class="rounded-lg border p-4 transition hover:border-foreground/40 hover:bg-muted/40"
                            >
                                <div class="font-medium">{{ $guideline['title'] }}</div>
                                <div class="mt-1 line-clamp-3 text-sm text-muted-foreground">{{ $guideline['summary'] }}</div>
                            </a>
                        @endforeach
                    </div>

                    <h2 class="mb-4 text-xl font-semibold">Commands</h2>
                    <p class="mb-4 max-w-3xl text-sm text-muted-foreground">
                        Every piece is scaffolded, never hand-written. These are the generators and structure commands this project's console has.
                    </p>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Command</th>
                                    <th class="px-4 py-2 font-medium">What it does</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($commands as $command)
                                    <tr class="border-t align-top">
                                        <td translate="no" class="px-4 py-2 font-mono text-xs whitespace-nowrap">php artisan {{ $command['name'] }}</td>
                                        <td class="px-4 py-2 text-muted-foreground">{{ $command['description'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <article class="max-w-3xl leading-7 [&_a]:text-primary [&_a]:underline [&_a]:underline-offset-2 [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.85em] [&_h1]:mb-6 [&_h1]:text-3xl [&_h1]:font-semibold [&_h2]:mt-12 [&_h2]:mb-3 [&_h2]:scroll-mt-20 [&_h2]:border-b [&_h2]:pb-2 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-6 [&_h3]:mb-2 [&_h3]:font-semibold [&_li]:my-1 [&_ol]:my-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-3 [&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-4 [&_pre]:text-sm [&_pre]:leading-6 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_strong]:font-semibold [&_table]:my-4 [&_table]:block [&_table]:overflow-x-auto [&_table]:text-sm [&_td]:border [&_td]:px-3 [&_td]:py-2 [&_td]:align-top [&_th]:border [&_th]:bg-muted [&_th]:px-3 [&_th]:py-2 [&_th]:text-left [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6">
                        {!! $page['html'] !!}
                    </article>
                @endif
            </main>

            @if ($page !== null && $page['sections'] !== [])
                <nav class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-52 shrink-0 overflow-y-auto py-8 text-sm xl:block">
                    <div class="mb-2 font-medium">On this page</div>
                    <ul class="space-y-1">
                        @foreach ($page['sections'] as $section)
                            <li><a href="#{{ $section['id'] }}" class="text-muted-foreground hover:text-foreground">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
        </div>

        <script>
            document.getElementById('kit-docs-filter').addEventListener('input', (event) => {
                const term = event.target.value.trim().toLowerCase();

                document.querySelectorAll('#kit-docs-rules li').forEach((item) => {
                    item.hidden = term !== '' && !item.dataset.filter.includes(term);
                });
            });
        </script>
    </body>
</html>
