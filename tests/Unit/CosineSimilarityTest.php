<?php

namespace Tests\Unit;

use App\Services\Search\CosineSimilarity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CosineSimilarityTest extends TestCase
{
    private CosineSimilarity $similarity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->similarity = new CosineSimilarity();
    }

    public function test_identical_vectors_return_one(): void
    {
        $result = $this->similarity->calculate(
            [1, 2, 3],
            [1, 2, 3]
        );

        $this->assertEqualsWithDelta(1.0, $result, 0.000001);
    }

    public function test_orthogonal_vectors_return_zero(): void
    {
        $result = $this->similarity->calculate(
            [1, 0],
            [0, 1]
        );

        $this->assertEqualsWithDelta(0.0, $result, 0.000001);
    }

    public function test_opposite_vectors_return_negative_one(): void
    {
        $result = $this->similarity->calculate(
            [1, 2],
            [-1, -2]
        );

        $this->assertEqualsWithDelta(-1.0, $result, 0.000001);
    }

    public function test_vectors_with_different_dimensions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->similarity->calculate([1, 2], [1, 2, 3]);
    }

    public function test_empty_vectors_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->similarity->calculate([], []);
    }

    public function test_zero_vector_returns_zero(): void
    {
        $result = $this->similarity->calculate(
            [0, 0, 0],
            [1, 2, 3]
        );

        $this->assertSame(0.0, $result);
    }
}
