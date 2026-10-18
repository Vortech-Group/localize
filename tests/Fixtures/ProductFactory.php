<?php

declare(strict_types=1);

namespace Vortech\Localize\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['sku' => fake()->unique()->bothify('SKU-####')];
    }
}
