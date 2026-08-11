{{-- Each page-level Livewire component's own view already wraps itself in <x-app-layout>
     (matching the rest of the Breeze-generated views), so this Livewire page-component
     layout — resolved by convention at components.layouts.app — simply passes the
     already-complete HTML straight through without adding another wrapper. --}}
{{ $slot }}
