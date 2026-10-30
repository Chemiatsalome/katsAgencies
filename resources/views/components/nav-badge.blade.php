@props(['count'])

@if ($count > 0)
    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-danger text-white text-[10px] font-semibold">
        {{ $count > 9 ? '9+' : $count }}
    </span>
@endif
