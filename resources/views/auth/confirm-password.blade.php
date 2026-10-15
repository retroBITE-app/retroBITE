<x-layouts::auth :title="__('Confirm password')">
    <x-auth-header
        class="mt-4"
        :title="__('Confirm password')"
        :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
    />

    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-8 flex flex-col gap-4">
        @csrf

        <x-auth-session-status class="text-center" :status="session('status')" />

        <flux:input
            name="password"
            :label="__('Password')"
            type="password"
            icon="lock-closed"
            required
            autocomplete="current-password"
            :placeholder="__('Password')"
            viewable
        />

        <x-cta-button icon="lock-closed" data-test="confirm-password-button">
            {{ __('Confirm') }}
        </x-cta-button>
    </form>
</x-layouts::auth>
