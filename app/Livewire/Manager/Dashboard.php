<?php

namespace App\Livewire\Manager;

use App\Models\Application;
use App\Models\Complaint;
use App\Models\House;
use App\Models\MaintenanceTask;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Operations-only view for a manager/agent: assigned properties, units,
// maintenance, complaints, applications, expiring tenancies — deliberately no
// rent/expense/income figures anywhere on this page, per the module's
// financial-lockout-by-default design (see finance.view_rent_status).
#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();

        $properties = Property::accessibleBy($user)->get(['id', 'name']);
        $propertyIds = $properties->pluck('id');

        $houseIds = House::whereHas('block', fn ($q) => $q->whereIn('property_id', $propertyIds))->pluck('id');

        $vacantCount = House::whereIn('id', $houseIds)->vacant()->count();
        $occupiedCount = House::whereIn('id', $houseIds)->occupied()->count();
        $totalUnits = $houseIds->count();

        $emergencyMaintenance = MaintenanceTask::whereIn('property_id', $propertyIds)->open()->where('priority', 'emergency')->count();
        $openMaintenance = MaintenanceTask::whereIn('property_id', $propertyIds)->open()->count();
        $scheduledToday = MaintenanceTask::whereIn('property_id', $propertyIds)->scheduledOn(now()->toDateString())->count();

        $pendingComplaints = Complaint::where(function ($q) use ($propertyIds) {
            $q->whereIn('property_id', $propertyIds)
                ->orWhereHas('house.block', fn ($q2) => $q2->whereIn('property_id', $propertyIds));
        })->unresolved()->count();

        $pendingApplications = Application::whereIn('desired_property_id', $propertyIds)->pending()->count();

        $expiringTenancies = Tenancy::whereIn('house_id', $houseIds)->expiringSoon(30)->count();

        $totalTenants = Tenant::whereHas('tenancies', fn ($q) => $q->whereIn('house_id', $houseIds))->count();

        return view('livewire.manager.dashboard', [
            'properties' => $properties,
            'totalProperties' => $properties->count(),
            'totalUnits' => $totalUnits,
            'occupiedUnits' => $occupiedCount,
            'vacantUnits' => $vacantCount,
            'occupancyRate' => $totalUnits > 0 ? round($occupiedCount / $totalUnits * 100, 1) : 0,

            'emergencyMaintenance' => $emergencyMaintenance,
            'openMaintenance' => $openMaintenance,
            'scheduledToday' => $scheduledToday,
            'pendingComplaints' => $pendingComplaints,
            'pendingApplications' => $pendingApplications,
            'expiringTenancies' => $expiringTenancies,
            'totalTenants' => $totalTenants,

            'canViewRentStatus' => $user->hasManagerPermission('finance.view_rent_status'),
        ]);
    }
}
