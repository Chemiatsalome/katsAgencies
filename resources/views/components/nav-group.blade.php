@props(['label', 'active' => false, 'slug'])

{{--
    An accordion section for the sidebar: the label row is the toggle,
    its links collapse/expand under it instead of every section's full
    link list always being visible at once. Each group remembers its own
    open/closed state in localStorage (same convention the sidebar's own
    collapsed/expanded rail state already uses), and defaults open only
    when it contains the page currently being viewed — so landing on a
    page never hides the very link that's highlighted as current.

    When the sidebar itself is the icon-only rail (collapsed), the group's
    own open/closed state is ignored and its links always show (as icons)
    — collapsing the rail already reduces everything to icons; layering a
    second, independent collapse on top of that would hide links with no
    way to see them short of re-expanding the whole rail.
--}}
<div
    x-data="{
        open: (localStorage.getItem('nav-group-{{ $slug }}') ?? '{{ $active ? '1' : '0' }}') === '1',
    }"
    x-init="$watch('open', value => localStorage.setItem('nav-group-{{ $slug }}', value ? '1' : '0'))"
>
    <button
        type="button"
        @click="open = !open"
        x-show="!collapsed"
        x-cloak
        class="w-full flex items-center justify-between px-4 mb-1 text-[11px] font-semibold text-white/40 uppercase tracking-wider hover:text-white/70"
    >
        <span>{{ $label }}</span>
        <svg class="h-3 w-3 shrink-0 transition-transform" :class="{ '-rotate-90': !open }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div x-show="open || collapsed">
        {{ $slot }}
    </div>
</div>
