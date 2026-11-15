    <div>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-charcoal leading-tight">
                {{ __('Profile') }}
            </h2>
        </x-slot>

        <div class="p-6 space-y-6">
            <div class="max-w-xl p-4 sm:p-8 bg-white shadow-sm border border-brand-border sm:rounded-lg">
                <livewire:profile.update-profile-information-form />
            </div>

            <div class="max-w-xl p-4 sm:p-8 bg-white shadow-sm border border-brand-border sm:rounded-lg">
                <livewire:profile.update-password-form />
            </div>
        </div>
    </div>
