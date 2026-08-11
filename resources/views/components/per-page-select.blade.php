@props(['paginator', 'field' => 'perPage'])

<div class="flex items-center gap-4 text-sm text-brand-slate">
    <div class="flex items-center gap-2">
        <span>Show</span>
        <select wire:model.live="{{ $field }}" class="rounded-md border-brand-border text-sm py-1">
            @foreach ([10, 25, 50, 100] as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </select>
        <span>per page</span>
    </div>

    @if ($paginator->total() > 0)
        <span>
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </span>
    @endif
</div>

<div>{{ $paginator->links() }}</div>
