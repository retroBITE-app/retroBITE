@php
    use App\Models\ConsoleSourceFolder;
    use App\Models\Game;
    use App\Models\Media;
    use App\Support\LibraryStorage;
    use Illuminate\Support\Number;

    // A different game's key art on every visit, and nothing at all on a host
    // with nothing big enough scraped yet. Same rule the route itself applies,
    // so the page never points at a backdrop it will refuse to serve.
    $backdrop = Media::query()->wallpaper()->exists() ? route('login.backdrop') : null;

    // These land on a page anyone can reach, which is what the setting is for.
    $stats = [];

    if (config('settings.login_show_stats')) {
        $games = Game::count();
        $consoles = ConsoleSourceFolder::consoles()->count();

        $stats = [
            [
                'value' => Number::format($games),
                'label' => trans_choice('game catalogued|games catalogued', $games),
            ],
            [
                'value' => (string) $consoles,
                'label' => trans_choice('console installed|consoles installed', $consoles),
            ],
        ];

        // The same reading the sidebar draws, so the figure somebody sees before
        // signing in is the one they see after. Dropped rather than dashed when
        // the mount cannot be read: a stat tile is a bare number over a caption,
        // with no label or track to hang an em dash on, and the row is centred
        // so two of them sit as well as three.
        $storage = LibraryStorage::current();

        if ($storage !== null) {
            $stats[] = [
                'value' => Number::fileSize($storage->used, 1),
                'label' => __('of :total used', ['total' => Number::fileSize($storage->total(), 1)]),
            ];
        }
    }
@endphp

<x-layouts::auth :title="__('Log in')" :backdrop="$backdrop" :stats="$stats">
    <p class="mt-4 text-center text-sm leading-relaxed text-fg-dim">
        {{ __('Your library, achievements and console files are waiting.') }}
    </p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 flex flex-col gap-4">
        @csrf

        @if ($errors->any())
            <p role="alert" class="rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm text-danger">
                {{ $errors->first() }}
            </p>
        @endif

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:input
            name="login"
            :label="__('Username or email')"
            :value="old('login')"
            type="text"
            icon="user"
            required
            autofocus
            autocomplete="username"
            :placeholder="__('Enter username')"
        />

        <flux:input
            name="password"
            :label="__('Password')"
            type="password"
            icon="lock-closed"
            required
            autocomplete="current-password"
            :placeholder="__('Enter password')"
            viewable
        />

        <button
            type="submit"
            data-test="login-button"
            class="mt-1 flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-accent-tint/55 bg-accent-tint/10 px-4 py-3 text-base text-accent transition-colors hover:bg-accent-tint/18 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-deep"
        >
            <flux:icon.arrow-right-end-on-rectangle variant="micro" />
            {{ __('Sign in') }}
        </button>
    </form>
</x-layouts::auth>
