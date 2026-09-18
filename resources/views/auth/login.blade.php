<x-layouts::auth :title="__('Log in')">
    <p class="kicker-sans mt-9 text-center text-fg-faint uppercase">{{ __('Sign in') }}</p>

    <h1 class="mt-2 text-center text-display leading-tight font-medium tracking-display text-fg">
        {{ __('Welcome back') }}
    </h1>

    <p class="mt-2 text-center text-sm leading-relaxed text-fg-dim">
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
