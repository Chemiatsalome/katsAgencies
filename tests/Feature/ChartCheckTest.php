<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_overview_and_occupancy_charts_render_with_data(): void
    {
        $this->seed();
        $admin = User::role('admin')->firstOrFail();

        $overview = $this->actingAs($admin)->get('/admin/platform/overview');
        $overview->assertOk();
        $overview->assertSee('platformRentChart', false);
        $overview->assertSee('x-ref="canvas"', false);

        $occupancy = $this->actingAs($admin)->get('/admin/platform/occupancy');
        $occupancy->assertOk();
        $occupancy->assertSee('occupancyStatusChart', false);
    }
}
