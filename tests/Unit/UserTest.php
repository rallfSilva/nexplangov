<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function test_cpf_aparece_mascarado(): void
    {
        $this->assertSame('***.456.789-**', (new User(['cpf' => '12345678909']))->cpfMascarado());
        $this->assertNull((new User)->cpfMascarado());
    }

    public function test_iniciais(): void
    {
        $this->assertSame('AM', (new User(['name' => 'Ana Beatriz Martins']))->iniciais());
        $this->assertSame('J', (new User(['name' => 'João']))->iniciais());
    }
}
