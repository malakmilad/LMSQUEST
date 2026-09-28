<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->catchPhrase(),
            'instructor_id' => Instructor::factory(),
        ];
    }
}
