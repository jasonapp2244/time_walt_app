<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    /**
     * The public home page loads.
     */
    public function test_the_home_page_loads(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
