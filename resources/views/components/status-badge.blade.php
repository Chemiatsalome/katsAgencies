@props(['status'])

@php
    $label = ucwords(str_replace('_', ' ', $status));

    $classes = match ($status) {
        'paid', 'active', 'resolved', 'approved', 'delivered', 'sent', 'good' => 'bg-success/10 text-success',
        'advance' => 'bg-teal/10 text-teal',
        'partial', 'due_soon', 'expiring_soon', 'pending', 'reserved', 'under_review', 'assigned', 'in_progress', 'fair', 'submitted', 'reviewed', 'scheduled' => 'bg-warning/10 text-warning',
        'overdue', 'failed', 'terminated', 'rejected', 'emergency', 'high', 'unavailable', 'needs_attention' => 'bg-danger/10 text-danger',
        'occupied' => 'bg-teal/10 text-teal',
        'ended', 'vacant', 'completed', 'closed', 'withdrawn', 'low', 'inactive' => 'bg-gray-100 text-brand-slate',
        'notice_given', 'pending_approval', 'under_maintenance', 'more_info_required', 'medium', 'suspended' => 'bg-warning/10 text-warning',
        default => 'bg-navy-light text-navy',
    };
@endphp

<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $classes }}">{{ $label }}</span>
