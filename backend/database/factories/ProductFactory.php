<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'img' => null,
            'nots' => $this->faker->sentence(),
            'barcode' => $this->faker->unique()->bothify('PRD-####'),
            'is_active' => true,
        ];
    }
}
