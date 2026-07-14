<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function testComposerAutoloadIsAvailable(): void
    {
        $this->assertTrue(class_exists(DateTimeImmutable::class));
    }
}
