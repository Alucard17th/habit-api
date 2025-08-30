<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoachAiControllerTest extends TestCase
{
    use RefreshDatabase; // <-- runs migrations on the sqlite testing DB

    public function test_atomic_requires_auth_and_text(): void
    {
        // 1) Fake the external AI call so we don't hit the network
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            // STRICT JSON content the controller expects to parse
                            'content' => json_encode([
                                'starter_goal' => 'Read 2 pages',
                                'cue'          => 'After breakfast',
                                'duration_min' => 5,
                                'location'     => 'Sofa',
                                'metric'       => 'pages',
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        // 2) Create a user in the *testing* DB
        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'test3@example.com',
            'password' => bcrypt('password'),
        ]);

        // 3) Act as that user (Sanctum)
        Sanctum::actingAs($user);

        // 4) Call the endpoint
        $res = $this->postJson('/api/coach/ai/atomic', ['text' => 'Read more books']);

        // 5) Assertions
        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['starter_goal','cue','duration_min','location','metric'],
            ])
            ->assertJsonPath('data.starter_goal', 'Read 2 pages');
    }
}
