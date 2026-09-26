<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'given_name' => fake()->firstName(),
            'family_name' => fake()->lastName(),
            'birth_date' => fake()->optional()->date(max: '-1 year'),
        ];
    }
}
