@props([
    'addressProperty' => 'address',
    'latProperty' => null,
    'lonProperty' => null,
    'initialAddress' => '',
    'id' => 'location-autocomplete',
    'label' => 'Address / Location',
    'placeholder' => 'Start typing an address, estate or neighbourhood…',
])

<div
    x-data="locationAutocomplete({
        endpoint: @js(route('manage.geocoding.autocomplete')),
        addressProperty: @js($addressProperty),
        latProperty: @js($latProperty),
        lonProperty: @js($lonProperty),
        initial: @js($initialAddress),
    })"
    class="relative"
    @click.outside="open = false"
>
    <x-input-label :for="$id" :value="$label" />

    <input
        type="text"
        id="{{ $id }}"
        x-ref="input"
        x-model="query"
        @input="onInput()"
        @focus="if (suggestions.length > 0) open = true"
        @keydown.escape="open = false"
        @keydown.arrow-down.prevent="highlightNext()"
        @keydown.arrow-up.prevent="highlightPrev()"
        @keydown.enter.prevent="selectHighlighted()"
        @blur="setTimeout(() => { if (! confirmed) commitManual() }, 150)"
        autocomplete="off"
        placeholder="{{ $placeholder }}"
        {{ $attributes->merge(['class' => 'mt-1 block w-full rounded-md border-brand-border focus:border-teal focus:ring-teal']) }}
    >

    <div x-show="loading" x-cloak class="text-xs text-brand-slate mt-1">Searching…</div>
    <div x-show="errorMessage && !loading" x-cloak x-text="errorMessage" class="text-xs text-warning mt-1"></div>
    <div x-show="confirmed && !loading" x-cloak class="text-xs text-teal mt-1">
        Location confirmed from suggestions.
        <button type="button" @click="clearConfirmation()" class="underline hover:no-underline">Change</button>
    </div>

    <ul
        x-show="open && suggestions.length > 0"
        x-cloak
        class="absolute z-20 mt-1 w-full bg-white border border-brand-border rounded-md shadow-lg max-h-60 overflow-auto text-sm"
    >
        <template x-for="(suggestion, index) in suggestions" :key="index">
            <li
                @click="select(suggestion)"
                @mouseenter="highlightedIndex = index"
                :class="index === highlightedIndex ? 'bg-teal/10 text-charcoal' : 'text-brand-slate'"
                class="px-3 py-2 cursor-pointer hover:bg-teal/5"
                x-text="suggestion.label"
            ></li>
        </template>
    </ul>
</div>
