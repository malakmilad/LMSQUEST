<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'course_id' => Course::factory(),
            'enrolled_at' => now(),
        ];
    }
}
