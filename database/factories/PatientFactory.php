<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'      => fake()->name(),
            'dob'       => fake()->dateTimeBetween('-70 years', '-20 years')->format('Y-m-d'),
            'diagnosis' => fake()->randomElement([
                'Breast cancer',
                'Lung cancer',
                'Cervical cancer',
                'Lymphoma',
                'Colorectal cancer',
            ]),
            'mobile_app_user_id' => null,
        ];
    }
}