<?php
/**
 * Copyright © - LiquidLab Agency - All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Liquidlab\InnoShipHyva\Test\Unit\Controller\Pudo;

use Liquidlab\InnoShipHyva\Controller\Pudo\Points;
use Liquidlab\InnoShipHyva\Model\PudoPointsProvider;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PointsTest extends TestCase
{
    private const PIN = ['pudo_id' => 101, 'name' => 'Locker 101'];

    private RequestInterface&MockObject $request;
    private PudoPointsProvider&MockObject $pointsProvider;
    private Json&MockObject $result;
    private Points $controller;

    /** @var array<string, string> */
    private array $headers = [];
    private mixed $data = null;
    private ?int $status = null;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->pointsProvider = $this->createMock(PudoPointsProvider::class);

        $this->result = $this->createMock(Json::class);
        $this->result->method('setHeader')->willReturnCallback(function (string $name, $value) {
            $this->headers[$name] = (string)$value;
            return $this->result;
        });
        $this->result->method('setData')->willReturnCallback(function ($data) {
            $this->data = $data;
            return $this->result;
        });
        $this->result->method('setHttpResponseCode')->willReturnCallback(function ($status) {
            $this->status = (int)$status;
            return $this->result;
        });

        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($this->result);

        $this->controller = new Points(
            $this->request,
            $factory,
            $this->pointsProvider,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testCountyAndCityReturnTheCityPoints(): void
    {
        $this->givenParams(['county' => ' Cluj ', 'city' => 'Cluj-Napoca', 'region_id' => '42']);
        $this->pointsProvider->expects($this->once())
            ->method('getByCity')
            ->with('Cluj', 'Cluj-Napoca')
            ->willReturn([self::PIN]);
        $this->pointsProvider->expects($this->never())->method('getNearRegion');

        $this->controller->execute();

        $this->assertSame(['pins' => [self::PIN]], $this->data);
        $this->assertSame('public, max-age=3600, s-maxage=3600', $this->headers['Cache-Control']);
        $this->assertNull($this->status);
    }

    public function testRegionReturnsThePointsNearIt(): void
    {
        $near = ['customerLocation' => ['lat' => 44.4268, 'lng' => 26.1025, 'region_id' => 42], 'pins' => [self::PIN]];
        $this->givenParams(['county' => 'Cluj', 'region_id' => '42']);
        $this->pointsProvider->expects($this->never())->method('getByCity');
        $this->pointsProvider->expects($this->once())->method('getNearRegion')->with(42)->willReturn($near);

        $this->controller->execute();

        $this->assertSame($near, $this->data);
    }

    public function testNoContextReturnsNoPins(): void
    {
        $this->givenParams(['region_id' => '0']);
        $this->pointsProvider->expects($this->never())->method('getByCity');
        $this->pointsProvider->expects($this->never())->method('getNearRegion');

        $this->controller->execute();

        $this->assertSame(['pins' => []], $this->data);
    }

    public function testArrayParamsAreIgnored(): void
    {
        $this->givenParams(['county' => ['Cluj'], 'city' => ['Cluj-Napoca'], 'region_id' => ['42']]);
        $this->pointsProvider->expects($this->never())->method('getByCity');
        $this->pointsProvider->expects($this->never())->method('getNearRegion');

        $this->controller->execute();

        $this->assertSame(['pins' => []], $this->data);
    }

    public function testAFailureIsNotCached(): void
    {
        $this->givenParams(['region_id' => '42']);
        $this->pointsProvider->method('getNearRegion')->willThrowException(new \RuntimeException('db down'));

        $this->controller->execute();

        $this->assertSame(500, $this->status);
        $this->assertSame('no-store', $this->headers['Cache-Control']);
        $this->assertSame(['pins' => []], $this->data);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function givenParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(fn (string $name) => $params[$name] ?? null);
    }
}
