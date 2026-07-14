<?php

declare(strict_types=1);

use LibraTrack\Services\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    public function testHashesAndVerifiesPassword(): void
    {
        $service = new PasswordService();

        $hash = $service->hash('Admin@1234');

        $this->assertNotSame('Admin@1234', $hash);
        $this->assertTrue($service->verify('Admin@1234', $hash));
        $this->assertFalse($service->verify('wrong-password', $hash));
    }
}
